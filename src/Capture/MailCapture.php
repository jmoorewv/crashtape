<?php

namespace JMooreWV\CrashTape\Capture;

use JMooreWV\CrashTape\Components\Resolver;
use JMooreWV\CrashTape\EventWriter;
use JMooreWV\CrashTape\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Observes WordPress mail hooks. Never captures message bodies or full
 * subjects; only a subject fingerprint, recipient count, and recipient
 * domains. Attribution walks the call stack the same way
 * HttpCapture does, since a failed wp_mail() call is almost always
 * triggered by a specific plugin (a form plugin, a notification hook, ...).
 */
class MailCapture {

	/** @var int Numeric requests-table ID for this request. */
	private $request_id;

	public function __construct( $request_id ) {
		$this->request_id = $request_id;
	}

	public function register() {
		add_action( 'wp_mail_failed', array( $this, 'handle_failed' ) );

		// wp_mail_succeeded was added in WP 6.4; registering unconditionally
		// is harmless on older cores; it simply never fires.
		add_action( 'wp_mail_succeeded', array( $this, 'handle_succeeded' ) );
	}

	public function handle_failed( $wp_error ) {
		try {
			if ( ! is_wp_error( $wp_error ) ) {
				return;
			}

			$data      = $wp_error->get_error_data();
			$data      = is_array( $data ) ? $data : array();
			$component = $this->attribute_caller();

			EventWriter::instance()->add(
				'mail',
				'mail_failed',
				'error',
				sprintf( 'WordPress mail failed: %s', $wp_error->get_error_message() ),
				array(
					'error_code'          => $wp_error->get_error_code(),
					'recipient_count'     => self::recipient_count( $data ),
					'recipient_domains'   => self::recipient_domains( $data ),
					'subject_fingerprint' => self::subject_fingerprint( $data ),
					'attachment_count'    => ! empty( $data['attachments'] ) && is_array( $data['attachments'] ) ? count( $data['attachments'] ) : 0,
				),
				$component
			);

			if ( $component && in_array( $component['type'], Resolver::ATTRIBUTABLE_TYPES, true ) ) {
				Request::attribute( $this->request_id, $component['slug'], 'error' );
			}
		} catch ( \Throwable $e ) {
			// Never let capture itself break mail sending.
		}
	}

	public function handle_succeeded( $mail_data ) {
		try {
			$data = is_array( $mail_data ) ? $mail_data : array();

			EventWriter::instance()->add(
				'mail',
				'mail_accepted',
				'info',
				'WordPress reported that the mailer accepted the message. This does not prove final delivery to the recipient.',
				array(
					'recipient_count'     => self::recipient_count( $data ),
					'recipient_domains'   => self::recipient_domains( $data ),
					'subject_fingerprint' => self::subject_fingerprint( $data ),
				),
				$this->attribute_caller()
			);
		} catch ( \Throwable $e ) {
			// Never let capture itself break mail sending.
		}
	}

	private static function recipient_count( array $data ) {
		return count( self::normalize_recipients( isset( $data['to'] ) ? $data['to'] : array() ) );
	}

	private static function recipient_domains( array $data ) {
		$domains = array();

		foreach ( self::normalize_recipients( isset( $data['to'] ) ? $data['to'] : array() ) as $address ) {
			if ( preg_match( '/@([^\s>,]+)/', $address, $m ) ) {
				$domains[ strtolower( $m[1] ) ] = true;
			}
		}

		return array_keys( $domains );
	}

	private static function normalize_recipients( $to ) {
		if ( is_array( $to ) ) {
			return $to;
		}

		return array_filter( array_map( 'trim', explode( ',', (string) $to ) ) );
	}

	private static function subject_fingerprint( array $data ) {
		if ( empty( $data['subject'] ) ) {
			return null;
		}

		return substr( hash( 'sha256', (string) $data['subject'] ), 0, 16 );
	}

	private function attribute_caller() {
		return Resolver::resolve_caller( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, Resolver::BACKTRACE_LIMIT ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}
