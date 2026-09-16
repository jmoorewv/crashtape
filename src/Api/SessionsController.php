<?php

namespace JMooreWV\CrashTape\Api;

use JMooreWV\CrashTape\Database;
use JMooreWV\CrashTape\Reports\Builder;
use JMooreWV\CrashTape\Reports\Package;
use JMooreWV\CrashTape\Reports\Storage;
use JMooreWV\CrashTape\Session;
use JMooreWV\CrashTape\Support\SelfTest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Session control-plane REST API. Deliberately distinct from
 * Capture\BrowserIngest's /events route: that one accepts anonymous
 * browser event submissions authenticated by the diagnostic cookie itself
 * (visitor-mode recording has to work for logged-out browsers). Every
 * route here requires the same 'manage_options' capability as every other
 * CrashTape admin action (Admin\Timeline's admin-post handlers, matching
 * this codebase's established choice to use one plain capability rather
 * than a finer-grained crashtape_* list), authenticated the normal
 * WordPress REST way; cookie + nonce for a logged-in browser (WP core's
 * own rest_cookie_check_errors() already enforces the X-WP-Nonce check
 * for every authenticated REST request, so this class doesn't need to
 * reinvent CSRF protection), or an Application Password for external
 * tooling. No separate opaque-token scheme is invented; an opaque scoped
 * token is only needed for the *browser ingestion* endpoint specifically,
 * which already has one (the diagnostic cookie); it isn't a requirement
 * for an already-privileged, already-authenticated admin API.
 *
 * Route design intentionally omits a literal /session/{uuid}/browser-events
 * alias for BrowserIngest's existing /events route; duplicating it under
 * a manage_options-gated path would be redundant (that route is for
 * anonymous visitor browsers, not admins), and route design should
 * minimize attack surface, not maximize path coverage.
 */
class SessionsController {

	const ROUTE_NAMESPACE = 'crashtape/v1';
	const UUID_PATTERN    = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

	/**
	 * Applied only to the state-changing routes (create/stop/export); a
	 * defense-in-depth bound against a runaway or misbehaving external
	 * client, not something strictly required for an already-privileged
	 * admin API the way it is for anonymous browser ingestion, but cheap
	 * insurance given export builds a real ZIP on disk.
	 */
	const RATE_LIMIT_MAX    = 30;
	const RATE_LIMIT_WINDOW = 60;

	/**
	 * A package this big would blow up PHP memory when base64-encoded
	 * whole into a JSON response (see download() below); the admin UI's
	 * existing streamed admin-post.php download has no such limit and
	 * remains available for a package over this size.
	 */
	const MAX_DOWNLOAD_BYTES = 25 * MB_IN_BYTES;

	public function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/session',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'page'     => array(
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'label'         => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'mode'          => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'isolate_theme' => array(
							'type' => 'boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/session/(?P<uuid>' . self::UUID_PATTERN . ')',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/session/(?P<uuid>' . self::UUID_PATTERN . ')/stop',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'stop' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/session/(?P<uuid>' . self::UUID_PATTERN . ')/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/session/(?P<uuid>' . self::UUID_PATTERN . ')/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/self-test',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'self_test' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	public function index( WP_REST_Request $request ) {
		global $wpdb;

		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = Database::sessions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, uuid, label, mode, status, started_at, stopped_at, event_count, error_count, warning_count FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$response = new WP_REST_Response( array_map( array( $this, 'summarize' ), $rows ) );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $total / $per_page ) : 0 ) );

		return $response;
	}

	public function create( WP_REST_Request $request ) {
		if ( self::rate_limited() ) {
			return new WP_Error( 'crashtape_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}

		// Session::start() has no such guard of its own; Session::stop()
		// always stops whatever's active, and get_active() only ever
		// returns the newest 'active' row, so two active rows would
		// silently orphan the older one with no way to stop or find it
		// again through any normal flow. "No privilege escalation" is
		// normally about auth, but this is the same category of mistake:
		// something a REST caller could trivially trigger by POSTing twice.
		if ( Session::get_active() ) {
			return new WP_Error( 'crashtape_session_active', 'A diagnostic session is already active. Stop it before starting another.', array( 'status' => 409 ) );
		}

		$label = (string) $request->get_param( 'label' );
		$mode  = (string) $request->get_param( 'mode' );
		$mode  = in_array( $mode, array( Session::MODE_AUTHENTICATED, Session::MODE_VISITOR ), true ) ? $mode : Session::MODE_AUTHENTICATED;

		$isolate_theme = $request->get_param( 'isolate_theme' ) ? Session::default_isolation_theme() : null;

		$uuid    = Session::start( $label, $mode, $isolate_theme );
		$session = Session::find_by_uuid( $uuid );

		return new WP_REST_Response( $this->summarize( $session ), 201 );
	}

	public function show( WP_REST_Request $request ) {
		$session = Session::find_by_uuid( $request->get_param( 'uuid' ) );

		if ( ! $session ) {
			return new WP_Error( 'crashtape_session_not_found', 'No session found for that UUID.', array( 'status' => 404 ) );
		}

		$data                  = $this->summarize( $session );
		$data['environment']   = $session['environment'] ? json_decode( $session['environment'], true ) : null;
		$data['analysis']      = $session['analysis'] ? json_decode( $session['analysis'], true ) : null;
		$data['baseline_diff'] = $session['baseline_diff'] ? json_decode( $session['baseline_diff'], true ) : null;

		return new WP_REST_Response( $data );
	}

	public function stop( WP_REST_Request $request ) {
		if ( self::rate_limited() ) {
			return new WP_Error( 'crashtape_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}

		$session = Session::find_by_uuid( $request->get_param( 'uuid' ) );

		if ( ! $session ) {
			return new WP_Error( 'crashtape_session_not_found', 'No session found for that UUID.', array( 'status' => 404 ) );
		}

		if ( 'active' !== $session['status'] ) {
			return new WP_Error( 'crashtape_session_not_active', 'That session is not currently active.', array( 'status' => 409 ) );
		}

		// Session::stop() always stops whichever session is active; this
		// plugin only ever supports one at a time; so the check above
		// (this uuid *is* the active session) is what makes stopping "by
		// uuid" actually correct rather than coincidentally correct.
		Session::stop();

		return new WP_REST_Response( $this->summarize( Session::find_by_uuid( $session['uuid'] ) ) );
	}

	public function export( WP_REST_Request $request ) {
		if ( self::rate_limited() ) {
			return new WP_Error( 'crashtape_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}

		$session = Session::find_by_uuid( $request->get_param( 'uuid' ) );

		if ( ! $session ) {
			return new WP_Error( 'crashtape_session_not_found', 'No session found for that UUID.', array( 'status' => 404 ) );
		}

		if ( 'active' === $session['status'] ) {
			return new WP_Error( 'crashtape_session_active', 'Stop the session before exporting a support package.', array( 'status' => 409 ) );
		}

		$result = Builder::build( $session );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'package_id'   => $result['package_id'],
				'filename'     => $result['filename'],
				'hash'         => $result['hash'],
				'size_bytes'   => $result['size_bytes'],
				// A REST-native route rather than the admin UI's existing
				// admin-post.php download link; that link only works for
				// a cookie-authenticated browser (WordPress core doesn't
				// accept Application Passwords outside the REST API), which
				// would leave an external/headless REST client; the exact
				// audience this section exists for; unable to retrieve the
				// file it just asked this same API to build.
				'download_url' => rest_url( self::ROUTE_NAMESPACE . '/session/' . $session['uuid'] . '/download' ),
			),
			201
		);
	}

	/**
	 * Returns the session's most recently built package as base64 inside
	 * the normal JSON response envelope, rather than streaming raw bytes;
	 * WP_REST_Server serializes every response as JSON, and writing raw
	 * binary output from inside a route callback needs the
	 * rest_pre_serve_request short-circuit (or an exit()-terminated
	 * response), neither of which this codebase's own established
	 * precedent considers safe to unit test the same way as a WP-CLI
	 * exit()-terminating handler. A support package is small JSON/text
	 * content, not media, so the size cost of base64 is a non-issue up to
	 * MAX_DOWNLOAD_BYTES; past that, the admin UI's existing streamed
	 * admin-post.php download (Admin\Timeline::handle_download()) still
	 * works for a cookie-authenticated browser.
	 */
	public function download( WP_REST_Request $request ) {
		$session = Session::find_by_uuid( $request->get_param( 'uuid' ) );

		if ( ! $session ) {
			return new WP_Error( 'crashtape_session_not_found', 'No session found for that UUID.', array( 'status' => 404 ) );
		}

		$packages = Package::for_session( $session['id'] );

		if ( ! $packages ) {
			return new WP_Error( 'crashtape_package_not_found', 'No support package has been built for this session yet.', array( 'status' => 404 ) );
		}

		$package = $packages[0];
		$path    = Storage::path( $package['filename'] );

		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'crashtape_package_missing', 'Package file is missing on disk.', array( 'status' => 404 ) );
		}

		$size = filesize( $path );

		if ( $size > self::MAX_DOWNLOAD_BYTES ) {
			return new WP_Error(
				'crashtape_package_too_large',
				'This package is too large to download over the REST API. Use the admin UI\'s download link instead.',
				array( 'status' => 413 )
			);
		}

		return new WP_REST_Response(
			array(
				'package_id'     => (int) $package['id'],
				'filename'       => $package['filename'],
				'hash'           => $package['hash'],
				'size_bytes'     => $size,
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- reading and base64-encoding a small local file this same plugin just built, for JSON transport; not a remote fetch, not obfuscation.
				'content_base64' => base64_encode( file_get_contents( $path ) ),
			)
		);
	}

	public function self_test() {
		return new WP_REST_Response( SelfTest::run() );
	}

	private function summarize( array $session ) {
		return array(
			'id'            => (int) $session['id'],
			'uuid'          => $session['uuid'],
			'label'         => $session['label'] ? $session['label'] : '',
			'mode'          => $session['mode'],
			'status'        => $session['status'],
			'started_at'    => $session['started_at'],
			'stopped_at'    => $session['stopped_at'],
			'event_count'   => (int) $session['event_count'],
			'error_count'   => (int) $session['error_count'],
			'warning_count' => (int) $session['warning_count'],
		);
	}

	private static function rate_limited() {
		$key   = 'crashtape_rl_api_' . get_current_user_id();
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return true;
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );

		return false;
	}
}
