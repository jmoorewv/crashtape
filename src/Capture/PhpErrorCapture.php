<?php

namespace JMooreWV\CrashTape\Capture;

use JMooreWV\CrashTape\Components\Resolver;
use JMooreWV\CrashTape\EventWriter;
use JMooreWV\CrashTape\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Chains a runtime error handler and a shutdown fatal check during an
 * active diagnostic request only. Never suppresses PHP's own behavior,
 * never converts warnings into exceptions, and always chains to any
 * previously installed handler.
 */
class PhpErrorCapture {

	const CAPTURED_LEVELS = array(
		E_WARNING         => 'warning',
		E_NOTICE          => 'notice',
		E_USER_WARNING    => 'warning',
		E_USER_NOTICE     => 'notice',
		E_DEPRECATED      => 'notice',
		E_USER_DEPRECATED => 'notice',
	);

	const FATAL_TYPES = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

	/** @var callable|null */
	private $previous_handler;

	/** @var int Numeric requests-table ID for this request. */
	private $request_id;

	public function __construct( $request_id ) {
		$this->request_id = $request_id;
	}

	public function register() {
		$this->previous_handler = set_error_handler( array( $this, 'handle_error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}

	public function handle_error( $errno, $errstr, $errfile, $errline ) {
		if ( isset( self::CAPTURED_LEVELS[ $errno ] ) ) {
			try {
				$this->record( $errstr, $errfile, $errline, self::CAPTURED_LEVELS[ $errno ], 'php_error' );
			} catch ( \Throwable $e ) {
				// Never let capture itself break the request.
			}
		}

		if ( $this->previous_handler ) {
			return (bool) call_user_func( $this->previous_handler, $errno, $errstr, $errfile, $errline );
		}

		// Returning false preserves PHP's normal internal error handling
		// (e.g. logging to error_log if configured).
		return false;
	}

	public function handle_shutdown() {
		try {
			$error = error_get_last();

			if ( $error && in_array( $error['type'], self::FATAL_TYPES, true ) ) {
				$this->record( $error['message'], $error['file'], $error['line'], 'critical', 'php_fatal' );
			}
		} catch ( \Throwable $e ) {
			// Never let capture itself break shutdown.
		}
	}

	private function record( $message, $file, $line, $severity, $event_type ) {
		$component = Resolver::resolve_path( $file );
		// Fall back to just the basename rather than ever exposing the full
		// absolute path if it didn't resolve under a known WordPress root.
		$file_display = '' !== $component['file'] ? $component['file'] : basename( (string) $file );

		EventWriter::instance()->add(
			'php',
			$event_type,
			$severity,
			$message . ' in ' . $file_display . ':' . $line,
			array(
				'file' => $file_display,
				'line' => $line,
			),
			$component
		);

		if ( in_array( $component['type'], Resolver::ATTRIBUTABLE_TYPES, true ) ) {
			Request::attribute( $this->request_id, $component['slug'], $severity );
		}
	}
}
