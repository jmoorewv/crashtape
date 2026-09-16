<?php

namespace JMooreWV\CrashTape\Cli;

use JMooreWV\CrashTape\Database;
use JMooreWV\CrashTape\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Shared lookup logic for CLI commands that accept a session identifier.
 */
class Helpers {

	/**
	 * Accepts either a session UUID or a numeric row ID; a `sessions show
	 * <id>` example doesn't specify which, and a numeric ID is easier to
	 * type at a terminal than a UUID.
	 */
	public static function find_session( $identifier ) {
		if ( ctype_digit( (string) $identifier ) ) {
			global $wpdb;

			$table = Database::sessions_table();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $identifier ),
				ARRAY_A
			);

			if ( $row ) {
				return $row;
			}
		}

		return Session::find_by_uuid( $identifier );
	}
}
