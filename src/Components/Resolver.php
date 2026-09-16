<?php

namespace JMooreWV\CrashTape\Components;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a filesystem path (or a site-relative asset URL path, which has the
 * same wp-content/... shape) to the plugin/theme/core/mu-plugin/drop-in
 * that owns it.
 *
 * Only ever returns the relative path; the full absolute server path is
 * never exposed (it should not be shown in normal reports unless the
 * user enables advanced detail, which Phase 1 doesn't have yet, so never).
 */
class Resolver {

	/** Component types specific enough to be worth attributing a request/event to. */
	const ATTRIBUTABLE_TYPES = array( 'plugin', 'theme', 'parent-theme', 'mu-plugin' );

	const BACKTRACE_LIMIT = 30;

	const KNOWN_DROP_INS = array(
		'advanced-cache.php',
		'db.php',
		'db-error.php',
		'install.php',
		'maintenance.php',
		'object-cache.php',
		'php-error.php',
		'fatal-error-handler.php',
		'sunrise.php',
		'blog-deleted.php',
		'blog-inactive.php',
		'blog-suspended.php',
	);

	/** @var array|null Cache of get_plugins() for this request. */
	private static $plugin_headers;

	/**
	 * Walks the call stack to find who actually initiated whatever's being
	 * captured (an HTTP request, a wp_mail() call, ...); WordPress core
	 * and CrashTape's own frames don't count. Callers should pass
	 * DEBUG_BACKTRACE_IGNORE_ARGS to debug_backtrace(): argument values
	 * could contain request bodies, credentials, etc.
	 */
	public static function resolve_caller( array $trace ) {
		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}

			// Skip CrashTape's own frames (this handler, the shutdown
			// closure, etc.); otherwise the walk matches this plugin
			// itself before ever reaching the real caller. (Found the hard
			// way: HttpCapture originally attributed outbound failures to
			// itself).
			if ( 0 === strpos( $frame['file'], CRASHTAPE_DIR ) ) {
				continue;
			}

			$component = self::resolve_path( $frame['file'] );

			if ( in_array( $component['type'], self::ATTRIBUTABLE_TYPES, true ) ) {
				return $component;
			}
		}

		return null;
	}

	public static function resolve_path( $path ) {
		$relative = self::to_relative( (string) $path );

		if ( '' === $relative ) {
			return self::unknown( '' );
		}

		if ( preg_match( '#^wp-content/([^/]+\.php)$#', $relative, $m ) && in_array( $m[1], self::KNOWN_DROP_INS, true ) ) {
			return array(
				'type'    => 'drop-in',
				'slug'    => $m[1],
				'name'    => $m[1],
				'version' => null,
				'file'    => $relative,
			);
		}

		if ( preg_match( '#^wp-content/plugins/([^/]+)/(.*)$#', $relative, $m ) ) {
			return self::plugin_result( $m[1], $m[2] );
		}

		if ( preg_match( '#^wp-content/plugins/([^/]+\.php)$#', $relative, $m ) ) {
			// A single-file plugin living directly in plugins/ (e.g. hello.php).
			return self::plugin_result( $m[1], $m[1] );
		}

		if ( preg_match( '#^wp-content/mu-plugins/(.+)$#', $relative, $m ) ) {
			return array(
				'type'    => 'mu-plugin',
				'slug'    => basename( $m[1] ),
				'name'    => basename( $m[1] ),
				'version' => self::mu_plugin_version( $m[1] ),
				'file'    => $m[1],
			);
		}

		if ( preg_match( '#^wp-content/themes/([^/]+)/(.*)$#', $relative, $m ) ) {
			return self::theme_result( $m[1], $m[2] );
		}

		if ( preg_match( '#^(?:wp-admin|wp-includes)/#', $relative ) || preg_match( '#^wp-[a-z-]+\.php$#', $relative ) ) {
			return array(
				'type'    => 'core',
				'slug'    => null,
				'name'    => 'WordPress Core',
				'version' => get_bloginfo( 'version' ),
				'file'    => $relative,
			);
		}

		return self::unknown( $relative );
	}

	private static function plugin_result( $slug, $file ) {
		$headers = self::plugin_headers();
		$name    = $slug;
		$version = null;

		foreach ( $headers as $plugin_file => $data ) {
			if ( 0 === strpos( $plugin_file, $slug . '/' ) || $plugin_file === $slug ) {
				$name    = ! empty( $data['Name'] ) ? $data['Name'] : $slug;
				$version = ! empty( $data['Version'] ) ? $data['Version'] : null;
				break;
			}
		}

		return array(
			'type'    => 'plugin',
			'slug'    => $slug,
			'name'    => $name,
			'version' => $version,
			'file'    => $file,
		);
	}

	private static function theme_result( $slug, $file ) {
		$theme     = wp_get_theme( $slug );
		$is_active = get_stylesheet() === $slug;
		$is_parent = ! $is_active && get_template() === $slug;

		return array(
			'type'    => $is_parent ? 'parent-theme' : 'theme',
			'slug'    => $slug,
			'name'    => $theme->exists() ? $theme->get( 'Name' ) : $slug,
			'version' => $theme->exists() ? $theme->get( 'Version' ) : null,
			'file'    => $file,
		);
	}

	private static function mu_plugin_version( $relative_file ) {
		$full = WPMU_PLUGIN_DIR . '/' . $relative_file;

		if ( ! is_readable( $full ) ) {
			return null;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( $full, false, false );

		return ! empty( $data['Version'] ) ? $data['Version'] : null;
	}

	private static function plugin_headers() {
		if ( null === self::$plugin_headers ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			self::$plugin_headers = get_plugins();
		}

		return self::$plugin_headers;
	}

	private static function unknown( $relative ) {
		return array(
			'type'    => 'unknown',
			'slug'    => null,
			'name'    => null,
			'version' => null,
			'file'    => $relative,
		);
	}

	/**
	 * Accepts either an absolute filesystem path or a site-relative URL
	 * path (both share the same wp-content/... shape). Returns '' if it
	 * can't be normalized to something starting with wp-content, wp-admin,
	 * or wp-includes.
	 */
	private static function to_relative( $path ) {
		if ( '' === $path ) {
			return '';
		}

		if ( 0 === strpos( $path, ABSPATH ) ) {
			$path = substr( $path, strlen( ABSPATH ) );
		}

		$path = ltrim( str_replace( '\\', '/', $path ), '/' );

		if ( preg_match( '#^(wp-content|wp-admin|wp-includes)(/|$)#', $path ) ) {
			return $path;
		}

		return '';
	}
}
