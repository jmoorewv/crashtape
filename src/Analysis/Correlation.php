<?php

namespace JMooreWV\CrashTape\Analysis;

use JMooreWV\CrashTape\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Background Process Correlation. CrashTape can't deterministically prove
 * a background task (currently: a WP-Cron/Action Scheduler dispatch; the
 * only "background" `request_type` this plugin captures, see
 * Request::request_type()) was caused by a specific browser action, so
 * rather than guessing it labels the *confidence* of the relationship:
 *
 * - "related": a foreground (non-cron) event in the same session, with
 *   the same attributed component, occurred shortly before it.
 * - "nearby": it happened during the session's recording window, but
 *   nothing ties it to a specific foreground event.
 *
 * A third category, "directly correlated" (the trace/session ID is
 * present in the initiating request and propagated to events in that
 * request), deliberately isn't emitted here; a background dispatch is its
 * own separate HTTP request with no shared request_id to the request that
 * scheduled it, and this plugin has no trace-ID-propagation adapter for
 * WP-Cron/Action Scheduler today. That's future work (a future adapter
 * could explicitly propagate CrashTape trace IDs into supported
 * background systems); claiming it here would misrepresent the actual
 * evidence, and this plugin never claims certainty it doesn't have.
 */
class Correlation {

	const RELATED = 'related';
	const NEARBY  = 'nearby';

	/**
	 * A foreground event has to have happened no more than this many
	 * seconds before a background event with the same component to count
	 * as "related" rather than merely "nearby". Five minutes: long enough
	 * to cover a scheduled task queued during a request and processed on
	 * the next cron tick, short enough that an unrelated same-plugin error
	 * from earlier in a long session isn't mistaken for a cause.
	 */
	const RELATED_WINDOW_SECONDS = 300;

	/**
	 * @return array<int,string> Map of events.id => self::RELATED|self::NEARBY,
	 *                            one entry per background-request event in
	 *                            this session. Foreground events aren't
	 *                            included; they're not what's being labeled.
	 */
	public static function labels_for_session( $session_id ) {
		global $wpdb;

		$events_table   = Database::events_table();
		$requests_table = Database::requests_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$background = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.created_at, e.component_slug FROM {$events_table} e
				INNER JOIN {$requests_table} r ON r.id = e.request_id
				WHERE e.session_id = %d AND r.request_type = 'cron'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$session_id
			),
			ARRAY_A
		);

		if ( ! $background ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$foreground = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.created_at, e.component_slug FROM {$events_table} e
				INNER JOIN {$requests_table} r ON r.id = e.request_id
				WHERE e.session_id = %d AND r.request_type != 'cron' AND e.component_slug IS NOT NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$session_id
			),
			ARRAY_A
		);

		$labels = array();

		foreach ( $background as $event ) {
			$labels[ (int) $event['id'] ] = self::label( $event, $foreground );
		}

		return $labels;
	}

	private static function label( array $background_event, array $foreground_events ) {
		if ( empty( $background_event['component_slug'] ) ) {
			return self::NEARBY;
		}

		$event_time = strtotime( $background_event['created_at'] );

		foreach ( $foreground_events as $candidate ) {
			if ( $candidate['component_slug'] !== $background_event['component_slug'] ) {
				continue;
			}

			$delta = $event_time - strtotime( $candidate['created_at'] );

			if ( $delta >= 0 && $delta <= self::RELATED_WINDOW_SECONDS ) {
				return self::RELATED;
			}
		}

		return self::NEARBY;
	}
}
