<?php

namespace JMooreWV\CrashTape;

defined( 'ABSPATH' ) || exit;

/**
 * Central redaction service. No capture channel
 * should invent its own redaction logic; this is the single place that
 * decides what leaves a request and what gets masked before persistence.
 *
 * Three profiles, each strictly retaining more than the last;
 * Advanced never loosens anything Strict/Balanced don't already touch:
 * - Strict (default): email addresses and IP addresses fully redacted
 *   (masked, not omitted), query strings omitted entirely regardless of
 *   any allowlist.
 * - Balanced: email domain retained; IP address coarsened (last IPv4
 *   octet / IPv6 host portion zeroed via redact_ip()); only admin-
 *   allowlisted query parameters retained (redact_query_string()).
 * - Advanced (explicitly selected additional fields with a warning):
 *   email addresses and IP addresses retained in full; the *entire*
 *   query string retained (the allowlist restriction lifted), still
 *   passed through redact_text() for secret-pattern detection as
 *   defense in depth. Scoped to exactly the dimensions this codebase
 *   has a real, working, profile-aware mechanism for. Everything else on
 *   the never-capture-by-default list (passwords,
 *   Authorization/Cookie headers, session tokens, form values, request/
 *   response bodies) has no capture surface in this codebase at all yet
 *   for any profile, Advanced included; inventing one would be a new
 *   capture channel, not a redaction-profile change, and stays out of
 *   scope here.
 */
class Redactor {

	const MASK = '[redacted]';

	const PROFILE_OPTION   = 'crashtape_privacy_profile';
	const PROFILE_STRICT   = 'strict';
	const PROFILE_BALANCED = 'balanced';
	const PROFILE_ADVANCED = 'advanced';

	const SENSITIVE_KEYS = array(
		'password',
		'passwd',
		'pwd',
		'secret',
		'client_secret',
		'api_key',
		'apikey',
		'access_token',
		'refresh_token',
		'authorization',
		'cookie',
		'token',
		'nonce',
		'signature',
		'webhook_secret',
		'private_key',
	);

	const NEVER_CAPTURE_HEADERS = array( 'authorization', 'cookie', 'set-cookie' );

	/**
	 * Custom redaction rules; site-specific secrets
	 * the built-in key/pattern lists can't know about (an internal field
	 * name, a bespoke token format). Additive to the built-ins, never a
	 * replacement for them.
	 */
	const CUSTOM_KEYS_OPTION     = 'crashtape_redaction_custom_keys';
	const CUSTOM_PATTERNS_OPTION = 'crashtape_redaction_custom_patterns';
	const MAX_CUSTOM_RULES       = 20;
	const MAX_PATTERN_LENGTH     = 200;

	/**
	 * Admin-configured query parameter names safe to retain.
	 * Off by default; an empty allowlist means every query
	 * string is omitted entirely, same as Strict.
	 */
	const QUERY_ALLOWLIST_OPTION = 'crashtape_query_param_allowlist';
	const MAX_ALLOWLIST_PARAMS   = 20;

	/**
	 * Reduces an *outbound* HTTP URL (HttpCapture) to scheme + host + path.
	 * Query strings are always stripped here regardless
	 * of profile/allowlist; a third-party API URL's query parameters are a
	 * different risk surface than this site's own front-end request (which
	 * redact_query_string() below handles) and aren't in scope for this
	 * allowlist yet.
	 */
	public static function redact_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return self::MASK;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$path   = wp_parse_url( $url, PHP_URL_PATH );

		return ( $scheme ? $scheme : 'https' ) . '://' . $host . ( $path ? $path : '' );
	}

	/**
	 * Drops Authorization/Cookie entirely rather than masking them; those
	 * must never be captured at all. Other headers
	 * are text-redacted defensively.
	 */
	public static function redact_headers( array $headers ) {
		$clean = array();

		foreach ( $headers as $key => $value ) {
			$lower_key = strtolower( (string) $key );

			if ( in_array( $lower_key, self::NEVER_CAPTURE_HEADERS, true ) ) {
				continue;
			}

			$clean[ $key ] = self::key_is_sensitive( $lower_key ) ? self::MASK : self::redact_text( (string) $value );
		}

		return $clean;
	}

	/**
	 * Recursively masks values whose key name matches a known secret
	 * pattern, and runs remaining string values through redact_text().
	 */
	public static function redact_array( array $data ) {
		$clean = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$clean[ $key ] = self::redact_array( $value );
				continue;
			}

			if ( ! is_scalar( $value ) && null !== $value ) {
				continue;
			}

			if ( self::key_is_sensitive( (string) $key ) ) {
				$clean[ $key ] = self::MASK;
				continue;
			}

			$clean[ $key ] = is_string( $value ) ? self::redact_text( $value ) : $value;
		}

		return $clean;
	}

	/**
	 * Pattern-based redaction for free-text values. This
	 * is deliberately conservative; it cannot claim perfect secret
	 * detection, only common, recognizable patterns.
	 */
	public static function redact_text( $text ) {
		$text = (string) $text;

		// Custom patterns run first; a site's own known secret format is
		// a more specific, deliberate match than the generic passes below.
		foreach ( self::custom_patterns() as $pattern ) {
			if ( ! self::is_valid_pattern( $pattern ) ) {
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$result = @preg_replace( $pattern, self::MASK, $text );

			if ( null !== $result ) {
				$text = $result;
			}
		}

		// Bearer tokens.
		$text = preg_replace( '/\bBearer\s+[A-Za-z0-9\-_.=]+/i', 'Bearer ' . self::MASK, $text );

		// JWT-like values (three dot-separated base64url segments).
		$text = preg_replace( '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/', self::MASK, $text );

		// Long high-entropy-looking tokens (32+ chars, no spaces); catches
		// API keys pasted into error messages without needing the key name.
		// Delegates the actual judgment call to detect_secret() rather than
		// duplicating the same pattern here, so there's one place to tune it.
		$text = preg_replace_callback(
			'/\b[A-Za-z0-9\-_]{32,}\b/',
			function ( $matches ) {
				return self::detect_secret( $matches[0] ) ? self::MASK : $matches[0];
			},
			$text
		);

		return self::redact_email( $text );
	}

	/**
	 * Strict redacts the whole address; Balanced retains the domain only;
	 * Advanced retains the address in full, unmasked; one of its
	 * explicitly-opted-in fields.
	 */
	public static function redact_email( $text ) {
		$text = (string) $text;

		if ( self::PROFILE_ADVANCED === self::profile() ) {
			return $text;
		}

		if ( self::PROFILE_BALANCED === self::profile() ) {
			return preg_replace(
				'/[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)/',
				self::MASK . '@$1',
				$text
			);
		}

		return preg_replace( '/[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/', self::MASK, $text );
	}

	/**
	 * Redacts IP addresses under Strict. Takes a single IP
	 * (not free text to scan; a request's client IP is a structured
	 * field, captured directly from $_SERVER['REMOTE_ADDR'], not
	 * discovered inside a string) and returns null for anything that
	 * isn't actually a valid IP, so a caller never stores garbage.
	 * Strict masks it outright (matching redact_email()'s masking;
	 * distinct from redact_query_string(), which *omits* the field
	 * entirely under Strict; redacting vs. omitting is a deliberate
	 * distinction, not an inconsistency).
	 * Balanced coarsens rather than fully retaining or fully masking;
	 * the IPv4 host octet / IPv6 host portion is zeroed, a standard
	 * anonymization technique (the same shape as GDPR-style IP
	 * anonymization), keeping enough to distinguish networks/regions
	 * without keeping an individually identifying address.
	 */
	public static function redact_ip( $ip ) {
		if ( ! is_string( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$profile = self::profile();

		if ( self::PROFILE_ADVANCED === $profile ) {
			return $ip;
		}

		if ( self::PROFILE_BALANCED === $profile ) {
			$coarse = self::coarsen_ip( $ip );

			return null !== $coarse ? $coarse : self::MASK;
		}

		return self::MASK;
	}

	private static function coarsen_ip( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts    = explode( '.', $ip );
			$parts[3] = '0';

			return implode( '.', $parts );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$binary = inet_pton( $ip );

			if ( false === $binary ) {
				return null;
			}

			// Keep the first 64 bits (network portion), zero the rest.
			$truncated = substr( $binary, 0, 8 ) . str_repeat( "\0", 8 );

			return inet_ntop( $truncated );
		}

		return null;
	}

	public static function profile() {
		$profile = get_option( self::PROFILE_OPTION, self::PROFILE_STRICT );

		$valid = array( self::PROFILE_STRICT, self::PROFILE_BALANCED, self::PROFILE_ADVANCED );

		return in_array( $profile, $valid, true ) ? $profile : self::PROFILE_STRICT;
	}

	/**
	 * Query strings are stripped unless individual keys are explicitly
	 * allowlisted. Strict omits query strings
	 * entirely, full stop. Balanced keeps only admin-allowlisted keys.
	 * Advanced keeps every key (the allowlist restriction lifted; one of
	 * its explicitly-opted-in fields) but every value, allowlisted or not,
	 * still passes through redact_text() as defense in depth against a
	 * value that happens to look like a secret. Array-valued params (e.g.
	 * `a[]=1&a[]=2`) are dropped rather than partially represented under
	 * every profile, Advanced included; not a scope reduction, just
	 * avoiding recursive per-element redaction this doesn't need yet.
	 */
	public static function redact_query_string( $query_string ) {
		$profile = self::profile();

		if ( self::PROFILE_STRICT === $profile ) {
			return '';
		}

		if ( ! is_string( $query_string ) || '' === $query_string ) {
			return '';
		}

		$allowlist = self::PROFILE_ADVANCED === $profile ? null : self::query_param_allowlist();

		if ( null !== $allowlist && empty( $allowlist ) ) {
			return '';
		}

		wp_parse_str( $query_string, $params );

		$kept = array();

		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}

			if ( null !== $allowlist && ! in_array( strtolower( (string) $key ), $allowlist, true ) ) {
				continue;
			}

			$kept[ $key ] = self::redact_text( (string) $value );
		}

		return $kept ? http_build_query( $kept ) : '';
	}

	/**
	 * Admin-configured, lowercased for case-insensitive matching against
	 * real query parameter names.
	 */
	public static function query_param_allowlist() {
		$params = get_option( self::QUERY_ALLOWLIST_OPTION, array() );
		$params = is_array( $params ) ? $params : array();

		return array_map( 'strtolower', $params );
	}

	/**
	 * No stack traces with argument values are captured yet (avoided by
	 * default); this exists so a future capture
	 * channel that adds stack text has one place to route it through.
	 */
	public static function redact_stack( $stack ) {
		return self::redact_text( (string) $stack );
	}

	/**
	 * Heuristic only; never presented to users as certain detection.
	 *
	 * Release-readiness checklist Priority 12 found this the hard way: the
	 * length/charset check alone matches any 32+ character
	 * lowercase_with_underscores string; exactly WordPress's own hook,
	 * cron-action, and meta-key naming convention (`rocket_preload_job_
	 * load_initial_sitemap`, `wpforms_email_summaries_fetch_info_blocks`,
	 * both real Action Scheduler hook names observed live with a real
	 * kitchen-sink of third-party plugins active, both false-positively
	 * masked before this fix). A genuine high-entropy secret packs more
	 * information into fewer characters by using a *larger* character set
	 * (mixed case and/or digits); Stripe's `sk_live_...`, GitHub's
	 * `ghp_...`, AWS's `AKIA...`, and a bare hex token/hash all still trip
	 * this because they all still mix at least two of
	 * lower/upper/digit; confirmed against all four plus the existing
	 * test's Stripe-like key. A single-character-class string this long
	 * is far more likely to be a readable identifier than a secret, so it
	 * no longer counts as one.
	 */
	public static function detect_secret( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z0-9\-_]{32,}$/', $value ) ) {
			return false;
		}

		$classes  = 0;
		$classes += preg_match( '/[a-z]/', $value ) ? 1 : 0;
		$classes += preg_match( '/[A-Z]/', $value ) ? 1 : 0;
		$classes += preg_match( '/[0-9]/', $value ) ? 1 : 0;

		return $classes >= 2;
	}

	/**
	 * Release-readiness checklist Priority 8 found this the hard way: a
	 * real HTTP header name like `X-Api-Key` never matched SENSITIVE_KEYS'
	 * `api_key` entry, because header names conventionally use hyphens and
	 * every built-in (and documented custom-key) entry here uses
	 * underscores. redact_headers() shares this same method specifically
	 * so header names get the same key-name defense array/JSON keys
	 * already had; normalizing hyphens to underscores before matching is
	 * what actually makes that shared use correct, not just superficially
	 * shared code. Not an active leak today (HttpCapture's only real
	 * caller passes a fixed 5-header allowlist that was already safe by
	 * construction), but redact_headers()'s own docblock promises to
	 * handle arbitrary headers "defensively"; this is what makes that
	 * promise actually true.
	 */
	private static function key_is_sensitive( $key ) {
		$key = str_replace( '-', '_', strtolower( (string) $key ) );

		foreach ( self::SENSITIVE_KEYS as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return true;
			}
		}

		foreach ( self::custom_keys() as $needle ) {
			$needle = strtolower( (string) $needle );

			if ( '' !== $needle && false !== strpos( $key, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Admin-configured additional field-name substrings, matched the same
	 * way as the built-in SENSITIVE_KEYS list.
	 */
	public static function custom_keys() {
		$keys = get_option( self::CUSTOM_KEYS_OPTION, array() );

		return is_array( $keys ) ? $keys : array();
	}

	/**
	 * Admin-configured additional regex patterns, applied in redact_text()
	 * alongside the built-in bearer/JWT/entropy passes.
	 */
	public static function custom_patterns() {
		$patterns = get_option( self::CUSTOM_PATTERNS_OPTION, array() );

		return is_array( $patterns ) ? $patterns : array();
	}

	/**
	 * A pattern must actually compile to be usable; checked both when an
	 * admin saves the setting and again here at use time (defense in
	 * depth in case a stored value predates this check or was edited
	 * directly). An invalid pattern is skipped rather than allowed to
	 * throw a PHP warning into the response; capture must
	 * never break the site.
	 */
	public static function is_valid_pattern( $pattern ) {
		if ( ! is_string( $pattern ) || '' === $pattern || strlen( $pattern ) > self::MAX_PATTERN_LENGTH ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return @preg_match( $pattern, '' ) !== false;
	}
}
