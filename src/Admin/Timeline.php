<?php

namespace JMooreWV\CrashTape\Admin;

use JMooreWV\CrashTape\Analysis\Baseline;
use JMooreWV\CrashTape\Analysis\Comparison;
use JMooreWV\CrashTape\Analysis\Correlation;
use JMooreWV\CrashTape\Bootstrap;
use JMooreWV\CrashTape\Cleanup;
use JMooreWV\CrashTape\Database;
use JMooreWV\CrashTape\Diagnostics\CronSnapshot;
use JMooreWV\CrashTape\Diagnostics\CustomChecks;
use JMooreWV\CrashTape\Diagnostics\PassiveMonitor;
use JMooreWV\CrashTape\Isolation\ConflictFinder;
use JMooreWV\CrashTape\Isolation\Loader as IsolationLoader;
use JMooreWV\CrashTape\Isolation\Token as IsolationToken;
use JMooreWV\CrashTape\Redactor;
use JMooreWV\CrashTape\Reports\Branding;
use JMooreWV\CrashTape\Reports\Builder;
use JMooreWV\CrashTape\Reports\Package;
use JMooreWV\CrashTape\Reports\Storage;
use JMooreWV\CrashTape\Sdk\Registry;
use JMooreWV\CrashTape\Session;
use JMooreWV\CrashTape\Support\InternalLog;
use JMooreWV\CrashTape\Support\SelfTest;
use JMooreWV\CrashTape\Support\Uninstaller;

defined( 'ABSPATH' ) || exit;

/**
 * The main CrashTape admin surface under Tools: start/stop a session,
 * the tabbed dashboard/sessions/analysis views, and the event timeline.
 */
class Timeline {

	const PAGE_SLUG               = 'crashtape';
	const SELF_TEST_TRANSIENT     = 'crashtape_self_test_result';
	const CUSTOM_CHECKS_TRANSIENT = 'crashtape_custom_checks_result';
	const DEVELOPER_MODE_OPTION   = 'crashtape_developer_mode';

	public function register_menu() {
		$hook = add_management_page(
			__( 'CrashTape', 'crashtape' ),
			__( 'CrashTape', 'crashtape' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		// admin_print_styles-{$hook} rather than the unconditional
		// admin_enqueue_scripts; this stylesheet has no reason to load on
		// every wp-admin screen, only this plugin's own page.
		add_action( 'admin_print_styles-' . $hook, array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets() {
		wp_enqueue_style( 'crashtape-admin', CRASHTAPE_URL . 'assets/css/admin.css', array( 'dashicons' ), CRASHTAPE_VERSION );
	}

	/**
	 * admin-post.php never fires admin_menu, so these must be registered
	 * unconditionally on admin_init rather than inside register_menu().
	 */
	public function register_actions() {
		add_action( 'admin_post_crashtape_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_crashtape_stop', array( $this, 'handle_stop' ) );
		add_action( 'admin_post_crashtape_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_crashtape_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_crashtape_save_privacy', array( $this, 'handle_save_privacy' ) );
		add_action( 'admin_post_crashtape_save_retention', array( $this, 'handle_save_retention' ) );
		add_action( 'admin_post_crashtape_purge_all', array( $this, 'handle_purge_all' ) );
		add_action( 'admin_post_crashtape_save_redaction', array( $this, 'handle_save_redaction' ) );
		add_action( 'admin_post_crashtape_save_branding', array( $this, 'handle_save_branding' ) );
		add_action( 'admin_post_crashtape_save_uninstall_preference', array( $this, 'handle_save_uninstall_preference' ) );
		add_action( 'admin_post_crashtape_self_test', array( $this, 'handle_self_test' ) );
		add_action( 'admin_post_crashtape_clear_internal_log', array( $this, 'handle_clear_internal_log' ) );
		add_action( 'admin_post_crashtape_save_developer_mode', array( $this, 'handle_save_developer_mode' ) );
		add_action( 'admin_post_crashtape_isolation_install', array( $this, 'handle_isolation_install' ) );
		add_action( 'admin_post_crashtape_isolation_uninstall', array( $this, 'handle_isolation_uninstall' ) );
		add_action( 'admin_post_crashtape_isolation_bypass', array( $this, 'handle_isolation_bypass' ) );
		add_action( 'admin_post_crashtape_isolation_start', array( $this, 'handle_isolation_start' ) );
		add_action( 'admin_post_crashtape_isolation_stop', array( $this, 'handle_isolation_stop' ) );
		add_action( 'admin_post_crashtape_finder_start', array( $this, 'handle_finder_start' ) );
		add_action( 'admin_post_crashtape_finder_start_automated', array( $this, 'handle_finder_start_automated' ) );
		add_action( 'admin_post_crashtape_finder_answer', array( $this, 'handle_finder_answer' ) );
		add_action( 'admin_post_crashtape_finder_stop', array( $this, 'handle_finder_stop' ) );
		add_action( 'admin_post_crashtape_baseline_mark', array( $this, 'handle_baseline_mark' ) );
		add_action( 'admin_post_crashtape_baseline_clear', array( $this, 'handle_baseline_clear' ) );
		add_action( 'admin_post_crashtape_check_add', array( $this, 'handle_check_add' ) );
		add_action( 'admin_post_crashtape_check_delete', array( $this, 'handle_check_delete' ) );
		add_action( 'admin_post_crashtape_check_run', array( $this, 'handle_check_run' ) );
		add_action( 'admin_post_crashtape_save_passive_monitoring', array( $this, 'handle_save_passive_monitoring' ) );
		add_action( 'admin_post_crashtape_clear_passive_monitoring', array( $this, 'handle_clear_passive_monitoring' ) );
	}

	/**
	 * Release-readiness checklist Priority 31 (Fresh Installation Test)
	 * found this the hard way: every admin_post handler that didn't
	 * explicitly pick a tab (handle_stop() and handle_export() already did,
	 * per Priority 15/16) redirected back to the bare page URL, which lands
	 * on the Dashboard tab regardless of which tab the action was actually
	 * taken from. Running Self-Test from the Diagnostics tab, for example,
	 * bounced the admin back to Dashboard with no visible sign the test had
	 * even run. Every form on this page already emits a `_wp_http_referer`
	 * field via wp_nonce_field()'s own default, so wp_get_referer() already
	 * has the originating tab; wp_safe_redirect() validates it's same-host
	 * before using it.
	 */
	private function redirect_after_action() {
		wp_safe_redirect( $this->resolve_action_redirect_target() );
		exit;
	}

	/**
	 * Split out from redirect_after_action() so the tab-preservation logic
	 * is testable without triggering that method's exit().
	 */
	private function resolve_action_redirect_target() {
		$referer = wp_get_referer();

		if ( $referer && false !== strpos( $referer, 'page=' . self::PAGE_SLUG ) ) {
			return $referer;
		}

		return add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'tools.php' ) );
	}

	public function handle_baseline_mark() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_baseline_mark' );

		$uuid = isset( $_POST['session_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['session_uuid'] ) ) : '';

		if ( $uuid && Session::find_by_uuid( $uuid ) ) {
			Baseline::mark( $uuid );
		}

		$this->redirect_after_action();
	}

	public function handle_baseline_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_baseline_clear' );

		Baseline::clear();

		$this->redirect_after_action();
	}

	public function handle_finder_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_finder_start' );

		if ( ! IsolationLoader::is_installed() ) {
			wp_die( esc_html__( 'Install the MU loader under Conflict Isolation first.', 'crashtape' ) );
		}

		ConflictFinder::start();

		$this->redirect_after_action();
	}

	public function handle_finder_start_automated() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_finder_start_automated' );

		if ( ! IsolationLoader::is_installed() ) {
			wp_die( esc_html__( 'Install the MU loader under Conflict Isolation first.', 'crashtape' ) );
		}

		$check_id = isset( $_POST['check_id'] ) ? sanitize_text_field( wp_unslash( $_POST['check_id'] ) ) : '';

		ConflictFinder::run_automated( $check_id );

		$this->redirect_after_action();
	}

	public function handle_finder_answer() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_finder_answer' );

		$verdict = isset( $_POST['verdict'] ) ? sanitize_key( wp_unslash( $_POST['verdict'] ) ) : '';

		if ( in_array( $verdict, array( 'yes', 'no', 'unable' ), true ) ) {
			ConflictFinder::answer( $verdict );
		}

		$this->redirect_after_action();
	}

	public function handle_finder_stop() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_finder_stop' );

		ConflictFinder::stop();

		$this->redirect_after_action();
	}

	public function handle_isolation_install() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_isolation_install' );

		IsolationLoader::install();

		$this->redirect_after_action();
	}

	public function handle_isolation_uninstall() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_isolation_uninstall' );

		IsolationToken::stop_test();
		IsolationLoader::uninstall();

		$this->redirect_after_action();
	}

	public function handle_isolation_bypass() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_isolation_bypass' );

		update_option( IsolationToken::BYPASS_OPTION, ! empty( $_POST['enable'] ) );

		$this->redirect_after_action();
	}

	public function handle_isolation_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_isolation_start' );

		$keep = isset( $_POST['keep_plugins'] ) && is_array( $_POST['keep_plugins'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['keep_plugins'] ) )
			: array();

		IsolationToken::start_test( $keep );

		$this->redirect_after_action();
	}

	public function handle_isolation_stop() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_isolation_stop' );

		IsolationToken::stop_test();

		$this->redirect_after_action();
	}

	public function handle_self_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_self_test' );

		set_transient( self::SELF_TEST_TRANSIENT, SelfTest::run(), 5 * MINUTE_IN_SECONDS );

		$this->redirect_after_action();
	}

	public function handle_clear_internal_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_clear_internal_log' );

		InternalLog::clear();

		$this->redirect_after_action();
	}

	public function handle_save_developer_mode() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_save_developer_mode' );

		update_option( self::DEVELOPER_MODE_OPTION, ! empty( $_POST['developer_mode'] ) );

		$this->redirect_after_action();
	}

	public function handle_save_privacy() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_save_privacy' );

		$profile      = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : Redactor::PROFILE_STRICT;
		$all_profiles = array( Redactor::PROFILE_STRICT, Redactor::PROFILE_BALANCED, Redactor::PROFILE_ADVANCED );

		if ( ! in_array( $profile, $all_profiles, true ) ) {
			$profile = Redactor::PROFILE_STRICT;
		}

		update_option( Redactor::PROFILE_OPTION, $profile );

		$allowlist = self::parse_lines(
			isset( $_POST['query_allowlist'] ) ? wp_unslash( $_POST['query_allowlist'] ) : '',
			Redactor::MAX_ALLOWLIST_PARAMS
		);
		update_option( Redactor::QUERY_ALLOWLIST_OPTION, array_map( 'sanitize_key', $allowlist ) );

		$this->redirect_after_action();
	}

	public function handle_save_retention() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_save_retention' );

		$days = isset( $_POST['retention_days'] ) ? (int) $_POST['retention_days'] : Session::RETENTION_DAYS;
		$days = max( Session::RETENTION_MIN, min( Session::RETENTION_MAX, $days ) );

		update_option( Session::RETENTION_OPTION, $days );

		$this->redirect_after_action();
	}

	/**
	 * The manual, immediate "start fresh" counterpart to retention's
	 * date-based automatic cleanup (Cleanup::run()); deliberately scoped to
	 * diagnostic session records only. Settings, the internal operational
	 * log, and Passive Monitoring's stats each already have their own
	 * dedicated clear action and are untouched here.
	 */
	public function handle_purge_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_purge_all' );

		Cleanup::purge_all();

		self::set_notice( 'success', __( 'All diagnostic sessions and recorded changes were deleted. Settings, the internal log, and Passive Monitoring stats were not affected.', 'crashtape' ) );

		$this->redirect_after_action();
	}

	/**
	 * Invalid regex patterns are silently dropped rather than rejecting
	 * the whole submission; consistent with retention's clamp-don't-error
	 * handling above; this plugin has no flash-notice mechanism yet.
	 */
	public function handle_save_redaction() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_save_redaction' );

		$keys = self::parse_lines(
			isset( $_POST['redaction_keys'] ) ? wp_unslash( $_POST['redaction_keys'] ) : '',
			Redactor::MAX_CUSTOM_RULES
		);
		update_option( Redactor::CUSTOM_KEYS_OPTION, array_map( 'sanitize_text_field', $keys ) );

		$patterns = self::parse_lines(
			isset( $_POST['redaction_patterns'] ) ? wp_unslash( $_POST['redaction_patterns'] ) : '',
			Redactor::MAX_CUSTOM_RULES
		);
		$patterns = array_values( array_filter( $patterns, array( 'JMooreWV\CrashTape\Redactor', 'is_valid_pattern' ) ) );
		update_option( Redactor::CUSTOM_PATTERNS_OPTION, $patterns );

		$this->redirect_after_action();
	}

	/**
	 * A rejected/missing logo upload leaves any existing logo untouched
	 * rather than clearing it; an admin re-saving the org name shouldn't
	 * silently lose a logo set in an earlier save.
	 */
	public function handle_save_branding() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_save_branding' );

		Branding::save_org_name( isset( $_POST['org_name'] ) ? wp_unslash( $_POST['org_name'] ) : '' );
		Branding::save_footer_note( isset( $_POST['footer_note'] ) ? wp_unslash( $_POST['footer_note'] ) : '' );

		if ( ! empty( $_POST['remove_logo'] ) ) {
			Branding::clear_logo();
		} elseif ( ! empty( $_FILES['logo']['tmp_name'] ) && UPLOAD_ERR_OK === $_FILES['logo']['error'] ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			Branding::save_logo_from_upload( $_FILES['logo'] );
		}

		$this->redirect_after_action();
	}

	public function handle_save_uninstall_preference() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_save_uninstall_preference' );

		update_option( Uninstaller::KEEP_DATA_OPTION, ! empty( $_POST['keep_data'] ) );

		$this->redirect_after_action();
	}

	/**
	 * Which config fields matter depends on the check type; read only
	 * the relevant $_POST keys for the submitted type rather than trying
	 * to validate a one-size-fits-all shape. CustomChecks::add() does the
	 * actual sanitizing/clamping per field.
	 */
	public function handle_check_add() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_check_add' );

		$label = isset( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : '';
		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		$config = array();

		switch ( $type ) {
			case CustomChecks::TYPE_OPTION_EQUALS:
				$config['option_name']    = isset( $_POST['option_name'] ) ? wp_unslash( $_POST['option_name'] ) : '';
				$config['expected_value'] = isset( $_POST['expected_value'] ) ? wp_unslash( $_POST['expected_value'] ) : '';
				break;
			case CustomChecks::TYPE_CONSTANT_DEFINED:
				$config['constant_name'] = isset( $_POST['constant_name'] ) ? wp_unslash( $_POST['constant_name'] ) : '';
				break;
			case CustomChecks::TYPE_URL_STATUS:
				$config['url']             = isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '';
				$config['expected_status'] = isset( $_POST['expected_status'] ) ? wp_unslash( $_POST['expected_status'] ) : '200';
				break;
			case CustomChecks::TYPE_PLUGIN_ACTIVE:
				$config['plugin_file'] = isset( $_POST['plugin_file'] ) ? wp_unslash( $_POST['plugin_file'] ) : '';
				break;
		}

		if ( $label && $type ) {
			CustomChecks::add( $label, $type, $config );
		}

		$this->redirect_after_action();
	}

	public function handle_check_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_check_delete' );

		$id = isset( $_POST['check_id'] ) ? sanitize_text_field( wp_unslash( $_POST['check_id'] ) ) : '';

		if ( $id ) {
			CustomChecks::delete( $id );
		}

		$this->redirect_after_action();
	}

	public function handle_check_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_check_run' );

		set_transient( self::CUSTOM_CHECKS_TRANSIENT, CustomChecks::run_all(), 5 * MINUTE_IN_SECONDS );

		$this->redirect_after_action();
	}

	public function handle_save_passive_monitoring() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_save_passive_monitoring' );

		PassiveMonitor::set_enabled( ! empty( $_POST['enabled'] ) );

		$days = isset( $_POST['retention_days'] ) ? (int) $_POST['retention_days'] : PassiveMonitor::RETENTION_DEFAULT;
		$days = max( PassiveMonitor::RETENTION_MIN, min( PassiveMonitor::RETENTION_MAX, $days ) );
		update_option( PassiveMonitor::RETENTION_OPTION, $days );

		$this->redirect_after_action();
	}

	public function handle_clear_passive_monitoring() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_clear_passive_monitoring' );

		PassiveMonitor::clear();

		$this->redirect_after_action();
	}

	private static function parse_lines( $raw, $max ) {
		$lines = preg_split( '/[\r\n]+/', (string) $raw );
		$lines = array_map( 'trim', $lines );
		$lines = array_values(
			array_filter(
				$lines,
				function ( $line ) {
					return '' !== $line;
				}
			)
		);

		return array_slice( $lines, 0, $max );
	}

	public function handle_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_start' );

		$label         = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$mode          = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : Session::MODE_AUTHENTICATED;
		$isolate_theme = ! empty( $_POST['isolate_theme'] ) ? Session::default_isolation_theme() : null;

		Session::start( $label, $mode, $isolate_theme );

		$this->redirect_after_action();
	}

	public function handle_stop() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_stop' );

		Session::stop();

		// Release-readiness checklist Priority 15: land on the Sessions tab
		// (where this session's analysis actually is), not back on
		// Dashboard; "Stop Recording -> Review Result" is this plugin's
		// own documented core workflow, and required an extra manual click
		// before this.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => 'sessions',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Release-readiness checklist Priority 16 found this the hard way:
	 * Builder::build()'s return value was discarded entirely; a real
	 * WP_Error (ZipArchive unavailable, a failed ZipArchive::close(), a
	 * failed finalize) had a real, working InternalLog entry recorded for
	 * it already, but the admin who clicked the button saw nothing at all:
	 * the page just reloaded, no new package appeared, no error explained
	 * why. Every admin_post handler in this class had the same gap (no
	 * success/failure feedback mechanism existed at all); this is fixed
	 * for the one verified real case rather than a speculative rewrite of
	 * every handler.
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_export' );

		$session = Session::get_last();

		if ( $session && 'active' !== $session['status'] ) {
			$result = Builder::build( $session );

			if ( is_wp_error( $result ) ) {
				self::set_notice( 'error', $result->get_error_message() );
			} else {
				self::set_notice( 'success', __( 'Support package generated.', 'crashtape' ) );
			}
		} else {
			self::set_notice( 'error', __( 'No completed session to generate a support package for. Stop a diagnostic session first.', 'crashtape' ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => 'sessions',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * A one-time, per-user notice that survives the redirect an
	 * admin_post handler always does; WordPress's own transient-backed
	 * "settings saved" pattern (options.php's use of the 'settings_errors'
	 * transient), adapted since this plugin's actions don't go through
	 * options.php at all.
	 */
	private static function set_notice( $type, $message ) {
		set_transient(
			'crashtape_admin_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
	}

	private function render_pending_notice() {
		$key    = 'crashtape_admin_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );

		$css_class = 'error' === $notice['type'] ? 'notice-error' : 'notice-success';

		echo '<div class="notice ' . esc_attr( $css_class ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
	}

	/**
	 * Authenticated download; the package is never served by a direct,
	 * guessable URL.
	 */
	public function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crashtape' ) );
		}

		check_admin_referer( 'crashtape_download' );

		$package_id = isset( $_GET['package'] ) ? absint( $_GET['package'] ) : 0;
		$package    = $package_id ? Package::find( $package_id ) : null;

		// Release-readiness checklist Priority 16 "report download no
		// longer exists": both messages below previously said only what
		// happened, not what to do about it; a real gap the checklist
		// calls out explicitly. The most likely real cause for either is
		// the same: retention cleanup (Cleanup::delete_expired_packages())
		// already removed this package, since packages expire on the same
		// schedule as their session.
		if ( ! $package ) {
			wp_die( esc_html__( 'This support package was not found. It may have already expired and been removed by retention cleanup. Go back and generate a new one from the session\'s Sessions tab.', 'crashtape' ) );
		}

		$path = Storage::path( $package['filename'] );

		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'This support package\'s file is missing on disk, even though it\'s still listed. Generate a new one from the session\'s Sessions tab; this old entry will be cleaned up automatically.', 'crashtape' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $package['filename'] . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	/**
	 * The "Current Site Health" and "Recent Sessions" cards, plus a
	 * one-line Privacy status shown as its own dashboard card. "Record a
	 * Problem" (the Start button) and the full "Recent Changes" list
	 * already exist as their own sections further down this same page;
	 * not duplicated here.
	 *
	 * "PHP Errors"/"External Services"/"Mail" are continuous,
	 * between-session health facts; the only mechanism this plugin has
	 * that tracks those independent of an active diagnostic session is
	 * Passive Monitoring, off by default.
	 * Showing "0" when it's off would misleadingly imply "measured and
	 * healthy" rather than "not measured at all", so this says so plainly
	 * instead. Scheduled Tasks and Recent Changes don't have that problem;
	 * both are always live, session-independent facts (CronSnapshot,
	 * the change journal), so they're shown regardless.
	 */
	private function render_dashboard_summary() {
		echo '<h2>' . esc_html__( 'Current Site Health', 'crashtape' ) . '</h2>';

		$passive_enabled = PassiveMonitor::enabled();
		$today_stats     = array();

		if ( $passive_enabled ) {
			$stats       = PassiveMonitor::stats();
			$today_stats = isset( $stats['days'][ gmdate( 'Y-m-d' ) ] ) ? $stats['days'][ gmdate( 'Y-m-d' ) ] : array();
		}

		$cron          = CronSnapshot::capture();
		$cron_overdue  = (int) ( $cron['wp_cron']['overdue_count'] ?? 0 ) + (int) ( $cron['action_scheduler']['overdue_count'] ?? 0 );
		$changes_count = $this->changes_in_last_24_hours();

		echo '<table class="widefat striped" style="max-width:480px;">';
		echo '<tbody>';

		if ( $passive_enabled ) {
			$php_fatal    = (int) ( $today_stats['php_fatal'] ?? 0 );
			$http_failure = (int) ( $today_stats['http_failure'] ?? 0 );
			$mail_failed  = (int) ( $today_stats['mail_failed'] ?? 0 );

			/* translators: %d: number of PHP fatals recorded today by Passive Monitoring */
			echo '<tr><td>' . esc_html__( 'PHP Errors', 'crashtape' ) . '</td><td>' . esc_html( sprintf( _n( '%d critical (today)', '%d critical (today)', $php_fatal, 'crashtape' ), $php_fatal ) ) . '</td></tr>';
			/* translators: %d: number of outbound HTTP failures recorded today by Passive Monitoring */
			echo '<tr><td>' . esc_html__( 'External Services', 'crashtape' ) . '</td><td>' . esc_html( sprintf( _n( '%d failure (today)', '%d failures (today)', $http_failure, 'crashtape' ), $http_failure ) ) . '</td></tr>';
			/* translators: %d: number of mail failures recorded today by Passive Monitoring */
			echo '<tr><td>' . esc_html__( 'Mail', 'crashtape' ) . '</td><td>' . esc_html( $mail_failed ? sprintf( _n( '%d failure (today)', '%d failures (today)', $mail_failed, 'crashtape' ), $mail_failed ) : __( 'Healthy (today)', 'crashtape' ) ) . '</td></tr>';
		} else {
			foreach ( array( __( 'PHP Errors', 'crashtape' ), __( 'External Services', 'crashtape' ), __( 'Mail', 'crashtape' ) ) as $row_label ) {
				echo '<tr><td>' . esc_html( $row_label ) . '</td><td><em>' . esc_html__( 'Passive Monitoring is off, not measured', 'crashtape' ) . '</em></td></tr>';
			}
		}

		/* translators: %d: number of WP-Cron/Action Scheduler tasks currently overdue */
		echo '<tr><td>' . esc_html__( 'Scheduled Tasks', 'crashtape' ) . '</td><td>' . esc_html( $cron_overdue ? sprintf( _n( '%d overdue', '%d overdue', $cron_overdue, 'crashtape' ), $cron_overdue ) : __( 'Healthy', 'crashtape' ) ) . '</td></tr>';
		/* translators: %d: number of plugin/theme/core changes recorded in the last 24 hours */
		echo '<tr><td>' . esc_html__( 'Recent Changes', 'crashtape' ) . '</td><td>' . esc_html( sprintf( _n( '%d in the last 24 hours', '%d in the last 24 hours', $changes_count, 'crashtape' ), $changes_count ) ) . '</td></tr>';

		echo '</tbody></table>';

		if ( ! $passive_enabled ) {
			echo '<p><em>' . esc_html__( 'Enable Passive Monitoring below for continuous PHP/mail/external-service health tracking between diagnostic sessions.', 'crashtape' ) . '</em></p>';
		}

		echo '<p><strong>' . esc_html__( 'Privacy:', 'crashtape' ) . '</strong> ' . esc_html(
			sprintf(
				/* translators: %s: privacy profile name, e.g. Strict */
				__( '%s redaction enabled', 'crashtape' ),
				ucfirst( Redactor::profile() )
			)
		) . '</p>';

		$this->render_recent_sessions_summary();
	}

	private function changes_in_last_24_hours() {
		global $wpdb;

		$table = Database::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Shows the latest five sessions; previously only ever surfaced
	 * inside the session-comparison dropdown, never as its own
	 * at-a-glance list.
	 */
	private function render_recent_sessions_summary() {
		$sessions = Session::recent( 5 );

		echo '<h2>' . esc_html__( 'Recent Sessions', 'crashtape' ) . '</h2>';

		if ( ! $sessions ) {
			echo '<p>' . esc_html__( 'No completed sessions yet.', 'crashtape' ) . '</p>';
			return;
		}

		echo '<ul>';

		foreach ( $sessions as $session ) {
			$pill = '<span class="crashtape-pill crashtape-pill--' . esc_attr( $session['status'] ) . '">' . esc_html( $session['status'] ) . '</span>';

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- every substituted value is already individually escaped above ($pill is built entirely from esc_attr()/esc_html()); sprintf()'s own format string is a plain translatable literal, not user input.
			echo '<li>' . sprintf(
				/* translators: 1: session label or UUID (already escaped) 2: status pill markup (already-safe HTML) 3: start date (already escaped) */
				__( '%1$s %2$s (%3$s)', 'crashtape' ),
				esc_html( $session['label'] ? $session['label'] : $session['uuid'] ),
				$pill,
				esc_html( get_date_from_gmt( $session['started_at'] ) )
			) . '</li>';
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</ul>';
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active = Session::get_active();
		$last   = Session::get_last();

		$tabs = array(
			'dashboard'       => __( 'Dashboard', 'crashtape' ),
			'sessions'        => __( 'Sessions', 'crashtape' ),
			'diagnostics'     => __( 'Diagnostics', 'crashtape' ),
			'conflict-finder' => __( 'Conflict Finder', 'crashtape' ),
			'settings'        => __( 'Settings', 'crashtape' ),
		);

		// Release-readiness checklist Priority 15 found this the hard way:
		// Stop Recording always landed back on the Dashboard tab, never on
		// the Sessions tab where the just-finished session's analysis
		// actually lives; breaking the "Stop Recording -> Review Result"
		// step of this plugin's own documented core workflow, since
		// reviewing the result took an extra, undocumented manual click.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection, not a state change.
		$requested_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$active_tab    = array_key_exists( $requested_tab, $tabs ) ? $requested_tab : 'dashboard';

		echo '<div class="wrap crashtape-wrap">';
		// Release-readiness checklist Priority 21: the real brand mark
		// (.release/icon.svg; a waveform inside a rounded tile,
		// evoking "recorder" and "signal" at once) replaces the generic
		// WordPress dashicon placeholder that stood in for it until now.
		// Inlined directly (not an <img> referencing a shipped asset file)
		// so there's no extra request and no server MIME-type dependency
		// for serving .svg correctly.
		echo '<h1><svg class="crashtape-mark" width="28" height="28" viewBox="0 0 256 256" aria-hidden="true" style="vertical-align:text-bottom;margin-right:6px;"><rect width="256" height="256" rx="56" fill="#14171F"/><g fill="#FF7A3D"><rect x="39" y="88" width="18" height="40" rx="9"/><rect x="71" y="70" width="18" height="76" rx="9"/><rect x="103" y="81" width="18" height="54" rx="9"/><rect x="135" y="64" width="18" height="88" rx="9"/><rect x="167" y="77" width="18" height="62" rx="9"/><rect x="199" y="85" width="18" height="46" rx="9"/></g></svg>' . esc_html__( 'CrashTape', 'crashtape' ) . '</h1>';

		$this->render_pending_notice();

		$this->render_tabs( $tabs, $active_tab );

		echo '<div id="crashtape-tab-dashboard" class="crashtape-tab-panel" role="tabpanel" aria-labelledby="crashtape-tab-btn-dashboard" tabindex="0"' . ( 'dashboard' === $active_tab ? '' : ' hidden' ) . '>';

		echo '<div class="crashtape-card">';
		$this->render_dashboard_summary();
		echo '</div>';

		echo '<div class="crashtape-card">';

		if ( $active ) {
			echo '<p><strong>' . esc_html__( 'Recording:', 'crashtape' ) . '</strong> ' . esc_html( $active['label'] ? $active['label'] : '(no label)' ) . ', started ' . esc_html( get_date_from_gmt( $active['started_at'] ) ) . ', mode: ' . esc_html( $active['mode'] ) . ' <span class="crashtape-pill crashtape-pill--active">' . esc_html__( 'Active', 'crashtape' ) . '</span> <span id="crashtape-elapsed" data-started="' . esc_attr( mysql2date( 'U', $active['started_at'], false ) ) . '"></span></p>';
			$this->render_elapsed_script();

			$this->maybe_render_no_requests_warning( $active );

			$isolated_theme = Session::isolated_theme( $active );

			if ( $isolated_theme ) {
				echo '<p><em>' . esc_html(
					sprintf(
						/* translators: %s: theme name */
						__( 'Theme isolated: this diagnostic browser is rendering with %s instead of the active theme.', 'crashtape' ),
						wp_get_theme( $isolated_theme )->get( 'Name' )
					)
				) . '</em></p>';
			}

			if ( Session::MODE_VISITOR === $active['mode'] ) {
				$this->render_visitor_setup_link( $active );
			}

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'crashtape_stop' );
			echo '<input type="hidden" name="action" value="crashtape_stop" />';
			submit_button( __( 'Stop Recording', 'crashtape' ), 'primary' );
			echo '</form>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'crashtape_start' );
			echo '<input type="hidden" name="action" value="crashtape_start" />';
			echo '<p><label for="crashtape-label">' . esc_html__( 'Session label (optional)', 'crashtape' ) . '</label><br />';
			echo '<input type="text" id="crashtape-label" name="label" class="regular-text" /></p>';

			echo '<fieldset><legend>' . esc_html__( 'How will you reproduce the problem?', 'crashtape' ) . '</legend>';
			echo '<p><label><input type="radio" name="mode" value="' . esc_attr( Session::MODE_AUTHENTICATED ) . '" checked="checked" /> '
				. esc_html__( 'In this logged-in browser', 'crashtape' ) . '</label></p>';
			echo '<p><label><input type="radio" name="mode" value="' . esc_attr( Session::MODE_VISITOR ) . '" /> '
				. esc_html__( 'As a logged-out visitor', 'crashtape' ) . '</label></p>';
			echo '</fieldset>';

			$default_theme = Session::default_isolation_theme();

			if ( $default_theme ) {
				echo '<p><label><input type="checkbox" name="isolate_theme" value="1" /> '
					. esc_html(
						sprintf(
							/* translators: %s: theme name, e.g. Twenty Twenty-Five */
							__( 'Use the default theme (%s) instead of the active theme for this diagnostic browser only, which helps tell a theme problem apart from a plugin problem. Never changes the site\'s theme for other visitors.', 'crashtape' ),
							wp_get_theme( $default_theme )->get( 'Name' )
						)
					) . '</label></p>';
			}

			submit_button( __( 'Start Diagnostic Session', 'crashtape' ), 'primary' );
			echo '</form>';
		}

		echo '</div>';
		echo '</div>'; // .crashtape-tab-panel#crashtape-tab-dashboard

		echo '<div id="crashtape-tab-sessions" class="crashtape-tab-panel" role="tabpanel" aria-labelledby="crashtape-tab-btn-sessions" tabindex="0"' . ( 'sessions' === $active_tab ? '' : ' hidden' ) . '>';

		if ( $last ) {
			echo '<div class="crashtape-card">';
			echo '<h2>' . esc_html__( 'Raw Timeline: Most Recent Session', 'crashtape' ) . '</h2>';
			echo '<p>' . esc_html__( 'Session', 'crashtape' ) . ' <code>' . esc_html( $last['uuid'] ) . '</code>'
				. ' <span class="crashtape-pill crashtape-pill--' . esc_attr( $last['status'] ) . '">' . esc_html( $last['status'] ) . '</span>'
				. ': ' . esc_html(
					sprintf(
						/* translators: 1: event count 2: error count 3: warning count */
						__( '%1$d events, %2$d errors, %3$d warnings', 'crashtape' ),
						$last['event_count'],
						$last['error_count'],
						$last['warning_count']
					)
				) . '</p>';
			$this->render_analysis( $last );

			if ( 'active' !== $last['status'] ) {
				$this->render_baseline( $last );
			}

			// Release-readiness checklist Priority 15 found this the hard
			// way: these two sections (raw WP/PHP/plugin environment detail,
			// plus a correlation-window changes list that can run to 50+
			// lines on an actively-maintained site) previously rendered at
			// full, unconditional prominence; sharing equal visual weight
			// with the plain-English "Most Likely Issue" analysis above and
			// pushing the actual event table and Generate Support Package
			// button further down the page. A native <details> disclosure,
			// closed by default, keeps this real evidence one click away
			// without deleting or hiding it outright.
			echo '<details class="crashtape-technical-details"><summary>' . esc_html__( 'Technical details (environment & recent changes)', 'crashtape' ) . '</summary>';
			$this->render_environment( $last );
			$this->render_changes_before_session( $last );
			echo '</details>';
			$this->render_events_table( (int) $last['id'] );

			if ( 'active' !== $last['status'] ) {
				$this->render_export( $last );
			}

			echo '</div>';
		}

		echo '<div class="crashtape-card">';
		echo '<h2>' . esc_html__( 'Recent Changes', 'crashtape' ) . '</h2>';
		$this->render_recent_changes();
		echo '</div>';

		echo '<div class="crashtape-card">';
		$this->render_comparison();
		echo '</div>';

		echo '</div>'; // .crashtape-tab-panel#crashtape-tab-sessions

		echo '<div id="crashtape-tab-diagnostics" class="crashtape-tab-panel" role="tabpanel" aria-labelledby="crashtape-tab-btn-diagnostics" tabindex="0"' . ( 'diagnostics' === $active_tab ? '' : ' hidden' ) . '>';

		echo '<div class="crashtape-card">';
		$this->render_diagnostics();
		echo '</div>';

		echo '<div class="crashtape-card">';
		$this->render_custom_checks();
		echo '</div>';

		echo '<div class="crashtape-card">';
		$this->render_self_test();
		echo '</div>';

		if ( InternalLog::all() ) {
			echo '<div class="crashtape-card">';
			$this->render_internal_log();
			echo '</div>';
		}

		echo '</div>'; // .crashtape-tab-panel#crashtape-tab-diagnostics

		echo '<div id="crashtape-tab-conflict-finder" class="crashtape-tab-panel" role="tabpanel" aria-labelledby="crashtape-tab-btn-conflict-finder" tabindex="0"' . ( 'conflict-finder' === $active_tab ? '' : ' hidden' ) . '>';

		echo '<div class="crashtape-card">';
		$this->render_isolation();
		echo '</div>';

		echo '<div class="crashtape-card">';
		$this->render_conflict_finder();
		echo '</div>';

		echo '</div>'; // .crashtape-tab-panel#crashtape-tab-conflict-finder

		echo '<div id="crashtape-tab-settings" class="crashtape-tab-panel" role="tabpanel" aria-labelledby="crashtape-tab-btn-settings" tabindex="0"' . ( 'settings' === $active_tab ? '' : ' hidden' ) . '>';

		echo '<div class="crashtape-card">';
		$this->render_passive_monitoring();
		echo '</div>';

		echo '<div class="crashtape-card">';
		$this->render_privacy_settings();
		echo '</div>';

		echo '<div class="crashtape-card">';
		$this->render_retention_settings();
		echo '</div>';

		echo '<div class="crashtape-card">';
		$this->render_start_fresh_settings();
		echo '</div>';

		echo '<div class="crashtape-card">';
		$this->render_redaction_settings();
		echo '</div>';

		echo '<div class="crashtape-card">';
		$this->render_branding_settings();
		echo '</div>';

		echo '<div class="crashtape-card">';
		$this->render_uninstall_settings();
		echo '</div>';

		echo '<div class="crashtape-card">';
		$this->render_developer_mode_settings();
		echo '</div>';

		echo '</div>'; // .crashtape-tab-panel#crashtape-tab-settings

		echo '</div>'; // .wrap.crashtape-wrap
	}

	/**
	 * Release-readiness checklist Priority 15 found this the hard way: the
	 * active-recording line only ever showed a static "started <timestamp>";
	 * a non-developer reviewing the Dashboard had to do their own mental
	 * math against a wall-clock time to know how long a session had been
	 * running. `started_at` is stored in GMT (Session::start() uses
	 * current_time('mysql', true)), so `mysql2date('U', ..., false)` (the
	 * `false` specifically skips the site-timezone conversion mysql2date()
	 * would otherwise apply, since the value is already GMT) gives a real
	 * GMT epoch for the browser's own Date.now() to diff against; no
	 * server/client timezone mismatch possible either way, since both
	 * sides are working in UTC milliseconds.
	 */
	private function render_elapsed_script() {
		?>
		<script>
		( function () {
			var el = document.getElementById( 'crashtape-elapsed' );

			if ( ! el ) {
				return;
			}

			var started = parseInt( el.getAttribute( 'data-started' ), 10 ) * 1000;

			function format( ms ) {
				var totalSeconds = Math.max( 0, Math.floor( ms / 1000 ) );
				var hours = Math.floor( totalSeconds / 3600 );
				var minutes = Math.floor( ( totalSeconds % 3600 ) / 60 );
				var seconds = totalSeconds % 60;

				return ( hours > 0 ? hours + 'h ' : '' ) + minutes + 'm ' + seconds + 's';
			}

			function tick() {
				el.textContent = '(' + format( Date.now() - started ) + ' elapsed)';
			}

			tick();
			setInterval( tick, 1000 );
		} )();
		</script>
		<?php
	}

	/**
	 * Adapted admin information architecture: rather than splitting into
	 * separate wp-admin pages (which would require moving
	 * every one of the 27 admin_post_crashtape_* redirect targets to
	 * whichever new page its section landed on), this keeps the single
	 * registered page and organizes its content into a standard WAI-ARIA
	 * tabs pattern instead. Every panel's full content is still always
	 * rendered; hidden via the `hidden` attribute, not omitted; so a
	 * server-rendered page (no JS) still shows the first tab's content
	 * correctly, and existing tests that assert on section content in the
	 * full page HTML are unaffected by which tab happens to be active.
	 */
	private function render_tabs( array $tabs, $active_tab = 'dashboard' ) {
		echo '<h2 class="nav-tab-wrapper crashtape-tabs" role="tablist" aria-label="' . esc_attr__( 'CrashTape sections', 'crashtape' ) . '">';

		foreach ( $tabs as $slug => $label ) {
			$is_active = $slug === $active_tab;
			echo '<button type="button" id="crashtape-tab-btn-' . esc_attr( $slug ) . '" class="nav-tab' . ( $is_active ? ' nav-tab-active' : '' ) . '" role="tab" aria-selected="' . ( $is_active ? 'true' : 'false' ) . '" aria-controls="crashtape-tab-' . esc_attr( $slug ) . '" data-crashtape-tab="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</button>';
		}

		echo '</h2>';
		?>
		<script>
		( function () {
			var buttons = document.querySelectorAll( '.crashtape-tabs [role="tab"]' );

			buttons.forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var slug   = button.getAttribute( 'data-crashtape-tab' );
					var target = document.getElementById( 'crashtape-tab-' + slug );

					buttons.forEach( function ( other ) {
						var panel = document.getElementById( 'crashtape-tab-' + other.getAttribute( 'data-crashtape-tab' ) );
						var active = other === button;

						other.classList.toggle( 'nav-tab-active', active );
						other.setAttribute( 'aria-selected', active ? 'true' : 'false' );

						if ( panel ) {
							panel.hidden = ! active;
						}
					} );

					if ( target ) {
						target.focus();
					}

					// Switching tabs here never reloads the page (every
					// panel's full content is already server-rendered, only
					// `hidden` toggles), so the browser's address bar and
					// every already-rendered form's _wp_http_referer hidden
					// field otherwise keep pointing at whichever tab was
					// active at the last real page load. redirect_after_action()
					// (PHP side) trusts that field to send an action's
					// result back to the right tab; a user switching tabs
					// here first and then submitting a form on the new tab
					// would silently land back on the old one instead. Keep
					// both in sync with what's actually on screen so that
					// stays correct no matter how the user got here.
					var url = new URL( window.location.href );
					url.searchParams.set( 'tab', slug );
					window.history.replaceState( null, '', url );

					document.querySelectorAll( 'input[name="_wp_http_referer"]' ).forEach( function ( input ) {
						input.value = url.pathname + url.search;
					} );
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Conflict isolation. This is just the MU-loader mechanism itself; a
	 * signed cookie, a server-side allowlist, an emergency bypass; not
	 * yet the guided binary-search walkthrough, which is a follow-up on
	 * top of this. Installing/removing the MU file requires administrator
	 * confirmation: an explicit POST with capability + nonce, plus a
	 * native confirm() dialog since this writes to the filesystem.
	 */
	private function render_isolation() {
		echo '<h2>' . esc_html__( 'Conflict Isolation (experimental)', 'crashtape' ) . '</h2>';

		$installed = IsolationLoader::is_installed();

		if ( ! $installed ) {
			// Release-readiness checklist Priority 15 found this the hard
			// way: this explanation previously only existed inside the
			// Install button's confirm() dialog; visible only after a
			// user had already decided to click, not before, when they'd
			// actually need it to decide whether to. A first-time,
			// non-developer visitor to this tab saw only a status line and
			// a button with no visible context at all.
			echo '<p>' . esc_html__( 'The Conflict Finder below narrows down which plugin is causing a problem by temporarily loading a reduced plugin set, but only for one specific diagnostic browser, identified by a signed cookie. This "MU loader" is what makes that possible: a small file in wp-content/mu-plugins/ that filters which plugins load for that one browser only. It never changes the site\'s real active-plugins list, every other visitor is unaffected the whole time, and it can be removed at any time (including an emergency bypass if a test ever leaves your own diagnostic browser in a state you need to immediately back out of).', 'crashtape' ) . '</p>';
		}

		echo '<p>' . esc_html(
			$installed
				? __( 'MU loader status: installed.', 'crashtape' )
				: __( 'MU loader status: not installed.', 'crashtape' )
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
		if ( $installed ) {
			wp_nonce_field( 'crashtape_isolation_uninstall' );
			echo '<input type="hidden" name="action" value="crashtape_isolation_uninstall" />';
			echo '<button type="submit" class="button" onclick="return confirm(\'' . esc_js( __( 'Remove the CrashTape MU loader? This immediately restores normal plugin loading.', 'crashtape' ) ) . '\');">' . esc_html__( 'Remove MU Loader', 'crashtape' ) . '</button>';
		} else {
			wp_nonce_field( 'crashtape_isolation_install' );
			echo '<input type="hidden" name="action" value="crashtape_isolation_install" />';
			echo '<button type="submit" class="button" onclick="return confirm(\'' . esc_js( __( 'Install the CrashTape MU loader? This writes a file to wp-content/mu-plugins/. It only ever filters which plugins load for a browser carrying a valid isolation cookie, and can be removed at any time.', 'crashtape' ) ) . '\');">' . esc_html__( 'Install MU Loader', 'crashtape' ) . '</button>';
		}
		echo '</form>';

		if ( ! $installed ) {
			return;
		}

		$bypass = (bool) get_option( IsolationToken::BYPASS_OPTION );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
		wp_nonce_field( 'crashtape_isolation_bypass' );
		echo '<input type="hidden" name="action" value="crashtape_isolation_bypass" />';
		echo '<input type="hidden" name="enable" value="' . ( $bypass ? '0' : '1' ) . '" />';
		echo '<button type="submit" class="button">' . esc_html( $bypass ? __( 'Disable Emergency Bypass', 'crashtape' ) : __( 'Enable Emergency Bypass', 'crashtape' ) ) . '</button>';
		echo '</form>';
		echo '<p><em>' . esc_html__( 'Emergency bypass immediately stops the MU loader from filtering anything, without removing the file.', 'crashtape' ) . '</em></p>';

		if ( IsolationToken::is_active() ) {
			$allowlist = IsolationToken::current_allowlist();
			echo '<p><strong>' . esc_html__( 'Isolation test active in this browser.', 'crashtape' ) . '</strong> ' . esc_html(
				sprintf(
					/* translators: %d: number of plugins kept active */
					__( 'Keeping %d plugin(s) active for this browser only.', 'crashtape' ),
					is_array( $allowlist ) ? count( $allowlist ) : 0
				)
			) . '</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'crashtape_isolation_stop' );
			echo '<input type="hidden" name="action" value="crashtape_isolation_stop" />';
			submit_button( __( 'Stop Isolation Test', 'crashtape' ), 'secondary' );
			echo '</form>';
			return;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$all_plugins    = get_plugins();
		$own_basename   = plugin_basename( CRASHTAPE_FILE );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_isolation_start' );
		echo '<input type="hidden" name="action" value="crashtape_isolation_start" />';
		echo '<fieldset><legend>' . esc_html__( 'Keep active for this test (CrashTape itself is always kept):', 'crashtape' ) . '</legend>';

		foreach ( $active_plugins as $plugin_file ) {
			if ( $plugin_file === $own_basename ) {
				continue;
			}

			$name = isset( $all_plugins[ $plugin_file ]['Name'] ) ? $all_plugins[ $plugin_file ]['Name'] : $plugin_file;

			echo '<p><label><input type="checkbox" name="keep_plugins[]" value="' . esc_attr( $plugin_file ) . '" checked="checked" /> ' . esc_html( $name ) . '</label></p>';
		}

		echo '</fieldset>';
		submit_button( __( 'Start Isolation Test', 'crashtape' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Third-party diagnostics registered via crashtape_register_diagnostic(),
	 * shown on their own distinct "Diagnostics" admin surface. Runs
	 * on every page load rather than on-demand like self-test, since these
	 * are meant to be cheap status checks, not integration tests.
	 */
	/**
	 * Compare a working session against a failing one. Read-only, so a
	 * plain GET form (no admin-post round trip, no nonce needed; it
	 * changes nothing) is enough; the same page reload just shows the
	 * diff below.
	 */
	private function render_comparison() {
		echo '<h2>' . esc_html__( 'Compare Sessions', 'crashtape' ) . '</h2>';

		$sessions = Session::recent( 20 );

		if ( count( $sessions ) < 2 ) {
			echo '<p>' . esc_html__( 'Need at least two completed sessions to compare.', 'crashtape' ) . '</p>';
			return;
		}

		$uuid_a = isset( $_GET['compare_a'] ) ? sanitize_text_field( wp_unslash( $_GET['compare_a'] ) ) : '';
		$uuid_b = isset( $_GET['compare_b'] ) ? sanitize_text_field( wp_unslash( $_GET['compare_b'] ) ) : '';

		echo '<form method="get" action="' . esc_url( admin_url( 'tools.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="sessions" />';

		foreach ( array(
			'compare_a' => __( 'Working session', 'crashtape' ),
			'compare_b' => __( 'Failing session', 'crashtape' ),
		) as $field => $field_label ) {
			echo '<p><label for="crashtape-' . esc_attr( $field ) . '">' . esc_html( $field_label ) . '</label><br />';
			echo '<select id="crashtape-' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '">';
			echo '<option value="">' . esc_html__( 'Choose a session', 'crashtape' ) . '</option>';

			foreach ( $sessions as $session ) {
				$current = ( 'compare_a' === $field ? $uuid_a : $uuid_b );
				echo '<option value="' . esc_attr( $session['uuid'] ) . '" ' . selected( $current, $session['uuid'], false ) . '>'
					. esc_html( ( $session['label'] ? $session['label'] : $session['uuid'] ) . ' (' . get_date_from_gmt( $session['started_at'] ) . ')' )
					. '</option>';
			}

			echo '</select></p>';
		}

		submit_button( __( 'Compare', 'crashtape' ), 'secondary' );
		echo '</form>';

		if ( '' === $uuid_a || '' === $uuid_b ) {
			return;
		}

		$session_a = Session::find_by_uuid( $uuid_a );
		$session_b = Session::find_by_uuid( $uuid_b );

		if ( ! $session_a || ! $session_b ) {
			echo '<p>' . esc_html__( 'One or both sessions could not be found.', 'crashtape' ) . '</p>';
			return;
		}

		$diff = Comparison::compare( $session_a, $session_b );

		echo '<h3>' . esc_html__( 'Environment differences', 'crashtape' ) . '</h3>';

		if ( ! $diff['environment_diff'] ) {
			echo '<p>' . esc_html__( 'No environment differences.', 'crashtape' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Field', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Working', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Failing', 'crashtape' ) . '</th></tr></thead><tbody>';
			foreach ( $diff['environment_diff'] as $row ) {
				echo '<tr><td>' . esc_html( $row['field'] ) . '</td><td>' . esc_html( $row['a'] ) . '</td><td>' . esc_html( $row['b'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Plugin version differences', 'crashtape' ) . '</h3>';

		if ( ! $diff['plugin_diff'] ) {
			echo '<p>' . esc_html__( 'No plugin version differences.', 'crashtape' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Plugin', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Working', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Failing', 'crashtape' ) . '</th></tr></thead><tbody>';
			foreach ( $diff['plugin_diff'] as $row ) {
				echo '<tr><td>' . esc_html( $row['slug'] ) . '</td><td>' . esc_html( $row['a'] ? $row['a'] : '(not active)' ) . '</td><td>' . esc_html( $row['b'] ? $row['b'] : '(not active)' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Errors only in the failing session', 'crashtape' ) . '</h3>';
		$this->render_fingerprint_list( $diff['fingerprint_diff']['only_in_b'] );

		echo '<h3>' . esc_html__( 'Errors only in the working session', 'crashtape' ) . '</h3>';
		$this->render_fingerprint_list( $diff['fingerprint_diff']['only_in_a'] );
	}

	private function render_fingerprint_list( array $entries ) {
		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'None.', 'crashtape' ) . '</p>';
			return;
		}

		echo '<ul>';
		foreach ( $entries as $entry ) {
			echo '<li>[' . esc_html( strtoupper( $entry['severity'] ) ) . '] ' . esc_html( $entry['summary'] );
			if ( $entry['occurrence_count'] > 1 ) {
				echo ' ' . esc_html( sprintf( '(×%d)', $entry['occurrence_count'] ) );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	private function render_diagnostics() {
		echo '<h2>' . esc_html__( 'Diagnostics', 'crashtape' ) . '</h2>';

		$results = Registry::run_diagnostics();

		if ( ! $results ) {
			echo '<p>' . esc_html__( 'No diagnostics registered. Other plugins can add one via crashtape_register_diagnostic().', 'crashtape' ) . '</p>';
			return;
		}

		echo '<div class="crashtape-table-wrap"><table class="widefat striped">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Diagnostic', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Status', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Summary', 'crashtape' ) . '</th></tr></thead><tbody>';

		foreach ( $results as $result ) {
			echo '<tr>';
			echo '<td>' . esc_html( $result['label'] ) . '</td>';
			echo '<td><span class="crashtape-pill crashtape-pill--' . esc_attr( $result['status'] ) . '">' . esc_html( strtoupper( $result['status'] ) ) . '</span></td>';
			echo '<td>' . esc_html( $result['summary'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Unlike the SDK's crashtape_register_diagnostic()
	 * above (which needs a developer to write a PHP callback), these are
	 * defined entirely through this form; a handful of read-only
	 * assertion types, each capped/sanitized in CustomChecks itself.
	 */
	private function render_custom_checks() {
		echo '<h2>' . esc_html__( 'Custom Checks', 'crashtape' ) . '</h2>';

		$checks = CustomChecks::all();

		if ( $checks ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'crashtape_check_run' );
			echo '<input type="hidden" name="action" value="crashtape_check_run" />';
			submit_button( __( 'Run All Checks', 'crashtape' ), 'secondary' );
			echo '</form>';

			$results       = get_transient( self::CUSTOM_CHECKS_TRANSIENT );
			$results_by_id = array();

			if ( is_array( $results ) ) {
				foreach ( $results as $result ) {
					$results_by_id[ $result['id'] ] = $result;
				}
			}

			echo '<div class="crashtape-table-wrap"><table class="widefat striped">';
			echo '<thead><tr><th scope="col">' . esc_html__( 'Check', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Type', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Result', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'crashtape' ) . '</th></tr></thead><tbody>';

			foreach ( $checks as $check ) {
				$result = isset( $results_by_id[ $check['id'] ] ) ? $results_by_id[ $check['id'] ] : null;

				echo '<tr>';
				echo '<td>' . esc_html( $check['label'] ) . '</td>';
				echo '<td>' . esc_html( $check['type'] ) . '</td>';
				echo '<td>' . ( $result ? '<span class="crashtape-pill crashtape-pill--' . esc_attr( $result['status'] ) . '">' . esc_html( strtoupper( $result['status'] ) ) . '</span> ' . esc_html( $result['message'] ) : esc_html__( 'Not run yet.', 'crashtape' ) ) . '</td>';
				echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'crashtape_check_delete' );
				echo '<input type="hidden" name="action" value="crashtape_check_delete" />';
				echo '<input type="hidden" name="check_id" value="' . esc_attr( $check['id'] ) . '" />';
				submit_button( __( 'Delete', 'crashtape' ), 'small', 'submit', false );
				echo '</form></td>';
				echo '</tr>';
			}

			echo '</tbody></table></div>';
		} else {
			echo '<p>' . esc_html__( 'No custom checks defined yet.', 'crashtape' ) . '</p>';
		}

		echo '<h3>' . esc_html__( 'Add a Check', 'crashtape' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_check_add' );
		echo '<input type="hidden" name="action" value="crashtape_check_add" />';

		echo '<p><label for="crashtape-check-label">' . esc_html__( 'Label:', 'crashtape' ) . '</label> ';
		echo '<input type="text" id="crashtape-check-label" name="label" class="regular-text" maxlength="100" required /></p>';

		echo '<p><label for="crashtape-check-type">' . esc_html__( 'Type:', 'crashtape' ) . '</label> ';
		echo '<select id="crashtape-check-type" name="type">';
		echo '<option value="' . esc_attr( CustomChecks::TYPE_OPTION_EQUALS ) . '">' . esc_html__( 'WordPress option equals a value', 'crashtape' ) . '</option>';
		echo '<option value="' . esc_attr( CustomChecks::TYPE_CONSTANT_DEFINED ) . '">' . esc_html__( 'PHP constant is defined', 'crashtape' ) . '</option>';
		echo '<option value="' . esc_attr( CustomChecks::TYPE_URL_STATUS ) . '">' . esc_html__( 'URL responds with an expected HTTP status', 'crashtape' ) . '</option>';
		echo '<option value="' . esc_attr( CustomChecks::TYPE_PLUGIN_ACTIVE ) . '">' . esc_html__( 'A plugin is active', 'crashtape' ) . '</option>';
		echo '</select></p>';

		echo '<p>' . esc_html__( 'Option equals (option name and expected value):', 'crashtape' ) . ' ';
		echo '<input type="text" name="option_name" placeholder="' . esc_attr__( 'option name', 'crashtape' ) . '" /> ';
		echo '<input type="text" name="expected_value" placeholder="' . esc_attr__( 'expected value', 'crashtape' ) . '" /></p>';

		echo '<p>' . esc_html__( 'Constant defined (constant name):', 'crashtape' ) . ' ';
		echo '<input type="text" name="constant_name" placeholder="' . esc_attr__( 'e.g. WP_DEBUG', 'crashtape' ) . '" /></p>';

		echo '<p>' . esc_html__( 'URL status (URL and expected status code(s)):', 'crashtape' ) . ' ';
		echo '<input type="url" name="url" placeholder="https://example.com/" /> ';
		echo '<input type="text" name="expected_status" placeholder="200" style="width:6em;" /></p>';

		echo '<p>' . esc_html__( 'Plugin active (plugin file, e.g. woocommerce/woocommerce.php):', 'crashtape' ) . ' ';
		echo '<input type="text" name="plugin_file" placeholder="plugin-folder/plugin-file.php" /></p>';

		echo '<p><em>' . esc_html__( 'Only the fields matching the selected type above are used.', 'crashtape' ) . '</em></p>';

		submit_button( __( 'Add Check', 'crashtape' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Off by default, independent of any recording session.
	 * Settings + the rolling stats display live in one section since
	 * there's nothing to show until it's been enabled for a while.
	 */
	private function render_passive_monitoring() {
		echo '<h2>' . esc_html__( 'Passive Monitoring', 'crashtape' ) . '</h2>';
		// Release-readiness checklist Priority 17 "avoid unnecessary jargon
		// in default views": this settings description previously used
		// "WP-Cron/Action Scheduler", "browser instrumentation", and "error
		// fingerprints" back to back with no framing; reworded to the same
		// plain terms already used elsewhere in this UI ("Scheduled Tasks",
		// "recurring errors") without losing any real information.
		echo '<p>' . esc_html__( 'Optional, low-volume background monitoring that runs independently of any recording session: PHP fatal errors, mail failures, outbound HTTP failures, and periodic scheduled-task backlog checks. Nothing in the browser is tracked, and no full event detail is kept: only rolling daily counts and a short list of the most common recurring errors.', 'crashtape' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_save_passive_monitoring' );
		echo '<input type="hidden" name="action" value="crashtape_save_passive_monitoring" />';
		echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( PassiveMonitor::enabled(), true, false ) . ' /> ' . esc_html__( 'Enable passive monitoring', 'crashtape' ) . '</label></p>';
		echo '<p><label for="crashtape-passive-retention">' . esc_html__( 'Keep daily counts for (days):', 'crashtape' ) . '</label> ';
		echo '<input type="number" id="crashtape-passive-retention" name="retention_days" min="' . esc_attr( PassiveMonitor::RETENTION_MIN ) . '" max="' . esc_attr( PassiveMonitor::RETENTION_MAX ) . '" value="' . esc_attr( PassiveMonitor::retention_days() ) . '" class="small-text" /></p>';
		submit_button( __( 'Save Passive Monitoring Setting', 'crashtape' ), 'secondary' );
		echo '</form>';

		if ( ! PassiveMonitor::enabled() ) {
			return;
		}

		$stats = PassiveMonitor::stats();

		echo '<h3>' . esc_html__( 'Recent Daily Counts', 'crashtape' ) . '</h3>';

		if ( ! $stats['days'] ) {
			echo '<p>' . esc_html__( 'No data yet.', 'crashtape' ) . '</p>';
		} else {
			$days = $stats['days'];
			krsort( $days );

			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Day', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Counts', 'crashtape' ) . '</th></tr></thead><tbody>';

			foreach ( array_slice( $days, 0, 14, true ) as $day => $counters ) {
				$parts = array();

				foreach ( $counters as $type => $count ) {
					$parts[] = sprintf( '%s: %d', $type, $count );
				}

				echo '<tr><td>' . esc_html( $day ) . '</td><td>' . esc_html( implode( ', ', $parts ) ) . '</td></tr>';
			}

			echo '</tbody></table>';
		}

		if ( $stats['fingerprints'] ) {
			echo '<h3>' . esc_html__( 'Recurring Errors', 'crashtape' ) . '</h3>';

			$fingerprints = $stats['fingerprints'];
			uasort(
				$fingerprints,
				function ( $a, $b ) {
					return $b['count'] <=> $a['count'];
				}
			);

			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Type', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Summary', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Count', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Last Seen', 'crashtape' ) . '</th></tr></thead><tbody>';

			foreach ( array_slice( $fingerprints, 0, 20 ) as $fp ) {
				echo '<tr>';
				echo '<td>' . esc_html( $fp['type'] ) . '</td>';
				echo '<td>' . esc_html( $fp['summary'] ) . '</td>';
				echo '<td>' . esc_html( $fp['count'] ) . '</td>';
				echo '<td>' . esc_html( get_date_from_gmt( $fp['last_seen'] ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_clear_passive_monitoring' );
		echo '<input type="hidden" name="action" value="crashtape_clear_passive_monitoring" />';
		submit_button( __( 'Clear Passive Monitoring Data', 'crashtape' ), 'delete' );
		echo '</form>';
	}

	/**
	 * Guided binary-search conflict finder, built on the isolation
	 * mechanism above. Roughly halves the suspect pool each round based
	 * on a Yes/No/Unable-to-Test answer. Automated Mode drives the same
	 * rounds via an existing url_status custom check instead of manual
	 * clicks; see ConflictFinder::run_automated()'s docblock for why only
	 * that check type can meaningfully drive it.
	 */
	private function render_conflict_finder() {
		echo '<h2>' . esc_html__( 'Conflict Finder (experimental)', 'crashtape' ) . '</h2>';

		if ( ! IsolationLoader::is_installed() ) {
			echo '<p>' . esc_html__( 'A guided, binary-search process that narrows down which plugin is causing a problem by testing with roughly half your plugins temporarily disabled, for one diagnostic browser only, never for other visitors. Requires the MU loader above first, which is what actually performs the isolation.', 'crashtape' ) . '</p>';
			return;
		}

		echo '<p><em>' . esc_html__( 'This only isolates regular plugins. Any active MU plugin, drop-in, or the current theme stays active throughout and is not part of this test.', 'crashtape' ) . '</em></p>';

		$state = ConflictFinder::current();

		if ( ! $state ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'crashtape_finder_start' );
			echo '<input type="hidden" name="action" value="crashtape_finder_start" />';
			submit_button( __( 'Start Conflict Finder', 'crashtape' ), 'primary' );
			echo '</form>';

			$this->render_automated_finder_start();

			return;
		}

		if ( 'complete' === $state['status'] ) {
			$name = $this->plugin_display_name( $state['result'] );
			echo '<p><strong>' . esc_html(
				sprintf(
					/* translators: %s: plugin name */
					__( 'Likely conflicting plugin: %s', 'crashtape' ),
					$name
				)
			) . '</strong></p>';
			echo '<p>' . esc_html__( 'This is a narrowing result, not certainty; confirm by re-testing with only that plugin active.', 'crashtape' ) . '</p>';
		} elseif ( 'inconclusive' === $state['status'] ) {
			echo '<p>' . esc_html__( 'Inconclusive: the last plugin tested was cleared, but no other candidate remained. The problem may not be a single-plugin conflict, or may involve an MU plugin, drop-in, or the theme.', 'crashtape' ) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: round number 2: number of plugins being tested 3: number of remaining candidates */
					__( 'Round %1$d: keeping %2$d plugin(s) active out of %3$d remaining candidate(s).', 'crashtape' ),
					$state['round'],
					count( $state['testing'] ),
					count( $state['candidates'] )
				)
			) . '</p>';

			echo '<ul>';
			foreach ( $state['testing'] as $plugin_file ) {
				echo '<li>' . esc_html( $this->plugin_display_name( $plugin_file ) ) . '</li>';
			}
			echo '</ul>';

			if ( ! empty( $state['dependencies_included'] ) ) {
				echo '<p><em>' . esc_html__( 'Also kept active: declared as a dependency of one of the plugins above:', 'crashtape' ) . '</em></p>';
				echo '<ul>';
				foreach ( $state['dependencies_included'] as $plugin_file ) {
					echo '<li>' . esc_html( $this->plugin_display_name( $plugin_file ) ) . '</li>';
				}
				echo '</ul>';
			}

			echo '<p>' . esc_html__( 'Reproduce the problem now (open the site in a new tab in this same browser), then answer:', 'crashtape' ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Is the problem still happening?', 'crashtape' ) . '</strong></p>';

			foreach ( array(
				'yes'    => __( 'Yes', 'crashtape' ),
				'no'     => __( 'No', 'crashtape' ),
				'unable' => __( 'Unable to Test', 'crashtape' ),
			) as $value => $label ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
				wp_nonce_field( 'crashtape_finder_answer' );
				echo '<input type="hidden" name="action" value="crashtape_finder_answer" />';
				echo '<input type="hidden" name="verdict" value="' . esc_attr( $value ) . '" />';
				echo '<button type="submit" class="button">' . esc_html( $label ) . '</button>';
				echo '</form>';
			}
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
		wp_nonce_field( 'crashtape_finder_stop' );
		echo '<input type="hidden" name="action" value="crashtape_finder_stop" />';
		submit_button( __( 'Cancel Conflict Finder', 'crashtape' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Only offered when at least one url_status custom check already
	 * exists (Tools → CrashTape → Diagnostics); Automated Mode reuses
	 * that definition rather than a second URL/status input.
	 */
	private function render_automated_finder_start() {
		$url_checks = array_values(
			array_filter(
				CustomChecks::all(),
				function ( $check ) {
					return CustomChecks::TYPE_URL_STATUS === $check['type'];
				}
			)
		);

		if ( ! $url_checks ) {
			echo '<p><em>' . esc_html__( 'Automated Mode: define a URL-status custom check under Tools → CrashTape → Diagnostics to run the finder automatically instead of answering Yes/No each round.', 'crashtape' ) . '</em></p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
		wp_nonce_field( 'crashtape_finder_start_automated' );
		echo '<input type="hidden" name="action" value="crashtape_finder_start_automated" />';
		echo '<label for="crashtape-automated-check">' . esc_html__( 'Automated Mode, run every round automatically using:', 'crashtape' ) . '</label> ';
		echo '<select id="crashtape-automated-check" name="check_id">';
		foreach ( $url_checks as $check ) {
			echo '<option value="' . esc_attr( $check['id'] ) . '">' . esc_html( $check['label'] ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Run Automated', 'crashtape' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	private function plugin_display_name( $plugin_file ) {
		if ( ! $plugin_file ) {
			return '-';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all = get_plugins();

		return isset( $all[ $plugin_file ]['Name'] ) ? $all[ $plugin_file ]['Name'] : $plugin_file;
	}

	/**
	 * A troubleshooting plugin must be able to troubleshoot itself. Runs
	 * on demand (it makes a real HTTP round-trip and a real DB write),
	 * not on every page load.
	 */
	private function render_self_test() {
		echo '<h2>' . esc_html__( 'Self-Test', 'crashtape' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_self_test' );
		echo '<input type="hidden" name="action" value="crashtape_self_test" />';
		submit_button( __( 'Run Self-Test', 'crashtape' ), 'secondary' );
		echo '</form>';

		$results = get_transient( self::SELF_TEST_TRANSIENT );

		if ( ! is_array( $results ) ) {
			return;
		}

		$plain_lines = array( 'CrashTape Self-Test: ' . current_time( 'mysql' ) );

		echo '<div class="crashtape-table-wrap"><table class="widefat striped" id="crashtape-self-test-table">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Check', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Status', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Detail', 'crashtape' ) . '</th></tr></thead><tbody>';

		foreach ( $results as $check ) {
			$status_label = strtoupper( $check['status'] );
			echo '<tr>';
			echo '<td>' . esc_html( $check['label'] ) . '</td>';
			echo '<td><span class="crashtape-pill crashtape-pill--' . esc_attr( $check['status'] ) . '">' . esc_html( $status_label ) . '</span></td>';
			echo '<td>' . esc_html( $check['message'] ) . '</td>';
			echo '</tr>';

			$plain_lines[] = sprintf( '[%s] %s: %s', $status_label, $check['label'], $check['message'] );
		}

		echo '</tbody></table></div>';

		$plain_text = implode( "\n", $plain_lines );

		echo '<p><button type="button" class="button" id="crashtape-copy-self-test">' . esc_html__( 'Copy Self-Test Results', 'crashtape' ) . '</button></p>';
		echo '<textarea id="crashtape-self-test-plain" class="crashtape-visually-hidden" readonly>' . esc_textarea( $plain_text ) . '</textarea>';
		?>
		<script>
		( function () {
			var button = document.getElementById( 'crashtape-copy-self-test' );
			if ( ! button ) { return; }
			button.addEventListener( 'click', function () {
				var textarea = document.getElementById( 'crashtape-self-test-plain' );
				var done = function () {
					button.textContent = '<?php echo esc_js( __( 'Copied!', 'crashtape' ) ); ?>';
					setTimeout( function () {
						button.textContent = '<?php echo esc_js( __( 'Copy Self-Test Results', 'crashtape' ) ); ?>';
					}, 2000 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( textarea.value ).then( done, function () {
						textarea.select();
						document.execCommand( 'copy' );
						done();
					} );
				} else {
					textarea.select();
					document.execCommand( 'copy' );
					done();
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * CrashTape's own operational failures (migration,
	 * storage, export, ingestion validation, integration init), entirely
	 * separate from diagnostic event data. Hidden entirely when empty
	 * (the common, healthy case) rather than showing an empty table.
	 */
	private function render_internal_log() {
		$entries = InternalLog::all();

		if ( ! $entries ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Internal Log', 'crashtape' ) . '</h2>';
		echo '<p>' . esc_html__( 'Operational failures in CrashTape itself, not diagnostic data about your site.', 'crashtape' ) . '</p>';

		echo '<div class="crashtape-table-wrap"><table class="widefat striped">';
		echo '<thead><tr><th scope="col">' . esc_html__( 'Time', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Category', 'crashtape' ) . '</th><th scope="col">' . esc_html__( 'Message', 'crashtape' ) . '</th></tr></thead><tbody>';

		foreach ( array_reverse( $entries ) as $entry ) {
			echo '<tr>';
			echo '<td>' . esc_html( get_date_from_gmt( $entry['time'] ) ) . '</td>';
			echo '<td><span class="crashtape-pill crashtape-pill--error">' . esc_html( str_replace( '_', ' ', $entry['category'] ) ) . '</span></td>';
			echo '<td>' . esc_html( $entry['message'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
		wp_nonce_field( 'crashtape_clear_internal_log' );
		echo '<input type="hidden" name="action" value="crashtape_clear_internal_log" />';
		submit_button( __( 'Clear Internal Log', 'crashtape' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * All three privacy profiles. Advanced retains full email
	 * addresses and the complete query string (Redactor::redact_email()/
	 * redact_query_string()); everything else on the never-capture-by-default
	 * list has no capture surface in this codebase at
	 * all yet, for any profile, so there's nothing further Advanced can
	 * meaningfully loosen here without inventing a new capture channel.
	 */
	private function render_privacy_settings() {
		$profile = Redactor::profile();

		echo '<h2>' . esc_html__( 'Privacy', 'crashtape' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_save_privacy' );
		echo '<input type="hidden" name="action" value="crashtape_save_privacy" />';

		echo '<fieldset><legend>' . esc_html__( 'Privacy profile', 'crashtape' ) . '</legend>';
		echo '<p><label><input type="radio" name="profile" value="' . esc_attr( Redactor::PROFILE_STRICT ) . '" ' . checked( $profile, Redactor::PROFILE_STRICT, false ) . ' /> '
			. esc_html__( 'Strict: redact email addresses entirely, omit query strings entirely (default)', 'crashtape' ) . '</label></p>';
		echo '<p><label><input type="radio" name="profile" value="' . esc_attr( Redactor::PROFILE_BALANCED ) . '" ' . checked( $profile, Redactor::PROFILE_BALANCED, false ) . ' /> '
			. esc_html__( 'Balanced: retain email domain only (e.g. [redacted]@example.com), and allowlisted query parameters below', 'crashtape' ) . '</label></p>';
		echo '<p><label><input type="radio" name="profile" value="' . esc_attr( Redactor::PROFILE_ADVANCED ) . '" ' . checked( $profile, Redactor::PROFILE_ADVANCED, false ) . ' /> '
			. esc_html__( 'Advanced: retain full email addresses and complete query strings (every parameter, not just the allowlist below)', 'crashtape' ) . '</label></p>';
		echo '</fieldset>';

		if ( Redactor::PROFILE_ADVANCED === $profile ) {
			echo '<p><strong>' . esc_html__( 'Warning:', 'crashtape' ) . '</strong> ' . esc_html__( 'Advanced retains more than the other profiles: full email addresses and every query string parameter (e.g. search terms, IDs) captured sessions record from now on. Values are still scanned for recognizable secret patterns, but this is not a substitute for Strict/Balanced on a site with sensitive query data. Only enable this if you understand the tradeoff.', 'crashtape' ) . '</p>';
		}

		echo '<p><label for="crashtape-query-allowlist">' . esc_html__( 'Query parameters safe to retain under Balanced (one per line, e.g. page, s, orderby). Ignored under Strict; every parameter is already retained under Advanced regardless of this list.', 'crashtape' ) . '</label><br />';
		echo '<textarea id="crashtape-query-allowlist" name="query_allowlist" rows="4" cols="50" class="large-text code">'
			. esc_textarea( implode( "\n", Redactor::query_param_allowlist() ) ) . '</textarea></p>';

		submit_button( __( 'Save Privacy Setting', 'crashtape' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Configurable 1-365 days, not a fixed tier ceiling.
	 */
	private function render_retention_settings() {
		echo '<h2>' . esc_html__( 'Retention', 'crashtape' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_save_retention' );
		echo '<input type="hidden" name="action" value="crashtape_save_retention" />';
		echo '<p><label for="crashtape-retention-days">' . esc_html__( 'Keep sessions and support packages for (days):', 'crashtape' ) . '</label><br />';
		echo '<input type="number" id="crashtape-retention-days" name="retention_days" min="' . esc_attr( Session::RETENTION_MIN ) . '" max="' . esc_attr( Session::RETENTION_MAX ) . '" value="' . esc_attr( Session::retention_days() ) . '" class="small-text" /></p>';
		submit_button( __( 'Save Retention Setting', 'crashtape' ), 'secondary' );
		echo '</form>';
	}

	private function render_start_fresh_settings() {
		echo '<h2>' . esc_html__( 'Start Fresh', 'crashtape' ) . '</h2>';
		echo '<p>' . esc_html__( 'Delete every completed diagnostic session (and its requests, events, and support packages) and the entire recent-changes journal, right now, regardless of the retention period above. An active recording in progress, if any, is not affected. Settings, the internal log, and Passive Monitoring stats are not affected either; those each have their own clear action.', 'crashtape' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_purge_all' );
		echo '<input type="hidden" name="action" value="crashtape_purge_all" />';
		submit_button(
			__( 'Clear All Diagnostic Data', 'crashtape' ),
			'secondary',
			'submit',
			true,
			array( 'onclick' => "return confirm('" . esc_js( __( 'Delete every completed diagnostic session and all recorded changes? This cannot be undone.', 'crashtape' ) ) . "');" )
		);
		echo '</form>';
	}

	/**
	 * Additive to the built-in key/pattern lists in
	 * Redactor, never a replacement for them. One rule per line for both
	 * fields, capped at Redactor::MAX_CUSTOM_RULES each.
	 */
	private function render_redaction_settings() {
		echo '<h2>' . esc_html__( 'Custom Redaction Rules', 'crashtape' ) . '</h2>';
		echo '<p>' . esc_html__( 'These apply in addition to the built-in secret detection above; they cannot loosen it.', 'crashtape' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_save_redaction' );
		echo '<input type="hidden" name="action" value="crashtape_save_redaction" />';

		echo '<p><label for="crashtape-redaction-keys">' . esc_html__( 'Additional field names to redact (one per line, matched as a substring):', 'crashtape' ) . '</label><br />';
		echo '<textarea id="crashtape-redaction-keys" name="redaction_keys" rows="4" cols="50" class="large-text code">'
			. esc_textarea( implode( "\n", Redactor::custom_keys() ) ) . '</textarea></p>';

		echo '<p><label for="crashtape-redaction-patterns">' . esc_html__( 'Additional regex patterns to mask in captured text (one per line, full PCRE incl. delimiters, e.g. /internal-[a-z0-9]+/i):', 'crashtape' ) . '</label><br />';
		echo '<textarea id="crashtape-redaction-patterns" name="redaction_patterns" rows="4" cols="50" class="large-text code">'
			. esc_textarea( implode( "\n", Redactor::custom_patterns() ) ) . '</textarea></p>';
		echo '<p>' . esc_html__( 'An invalid pattern is dropped on save rather than applied.', 'crashtape' ) . '</p>';

		submit_button( __( 'Save Redaction Rules', 'crashtape' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Only affects summary.html; the report a
	 * professional user actually hands to a client; never the JSON data
	 * files, which stay plain/machine-readable.
	 */
	private function render_branding_settings() {
		$logo = Branding::logo_data_uri();

		echo '<h2>' . esc_html__( 'Report Branding', 'crashtape' ) . '</h2>';
		echo '<p>' . esc_html__( 'Shown on the human-readable summary.html included in every exported support package.', 'crashtape' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		wp_nonce_field( 'crashtape_save_branding' );
		echo '<input type="hidden" name="action" value="crashtape_save_branding" />';

		echo '<p><label for="crashtape-org-name">' . esc_html__( 'Your name or company name:', 'crashtape' ) . '</label><br />';
		echo '<input type="text" id="crashtape-org-name" name="org_name" value="' . esc_attr( Branding::org_name() ) . '" class="regular-text" maxlength="' . esc_attr( Branding::MAX_ORG_NAME_LENGTH ) . '" /></p>';

		echo '<p><label for="crashtape-footer-note">' . esc_html__( 'Footer note (e.g. contact details):', 'crashtape' ) . '</label><br />';
		echo '<textarea id="crashtape-footer-note" name="footer_note" rows="3" cols="50" class="large-text" maxlength="' . esc_attr( Branding::MAX_FOOTER_LENGTH ) . '">' . esc_textarea( Branding::footer_note() ) . '</textarea></p>';

		echo '<p><label for="crashtape-logo">' . esc_html__( 'Logo (PNG, JPEG, or GIF, 50KB max, embedded directly in the report):', 'crashtape' ) . '</label><br />';

		if ( $logo ) {
			echo '<img src="' . esc_attr( $logo ) . '" alt="" style="max-height:40px;max-width:200px;display:block;margin-bottom:6px;" />';
			echo '<label><input type="checkbox" name="remove_logo" value="1" /> ' . esc_html__( 'Remove current logo', 'crashtape' ) . '</label><br />';
		}

		echo '<input type="file" id="crashtape-logo" name="logo" accept="image/png,image/jpeg,image/gif" /></p>';

		submit_button( __( 'Save Report Branding', 'crashtape' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Asks through settings beforehand how data should be
	 * handled on uninstall. Defaults to deleting everything (unchecked);
	 * matching this plugin's existing documented behavior and its own
	 * "Uninstalling the plugin removes every table, option, transient, and
	 * stored file it created" claim (readme.txt); so nobody's uninstall
	 * behavior silently changes just because this setting now exists; a
	 * user has to explicitly opt into keeping their data.
	 */
	private function render_uninstall_settings() {
		echo '<h2>' . esc_html__( 'Uninstall Behavior', 'crashtape' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose what happens to sessions, settings, and support packages if you ever delete this plugin (not on ordinary deactivation).', 'crashtape' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_save_uninstall_preference' );
		echo '<input type="hidden" name="action" value="crashtape_save_uninstall_preference" />';

		echo '<p><label><input type="checkbox" name="keep_data" value="1" ' . checked( Uninstaller::keep_data(), true, false ) . ' /> '
			. esc_html__( 'Keep my diagnostic data if this plugin is deleted', 'crashtape' ) . '</label></p>';

		submit_button( __( 'Save Uninstall Preference', 'crashtape' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Most likely issue, confidence, evidence, suggested checks. Analysis
	 * is only computed at
	 * Session::stop(), so an active session shows nothing here yet.
	 */
	private function render_analysis( array $session ) {
		if ( empty( $session['analysis'] ) ) {
			return;
		}

		$analysis = json_decode( $session['analysis'], true );

		if ( ! is_array( $analysis ) || empty( $analysis['candidates'] ) ) {
			echo '<h2>' . esc_html__( 'Analysis', 'crashtape' ) . '</h2>';

			// Release-readiness checklist Priority 16: this used to say the
			// same "no rule matched" thing regardless of whether any events
			// existed at all; genuinely misleading for a zero-event
			// session (nothing to match against, not "matched and failed"),
			// and neither case ever said what to actually do next.
			if ( empty( $session['event_count'] ) ) {
				echo '<p>' . esc_html__( 'No events were captured during this session, so there is nothing to analyze. If you reproduced the problem but nothing was recorded, see the warning above about a page cache possibly serving pages without this recorder running.', 'crashtape' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'Events were captured, but none matched a known analysis rule. The raw evidence is still recorded below. Review the timeline and recent changes directly, or generate a support package to share with someone who can look further.', 'crashtape' ) . '</p>';
			}

			return;
		}

		$top = $analysis['candidates'][0];

		echo '<h2>' . esc_html__( 'Most Likely Issue', 'crashtape' ) . '</h2>';
		echo '<p><strong>' . esc_html( $top['title'] ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Confidence:', 'crashtape' ) . ' <span class="crashtape-pill crashtape-pill--' . esc_attr( self::confidence_pill_modifier( $top['confidence'] ) ) . '">' . esc_html( $top['confidence'] ) . '</span></p>';
		echo '<p>' . esc_html( $top['explanation'] ) . '</p>';

		echo '<p>' . esc_html__( 'Evidence:', 'crashtape' ) . '</p><ul>';
		foreach ( $top['evidence'] as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';

		echo '<p>' . esc_html__( 'Suggested checks:', 'crashtape' ) . '</p><ul>';
		foreach ( $top['suggested_checks'] as $check ) {
			echo '<li>' . esc_html( $check ) . '</li>';
		}
		echo '</ul>';

		if ( count( $analysis['candidates'] ) > 1 ) {
			echo '<p>' . esc_html__( 'Other possible issues:', 'crashtape' ) . '</p><ul>';
			foreach ( array_slice( $analysis['candidates'], 1 ) as $other ) {
				echo '<li>' . esc_html( sprintf( '%s (%s confidence)', $other['title'], $other['confidence'] ) ) . '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * Informs the user if a page appears not to have loaded the recorder.
	 * No per-page signal exists for that directly, so this is a
	 * session-level proxy;
	 * zero requests captured at all a few minutes into an active session
	 * is exactly what a full-page cache serving every request without
	 * WordPress running would look like. Quiet for the first few minutes
	 * so it doesn't fire before the visitor has even had a chance to
	 * browse anything yet.
	 */
	private function maybe_render_no_requests_warning( array $session ) {
		$grace_period = 3 * MINUTE_IN_SECONDS;
		$started      = strtotime( $session['started_at'] . ' UTC' );

		if ( ! $started || ( time() - $started ) < $grace_period ) {
			return;
		}

		global $wpdb;

		$table = Database::requests_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_id = %d", $session['id'] ) );

		if ( $count > 0 ) {
			return;
		}

		echo '<p><strong>' . esc_html__( 'No requests captured yet.', 'crashtape' ) . '</strong> ' . esc_html__( 'If you\'ve already tried browsing the site, a full-page cache may be serving pages without WordPress (and this recorder) ever running. Check for a cache-bypass setting on this host, or try reproducing the problem in a private/incognito window.', 'crashtape' ) . '</p>';

		// Release-readiness checklist Priority 16 "session expired": a
		// visitor-mode setup link is single-use and expires after 15
		// minutes (Session::VISITOR_TOKEN_TTL); a real, plausible way for
		// this exact empty state to happen that has nothing to do with
		// caching (a delayed click, a second click, or a chat/email
		// client's own link-preview crawler silently consuming the
		// single-use token before the real visitor ever gets to it).
		// maybe_handle_visitor_setup() deliberately redirects identically
		// whether the token was valid or not (its own docblock: "never
		// reveals via timing or response differences whether a given
		// token existed"; a real security property, not an oversight),
		// so this admin-side-only, capability-gated message is the safe
		// place to mention the possibility instead.
		if ( Session::MODE_VISITOR === $session['mode'] ) {
			echo '<p>' . esc_html__( 'This is a visitor-mode session: the one-time link expires after 15 minutes and only works once. If it\'s been longer than that, or the link may have already been opened, generate a fresh link below.', 'crashtape' ) . '</p>';
		}
	}

	/**
	 * A fresh one-time link is minted on every page view rather than
	 * persisted;
	 * multiple simultaneously-valid links for the same session are harmless
	 * (each can only set a privilege-free cookie), and this avoids ever
	 * showing an already-expired 15-minute-old link.
	 */
	private function render_visitor_setup_link( array $session ) {
		$token = Session::create_visitor_token( $session['uuid'] );
		$url   = add_query_arg( Bootstrap::VISITOR_SETUP_PARAM, $token, home_url( '/' ) );

		echo '<p>' . esc_html__( 'Open the site in a private/incognito window (so it stays logged out) and visit this link to attach that browser to the session:', 'crashtape' ) . '</p>';
		echo '<p><code>' . esc_html( $url ) . '</code></p>';
		echo '<p><a class="button" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Site in Diagnostic Tab', 'crashtape' ) . '</a></p>';
		echo '<p><em>' . esc_html__( 'This link expires in 15 minutes and only sets a diagnostic cookie; it does not log the visitor in or grant any WordPress access.', 'crashtape' ) . '</em></p>';
		echo '<p><em>' . esc_html__( 'If this site runs a full-page cache, a logged-out visitor can sometimes be served a cached page where WordPress (and this recorder) never runs. Clicking through this link helps avoid that on most default cache configurations, but a server-level or CDN cache may still need its own bypass rule.', 'crashtape' ) . '</em></p>';
		echo '<p><em>' . esc_html(
			sprintf(
				/* translators: %s: the diagnostic cookie's literal name, e.g. crashtape_diag */
				__( 'For a caching plugin that supports excluding requests by cookie name (e.g. WP Super Cache\'s Settings → Advanced → Rejected Cookies), add "%s" to skip the cache entirely for this diagnostic browser.', 'crashtape' ),
				Session::COOKIE
			)
		) . '</em></p>';
	}

	/**
	 * Separate from, and built on, direct session comparison.
	 * Whichever session is currently marked gets auto-compared against
	 * every subsequent session at Session::stop() (see Session::stop()),
	 * so this just surfaces that stored result plus the mark/clear
	 * controls.
	 */
	private function render_baseline( array $session ) {
		echo '<h3>' . esc_html__( 'Baseline', 'crashtape' ) . '</h3>';

		$baseline_uuid = Baseline::current();

		if ( $baseline_uuid === $session['uuid'] ) {
			echo '<p>' . esc_html__( 'This session is the current healthy baseline.', 'crashtape' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'crashtape_baseline_clear' );
			echo '<input type="hidden" name="action" value="crashtape_baseline_clear" />';
			submit_button( __( 'Clear Baseline', 'crashtape' ), 'secondary' );
			echo '</form>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_baseline_mark' );
		echo '<input type="hidden" name="action" value="crashtape_baseline_mark" />';
		echo '<input type="hidden" name="session_uuid" value="' . esc_attr( $session['uuid'] ) . '" />';
		submit_button( __( 'Mark This Session as Healthy Baseline', 'crashtape' ), 'secondary' );
		echo '</form>';

		if ( empty( $session['baseline_diff'] ) ) {
			return;
		}

		$diff = json_decode( $session['baseline_diff'], true );

		if ( ! is_array( $diff ) ) {
			return;
		}

		echo '<h4>' . esc_html__( 'What changed since the healthy baseline', 'crashtape' ) . '</h4>';

		$nothing_changed = ! $diff['environment_diff'] && ! $diff['plugin_diff'] && ! $diff['fingerprint_diff']['only_in_b'];

		if ( $nothing_changed ) {
			echo '<p>' . esc_html__( 'No meaningful differences from the baseline.', 'crashtape' ) . '</p>';
			return;
		}

		if ( $diff['plugin_diff'] ) {
			echo '<p><strong>' . esc_html__( 'Plugin changes:', 'crashtape' ) . '</strong></p><ul>';
			foreach ( $diff['plugin_diff'] as $row ) {
				echo '<li>' . esc_html( sprintf( '%s: %s → %s', $row['slug'], $row['a'] ? $row['a'] : '(not active)', $row['b'] ? $row['b'] : '(not active)' ) ) . '</li>';
			}
			echo '</ul>';
		}

		if ( $diff['environment_diff'] ) {
			echo '<p><strong>' . esc_html__( 'Environment changes:', 'crashtape' ) . '</strong></p><ul>';
			foreach ( $diff['environment_diff'] as $row ) {
				echo '<li>' . esc_html( sprintf( '%s: %s → %s', $row['field'], $row['a'], $row['b'] ) ) . '</li>';
			}
			echo '</ul>';
		}

		if ( $diff['fingerprint_diff']['only_in_b'] ) {
			echo '<p><strong>' . esc_html__( 'New errors not seen in the baseline:', 'crashtape' ) . '</strong></p>';
			$this->render_fingerprint_list( $diff['fingerprint_diff']['only_in_b'] );
		}
	}

	private function render_environment( array $session ) {
		$env = ! empty( $session['environment'] ) ? json_decode( $session['environment'], true ) : null;

		if ( ! is_array( $env ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Environment at Session Start', 'crashtape' ) . '</h3>';
		echo '<ul>';
		echo '<li>' . esc_html( sprintf( 'WordPress %s / PHP %s', $env['wp_version'], $env['php_version'] ) ) . '</li>';

		if ( ! empty( $env['active_theme'] ) ) {
			echo '<li>' . esc_html(
				sprintf(
					'Theme: %s %s',
					$env['active_theme']['name'],
					$env['active_theme']['version']
				)
			) . '</li>';
		}

		echo '<li>' . esc_html( sprintf( '%d active plugins', count( $env['active_plugins'] ) ) ) . '</li>';
		echo '<li>' . esc_html(
			sprintf(
				'HTTPS: %s · WP-Cron disabled: %s · Object cache: %s',
				$env['https'] ? 'yes' : 'no',
				$env['wp_cron_disabled'] ? 'yes' : 'no',
				$env['object_cache'] ? 'yes' : 'no'
			)
		) . '</li>';

		if ( ! empty( $env['wp_cron']['available'] ) ) {
			echo '<li>' . esc_html(
				sprintf(
					'WP-Cron: %d scheduled event(s), %d overdue',
					$env['wp_cron']['total_events'],
					$env['wp_cron']['overdue_count']
				)
			) . '</li>';

			if ( ! empty( $env['wp_cron']['duplicate_hooks'] ) ) {
				$labels = array();

				foreach ( $env['wp_cron']['duplicate_hooks'] as $dup ) {
					$labels[] = sprintf( '%s (×%d)', $dup['hook'], $dup['count'] );
				}

				echo '<li>' . esc_html( 'Hooks scheduled unusually often: ' . implode( ', ', $labels ) ) . '</li>';
			}
		}

		if ( ! empty( $env['action_scheduler']['detected'] ) ) {
			$as = $env['action_scheduler'];

			if ( isset( $as['pending_count'] ) ) {
				echo '<li>' . esc_html(
					sprintf(
						'Action Scheduler: %d pending (%d overdue), %d failed',
						$as['pending_count'],
						$as['overdue_count'],
						$as['failed_count']
					)
				) . '</li>';
			} else {
				echo '<li>' . esc_html(
					sprintf(
						'Action Scheduler detected (pending: %s, failed: %s)',
						! empty( $as['has_pending'] ) ? 'yes' : 'no',
						! empty( $as['has_failed'] ) ? 'yes' : 'no'
					)
				) . '</li>';
			}

			if ( ! empty( $as['failed_sample'] ) ) {
				echo '<li>' . esc_html__( 'Recently failed actions:', 'crashtape' ) . '<ul>';

				foreach ( $as['failed_sample'] as $action ) {
					echo '<li>' . esc_html(
						sprintf(
							'%s (%s), scheduled %s%s',
							$action['hook'],
							$action['group'],
							isset( $action['scheduled_date'] ) ? get_date_from_gmt( $action['scheduled_date'] ) : '?',
							! empty( $action['log_message'] ) ? ', ' . $action['log_message'] : ''
						)
					) . '</li>';
				}

				echo '</ul></li>';
			}

			if ( ! empty( $as['overdue_sample'] ) ) {
				echo '<li>' . esc_html__( 'Overdue pending actions:', 'crashtape' ) . '<ul>';

				foreach ( $as['overdue_sample'] as $action ) {
					echo '<li>' . esc_html(
						sprintf(
							'%s (%s), scheduled %s',
							$action['hook'],
							$action['group'],
							isset( $action['scheduled_date'] ) ? get_date_from_gmt( $action['scheduled_date'] ) : '?'
						)
					) . '</li>';
				}

				echo '</ul></li>';
			}
		}

		if ( ! empty( $env['woocommerce']['detected'] ) ) {
			$wc = $env['woocommerce'];

			echo '<li>' . esc_html(
				sprintf(
					/* translators: 1: WooCommerce version 2: on/off/unknown */
					__( 'WooCommerce %1$s (High-Performance Order Storage: %2$s)', 'crashtape' ),
					isset( $wc['version'] ) ? $wc['version'] : '?',
					is_null( $wc['hpos_enabled'] ) ? __( 'unknown', 'crashtape' ) : ( $wc['hpos_enabled'] ? __( 'on', 'crashtape' ) : __( 'off', 'crashtape' ) )
				)
			) . '</li>';

			if ( ! empty( $wc['scheduled_actions']['detected'] ) ) {
				$wc_as = $wc['scheduled_actions'];

				echo '<li>' . esc_html(
					sprintf(
						'WooCommerce scheduled actions: %d pending, %d failed',
						isset( $wc_as['pending_count'] ) ? $wc_as['pending_count'] : 0,
						isset( $wc_as['failed_count'] ) ? $wc_as['failed_count'] : 0
					)
				) . '</li>';

				if ( ! empty( $wc_as['failed_sample'] ) ) {
					echo '<li>' . esc_html__( 'Recently failed WooCommerce actions:', 'crashtape' ) . '<ul>';

					foreach ( $wc_as['failed_sample'] as $action ) {
						echo '<li>' . esc_html(
							sprintf(
								'%s (%s), scheduled %s',
								$action['hook'],
								$action['group'],
								isset( $action['scheduled_date'] ) ? get_date_from_gmt( $action['scheduled_date'] ) : '?'
							)
						) . '</li>';
					}

					echo '</ul></li>';
				}
			}

			if ( ! empty( $wc['webhooks']['detected'] ) ) {
				$wc_hooks = $wc['webhooks'];

				echo '<li>' . esc_html(
					sprintf(
						/* translators: 1: active count, 2: paused count, 3: disabled count */
						__( 'WooCommerce webhooks: %1$d active, %2$d paused, %3$d disabled', 'crashtape' ),
						isset( $wc_hooks['active'] ) ? $wc_hooks['active'] : 0,
						isset( $wc_hooks['paused'] ) ? $wc_hooks['paused'] : 0,
						isset( $wc_hooks['disabled'] ) ? $wc_hooks['disabled'] : 0
					)
				) . '</li>';

				if ( ! empty( $wc_hooks['failing_sample'] ) ) {
					echo '<li>' . esc_html__( 'WooCommerce webhooks with delivery failures:', 'crashtape' ) . '<ul>';

					foreach ( $wc_hooks['failing_sample'] as $hook ) {
						echo '<li>' . esc_html(
							sprintf(
								/* translators: 1: webhook name, 2: topic, 3: failure count, 4: delivery host */
								__( '%1$s (%2$s), %3$d consecutive failures, delivering to %4$s', 'crashtape' ),
								$hook['name'],
								$hook['topic'],
								$hook['failure_count'],
								$hook['delivery_host'] ? $hook['delivery_host'] : '?'
							)
						) . '</li>';
					}

					echo '</ul></li>';
				}
			}

			if ( ! empty( $wc['templates']['detected'] ) && ! empty( $wc['templates']['overrides'] ) ) {
				echo '<li>' . esc_html(
					sprintf(
						/* translators: %d: number of overridden WooCommerce template files */
						__( 'WooCommerce template overrides in the active theme: %d', 'crashtape' ),
						$wc['templates']['override_count']
					)
				) . '<ul>';

				foreach ( $wc['templates']['overrides'] as $override ) {
					echo '<li>' . esc_html( $override['file'] );

					if ( $override['outdated'] ) {
						echo ': ' . esc_html(
							sprintf(
								/* translators: 1: override's version, 2: current core version */
								__( 'outdated (%1$s, core is %2$s)', 'crashtape' ),
								$override['version'] ? $override['version'] : '?',
								$override['core_version']
							)
						);
					}

					echo '</li>';
				}

				echo '</ul></li>';
			}
		}

		echo '</ul>';
	}

	/**
	 * Looks backward from the session start for changes
	 * that might explain what broke; evidence, not proof, so this is
	 * worded as correlation, never causation.
	 */
	private function render_changes_before_session( array $session ) {
		global $wpdb;

		$window_hours = 24;
		$window_start = gmdate( 'Y-m-d H:i:s', strtotime( $session['started_at'] ) - ( $window_hours * HOUR_IN_SECONDS ) );

		$table = Database::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$changes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT change_type, component_type, component_slug, previous_version, new_version, created_at FROM {$table} WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC",
				$window_start,
				$session['started_at']
			)
		);

		if ( ! $changes ) {
			return;
		}

		echo '<p><strong>' . esc_html(
			sprintf(
				/* translators: %d: number of hours */
				__( 'Changes in the %d hours before this session started (correlation, not proof of cause):', 'crashtape' ),
				$window_hours
			)
		) . '</strong></p>';
		echo '<ul>';

		foreach ( $changes as $change ) {
			echo '<li>' . esc_html( $this->describe_change( $change, $session['started_at'] ) ) . '</li>';
		}

		echo '</ul>';
	}

	private function render_recent_changes() {
		global $wpdb;

		$table = Database::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$changes = $wpdb->get_results(
			"SELECT change_type, component_type, component_slug, previous_version, new_version, created_at FROM {$table} ORDER BY id DESC LIMIT 20"
		);

		if ( ! $changes ) {
			echo '<p>' . esc_html__( 'No changes recorded yet.', 'crashtape' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Time', 'crashtape' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Change', 'crashtape' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $changes as $change ) {
			echo '<tr>';
			echo '<td>' . esc_html( get_date_from_gmt( $change->created_at, 'Y-m-d H:i:s' ) ) . '</td>';
			echo '<td>' . esc_html( $this->describe_change( $change ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function describe_change( $change, $relative_to = null ) {
		$version_suffix = '';

		if ( $change->previous_version && $change->new_version ) {
			$version_suffix = sprintf( ' (%s → %s)', $change->previous_version, $change->new_version );
		} elseif ( $change->new_version ) {
			$version_suffix = sprintf( ' (%s)', $change->new_version );
		}

		$verbs = array(
			'plugin_updated'      => 'updated',
			'plugin_activated'    => 'activated',
			'plugin_deactivated'  => 'deactivated',
			'plugin_deleted'      => 'deleted',
			'theme_updated'       => 'updated',
			'theme_switched'      => 'switched to',
			'core_updated'        => 'WordPress core updated',
			'php_version_changed' => 'PHP version changed',
		);

		$verb = isset( $verbs[ $change->change_type ] ) ? $verbs[ $change->change_type ] : $change->change_type;

		$description = sprintf( '%s %s %s%s', ucfirst( $change->component_type ), $change->component_slug, $verb, $version_suffix );

		if ( $relative_to ) {
			$hours_before = round( ( strtotime( $relative_to ) - strtotime( $change->created_at ) ) / HOUR_IN_SECONDS, 1 );
			$description .= sprintf( ', %s hours before this session started', $hours_before );
		}

		return $description;
	}

	private function render_export( array $session ) {
		echo '<h2>' . esc_html__( 'Support Package', 'crashtape' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_export' );
		echo '<input type="hidden" name="action" value="crashtape_export" />';
		submit_button( __( 'Generate Support Package', 'crashtape' ), 'secondary' );
		echo '</form>';

		$packages = Package::for_session( (int) $session['id'] );

		if ( ! $packages ) {
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'File', 'crashtape' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Size', 'crashtape' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Created', 'crashtape' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Expires', 'crashtape' ) . '</th>';
		echo '<th scope="col"></th>';
		echo '</tr></thead><tbody>';

		foreach ( $packages as $package ) {
			$download_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'  => 'crashtape_download',
						'package' => $package['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'crashtape_download'
			);

			echo '<tr>';
			echo '<td>' . esc_html( $package['filename'] ) . '</td>';
			echo '<td>' . esc_html( size_format( (int) $package['size_bytes'] ) ) . '</td>';
			echo '<td>' . esc_html( $package['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $package['expires_at'] ? $package['expires_at'] : '-' ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download', 'crashtape' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Normal users should see explanations rather than raw
	 * internals. Off by default, only the events table (render_events_table())
	 * currently changes when this is on, since that's the one view this
	 * plugin has that trades a plain-English summary for raw internals.
	 */
	private function render_developer_mode_settings() {
		echo '<h2>' . esc_html__( 'Developer Mode', 'crashtape' ) . '</h2>';
		echo '<p>' . esc_html__( 'Adds event UUIDs, request IDs, microsecond timing, and a raw JSON viewer to the timeline table above. Off by default so ordinary users see plain explanations, not raw internals.', 'crashtape' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crashtape_save_developer_mode' );
		echo '<input type="hidden" name="action" value="crashtape_save_developer_mode" />';
		echo '<p><label><input type="checkbox" name="developer_mode" value="1" ' . checked( get_option( self::DEVELOPER_MODE_OPTION, false ), true, false ) . ' /> ' . esc_html__( 'Show developer detail in the timeline', 'crashtape' ) . '</label></p>';
		submit_button( __( 'Save Developer Mode Setting', 'crashtape' ), 'secondary' );
		echo '</form>';
	}

	/**
	 * Developer Mode is an advanced toggle adding relative
	 * detail (event UUIDs, request IDs, microsecond timing, raw JSON data)
	 * on top of the plain-English table every user sees by default.
	 */
	private function render_events_table( $session_id ) {
		global $wpdb;

		$developer_mode = (bool) get_option( self::DEVELOPER_MODE_OPTION, false );
		$table          = Database::events_table();
		$columns        = 'id, created_at, last_seen_at, occurrence_count, channel, event_type, severity, component_type, component_slug, summary';

		if ( $developer_mode ) {
			$columns .= ', event_id, request_id, data';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$columns} FROM {$table} WHERE session_id = %d ORDER BY created_at ASC, id ASC LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$session_id
			)
		);

		if ( ! $events ) {
			echo '<p>' . esc_html__( 'No events recorded yet.', 'crashtape' ) . '</p>';
			return;
		}

		$this->render_events_filter_bar( $events );

		// Background process correlation; only rendered
		// when this session actually has at least one background (cron)
		// event to label; most sessions never touch WP-Cron, and an
		// always-empty column would just be noise.
		$correlation_labels = Correlation::labels_for_session( $session_id );
		$show_correlation   = ! empty( $correlation_labels );

		echo '<div class="crashtape-table-wrap">';
		echo '<table class="widefat striped" id="crashtape-events-table">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Time', 'crashtape' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Channel', 'crashtape' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Type', 'crashtape' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Severity', 'crashtape' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Component', 'crashtape' ) . '</th>';

		if ( $show_correlation ) {
			echo '<th scope="col">' . esc_html__( 'Correlation', 'crashtape' ) . '</th>';
		}

		echo '<th scope="col">' . esc_html__( 'Summary', 'crashtape' ) . '</th>';

		if ( $developer_mode ) {
			echo '<th scope="col">' . esc_html__( 'Event ID', 'crashtape' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Request ID', 'crashtape' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Data', 'crashtape' ) . '</th>';
		}

		echo '</tr></thead><tbody>';

		$colspan = $developer_mode ? 9 : 6;

		if ( $show_correlation ) {
			++$colspan;
		}

		foreach ( $events as $event ) {
			echo '<tr class="crashtape-event-row" data-crashtape-channel="' . esc_attr( $event->channel ) . '" data-crashtape-severity="' . esc_attr( $event->severity ) . '">';
			echo '<td>' . esc_html( $developer_mode ? $event->created_at : get_date_from_gmt( $event->created_at, 'Y-m-d H:i:s' ) ) . '</td>';
			echo '<td>' . esc_html( $event->channel ) . '</td>';
			echo '<td>' . esc_html( $event->event_type ) . '</td>';
			echo '<td><span class="crashtape-pill crashtape-pill--' . esc_attr( $event->severity ) . '">' . esc_html( strtoupper( $event->severity ) ) . '</span></td>';
			echo '<td>' . esc_html( $event->component_slug ? $event->component_type . ': ' . $event->component_slug : '-' ) . '</td>';

			if ( $show_correlation ) {
				$correlation_key = isset( $correlation_labels[ (int) $event->id ] ) ? $correlation_labels[ (int) $event->id ] : '';
				echo '<td>' . ( $correlation_key ? '<span class="crashtape-pill crashtape-pill--' . esc_attr( $correlation_key ) . '">' . esc_html( self::correlation_label( $correlation_labels, (int) $event->id ) ) . '</span>' : '-' ) . '</td>';
			}

			echo '<td>' . esc_html( $event->summary );

			if ( $event->occurrence_count > 1 ) {
				echo ' ' . esc_html(
					sprintf(
						/* translators: 1: repeat count 2: first-seen time 3: last-seen time */
						__( '(Repeated %1$d times. First: %2$s. Last: %3$s.)', 'crashtape' ),
						$event->occurrence_count,
						get_date_from_gmt( $event->created_at, 'g:i:s A' ),
						get_date_from_gmt( $event->last_seen_at, 'g:i:s A' )
					)
				);
			}

			echo '</td>';

			if ( $developer_mode ) {
				echo '<td><code>' . esc_html( $event->event_id ) . '</code></td>';
				echo '<td>' . esc_html( $event->request_id ? $event->request_id : '-' ) . '</td>';
				echo '<td><details><summary>' . esc_html__( 'View', 'crashtape' ) . '</summary><pre class="crashtape-json-preview">' . esc_html( self::pretty_json( $event->data ) ) . '</pre></details></td>';
			}

			echo '</tr>';

			// Clicking a row opens a detail drawer; a
			// plain hidden sibling row, toggled by the click handler
			// rendered once in render_events_filter_bar(); works with no
			// JS framework and degrades to simply staying hidden if
			// JavaScript is off.
			echo '<tr class="crashtape-detail-row" hidden><td colspan="' . (int) $colspan . '">';
			echo '<p><strong>' . esc_html__( 'Severity:', 'crashtape' ) . '</strong> ' . esc_html( strtoupper( $event->severity ) ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Channel / type:', 'crashtape' ) . '</strong> ' . esc_html( $event->channel . ' / ' . $event->event_type ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Component:', 'crashtape' ) . '</strong> ' . esc_html( $event->component_slug ? $event->component_type . ': ' . $event->component_slug : __( 'none attributed', 'crashtape' ) ) . '</p>';

			if ( $show_correlation && isset( $correlation_labels[ (int) $event->id ] ) ) {
				echo '<p><strong>' . esc_html__( 'Correlation:', 'crashtape' ) . '</strong> ' . esc_html( self::correlation_label( $correlation_labels, (int) $event->id ) )
					. ': ' . esc_html( self::correlation_explanation( $correlation_labels[ (int) $event->id ] ) ) . '</p>';
			}
			echo '<p><strong>' . esc_html__( 'First seen:', 'crashtape' ) . '</strong> ' . esc_html( get_date_from_gmt( $event->created_at, 'Y-m-d H:i:s' ) )
				. '; <strong>' . esc_html__( 'Last seen:', 'crashtape' ) . '</strong> ' . esc_html( get_date_from_gmt( $event->last_seen_at, 'Y-m-d H:i:s' ) )
				. ' (' . esc_html(
					sprintf(
						/* translators: %d: number of times this fingerprint recurred */
						_n( '%d occurrence', '%d occurrences', (int) $event->occurrence_count, 'crashtape' ),
						$event->occurrence_count
					)
				) . ')</p>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		$this->render_events_filter_script();
	}

	/**
	 * A filter list (errors only/warnings+/PHP/browser/HTTP/etc.) mixing
	 * severities and channels; only channel and severity are real,
	 * per-event columns in this data model (REST/AJAX/cron are request
	 * *types*, not event channels; "changes" isn't an event at all), so
	 * filters are generated from whichever channels genuinely appear in
	 * this session rather than a fixed list that would include channels
	 * this session never used, or omit ones (e.g. woocommerce, custom)
	 * a fixed list wouldn't anticipate.
	 */
	private function render_events_filter_bar( array $events ) {
		$channels = array();

		foreach ( $events as $event ) {
			$channels[ $event->channel ] = true;
		}

		echo '<div class="crashtape-filters" role="group" aria-label="' . esc_attr__( 'Filter timeline events', 'crashtape' ) . '" style="margin-bottom:8px;">';
		echo '<button type="button" class="button button-small crashtape-filter" aria-pressed="true" data-crashtape-filter="all" data-crashtape-filter-type="severity">' . esc_html__( 'All', 'crashtape' ) . '</button> ';
		echo '<button type="button" class="button button-small crashtape-filter" aria-pressed="false" data-crashtape-filter="errors" data-crashtape-filter-type="severity">' . esc_html__( 'Errors only', 'crashtape' ) . '</button> ';
		echo '<button type="button" class="button button-small crashtape-filter" aria-pressed="false" data-crashtape-filter="warnings" data-crashtape-filter-type="severity">' . esc_html__( 'Warnings+', 'crashtape' ) . '</button> ';

		foreach ( array_keys( $channels ) as $channel ) {
			echo '<button type="button" class="button button-small crashtape-filter" aria-pressed="false" data-crashtape-filter="' . esc_attr( $channel ) . '" data-crashtape-filter-type="channel">' . esc_html( self::channel_button_label( $channel ) ) . '</button> ';
		}

		echo '</div>';
		// Avoids constantly updating regions without
		// accessible announcements; a screen reader user gets a spoken
		// summary of what the filter actually did, not just a silent
		// visual change to which rows are hidden.
		echo '<p class="crashtape-filter-status" aria-live="polite">' . esc_html(
			sprintf(
			/* translators: %d: number of events shown */
				__( 'Showing all %d event(s).', 'crashtape' ),
				count( $events )
			)
		) . '</p>';
	}

	/**
	 * Release-readiness checklist Priority 19 found this the hard way,
	 * while writing a troubleshooting guide that names these exact button
	 * labels: plain `ucfirst( $channel )` reads fine for most channels
	 * (Browser, Mail, Forms, Cache, Custom) but produces "Php", "Http", and
	 * "Woocommerce" for the three whose real names are acronyms/proper
	 * nouns, not just capitalized words.
	 */
	private static function channel_button_label( $channel ) {
		$special_cased = array(
			'php'         => 'PHP',
			'http'        => 'HTTP',
			'woocommerce' => 'WooCommerce',
		);

		return isset( $special_cased[ $channel ] ) ? $special_cased[ $channel ] : ucfirst( $channel );
	}

	/**
	 * Split out from render_events_filter_bar() and called separately,
	 * *after* the `<table id="crashtape-events-table">` markup; this
	 * script's own `document.getElementById('crashtape-events-table')`
	 * call would otherwise run before that element exists in the DOM
	 * (inline `<script>` tags execute synchronously, in document order, as
	 * the browser parses them). That's exactly what this codebase's PHPUnit
	 * suite could never catch: every assertion only checks the server-
	 * rendered HTML *string* contains the right markup, never that this
	 * script actually runs against a live DOM. A real Playwright test
	 * (tests/E2E/session-workflow.spec.js) caught it directly; clicking a
	 * real filter button in a real browser had zero effect, because the
	 * `if ( ! table ) { return; }` guard below was silently aborting the
	 * entire IIFE, every time, for as long as this bug existed. That guard
	 * itself was correct defensive code; the actual bug was call order one
	 * level up.
	 */
	private function render_events_filter_script() {
		?>
		<script>
		( function () {
			var table  = document.getElementById( 'crashtape-events-table' );
			var status = document.querySelector( '.crashtape-filter-status' );
			if ( ! table ) { return; }

			function toggleDetail( row, open ) {
				var detail = row.nextElementSibling;
				if ( detail && detail.classList.contains( 'crashtape-detail-row' ) ) {
					detail.hidden = ! open;
					row.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				}
			}

			document.querySelectorAll( '.crashtape-filter' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var type  = button.getAttribute( 'data-crashtape-filter-type' );
					var value = button.getAttribute( 'data-crashtape-filter' );
					var shown = 0;

					document.querySelectorAll( '.crashtape-filter' ).forEach( function ( other ) {
						other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' );
					} );

					table.querySelectorAll( '.crashtape-event-row' ).forEach( function ( row ) {
						var show = true;

						if ( 'severity' === type && 'all' !== value ) {
							var severity = row.getAttribute( 'data-crashtape-severity' );
							show = 'errors' === value
								? ( 'error' === severity || 'critical' === severity )
								: ( 'notice' !== severity && 'info' !== severity );
						} else if ( 'channel' === type ) {
							show = row.getAttribute( 'data-crashtape-channel' ) === value;
						}

						row.hidden = ! show;

						if ( show ) { shown++; }
						if ( ! show ) { toggleDetail( row, false ); }
					} );

					if ( status ) {
						status.textContent = shown + ' event(s) shown for "' + button.textContent.trim() + '".';
					}
				} );
			} );

			// Works by keyboard; every event row toggles
			// its detail drawer the same way whether clicked or activated
			// with Enter/Space, and announces its own expanded/collapsed
			// state via aria-expanded rather than relying on sight alone.
			table.querySelectorAll( '.crashtape-event-row' ).forEach( function ( row ) {
				row.setAttribute( 'role', 'button' );
				row.setAttribute( 'tabindex', '0' );
				row.setAttribute( 'aria-expanded', 'false' );

				row.addEventListener( 'click', function () {
					var detail = row.nextElementSibling;
					var isOpen = detail && ! detail.hidden;
					toggleDetail( row, ! isOpen );
				} );

				row.addEventListener( 'keydown', function ( event ) {
					if ( 'Enter' === event.key || ' ' === event.key ) {
						event.preventDefault();
						row.click();
					}
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Confidence (High/Medium/Low) isn't a severity scale;
	 * a High-confidence diagnosis is good news (it tells you what's
	 * wrong), so it gets the same visual weight as a "pass"/"good" pill,
	 * not "error". Medium/Low get progressively less certain treatment.
	 */
	private static function confidence_pill_modifier( $confidence ) {
		$map = array(
			'High'   => 'good',
			'Medium' => 'warning',
			'Low'    => 'muted',
		);

		return isset( $map[ $confidence ] ) ? $map[ $confidence ] : 'muted';
	}

	private static function correlation_label( array $labels, $event_id ) {
		if ( ! isset( $labels[ $event_id ] ) ) {
			return '-';
		}

		return Correlation::RELATED === $labels[ $event_id ]
			? __( 'Related', 'crashtape' )
			: __( 'Nearby', 'crashtape' );
	}

	private static function correlation_explanation( $label ) {
		return Correlation::RELATED === $label
			? __( 'A foreground event with the same attributed component happened shortly before this background (WP-Cron) event.', 'crashtape' )
			: __( 'This background (WP-Cron) event happened during the session but could not be tied to a specific foreground event.', 'crashtape' );
	}

	private static function pretty_json( $raw ) {
		$decoded = json_decode( (string) $raw, true );

		return wp_json_encode( is_array( $decoded ) ? $decoded : array(), JSON_PRETTY_PRINT );
	}
}
