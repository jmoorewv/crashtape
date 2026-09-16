<?php

namespace JMooreWV\CrashTape\Cli;

use JMooreWV\CrashTape\Isolation\ConflictFinder;
use JMooreWV\CrashTape\Isolation\Loader as IsolationLoader;
use JMooreWV\CrashTape\Isolation\Token;

defined( 'ABSPATH' ) || exit;

/**
 * `wp crashtape isolation <command>`.
 */
class IsolationCommand {

	/**
	 * Shows the MU isolation loader, emergency bypass, and conflict
	 * finder status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp crashtape isolation status
	 */
	public function status( $args, $assoc_args ) {
		\WP_CLI::log( 'MU loader installed: ' . ( IsolationLoader::is_installed() ? 'yes' : 'no' ) );
		\WP_CLI::log( 'Emergency bypass active: ' . ( get_option( Token::BYPASS_OPTION ) ? 'yes' : 'no' ) );

		$finder = ConflictFinder::current();

		if ( ! $finder ) {
			\WP_CLI::log( 'Conflict finder: not running.' );
			return;
		}

		\WP_CLI::log(
			sprintf(
				'Conflict finder: %s (round %d, %d candidate(s) remaining)',
				$finder['status'],
				$finder['round'],
				count( $finder['candidates'] )
			)
		);

		if ( 'complete' === $finder['status'] && ! empty( $finder['result'] ) ) {
			\WP_CLI::log( 'Likely conflicting plugin: ' . $finder['result'] );
		}
	}
}
