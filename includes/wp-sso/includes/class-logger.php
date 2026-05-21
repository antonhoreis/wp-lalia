<?php
/**
 * Handles logging functionality for SSO attempts
 */

if (!defined('ABSPATH')) {
    exit;
}

class WP_SSO_Logger {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!wp_next_scheduled('wp_sso_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'wp_sso_cleanup_logs');
        }
        add_action('wp_sso_cleanup_logs', array($this, 'cleanup_old_logs'));
    }

    public function log_attempt($user_id, $status, $error_message = null) {
        if (get_option('wp_sso_enable_logging', 'yes') !== 'yes') {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'sso_logs';
        $user = get_user_by('id', $user_id);
        $user_email = $user ? $user->user_email : 'unknown';

        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'user_email' => $user_email,
                'status' => $status,
                'error_message' => $error_message,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );
    }

    public function get_logs($args = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sso_logs';
        $defaults = array(
            'page' => 1,
            'per_page' => 20,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'user_id' => null,
            'status' => null,
            'date_from' => null,
            'date_to' => null
        );
        $args = wp_parse_args($args, $defaults);
        $where = array('1=1');
        $values = array();
        if ($args['user_id']) { $where[] = 'user_id = %d'; $values[] = $args['user_id']; }
        if ($args['status']) { $where[] = 'status = %s'; $values[] = $args['status']; }
        if ($args['date_from']) { $where[] = 'created_at >= %s'; $values[] = $args['date_from']; }
        if ($args['date_to']) { $where[] = 'created_at <= %s'; $values[] = $args['date_to']; }
        $where_clause = implode(' AND ', $where);
        $count_query = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";
        if (!empty($values)) { $count_query = $wpdb->prepare($count_query, $values); }
        $total_items = $wpdb->get_var($count_query);
        $offset = ($args['page'] - 1) * $args['per_page'];
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        $query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY $orderby LIMIT %d OFFSET %d";
        $values[] = $args['per_page'];
        $values[] = $offset;
        $results = $wpdb->get_results($wpdb->prepare($query, $values));
        return array(
            'items' => $results,
            'total' => $total_items,
            'pages' => ceil($total_items / $args['per_page'])
        );
    }

    public function cleanup_old_logs() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sso_logs';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                30
            )
        );
    }

    public function get_statistics() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sso_logs';
        $stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM $table_name WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY status",
            OBJECT_K
        );
        $daily_stats = $wpdb->get_results(
            "SELECT DATE(created_at) as date, status, COUNT(*) as count FROM $table_name WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(created_at), status ORDER BY date DESC"
        );
        return array(
            'totals' => $stats,
            'daily' => $daily_stats
        );
    }
}


