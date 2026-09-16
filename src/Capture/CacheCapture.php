<?php

namespace JMooreWV\CrashTape\Capture;

use JMooreWV\CrashTape\EventWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Observes page-cache purge events during a session. Each cache plugin is
 * feature-detected individually and independently, so any subset
 * (including all four at once, a real combination verified live during
 * release-readiness testing) gets exactly the events its own purge hooks
 * actually fire, never a guess at what an absent plugin might do:
 *
 * - WP Super Cache: `wp_cache_clear_cache()` (confirmed against the real
 *   3.1.3 source, wp-cache-phase2.php) gates the real `wp_cache_cleared`
 *   action, fired from every real code path that actually clears the
 *   cache.
 * - LiteSpeed Cache: the `LSCWP_V` constant (confirmed against the real
 *   7.9.1 source, litespeed-cache.php) gates the real
 *   `litespeed_purged_all` action (src/purge.cls.php).
 * - W3 Total Cache: the real `w3tc_flush_all()` function (confirmed
 *   against the real 2.10.6 source, w3-total-cache-api.php) gates the
 *   action of the same name it dispatches.
 * - WP Rocket: the `WP_ROCKET_VERSION` constant (confirmed against the
 *   real 3.23.3.3 source, wp-rocket.php) gates the real
 *   `rocket_after_clean_domain` action, fired at the end of
 *   `rocket_clean_domain()` (inc/functions/files.php); the function
 *   every real "clear entire cache" code path (settings-screen button,
 *   WP-CLI, auto-purge-on-change) ultimately calls.
 *
 * A real cache purge mid-session ("the site broke right after someone
 * cleared the cache") is valuable correlation evidence for the timeline,
 * same class of signal as the change journal.
 *
 * The four add_action() calls below are unconditional, not individually
 * gated; hooking an action name that a given site's cache plugin never
 * actually fires is harmless (it simply never runs), and keeping this
 * class itself free of any real-plugin dependency is what lets it be
 * exercised directly in an isolated test environment that has none of
 * the four installed, the same way it already worked before this class
 * supported more than one. Bootstrap's own registration of this class is
 * still gated on at least one of the four being present, so a site with
 * none of them never pays even this negligible cost.
 */
class CacheCapture {

	public function register() {
		add_action( 'wp_cache_cleared', array( $this, 'handle_cache_cleared' ) );
		add_action( 'litespeed_purged_all', array( $this, 'handle_cache_cleared' ) );
		add_action( 'w3tc_flush_all', array( $this, 'handle_cache_cleared' ) );
		add_action( 'rocket_after_clean_domain', array( $this, 'handle_cache_cleared' ) );
	}

	public function handle_cache_cleared() {
		try {
			EventWriter::instance()->add( 'cache', 'cache_cleared', 'info', 'The page cache was cleared during this session.' );
		} catch ( \Throwable $e ) {
			// Never let capture break a real cache-clear operation.
		}
	}
}
