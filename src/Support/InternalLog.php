<?php

namespace JMooreWV\CrashTape\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A small internal operational log, entirely separate from diagnostic
 * event data (wp_crashtape_events); this records CrashTape's own
 * operational failures, not anything about the site being diagnosed.
 * Deliberately narrow: only five specific categories are logged, nothing
 * more general-purpose. One capped option, not a table; this is meant to
 * answer "has CrashTape itself been failing at something," not to be
 * queried/reported on like real diagnostic data.
 *
 * Avoid recursive logging: if CrashTape's own logging fails, stop logging
 * rather than creating loops. record() is wrapped so a failure writing
 * the log itself is silently swallowed, never retried, and never itself
 * logged.
 */
class InternalLog {

	const OPTION      = 'crashtape_internal_log';
	const MAX_ENTRIES = 50;

	const MIGRATION_FAILURE            = 'migration_failure';
	const STORAGE_FAILURE              = 'storage_failure';
	const EXPORT_FAILURE               = 'export_failure';
	const INGESTION_VALIDATION_FAILURE = 'ingestion_validation_failure';
	const INTEGRATION_INIT_FAILURE     = 'integration_init_failure';

	const CATEGORIES = array(
		self::MIGRATION_FAILURE,
		self::STORAGE_FAILURE,
		self::EXPORT_FAILURE,
		self::INGESTION_VALIDATION_FAILURE,
		self::INTEGRATION_INIT_FAILURE,
	);

	public static function record( $category, $message ) {
		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			return;
		}

		try {
			$entries   = self::all();
			$entries[] = array(
				'time'     => current_time( 'mysql', true ),
				'category' => $category,
				'message'  => substr( sanitize_text_field( (string) $message ), 0, 300 ),
			);

			if ( count( $entries ) > self::MAX_ENTRIES ) {
				$entries = array_slice( $entries, -self::MAX_ENTRIES );
			}

			update_option( self::OPTION, $entries, false );
		} catch ( \Throwable $e ) {
			// Never let this become a second failure to log, or retry into
			// a loop.
		}
	}

	public static function all() {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? $entries : array();
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
