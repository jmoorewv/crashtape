<?php

namespace JMooreWV\CrashTape;

defined( 'ABSPATH' ) || exit;

/**
 * Request-level metadata capture. Creates a row at request start and
 * completes it at shutdown. Query strings are never stored; only the path.
 */
class Request {

	/** @var float Set at start() via microtime(true). */
	private static $start_time;

	/** @var array<int,int> request_id => highest severity rank attributed so far. */
	private static $attributed_rank = array();

	public static function start( $session_id ) {
		global $wpdb;

		self::$start_time = microtime( true );

		$uuid         = wp_generate_uuid4();
		$method       = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$safe_path    = self::safe_path();
		$request_type = self::request_type();
		$now          = current_time( 'mysql', true );

		$wpdb->insert(
			Database::requests_table(),
			array(
				'uuid'         => $uuid,
				'session_id'   => $session_id,
				'method'       => substr( $method, 0, 12 ),
				'path_hash'    => hash( 'sha256', $safe_path ),
				'safe_path'    => $safe_path,
				'request_type' => $request_type,
				'request_meta' => self::initial_meta( $request_type ),
				'started_at'   => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return array(
			'id'   => (int) $wpdb->insert_id,
			'uuid' => $uuid,
		);
	}

	/**
	 * The action name is the one piece of AJAX request metadata that's
	 * genuinely available this early (unlike REST auth/namespace below,
	 * which need routing to have actually run). sanitize_key() rather than
	 * sanitize_text_field() since an AJAX action name is a machine
	 * identifier, not free text.
	 */
	private static function initial_meta( $request_type ) {
		$meta = array();

		if ( 'ajax' === $request_type && ! empty( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$meta['ajax_action'] = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$query = self::safe_query_string();

		if ( null !== $query ) {
			$meta['query'] = $query;
		}

		$ip = self::client_ip();

		if ( null !== $ip ) {
			$redacted_ip = Redactor::redact_ip( $ip );

			if ( null !== $redacted_ip ) {
				$meta['ip'] = $redacted_ip;
			}
		}

		// Cache-bypass status during the diagnostic session: whether this
		// specific request carried the cache-bypass parameter
		// Bootstrap::visitor_landing_url() adds. Not a secret and not
		// something to sanitize as free text; its mere presence/absence is
		// the entire signal.
		if ( isset( $_GET[ Bootstrap::CACHE_BYPASS_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$meta['cache_bypassed'] = true;
		}

		return $meta ? wp_json_encode( $meta ) : null;
	}

	/**
	 * Redacts IP addresses. The direct TCP peer only; deliberately not
	 * X-Forwarded-For or any other client-suppliable
	 * proxy header, which can't be trusted without per-site reverse-proxy
	 * configuration this plugin has no way to know. filter_var() (not
	 * sanitize_text_field()) is the correct tool here, same lesson as
	 * safe_query_string() above: REMOTE_ADDR isn't free text to clean up,
	 * it's a value that either is a well-formed IP or isn't.
	 */
	private static function client_ip() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}

		$ip = wp_unslash( $_SERVER['REMOTE_ADDR'] );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}

	/**
	 * Only allowlisted query parameters are ever retained (Strict omits
	 * query strings entirely; Redactor enforces that, not this method).
	 * Any request type can carry a query string, not just AJAX/REST.
	 *
	 * Only wp_unslash(); deliberately not sanitize_text_field() too, even
	 * though every other raw $_SERVER/$_REQUEST read in this class goes
	 * through it. Confirmed by testing: sanitize_text_field() silently
	 * strips %XX-looking sequences from a *still percent-encoded* query
	 * string (its own defense against double-encoded control characters),
	 * which corrupts perfectly ordinary values; 'jane%40example.com'
	 * became 'janeexample.com', the '@' just gone, no error. It's the
	 * wrong tool for text that's about to be parsed, not displayed.
	 * wp_parse_str() (inside Redactor::redact_query_string()) safely
	 * extracts key/value structure on its own, and every value still
	 * passes through redact_text() there regardless.
	 */
	private static function safe_query_string() {
		if ( empty( $_SERVER['QUERY_STRING'] ) ) {
			return null;
		}

		$safe = Redactor::redact_query_string( wp_unslash( $_SERVER['QUERY_STRING'] ) );

		return '' !== $safe ? $safe : null;
	}

	/**
	 * Route/method/status/duration are already covered by the generic
	 * per-request capture above (safe_path *is* the route); this adds the
	 * two enrichments that genuinely need REST routing to have finished
	 * first: a coarse auth-result category and the endpoint namespace,
	 * captured via rest_post_dispatch, the point at which that routing has
	 * actually resolved. "Coarse" deliberately: a boolean, not which
	 * specific auth method/capability was involved.
	 */
	public static function capture_rest_context( $request_id, \WP_REST_Request $rest_request ) {
		global $wpdb;

		$route = $rest_request->get_route();

		// Merge into whatever start() already stored (e.g. an allowlisted
		// query string) rather than overwrite it; this used to unconditionally
		// replace request_meta, silently discarding it for every REST request.
		$table    = Database::requests_table();
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT request_meta FROM {$table} WHERE id = %d", $request_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$meta     = $existing ? json_decode( $existing, true ) : array();
		$meta     = is_array( $meta ) ? $meta : array();

		$meta['rest_namespace'] = self::rest_namespace( $route );
		$meta['rest_auth']      = is_user_logged_in() ? 'authenticated' : 'anonymous';

		$wpdb->update(
			$table,
			array( 'request_meta' => wp_json_encode( $meta ) ),
			array( 'id' => $request_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	private static function rest_namespace( $route ) {
		$segments = array_values( array_filter( explode( '/', (string) $route ) ) );

		if ( isset( $segments[0], $segments[1] ) ) {
			return $segments[0] . '/' . $segments[1];
		}

		return isset( $segments[0] ) ? $segments[0] : null;
	}

	public static function complete( $request_id, $status = null ) {
		global $wpdb;

		$duration_ms = null !== self::$start_time ? ( microtime( true ) - self::$start_time ) * 1000 : null;

		$wpdb->update(
			Database::requests_table(),
			array(
				'status'       => $status,
				'duration_ms'  => $duration_ms,
				'peak_memory'  => memory_get_peak_usage( true ),
				'completed_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $request_id ),
			array( '%d', '%f', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Best-effort attribution of which component this request's trouble
	 * belongs to (the `component` column). A stronger signal (e.g. a
	 * fatal error) always overrides a weaker one already recorded; a
	 * weaker one never downgrades an existing stronger attribution.
	 */
	public static function attribute( $request_id, $slug, $severity = 'warning' ) {
		if ( empty( $slug ) ) {
			return;
		}

		$rank = self::severity_rank( $severity );

		if ( isset( self::$attributed_rank[ $request_id ] ) && self::$attributed_rank[ $request_id ] > $rank ) {
			return;
		}

		self::$attributed_rank[ $request_id ] = $rank;

		global $wpdb;

		$wpdb->update(
			Database::requests_table(),
			array( 'component' => substr( (string) $slug, 0, 191 ) ),
			array( 'id' => $request_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	private static function severity_rank( $severity ) {
		$ranks = array(
			'notice'   => 1,
			'warning'  => 2,
			'error'    => 3,
			'critical' => 4,
		);

		return isset( $ranks[ $severity ] ) ? $ranks[ $severity ] : 0;
	}

	private static function safe_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = wp_parse_url( $uri, PHP_URL_PATH );

		return $path ? sanitize_text_field( $path ) : '/';
	}

	private static function request_type() {
		// REST_REQUEST isn't defined until rest_api_loaded() runs on
		// 'parse_request'; well after the 'init' priority 0 hook this class
		// is called from; so detect it from the URL instead.
		if ( self::looks_like_rest_request() ) {
			return 'rest';
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return 'ajax';
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return 'cron';
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}

		if ( is_admin() ) {
			return 'admin';
		}

		return 'frontend';
	}

	private static function looks_like_rest_request() {
		if ( isset( $_GET['rest_route'] ) ) {
			return true;
		}

		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';

		return 0 === strpos( ltrim( self::safe_path(), '/' ), trailingslashit( $prefix ) );
	}
}
