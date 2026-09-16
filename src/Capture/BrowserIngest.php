<?php

namespace JMooreWV\CrashTape\Capture;

use JMooreWV\CrashTape\Components\Resolver;
use JMooreWV\CrashTape\EventWriter;
use JMooreWV\CrashTape\Request;
use JMooreWV\CrashTape\RequestContext;
use JMooreWV\CrashTape\Support\InternalLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * REST ingestion endpoint for the browser recorder. Authorization is the
 * signed diagnostic cookie itself; no WordPress capability is required,
 * since visitor-mode recording must work for logged-out browsers too.
 */
class BrowserIngest {

	const ROUTE_NAMESPACE = 'crashtape/v1';
	const ROUTE           = '/events';
	const MAX_EVENTS      = 25;

	const ALLOWED_EVENT_TYPES = array( 'js_error', 'unhandled_rejection', 'fetch_failed', 'fetch_error', 'xhr_failed', 'xhr_error' );
	const ALLOWED_SEVERITIES  = array( 'warning', 'error' );

	/**
	 * Rate limiting where applicable. Normal recorder behavior flushes at
	 * most every ~4s (recorder.js), so 60 requests/min per session gives
	 * generous headroom over that while still bounding a runaway/abusive
	 * client.
	 */
	const RATE_LIMIT_MAX    = 60;
	const RATE_LIMIT_WINDOW = 60;

	public function register_routes() {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'events' => array(
						'required' => true,
						'type'     => 'array',
					),
				),
			)
		);
	}

	public function handle( WP_REST_Request $request ) {
		try {
			return $this->handle_inner( $request );
		} catch ( \Throwable $e ) {
			// This REST route is the one capture entry point directly
			// reachable by an untrusted client; an uncaught exception here
			// must never surface raw to the browser, so this catches
			// anything handle_inner()'s own per-event guard below doesn't.
			return new WP_Error( 'crashtape_capture_failed', 'Could not process the submitted events.', array( 'status' => 500 ) );
		}
	}

	private function handle_inner( WP_REST_Request $request ) {
		// Bootstrap::maybe_start_capture already validated the cookie and
		// created this request's row during the earlier 'init' hook; if it's
		// not active here, there is no active session for this browser.
		if ( ! RequestContext::is_active() ) {
			return new WP_Error( 'crashtape_no_session', 'No active diagnostic session.', array( 'status' => 403 ) );
		}

		if ( self::rate_limited( RequestContext::session_id() ) ) {
			return new WP_Error( 'crashtape_rate_limited', 'Too many requests.', array( 'status' => 429 ) );
		}

		$events = $request->get_param( 'events' );

		if ( ! is_array( $events ) ) {
			return new WP_Error( 'crashtape_invalid_payload', 'Events must be an array.', array( 'status' => 400 ) );
		}

		$events   = array_slice( $events, 0, self::MAX_EVENTS );
		$recorded = 0;
		$writer   = EventWriter::instance();

		foreach ( $events as $event ) {
			try {
				if ( ! is_array( $event ) ) {
					continue;
				}

				$event_type = isset( $event['event_type'] ) ? sanitize_key( $event['event_type'] ) : '';
				$severity   = isset( $event['severity'] ) ? sanitize_key( $event['severity'] ) : 'error';
				$summary    = isset( $event['summary'] ) ? sanitize_text_field( (string) $event['summary'] ) : '';

				if ( ! in_array( $event_type, self::ALLOWED_EVENT_TYPES, true ) ) {
					continue;
				}

				if ( ! in_array( $severity, self::ALLOWED_SEVERITIES, true ) ) {
					$severity = 'error';
				}

				if ( '' === $summary ) {
					continue;
				}

				$data = array();

				if ( isset( $event['data'] ) && is_array( $event['data'] ) ) {
					foreach ( array( 'source', 'line', 'col', 'status', 'duration_ms' ) as $key ) {
						if ( isset( $event['data'][ $key ] ) && ( is_scalar( $event['data'][ $key ] ) ) ) {
							$data[ $key ] = $event['data'][ $key ];
						}
					}
				}

				$component = $this->attribute_source( $data );

				$writer->add( 'browser', $event_type, $severity, $summary, $data, $component );

				if ( $component && in_array( $component['type'], Resolver::ATTRIBUTABLE_TYPES, true ) ) {
					Request::attribute( RequestContext::request_id(), $component['slug'], $severity );
				}

				++$recorded;
			} catch ( \Throwable $e ) {
				// One malformed event must never take down the rest of the
				// batch, or surface a raw exception to the browser; every
				// other capture channel in this codebase applies this same
				// guard; this REST handler is the one entry point that's
				// directly reachable by an untrusted client, making it the
				// most important place for it to be applied correctly.
				continue;
			}
		}

		// No manual flush needed; Bootstrap's shutdown handler for this same
		// request will flush the buffer under this request's own row.

		$dropped = count( $events ) - $recorded;

		if ( $dropped > 0 ) {
			// Ingestion validation failure counts are logged specifically
			// as a *count*, not one log entry per malformed event, which
			// is exactly what the per-event catch above would invite if
			// it logged directly.
			InternalLog::record( InternalLog::INGESTION_VALIDATION_FAILURE, "{$dropped} of " . count( $events ) . ' submitted browser event(s) failed validation and were dropped.' );
		}

		return new WP_REST_Response( array( 'recorded' => $recorded ), 202 );
	}

	private static function rate_limited( $session_id ) {
		$key   = 'crashtape_rl_' . $session_id;
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return true;
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );

		return false;
	}

	/**
	 * A JS error's 'source' is the erroring script's URL, which shares the
	 * same wp-content/... path shape as a filesystem path once the scheme
	 * and host are stripped, letting it map to a component the same way
	 * an asset URL does.
	 */
	private function attribute_source( array $data ) {
		if ( empty( $data['source'] ) || ! is_string( $data['source'] ) ) {
			return null;
		}

		$path = wp_parse_url( $data['source'], PHP_URL_PATH );

		return $path ? Resolver::resolve_path( $path ) : null;
	}
}
