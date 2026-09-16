<?php

namespace JMooreWV\CrashTape\Reports;

use JMooreWV\CrashTape\Database;
use JMooreWV\CrashTape\Redactor;
use JMooreWV\CrashTape\Session;

defined( 'ABSPATH' ) || exit;

/**
 * wp_crashtape_packages row access. Retention (default 7 days,
 * configurable 1-365 via Session::retention_days()) is shared with
 * sessions rather than tracked separately, so a package doesn't outlive
 * (or get cleaned up wildly out of sync with) the session it belongs to.
 * Cleanup handles actually deleting expired rows/files.
 */
class Package {

	public static function create( $session_id, $filename, $hash, $size_bytes, $redaction_profile = null ) {
		if ( null === $redaction_profile ) {
			$redaction_profile = Redactor::profile();
		}

		global $wpdb;

		$now     = current_time( 'mysql' );
		$expires = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + ( Session::retention_days() * DAY_IN_SECONDS ) );

		$wpdb->insert(
			Database::packages_table(),
			array(
				'session_id'        => $session_id,
				'filename'          => $filename,
				'hash'              => $hash,
				'redaction_profile' => $redaction_profile,
				'size_bytes'        => $size_bytes,
				'created_at'        => $now,
				'expires_at'        => $expires,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public static function find( $id ) {
		global $wpdb;

		$table = Database::packages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? $row : null;
	}

	public static function for_session( $session_id ) {
		global $wpdb;

		$table = Database::packages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %d ORDER BY id DESC", $session_id ),
			ARRAY_A
		);
	}

	public static function expired() {
		global $wpdb;

		$table = Database::packages_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE expires_at IS NOT NULL AND expires_at < %s", current_time( 'mysql' ) ),
			ARRAY_A
		);
	}

	public static function delete( $id ) {
		global $wpdb;

		$wpdb->delete( Database::packages_table(), array( 'id' => $id ), array( '%d' ) );
	}
}
