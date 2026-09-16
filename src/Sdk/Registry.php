<?php

namespace JMooreWV\CrashTape\Sdk;

use JMooreWV\CrashTape\EventWriter;
use JMooreWV\CrashTape\Redactor;
use JMooreWV\CrashTape\RequestContext;

defined( 'ABSPATH' ) || exit;

/**
 * Backs the public global functions in sdk-functions.php. Third-party
 * plugins never touch this class directly; the global functions are the
 * actual API surface, using plain function calls rather than namespaced
 * ones.
 */
class Registry {

	const STATUSES = array( 'good', 'info', 'warning', 'error', 'unavailable' );

	/** Diagnostics take longer than this get flagged in their own result. */
	const TIME_BUDGET_SECONDS = 2.0;

	/** @var array<string,array> */
	private static $diagnostics = array();

	/** @var array<string,array> */
	private static $rules = array();

	/**
	 * Providers must declare sensitivity, provide a plain-language summary,
	 * and fail safely. Enforced here at registration time rather than
	 * trusted; a provider that skips a required field is rejected outright
	 * (with a doing_it_wrong notice, the standard WordPress way of telling
	 * a developer their integration is wrong) rather than silently
	 * accepted and failing later.
	 */
	public static function register_diagnostic( $slug, array $args ) {
		$slug = self::sanitize_namespaced_key( $slug );

		if ( '' === $slug || empty( $args['callback'] ) || ! is_callable( $args['callback'] ) ) {
			self::doing_it_wrong( 'crashtape_register_diagnostic', 'A diagnostic needs a slug and a callable "callback".' );
			return false;
		}

		if ( empty( $args['label'] ) ) {
			self::doing_it_wrong( 'crashtape_register_diagnostic', 'A diagnostic needs a "label".' );
			return false;
		}

		if ( empty( $args['sensitivity'] ) || ! in_array( $args['sensitivity'], array( 'low', 'medium', 'high' ), true ) ) {
			self::doing_it_wrong( 'crashtape_register_diagnostic', 'A diagnostic needs a "sensitivity" of low, medium, or high.' );
			return false;
		}

		self::$diagnostics[ $slug ] = array(
			'label'       => sanitize_text_field( $args['label'] ),
			'permission'  => ! empty( $args['permission'] ) ? sanitize_key( $args['permission'] ) : 'manage_options',
			'callback'    => $args['callback'],
			'sensitivity' => $args['sensitivity'],
		);

		return true;
	}

	/**
	 * Custom rule callback contract (documented for third parties, not
	 * enforced beyond shape-checking the return value): given an event
	 * (stdClass row) and its decoded data array, return null (no match) or
	 * an array with classification/title/explanation/suggested_checks/
	 * base_points; the same shape Analysis\Engine's built-in rules
	 * produce (diagnosis code, title, explanation, confidence
	 * contribution, suggested checks).
	 */
	public static function register_rule( $code, array $args ) {
		$code = self::sanitize_namespaced_key( $code );

		if ( '' === $code || empty( $args['callback'] ) || ! is_callable( $args['callback'] ) ) {
			self::doing_it_wrong( 'crashtape_register_rule', 'A rule needs a code and a callable "callback".' );
			return false;
		}

		$channels = ! empty( $args['channels'] ) && is_array( $args['channels'] ) ? array_map( 'sanitize_key', $args['channels'] ) : array();

		if ( empty( $channels ) ) {
			self::doing_it_wrong( 'crashtape_register_rule', 'A rule needs a non-empty "channels" array.' );
			return false;
		}

		self::$rules[ $code ] = array(
			'channels' => $channels,
			'callback' => $args['callback'],
		);

		return true;
	}

	/**
	 * No event should be recorded when no relevant recording/monitoring
	 * mode is active; silently does nothing outside an active session
	 * rather than erroring, since a third-party plugin calling this on
	 * every request shouldn't need to check session state itself first.
	 */
	public static function record_event( $source, $event_type, array $args = array() ) {
		if ( ! RequestContext::is_active() ) {
			return false;
		}

		$severity = isset( $args['severity'] ) ? sanitize_key( $args['severity'] ) : 'info';
		$summary  = isset( $args['summary'] ) ? sanitize_text_field( (string) $args['summary'] ) : '';
		$data     = isset( $args['data'] ) && is_array( $args['data'] ) ? $args['data'] : array();

		if ( '' === $summary ) {
			self::doing_it_wrong( 'crashtape_record_event', 'An event needs a "summary".' );
			return false;
		}

		EventWriter::instance()->add(
			'custom',
			sanitize_key( $event_type ),
			$severity,
			$summary,
			$data,
			array(
				'type' => 'plugin',
				'slug' => sanitize_key( $source ),
			)
		);

		return true;
	}

	public static function rules_for_channel( $channel ) {
		$matching = array();

		foreach ( self::$rules as $code => $rule ) {
			if ( in_array( $channel, $rule['channels'], true ) ) {
				$matching[ $code ] = $rule['callback'];
			}
		}

		return $matching;
	}

	/**
	 * Runs every registered diagnostic and returns its result; used by
	 * the admin Diagnostics section and included in support packages.
	 * Never lets one provider's failure take down the others.
	 */
	public static function run_diagnostics() {
		$results = array();

		foreach ( self::$diagnostics as $slug => $diagnostic ) {
			if ( ! current_user_can( $diagnostic['permission'] ) ) {
				continue;
			}

			$start = microtime( true );

			try {
				$result = call_user_func( $diagnostic['callback'] );
			} catch ( \Throwable $e ) {
				$result = array(
					'status'  => 'unavailable',
					'summary' => 'Diagnostic threw an exception and was skipped.',
					'data'    => array(),
				);
			}

			$elapsed = microtime( true ) - $start;

			if ( ! is_array( $result ) || empty( $result['status'] ) || ! in_array( $result['status'], self::STATUSES, true ) ) {
				$result = array(
					'status'  => 'unavailable',
					'summary' => 'Diagnostic did not return a valid result.',
					'data'    => array(),
				);
			}

			if ( $elapsed > self::TIME_BUDGET_SECONDS ) {
				$result['status']  = 'unavailable';
				$result['summary'] = 'Diagnostic exceeded its time budget (' . round( $elapsed, 2 ) . 's) and its result was discarded.';
				$result['data']    = array();
			}

			$results[ $slug ] = array(
				'label'       => $diagnostic['label'],
				'sensitivity' => $diagnostic['sensitivity'],
				'status'      => $result['status'],
				'summary'     => sanitize_text_field( (string) ( $result['summary'] ?? '' ) ),
				'data'        => Redactor::redact_array( is_array( $result['data'] ?? null ) ? $result['data'] : array() ),
				'duration_ms' => round( $elapsed * 1000, 1 ),
			);
		}

		return $results;
	}

	/**
	 * sanitize_key() strips '/', but slash-namespaced slugs
	 * ('my-plugin/connection', 'http/authentication-failure') are a
	 * documented, expected shape for registration keys; found via a real
	 * fixture plugin registering exactly that shape and getting
	 * 'sdk-testconnection' back. Same character set as sanitize_key()
	 * otherwise, with '/' additionally allowed.
	 */
	private static function sanitize_namespaced_key( $key ) {
		$key = strtolower( (string) $key );

		return preg_replace( '/[^a-z0-9_\-\/]/', '', $key );
	}

	private static function doing_it_wrong( $function_name, $message ) {
		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong( esc_html( $function_name ), esc_html( $message ), '' );
		}
	}
}
