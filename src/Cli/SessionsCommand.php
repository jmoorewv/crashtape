<?php

namespace JMooreWV\CrashTape\Cli;

use JMooreWV\CrashTape\Session;

defined( 'ABSPATH' ) || exit;

/**
 * `wp crashtape sessions <command>`.
 */
class SessionsCommand {

	/**
	 * Lists recent completed sessions.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum number of sessions to show. Default 20.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, count. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crashtape sessions list
	 *     wp crashtape sessions list --limit=5 --format=json
	 */
	public function list( $args, $assoc_args ) {
		$limit    = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;
		$sessions = Session::recent( $limit );

		$rows = array();

		foreach ( $sessions as $session ) {
			$rows[] = array(
				'id'         => $session['id'],
				'uuid'       => $session['uuid'],
				'label'      => $session['label'] ? $session['label'] : '',
				'mode'       => $session['mode'],
				'status'     => $session['status'],
				'started_at' => $session['started_at'],
			);
		}

		\WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$rows,
			array( 'id', 'uuid', 'label', 'mode', 'status', 'started_at' )
		);
	}

	/**
	 * Shows a single session's summary and top analysis result.
	 *
	 * ## OPTIONS
	 *
	 * <session>
	 * : Session UUID or numeric ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crashtape sessions show a1b2c3d4-e5f6-7890-abcd-ef1234567890
	 */
	public function show( $args, $assoc_args ) {
		$session = Helpers::find_session( $args[0] );

		if ( ! $session ) {
			\WP_CLI::error( 'No session found matching "' . $args[0] . '".' );
			return;
		}

		\WP_CLI::log( 'UUID: ' . $session['uuid'] );
		\WP_CLI::log( 'Label: ' . ( $session['label'] ? $session['label'] : '(none)' ) );
		\WP_CLI::log( 'Mode: ' . $session['mode'] );
		\WP_CLI::log( 'Status: ' . $session['status'] );
		\WP_CLI::log( 'Started: ' . $session['started_at'] );
		\WP_CLI::log( 'Stopped: ' . ( $session['stopped_at'] ? $session['stopped_at'] : '(still active)' ) );
		\WP_CLI::log(
			sprintf(
				'Events: %d (%d errors, %d warnings)',
				$session['event_count'],
				$session['error_count'],
				$session['warning_count']
			)
		);

		if ( ! empty( $session['analysis'] ) ) {
			$analysis = json_decode( $session['analysis'], true );
			$top      = ( $analysis && ! empty( $analysis['candidates'] ) ) ? $analysis['candidates'][0] : null;

			if ( $top ) {
				\WP_CLI::log( sprintf( 'Most likely issue: %s (%s confidence)', $top['title'], $top['confidence'] ) );
			}
		}
	}
}
