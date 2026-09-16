<?php

namespace JMooreWV\CrashTape\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * Custom report branding; lets a professional user (an agency, a
 * freelance developer) put their own name/logo/contact note
 * on the human-readable summary.html handed to a client, instead of a bare
 * "CrashTape Report". Purely cosmetic: never affects capture, redaction,
 * or analysis.
 *
 * The logo is stored as a data: URI (not a media-library attachment ID or
 * external URL) so summary.html stays true to its own README.txt promise;
 * "no internet connection or external files required"; even once it's
 * been unzipped and handed to someone else on a different machine.
 */
class Branding {

	const ORG_NAME_OPTION = 'crashtape_report_org_name';
	const FOOTER_OPTION   = 'crashtape_report_footer_note';
	const LOGO_OPTION     = 'crashtape_report_logo';

	const MAX_ORG_NAME_LENGTH = 100;
	const MAX_FOOTER_LENGTH   = 500;

	// Kept small deliberately; this gets base64-encoded inline into every
	// exported report, not stored once and linked.
	const MAX_LOGO_BYTES = 51200;

	const ALLOWED_LOGO_MIMES = array( 'image/png', 'image/jpeg', 'image/gif' );

	public static function org_name() {
		return (string) get_option( self::ORG_NAME_OPTION, '' );
	}

	public static function footer_note() {
		return (string) get_option( self::FOOTER_OPTION, '' );
	}

	/**
	 * The full data: URI ready to drop straight into an <img src="">, or
	 * '' if no logo has been set.
	 */
	public static function logo_data_uri() {
		$logo = get_option( self::LOGO_OPTION, null );

		if ( ! is_array( $logo ) || empty( $logo['mime'] ) || empty( $logo['base64'] ) ) {
			return '';
		}

		return 'data:' . $logo['mime'] . ';base64,' . $logo['base64'];
	}

	public static function save_org_name( $name ) {
		update_option( self::ORG_NAME_OPTION, substr( sanitize_text_field( $name ), 0, self::MAX_ORG_NAME_LENGTH ) );
	}

	public static function save_footer_note( $note ) {
		update_option( self::FOOTER_OPTION, substr( sanitize_textarea_field( $note ), 0, self::MAX_FOOTER_LENGTH ) );
	}

	public static function clear_logo() {
		delete_option( self::LOGO_OPTION );
	}

	/**
	 * Validates and stores an uploaded logo from a $_FILES entry. Rejects
	 * (returns false, leaving any existing logo untouched) rather than
	 * throwing; an admin-post handler failing a file upload should not
	 * take down the whole settings save.
	 */
	public static function save_logo_from_upload( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return false;
		}

		if ( ! empty( $file['size'] ) && $file['size'] > self::MAX_LOGO_BYTES ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$info = @getimagesize( $file['tmp_name'] );

		if ( ! $info || empty( $info['mime'] ) || ! in_array( $info['mime'], self::ALLOWED_LOGO_MIMES, true ) ) {
			return false;
		}

		// A local, already-uploaded tmp file (is_uploaded_file() checked
		// above); not a remote URL, so wp_remote_get() doesn't apply here.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $file['tmp_name'] );

		if ( false === $contents || strlen( $contents ) > self::MAX_LOGO_BYTES ) {
			return false;
		}

		update_option(
			self::LOGO_OPTION,
			array(
				'mime'   => $info['mime'],
				'base64' => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			)
		);

		return true;
	}
}
