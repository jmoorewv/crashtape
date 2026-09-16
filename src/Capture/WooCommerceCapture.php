<?php

namespace JMooreWV\CrashTape\Capture;

use JMooreWV\CrashTape\Components\Resolver;
use JMooreWV\CrashTape\EventWriter;
use JMooreWV\CrashTape\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Observes WooCommerce order failures. Feature-detected; only registers
 * when WooCommerce is active. Hooks the real, stable
 * `woocommerce_order_status_failed` action WooCommerce itself dispatches
 * from its own status-transition machinery (includes/class-wc-order.php),
 * not an internal/undocumented API.
 *
 * Deliberately narrow: captures only what's needed to point a developer at
 * the right order and gateway, never card data, full order personal
 * information, or checkout field contents; no customer name/address/email,
 * no order total, no line items.
 */
class WooCommerceCapture {

	/** @var int Numeric requests-table ID for this request. */
	private $request_id;

	public function __construct( $request_id ) {
		$this->request_id = $request_id;
	}

	public function register() {
		add_action( 'woocommerce_order_status_failed', array( $this, 'handle_order_failed' ), 10, 2 );
	}

	public function handle_order_failed( $order_id, $order ) {
		try {
			if ( ! is_a( $order, 'WC_Order' ) ) {
				return;
			}

			$component = Resolver::resolve_caller( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, Resolver::BACKTRACE_LIMIT ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions

			EventWriter::instance()->add(
				'woocommerce',
				'order_failed',
				'error',
				sprintf( 'WooCommerce order #%d transitioned to Failed.', $order_id ),
				array(
					'order_id'             => (int) $order_id,
					'payment_method'       => $order->get_payment_method(),
					'payment_method_title' => $order->get_payment_method_title(),
				),
				$component
			);

			if ( $component && in_array( $component['type'], Resolver::ATTRIBUTABLE_TYPES, true ) ) {
				Request::attribute( $this->request_id, $component['slug'], 'error' );
			}
		} catch ( \Throwable $e ) {
			// Never let capture itself break order processing.
		}
	}
}
