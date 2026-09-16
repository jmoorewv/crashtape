<?php

namespace JMooreWV\CrashTape\Capture;

use JMooreWV\CrashTape\EventWriter;
use JMooreWV\CrashTape\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Observes Contact Form 7 submission outcomes. Feature-detected; only
 * registers when CF7 is active. Hooks the real, stable `wpcf7_submit`
 * action every submission passes through (confirmed against the real
 * 6.1.7 source; includes/contact-form.php), carrying the exact status
 * values CF7's own submission handler sets (includes/submission.php):
 * validation_failed, acceptance_missing, spam, aborted, mail_sent,
 * mail_failed.
 *
 * Deliberately narrow: form field values are never stored by default;
 * only the outcome status and form ID/title are captured, never any
 * submitted field value.
 */
class ContactForm7Capture {

	/**
	 * Every real status CF7_Submission::set_status() can produce except
	 * mail_sent (success; not diagnostically interesting, same restraint
	 * WooCommerceCapture already applies to successful orders).
	 */
	const INTERESTING_STATUSES = array( 'validation_failed', 'acceptance_missing', 'spam', 'aborted', 'mail_failed' );

	/** @var int Numeric requests-table ID for this request. */
	private $request_id;

	public function __construct( $request_id ) {
		$this->request_id = $request_id;
	}

	public function register() {
		add_action( 'wpcf7_submit', array( $this, 'handle_submit' ), 10, 2 );
	}

	public function handle_submit( $contact_form, $result ) {
		try {
			$status = isset( $result['status'] ) ? (string) $result['status'] : '';

			if ( ! in_array( $status, self::INTERESTING_STATUSES, true ) ) {
				return;
			}

			$severity = 'mail_failed' === $status ? 'error' : 'warning';
			$title    = method_exists( $contact_form, 'title' ) ? $contact_form->title() : '';

			EventWriter::instance()->add(
				'forms',
				'cf7_' . $status,
				$severity,
				sprintf(
					'Contact Form 7 submission %s%s',
					str_replace( '_', ' ', $status ),
					$title ? " ({$title})" : ''
				),
				array( 'form_id' => method_exists( $contact_form, 'id' ) ? $contact_form->id() : null ),
				array(
					'type' => 'plugin',
					'slug' => 'contact-form-7',
				)
			);

			Request::attribute( $this->request_id, 'contact-form-7', $severity );
		} catch ( \Throwable $e ) {
			// Never let capture itself break a real form submission.
		}
	}
}
