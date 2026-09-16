<?php

namespace JMooreWV\CrashTape\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable diagnostic checks; lightweight, admin-defined assertions ("is
 * this option set to X", "does this URL respond 200") that don't require
 * writing PHP, unlike the developer-facing crashtape_register_diagnostic()
 * SDK (Sdk\Registry). Defined once, run on demand as many times as
 * needed. Every check type is a read-only assertion; none of them can
 * execute arbitrary code; so a broken or malicious check definition can
 * fail loudly but can't do anything worse than that (capture/checks must
 * never break the site).
 */
class CustomChecks {

	const OPTION     = 'crashtape_custom_checks';
	const MAX_CHECKS = 20;

	const TYPE_OPTION_EQUALS    = 'option_equals';
	const TYPE_CONSTANT_DEFINED = 'constant_defined';
	const TYPE_URL_STATUS       = 'url_status';
	const TYPE_PLUGIN_ACTIVE    = 'plugin_active';

	const TYPES = array(
		self::TYPE_OPTION_EQUALS,
		self::TYPE_CONSTANT_DEFINED,
		self::TYPE_URL_STATUS,
		self::TYPE_PLUGIN_ACTIVE,
	);

	const STATUS_PASS  = 'pass';
	const STATUS_FAIL  = 'fail';
	const STATUS_ERROR = 'error';

	public static function all() {
		$checks = get_option( self::OPTION, array() );

		return is_array( $checks ) ? $checks : array();
	}

	public static function find( $id ) {
		foreach ( self::all() as $check ) {
			if ( $check['id'] === $id ) {
				return $check;
			}
		}

		return null;
	}

	public static function add( $label, $type, array $config ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return false;
		}

		$checks = self::all();

		if ( count( $checks ) >= self::MAX_CHECKS ) {
			return false;
		}

		$checks[] = array(
			'id'     => wp_generate_uuid4(),
			'label'  => substr( sanitize_text_field( $label ), 0, 100 ),
			'type'   => $type,
			'config' => self::sanitize_config( $type, $config ),
		);

		update_option( self::OPTION, $checks );

		return true;
	}

	public static function delete( $id ) {
		$checks = array_values(
			array_filter(
				self::all(),
				function ( $check ) use ( $id ) {
					return $check['id'] !== $id;
				}
			)
		);

		update_option( self::OPTION, $checks );
	}

	/**
	 * Runs every stored check. Never lets one bad definition (an
	 * unreachable URL, a typo'd option name) stop the rest from running.
	 */
	public static function run_all() {
		$results = array();

		foreach ( self::all() as $check ) {
			try {
				$results[] = self::run_one( $check );
			} catch ( \Throwable $e ) {
				$results[] = self::result( $check, self::STATUS_ERROR, __( 'Check failed to run.', 'crashtape' ) );
			}
		}

		return $results;
	}

	private static function run_one( array $check ) {
		switch ( $check['type'] ) {
			case self::TYPE_OPTION_EQUALS:
				return self::eval_option_equals( $check );
			case self::TYPE_CONSTANT_DEFINED:
				return self::eval_constant_defined( $check );
			case self::TYPE_URL_STATUS:
				return self::eval_url_status( $check );
			case self::TYPE_PLUGIN_ACTIVE:
				return self::eval_plugin_active( $check );
			default:
				return self::result( $check, self::STATUS_ERROR, __( 'Unknown check type.', 'crashtape' ) );
		}
	}

	private static function eval_option_equals( array $check ) {
		$name     = $check['config']['option_name'];
		$expected = $check['config']['expected_value'];
		$actual   = get_option( $name );
		$pass     = is_scalar( $actual ) && (string) $actual === (string) $expected;

		return self::result(
			$check,
			$pass ? self::STATUS_PASS : self::STATUS_FAIL,
			sprintf(
				/* translators: 1: option name, 2: actual value, 3: expected value */
				__( 'Option "%1$s" is "%2$s" (expected "%3$s").', 'crashtape' ),
				$name,
				is_scalar( $actual ) ? (string) $actual : wp_json_encode( $actual ),
				$expected
			)
		);
	}

	private static function eval_constant_defined( array $check ) {
		$name = $check['config']['constant_name'];
		$pass = '' !== $name && defined( $name );

		return self::result(
			$check,
			$pass ? self::STATUS_PASS : self::STATUS_FAIL,
			sprintf(
				/* translators: %s: PHP constant name */
				$pass ? __( 'Constant "%s" is defined.', 'crashtape' ) : __( 'Constant "%s" is not defined.', 'crashtape' ),
				$name
			)
		);
	}

	private static function eval_url_status( array $check ) {
		$url             = $check['config']['url'];
		$expected_status = $check['config']['expected_status'];

		if ( '' === $url ) {
			return self::result( $check, self::STATUS_ERROR, __( 'No URL configured.', 'crashtape' ) );
		}

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return self::result(
				$check,
				self::STATUS_ERROR,
				sprintf(
					/* translators: %s: error message */
					__( 'Request failed: %s', 'crashtape' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$pass = in_array( $code, $expected_status, true );

		return self::result(
			$check,
			$pass ? self::STATUS_PASS : self::STATUS_FAIL,
			sprintf(
				/* translators: 1: actual HTTP status, 2: expected statuses */
				__( 'URL responded %1$d (expected %2$s).', 'crashtape' ),
				$code,
				implode( ', ', $expected_status )
			)
		);
	}

	private static function eval_plugin_active( array $check ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_file = $check['config']['plugin_file'];
		$pass        = '' !== $plugin_file && is_plugin_active( $plugin_file );

		return self::result(
			$check,
			$pass ? self::STATUS_PASS : self::STATUS_FAIL,
			sprintf(
				/* translators: %s: plugin file path, e.g. woocommerce/woocommerce.php */
				$pass ? __( 'Plugin "%s" is active.', 'crashtape' ) : __( 'Plugin "%s" is not active.', 'crashtape' ),
				$plugin_file
			)
		);
	}

	private static function result( array $check, $status, $message ) {
		return array(
			'id'      => $check['id'],
			'label'   => $check['label'],
			'type'    => $check['type'],
			'status'  => $status,
			'message' => $message,
		);
	}

	private static function sanitize_config( $type, array $config ) {
		switch ( $type ) {
			case self::TYPE_OPTION_EQUALS:
				return array(
					'option_name'    => sanitize_key( isset( $config['option_name'] ) ? $config['option_name'] : '' ),
					'expected_value' => substr( sanitize_text_field( isset( $config['expected_value'] ) ? $config['expected_value'] : '' ), 0, 200 ),
				);

			case self::TYPE_CONSTANT_DEFINED:
				return array(
					'constant_name' => preg_replace( '/[^A-Za-z0-9_]/', '', isset( $config['constant_name'] ) ? $config['constant_name'] : '' ),
				);

			case self::TYPE_URL_STATUS:
				$codes = preg_split( '/[\s,]+/', isset( $config['expected_status'] ) ? (string) $config['expected_status'] : '200', -1, PREG_SPLIT_NO_EMPTY );

				return array(
					'url'             => esc_url_raw( isset( $config['url'] ) ? $config['url'] : '' ),
					'expected_status' => $codes ? array_map( 'intval', $codes ) : array( 200 ),
				);

			case self::TYPE_PLUGIN_ACTIVE:
				return array(
					'plugin_file' => sanitize_text_field( isset( $config['plugin_file'] ) ? $config['plugin_file'] : '' ),
				);

			default:
				return array();
		}
	}
}
