<?php

namespace JMooreWV\CrashTape\Cli;

use JMooreWV\CrashTape\Database;

defined( 'ABSPATH' ) || exit;

/**
 * `wp crashtape changes <command>`.
 */
class ChangesCommand {

	/**
	 * Lists the most recently recorded plugin/theme/core changes.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum number of changes to show. Default 20.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, count. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crashtape changes list
	 */
	public function list( $args, $assoc_args ) {
		global $wpdb;

		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;
		$table = Database::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$changes = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		$rows = array();

		foreach ( $changes as $change ) {
			$rows[] = array(
				'time'      => $change['created_at'],
				'type'      => $change['change_type'],
				'component' => $change['component_type'] . ':' . $change['component_slug'],
				'from'      => $change['previous_version'] ? $change['previous_version'] : '',
				'to'        => $change['new_version'] ? $change['new_version'] : '',
			);
		}

		\WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$rows,
			array( 'time', 'type', 'component', 'from', 'to' )
		);
	}
}
