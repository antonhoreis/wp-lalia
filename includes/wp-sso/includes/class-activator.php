<?php
/**
 * Handles plugin activation and deactivation
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WP_SSO_Activator {
    
    /**
     * Plugin activation
     */
    public static function activate() {
        // Create database tables
        self::create_tables();
        
        // Set default options
        self::set_default_options();
        
        // Clear permalinks
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled hooks if any
        wp_clear_scheduled_hook('wp_sso_cleanup_logs');
        
        // Clear permalinks
        flush_rewrite_rules();
    }
    
    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'sso_logs';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            user_email VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL,
            error_message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Store database version
        add_option('wp_sso_db_version', '1.0.0');
    }
    
    /**
     * Set default plugin options
     */
    private static function set_default_options() {
        // Only add options if they don't exist
        add_option('wp_sso_api_key', '');
        add_option('wp_sso_other_website_domain', '');
        add_option('wp_sso_error_page_url', home_url('/sso-error/'));
        add_option('wp_sso_menu_item_title', 'My Courses');
        add_option('wp_sso_enable_logging', 'yes');
        add_option('wp_sso_return_to_url', '');
    }
}


