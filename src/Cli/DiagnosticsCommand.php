<?php

namespace JMooreWV\CrashTape\Cli;

use JMooreWV\CrashTape\Diagnostics\CustomChecks;
use JMooreWV\CrashTape\Sdk\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * `wp crashtape diagnostics <command>`. Runs both mechanisms this plugin
 * calls a "diagnostic"; the developer-facing SDK registry
 * (crashtape_register_diagnostic()) and the no-code custom checks; since
 * both are genuinely "run a check and report pass/fail/error", just
 * aimed at different authors.
 */
class DiagnosticsCommand {

	/**
	 * Runs every registered diagnostic and custom check.
	 *
	 * SDK diagnostics are gated by their own registered capability
	 * (crashtape_register_diagnostic()'s 'permission' arg); run with
	 * --user=<id> for an admin capability check to actually pass under
	 * WP-CLI, which has no logged-in user by default.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crashtape diagnostics run --user=1
	 */
	public function run( $args, $assoc_args ) {
		$rows = array();

		foreach ( Registry::run_diagnostics() as $slug => $result ) {
			$rows[] = array(
				'source'  => 'sdk',
				'label'   => $slug,
				'status'  => $result['status'],
				'message' => $result['summary'],
			);
		}

		foreach ( CustomChecks::run_all() as $result ) {
			$rows[] = array(
				'source'  => 'custom-check',
				'label'   => $result['label'],
				'status'  => $result['status'],
				'message' => $result['message'],
			);
		}

		if ( ! $rows ) {
			\WP_CLI::log( 'No diagnostics or custom checks are registered.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$rows,
			array( 'source', 'label', 'status', 'message' )
		);
	}
}
