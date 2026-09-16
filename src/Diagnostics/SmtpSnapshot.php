<?php

namespace JMooreWV\CrashTape\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Mail-transport environment facts, exposing transport state without
 * exposing passwords or credentials, captured at session start alongside
 * the environment/cron/WooCommerce snapshot. Feature-detected against WP
 * Mail SMTP (confirmed against the real 4.9.0 source: src/Options.php's
 * $map, src/Providers/SMTP/Options.php), the only mailer plugin adapter
 * this class targets so far.
 *
 * Only the 'mail' and 'smtp' option groups are ever read, and only an
 * explicit allowlist of keys from each: mailer/from_email domain/host/
 * port/encryption/auth. WP Mail SMTP's own $map (Options.php) also stores
 * 'user'/'pass' under 'smtp' and per-provider API keys/client secrets
 * under groups like 'gmail'/'mailgun'; none of those groups or keys are
 * ever touched here, by construction (this class never calls get_group()
 * or get_all(), only get() with a fixed key literal per call).
 */
class SmtpSnapshot {

	public static function capture() {
		if ( ! function_exists( 'wp_mail_smtp' ) || ! class_exists( '\WPMailSMTP\Options' ) ) {
			return array( 'smtp_plugin' => array( 'detected' => false ) );
		}

		try {
			$options = \WPMailSMTP\Options::init();
			$mailer  = $options->get( 'mail', 'mailer' );

			$data = array(
				'detected' => true,
				'mailer'   => $mailer ? $mailer : null,
			);

			// Host/port/encryption/auth only exist for the 'smtp' (Other
			// SMTP) mailer; API-based mailers (Mailgun, SendGrid, etc.)
			// have no such fields, and this class doesn't read their
			// groups at all.
			if ( 'smtp' === $mailer ) {
				$data['host']       = $options->get( 'smtp', 'host' );
				$data['port']       = $options->get( 'smtp', 'port' );
				$data['encryption'] = $options->get( 'smtp', 'encryption' );
				$data['auth']       = (bool) $options->get( 'smtp', 'auth' );
			}

			return array( 'smtp_plugin' => $data );
		} catch ( \Throwable $e ) {
			return array(
				'smtp_plugin' => array(
					'detected' => true,
					'error'    => 'snapshot_failed',
				),
			);
		}
	}
}
