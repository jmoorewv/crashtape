<?php

namespace JMooreWV\CrashTape\Analysis;

use JMooreWV\CrashTape\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Session comparison; one of the strongest professional features.
 * Compares a working session against a failing one and highlights only
 * meaningful differences: environment/plugin versions and which error
 * fingerprints appear in one but not the other. Not "mark this session as
 * a healthy baseline"; that's explicitly a separate, later feature; this
 * is the direct two-session diff only.
 */
class Comparison {

	public static function compare( array $session_a, array $session_b ) {
		$env_a = self::decode_environment( $session_a );
		$env_b = self::decode_environment( $session_b );

		return array(
			'session_a'        => self::summary( $session_a ),
			'session_b'        => self::summary( $session_b ),
			'environment_diff' => self::environment_diff( $env_a, $env_b ),
			'plugin_diff'      => self::plugin_diff( $env_a, $env_b ),
			'fingerprint_diff' => self::fingerprint_diff( (int) $session_a['id'], (int) $session_b['id'] ),
		);
	}

	private static function summary( array $session ) {
		return array(
			'uuid'   => $session['uuid'],
			'label'  => $session['label'],
			'status' => $session['status'],
		);
	}

	private static function decode_environment( array $session ) {
		$env = ! empty( $session['environment'] ) ? json_decode( $session['environment'], true ) : array();

		return is_array( $env ) ? $env : array();
	}

	/**
	 * Only the fields most likely to explain "why did this start failing";
	 * not every environment field, since the goal is to highlight only
	 * meaningful differences.
	 */
	private static function environment_diff( array $env_a, array $env_b ) {
		$fields = array(
			'wp_version'       => 'WordPress version',
			'php_version'      => 'PHP version',
			'https'            => 'HTTPS',
			'object_cache'     => 'Object cache',
			'wp_cron_disabled' => 'WP-Cron disabled',
		);

		$diff = array();

		foreach ( $fields as $key => $label ) {
			$a = self::stringify( $env_a[ $key ] ?? null );
			$b = self::stringify( $env_b[ $key ] ?? null );

			if ( $a !== $b ) {
				$diff[] = array(
					'field' => $label,
					'a'     => $a,
					'b'     => $b,
				);
			}
		}

		$theme_a = ! empty( $env_a['active_theme'] ) ? $env_a['active_theme']['name'] . ' ' . $env_a['active_theme']['version'] : null;
		$theme_b = ! empty( $env_b['active_theme'] ) ? $env_b['active_theme']['name'] . ' ' . $env_b['active_theme']['version'] : null;

		if ( $theme_a !== $theme_b ) {
			$diff[] = array(
				'field' => 'Active theme',
				'a'     => $theme_a,
				'b'     => $theme_b,
			);
		}

		return $diff;
	}

	/**
	 * A worked example is exactly this: "Payment Addon: 3.4.0" vs "Payment
	 * Addon: 3.5.0." Only plugins that differ (present in one but not the
	 * other, or a different version) are returned.
	 */
	private static function plugin_diff( array $env_a, array $env_b ) {
		$plugins_a = self::index_plugins( $env_a );
		$plugins_b = self::index_plugins( $env_b );

		$diff = array();

		foreach ( array_unique( array_merge( array_keys( $plugins_a ), array_keys( $plugins_b ) ) ) as $slug ) {
			$version_a = $plugins_a[ $slug ] ?? null;
			$version_b = $plugins_b[ $slug ] ?? null;

			if ( $version_a !== $version_b ) {
				$diff[] = array(
					'slug' => $slug,
					'a'    => $version_a,
					'b'    => $version_b,
				);
			}
		}

		return $diff;
	}

	private static function index_plugins( array $env ) {
		$indexed = array();

		foreach ( $env['active_plugins'] ?? array() as $plugin ) {
			if ( ! empty( $plugin['slug'] ) ) {
				$indexed[ $plugin['slug'] ] = $plugin['version'];
			}
		}

		return $indexed;
	}

	/**
	 * The fingerprinting work makes this cheap: which distinct errors
	 * appeared only in the failing session, or only in
	 * the working one. Fingerprints present in both aren't returned;
	 * they're not a meaningful difference between the two sessions.
	 */
	private static function fingerprint_diff( $session_a_id, $session_b_id ) {
		$events_a = self::fingerprints_for( $session_a_id );
		$events_b = self::fingerprints_for( $session_b_id );

		return array(
			'only_in_a' => array_values( array_diff_key( $events_a, $events_b ) ),
			'only_in_b' => array_values( array_diff_key( $events_b, $events_a ) ),
		);
	}

	private static function fingerprints_for( $session_id ) {
		global $wpdb;

		$table = Database::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fingerprint, severity, summary, occurrence_count FROM {$table} WHERE session_id = %d AND fingerprint IS NOT NULL",
				$session_id
			),
			ARRAY_A
		);

		$indexed = array();

		foreach ( $rows as $row ) {
			$indexed[ $row['fingerprint'] ] = array(
				'severity'         => $row['severity'],
				'summary'          => $row['summary'],
				'occurrence_count' => (int) $row['occurrence_count'],
			);
		}

		return $indexed;
	}

	private static function stringify( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}

		return null === $value ? '(unknown)' : (string) $value;
	}
}
