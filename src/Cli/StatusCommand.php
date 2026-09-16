<?php

namespace JMooreWV\CrashTape\Cli;

use JMooreWV\CrashTape\Cleanup;
use JMooreWV\CrashTape\Database;
use JMooreWV\CrashTape\Diagnostics\CronSnapshot;
use JMooreWV\CrashTape\Diagnostics\EnvironmentSnapshot;
use JMooreWV\CrashTape\Diagnostics\PassiveMonitor;
use JMooreWV\CrashTape\Diagnostics\SmtpSnapshot;
use JMooreWV\CrashTape\Diagnostics\WooCommerceSnapshot;
use JMooreWV\CrashTape\Isolation\Loader as IsolationLoader;
use JMooreWV\CrashTape\Redactor;
use JMooreWV\CrashTape\Reports\Builder;
use JMooreWV\CrashTape\Session;

defined( 'ABSPATH' ) || exit;

/**
 * `wp crashtape <command>`. snapshot() is the one command here that
 * computes fresh point-in-time data rather than displaying already-stored
 * (already-redacted-before-persistence) rows, so it's the one that
 * explicitly passes through Redactor itself; commands must respect
 * redaction, not something CLI output gets a pass on just because it
 * isn't the browser admin UI.
 */
class StatusCommand {

	/**
	 * Shows an at-a-glance operational summary.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crashtape status
	 */
	public function status( $args, $assoc_args ) {
		$active = Session::get_active();

		\WP_CLI::log( 'CrashTape ' . CRASHTAPE_VERSION );
		\WP_CLI::log( 'Database schema version: ' . get_option( Database::DB_VERSION_OPTION, '(not installed)' ) );
		\WP_CLI::log( 'Privacy profile: ' . Redactor::profile() );
		\WP_CLI::log( 'Retention: ' . Session::retention_days() . ' day(s)' );
		\WP_CLI::log( 'Active session: ' . ( $active ? sprintf( '%s (%s)', $active['uuid'], $active['label'] ? $active['label'] : 'no label' ) : 'none' ) );
		\WP_CLI::log( 'Stored sessions: ' . Session::count_all() );
		\WP_CLI::log( 'MU isolation loader installed: ' . ( IsolationLoader::is_installed() ? 'yes' : 'no' ) );
		\WP_CLI::log( 'Passive monitoring: ' . ( PassiveMonitor::enabled() ? 'enabled' : 'disabled' ) );
	}

	/**
	 * Captures and displays a fresh environment/cron/WooCommerce snapshot;
	 * the same data a new session would record; redacted per the current
	 * privacy profile.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crashtape snapshot
	 */
	public function snapshot( $args, $assoc_args ) {
		$snapshot = Redactor::redact_array(
			array_merge(
				EnvironmentSnapshot::capture(),
				CronSnapshot::capture(),
				WooCommerceSnapshot::capture(),
				SmtpSnapshot::capture()
			)
		);

		\WP_CLI::log( wp_json_encode( $snapshot, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Builds a support package ZIP for a session and prints its filename.
	 *
	 * ## OPTIONS
	 *
	 * <session>
	 * : Session UUID or numeric ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crashtape export a1b2c3d4-e5f6-7890-abcd-ef1234567890
	 */
	public function export( $args, $assoc_args ) {
		$session = Helpers::find_session( $args[0] );

		if ( ! $session ) {
			\WP_CLI::error( 'No session found matching "' . $args[0] . '".' );
			return;
		}

		$result = Builder::build( $session );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		\WP_CLI::success( 'Package built: ' . $result['filename'] );
	}

	/**
	 * Runs the retention cleanup pass immediately, instead of waiting for
	 * the daily wp-cron event.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crashtape cleanup
	 */
	public function cleanup( $args, $assoc_args ) {
		Cleanup::run();

		\WP_CLI::success( 'Cleanup pass complete.' );
	}
}
