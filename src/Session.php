<?php

namespace JMooreWV\CrashTape;

use JMooreWV\CrashTape\Analysis\Baseline;
use JMooreWV\CrashTape\Analysis\Comparison;
use JMooreWV\CrashTape\Analysis\Engine;
use JMooreWV\CrashTape\Diagnostics\CronSnapshot;
use JMooreWV\CrashTape\Diagnostics\EnvironmentSnapshot;
use JMooreWV\CrashTape\Diagnostics\SmtpSnapshot;
use JMooreWV\CrashTape\Diagnostics\WooCommerceSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Session manager backed by wp_crashtape_sessions. Issues/validates the
 * signed diagnostic cookie. Only one active session at a time; "admin" and
 * "isolated" modes aren't distinct in this implementation; the same
 * cookie-based capture already works identically in wp-admin as anywhere
 * else, so there's nothing architecturally different for "admin" mode to do.
 */
class Session {

	const COOKIE = 'crashtape_diag';

	const MODE_AUTHENTICATED = 'authenticated';
	const MODE_VISITOR       = 'visitor';

	const VISITOR_TOKEN_PREFIX = 'crashtape_visitor_';
	const VISITOR_TOKEN_TTL    = 15 * MINUTE_IN_SECONDS;

	public static function get_active() {
		global $wpdb;

		$table = Database::sessions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			"SELECT * FROM {$table} WHERE status = 'active' ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);

		return $row ? $row : null;
	}

	public static function get_last() {
		global $wpdb;

		$table = Database::sessions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);

		return $row ? $row : null;
	}

	public static function count_all() {
		global $wpdb;

		$table = Database::sessions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Completed sessions only; an in-progress session has no analysis
	 * yet and comparing against one mid-recording isn't meaningful.
	 */
	public static function recent( $limit = 20 ) {
		global $wpdb;

		$table = Database::sessions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status != 'active' ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	public static function find_by_uuid( $uuid ) {
		global $wpdb;

		$table = Database::sessions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE uuid = %s", $uuid ),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	const RETENTION_DAYS   = 7;
	const RETENTION_OPTION = 'crashtape_retention_days';
	const RETENTION_MIN    = 1;
	const RETENTION_MAX    = 365;

	/**
	 * Configurable 1-365 days, not a fixed value. Clamped defensively in
	 * case the stored option is somehow out of range (a
	 * stale value from before this existed, direct DB edit, etc.).
	 */
	public static function retention_days() {
		$days = (int) get_option( self::RETENTION_OPTION, self::RETENTION_DAYS );

		return max( self::RETENTION_MIN, min( self::RETENTION_MAX, $days ) );
	}

	/**
	 * Authenticated mode sets the diagnostic cookie on the current
	 * (admin's) browser immediately. Visitor mode deliberately does NOT;
	 * the whole point is to hand a fresh, logged-out browser its own
	 * diagnostic cookie via a one-time signed link, never the admin's own
	 * already-authenticated session.
	 *
	 * $isolate_theme: a theme slug to render with for the whole session
	 * instead of the site's real active theme, or null for no isolation.
	 * Only ever affects requests carrying this session's own
	 * cookie (Bootstrap::maybe_isolate_theme()); never touches the
	 * `template`/`stylesheet` options, so normal visitors are never
	 * affected and the live site's stored theme selection never changes.
	 */
	public static function start( $label = '', $mode = self::MODE_AUTHENTICATED, $isolate_theme = null ) {
		global $wpdb;

		if ( ! in_array( $mode, array( self::MODE_AUTHENTICATED, self::MODE_VISITOR ), true ) ) {
			$mode = self::MODE_AUTHENTICATED;
		}

		$uuid    = wp_generate_uuid4();
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + ( self::retention_days() * DAY_IN_SECONDS ) );

		$settings = null;

		if ( $isolate_theme && wp_get_theme( $isolate_theme )->exists() ) {
			$settings = wp_json_encode( array( 'isolate_theme' => $isolate_theme ) );
		}

		$wpdb->insert(
			Database::sessions_table(),
			array(
				'uuid'        => $uuid,
				'user_id'     => get_current_user_id(),
				'label'       => sanitize_text_field( $label ),
				'mode'        => $mode,
				'status'      => 'active',
				'settings'    => $settings,
				'environment' => wp_json_encode( Redactor::redact_array( array_merge( EnvironmentSnapshot::capture(), CronSnapshot::capture(), WooCommerceSnapshot::capture(), SmtpSnapshot::capture() ) ) ),
				'started_at'  => $now,
				'expires_at'  => $expires,
				'created_at'  => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( self::MODE_AUTHENTICATED === $mode ) {
			self::set_cookie( $uuid );
		}

		return $uuid;
	}

	/**
	 * The theme slug a request carrying this session's cookie should
	 * render with, or null if this session isn't isolating the theme.
	 * $session is the row shape validate_request_cookie()/find_by_uuid()
	 * return (array with a `settings` key).
	 */
	public static function isolated_theme( $session ) {
		if ( empty( $session['settings'] ) ) {
			return null;
		}

		$settings = json_decode( $session['settings'], true );

		return ( is_array( $settings ) && ! empty( $settings['isolate_theme'] ) ) ? $settings['isolate_theme'] : null;
	}

	/**
	 * The "temporary default theme" used for isolation; prefers
	 * WP_DEFAULT_THEME if it's actually installed, otherwise the newest
	 * installed default ("twenty*") theme from this explicit, newest-first
	 * list. Not derived
	 * by sorting installed slugs: "twentytwentyfive" > "twentytwentyfour"
	 * as a plain string comparison (>'f'... vs 't'...) doesn't reflect
	 * which theme is actually newer; the spelled-out number in the name
	 * isn't string-sortable into chronological order. Null if none of
	 * these are installed (no safe default theme exists on this install).
	 */
	const KNOWN_DEFAULT_THEMES = array(
		'twentytwentyfive',
		'twentytwentyfour',
		'twentytwentythree',
		'twentytwentytwo',
		'twentytwentyone',
		'twentytwenty',
		'twentynineteen',
	);

	public static function default_isolation_theme() {
		if ( defined( 'WP_DEFAULT_THEME' ) && wp_get_theme( WP_DEFAULT_THEME )->exists() ) {
			return WP_DEFAULT_THEME;
		}

		foreach ( self::KNOWN_DEFAULT_THEMES as $slug ) {
			if ( wp_get_theme( $slug )->exists() ) {
				return $slug;
			}
		}

		return null;
	}

	/**
	 * Mints a fresh one-time setup link token for a visitor-mode session.
	 * Not persisted on the session row; multiple valid tokens can exist
	 * for the same session simultaneously (e.g. the admin reloads the
	 * Timeline page before using the first one), which is harmless since
	 * the only thing a token can do is set a privilege-free cookie.
	 */
	public static function create_visitor_token( $uuid ) {
		$token = wp_generate_password( 40, false, false );

		set_transient( self::VISITOR_TOKEN_PREFIX . $token, $uuid, self::VISITOR_TOKEN_TTL );

		return $token;
	}

	/**
	 * Consumes a one-time setup token: sets the diagnostic cookie on
	 * whichever browser requests it, without touching WordPress auth or
	 * capabilities at all — must not authenticate the visitor or grant
	 * WordPress privileges. Single-use: the transient is deleted
	 * immediately regardless of outcome.
	 */
	public static function activate_visitor_browser( $token ) {
		$key  = self::VISITOR_TOKEN_PREFIX . sanitize_text_field( $token );
		$uuid = get_transient( $key );

		if ( ! $uuid ) {
			return false;
		}

		delete_transient( $key );

		$session = self::find_by_uuid( $uuid );

		if ( ! $session || 'active' !== $session['status'] ) {
			return false;
		}

		self::set_cookie( $uuid );

		return true;
	}

	public static function stop() {
		global $wpdb;

		$active = self::get_active();

		if ( $active ) {
			$analysis = Engine::analyze( $active );

			$fields  = array(
				'status'     => 'complete',
				'stopped_at' => current_time( 'mysql', true ),
				'analysis'   => wp_json_encode( $analysis ),
			);
			$formats = array( '%s', '%s', '%s' );

			$baseline_uuid = Baseline::current();

			if ( $baseline_uuid && $baseline_uuid !== $active['uuid'] ) {
				$baseline_session = self::find_by_uuid( $baseline_uuid );

				if ( $baseline_session ) {
					$fields['baseline_diff'] = wp_json_encode( Comparison::compare( $baseline_session, $active ) );
					$formats[]               = '%s';
				}
			}

			$wpdb->update(
				Database::sessions_table(),
				$fields,
				array( 'id' => $active['id'] ),
				$formats,
				array( '%d' )
			);
		}

		self::clear_cookie();
	}

	/**
	 * Increments session-level counters. Cheap best-effort update at
	 * shutdown; not meant to be called per-event.
	 */
	public static function increment_counters( $session_id, $event_count, $error_count, $warning_count ) {
		global $wpdb;

		if ( ! $event_count && ! $error_count && ! $warning_count ) {
			return;
		}

		$table = Database::sessions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET event_count = event_count + %d, error_count = error_count + %d, warning_count = warning_count + %d WHERE id = %d",
				$event_count,
				$error_count,
				$warning_count,
				$session_id
			)
		);
	}

	/**
	 * headers_sent() guards are defensive, not just test scaffolding: a
	 * theme/plugin that has already flushed output (stray whitespace
	 * before an opening `<?php`, an early echo) would otherwise turn
	 * starting/stopping a session into a PHP warning on someone's live
	 * page, and capture must never break the site. $_COOKIE is still
	 * updated either way so the rest of this request behaves as if the
	 * cookie were set/cleared, even when the header itself couldn't be
	 * sent.
	 */
	private static function set_cookie( $uuid ) {
		$value = self::sign( $uuid );

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$value,
				array(
					'expires'  => time() + DAY_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		// Also make it available to the current request without a reload.
		$_COOKIE[ self::COOKIE ] = $value;
	}

	private static function clear_cookie() {
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				'',
				array(
					'expires'  => time() - HOUR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		unset( $_COOKIE[ self::COOKIE ] );
	}

	private static function sign( $uuid ) {
		return $uuid . '.' . wp_hash( 'crashtape-session|' . $uuid );
	}

	/**
	 * Validates the diagnostic cookie on the current request against the
	 * active session. Returns the session row (array) on success, null
	 * otherwise.
	 */
	public static function validate_request_cookie() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}

		$raw = wp_unslash( $_COOKIE[ self::COOKIE ] );

		if ( ! is_string( $raw ) || false === strpos( $raw, '.' ) ) {
			return null;
		}

		list( $uuid, $signature ) = explode( '.', $raw, 2 );

		if ( ! hash_equals( wp_hash( 'crashtape-session|' . $uuid ), $signature ) ) {
			return null;
		}

		$session = self::get_active();

		if ( ! $session || $session['uuid'] !== $uuid ) {
			return null;
		}

		return $session;
	}
}
