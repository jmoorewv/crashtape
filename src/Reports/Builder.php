<?php

namespace JMooreWV\CrashTape\Reports;

use JMooreWV\CrashTape\Changes\Journal;
use JMooreWV\CrashTape\Database;
use JMooreWV\CrashTape\Redactor;
use JMooreWV\CrashTape\Sdk\Registry;
use JMooreWV\CrashTape\Support\InternalLog;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a support ZIP package for a completed session. The per-channel
 * files (errors.json, http.json, browser.json, mail.json, rest.json,
 * scheduled-tasks.json, plus woocommerce.json/custom-events.json for the
 * two channels added later) are filtered views of the same data already
 * in timeline.jsonl; a convenience for someone who only wants one
 * channel's evidence, not a second copy of information that isn't
 * already captured elsewhere.
 */
class Builder {

	public static function build( array $session ) {
		// Release-readiness checklist Priority 7 "Missing ZipArchive"; a
		// real gap: `new ZipArchive()` below with the extension absent is
		// an uncaught fatal `Error`, not the clean WP_Error every other
		// failure path in this method returns. SelfTest already checks
		// class_exists('ZipArchive') for exactly this reason; this class
		// needs the same awareness at the point it would actually matter,
		// not just in a diagnostic display.
		if ( ! class_exists( 'ZipArchive' ) ) {
			InternalLog::record( InternalLog::EXPORT_FAILURE, 'ZipArchive extension is not available.' );

			return new \WP_Error( 'crashtape_zip_unavailable', 'The ZipArchive PHP extension is required to build a support package, and is not available on this server.' );
		}

		$events  = self::fetch_events( $session['id'] );
		$changes = Journal::changes_before( $session, 24 );

		$files = array();

		$files['README.txt']            = self::readme();
		$files['manifest.json']         = null; // filled in after other files are known, for checksums.
		$files['session.json']          = self::session_json( $session );
		$files['environment.json']      = self::environment_json( $session );
		$files['timeline.jsonl']        = self::timeline_jsonl( $events );
		$files['requests.json']         = self::requests_json( $session['id'] );
		$files['changes.json']          = self::changes_json( $changes );
		$files['redaction-report.json'] = self::redaction_report_json( $events, $changes );
		$files['diagnostics.json']      = wp_json_encode( Registry::run_diagnostics(), JSON_PRETTY_PRINT );

		$files['errors.json']          = self::channel_json( $events, 'php' );
		$files['http.json']            = self::channel_json( $events, 'http' );
		$files['browser.json']         = self::channel_json( $events, 'browser' );
		$files['mail.json']            = self::channel_json( $events, 'mail' );
		$files['woocommerce.json']     = self::channel_json( $events, 'woocommerce' );
		$files['forms.json']           = self::channel_json( $events, 'forms' );
		$files['cache.json']           = self::channel_json( $events, 'cache' );
		$files['custom-events.json']   = self::channel_json( $events, 'custom' );
		$files['rest.json']            = self::rest_json( $session['id'] );
		$files['scheduled-tasks.json'] = self::scheduled_tasks_json( $session );

		$files['summary.html'] = self::summary_html( $session, $events, $changes );

		$checksums = array();

		foreach ( $files as $name => $content ) {
			if ( null !== $content ) {
				$checksums[ $name ] = hash( 'sha256', $content );
			}
		}

		$files['manifest.json'] = self::manifest_json( $session, $checksums );

		$filename = Storage::unique_filename();
		$zip_path = Storage::path( $filename );

		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			InternalLog::record( InternalLog::EXPORT_FAILURE, "Could not open ZIP for writing: {$zip_path}" );

			return new \WP_Error( 'crashtape_zip_failed', 'Could not create the support package archive.' );
		}

		foreach ( $files as $name => $content ) {
			$zip->addFromString( $name, $content );
		}

		// Real bug found via testing (a permissions failure that only
		// surfaces at close(), not open()): close() failing was completely
		// unhandled; its return value was ignored, and a real failure
		// here can throw rather than just returning false, letting a raw
		// PHP error escape instead of the clean WP_Error every other
		// failure path in this method returns.
		try {
			$closed = $zip->close();
		} catch ( \Throwable $e ) {
			$closed = false;
		}

		if ( ! $closed || ! file_exists( $zip_path ) ) {
			InternalLog::record( InternalLog::EXPORT_FAILURE, "Could not finalize ZIP: {$zip_path}" );

			return new \WP_Error( 'crashtape_zip_failed', 'Could not create the support package archive.' );
		}

		$hash = hash_file( 'sha256', $zip_path );
		$size = filesize( $zip_path );

		$package_id = Package::create( $session['id'], $filename, $hash, $size, Redactor::profile() );

		return array(
			'package_id' => $package_id,
			'filename'   => $filename,
			'hash'       => $hash,
			'size_bytes' => $size,
		);
	}

	private static function fetch_events( $session_id ) {
		global $wpdb;

		$table = Database::events_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id = %d ORDER BY created_at ASC, id ASC",
				$session_id
			)
		);
	}

	/**
	 * Per-request metadata (method, path, type, status, duration,
	 * component attribution, and the AJAX action name / REST
	 * namespace+auth enrichment Request::start()/
	 * capture_rest_context() capture); never previously surfaced
	 * anywhere, including here, despite being captured all along.
	 */
	private static function requests_json( $session_id ) {
		global $wpdb;

		$table = Database::requests_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$requests = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id = %d ORDER BY started_at ASC, id ASC",
				$session_id
			)
		);

		$out = array();

		foreach ( $requests as $request ) {
			$out[] = array(
				'method'       => $request->method,
				'path'         => $request->safe_path,
				'type'         => $request->request_type,
				'status'       => null !== $request->status ? (int) $request->status : null,
				'duration_ms'  => null !== $request->duration_ms ? (float) $request->duration_ms : null,
				'component'    => $request->component,
				'meta'         => $request->request_meta ? json_decode( $request->request_meta, true ) : null,
				'started_at'   => $request->started_at,
				'completed_at' => $request->completed_at,
			);
		}

		return wp_json_encode( $out, JSON_PRETTY_PRINT );
	}

	/**
	 * A single channel's events as a plain JSON array (not JSONL; these
	 * are the convenience per-channel breakdowns listed alongside
	 * timeline.jsonl, not a second large event stream). Same
	 * event shape as timeline_jsonl() minus the channel key, which is
	 * redundant once every entry in the file is already that channel.
	 */
	private static function channel_json( array $events, $channel ) {
		$out = array();

		foreach ( $events as $event ) {
			if ( $channel !== $event->channel ) {
				continue;
			}

			$out[] = array(
				'event_id'         => $event->event_id,
				'created_at'       => $event->created_at,
				'request_id'       => $event->request_id,
				'event_type'       => $event->event_type,
				'severity'         => $event->severity,
				'component_type'   => $event->component_type,
				'component_slug'   => $event->component_slug,
				'occurrence_count' => (int) $event->occurrence_count,
				'last_seen_at'     => $event->last_seen_at,
				'summary'          => $event->summary,
				'data'             => json_decode( $event->data, true ),
			);
		}

		return wp_json_encode( $out, JSON_PRETTY_PRINT );
	}

	/**
	 * requests.json already has every request; this is the same data
	 * filtered to REST requests only, with the REST-specific enrichment
	 * (namespace/auth) promoted to top-level fields instead of buried in
	 * a generic `meta` blob; for someone who only cares about REST.
	 */
	private static function rest_json( $session_id ) {
		global $wpdb;

		$table = Database::requests_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$requests = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id = %d AND request_type = %s ORDER BY started_at ASC, id ASC",
				$session_id,
				'rest'
			)
		);

		$out = array();

		foreach ( $requests as $request ) {
			$meta = $request->request_meta ? json_decode( $request->request_meta, true ) : array();
			$meta = is_array( $meta ) ? $meta : array();

			$out[] = array(
				'method'       => $request->method,
				'path'         => $request->safe_path,
				'namespace'    => isset( $meta['rest_namespace'] ) ? $meta['rest_namespace'] : null,
				'auth'         => isset( $meta['rest_auth'] ) ? $meta['rest_auth'] : null,
				'status'       => null !== $request->status ? (int) $request->status : null,
				'duration_ms'  => null !== $request->duration_ms ? (float) $request->duration_ms : null,
				'component'    => $request->component,
				'started_at'   => $request->started_at,
				'completed_at' => $request->completed_at,
			);
		}

		return wp_json_encode( $out, JSON_PRETTY_PRINT );
	}

	/**
	 * The WP-Cron/Action Scheduler overview is already captured once, at
	 * session start, as part of environment.json (CronSnapshot::capture(),
	 * merged in by Session::start()); this just pulls those same two keys
	 * into their own file for someone who only wants the scheduled-tasks
	 * view, not a second capture.
	 */
	private static function scheduled_tasks_json( array $session ) {
		$env = ! empty( $session['environment'] ) ? json_decode( $session['environment'], true ) : array();
		$env = is_array( $env ) ? $env : array();

		return wp_json_encode(
			array(
				'wp_cron'          => isset( $env['wp_cron'] ) ? $env['wp_cron'] : null,
				'action_scheduler' => isset( $env['action_scheduler'] ) ? $env['action_scheduler'] : null,
			),
			JSON_PRETTY_PRINT
		);
	}

	private static function readme() {
		return "CrashTape Support Package\n"
			. "=========================\n\n"
			. "This ZIP contains a privacy-redacted diagnostic report generated by CrashTape,\n"
			. "a WordPress diagnostic recorder. Open summary.html in any browser to view a\n"
			. "human-readable report; no internet connection or external files required.\n\n"
			. "Files:\n"
			. "  summary.html           Human-readable report\n"
			. "  manifest.json          Report metadata and file checksums\n"
			. "  session.json           Session summary and analysis result\n"
			. "  environment.json       Site environment at the time of recording\n"
			. "  timeline.jsonl         Every captured event, one JSON object per line\n"
			. "  requests.json          Every request during this session: method, path, type,\n"
			. "                         status, duration, attributed component, and AJAX/REST\n"
			. "                         metadata (action name, namespace, auth status)\n"
			. "  changes.json           Plugin/theme/core changes before this session\n"
			. "  redaction-report.json  What was redacted before this package was built\n"
			. "  diagnostics.json       Results from any third-party crashtape_register_diagnostic() checks\n"
			. "  errors.json            PHP warnings/notices/fatals only, filtered from timeline.jsonl\n"
			. "  http.json              WordPress HTTP API failures only\n"
			. "  browser.json           Browser JS errors and failed fetch/XHR requests only\n"
			. "  mail.json              Failed wp_mail() calls only\n"
			. "  woocommerce.json       WooCommerce order/payment failures only\n"
			. "  forms.json             Contact Form 7 submission outcomes only (validation/mail failures)\n"
			. "  cache.json             Page-cache purge events during this session only\n"
			. "  custom-events.json     Events from crashtape_record_event() (third-party integrations) only\n"
			. "  rest.json              REST requests only, with namespace/auth status promoted to top level\n"
			. "  scheduled-tasks.json   WP-Cron/Action Scheduler overview at session start\n";
	}

	private static function manifest_json( array $session, array $checksums ) {
		return wp_json_encode(
			array(
				'product'           => 'CrashTape',
				'schema_version'    => '1.0',
				'plugin_version'    => defined( 'CRASHTAPE_VERSION' ) ? CRASHTAPE_VERSION : null,
				'generated_at'      => current_time( 'mysql', true ),
				'session_uuid'      => $session['uuid'],
				'redaction_profile' => Redactor::profile(),
				'file_checksums'    => $checksums,
			),
			JSON_PRETTY_PRINT
		);
	}

	private static function session_json( array $session ) {
		return wp_json_encode(
			array(
				'uuid'          => $session['uuid'],
				'label'         => $session['label'],
				'mode'          => $session['mode'],
				'status'        => $session['status'],
				'started_at'    => $session['started_at'],
				'stopped_at'    => $session['stopped_at'],
				'event_count'   => (int) $session['event_count'],
				'error_count'   => (int) $session['error_count'],
				'warning_count' => (int) $session['warning_count'],
				'analysis'      => ! empty( $session['analysis'] ) ? json_decode( $session['analysis'], true ) : null,
			),
			JSON_PRETTY_PRINT
		);
	}

	private static function environment_json( array $session ) {
		$env = ! empty( $session['environment'] ) ? json_decode( $session['environment'], true ) : array();

		return wp_json_encode( $env, JSON_PRETTY_PRINT );
	}

	private static function timeline_jsonl( array $events ) {
		$lines = array();

		foreach ( $events as $event ) {
			$lines[] = wp_json_encode(
				array(
					'event_id'         => $event->event_id,
					'created_at'       => $event->created_at,
					'request_id'       => $event->request_id,
					'channel'          => $event->channel,
					'event_type'       => $event->event_type,
					'severity'         => $event->severity,
					'component_type'   => $event->component_type,
					'component_slug'   => $event->component_slug,
					'occurrence_count' => (int) $event->occurrence_count,
					'last_seen_at'     => $event->last_seen_at,
					'summary'          => $event->summary,
					'data'             => json_decode( $event->data, true ),
				)
			);
		}

		return implode( "\n", $lines ) . ( $lines ? "\n" : '' );
	}

	private static function changes_json( array $changes ) {
		$out = array();

		foreach ( $changes as $change ) {
			$out[] = array(
				'change_type'      => $change->change_type,
				'component_type'   => $change->component_type,
				'component_slug'   => $change->component_slug,
				'previous_version' => $change->previous_version,
				'new_version'      => $change->new_version,
				'created_at'       => $change->created_at,
			);
		}

		return wp_json_encode( $out, JSON_PRETTY_PRINT );
	}

	/**
	 * Approximates a sensitive-data scan by counting how many times the
	 * redaction mask appears in what's being exported; everything was
	 * already redacted before it reached the database, so this reports
	 * what was caught, not a live re-scan of raw data (which no longer
	 * exists).
	 */
	private static function redaction_report_json( array $events, array $changes ) {
		$mask_count = 0;

		foreach ( $events as $event ) {
			$mask_count += substr_count( $event->summary, Redactor::MASK );
			$mask_count += substr_count( (string) $event->data, Redactor::MASK );
		}

		foreach ( $changes as $change ) {
			$mask_count += substr_count( (string) $change->metadata, Redactor::MASK );
		}

		return wp_json_encode(
			array(
				'redaction_profile'              => Redactor::profile(),
				'redactions_found'               => $mask_count,
				'authorization_headers_captured' => 0,
				'cookie_headers_captured'        => 0,
				'status'                         => 'Safe to review',
				'note'                           => 'Redaction happens before data is written to the database, not only at export time.',
			),
			JSON_PRETTY_PRINT
		);
	}

	private static function summary_html( array $session, array $events, array $changes ) {
		$analysis = ! empty( $session['analysis'] ) ? json_decode( $session['analysis'], true ) : null;
		$top      = ( $analysis && ! empty( $analysis['candidates'] ) ) ? $analysis['candidates'][0] : null;

		$brand_name   = Branding::org_name();
		$brand_footer = Branding::footer_note();
		$brand_logo   = Branding::logo_data_uri();

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo esc_html( sprintf( 'CrashTape Report: %s', $session['label'] ? $session['label'] : $session['uuid'] ) ); ?></title>
<style>
	body { font-family: -apple-system, sans-serif; max-width: 800px; margin: 2em auto; padding: 0 1em; color: #1d2327; }
	h1 { font-size: 20px; } h2 { font-size: 16px; border-bottom: 1px solid #ccc; padding-bottom: 4px; margin-top: 2em; }
	table { border-collapse: collapse; width: 100%; font-size: 13px; }
	th, td { text-align: left; padding: 4px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
	.confidence-High { color: #b32d2e; font-weight: bold; }
	.confidence-Medium { color: #996800; font-weight: bold; }
	.confidence-Low { color: #666; font-weight: bold; }
	.badge { display: inline-block; padding: 1px 6px; border-radius: 3px; background: #f0f0f1; font-size: 11px; }
</style>
</head>
<body>
		<?php if ( $brand_logo || $brand_name ) : ?>
		<div style="display:flex;align-items:center;gap:10px;margin-bottom:1em;">
			<?php if ( $brand_logo ) : ?>
				<img src="<?php echo esc_attr( $brand_logo ); ?>" alt="" style="max-height:40px;max-width:200px;">
			<?php endif; ?>
			<?php if ( $brand_name ) : ?>
				<span style="font-size:14px;color:#50575e;"><?php echo esc_html( $brand_name ); ?></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<h1><?php echo esc_html( $session['label'] ? $session['label'] : 'CrashTape Diagnostic Session' ); ?></h1>
	<p>
		<?php echo esc_html( sprintf( 'Session %s (%s)', $session['uuid'], $session['status'] ) ); ?><br>
		<?php echo esc_html( sprintf( 'Started %s, stopped %s', $session['started_at'], $session['stopped_at'] ? $session['stopped_at'] : '-' ) ); ?><br>
		<?php echo esc_html( sprintf( '%d events, %d errors, %d warnings', $session['event_count'], $session['error_count'], $session['warning_count'] ) ); ?>
	</p>

	<h2>Most Likely Issue</h2>
		<?php if ( $top ) : ?>
		<p><strong><?php echo esc_html( $top['title'] ); ?></strong>:
			<span class="confidence-<?php echo esc_attr( $top['confidence'] ); ?>"><?php echo esc_html( $top['confidence'] ); ?> confidence</span>
		</p>
		<p><?php echo esc_html( $top['explanation'] ); ?></p>
		<p><strong>Evidence</strong></p>
		<ul>
			<?php
			foreach ( $top['evidence'] as $line ) :
				?>
			<li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ul>
		<p><strong>Suggested checks</strong></p>
		<ul>
			<?php
			foreach ( $top['suggested_checks'] as $check ) :
				?>
			<li><?php echo esc_html( $check ); ?></li><?php endforeach; ?></ul>
	<?php else : ?>
		<p>No rule matched any captured event for this session.</p>
	<?php endif; ?>

	<h2>Recent Changes</h2>
		<?php if ( $changes ) : ?>
		<table>
			<thead><tr><th>Time</th><th>Change</th></tr></thead>
			<tbody>
			<?php foreach ( $changes as $change ) : ?>
				<tr>
					<td><?php echo esc_html( $change->created_at ); ?></td>
					<td><?php echo esc_html( sprintf( '%s %s %s%s', ucfirst( $change->component_type ), $change->component_slug, str_replace( '_', ' ', $change->change_type ), $change->previous_version && $change->new_version ? " ({$change->previous_version} → {$change->new_version})" : '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p>No changes recorded in the 24 hours before this session.</p>
	<?php endif; ?>

	<h2>Timeline</h2>
	<table>
		<thead><tr><th>Time</th><th>Channel</th><th>Type</th><th>Severity</th><th>Component</th><th>Summary</th></tr></thead>
		<tbody>
		<?php foreach ( $events as $event ) : ?>
			<tr>
				<td><?php echo esc_html( $event->created_at ); ?></td>
				<td><span class="badge"><?php echo esc_html( $event->channel ); ?></span></td>
				<td><?php echo esc_html( $event->event_type ); ?></td>
				<td><?php echo esc_html( strtoupper( $event->severity ) ); ?></td>
				<td><?php echo esc_html( $event->component_slug ? $event->component_slug : '-' ); ?></td>
				<td><?php echo esc_html( $event->summary ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2>Privacy</h2>
	<p>Secrets and personal information are redacted before this data is ever written to the
		database, not only when this report is generated. See redaction-report.json for details.</p>

		<?php if ( $brand_footer ) : ?>
		<hr style="margin-top:2em;">
		<p style="color:#50575e;font-size:12px;white-space:pre-wrap;"><?php echo esc_html( $brand_footer ); ?></p>
	<?php endif; ?>
</body>
</html>
		<?php
		return ob_get_clean();
	}
}
