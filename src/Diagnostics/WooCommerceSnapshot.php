<?php

namespace JMooreWV\CrashTape\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce environment facts, captured at session start alongside the
 * environment/cron snapshot. Feature-detected; WooCommerce is a very
 * common but still optional dependency. Order-level failure events are
 * handled separately by Capture\WooCommerceCapture (a real-time,
 * session-scoped hook), not here; this class only reports point-in-time
 * facts, the same role CronSnapshot plays for WP-Cron/Action Scheduler in
 * general.
 */
class WooCommerceSnapshot {

	/**
	 * Action Scheduler group names WooCommerce core itself schedules
	 * under (confirmed against the real WC 11.1.0 source; class-wc-
	 * install.php, wc-product-functions.php, admin/notes/*.php; not
	 * guessed). Not necessarily exhaustive for every WC version or
	 * gateway extension; that's a real limitation, not an oversight.
	 */
	const GROUPS = array(
		'woocommerce',
		'woocommerce-sales',
		'woocommerce-db-updates',
		'wc-admin-data',
		'wc-admin-notes',
		'wc_update_product_lookup_tables',
	);

	const MAX_SAMPLE = 5;

	public static function capture() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'woocommerce' => array( 'detected' => false ) );
		}

		return array(
			'woocommerce' => array(
				'detected'          => true,
				'version'           => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'hpos_enabled'      => self::hpos_enabled(),
				'scheduled_actions' => self::scheduled_actions_overview(),
				'webhooks'          => self::webhook_health(),
				'templates'         => self::template_overrides(),
			),
		);
	}

	private static function hpos_enabled() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return null;
		}

		try {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private static function scheduled_actions_overview() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array( 'detected' => false );
		}

		try {
			$pending_count = 0;
			$failed_count  = 0;
			$failed_sample = array();

			foreach ( self::GROUPS as $group ) {
				$pending_count += count(
					as_get_scheduled_actions(
						array(
							'group'    => $group,
							'status'   => 'pending',
							'per_page' => 0,
						),
						'ids'
					)
				);
				$failed_count  += count(
					as_get_scheduled_actions(
						array(
							'group'    => $group,
							'status'   => 'failed',
							'per_page' => 0,
						),
						'ids'
					)
				);

				if ( count( $failed_sample ) < self::MAX_SAMPLE ) {
					$objects = as_get_scheduled_actions(
						array(
							'group'    => $group,
							'status'   => 'failed',
							'per_page' => self::MAX_SAMPLE - count( $failed_sample ),
							'orderby'  => 'date',
							'order'    => 'DESC',
						),
						'OBJECT'
					);

					foreach ( $objects as $id => $action ) {
						$schedule        = $action->get_schedule();
						$failed_sample[] = array(
							'id'             => (int) $id,
							'hook'           => $action->get_hook(),
							'group'          => $action->get_group(),
							'scheduled_date' => ( $schedule && $schedule->get_date() ) ? $schedule->get_date()->format( 'Y-m-d H:i:s' ) : null,
						);
					}
				}
			}

			return array(
				'detected'      => true,
				'pending_count' => $pending_count,
				'failed_count'  => $failed_count,
				'failed_sample' => $failed_sample,
			);
		} catch ( \Throwable $e ) {
			return array(
				'detected' => true,
				'error'    => 'query_failed',
			);
		}
	}

	/**
	 * Webhook health. Reuses WC's own real webhook API
	 * (WC_Data_Store::load('webhook'), wc_get_webhook()) rather than
	 * querying the underlying storage directly; WC core already auto-
	 * disables a webhook after 5 consecutive failures (class-wc-webhook.php
	 * failed_delivery(), confirmed against the real 11.1.0 source), so
	 * failure_count/status are already the exact signal to surface, not
	 * something this class needs to compute itself. Only the delivery
	 * URL's host is kept; webhook secrets are never-capture, and a
	 * delivery URL's path/query can itself embed one (e.g. a Zapier/Make
	 * hook token).
	 */
	private static function webhook_health() {
		if ( ! class_exists( 'WC_Data_Store' ) || ! function_exists( 'wc_get_webhook' ) ) {
			return array( 'detected' => false );
		}

		try {
			$data_store = \WC_Data_Store::load( 'webhook' );
			$ids        = $data_store->get_webhooks_ids();

			$by_status      = array(
				'active'   => 0,
				'paused'   => 0,
				'disabled' => 0,
			);
			$failing_sample = array();

			foreach ( $ids as $id ) {
				$webhook = wc_get_webhook( $id );

				if ( ! $webhook ) {
					continue;
				}

				$status = $webhook->get_status();

				if ( isset( $by_status[ $status ] ) ) {
					++$by_status[ $status ];
				}

				$failures = (int) $webhook->get_failure_count();

				if ( $failures > 0 && count( $failing_sample ) < self::MAX_SAMPLE ) {
					$failing_sample[] = array(
						'name'          => $webhook->get_name(),
						'topic'         => $webhook->get_topic(),
						'status'        => $status,
						'failure_count' => $failures,
						'delivery_host' => wp_parse_url( $webhook->get_delivery_url(), PHP_URL_HOST ),
					);
				}
			}

			return array_merge(
				array(
					'detected'       => true,
					'total'          => count( $ids ),
					'failing_sample' => $failing_sample,
				),
				$by_status
			);
		} catch ( \Throwable $e ) {
			return array(
				'detected' => true,
				'error'    => 'query_failed',
			);
		}
	}

	/**
	 * Template override warnings where safely detectable. Reuses WC's own
	 * real template-scanning implementation (WC_Admin_
	 * Status::scan_template_files()/get_file_version(), wc_locate_template();
	 * the same primitives WooCommerce's own Status > Templates panel and
	 * REST system-status endpoint use, confirmed by reading that endpoint's
	 * real source) rather than reimplementing "does this theme override a
	 * WC template, and is it stale" from scratch.
	 */
	private static function template_overrides() {
		if ( ! class_exists( 'WC_Admin_Status' ) || ! function_exists( 'wc_locate_template' ) || ! function_exists( 'WC' ) ) {
			return array( 'detected' => false );
		}

		try {
			$wc_templates_dir = trailingslashit( WC()->plugin_path() ) . 'templates/';
			$scan_files       = \WC_Admin_Status::scan_template_files( $wc_templates_dir );

			$overrides = array();
			$outdated  = false;

			foreach ( $scan_files as $file ) {
				$located = wc_locate_template( $file, WC()->template_path(), $wc_templates_dir );

				// Only overridden if WC actually resolved to a file outside its
				// own templates directory (a plain miss just falls back to core).
				if ( 0 !== strpos( $located, $wc_templates_dir ) && file_exists( $located ) ) {
					$core_version     = \WC_Admin_Status::get_file_version( $wc_templates_dir . $file );
					$override_version = \WC_Admin_Status::get_file_version( $located );
					$is_outdated      = $core_version && '' !== $override_version && version_compare( $override_version, $core_version, '<' );

					if ( $is_outdated ) {
						$outdated = true;
					}

					$overrides[] = array(
						'file'         => str_replace( ABSPATH, '', $located ),
						'version'      => $override_version,
						'core_version' => $core_version,
						'outdated'     => $is_outdated,
					);
				}
			}

			return array(
				'detected'       => true,
				'override_count' => count( $overrides ),
				'has_outdated'   => $outdated,
				'overrides'      => $overrides,
			);
		} catch ( \Throwable $e ) {
			return array(
				'detected' => true,
				'error'    => 'scan_failed',
			);
		}
	}
}
