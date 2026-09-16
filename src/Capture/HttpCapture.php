<?php

namespace JMooreWV\CrashTape\Capture;

use JMooreWV\CrashTape\Components\Resolver;
use JMooreWV\CrashTape\EventWriter;
use JMooreWV\CrashTape\Redactor;
use JMooreWV\CrashTape\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Observes WordPress's outbound HTTP API via http_api_debug. Never
 * captures Authorization/Cookie headers or request/response bodies.
 */
class HttpCapture {

	/**
	 * Only Authorization/Cookie are forbidden; these are safe,
	 * diagnostically useful response headers (e.g. Retry-After on a 429,
	 * WWW-Authenticate on a 401) worth capturing on a failed request.
	 */
	const SAFE_RESPONSE_HEADERS = array( 'content-type', 'retry-after', 'www-authenticate', 'x-ratelimit-limit', 'x-ratelimit-remaining' );

	/** @var int Numeric requests-table ID for this request. */
	private $request_id;

	public function __construct( $request_id ) {
		$this->request_id = $request_id;
	}

	public function register() {
		add_action( 'http_api_debug', array( $this, 'handle' ), 10, 5 );
	}

	public function handle( $response, $context, $transport_class, $args, $url ) {
		try {
			if ( 'response' !== $context ) {
				return;
			}

			$host      = wp_parse_url( $url, PHP_URL_HOST );
			$safe_url  = Redactor::redact_url( $url );
			$component = Resolver::resolve_caller( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, Resolver::BACKTRACE_LIMIT ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions

			if ( is_wp_error( $response ) ) {
				EventWriter::instance()->add(
					'http',
					'outbound_error',
					'error',
					sprintf( 'Outbound request to %s failed: %s', $host, $response->get_error_message() ),
					array(
						'url'        => $safe_url,
						'error_code' => $response->get_error_code(),
						'method'     => isset( $args['method'] ) ? $args['method'] : '',
					),
					$component
				);

				$this->maybe_attribute( $component, 'error' );
				return;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( $code >= 400 ) {
				$severity = $code >= 500 ? 'error' : 'warning';

				EventWriter::instance()->add(
					'http',
					'outbound_response',
					$severity,
					sprintf( 'Outbound request to %s returned HTTP %d', $host, $code ),
					array(
						'url'     => $safe_url,
						'status'  => $code,
						'method'  => isset( $args['method'] ) ? $args['method'] : '',
						'headers' => $this->safe_response_headers( $response ),
					),
					$component
				);

				$this->maybe_attribute( $component, $severity );
			}
		} catch ( \Throwable $e ) {
			// Never let capture itself break the request.
		}
	}

	private function safe_response_headers( $response ) {
		$headers = array();

		foreach ( self::SAFE_RESPONSE_HEADERS as $header_name ) {
			$value = wp_remote_retrieve_header( $response, $header_name );

			if ( '' !== $value && ! is_array( $value ) ) {
				$headers[ $header_name ] = $value;
			}
		}

		return Redactor::redact_headers( $headers );
	}

	private function maybe_attribute( ?array $component, $severity ) {
		if ( $component && in_array( $component['type'], Resolver::ATTRIBUTABLE_TYPES, true ) ) {
			Request::attribute( $this->request_id, $component['slug'], $severity );
		}
	}
}
