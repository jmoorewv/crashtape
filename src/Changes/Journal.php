<?php

namespace JMooreWV\CrashTape\Changes;

use JMooreWV\CrashTape\Database;
use JMooreWV\CrashTape\Redactor;
use WP_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight change journal, independent of diagnostic sessions.
 * Real-time capture via WordPress's own update/activation hooks, plus a
 * throttled admin-only reconciliation pass for updates that don't flow
 * through those hooks; WP-CLI, manual file replacement, etc.
 */
class Journal {

	const SNAPSHOT_OPTION   = 'crashtape_version_snapshot';
	const RECONCILE_LOCK    = 'crashtape_reconcile_lock';
	const RECONCILE_MINUTES = 5;

	/**
	 * Changes in the window before a session started; shared by the
	 * Timeline admin view and the Analysis engine so both
	 * use the same correlation window.
	 */
	public static function changes_before( array $session, $window_hours = 24 ) {
		global $wpdb;

		$window_start = gmdate( 'Y-m-d H:i:s', strtotime( $session['started_at'] ) - ( $window_hours * HOUR_IN_SECONDS ) );
		$table        = Database::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC",
				$window_start,
				$session['started_at']
			)
		);
	}

	public function register_hooks() {
		add_action( 'upgrader_process_complete', array( $this, 'handle_upgrader_complete' ), 10, 2 );
		add_action( 'activated_plugin', array( $this, 'handle_plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( $this, 'handle_plugin_deactivated' ), 10, 2 );
		add_action( 'deleted_plugin', array( $this, 'handle_plugin_deleted' ), 10, 2 );
		add_action( 'switch_theme', array( $this, 'handle_theme_switch' ), 10, 3 );

		// admin_init only; never runs on the frontend, and throttled below.
		add_action( 'admin_init', array( $this, 'maybe_reconcile' ) );
	}

	public function handle_upgrader_complete( $upgrader, $hook_extra ) {
		try {
			if ( ! isset( $hook_extra['action'], $hook_extra['type'] ) || 'update' !== $hook_extra['action'] ) {
				return;
			}

			if ( 'plugin' === $hook_extra['type'] ) {
				$plugins = ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] )
					? $hook_extra['plugins']
					: ( ! empty( $hook_extra['plugin'] ) ? array( $hook_extra['plugin'] ) : array() );

				foreach ( $plugins as $plugin_file ) {
					$this->record_plugin_update( $plugin_file );
				}
			} elseif ( 'theme' === $hook_extra['type'] ) {
				$themes = ! empty( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] )
					? $hook_extra['themes']
					: ( ! empty( $hook_extra['theme'] ) ? array( $hook_extra['theme'] ) : array() );

				foreach ( $themes as $slug ) {
					$this->record_theme_update( $slug );
				}
			} elseif ( 'core' === $hook_extra['type'] ) {
				$this->record_core_update();
			}
		} catch ( \Throwable $e ) {
			// Never let the journal break the updater.
		}
	}

	public function handle_plugin_activated( $plugin_file, $network_wide ) {
		try {
			$slug = self::plugin_slug( $plugin_file );

			$this->insert(
				'plugin_activated',
				'plugin',
				$slug,
				null,
				null,
				array(
					'file'         => $plugin_file,
					'network_wide' => (bool) $network_wide,
				)
			);

			// Seed the snapshot so the *next* real update has an accurate
			// "before" version; otherwise an update that lands before the
			// first admin_init reconciliation pass reports previous_version
			// as unknown even though we could have known it.
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
			$data      = is_readable( $full_path ) ? get_plugin_data( $full_path, false, false ) : array();

			$this->snapshot_set( 'plugin', $slug, ! empty( $data['Version'] ) ? $data['Version'] : null );
		} catch ( \Throwable $e ) {
			// Never let the journal break plugin activation.
		}
	}

	public function handle_plugin_deactivated( $plugin_file, $network_wide ) {
		try {
			$this->insert(
				'plugin_deactivated',
				'plugin',
				self::plugin_slug( $plugin_file ),
				null,
				null,
				array(
					'file'         => $plugin_file,
					'network_wide' => (bool) $network_wide,
				)
			);
		} catch ( \Throwable $e ) {
			// Never let the journal break plugin deactivation.
		}
	}

	public function handle_plugin_deleted( $plugin_file, $deleted ) {
		try {
			if ( ! $deleted ) {
				return;
			}

			$slug = self::plugin_slug( $plugin_file );

			$this->insert( 'plugin_deleted', 'plugin', $slug, null, null, array( 'file' => $plugin_file ) );
			$this->snapshot_forget( 'plugin', $slug );
		} catch ( \Throwable $e ) {
			// Never let the journal break plugin deletion.
		}
	}

	public function handle_theme_switch( $new_name, $new_theme, $old_theme ) {
		try {
			$new_slug = $new_theme instanceof WP_Theme ? $new_theme->get_stylesheet() : sanitize_title( $new_name );
			$old_slug = $old_theme instanceof WP_Theme ? $old_theme->get_stylesheet() : null;
			$new_ver  = $new_theme instanceof WP_Theme ? $new_theme->get( 'Version' ) : null;
			$old_ver  = $old_theme instanceof WP_Theme ? $old_theme->get( 'Version' ) : null;

			$this->insert(
				'theme_switched',
				'theme',
				$new_slug,
				$old_ver,
				$new_ver,
				array(
					'previous_theme' => $old_slug,
					'new_theme'      => $new_slug,
				)
			);

			$this->snapshot_set( 'theme', $new_slug, $new_ver );
		} catch ( \Throwable $e ) {
			// Never let the journal break a theme switch.
		}
	}

	/**
	 * Throttled to once per RECONCILE_MINUTES via a transient lock;
	 * get_plugins() is cheap (WordPress caches it) but there is no reason
	 * to run this reconciliation on every single wp-admin page load.
	 */
	public function maybe_reconcile() {
		if ( get_transient( self::RECONCILE_LOCK ) ) {
			return;
		}

		set_transient( self::RECONCILE_LOCK, 1, self::RECONCILE_MINUTES * MINUTE_IN_SECONDS );

		try {
			$this->reconcile_php_version();
			$this->reconcile_core_version();
			$this->reconcile_plugin_versions();
		} catch ( \Throwable $e ) {
			// Never let reconciliation break wp-admin.
		}
	}

	private function reconcile_php_version() {
		$current  = PHP_VERSION;
		$previous = $this->snapshot_get( 'core', 'php' );

		if ( $previous && $previous !== $current ) {
			$this->insert( 'php_version_changed', 'core', 'php', $previous, $current, array( 'source' => 'reconciliation' ) );
		}

		$this->snapshot_set( 'core', 'php', $current );
	}

	/**
	 * The lowercase core slug below is a machine key (sibling to 'php'
	 * just above, both under the 'core' component_type), not display
	 * text; auto-capitalizing it (as a coding-standards fixer otherwise
	 * would) would silently break every existing
	 * crashtape_version_snapshot option's lookup key.
	 */
	private function reconcile_core_version() {
		$current  = get_bloginfo( 'version' );
		$previous = $this->snapshot_get( 'core', 'wordpress' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText

		if ( $previous && $previous !== $current ) {
			$this->insert( 'core_updated', 'core', 'wordpress', $previous, $current, array( 'source' => 'reconciliation' ) ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText
		}

		$this->snapshot_set( 'core', 'wordpress', $current ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText
	}

	private function reconcile_plugin_versions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$seen_slugs = array();

		foreach ( get_plugins() as $plugin_file => $data ) {
			$slug                = self::plugin_slug( $plugin_file );
			$seen_slugs[ $slug ] = true;
			$current             = ! empty( $data['Version'] ) ? $data['Version'] : null;
			$previous            = $this->snapshot_get( 'plugin', $slug );

			if ( $previous && $current && $previous !== $current ) {
				$this->insert( 'plugin_updated', 'plugin', $slug, $previous, $current, array( 'source' => 'reconciliation' ) );
			}

			$this->snapshot_set( 'plugin', $slug, $current );
		}

		// Catches deletions that never fire WordPress's deleted_plugin
		// action; e.g. `wp plugin delete` removes the directory directly
		// without going through core's delete_plugins() (confirmed via a
		// bare probe hook: WP-CLI's plugin delete triggers neither
		// delete_plugin nor deleted_plugin).
		$snapshot = $this->snapshot();

		if ( ! empty( $snapshot['plugin'] ) && is_array( $snapshot['plugin'] ) ) {
			foreach ( array_keys( $snapshot['plugin'] ) as $slug ) {
				if ( ! isset( $seen_slugs[ $slug ] ) ) {
					$this->insert( 'plugin_deleted', 'plugin', $slug, null, null, array( 'source' => 'reconciliation' ) );
					$this->snapshot_forget( 'plugin', $slug );
				}
			}
		}
	}

	private function record_plugin_update( $plugin_file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$slug      = self::plugin_slug( $plugin_file );
		$full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		$data      = is_readable( $full_path ) ? get_plugin_data( $full_path, false, false ) : array();
		$new       = ! empty( $data['Version'] ) ? $data['Version'] : null;
		$previous  = $this->snapshot_get( 'plugin', $slug );

		if ( $previous === $new ) {
			return;
		}

		$this->insert( 'plugin_updated', 'plugin', $slug, $previous, $new, array( 'file' => $plugin_file ) );
		$this->snapshot_set( 'plugin', $slug, $new );
	}

	private function record_theme_update( $slug ) {
		$theme    = wp_get_theme( $slug );
		$new      = $theme->exists() ? $theme->get( 'Version' ) : null;
		$previous = $this->snapshot_get( 'theme', $slug );

		if ( $previous === $new ) {
			return;
		}

		$this->insert( 'theme_updated', 'theme', $slug, $previous, $new, array() );
		$this->snapshot_set( 'theme', $slug, $new );
	}

	/**
	 * Same lowercase core slug as reconcile_core_version() above; see
	 * that method's docblock for why it stays lowercase.
	 */
	private function record_core_update() {
		$new      = get_bloginfo( 'version' );
		$previous = $this->snapshot_get( 'core', 'wordpress' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText

		if ( $previous === $new ) {
			return;
		}

		$this->insert( 'core_updated', 'core', 'wordpress', $previous, $new, array() ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText
		$this->snapshot_set( 'core', 'wordpress', $new ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText
	}

	private function insert( $change_type, $component_type, $component_slug, $previous_version, $new_version, array $metadata ) {
		global $wpdb;

		$wpdb->insert(
			Database::changes_table(),
			array(
				'change_type'      => sanitize_key( $change_type ),
				'component_type'   => sanitize_key( $component_type ),
				'component_slug'   => substr( sanitize_text_field( (string) $component_slug ), 0, 191 ),
				'previous_version' => $previous_version ? substr( (string) $previous_version, 0, 64 ) : null,
				'new_version'      => $new_version ? substr( (string) $new_version, 0, 64 ) : null,
				'metadata'         => wp_json_encode( Redactor::redact_array( $metadata ) ),
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private static function plugin_slug( $plugin_file ) {
		return strtok( (string) $plugin_file, '/' );
	}

	private function snapshot() {
		$snapshot = get_option( self::SNAPSHOT_OPTION );

		return is_array( $snapshot ) ? $snapshot : array();
	}

	private function snapshot_get( $type, $slug ) {
		$snapshot = $this->snapshot();

		return isset( $snapshot[ $type ][ $slug ] ) ? $snapshot[ $type ][ $slug ] : null;
	}

	private function snapshot_set( $type, $slug, $version ) {
		$snapshot                   = $this->snapshot();
		$snapshot[ $type ][ $slug ] = $version;
		update_option( self::SNAPSHOT_OPTION, $snapshot, false );
	}

	private function snapshot_forget( $type, $slug ) {
		$snapshot = $this->snapshot();
		unset( $snapshot[ $type ][ $slug ] );
		update_option( self::SNAPSHOT_OPTION, $snapshot, false );
	}
}
