<?php
/**
 * Portal SSO — event log (mint / denied / error), mirroring the Zenler SSO
 * module's logger but in its own table so the two audit trails never mix.
 *
 * The table is created lazily (option-versioned dbDelta) rather than only on
 * activation: plugin updates through the wp_update pipeline do NOT re-run the
 * activation hook, so an install that upgrades into this module would
 * otherwise never get the table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lalia_Portal_SSO_Logger {

	const DB_VERSION        = '1';
	const OPTION_DB_VERSION = 'lalia_portal_sso_db_version';
	const CRON_HOOK         = 'lalia_portal_sso_cleanup_logs';
	const RETENTION_DAYS    = 30;

	const EVENT_MINT   = 'mint';
	const EVENT_DENIED = 'denied';
	const EVENT_ERROR  = 'error';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
		add_action( self::CRON_HOOK, array( $this, 'cleanup_old_logs' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lalia_portal_sso_logs';
	}

	/** Idempotent; cheap once the version option matches. */
	public static function ensure_table() {
		if ( get_option( self::OPTION_DB_VERSION, '' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_email VARCHAR(190) NOT NULL DEFAULT '',
			event VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL,
			message TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) $charset_collate;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	public static function logging_enabled() {
		return get_option( Lalia_Portal_SSO::OPTION_LOGGING, 'yes' ) === 'yes';
	}

	/**
	 * @param int    $user_id
	 * @param string $event   One of the EVENT_* constants.
	 * @param string $status  'success' | 'failed'
	 * @param string $message Free text (never token material).
	 */
	public function log( $user_id, $event, $status, $message = '' ) {
		if ( ! self::logging_enabled() ) {
			return;
		}
		self::ensure_table();
		global $wpdb;
		$user  = $user_id ? get_user_by( 'id', (int) $user_id ) : null;
		$email = $user ? (string) $user->user_email : '';
		$wpdb->insert(
			self::table(),
			array(
				'user_id'    => (int) $user_id,
				'user_email' => $email,
				'event'      => substr( (string) $event, 0, 20 ),
				'status'     => substr( (string) $status, 0, 20 ),
				'message'    => (string) $message,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function get_logs( $args = array() ) {
		global $wpdb;
		self::ensure_table();
		$table    = self::table();
		$args     = wp_parse_args(
			$args,
			array(
				'page'     => 1,
				'per_page' => 20,
			)
		);
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$items    = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	public function get_statistics() {
		global $wpdb;
		self::ensure_table();
		$table = self::table();
		$rows  = $wpdb->get_results(
			"SELECT event, status, COUNT(*) AS count FROM $table WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY event, status" // phpcs:ignore WordPress.DB.PreparedSQL
		);
		$stats = array(
			'mint'   => 0,
			'denied' => 0,
			'error'  => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $stats[ $row->event ] ) ) {
				$stats[ $row->event ] += (int) $row->count;
			}
		}
		return $stats;
	}

	public function cleanup_old_logs() {
		global $wpdb;
		self::ensure_table();
		$table = self::table();
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", self::RETENTION_DAYS ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}
}
