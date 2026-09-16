<?php
/**
 * Uninstall handler. WordPress only runs this file when a user explicitly
 * deletes the plugin; never on ordinary deactivation. Session retention
 * only governs automatic cleanup of individual sessions while the plugin
 * stays installed; deleting
 * the plugin is the one place a full, irreversible cleanup belongs, gated
 * by the user's own "keep my diagnostic data" preference (Settings tab);
 * see Support\Uninstaller, which owns the actual per-site cleanup logic and
 * the setting that gates it.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/crashtape.php';

use JMooreWV\CrashTape\Support\Uninstaller;

Uninstaller::run();
