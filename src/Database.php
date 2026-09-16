<?php

namespace JMooreWV\CrashTape;

use JMooreWV\CrashTape\Support\InternalLog;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 1 storage: sessions, requests, and events tables. Migrations here
 * are pre-release only; no external installs depend on this schema yet,
 * so a version bump drops and recreates rather than performing a careful
 * column migration (that applies once this ships).
 */
class Database {

	const DB_VERSION_OPTION = 'crashtape_db_version';
	const DB_VERSION        = '9';

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		self::migrate_to_v2( $wpdb );

		dbDelta( self::sessions_sql( $charset_collate ) );
		dbDelta( self::requests_sql( $charset_collate ) );
		dbDelta( self::events_sql( $charset_collate ) );
		dbDelta( self::changes_sql( $charset_collate ) );
		dbDelta( self::packages_sql( $charset_collate ) );

		// A migration failure here matters: dbDelta() doesn't throw or
		// return a usable success/failure signal, so the only reliable way
		// to know a table genuinely got created (a real SQL error, an
		// insufficient DB privilege) is to check for it afterward, before
		// this plugin tells itself the schema is current.
		$missing = self::missing_tables( $wpdb );

		if ( $missing ) {
			InternalLog::record( InternalLog::MIGRATION_FAILURE, 'Table(s) missing after install: ' . implode( ', ', $missing ) );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	private static function missing_tables( $wpdb ) {
		$tables  = array( self::sessions_table(), self::requests_table(), self::events_table(), self::changes_table(), self::packages_table() );
		$missing = array();

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}

	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * v1 stored session_id/request_id as UUID strings on the events table.
	 * v2 introduces real sessions/requests tables and switches those columns
	 * to numeric foreign keys, which dbDelta cannot safely alter in place.
	 * v3 only adds nullable columns, which dbDelta handles fine in place;
	 * so this destructive step is skipped once already at v2 or later.
	 */
	private static function migrate_to_v2( $wpdb ) {
		$current = get_option( self::DB_VERSION_OPTION );

		if ( $current && version_compare( (string) $current, '2', '>=' ) ) {
			return;
		}

		$events_table = self::events_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$events_table}" );
	}

	private static function sessions_sql( $charset_collate ) {
		$table = self::sessions_table();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			label VARCHAR(255) NULL,
			mode VARCHAR(32) NOT NULL,
			status VARCHAR(32) NOT NULL,
			started_at DATETIME(6) NOT NULL,
			stopped_at DATETIME(6) NULL,
			expires_at DATETIME NULL,
			event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			error_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			warning_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			analysis LONGTEXT NULL,
			settings LONGTEXT NULL,
			environment LONGTEXT NULL,
			baseline_diff LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY status (status),
			KEY started_at (started_at)
		) {$charset_collate};";
	}

	private static function requests_sql( $charset_collate ) {
		$table = self::requests_table();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			session_id BIGINT UNSIGNED NOT NULL,
			parent_request_uuid CHAR(36) NULL,
			method VARCHAR(12) NOT NULL,
			path_hash CHAR(64) NOT NULL,
			safe_path TEXT NOT NULL,
			request_type VARCHAR(32) NOT NULL,
			status SMALLINT NULL,
			duration_ms DECIMAL(12,3) NULL,
			peak_memory BIGINT UNSIGNED NULL,
			component VARCHAR(191) NULL,
			request_meta LONGTEXT NULL,
			started_at DATETIME(6) NOT NULL,
			completed_at DATETIME(6) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY session_id (session_id),
			KEY started_at (started_at),
			KEY request_type (request_type),
			KEY status (status)
		) {$charset_collate};";
	}

	private static function events_sql( $charset_collate ) {
		$table = self::events_table();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id CHAR(36) NOT NULL,
			session_id BIGINT UNSIGNED NOT NULL,
			request_id BIGINT UNSIGNED NULL,
			channel VARCHAR(32) NOT NULL,
			event_type VARCHAR(64) NOT NULL,
			severity VARCHAR(16) NOT NULL,
			component_type VARCHAR(32) NULL,
			component_slug VARCHAR(191) NULL,
			fingerprint CHAR(64) NULL,
			occurrence_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
			last_seen_at DATETIME(6) NULL,
			summary TEXT NOT NULL,
			data LONGTEXT NULL,
			created_at DATETIME(6) NOT NULL,
			PRIMARY KEY  (id),
			KEY session_created (session_id, created_at),
			KEY request_id (request_id),
			KEY channel (channel),
			KEY severity (severity),
			KEY component_slug (component_slug),
			KEY session_fingerprint (session_id, fingerprint)
		) {$charset_collate};";
	}

	private static function changes_sql( $charset_collate ) {
		$table = self::changes_table();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			change_type VARCHAR(64) NOT NULL,
			component_type VARCHAR(32) NOT NULL,
			component_slug VARCHAR(191) NOT NULL,
			previous_version VARCHAR(64) NULL,
			new_version VARCHAR(64) NULL,
			metadata LONGTEXT NULL,
			created_at DATETIME(6) NOT NULL,
			PRIMARY KEY  (id),
			KEY change_type (change_type),
			KEY component_slug (component_slug),
			KEY created_at (created_at)
		) {$charset_collate};";
	}

	private static function packages_sql( $charset_collate ) {
		$table = self::packages_table();

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT UNSIGNED NOT NULL,
			filename VARCHAR(255) NOT NULL,
			hash CHAR(64) NOT NULL,
			redaction_profile VARCHAR(32) NOT NULL,
			size_bytes BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";
	}

	public static function sessions_table() {
		global $wpdb;

		return $wpdb->prefix . 'crashtape_sessions';
	}

	public static function requests_table() {
		global $wpdb;

		return $wpdb->prefix . 'crashtape_requests';
	}

	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'crashtape_events';
	}

	public static function changes_table() {
		global $wpdb;

		return $wpdb->prefix . 'crashtape_changes';
	}

	public static function packages_table() {
		global $wpdb;

		return $wpdb->prefix . 'crashtape_packages';
	}
}
