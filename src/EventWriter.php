<?php

namespace JMooreWV\CrashTape;

defined( 'ABSPATH' ) || exit;

/**
 * Buffers events in memory during the request and writes them in one pass
 * near shutdown. Deduplicates by fingerprint at two levels, capping the
 * max duplicate occurrences stored individually: within a single
 * request's buffer (a tight loop triggering the same warning 50 times
 * becomes one buffered entry with occurrence_count=50, not 50 buffer
 * entries), and across the whole session at flush time (the same warning
 * recurring on request 1 and request 5 merges into the same DB row
 * rather than creating a second).
 */
class EventWriter {

	const MAX_EVENTS_PER_REQUEST = 200;
	const MAX_SUMMARY_LENGTH     = 500;

	private static $instance;

	/** @var array<string,array> Keyed by fingerprint for O(1) in-buffer dedup. */
	private $buffer = array();

	private function __construct() {}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Every capture channel funnels through here, and redaction happens
	 * here rather than in each channel; the single choke point guarantees
	 * it runs even if a future channel forgets to sanitize its own output.
	 * Redact before truncating: cutting a secret in half first could
	 * leave a partial value unmasked.
	 *
	 * $component, when given, is a Resolver::resolve_path() result; only
	 * 'type' and 'slug' are persisted (as component_type/component_slug);
	 * name/version/file stay in $data if the caller wants them in the
	 * human-readable summary.
	 */
	public function add( $channel, $event_type, $severity, $summary, array $data = array(), ?array $component = null ) {
		$summary        = Redactor::redact_text( (string) $summary );
		$data           = Redactor::redact_array( $data );
		$channel        = sanitize_key( $channel );
		$event_type     = sanitize_key( $event_type );
		$severity       = sanitize_key( $severity );
		$component_type = $component && ! empty( $component['type'] ) ? sanitize_key( $component['type'] ) : null;
		$component_slug = $component && ! empty( $component['slug'] ) ? substr( sanitize_text_field( $component['slug'] ), 0, 191 ) : null;
		$summary        = mb_substr( $summary, 0, self::MAX_SUMMARY_LENGTH );

		$fingerprint = self::fingerprint( $channel, $event_type, $component_slug, $summary );

		if ( isset( $this->buffer[ $fingerprint ] ) ) {
			++$this->buffer[ $fingerprint ]['occurrence_count'];
			return;
		}

		if ( count( $this->buffer ) >= self::MAX_EVENTS_PER_REQUEST ) {
			return;
		}

		$this->buffer[ $fingerprint ] = array(
			'event_id'         => wp_generate_uuid4(),
			'channel'          => $channel,
			'event_type'       => $event_type,
			'severity'         => $severity,
			'component_type'   => $component_type,
			'component_slug'   => $component_slug,
			'fingerprint'      => $fingerprint,
			'occurrence_count' => 1,
			'summary'          => $summary,
			'data'             => $data,
		);
	}

	/**
	 * Fingerprints on channel/type/component/normalized message, not raw
	 * pre-redaction text; two errors differing only in a value that got
	 * masked to the same [redacted] token really are "the same" error for
	 * grouping purposes, and fingerprinting the already-redacted summary
	 * gives that for free rather than needing separate normalization.
	 */
	private static function fingerprint( $channel, $event_type, $component_slug, $summary ) {
		return hash( 'sha256', implode( '|', array( $channel, $event_type, (string) $component_slug, $summary ) ) );
	}

	/**
	 * Release-readiness checklist Priority 13 found this the hard way: the
	 * original version of this method ran one SELECT plus one UPDATE/INSERT
	 * per *distinct* buffered event, all as separate round-trips; fine at
	 * a handful of events, but a real, measured 4.5+ seconds of added
	 * request time at MAX_EVENTS_PER_REQUEST (200 events × up to 2 queries
	 * each = up to 400 sequential queries), directly against the "negligible
	 * overhead" goal an active session is still supposed to hold to.
	 * Fixed by batching: one query to find which
	 * fingerprints already exist for this session, one multi-row INSERT for
	 * everything new (the common case; first time this session has seen
	 * these fingerprints), and per-row UPDATEs only for the genuinely
	 * cross-request duplicates (typically a small subset, unlike the
	 * INSERT path this was actually measured against). Confirmed live:
	 * flushing 200 distinct events dropped from ~4.5s / 401 queries to a
	 * small fraction of that; see the checklist for the re-measured
	 * numbers.
	 */
	public function flush( $session_id, $request_id = null ) {
		if ( empty( $this->buffer ) ) {
			return;
		}

		global $wpdb;

		$table = Database::events_table();
		$now   = current_time( 'mysql', true );

		$error_count   = 0;
		$warning_count = 0;
		$event_count   = 0;

		foreach ( $this->buffer as $event ) {
			$event_count += $event['occurrence_count'];

			if ( in_array( $event['severity'], array( 'error', 'critical' ), true ) ) {
				$error_count += $event['occurrence_count'];
			} elseif ( 'warning' === $event['severity'] ) {
				$warning_count += $event['occurrence_count'];
			}
		}

		$fingerprints = array_keys( $this->buffer );
		$placeholders = implode( ',', array_fill( 0, count( $fingerprints ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fingerprint, id FROM {$table} WHERE session_id = %d AND fingerprint IN ({$placeholders})",
				array_merge( array( $session_id ), $fingerprints )
			)
		);

		$existing_ids = array();
		foreach ( $existing as $row ) {
			$existing_ids[ $row->fingerprint ] = (int) $row->id;
		}

		$insert_rows = array();

		foreach ( $this->buffer as $fingerprint => $event ) {
			if ( isset( $existing_ids[ $fingerprint ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET occurrence_count = occurrence_count + %d, last_seen_at = %s WHERE id = %d",
						$event['occurrence_count'],
						$now,
						$existing_ids[ $fingerprint ]
					)
				);
				continue;
			}

			$insert_rows[] = '(' . implode(
				',',
				array(
					self::sql_value( $event['event_id'], '%s' ),
					self::sql_value( $session_id, '%d' ),
					self::sql_value( $request_id, '%d' ),
					self::sql_value( $event['channel'], '%s' ),
					self::sql_value( $event['event_type'], '%s' ),
					self::sql_value( $event['severity'], '%s' ),
					self::sql_value( $event['component_type'], '%s' ),
					self::sql_value( $event['component_slug'], '%s' ),
					self::sql_value( $fingerprint, '%s' ),
					self::sql_value( $event['occurrence_count'], '%d' ),
					self::sql_value( $now, '%s' ),
					self::sql_value( $event['summary'], '%s' ),
					self::sql_value( wp_json_encode( $event['data'] ), '%s' ),
					self::sql_value( $now, '%s' ),
				)
			) . ')';
		}

		if ( ! empty( $insert_rows ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- every value above was already individually escaped via sql_value()/$wpdb->prepare().
			$wpdb->query(
				"INSERT INTO {$table} (event_id, session_id, request_id, channel, event_type, severity, component_type, component_slug, fingerprint, occurrence_count, last_seen_at, summary, data, created_at) VALUES "
				. implode( ',', $insert_rows )
			);
		}

		Session::increment_counters( $session_id, $event_count, $error_count, $warning_count );

		$this->buffer = array();
	}

	/**
	 * A safely-quoted SQL literal for one value in a hand-built multi-row
	 * INSERT; $wpdb->prepare()'s %d silently casts null to 0 and %s casts
	 * it to '', neither of which is correct for this table's nullable
	 * columns (request_id, component_type, component_slug), so null is
	 * emitted as the literal SQL keyword instead of going through a
	 * placeholder at all.
	 */
	private static function sql_value( $value, $format ) {
		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}

		return $wpdb->prepare( $format, $value );
	}
}
