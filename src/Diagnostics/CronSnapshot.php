<?php

namespace JMooreWV\CrashTape\Diagnostics;

use JMooreWV\CrashTape\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron overview and Action Scheduler feature-detection, captured at
 * session start alongside the environment snapshot. Uses WordPress's own
 * supported cron APIs; never executes cron events itself.
 */
class CronSnapshot {

	const MAX_SAMPLE   = 10;
	const DUPLICATE_AT = 4;

	public static function capture() {
		return array(
			'wp_cron'          => self::wp_cron_overview(),
			'action_scheduler' => self::action_scheduler_overview(),
		);
	}

	private static function wp_cron_overview() {
		if ( ! function_exists( '_get_cron_array' ) ) {
			return array( 'available' => false );
		}

		$cron = _get_cron_array();

		if ( ! is_array( $cron ) ) {
			return array( 'available' => false );
		}

		$now            = time();
		$total_events   = 0;
		$overdue_events = array();
		$overdue_count  = 0;
		$hook_counts    = array();

		foreach ( $cron as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $entries ) {
				$entries_at_this_hook = count( $entries );
				$total_events        += $entries_at_this_hook;
				$hook_counts[ $hook ] = isset( $hook_counts[ $hook ] ) ? $hook_counts[ $hook ] + $entries_at_this_hook : $entries_at_this_hook;

				if ( $timestamp < $now ) {
					$overdue_count += $entries_at_this_hook;

					if ( count( $overdue_events ) < self::MAX_SAMPLE ) {
						$overdue_events[] = array(
							'hook'            => $hook,
							'seconds_overdue' => $now - $timestamp,
						);
					}
				}
			}
		}

		$duplicate_hooks = array();

		foreach ( $hook_counts as $hook => $count ) {
			if ( $count >= self::DUPLICATE_AT ) {
				$duplicate_hooks[] = array(
					'hook'  => $hook,
					'count' => $count,
				);
			}
		}

		return array(
			'available'       => true,
			'total_events'    => $total_events,
			'overdue_count'   => $overdue_count,
			'overdue_sample'  => $overdue_events,
			'duplicate_hooks' => $duplicate_hooks,
			'disabled'        => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}

	/**
	 * Feature-detects Action Scheduler rather than assuming WooCommerce is
	 * active; it ships as a library bundled by many plugins, not just
	 * WooCommerce. The `date` + `date_compare => '<='`
	 * args for overdue-pending detection were confirmed against a real
	 * Action Scheduler 4.1.0 install (three real actions: failed, overdue
	 * pending, future pending) rather than assumed from documentation.
	 */
	private static function action_scheduler_overview() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array( 'detected' => false );
		}

		try {
			$pending_count = count(
				as_get_scheduled_actions(
					array(
						'status'   => 'pending',
						'per_page' => 0,
					),
					'ids'
				)
			);
			$failed_count  = count(
				as_get_scheduled_actions(
					array(
						'status'   => 'failed',
						'per_page' => 0,
					),
					'ids'
				)
			);
			$overdue_ids   = as_get_scheduled_actions(
				array(
					'status'       => 'pending',
					'date'         => as_get_datetime_object(),
					'date_compare' => '<=',
					'per_page'     => 0,
				),
				'ids'
			);

			$failed_sample  = self::action_sample(
				as_get_scheduled_actions(
					array(
						'status'   => 'failed',
						'per_page' => self::MAX_SAMPLE,
						'orderby'  => 'date',
						'order'    => 'DESC',
					),
					'OBJECT'
				),
				true
			);
			$overdue_sample = self::fetch_by_ids( array_slice( $overdue_ids, 0, self::MAX_SAMPLE ) );

			return array(
				'detected'       => true,
				'has_pending'    => $pending_count > 0,
				'has_failed'     => $failed_count > 0,
				'pending_count'  => $pending_count,
				'failed_count'   => $failed_count,
				'overdue_count'  => count( $overdue_ids ),
				'failed_sample'  => $failed_sample,
				'overdue_sample' => $overdue_sample,
			);
		} catch ( \Throwable $e ) {
			return array(
				'detected' => true,
				'error'    => 'query_failed',
			);
		}
	}

	/**
	 * as_get_scheduled_actions() has no "give me these specific IDs" query
	 * arg (confirmed against the real 4.1.0 source; unrecognized query
	 * keys are silently dropped rather than rejected, which is what
	 * happens if this isn't used), so overdue IDs from the date-compare
	 * query above are resolved one at a time via the store directly. A
	 * missing/deleted action fetches as ActionScheduler_NullAction with an
	 * empty hook rather than throwing, so that's the skip signal.
	 */
	private static function fetch_by_ids( array $ids ) {
		$store   = \ActionScheduler::store();
		$actions = array();

		foreach ( $ids as $id ) {
			$action = $store->fetch_action( $id );

			if ( $action && $action->get_hook() ) {
				$actions[ $id ] = $action;
			}
		}

		return self::action_sample( $actions, false );
	}

	/**
	 * Summarizes a set of Action Scheduler action objects for storage.
	 * Failure log messages are free-text written by whatever plugin
	 * registered the action, so they go through the same Redactor as
	 * every other capture channel before being persisted.
	 */
	private static function action_sample( $actions, $with_log_message ) {
		if ( ! is_array( $actions ) ) {
			return array();
		}

		$sample = array();

		foreach ( $actions as $id => $action ) {
			if ( ! is_object( $action ) ) {
				continue;
			}

			$entry = array(
				'id'    => (int) $id,
				'hook'  => $action->get_hook(),
				'group' => $action->get_group(),
			);

			$schedule = $action->get_schedule();

			if ( $schedule && $schedule->get_date() ) {
				$entry['scheduled_date'] = $schedule->get_date()->format( 'Y-m-d H:i:s' );
			}

			if ( $with_log_message && class_exists( '\ActionScheduler_Logger' ) ) {
				$entry['log_message'] = self::last_log_message( $id );
			}

			$sample[] = $entry;
		}

		return $sample;
	}

	/**
	 * Most recent log entry for an action, redacted the same as any other
	 * free-text capture. Returns null rather than an empty string when
	 * there's nothing to show, so report/UI code can tell "no log" apart
	 * from "log was empty after redaction."
	 */
	private static function last_log_message( $action_id ) {
		$logs = \ActionScheduler_Logger::instance()->get_logs( $action_id );

		if ( empty( $logs ) ) {
			return null;
		}

		$last = end( $logs );

		return Redactor::redact_text( $last->get_message() );
	}
}
