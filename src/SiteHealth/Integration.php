<?php

namespace JMooreWV\CrashTape\SiteHealth;

use JMooreWV\CrashTape\Session;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a small CrashTape section to Tools → Site Health → Info via the
 * supported debug_information filter. Deliberately just a handful of
 * status fields; the CrashTape dashboard remains the primary interface,
 * not a dump of stored events.
 */
class Integration {

	public function register() {
		add_filter( 'debug_information', array( $this, 'add_section' ) );
	}

	public function add_section( $info ) {
		$active = Session::get_active();
		$last   = Session::get_last();

		$info['crashtape'] = array(
			'label'  => __( 'CrashTape', 'crashtape' ),
			'fields' => array(
				'version'          => array(
					'label' => __( 'CrashTape version', 'crashtape' ),
					'value' => CRASHTAPE_VERSION,
				),
				'recording'        => array(
					'label' => __( 'Recording status', 'crashtape' ),
					'value' => $active ? __( 'Active', 'crashtape' ) : __( 'Idle', 'crashtape' ),
				),
				'stored_sessions'  => array(
					'label' => __( 'Stored sessions', 'crashtape' ),
					'value' => Session::count_all(),
				),
				'last_session'     => array(
					'label' => __( 'Last diagnostic session', 'crashtape' ),
					'value' => $last ? get_date_from_gmt( $last['started_at'] ) : __( 'None yet', 'crashtape' ),
				),
				'change_journal'   => array(
					'label' => __( 'Change journal status', 'crashtape' ),
					'value' => __( 'Active', 'crashtape' ),
				),
				'browser_recorder' => array(
					'label' => __( 'Browser recorder status', 'crashtape' ),
					'value' => $active ? __( 'Enqueued for the active session', 'crashtape' ) : __( 'Not loaded (no active session)', 'crashtape' ),
				),
			),
		);

		return $info;
	}
}
