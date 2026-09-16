<?php
/**
 * CrashTape Diagnostics SDK. Plain global functions, not namespaced
 * calls; third-party plugins are the audience, calling plain functions
 * rather than namespaced ones. Loaded unconditionally from
 * crashtape.php's top level (not inside any hook) so these are defined
 * as early as any other plugin's own bootstrap could run.
 *
 * Call these from your own plugin's `plugins_loaded` or `init` hook, not
 * from top-level file scope; CrashTape may not have loaded yet at that
 * point depending on plugin load order.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'crashtape_register_diagnostic' ) ) {
	/**
	 * Registers a self-check other code can query at any time; e.g. "is
	 * my API connection healthy right now." Not tied to
	 * an active diagnostic session; runs on demand from the admin
	 * Diagnostics section and when a support package is generated.
	 *
	 * @param string $slug Unique identifier, e.g. 'my-plugin/connection'.
	 * @param array  $args {
	 *     @type string   $label       Required. Human-readable name.
	 *     @type string   $sensitivity Required. 'low', 'medium', or 'high'.
	 *     @type callable $callback    Required. Returns an array:
	 *         'status'  => 'good'|'info'|'warning'|'error'|'unavailable',
	 *         'summary' => a plain-language sentence (never a secret),
	 *         'data'    => optional array of additional safe detail.
	 *     @type string   $permission  Capability required to view the
	 *         result. Default 'manage_options'.
	 * }
	 * @return bool Whether registration succeeded.
	 */
	function crashtape_register_diagnostic( $slug, array $args ) {
		return \JMooreWV\CrashTape\Sdk\Registry::register_diagnostic( $slug, $args );
	}
}

if ( ! function_exists( 'crashtape_register_rule' ) ) {
	/**
	 * Adds a rule to CrashTape's analysis engine. $args['channels']
	 * lists which event channels this rule should be tried against.
	 * $args['callback'] receives ($event, $data); the raw event row and its
	 * decoded data array; and returns either null (no match) or an array:
	 * 'classification', 'title', 'explanation', 'suggested_checks' (array),
	 * 'base_points' (int, contribution to confidence scoring).
	 *
	 * @return bool Whether registration succeeded.
	 */
	function crashtape_register_rule( $code, array $args ) {
		return \JMooreWV\CrashTape\Sdk\Registry::register_rule( $code, $args );
	}
}

if ( ! function_exists( 'crashtape_record_event' ) ) {
	/**
	 * Records a custom event into the active diagnostic session, if one is
	 * active for the current request; does nothing otherwise, so it's
	 * safe to call unconditionally on every request.
	 *
	 * @param string $source     Your plugin's slug, used for attribution.
	 * @param string $event_type A short machine identifier, e.g. 'sync_failed'.
	 * @param array  $args {
	 *     @type string $severity 'info'|'notice'|'warning'|'error'|'critical'. Default 'info'.
	 *     @type string $summary  Required. Plain-language sentence; never a secret.
	 *     @type array  $data     Optional additional safe detail (redacted like every
	 *                            other CrashTape event before it's stored).
	 * }
	 * @return bool Whether the event was recorded.
	 */
	function crashtape_record_event( $source, $event_type, array $args = array() ) {
		return \JMooreWV\CrashTape\Sdk\Registry::record_event( $source, $event_type, $args );
	}
}
