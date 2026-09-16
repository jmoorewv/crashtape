<?php

namespace JMooreWV\CrashTape\Isolation;

defined( 'ABSPATH' ) || exit;

/**
 * Creates/clears the signed isolation cookie and its server-side
 * allowlist. The MU loader (Loader::mu_loader_source()) is the other
 * half of this; it's a fully standalone file that reads what this class
 * writes, since it can't depend on this plugin's autoloaded classes at
 * the point it runs.
 */
class Token {

	const COOKIE           = 'crashtape_isolate';
	const TRANSIENT_PREFIX = 'crashtape_isolation_';
	const TTL              = 15 * MINUTE_IN_SECONDS;
	const BYPASS_OPTION    = 'crashtape_isolation_bypass';

	/**
	 * $keep_plugins are plugin basenames (e.g. 'akismet/akismet.php') to
	 * remain active for this test. CrashTape's own basename is always
	 * added, since CrashTape components required for recovery must
	 * always be allowed.
	 */
	public static function start_test( array $keep_plugins ) {
		$token = wp_generate_password( 40, false, false );

		$keep_plugins[] = plugin_basename( CRASHTAPE_FILE );
		$keep_plugins   = array_values( array_unique( array_map( 'sanitize_text_field', $keep_plugins ) ) );

		set_transient( self::TRANSIENT_PREFIX . $token, $keep_plugins, self::TTL );

		self::set_cookie( $token );

		return $token;
	}

	public static function stop_test() {
		if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$raw = wp_unslash( $_COOKIE[ self::COOKIE ] );

			if ( is_string( $raw ) && false !== strpos( $raw, '.' ) ) {
				list( $token, ) = explode( '.', $raw, 2 );
				delete_transient( self::TRANSIENT_PREFIX . $token );
			}
		}

		self::clear_cookie();
	}

	public static function is_active() {
		return ! empty( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Release-readiness checklist Priority 9 caught this: unlike the MU
	 * loader's own copy of this same lookup (Loader::mu_loader_source(),
	 * which correctly verifies the signature via hash_equals() before
	 * trusting the transient it names), this method used to read straight
	 * past the signature to the token without ever checking it. The MU
	 * loader is the actual security boundary; it only ever narrows the
	 * site's real active-plugins list via array_intersect(), so a forged
	 * cookie could never inject or add a plugin there regardless; so this
	 * was never a privilege-escalation path. But this method is what the
	 * admin UI displays as "what's currently being tested"
	 * (Admin\Timeline::render_isolation()), and without the same check, a
	 * forged cookie naming someone else's live transient token could make
	 * that display show the wrong allowlist. Verifying it here too is
	 * what makes this method actually trustworthy on its own, not reliant
	 * on every future caller remembering it currently only has one, purely
	 * cosmetic, consumer.
	 */
	public static function current_allowlist() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}

		$raw = wp_unslash( $_COOKIE[ self::COOKIE ] );

		if ( ! is_string( $raw ) || false === strpos( $raw, '.' ) ) {
			return null;
		}

		list( $token, $signature ) = explode( '.', $raw, 2 );

		if ( ! hash_equals( self::sign( $token ), $signature ) ) {
			return null;
		}

		$allowlist = get_transient( self::TRANSIENT_PREFIX . $token );

		return is_array( $allowlist ) ? $allowlist : null;
	}

	/**
	 * Deliberately NOT wp_hash(); the MU loader has to verify this
	 * signature before wp-includes/pluggable.php (where wp_hash() lives)
	 * has loaded, so both sides sign against AUTH_KEY/AUTH_SALT directly
	 * instead. See Loader::mu_loader_source() for the verifying half.
	 */
	private static function sign( $token ) {
		return hash_hmac( 'sha256', 'crashtape-isolation|' . $token, AUTH_KEY . AUTH_SALT );
	}

	/**
	 * headers_sent() guard; see Session::set_cookie() for why this isn't
	 * just test scaffolding.
	 */
	private static function set_cookie( $token ) {
		$value = $token . '.' . self::sign( $token );

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$value,
				array(
					'expires'  => time() + self::TTL,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

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
}
