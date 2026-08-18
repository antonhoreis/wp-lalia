<?php
if (!defined('ABSPATH')) { exit; }

class WP_SSO_Menu_Manager {
    /** Option holding the ID of the nav menu item this plugin owns. */
    const ITEM_ID_OPTION = 'wp_sso_menu_item_id';
    /** Option used as a cross-request mutex while the item is being created. */
    const LOCK_OPTION = 'wp_sso_menu_item_lock';
    /** Seconds after which a held lock is considered abandoned. */
    const LOCK_TIMEOUT = 60;

    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {
        add_filter('wp_nav_menu_objects', array($this, 'filter_menu_objects'), 10, 2);
        add_filter('wp_get_nav_menu_items', array($this, 'filter_menu_items'), 10, 3);
        add_action('admin_init', array($this, 'maybe_create_menu_item'));
        add_filter('nav_menu_link_attributes', array($this, 'modify_menu_link'), 10, 4);
    }

    /**
     * Filter the raw menu items coming out of the database.
     *
     * This filter also feeds the menu editor in wp-admin, so it must never
     * modify an item — whatever we hand back can be written straight back to
     * the database when an editor saves the menu. Hiding is applied on the
     * front end only; in wp-admin the item always stays visible so it can be
     * managed.
     */
    public function filter_menu_items($items, $menu = null, $args = null) {
        if (!is_array($items)) { return $items; }
        if (is_admin() && !wp_doing_ajax()) { return $items; }
        if ($this->should_show_courses_menu()) { return $items; }
        $menu_item_title = get_option('wp_sso_menu_item_title', 'My Courses');
        foreach ($items as $key => $item) {
            if ($this->is_sso_menu_item($item, $menu_item_title)) {
                unset($items[$key]);
            }
        }
        return array_values($items);
    }

    /**
     * Filter the menu items being rendered. Safe to modify — these objects are
     * throwaway copies used for output only.
     */
    public function filter_menu_objects($items, $args = null) {
        if (!is_array($items)) { return $items; }
        $menu_item_title = get_option('wp_sso_menu_item_title', 'My Courses');
        $show_menu = $this->should_show_courses_menu();
        foreach ($items as $key => $item) {
            if ($this->is_sso_menu_item($item, $menu_item_title)) {
                if (!$show_menu) {
                    unset($items[$key]);
                } else {
                    $item->classes[] = 'wp-sso-menu-item';
                    $item->url = home_url('/wp-sso-redirect/');
                }
            }
        }
        return array_values($items);
    }

    private function is_sso_menu_item($item, $title) {
        if (isset($item->ID)) {
            $is_sso = get_post_meta($item->ID, '_wp_sso_menu_item', true);
            if ($is_sso === 'yes') { return true; }
        }
        if (isset($item->title) && $item->title === $title) { return true; }
        return false;
    }

    private function should_show_courses_menu() {
        if (!is_user_logged_in()) { return false; }
        if (!class_exists('WooCommerce')) { return false; }
        $user = wp_get_current_user();
        $allowed_roles = array('customer', 'administrator', 'shop_manager');
        if (array_intersect($allowed_roles, $user->roles)) { return true; }
        return false;
    }

    /**
     * Create the SSO menu item once, and only once.
     *
     * This runs on every admin request, so it has to be reliably idempotent.
     * Ownership is tracked by post ID in an option (and by the
     * _wp_sso_menu_item meta as a fallback) rather than by matching the item
     * title: a title lookup misses as soon as the item is renamed, and any
     * miss used to mean another copy was inserted into the menu.
     */
    public function maybe_create_menu_item() {
        if ($this->locate_existing_item()) { return; }

        $menu_id = $this->get_target_menu_id();
        if (!$menu_id) { return; }

        if (!$this->acquire_create_lock()) { return; }

        try {
            // Re-check under the lock: a concurrent request may have created
            // the item between our first lookup and acquiring the lock.
            if ($this->locate_existing_item()) { return; }

            $menu_item_title = get_option('wp_sso_menu_item_title', 'My Courses');
            $menu_item_data = array(
                'menu-item-title'  => $menu_item_title,
                'menu-item-url'    => home_url('/wp-sso-redirect/'),
                'menu-item-status' => 'publish',
                'menu-item-type'   => 'custom',
            );
            $menu_item_id = wp_update_nav_menu_item($menu_id, 0, $menu_item_data);
            if (!is_wp_error($menu_item_id) && $menu_item_id) {
                $this->claim_item($menu_item_id);
            }
        } finally {
            $this->release_create_lock();
        }
    }

    /**
     * Find the menu item this plugin owns, adopting a pre-existing one if the
     * ownership marker was never recorded. Returns the item ID, or 0.
     */
    private function locate_existing_item() {
        $item_id = (int) get_option(self::ITEM_ID_OPTION, 0);
        if ($item_id && $this->is_live_menu_item($item_id)) { return $item_id; }

        // Recorded ID is missing or stale — fall back to the ownership meta.
        $owned = get_posts(array(
            'post_type'        => 'nav_menu_item',
            'post_status'      => 'publish',
            'numberposts'      => 1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'fields'           => 'ids',
            'meta_key'         => '_wp_sso_menu_item',
            'meta_value'       => 'yes',
            'suppress_filters' => true,
        ));
        foreach ($owned as $candidate) {
            if ($this->is_live_menu_item($candidate)) {
                $this->claim_item($candidate);
                return (int) $candidate;
            }
        }

        // Last resort: adopt a hand-made item matching the configured title,
        // so an item that predates this plugin is never duplicated.
        $menu_item_title = get_option('wp_sso_menu_item_title', 'My Courses');
        $by_title = get_posts(array(
            'title'            => $menu_item_title,
            'post_type'        => 'nav_menu_item',
            'post_status'      => 'publish',
            'numberposts'      => 1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'fields'           => 'ids',
            'suppress_filters' => true,
        ));
        foreach ($by_title as $candidate) {
            if ($this->is_live_menu_item($candidate)) {
                $this->claim_item($candidate);
                return (int) $candidate;
            }
        }

        return 0;
    }

    /**
     * True if $item_id still points at a published nav menu item.
     *
     * Deliberately a plain get_post() and nothing more: removing an item from a
     * menu deletes the post outright, so its existence is enough. Every extra
     * condition here is another way to wrongly conclude the item is gone, and
     * the cost of that mistake is a duplicate in the menu.
     */
    private function is_live_menu_item($item_id) {
        $post = get_post($item_id);
        return $post && 'nav_menu_item' === $post->post_type && 'publish' === $post->post_status;
    }

    /** Record $item_id as the item this plugin owns. */
    private function claim_item($item_id) {
        update_post_meta($item_id, '_wp_sso_menu_item', 'yes');
        update_option(self::ITEM_ID_OPTION, (int) $item_id, false);
    }

    /** Resolve the menu the item belongs in: the primary location, else the first menu. */
    private function get_target_menu_id() {
        $menu_locations = get_nav_menu_locations();
        $menu_id = isset($menu_locations['primary']) ? (int) $menu_locations['primary'] : 0;
        if ($menu_id) { return $menu_id; }
        $menus = wp_get_nav_menus();
        if (empty($menus)) { return 0; }
        $menu = reset($menus);
        return (int) $menu->term_id;
    }

    /**
     * Take a cross-request lock so two concurrent admin requests cannot both
     * pass the existence check and insert an item.
     *
     * option_name carries a UNIQUE index, so INSERT IGNORE either writes the
     * row or reports zero affected rows — the same mutex WP_Upgrader uses for
     * its own locks. get_option() cannot stand in for this: add_option() is an
     * upsert, and without a persistent object cache there is nothing else
     * shared between requests to test against.
     */
    private function acquire_create_lock() {
        global $wpdb;
        $now = time();

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::LOCK_OPTION,
            $now
        ));
        if (1 === (int) $wpdb->rows_affected) { return true; }

        // Someone else holds the lock. Steal it only once it has gone stale,
        // and only if nobody else steals it first.
        $held = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::LOCK_OPTION
        ));
        if (null === $held || ($now - (int) $held) < self::LOCK_TIMEOUT) { return false; }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $now,
            self::LOCK_OPTION,
            $held
        ));
        return 1 === (int) $wpdb->rows_affected;
    }

    private function release_create_lock() {
        global $wpdb;
        $wpdb->delete($wpdb->options, array('option_name' => self::LOCK_OPTION));
        wp_cache_delete(self::LOCK_OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    public function modify_menu_link($atts, $item, $args, $depth) {
        $menu_item_title = get_option('wp_sso_menu_item_title', 'My Courses');
        if ($this->is_sso_menu_item($item, $menu_item_title) && $this->should_show_courses_menu()) {
            $atts['href'] = home_url('/wp-sso-redirect/');
            $atts['class'] = isset($atts['class']) ? $atts['class'] . ' wp-sso-link' : 'wp-sso-link';
        }
        return $atts;
    }
}
