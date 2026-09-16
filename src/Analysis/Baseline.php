<?php

namespace JMooreWV\CrashTape\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * "Mark This Session as Healthy Baseline"; a later, separate feature
 * from direct session comparison, built on top of it: once a baseline is
 * set, every future session automatically gets compared against it at
 * Session::stop(), answering "what changed since it last worked" without
 * the admin picking two sessions by hand.
 */
class Baseline {

	const OPTION = 'crashtape_baseline_session';

	public static function mark( $session_uuid ) {
		update_option( self::OPTION, $session_uuid );
	}

	public static function current() {
		$uuid = get_option( self::OPTION );

		return $uuid ? $uuid : null;
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
