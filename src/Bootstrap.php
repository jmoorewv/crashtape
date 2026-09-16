<?php

namespace JMooreWV\CrashTape;

use JMooreWV\CrashTape\Admin\Timeline;
use JMooreWV\CrashTape\Api\SessionsController;
use JMooreWV\CrashTape\Capture\BrowserIngest;
use JMooreWV\CrashTape\Capture\CacheCapture;
use JMooreWV\CrashTape\Capture\ContactForm7Capture;
use JMooreWV\CrashTape\Capture\HttpCapture;
use JMooreWV\CrashTape\Capture\MailCapture;
use JMooreWV\CrashTape\Capture\PhpErrorCapture;
use JMooreWV\CrashTape\Capture\WooCommerceCapture;
use JMooreWV\CrashTape\Changes\Journal;
use JMooreWV\CrashTape\Diagnostics\PassiveMonitor;
use JMooreWV\CrashTape\SiteHealth\Integration as SiteHealthIntegration;
use JMooreWV\CrashTape\Support\InternalLog;

defined( 'ABSPATH' ) || exit;

/**
 * Wires capture channels only when a diagnostic session is active for the
 * current request (negligible overhead otherwise). Two exceptions run
 * independent of any session: the change journal, which only ever reacts
 * to specific WordPress hooks that fire in wp-admin/WP-CLI contexts, and
 * Passive Monitoring Mode, which is off by default and checks its own
 * enabled() option before
 * registering anything beyond its (harmless, no-op-until-enabled) cron
 * callback; registering either unconditionally costs nothing when idle.
 */
class Bootstrap {

	public static function init() {
		// Not hosted on WordPress.org, so translations aren't auto-loaded;
		// this plugin has to load its own .mo files from /languages.
		load_plugin_textdomain( 'crashtape', false, dirname( plugin_basename( CRASHTAPE_FILE ) ) . '/languages' );

		Database::maybe_upgrade();

		$instance = new self();
		$instance->hooks();
	}

	const VISITOR_SETUP_PARAM = 'crashtape_setup';

	/**
	 * Adds a unique diagnostic query parameter that causes common caches to
	 * bypass, where configured. Most page-cache plugins skip serving/storing
	 * a cached copy for any URL carrying a query string by default; the
	 * one-time setup link's own crashtape_setup=<token> already gets that
	 * for free, but the page it *redirects to* (the one the recorder
	 * script actually needs to load on) didn't. Can't guarantee a bypass on
	 * every host, just improve the odds on a default/common cache config.
	 */
	const CACHE_BYPASS_PARAM = 'crashtape_nocache';

	/**
	 * @var false|array|null false = not yet resolved this request, null =
	 * no active session, array = the session row. Memoized here because
	 * maybe_isolate_theme() (fires at theme setup, well before 'init') and
	 * maybe_start_capture()/maybe_enqueue_recorder() (both later) would
	 * otherwise each run their own validate_request_cookie() DB lookup for
	 * the same request.
	 */
	private $resolved_session = false;

	/**
	 * A WP-Cron dispatch (wp-cron.php, or an Action Scheduler async runner;
	 * both set DOING_CRON) is its own separate HTTP request with no
	 * diagnostic cookie of its own; without this fallback, every PHP error,
	 * mail failure, or WooCommerce/HTTP event a background task caused
	 * during an active session went completely uncaptured — there's
	 * nothing to correlate a background process against if it was never
	 * recorded. A real visitor request never has DOING_CRON set, so this
	 * never widens what a normal front-end/admin/AJAX/REST request
	 * resolves to.
	 */
	private function resolved_session() {
		if ( false === $this->resolved_session ) {
			$this->resolved_session = Session::validate_request_cookie();

			if ( ! $this->resolved_session && defined( 'DOING_CRON' ) && DOING_CRON ) {
				$this->resolved_session = Session::get_active();
			}
		}

		return $this->resolved_session;
	}

	private function hooks() {
		// Must be registered before 'setup_theme' resolves
		// get_stylesheet()/get_template() (wp-settings.php calls
		// wp_set_template_globals() right after that action fires); this
		// runs at 'plugins_loaded', well before, so it's early enough.
		// Filtering the *readback* value rather than ever calling
		// switch_theme() means the stored template/stylesheet options are
		// never touched; normal visitors (no session cookie) are
		// completely unaffected.
		add_filter( 'stylesheet', array( $this, 'maybe_isolate_theme' ) );
		add_filter( 'template', array( $this, 'maybe_isolate_theme' ) );

		// Must run before maybe_start_capture (and before any output) so a
		// visitor's very first request already carries the cookie it just set.
		add_action( 'init', array( $this, 'maybe_handle_visitor_setup' ), -10 );
		add_action( 'init', array( $this, 'maybe_start_capture' ), 0 );
		add_action( 'rest_api_init', array( new BrowserIngest(), 'register_routes' ) );
		add_action( 'rest_api_init', array( new SessionsController(), 'register_routes' ) );

		( new Journal() )->register_hooks();
		( new SiteHealthIntegration() )->register();
		( new PassiveMonitor() )->register_hooks();
		add_action( Cleanup::CRON_HOOK, array( Cleanup::class, 'run' ) );

		// Self-healing: the activation hook (which normally calls
		// Cleanup::schedule()) fires only once on a network activation, in
		// whichever single site's context that request happens to run in;
		// not once per site (unlike this plugins_loaded-driven hooks(),
		// which runs fresh on every site's own request). Without this, every
		// other site on the network would silently never get its retention
		// cleanup cron scheduled. Cheap and idempotent, same pattern as
		// PassiveMonitor::register_hooks().
		Cleanup::schedule();

		if ( is_admin() ) {
			$timeline = new Timeline();
			add_action( 'admin_menu', array( $timeline, 'register_menu' ) );
			add_action( 'admin_init', array( $timeline, 'register_actions' ) );
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_recorder' ) );
	}

	/**
	 * Consumes a visitor-mode one-time setup link. Redirects to the home
	 * page regardless of whether the token was valid; never reveals via
	 * timing or response differences whether a given token existed.
	 */
	public function maybe_handle_visitor_setup() {
		if ( empty( $_GET[ self::VISITOR_SETUP_PARAM ] ) ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ self::VISITOR_SETUP_PARAM ] ) );

		Session::activate_visitor_browser( $token );

		wp_safe_redirect( self::visitor_landing_url() );
		exit;
	}

	/**
	 * Extracted from maybe_handle_visitor_setup() so the cache-bypass
	 * query arg is directly testable without needing to invoke the
	 * wp_safe_redirect()+exit() wrapper around it.
	 */
	public static function visitor_landing_url() {
		return add_query_arg( self::CACHE_BYPASS_PARAM, wp_generate_password( 8, false, false ), home_url( '/' ) );
	}

	/**
	 * Theme isolation. Only ever returns a different value for a request
	 * carrying this specific session's own cookie; see
	 * Session::isolated_theme()/start()'s docblocks for the full mechanism.
	 */
	public function maybe_isolate_theme( $value ) {
		$session = $this->resolved_session();

		if ( ! $session ) {
			return $value;
		}

		$isolated = Session::isolated_theme( $session );

		return $isolated ? $isolated : $value;
	}

	public function maybe_start_capture() {
		$session = $this->resolved_session();

		if ( ! $session ) {
			return;
		}

		$request = Request::start( $session['id'] );

		RequestContext::set( $session['id'], $request['id'] );

		$php_capture = new PhpErrorCapture( $request['id'] );
		$php_capture->register();

		$http_capture = new HttpCapture( $request['id'] );
		$http_capture->register();

		$mail_capture = new MailCapture( $request['id'] );
		$mail_capture->register();

		if ( class_exists( 'WooCommerce' ) ) {
			try {
				( new WooCommerceCapture( $request['id'] ) )->register();
			} catch ( \Throwable $e ) {
				// A real safety gap this closes: nothing previously
				// caught an exception here, so a broken WooCommerce
				// integration could have taken down capture for every
				// other channel in the same request, not just this one.
				InternalLog::record( InternalLog::INTEGRATION_INIT_FAILURE, 'WooCommerce capture failed to initialize: ' . $e->getMessage() );
			}
		}

		if ( class_exists( 'WPCF7_ContactForm' ) ) {
			try {
				( new ContactForm7Capture( $request['id'] ) )->register();
			} catch ( \Throwable $e ) {
				InternalLog::record( InternalLog::INTEGRATION_INIT_FAILURE, 'Contact Form 7 capture failed to initialize: ' . $e->getMessage() );
			}
		}

		// Broad outer gate; CacheCapture::register() does its own precise
		// per-plugin feature detection for each of the four it supports,
		// this just avoids instantiating/registering anything at all when
		// none of them are present.
		if ( function_exists( 'wp_cache_clear_cache' ) || defined( 'LSCWP_V' ) || function_exists( 'w3tc_flush_all' ) || defined( 'WP_ROCKET_VERSION' ) ) {
			try {
				( new CacheCapture() )->register();
			} catch ( \Throwable $e ) {
				InternalLog::record( InternalLog::INTEGRATION_INIT_FAILURE, 'Cache-plugin capture failed to initialize: ' . $e->getMessage() );
			}
		}

		// Fires after REST routing/auth have actually resolved, unlike
		// request_type()'s guess at 'init' priority 0. A no-op for
		// non-REST requests.
		add_filter(
			'rest_post_dispatch',
			function ( $response, $server, $rest_request ) use ( $request ) {
				try {
					Request::capture_rest_context( $request['id'], $rest_request );
				} catch ( \Throwable $e ) {
					// Never let capture itself break a REST response.
				}

				return $response;
			},
			10,
			3
		);

		add_action(
			'shutdown',
			function () use ( $php_capture, $session, $request ) {
				$php_capture->handle_shutdown();
				EventWriter::instance()->flush( $session['id'], $request['id'] );
				Request::complete( $request['id'], function_exists( 'http_response_code' ) ? http_response_code() : null );
			},
			PHP_INT_MAX
		);
	}

	public function maybe_enqueue_recorder() {
		$session = $this->resolved_session();

		if ( ! $session ) {
			return;
		}

		wp_enqueue_script(
			'crashtape-recorder',
			CRASHTAPE_URL . 'assets/js/recorder.js',
			array(),
			CRASHTAPE_VERSION,
			true
		);

		wp_localize_script(
			'crashtape-recorder',
			'CrashTapeRecorder',
			array(
				'endpoint' => esc_url_raw( rest_url( 'crashtape/v1/events' ) ),
			)
		);
	}
}
