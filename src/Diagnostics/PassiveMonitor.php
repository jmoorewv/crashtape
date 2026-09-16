<?php

namespace JMooreWV\CrashTape\Diagnostics;

use JMooreWV\CrashTape\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Passive Monitoring Mode; optional, low-volume background diagnostics
 * independent of any recording session. Not the default: off until an
 * admin opts in. Deliberately narrower than session capture in every way:
 *
 * - No browser instrumentation at all, continuous or otherwise.
 * - No per-event rows, no backtraces, no component attribution; only
 *   rolling daily counters plus a small, bounded set of distinct error
 *   fingerprints (this mode is inherently sampled down to aggregate
 *   counts, not full detail, by design).
 * - Only PHP *fatals* are watched (not warnings/notices; a per-request
 *   set_error_handler() on every single site request, session or not,
 *   would run something costly on every request, which is acceptable
 *   only for an active session, not for background monitoring).
 * - Cron/Action Scheduler backlog is sampled periodically via WP-Cron
 *   (reusing CronSnapshot::capture()), never per-request.
 *
 * Storage is one option; genuinely low-volume, not a new table: a bounded
 * number of day-buckets (retention-controlled) and a bounded fingerprint
 * list (oldest-by-last-seen evicted once full).
 */
class PassiveMonitor {

	const ENABLED_OPTION   = 'crashtape_passive_enabled';
	const RETENTION_OPTION = 'crashtape_passive_retention_days';
	const STATS_OPTION     = 'crashtape_passive_stats';
	const CRON_HOOK        = 'crashtape_passive_sample_backlog';

	const RETENTION_MIN     = 1;
	const RETENTION_MAX     = 90;
	const RETENTION_DEFAULT = 30;

	const MAX_FINGERPRINTS = 100;

	const FATAL_TYPES = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

	public static function enabled() {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	public static function retention_days() {
		$days = (int) get_option( self::RETENTION_OPTION, self::RETENTION_DEFAULT );

		return max( self::RETENTION_MIN, min( self::RETENTION_MAX, $days ) );
	}

	public static function set_enabled( $enabled ) {
		update_option( self::ENABLED_OPTION, (bool) $enabled );

		if ( $enabled ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
			}
		} else {
			$timestamp = wp_next_scheduled( self::CRON_HOOK );

			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_HOOK );
			}
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	public function register_hooks() {
		// The cron hook is always registered; WP-Cron may fire a
		// previously-scheduled event even if the setting was toggled off
		// since, and sample_backlog() itself checks enabled() before
		// doing anything.
		add_action( self::CRON_HOOK, array( $this, 'sample_backlog' ) );

		if ( ! self::enabled() ) {
			return;
		}

		// Idempotent, self-healing schedule: set_enabled(true) is the
		// normal path, but this covers any state where the option is on
		// without the cron event having been (re)scheduled to match.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}

		add_action( 'shutdown', array( $this, 'handle_shutdown' ) );
		add_action( 'wp_mail_failed', array( $this, 'handle_mail_failed' ) );
		add_action( 'http_api_debug', array( $this, 'handle_http_debug' ), 10, 5 );
	}

	public function handle_shutdown() {
		try {
			$error = error_get_last();

			if ( $error && in_array( $error['type'], self::FATAL_TYPES, true ) ) {
				self::record( 'php_fatal', self::fatal_summary( $error ) );
			}
		} catch ( \Throwable $e ) {
			// Never let passive monitoring break shutdown.
		}
	}

	/**
	 * For an uncaught Error/Exception, PHP's error_get_last()['message']
	 * embeds the *entire* formatted stack trace; full absolute server
	 * paths for every frame, including within the first line itself
	 * ("...Call to undefined function foo() in /var/www/.../file.php:12
	 * \nStack trace:\n#0 ..."), not just appended after it; confirmed by
	 * triggering a real fatal over a real HTTP request rather than
	 * assumed (an earlier version of this method only cut at the first
	 * newline, which left the absolute path on that same first line
	 * untouched). Raw stack-trace capture is ruled out generally;
	 * everything from " in /" onward is dropped and replaced with just
	 * the failing file's basename, matching the same restraint
	 * Capture\PhpErrorCapture already applies to session-scoped fatals.
	 */
	private static function fatal_summary( array $error ) {
		$message = (string) $error['message'];
		$message = preg_replace( '#\s+in\s+/.*$#s', '', $message );

		return trim( $message ) . ' in ' . basename( (string) $error['file'] ) . ':' . $error['line'];
	}

	public function handle_mail_failed( $wp_error ) {
		try {
			if ( is_wp_error( $wp_error ) ) {
				self::record( 'mail_failed', $wp_error->get_error_message() );
			}
		} catch ( \Throwable $e ) {
			// Never let passive monitoring break mail sending.
		}
	}

	public function handle_http_debug( $response, $context, $transport_class, $args, $url ) {
		try {
			if ( 'response' !== $context ) {
				return;
			}

			$host = wp_parse_url( $url, PHP_URL_HOST );

			if ( is_wp_error( $response ) ) {
				self::record( 'http_failure', sprintf( 'Connection error to %s', $host ? $host : 'unknown host' ) );
				return;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( $code >= 400 ) {
				self::record( 'http_failure', sprintf( '%s responded %d', $host ? $host : 'unknown host', $code ) );
			}
		} catch ( \Throwable $e ) {
			// Never let passive monitoring break an outbound request.
		}
	}

	/**
	 * WP-Cron only; never per-request. Reuses the same environment-snapshot
	 * logic a diagnostic session's start already captures, rather than a
	 * second implementation of "is WP-Cron/Action Scheduler backed up."
	 */
	public function sample_backlog() {
		try {
			if ( ! self::enabled() ) {
				return;
			}

			$snapshot = CronSnapshot::capture();

			$cron_overdue = (int) ( $snapshot['wp_cron']['overdue_count'] ?? 0 );
			$as           = is_array( $snapshot['action_scheduler'] ?? null ) ? $snapshot['action_scheduler'] : array();
			$as_failed    = (int) ( $as['failed_count'] ?? 0 );
			$as_overdue   = (int) ( $as['overdue_count'] ?? 0 );

			if ( ! $cron_overdue && ! $as_failed && ! $as_overdue ) {
				return;
			}

			$stats = self::stats();

			if ( $cron_overdue ) {
				self::bump_counter( $stats, 'cron_overdue', $cron_overdue );
			}

			if ( $as_failed ) {
				self::bump_counter( $stats, 'action_scheduler_failed', $as_failed );
			}

			if ( $as_overdue ) {
				self::bump_counter( $stats, 'action_scheduler_overdue', $as_overdue );
			}

			self::save( $stats );
		} catch ( \Throwable $e ) {
			// Never let passive monitoring break wp-cron for other tasks.
		}
	}

	/**
	 * Increments today's counter for $type and updates a bounded, redacted
	 * fingerprint list; the "sampling" this mode relies on instead of a
	 * full per-event record. One read-modify-write per call rather than a
	 * separate option write per sub-update.
	 */
	public static function record( $type, $summary ) {
		$stats = self::stats();

		self::bump_counter( $stats, $type, 1 );

		$summary     = Redactor::redact_text( (string) $summary );
		$fingerprint = substr( hash( 'sha256', $type . '|' . $summary ), 0, 32 );
		$now         = current_time( 'mysql', true );

		if ( isset( $stats['fingerprints'][ $fingerprint ] ) ) {
			++$stats['fingerprints'][ $fingerprint ]['count'];
			$stats['fingerprints'][ $fingerprint ]['last_seen'] = $now;
		} else {
			$stats['fingerprints'][ $fingerprint ] = array(
				'type'       => $type,
				'summary'    => $summary,
				'count'      => 1,
				'first_seen' => $now,
				'last_seen'  => $now,
			);
		}

		if ( count( $stats['fingerprints'] ) > self::MAX_FINGERPRINTS ) {
			uasort(
				$stats['fingerprints'],
				function ( $a, $b ) {
					return strcmp( $a['last_seen'], $b['last_seen'] );
				}
			);
			$stats['fingerprints'] = array_slice( $stats['fingerprints'], -self::MAX_FINGERPRINTS, null, true );
		}

		self::save( $stats );
	}

	private static function bump_counter( array &$stats, $type, $amount ) {
		$today = gmdate( 'Y-m-d' );

		if ( ! isset( $stats['days'][ $today ] ) ) {
			$stats['days'][ $today ] = array();
		}

		$stats['days'][ $today ][ $type ] = ( $stats['days'][ $today ][ $type ] ?? 0 ) + $amount;
	}

	public static function stats() {
		$stats = get_option( self::STATS_OPTION, array() );

		if ( ! is_array( $stats ) ) {
			$stats = array();
		}

		$stats += array(
			'days'         => array(),
			'fingerprints' => array(),
		);

		return $stats;
	}

	/**
	 * Drops day-buckets older than the configured retention and writes the
	 * result; bounded, configurable storage is the point of this feature,
	 * so this runs on every write rather than relying solely on the
	 * hourly cron tick.
	 */
	private static function save( array $stats ) {
		$cutoff = gmdate( 'Y-m-d', time() - ( self::retention_days() * DAY_IN_SECONDS ) );

		foreach ( array_keys( $stats['days'] ) as $day ) {
			if ( $day < $cutoff ) {
				unset( $stats['days'][ $day ] );
			}
		}

		update_option( self::STATS_OPTION, $stats );
	}

	public static function clear() {
		delete_option( self::STATS_OPTION );
	}
}
