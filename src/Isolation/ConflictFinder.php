<?php

namespace JMooreWV\CrashTape\Isolation;

use JMooreWV\CrashTape\Diagnostics\CustomChecks;

defined( 'ABSPATH' ) || exit;

/**
 * Guided binary-search conflict finder, built on top of the isolation
 * Token/Loader mechanism. Each round keeps half of the remaining suspect
 * plugins active (via a fresh isolation test) and asks whether the
 * problem still happens; narrowing ~40 plugins to 1 in about six rounds.
 *
 * Automated Mode's assertions include "URL must return 200", "REST
 * endpoint must return expected status", and "API endpoint must answer
 * successfully"; it reuses an existing url_status CustomChecks definition
 * as the assertion, rather than a second URL/status input; only that check
 * type can meaningfully drive this: option_equals/constant_defined/
 * plugin_active all evaluate the *current* (admin, non-isolated) request,
 * not the isolated diagnostic browser's actual state, so only a real
 * outbound HTTP request carrying the isolation cookie reflects each
 * round's reduced plugin set. Other assertion kinds, such as "selector
 * must exist" (needs browser automation this plugin doesn't have) or a
 * "WooCommerce checkout test" (an explicit decision to avoid automating
 * real transactions), stay out of scope.
 *
 * State is a single option, not a table; this is inherently a single
 * in-progress wizard, not diagnostic data worth querying/reporting on.
 *
 * Plugin dependency headers, where available: a round's kept-active set
 * (testing_expanded, what Token::start_test() actually receives) always
 * includes the transitive closure of any declared `Requires Plugins`
 * dependency (the official WP 6.5+ mechanism) for whatever's under test
 * that round; disabling a declared dependency would risk a fatal in the
 * isolated browser that looks like a false "still happening" positive,
 * not a real isolation result. The narrowing logic in answer()
 * deliberately keeps operating on the *unexpanded* `testing` list; a
 * dependency riding along for stability was never actually a suspect,
 * and must never become one just because it had to stay active.
 */
class ConflictFinder {

	const OPTION = 'crashtape_conflict_finder';

	/**
	 * Safety cap on automated rounds; generous even for an implausibly
	 * large plugin count (2^15 candidates), and bounds worst-case runtime
	 * to 15 outbound requests rather than an unbounded loop.
	 */
	const MAX_AUTOMATED_ROUNDS = 15;

	/**
	 * Release-readiness checklist Priority 10 "corrupt isolation state":
	 * this option is never written from raw user input directly (only
	 * from this class's own state transitions), so external corruption
	 * isn't a normal attack surface, but `answer()` immediately reads
	 * `$state['status']` and `$state['candidates']` with no isset() guard
	 * of its own, so a malformed option (missing keys, e.g. from manual DB
	 * editing or a future bug elsewhere in this class) would surface as a
	 * PHP warning rather than the null this method's callers already
	 * handle cleanly. Checking the shape here, once, is cheaper and more
	 * robust than adding isset() checks at every read site downstream.
	 */
	public static function current() {
		$state = get_option( self::OPTION );

		if ( ! is_array( $state ) || ! isset( $state['status'], $state['candidates'] ) || ! is_array( $state['candidates'] ) ) {
			return null;
		}

		return $state;
	}

	/**
	 * $candidates defaults to every active plugin except CrashTape itself.
	 * Never claim this is a complete test; MU plugins, drop-ins, and the
	 * active theme are outside what plugin isolation can affect at all,
	 * so the UI must say so rather than imply completeness.
	 */
	public static function start( array $candidates = null ) {
		if ( null === $candidates ) {
			$candidates = self::default_candidates();
		}

		$candidates = array_values( array_unique( $candidates ) );

		$state = array(
			'status'     => 'active',
			'candidates' => $candidates,
			'testing'    => array(),
			'excluded'   => array(),
			'round'      => 0,
			'history'    => array(),
			'result'     => null,
			'started_at' => current_time( 'mysql', true ),
		);

		$state = self::begin_round( $state );

		update_option( self::OPTION, $state, false );

		return $state;
	}

	public static function answer( $verdict ) {
		$state = self::current();

		if ( ! $state || 'active' !== $state['status'] ) {
			return $state;
		}

		if ( 'unable' === $verdict ) {
			// Re-issue the same round's test rather than narrowing on no
			// information; a fresh Token in case the old one expired.
			Token::start_test( $state['testing_expanded'] );
			update_option( self::OPTION, $state, false );
			return $state;
		}

		$tested_half = $state['testing'];
		$other_half  = array_values( array_diff( $state['candidates'], $tested_half ) );

		if ( 'yes' === $verdict ) {
			// Still happening with only the tested half active: the
			// culprit is in that half. The other half is cleared.
			$state['candidates'] = $tested_half;
			$state['excluded']   = array_merge( $state['excluded'], $other_half );
		} else {
			// 'no': stopped happening, so the culprit isn't in the tested
			// half alone; narrow to whatever was disabled this round.
			$state['candidates'] = $other_half;
			$state['excluded']   = array_merge( $state['excluded'], $tested_half );
		}

		$state['history'][] = array(
			'round'  => $state['round'],
			'tested' => $tested_half,
			'result' => $verdict,
		);

		if ( count( $state['candidates'] ) <= 1 ) {
			$state['status'] = 'complete';
			$state['result'] = ! empty( $state['candidates'][0] ) ? $state['candidates'][0] : null;

			if ( null === $state['result'] ) {
				// 'no' answered when only one plugin was being tested;
				// the disabled half was empty, so narrowing found nothing.
				// The bug may not be a single-plugin conflict at all.
				$state['status'] = 'inconclusive';
			}

			Token::stop_test();
		} else {
			$state = self::begin_round( $state );
		}

		update_option( self::OPTION, $state, false );

		return $state;
	}

	public static function stop() {
		Token::stop_test();
		delete_option( self::OPTION );
	}

	/**
	 * Starts (if nothing is already running) and drives a finder run to
	 * completion using a url_status custom check as the assertion, instead
	 * of requiring a human "Yes/No/Unable" click every round. Stops early,
	 * leaving the finder active for the admin to continue manually, the
	 * moment a probe can't get a real answer (request failure) rather
	 * than guessing, and always after MAX_AUTOMATED_ROUNDS regardless.
	 */
	public static function run_automated( $check_id, array $candidates = null ) {
		$check = CustomChecks::find( $check_id );

		if ( ! $check || CustomChecks::TYPE_URL_STATUS !== $check['type'] ) {
			return new \WP_Error( 'crashtape_invalid_automated_check', __( 'Automated Mode needs an existing URL-status custom check.', 'crashtape' ) );
		}

		$state = self::current();

		if ( ! $state || 'active' !== $state['status'] ) {
			$state = self::start( $candidates );
		}

		$rounds_run = 0;

		while ( 'active' === $state['status'] && $rounds_run < self::MAX_AUTOMATED_ROUNDS ) {
			$verdict = self::probe( $check['config'] );

			if ( null === $verdict ) {
				break;
			}

			$state = self::answer( $verdict );
			++$rounds_run;
		}

		return $state;
	}

	/**
	 * Fires a real outbound request carrying the *current* round's
	 * isolation cookie (set on $_COOKIE by Token::start_test() inside
	 * begin_round(), which just ran as part of start()/answer()) at the
	 * check's configured URL. The assertion passing means the site is
	 * healthy with only this round's subset active; the problem is
	 * resolved, i.e. "no". Failing means the problem persists; "yes".
	 * Returns null (not a guess) if the request itself couldn't complete.
	 */
	private static function probe( array $check_config ) {
		if ( empty( $_COOKIE[ Token::COOKIE ] ) ) {
			return null;
		}

		$response = wp_remote_get(
			$check_config['url'],
			array(
				'timeout' => 10,
				'cookies' => array( Token::COOKIE => wp_unslash( $_COOKIE[ Token::COOKIE ] ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$pass = in_array( $code, $check_config['expected_status'], true );

		return $pass ? 'no' : 'yes';
	}

	private static function begin_round( array $state ) {
		++$state['round'];

		$candidates = $state['candidates'];
		$half       = (int) ceil( count( $candidates ) / 2 );

		$tested_half = array_slice( $candidates, 0, $half );
		$expanded    = self::expand_with_dependencies( $tested_half );

		$state['testing']               = $tested_half;
		$state['testing_expanded']      = $expanded;
		$state['dependencies_included'] = array_values( array_diff( $expanded, $tested_half ) );

		Token::start_test( $expanded );

		return $state;
	}

	/**
	 * Transitive closure of every declared `Requires Plugins` dependency
	 * (WP 6.5+'s official header for plugin dependency headers, where
	 * available) for the plugins in $keep. Resolved against
	 * the real, currently-active plugin list, not the finder's own
	 * candidates/excluded state; a dependency must stay active because
	 * it's genuinely required, regardless of whether it was narrowed out
	 * as a suspect in an earlier round.
	 */
	private static function expand_with_dependencies( array $keep ) {
		$active           = (array) get_option( 'active_plugins', array() );
		$slug_to_basename = array();

		foreach ( $active as $basename ) {
			$slug_to_basename[ strtok( $basename, '/' ) ] = $basename;
		}

		$expanded = $keep;
		$changed  = true;

		while ( $changed ) {
			$changed = false;

			foreach ( $expanded as $basename ) {
				foreach ( self::required_slugs( $basename ) as $required_slug ) {
					if ( isset( $slug_to_basename[ $required_slug ] ) && ! in_array( $slug_to_basename[ $required_slug ], $expanded, true ) ) {
						$expanded[] = $slug_to_basename[ $required_slug ];
						$changed    = true;
					}
				}
			}
		}

		return array_values( $expanded );
	}

	private static function required_slugs( $basename ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$path = WP_PLUGIN_DIR . '/' . $basename;

		if ( ! is_readable( $path ) ) {
			return array();
		}

		$data = get_plugin_data( $path, false, false );

		if ( empty( $data['RequiresPlugins'] ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $data['RequiresPlugins'] ) ) ) );
	}

	private static function default_candidates() {
		$own = plugin_basename( CRASHTAPE_FILE );

		return array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( $own ) ) );
	}
}
