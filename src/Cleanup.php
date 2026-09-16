<?php

namespace JMooreWV\CrashTape;

use JMooreWV\CrashTape\Analysis\Baseline;
use JMooreWV\CrashTape\Reports\Package;
use JMooreWV\CrashTape\Reports\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Automatic retention cleanup. Free tier default: 7 days, set on the
 * session at Session::start() as its retention expiration. Runs once
 * daily via wp-cron; never on a normal request.
 */
class Cleanup {

	const CRON_HOOK = 'crashtape_cleanup_daily';

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public static function run() {
		try {
			self::delete_expired_packages();
			self::delete_expired_sessions();
			self::delete_expired_changes();
		} catch ( \Throwable $e ) {
			// Never let cleanup break wp-cron for other scheduled tasks.
		}
	}

	/**
	 * The manual, immediate counterpart to run()'s date-based retention
	 * cleanup: an admin-triggered "start fresh" that wipes every completed
	 * session (and its requests/events/packages) and the entire change
	 * journal right now, regardless of retention/expiry. Deliberately
	 * narrower than a full uninstall: settings (privacy profile, retention
	 * period, custom redaction rules, branding, the keep-data-on-uninstall
	 * preference itself), the internal operational log, and Passive
	 * Monitoring's rolling stats each already have their own dedicated
	 * "clear" action and are left untouched here; this is specifically for
	 * the diagnostic session records themselves. An active session (if any)
	 * is never touched, matching delete_expired_sessions()'s own rule; a
	 * recording in progress isn't a "record" to start fresh from yet.
	 */
	public static function purge_all() {
		global $wpdb;

		$sessions_table = Database::sessions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$session_ids = $wpdb->get_col( "SELECT id FROM {$sessions_table} WHERE status != 'active'" );

		foreach ( $session_ids as $session_id ) {
			self::delete_session_cascade( (int) $session_id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'DELETE FROM ' . Database::changes_table() );

		// A baseline pointing at a session that no longer exists is worse
		// than no baseline at all; clear it rather than leave it dangling.
		Baseline::clear();
	}

	private static function delete_expired_packages() {
		foreach ( Package::expired() as $package ) {
			Storage::delete( $package['filename'] );
			Package::delete( $package['id'] );
		}
	}

	/**
	 * Deleting a session cascades to its requests/events/packages; none
	 * of those are meaningful without the session that ties them together.
	 * An active session is never deleted regardless of its expires_at.
	 */
	private static function delete_expired_sessions() {
		global $wpdb;

		$sessions_table = Database::sessions_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$sessions_table} WHERE expires_at IS NOT NULL AND expires_at < %s AND status != 'active'",
				current_time( 'mysql' )
			)
		);

		foreach ( $expired as $session_id ) {
			self::delete_session_cascade( (int) $session_id );
		}
	}

	private static function delete_session_cascade( $session_id ) {
		global $wpdb;

		foreach ( Package::for_session( $session_id ) as $package ) {
			Storage::delete( $package['filename'] );
			Package::delete( $package['id'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Database::events_table(), array( 'session_id' => $session_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Database::requests_table(), array( 'session_id' => $session_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Database::sessions_table(), array( 'id' => $session_id ), array( '%d' ) );
	}

	/**
	 * Release-readiness checklist Priority 14 found this the hard way:
	 * unlike every other table here, the change journal is independent of
	 * any session (Journal's own docblock), so it was never touched by
	 * delete_expired_sessions()'s cascade; and nothing else ever pruned it
	 * either. On an actively-maintained site (regular plugin/theme/core
	 * updates), this table would grow forever, exactly what this
	 * priority's completion criteria rules out ("does not accumulate
	 * unbounded operational data"). Reuses Session's own retention_days()
	 * setting rather than inventing a second one to keep in sync;
	 * consistent with correlation windows topping out at 7 days (the
	 * default), so nothing that still matters for analysis is lost by the
	 * time a row would age out.
	 */
	private static function delete_expired_changes() {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( Session::retention_days() * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . Database::changes_table() . ' WHERE created_at < %s',
				$cutoff
			)
		);
	}
}
