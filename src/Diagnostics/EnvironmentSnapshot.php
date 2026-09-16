<?php

namespace JMooreWV\CrashTape\Diagnostics;

use JMooreWV\CrashTape\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Captures a point-in-time environment snapshot. Builds on WordPress's
 * own Site Health debug data functions where they exist rather than
 * reinventing plugin/theme/drop-in inspection.
 *
 * Debug constants are represented as enabled/disabled booleans only; their
 * values are never dumped, since secret constant values should never be
 * exported, and this avoids the equivalent of dumping wp-config.php
 * wholesale.
 */
class EnvironmentSnapshot {

	public static function capture() {
		global $wpdb;

		$snapshot = array(
			'wp_version'          => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'database_version'    => $wpdb->db_version(),
			'server_software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : null,
			'memory_limit'        => ini_get( 'memory_limit' ),
			'wp_memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : null,
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'active_theme'        => self::theme_info( get_stylesheet() ),
			'parent_theme'        => is_child_theme() ? self::theme_info( get_template() ) : null,
			'active_plugins'      => self::active_plugins(),
			'mu_plugins'          => self::mu_plugins(),
			'drop_ins'            => self::drop_ins(),
			'pretty_permalinks'   => (bool) get_option( 'permalink_structure' ),
			'multisite'           => is_multisite(),
			'object_cache'        => (bool) wp_using_ext_object_cache(),
			// No reliable generic page-cache detection API exists; this is
			// a hint, not a claim of universal visibility.
			'page_cache_hint'     => defined( 'WP_CACHE' ) && WP_CACHE,
			'https'               => is_ssl(),
			'wp_cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'debug'               => array(
				'WP_DEBUG'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'WP_DEBUG_LOG'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
				'WP_DEBUG_DISPLAY' => ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY,
				'SCRIPT_DEBUG'     => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			),
			'filesystem_method'   => function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : null,
			'timezone'            => wp_timezone_string(),
			'captured_at'         => current_time( 'mysql', true ),
		);

		return Redactor::redact_array( $snapshot );
	}

	private static function theme_info( $stylesheet_or_template ) {
		$theme = wp_get_theme( $stylesheet_or_template );

		if ( ! $theme->exists() ) {
			return null;
		}

		return array(
			'slug'    => $stylesheet_or_template,
			'name'    => $theme->get( 'Name' ),
			'version' => $theme->get( 'Version' ),
		);
	}

	private static function active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$result = array();

		foreach ( $active as $plugin_file ) {
			if ( ! isset( $all[ $plugin_file ] ) ) {
				continue;
			}

			$result[] = array(
				'slug'    => strtok( $plugin_file, '/' ),
				'name'    => $all[ $plugin_file ]['Name'],
				'version' => $all[ $plugin_file ]['Version'],
			);
		}

		return $result;
	}

	private static function mu_plugins() {
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$result = array();

		foreach ( get_mu_plugins() as $file => $data ) {
			$result[] = array(
				'file'    => $file,
				'name'    => ! empty( $data['Name'] ) ? $data['Name'] : $file,
				'version' => ! empty( $data['Version'] ) ? $data['Version'] : null,
			);
		}

		return $result;
	}

	private static function drop_ins() {
		if ( ! function_exists( 'get_dropins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return array_keys( get_dropins() );
	}
}
