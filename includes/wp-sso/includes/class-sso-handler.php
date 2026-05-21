<?php
if (!defined('ABSPATH')) { exit; }

class WP_SSO_Handler {
    private static $instance = null;
    public static function get_instance() {
        if (null === self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {
        add_action('init', array($this, 'add_rewrite_rules'));
        add_action('template_redirect', array($this, 'handle_sso_redirect'));
        add_filter('query_vars', array($this, 'add_query_vars'));
    }
    public function add_rewrite_rules() {
        add_rewrite_rule('^wp-sso-redirect/?', 'index.php?wp_sso_redirect=1', 'top');
        flush_rewrite_rules();
    }
    public function add_query_vars($vars) { $vars[] = 'wp_sso_redirect'; return $vars; }
    public function handle_sso_redirect() {
        if (!get_query_var('wp_sso_redirect')) { return; }
        if (!is_user_logged_in()) { wp_redirect(home_url()); exit; }
        $user_id = get_current_user_id();
        $validation = WP_SSO_JWT_Generator::validate_user_data($user_id);
        if (is_wp_error($validation)) { $this->handle_error($user_id, $validation->get_error_message()); return; }
        $token = WP_SSO_JWT_Generator::generate_token($user_id);
        if (!$token) { $this->handle_error($user_id, __('Failed to generate JWT token', 'lalia')); return; }
        $redirect_url = $this->get_redirect_url($token);
        WP_SSO_Logger::get_instance()->log_attempt($user_id, 'success');
        wp_redirect($redirect_url); exit;
    }
    private function get_redirect_url($token) {
        $other_website_domain = get_option('wp_sso_other_website_domain', '');
        $return_to_url = get_option('wp_sso_return_to_url', '');
        $error_url = get_option('wp_sso_error_page_url', home_url('/sso-error/'));
        if (!preg_match('~^(?:f|ht)tps?://~i', $other_website_domain)) { $other_website_domain = 'https://' . $other_website_domain; }
        $other_website_domain = rtrim($other_website_domain, '/');
        $redirect_url = sprintf('%s/api/sso/v1?token=%s&return_to=%s&error_url=%s', $other_website_domain, urlencode($token), urlencode($return_to_url), urlencode($error_url));
        return $redirect_url;
    }
    private function handle_error($user_id, $error_message) {
        WP_SSO_Logger::get_instance()->log_attempt($user_id, 'failed', $error_message);
        $error_url = get_option('wp_sso_error_page_url', home_url('/sso-error/'));
        $error_url = add_query_arg(array('sso_error' => urlencode($error_message)), $error_url);
        wp_redirect($error_url); exit;
    }
    public static function validate_configuration() {
        $errors = array();
        $api_key = get_option('wp_sso_api_key', '');
        if (empty($api_key)) { $errors[] = __('API key is not configured', 'lalia'); }
        $domain = get_option('wp_sso_other_website_domain', '');
        if (empty($domain)) { $errors[] = __('Other website domain is not configured', 'lalia'); }
        $error_url = get_option('wp_sso_error_page_url', '');
        if (empty($error_url)) { $errors[] = __('Error page URL is not configured', 'lalia'); }
        $return_to_url = get_option('wp_sso_return_to_url', '');
        if (empty($return_to_url)) { $errors[] = __('Return to URL is not configured', 'lalia'); }
        if (!empty($errors)) { return new WP_Error('configuration_error', implode(', ', $errors)); }
        return true;
    }
}


