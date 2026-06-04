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
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redeem_token' ), 5 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'preserve_prefill_on_add_to_cart_redirect' ) );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill_value' ), 20, 2 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'maybe_apply_coupon' ), 5 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'clear_session_payload' ) );
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
			// Log the library detail internally; callers get an opaque message
			// so JWT internals can never leak toward user-facing output.
			self::log( 'JWT decode failed: ' . $e->getMessage() );
			return new WP_Error( 'lalia_prefill_invalid', 'Invalid or expired token' );
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
	protected static function sanitize_payload( array $payload ) {
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
	 * On the checkout page, redeem a ?prefill= token into the WC session,
	 * then redirect to a clean URL (token never persists in the address bar).
	 * Runs after WooCommerce consumed ?add-to-cart= on wp_loaded, so the cart
	 * is already populated. Invalid tokens degrade silently.
	 */
	public static function maybe_redeem_token() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() ) {
			return;
		}
		if ( ! isset( $_GET['prefill'] ) ) {
			return;
		}
		// No secret -> module is inert: leave the param alone (same as disabled).
		if ( '' === get_option( 'lalia_prefill_secret', '' ) ) {
			return;
		}

		// Narrow charset filter instead of sanitize_text_field (which strips
		// %XX sequences and could silently truncate a token); HMAC verification
		// immediately follows, so this is purely defensive input hygiene.
		$token = preg_replace( '/[^A-Za-z0-9._\-=]/', '', wp_unslash( $_GET['prefill'] ) );

		// Reject oversized inputs before doing HMAC work — a real JWT here is ~0.5 KB.
		if ( strlen( $token ) > 4096 ) {
			self::log( 'Token rejected: oversized (' . strlen( $token ) . ' bytes)' );
			wp_safe_redirect( remove_query_arg( array( 'prefill', 'add-to-cart', 'quantity' ) ) );
			exit;
		}

		$result = self::verify_token( $token );

		if ( is_wp_error( $result ) ) {
			self::log( 'Token rejected: ' . $result->get_error_message() );
		} elseif ( ! WC()->session ) {
			self::log( 'Token valid but WC session unavailable — payload dropped.' );
		} elseif ( ! empty( $result['billing'] ) || '' !== $result['coupon'] ) {
			// Guests may not have a session cookie yet — force one so the payload persists.
			WC()->session->set_customer_session_cookie( true );
			WC()->session->set( self::SESSION_KEY, $result );
		}

		// Strip the token AND the consumed add-to-cart args so refreshing the
		// clean URL neither re-verifies nor re-adds the product.
		wp_safe_redirect( remove_query_arg( array( 'prefill', 'add-to-cart', 'quantity' ) ) );
		exit;
	}

	/**
	 * WooCommerce's add-to-cart handler redirects on wp_loaded (before
	 * template_redirect) when woocommerce_cart_redirect_after_add=yes,
	 * dropping all query args — which would lose the ?prefill= token on
	 * payment links. Carry the token to the checkout URL so
	 * maybe_redeem_token() can redeem it on the follow-up request.
	 *
	 * Intentionally redirects to the checkout URL (ignoring $url) — the token
	 * must land on the checkout page for maybe_redeem_token() to consume it.
	 *
	 * @param string|false $url Redirect URL from WooCommerce (false = use default).
	 * @return string|false
	 */
	public static function preserve_prefill_on_add_to_cart_redirect( $url ) {
		if ( ! isset( $_GET['prefill'] ) || '' === get_option( 'lalia_prefill_secret', '' ) ) {
			return $url;
		}
		$token = preg_replace( '/[^A-Za-z0-9._\-=]/', '', wp_unslash( $_GET['prefill'] ) );
		if ( '' === $token ) {
			return $url;
		}
		return add_query_arg( 'prefill', $token, wc_get_checkout_url() );
	}

	/**
	 * Feed redeemed token values into checkout fields.
	 * Returning non-null from this filter short-circuits WC_Checkout::get_value(),
	 * so token data wins over customer/user-meta data and over the user_auth
	 * plugin's field defaults (per spec: SDR data is fresher).
	 *
	 * @param mixed  $value Existing value (null unless another filter set it).
	 * @param string $input Field key, e.g. 'billing_email'.
	 * @return mixed
	 */
	public static function prefill_value( $value, $input ) {
		if ( ! is_string( $input ) || 0 !== strpos( $input, 'billing_' ) ) {
			return $value;
		}
		$data = self::get_session_payload();
		if ( null === $data ) {
			return $value;
		}
		$key = substr( $input, strlen( 'billing_' ) );
		if ( isset( $data['billing'][ $key ] ) && '' !== $data['billing'][ $key ] ) {
			return $data['billing'][ $key ];
		}
		return $value;
	}

	/**
	 * Apply the token's coupon on each checkout view while the payload exists.
	 * Idempotent; survives the single-item-cart module emptying the cart
	 * (which clears coupons) when the customer switches products.
	 * Nonexistent codes are skipped silently (no customer-facing notice).
	 */
	public static function maybe_apply_coupon() {
		$data = self::get_session_payload();
		if ( null === $data || '' === $data['coupon'] || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		$code = $data['coupon'];
		// In-memory check first — skips the coupon lookup on every re-render
		// after the first successful apply.
		if ( WC()->cart->has_discount( $code ) ) {
			return;
		}
		if ( ! wc_get_coupon_id_by_code( $code ) ) {
			return;
		}
		WC()->cart->apply_coupon( $code );
	}

	/**
	 * Drop the payload once an order is placed so a later, unrelated checkout
	 * in the same browser is not prefilled with stale customer data.
	 *
	 * NOTE: hooked on woocommerce_checkout_order_processed (classic checkout).
	 * If this site ever moves to the WooCommerce Blocks checkout, also hook
	 * woocommerce_store_api_checkout_order_processed or payloads will outlive orders.
	 */
	public static function clear_session_payload() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, null );
		}
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
