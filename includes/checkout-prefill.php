<?php
/**
 * Lalia module: Checkout Prefill via signed payment links.
 *
 * Redeems ?prefill=<JWT> on the checkout page: verifies the HS256 token,
 * stores the sanitized payload in the WooCommerce session, prefills billing
 * fields and applies an optional coupon. Spec:
 * docs/superpowers/specs/2026-06-04-checkout-prefill-links-design.md (lalia-system repo)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lalia_Checkout_Prefill {

	const SESSION_KEY = 'lalia_prefill';

	const BILLING_WHITELIST = array(
		'email',
		'first_name',
		'last_name',
		'phone',
		'company',
		'address_1',
		'address_2',
		'city',
		'postcode',
		'state',
		'country',
	);

	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		// Hooks are registered incrementally across implementation tasks.
	}

	/**
	 * Verify a prefill JWT and return the sanitized payload, or WP_Error.
	 *
	 * @param string $jwt Raw token from the URL.
	 * @return array|WP_Error array{billing: array<string,string>, coupon: string}
	 */
	public static function verify_token( $jwt ) {
		$secret = get_option( 'lalia_prefill_secret', '' );
		if ( ! is_string( $secret ) || '' === $secret ) {
			return new WP_Error( 'lalia_prefill_no_secret', 'Prefill secret not configured' );
		}
		if ( ! is_string( $jwt ) || '' === $jwt ) {
			return new WP_Error( 'lalia_prefill_empty', 'Empty token' );
		}
		try {
			// Key pins the algorithm to HS256 — the token header's alg is never trusted.
			$decoded = \Firebase\JWT\JWT::decode( $jwt, new \Firebase\JWT\Key( $secret, 'HS256' ) );
		} catch ( \Exception $e ) {
			return new WP_Error( 'lalia_prefill_invalid', $e->getMessage() );
		}
		// JWT::decode only enforces exp when present; the contract requires it.
		if ( ! isset( $decoded->exp ) ) {
			return new WP_Error( 'lalia_prefill_invalid', 'Missing exp claim' );
		}
		$payload = json_decode( wp_json_encode( $decoded ), true );
		return self::sanitize_payload( is_array( $payload ) ? $payload : array() );
	}

	/**
	 * Whitelist and sanitize a decoded token payload.
	 *
	 * @param array $payload Decoded JWT claims.
	 * @return array array{billing: array<string,string>, coupon: string}
	 */
	public static function sanitize_payload( array $payload ) {
		$clean   = array(
			'billing' => array(),
			'coupon'  => '',
		);
		$billing = ( isset( $payload['billing'] ) && is_array( $payload['billing'] ) ) ? $payload['billing'] : array();

		foreach ( self::BILLING_WHITELIST as $key ) {
			if ( ! isset( $billing[ $key ] ) || ! is_string( $billing[ $key ] ) ) {
				continue;
			}
			$value = ( 'email' === $key ) ? sanitize_email( $billing[ $key ] ) : sanitize_text_field( $billing[ $key ] );
			if ( '' !== $value ) {
				$clean['billing'][ $key ] = $value;
			}
		}

		// Country must be a valid WC country code, else dropped.
		if ( isset( $clean['billing']['country'] ) ) {
			$country   = strtoupper( $clean['billing']['country'] );
			$countries = WC()->countries->get_countries();
			if ( isset( $countries[ $country ] ) ) {
				$clean['billing']['country'] = $country;
			} else {
				unset( $clean['billing']['country'] );
			}
		}

		// State: when WC defines states for the country, the code must match (uppercased), else dropped.
		// Countries without a WC state list (e.g. DE) pass the sanitized value through.
		if ( isset( $clean['billing']['state'] ) ) {
			if ( isset( $clean['billing']['country'] ) ) {
				$states = WC()->countries->get_states( $clean['billing']['country'] );
				if ( is_array( $states ) && ! empty( $states ) ) {
					$state_code = strtoupper( $clean['billing']['state'] );
					if ( isset( $states[ $state_code ] ) ) {
						$clean['billing']['state'] = $state_code;
					} else {
						unset( $clean['billing']['state'] );
					}
				}
			} else {
				// No (valid) country to validate against — drop the state too.
				unset( $clean['billing']['state'] );
			}
		}

		if ( isset( $payload['coupon'] ) && is_string( $payload['coupon'] ) && '' !== $payload['coupon'] ) {
			$clean['coupon'] = wc_format_coupon_code( sanitize_text_field( $payload['coupon'] ) );
		}

		return $clean;
	}

	/**
	 * Read the redeemed payload from the WC session, or null.
	 *
	 * @return array|null
	 */
	protected static function get_session_payload() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		$data = WC()->session->get( self::SESSION_KEY );
		return is_array( $data ) ? $data : null;
	}

	protected static function log( $message ) {
		error_log( '[lalia-checkout-prefill] ' . $message );
	}
}
