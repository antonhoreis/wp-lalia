<?php
/**
 * Handles JWT token generation
 */

if (!defined('ABSPATH')) {
    exit;
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class WP_SSO_JWT_Generator {
    public static function generate_token($user_id) {
        try {
            $user = get_user_by('id', $user_id);
            if (!$user) {
                throw new Exception('User not found');
            }

            $first_name = get_user_meta($user_id, 'first_name', true);
            $last_name  = get_user_meta($user_id, 'last_name', true);
            if (empty($first_name)) {
                $first_name = get_user_meta($user_id, 'billing_first_name', true);
            }
            if (empty($last_name)) {
                $last_name = get_user_meta($user_id, 'billing_last_name', true);
            }
            if (empty($first_name)) {
                throw new Exception('User first name is required');
            }

            $payload = array(
                'first_name' => $first_name,
                'last_name'  => $last_name ?: '',
                'email'      => $user->user_email,
            );

            $api_key = get_option('wp_sso_api_key', '');
            if (empty($api_key)) {
                throw new Exception('API key not configured');
            }

            $jwt = JWT::encode($payload, $api_key, 'HS256');
            return $jwt;
        } catch (Exception $e) {
            if (class_exists('WP_SSO_Logger')) {
                WP_SSO_Logger::get_instance()->log_attempt($user_id, 'failed', $e->getMessage());
            }
            return false;
        }
    }

    public static function decode_token($jwt) {
        try {
            $api_key = get_option('wp_sso_api_key', '');
            if (empty($api_key)) {
                throw new Exception('API key not configured');
            }
            $decoded = JWT::decode($jwt, new Key($api_key, 'HS256'));
            return $decoded;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function validate_user_data($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return new WP_Error('invalid_user', __('Invalid user ID', 'lalia'));
        }
        if (!in_array('customer', $user->roles) && !in_array('administrator', $user->roles)) {
            return new WP_Error('not_customer', __('User is not a WooCommerce customer', 'lalia'));
        }
        $first_name = get_user_meta($user_id, 'first_name', true);
        if (empty($first_name)) {
            $first_name = get_user_meta($user_id, 'billing_first_name', true);
        }
        if (empty($first_name)) {
            return new WP_Error('missing_first_name', __('User first name is required', 'lalia'));
        }
        if (empty($user->user_email)) {
            return new WP_Error('missing_email', __('User email is required', 'lalia'));
        }
        return true;
    }
}


