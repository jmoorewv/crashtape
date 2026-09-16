<?php

namespace JMooreWV\CrashTape\Analysis;

use JMooreWV\CrashTape\Changes\Journal;
use JMooreWV\CrashTape\Database;
use JMooreWV\CrashTape\Sdk\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic, rule-based diagnosis. No AI required or used. Every
 * candidate carries its own evidence and a confidence label; correlation
 * is never presented as proof of cause — certainty is never claimed when
 * only correlation exists.
 *
 * Evidence lines cite real, dereferenceable IDs: EVT-<events.id> for
 * per-event evidence, CHG-<changes.id> for the recent-change evidence
 * added in score_candidates(). The one exception is add_cron_candidate()'s
 * evidence, which describes an environment-snapshot fact (an overdue
 * count), not a specific database row; inventing an ID for that would
 * be worse than citing none.
 */
class Engine {

	const CONFIDENCE_HIGH_AT   = 8;
	const CONFIDENCE_MEDIUM_AT = 4;

	const MAX_EVIDENCE_LINES = 5;

	public static function analyze( array $session ) {
		global $wpdb;

		$table = Database::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$events = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id = %d ORDER BY created_at ASC, id ASC",
				$session['id']
			)
		);

		$changes_by_component = array();

		foreach ( Journal::changes_before( $session, 24 ) as $change ) {
			$changes_by_component[ $change->component_slug ][] = $change;
		}

		$candidates = self::build_candidates( $events, $changes_by_component );
		$candidates = self::add_cron_candidate( $candidates, $session );
		$results    = self::score_candidates( $candidates, $changes_by_component );

		usort(
			$results,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array(
			'schema_version' => '1.0',
			'generated_at'   => current_time( 'mysql', true ),
			'candidates'     => $results,
		);
	}

	private static function build_candidates( array $events, array $changes_by_component ) {
		$candidates = array();

		foreach ( $events as $event ) {
			$data = json_decode( (string) $event->data, true );
			$data = is_array( $data ) ? $data : array();

			$rule = self::match_rule( $event, $data );

			if ( ! $rule ) {
				continue;
			}

			$key = $rule['classification'];

			if ( ! isset( $candidates[ $key ] ) ) {
				$candidates[ $key ] = self::new_candidate( $rule );
			}

			$candidates[ $key ] = self::apply_event( $candidates[ $key ], $event, $events, $changes_by_component );
		}

		return $candidates;
	}

	private static function new_candidate( array $rule ) {
		return array(
			'classification'    => $rule['classification'],
			'title'             => $rule['title'],
			'explanation'       => $rule['explanation'],
			'suggested_checks'  => $rule['suggested_checks'],
			'base_points'       => $rule['base_points'],
			'max_severity'      => 0,
			'occurrences'       => 0,
			'has_component'     => false,
			'component_slug'    => null,
			'has_recent_change' => false,
			'has_causal'        => false,
			'evidence'          => array(),
		);
	}

	private static function apply_event( array $candidate, $event, array $all_events, array $changes_by_component ) {
		// One DB row can now represent many occurrences (dedup); count the
		// real total, not just the row.
		$candidate['occurrences'] += max( 1, (int) $event->occurrence_count );

		$sev_weight = self::severity_weight( $event->severity );

		if ( $sev_weight > $candidate['max_severity'] ) {
			$candidate['max_severity'] = $sev_weight;
		}

		if ( $event->component_slug ) {
			$candidate['has_component']  = true;
			$candidate['component_slug'] = $event->component_slug;

			if ( ! empty( $changes_by_component[ $event->component_slug ] ) ) {
				$candidate['has_recent_change'] = true;
			}
		}

		if ( $event->request_id && self::has_causal_neighbor( $event, $all_events ) ) {
			$candidate['has_causal'] = true;
		}

		if ( count( $candidate['evidence'] ) < self::MAX_EVIDENCE_LINES ) {
			$repeat_suffix = $event->occurrence_count > 1
				? sprintf( ' (repeated %dx, last %s)', $event->occurrence_count, get_date_from_gmt( $event->last_seen_at, 'H:i:s' ) )
				: '';

			// Evidence integrity: every evidence line cites a real,
			// dereferenceable ID (EVT-<events.id>); a made-up or omitted ID
			// would defeat the whole point, which is that a diagnosis can
			// be checked against a specific row, not just a
			// plausible-sounding description.
			$candidate['evidence'][] = sprintf(
				'EVT-%d: %s %s: %s%s',
				$event->id,
				get_date_from_gmt( $event->created_at, 'H:i:s' ),
				strtoupper( $event->severity ),
				$event->summary,
				$repeat_suffix
			);
		}

		return $candidate;
	}

	private static function has_causal_neighbor( $event, array $all_events ) {
		foreach ( $all_events as $other ) {
			if ( (int) $other->id === (int) $event->id ) {
				continue;
			}

			if ( (int) $other->request_id === (int) $event->request_id
				&& in_array( $other->severity, array( 'error', 'critical' ), true )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * WP-Cron's overdue count and Action Scheduler's overdue/failed counts
	 * come from the session's environment snapshot, not from events; there
	 * is no per-event signal for "this cron hook is overdue" during a
	 * normal session. Both are folded into the same
	 * classification since they're the same underlying symptom (background
	 * task processing not keeping up), just from two different queues.
	 */
	private static function add_cron_candidate( array $candidates, array $session ) {
		$env = json_decode( (string) $session['environment'], true );

		if ( ! is_array( $env ) ) {
			return $candidates;
		}

		$cron_overdue = (int) ( $env['wp_cron']['overdue_count'] ?? 0 );
		$as           = is_array( $env['action_scheduler'] ?? null ) ? $env['action_scheduler'] : array();
		$as_overdue   = (int) ( $as['overdue_count'] ?? 0 );
		$as_failed    = (int) ( $as['failed_count'] ?? 0 );

		if ( ! $cron_overdue && ! $as_overdue && ! $as_failed ) {
			return $candidates;
		}

		$evidence = array();

		if ( $cron_overdue ) {
			$evidence[] = sprintf( '%d WP-Cron task(s) were already overdue when this session started.', $cron_overdue );
		}

		if ( $as_overdue ) {
			$evidence[] = sprintf( '%d Action Scheduler task(s) were already overdue when this session started.', $as_overdue );
		}

		if ( $as_failed ) {
			$evidence[] = sprintf( '%d Action Scheduler task(s) have failed.', $as_failed );

			foreach ( (array) ( $as['failed_sample'] ?? array() ) as $failed_action ) {
				if ( ! empty( $failed_action['log_message'] ) ) {
					$evidence[] = sprintf( '%s: %s', $failed_action['hook'], $failed_action['log_message'] );
				}
			}
		}

		$candidates['background_processing_problem'] = array(
			'classification'    => 'background_processing_problem',
			'title'             => 'Overdue or failed scheduled tasks',
			'explanation'       => 'One or more scheduled background tasks (WP-Cron and/or Action Scheduler) were overdue or failed when this session started, which can indicate a stalled or broken task queue.',
			'suggested_checks'  => array(
				'Check whether WP-Cron is running (DISABLE_WP_CRON, a real server cron trigger)',
				'Review the overdue/failed hook names for a specific failing integration',
				'Consider a real server cron trigger instead of page-load-triggered WP-Cron',
			),
			'base_points'       => 2,
			'max_severity'      => 0,
			'occurrences'       => $cron_overdue + $as_overdue + $as_failed,
			'has_component'     => false,
			'component_slug'    => null,
			'has_recent_change' => false,
			'has_causal'        => false,
			'evidence'          => $evidence,
		);

		return $candidates;
	}

	private static function score_candidates( array $candidates, array $changes_by_component ) {
		$results = array();

		foreach ( $candidates as $candidate ) {
			$score = $candidate['base_points']
				+ $candidate['max_severity']
				+ ( $candidate['has_component'] ? 2 : 0 )
				+ ( $candidate['has_recent_change'] ? 2 : 0 )
				+ ( $candidate['has_causal'] ? 1 : 0 )
				+ min( max( $candidate['occurrences'] - 1, 0 ), 3 );

			$candidate['score']      = $score;
			$candidate['confidence'] = self::confidence_label( $score );

			if ( $candidate['has_recent_change'] && ! empty( $changes_by_component[ $candidate['component_slug'] ] ) ) {
				foreach ( array_slice( $changes_by_component[ $candidate['component_slug'] ], 0, 2 ) as $change ) {
					// CHG-<changes.id>; the other half of the real-ID
					// requirement, mirroring the EVT- prefix above.
					$candidate['evidence'][] = sprintf(
						'CHG-%d: %s %s %s %s',
						$change->id,
						get_date_from_gmt( $change->created_at, 'Y-m-d H:i' ),
						$change->component_type,
						$change->component_slug,
						str_replace( '_', ' ', $change->change_type )
					);
				}
			}

			$results[] = $candidate;
		}

		return $results;
	}

	/**
	 * The built-in rule set. No public registry/filter yet; that's part of
	 * the future Diagnostics SDK, a separate and larger piece of scope
	 * than this deterministic core.
	 */
	private static function match_rule( $event, array $data ) {
		if ( 'http' === $event->channel && 'outbound_response' === $event->event_type ) {
			$status = isset( $data['status'] ) ? (int) $data['status'] : 0;

			if ( in_array( $status, array( 401, 403 ), true ) ) {
				return self::rule(
					'authentication_or_permission_failure',
					'External API authentication or permission failure',
					'An external service rejected the request. Check API credentials, account authorization, permissions, environment mode, or token expiration.',
					array( 'Verify API credentials', 'Confirm live/test mode', 'Check account authorization/permissions', 'Check token expiration' ),
					3
				);
			}

			if ( 429 === $status ) {
				return self::rule(
					'rate_limit',
					'External service is rate-limiting requests',
					'The external service is limiting requests. Check API quotas, retry behavior, and request frequency.',
					array( 'Check API quota/usage', 'Review retry/backoff behavior', 'Reduce request frequency' ),
					3
				);
			}

			if ( $status >= 500 ) {
				return self::rule(
					'remote_service_failure',
					'Remote service returned a server error',
					'The remote service returned a server error.',
					array( 'Check the remote service status page', 'Retry later', 'Contact the API provider if this persists' ),
					3
				);
			}

			return null;
		}

		if ( 'http' === $event->channel && 'outbound_error' === $event->event_type ) {
			return self::rule(
				'network_or_remote_connectivity',
				'Network or connectivity problem reaching an external service',
				'A request to an external service failed at the network level (timeout, DNS, or SSL) rather than receiving an HTTP response.',
				array( 'Check DNS resolution for the target host', 'Check SSL certificate validity', 'Check outbound network/firewall rules', 'Check timeout configuration' ),
				3
			);
		}

		if ( 'php' === $event->channel && 'php_fatal' === $event->event_type ) {
			if ( false !== stripos( $event->summary, 'allowed memory size' ) ) {
				return self::rule(
					'resource_limit',
					'PHP memory limit exhausted',
					'A PHP process ran out of allowed memory.',
					array( 'Increase PHP memory_limit', 'Check for memory leaks in the responsible plugin/theme', 'Review recently changed code for large loops or uploads' ),
					4
				);
			}

			return self::rule(
				'php_fatal',
				'PHP fatal error',
				'A PHP fatal error occurred during this session.',
				array( 'Review the fatal error message and file location', 'Check for a recent update to the responsible plugin/theme', 'Try temporarily deactivating the responsible plugin in a safe environment' ),
				4
			);
		}

		if ( 'browser' === $event->channel && in_array( $event->event_type, array( 'js_error', 'unhandled_rejection' ), true ) ) {
			return self::rule(
				'frontend_javascript_failure',
				'Frontend JavaScript error',
				'A JavaScript error or unhandled promise rejection occurred in the browser during this session.',
				array( 'Open the browser console and reproduce the action', 'Check for a recent theme/plugin update affecting frontend scripts', 'Look for script loading order or dependency conflicts' ),
				3
			);
		}

		if ( 'mail' === $event->channel && 'mail_failed' === $event->event_type ) {
			return self::rule(
				'mail_transport_failure',
				'Mail transport failure',
				'WordPress attempted to send an email and the mail subsystem reported failure.',
				array( 'Check SMTP/mail plugin configuration', 'Verify mail server credentials', 'Check hosting provider mail sending limits' ),
				3
			);
		}

		if ( 'woocommerce' === $event->channel && 'order_failed' === $event->event_type ) {
			return self::rule(
				'checkout_or_payment_failure',
				'WooCommerce order failed',
				'An order transitioned to the Failed status, most often a declined or errored payment attempt at the gateway.',
				array( 'Check the payment gateway\'s own error/API logs for this order', 'Verify gateway API keys and live/test mode', 'Check for a recent gateway plugin update', 'Retry the same payment method to see if it reproduces' ),
				3
			);
		}

		return self::match_custom_rule( $event, $data );
	}

	/**
	 * Third-party rules registered via crashtape_register_rule(). Never let
	 * one plugin's rule callback break analysis for everything else; a bad
	 * return shape or a thrown exception is
	 * treated the same as "no match," not a fatal.
	 */
	private static function match_custom_rule( $event, array $data ) {
		foreach ( Registry::rules_for_channel( $event->channel ) as $callback ) {
			try {
				$result = call_user_func( $callback, $event, $data );
			} catch ( \Throwable $e ) {
				continue;
			}

			if ( self::is_valid_custom_rule_result( $result ) ) {
				return $result;
			}
		}

		return null;
	}

	private static function is_valid_custom_rule_result( $result ) {
		return is_array( $result )
			&& ! empty( $result['classification'] )
			&& ! empty( $result['title'] )
			&& ! empty( $result['explanation'] )
			&& isset( $result['suggested_checks'] ) && is_array( $result['suggested_checks'] )
			&& isset( $result['base_points'] ) && is_numeric( $result['base_points'] );
	}

	private static function rule( $classification, $title, $explanation, array $checks, $base_points ) {
		return array(
			'classification'   => $classification,
			'title'            => $title,
			'explanation'      => $explanation,
			'suggested_checks' => $checks,
			'base_points'      => $base_points,
		);
	}

	private static function severity_weight( $severity ) {
		$weights = array(
			'critical' => 3,
			'error'    => 2,
			'warning'  => 1,
			'notice'   => 0,
			'info'     => 0,
		);

		return isset( $weights[ $severity ] ) ? $weights[ $severity ] : 0;
	}

	private static function confidence_label( $score ) {
		if ( $score >= self::CONFIDENCE_HIGH_AT ) {
			return 'High';
		}

		if ( $score >= self::CONFIDENCE_MEDIUM_AT ) {
			return 'Medium';
		}

		return 'Low';
	}
}
