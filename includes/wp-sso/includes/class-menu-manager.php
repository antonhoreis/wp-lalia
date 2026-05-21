<?php
if (!defined('ABSPATH')) { exit; }

class WP_SSO_Menu_Manager {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {
        add_filter('wp_nav_menu_objects', array($this, 'filter_menu_items'), 10, 2);
        add_filter('wp_get_nav_menu_items', array($this, 'filter_menu_items'), 10, 2);
        add_action('admin_init', array($this, 'maybe_create_menu_item'));
        add_filter('nav_menu_link_attributes', array($this, 'modify_menu_link'), 10, 4);
    }
    public function filter_menu_items($items, $args = null) {
        if (!is_array($items)) { return $items; }
        $menu_item_title = get_option('wp_sso_menu_item_title', 'My Courses');
        $show_menu = $this->should_show_courses_menu();
        foreach ($items as $key => $item) {
            if ($this->is_sso_menu_item($item, $menu_item_title)) {
                if (!$show_menu) {
                    unset($items[$key]);
                } else {
                    $item->classes[] = 'wp-sso-menu-item';
                    $item->url = '#wp-sso-redirect';
                }
            }
        }
        return array_values($items);
    }
    private function is_sso_menu_item($item, $title) {
        if ($item->title === $title) { return true; }
        if (isset($item->ID)) {
            $is_sso = get_post_meta($item->ID, '_wp_sso_menu_item', true);
            if ($is_sso === 'yes') { return true; }
        }
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
    public function maybe_create_menu_item() {
        $menu_item_title = get_option('wp_sso_menu_item_title', 'My Courses');
        $existing_item = get_posts(array(
            'title' => $menu_item_title,
            'post_type' => 'nav_menu_item',
            'post_status' => 'publish',
            'numberposts' => 1
        ));
        if (!empty($existing_item)) { return; }
        $menu_locations = get_nav_menu_locations();
        $menu_id = isset($menu_locations['primary']) ? $menu_locations['primary'] : 0;
        if (!$menu_id) {
            $menus = wp_get_nav_menus();
            if (!empty($menus)) { $menu = reset($menus); $menu_id = $menu->term_id; } else { return; }
        }
        $menu_item_data = array(
            'menu-item-title'  => $menu_item_title,
            'menu-item-url'    => home_url('/wp-sso-redirect/'),
            'menu-item-status' => 'publish',
            'menu-item-type'   => 'custom',
        );
        $menu_item_id = wp_update_nav_menu_item($menu_id, 0, $menu_item_data);
        if (!is_wp_error($menu_item_id)) {
            update_post_meta($menu_item_id, '_wp_sso_menu_item', 'yes');
        }
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


