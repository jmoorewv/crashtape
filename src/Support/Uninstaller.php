<?php

namespace JMooreWV\CrashTape\Support;

use JMooreWV\CrashTape\Admin\Timeline;
use JMooreWV\CrashTape\Analysis\Baseline;
use JMooreWV\CrashTape\Changes\Journal;
use JMooreWV\CrashTape\Cleanup;
use JMooreWV\CrashTape\Database;
use JMooreWV\CrashTape\Diagnostics\CustomChecks;
use JMooreWV\CrashTape\Diagnostics\PassiveMonitor;
use JMooreWV\CrashTape\Isolation\ConflictFinder;
use JMooreWV\CrashTape\Isolation\Loader as IsolationLoader;
use JMooreWV\CrashTape\Isolation\Token as IsolationToken;
use JMooreWV\CrashTape\Redactor;
use JMooreWV\CrashTape\Reports\Branding;
use JMooreWV\CrashTape\Reports\Storage;
use JMooreWV\CrashTape\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Uninstall behavior: ask through settings beforehand how data should be
 * handled; WordPress uninstall code should only remove data if explicit
 * setting permits it. Split out of uninstall.php so the per-site cleanup
 * is a real, directly testable method rather than a bare script;
 * uninstall.php itself is now just a thin multisite-aware dispatcher
 * onto run().
 *
 * The MU loader, scheduled cron events, and generated report files are
 * gated by the same setting as the tables/options, treated as a single
 * "potentially remove" unit; not split into an "always remove" subset,
 * since none of it is unsafe to leave behind:
 * IsolationLoader's generated file is fully self-contained vanilla PHP
 * (see its own docblock) with no dependency on this plugin's classes, and
 * an orphaned cron event simply finds no listener and no-ops once this
 * plugin's files are gone.
 */
class Uninstaller {

	const KEEP_DATA_OPTION = 'crashtape_keep_data_on_uninstall';

	public static function keep_data() {
		return (bool) get_option( self::KEEP_DATA_OPTION, false );
	}

	/**
	 * Multisite-aware entry point; every table/option this plugin creates
	 * is per-site, not network-wide, so each site's own keep/delete choice
	 * is honored independently rather than a single network-wide decision.
	 */
	public static function run() {
		if ( is_multisite() ) {
			$site_ids = get_sites( array( 'fields' => 'ids' ) );

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::run_for_current_site();
				restore_current_blog();
			}
		} else {
			self::run_for_current_site();
		}
	}

	public static function run_for_current_site() {
		if ( self::keep_data() ) {
			return;
		}

		global $wpdb;

		// Only ever removes a file carrying CrashTape's own marker comment.
		IsolationLoader::uninstall();

		Storage::delete_all();

		foreach (
			array(
				Database::sessions_table(),
				Database::requests_table(),
				Database::events_table(),
				Database::changes_table(),
				Database::packages_table(),
			) as $table
		) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		foreach (
			array(
				self::KEEP_DATA_OPTION,
				Database::DB_VERSION_OPTION,
				Session::RETENTION_OPTION,
				Redactor::PROFILE_OPTION,
				Redactor::CUSTOM_KEYS_OPTION,
				Redactor::CUSTOM_PATTERNS_OPTION,
				Redactor::QUERY_ALLOWLIST_OPTION,
				Branding::ORG_NAME_OPTION,
				Branding::FOOTER_OPTION,
				Branding::LOGO_OPTION,
				CustomChecks::OPTION,
				Baseline::OPTION,
				ConflictFinder::OPTION,
				IsolationToken::BYPASS_OPTION,
				Journal::SNAPSHOT_OPTION,
				PassiveMonitor::ENABLED_OPTION,
				PassiveMonitor::RETENTION_OPTION,
				PassiveMonitor::STATS_OPTION,
				InternalLog::OPTION,
				Timeline::DEVELOPER_MODE_OPTION,
			) as $option
		) {
			delete_option( $option );
		}

		// Every transient this plugin ever sets (visitor tokens, isolation
		// test tokens, per-session rate-limit counters, self-test/custom-
		// checks results) uses a 'crashtape_' key, so one LIKE query catches
		// all of them without enumerating every dynamic suffix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_crashtape_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_crashtape_' ) . '%'
			)
		);

		Cleanup::unschedule();
		PassiveMonitor::unschedule();
	}
}
