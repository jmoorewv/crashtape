<?php

namespace JMooreWV\CrashTape\Support;

use JMooreWV\CrashTape\Cleanup;
use JMooreWV\CrashTape\Database;
use JMooreWV\CrashTape\Diagnostics\CronSnapshot;
use JMooreWV\CrashTape\Redactor;
use JMooreWV\CrashTape\Reports\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * A troubleshooting plugin must be able to troubleshoot itself. Every
 * check either genuinely exercises the real thing (a real DB write, a
 * real HTTP round-trip to the real REST route, a real Redactor call) or
 * is honestly reported as informational/not-installed; nothing here is
 * faked to make the report look better.
 */
class SelfTest {

	public static function run() {
		return array(
			self::check_tables_exist(),
			self::check_tables_writable(),
			self::check_rest_reachable(),
			self::check_cron_scheduled(),
			self::check_package_dir_protected(),
			self::check_redaction_engine(),
			self::check_zip_support(),
			self::check_action_scheduler(),
		);
	}

	private static function check_tables_exist() {
		global $wpdb;

		$tables = array(
			Database::sessions_table(),
			Database::requests_table(),
			Database::events_table(),
			Database::changes_table(),
			Database::packages_table(),
		);

		$missing = array();

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $found !== $table ) {
				$missing[] = $table;
			}
		}

		return $missing
			? self::result( 'fail', 'Database tables', 'Missing: ' . implode( ', ', $missing ) )
			: self::result( 'pass', 'Database tables', 'All 5 tables exist.' );
	}

	/**
	 * A real INSERT + SELECT + DELETE against the packages table; not a
	 * permissions guess. The row is created already-expired so the daily
	 * cleanup cron would also catch it if the DELETE here somehow didn't
	 * run (fail-safe).
	 */
	private static function check_tables_writable() {
		global $wpdb;

		$table  = Database::packages_table();
		$marker = 'crashtape-self-test-' . wp_generate_password( 8, false, false );
		$past   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$inserted = $wpdb->insert(
			$table,
			array(
				'session_id'        => 0,
				'filename'          => $marker,
				'hash'              => str_repeat( '0', 64 ),
				'redaction_profile' => 'strict',
				'size_bytes'        => 0,
				'created_at'        => $past,
				'expires_at'        => $past,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return self::result( 'fail', 'Database writable', 'Could not insert a test row: ' . $wpdb->last_error );
		}

		$id = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );

		$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		return $found
			? self::result( 'pass', 'Database writable', 'Insert/select/delete round-trip succeeded.' )
			: self::result( 'fail', 'Database writable', 'Row inserted but could not be read back.' );
	}

	/**
	 * A real HTTP round-trip to the actual registered REST route; the
	 * response content doesn't matter (a 403 "no active session" still
	 * proves the route exists); only rest_no_route / a connection failure
	 * counts as unreachable.
	 */
	private static function check_rest_reachable() {
		$url = rest_url( 'crashtape/v1/events' );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 5,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'events' => array() ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result( 'fail', 'Browser recorder / REST endpoint reachable', $response->get_error_message() );
		}

		$code = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_array( $code ) && isset( $code['code'] ) && 'rest_no_route' === $code['code'] ) {
			return self::result( 'fail', 'Browser recorder / REST endpoint reachable', 'Route not registered (rest_no_route).' );
		}

		return self::result( 'pass', 'Browser recorder / REST endpoint reachable', sprintf( 'Reached %s (HTTP %d).', $url, wp_remote_retrieve_response_code( $response ) ) );
	}

	private static function check_cron_scheduled() {
		$timestamp = wp_next_scheduled( Cleanup::CRON_HOOK );

		return $timestamp
			? self::result( 'pass', 'Cron cleanup scheduled', 'Next run: ' . gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC' )
			: self::result( 'fail', 'Cron cleanup scheduled', 'No scheduled event found.' );
	}

	private static function check_package_dir_protected() {
		$dir = Storage::dir();

		$has_htaccess = file_exists( $dir . '.htaccess' );
		$has_index    = file_exists( $dir . 'index.php' );

		return ( $has_htaccess && $has_index )
			? self::result( 'pass', 'Report directory protected', $dir )
			: self::result( 'fail', 'Report directory protected', 'Missing .htaccess and/or index.php in ' . $dir );
	}

	private static function check_redaction_engine() {
		$fake_secret = 'sk_live_' . wp_generate_password( 32, false, false );
		$result      = Redactor::redact_text( 'API key: ' . $fake_secret );

		return ( false === strpos( $result, $fake_secret ) )
			? self::result( 'pass', 'Redaction engine', 'A fake secret run through Redactor::redact_text() was masked.' )
			: self::result( 'fail', 'Redaction engine', 'Fake secret was NOT masked; this is a release blocker.' );
	}

	private static function check_zip_support() {
		return class_exists( 'ZipArchive' )
			? self::result( 'pass', 'ZIP support', 'ZipArchive is available.' )
			: self::result( 'fail', 'ZIP support', 'ZipArchive is not available; support package export will fail.' );
	}

	private static function check_action_scheduler() {
		$env = CronSnapshot::capture();
		$as  = $env['action_scheduler'];

		if ( empty( $as['detected'] ) ) {
			return self::result( 'info', 'Action Scheduler', 'Not detected on this site.' );
		}

		return self::result(
			'info',
			'Action Scheduler',
			sprintf( 'Detected; pending: %s, failed: %s', ! empty( $as['has_pending'] ) ? 'yes' : 'no', ! empty( $as['has_failed'] ) ? 'yes' : 'no' )
		);
	}

	private static function result( $status, $label, $message ) {
		return array(
			'status'  => $status,
			'label'   => $label,
			'message' => $message,
		);
	}
}
