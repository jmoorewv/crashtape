<?php
/**
 * Plugin Name:       CrashTape
 * Description:       WordPress Diagnostic & Support Recorder. Reproduce it. Record it. Fix it. Release candidate: feature-frozen, accepting release-blocker and important fixes only.
 * Version:           1.0.0-rc.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Jonathan Moore
 * Author URI:        https://jmoorewv.com
 * Text Domain:       crashtape
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'CRASHTAPE_VERSION', '1.0.0-rc.1' );
define( 'CRASHTAPE_FILE', __FILE__ );
define( 'CRASHTAPE_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRASHTAPE_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'JMooreWV\\CrashTape\\';

		if ( 0 !== strncmp( $prefix, $class_name, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = CRASHTAPE_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $path ) ) {
			require $path;
		}
	}
);

// Loaded unconditionally, not inside any hook, so these global functions are
// defined as early as any other plugin's own bootstrap could run.
require CRASHTAPE_DIR . 'src/Sdk/functions.php';

register_activation_hook(
	__FILE__,
	function () {
		JMooreWV\CrashTape\Database::install();
		JMooreWV\CrashTape\Cleanup::schedule();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		JMooreWV\CrashTape\Cleanup::unschedule();
		JMooreWV\CrashTape\Diagnostics\PassiveMonitor::unschedule();
	}
);

add_action( 'plugins_loaded', array( 'JMooreWV\\CrashTape\\Bootstrap', 'init' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'crashtape', 'JMooreWV\\CrashTape\\Cli\\StatusCommand' );
	WP_CLI::add_command( 'crashtape sessions', 'JMooreWV\\CrashTape\\Cli\\SessionsCommand' );
	WP_CLI::add_command( 'crashtape diagnostics', 'JMooreWV\\CrashTape\\Cli\\DiagnosticsCommand' );
	WP_CLI::add_command( 'crashtape changes', 'JMooreWV\\CrashTape\\Cli\\ChangesCommand' );
	WP_CLI::add_command( 'crashtape isolation', 'JMooreWV\\CrashTape\\Cli\\IsolationCommand' );
}
