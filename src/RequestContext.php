<?php

namespace JMooreWV\CrashTape;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the current request's validated session/request identifiers so
 * capture channels that fire later in the same request (e.g. the browser
 * ingestion REST handler) don't need to re-validate the cookie or create a
 * second request row.
 */
class RequestContext {

	private static $session_id;
	private static $request_id;

	public static function set( $session_id, $request_id ) {
		self::$session_id = $session_id;
		self::$request_id = $request_id;
	}

	public static function session_id() {
		return self::$session_id;
	}

	public static function request_id() {
		return self::$request_id;
	}

	public static function is_active() {
		return null !== self::$session_id;
	}
}
