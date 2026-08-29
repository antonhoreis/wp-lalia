<?php
/**
 * Portal SSO — handoff-token minting.
 *
 * Produces the token `POST portal-auth/exchange` on the LALIA ERP accepts
 * (lalia-erp `supabase/functions/_shared/portal/handoff.ts`; spec
 * docs/superpowers/specs/2026-08-28-user-zone-portal-design.md §3.1.1):
 *
 *   { iss: "lalia-wp", aud: "lalia-portal", sub: "<wp user id>", email,
 *     first_name, last_name, iat, exp = iat + 120, jti: <uuid v4> }
 *
 * HS256 with the shared PORTAL_SSO_SECRET (WP option `lalia_portal_sso_secret`,
 * ERP `secrets.sops.env`). The ERP hard-caps exp - iat at 300 s and records the
 * jti, so a token is short-lived and single-use whatever this side does.
 *
 * This is a THIRD key: never the Zenler SSO key (`wp_sso_api_key`) and never
 * the checkout-prefill secret (`lalia_prefill_secret`).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lalia_Portal_SSO_Token {

	const ISSUER   = 'lalia-wp';
	const AUDIENCE = 'lalia-portal';
	/** Seconds. The ERP verifier rejects anything above 300. */
	const TTL = 120;
	/** Names are display fallbacks on the ERP side; keep them bounded. */
	const NAME_MAX_LEN = 100;
	/** HS256 needs a 256-bit key; php-jwt ≥ 6.10 throws below this. */
	const SECRET_MIN_BYTES = 32;

	/**
	 * Roles allowed to open the portal. The WordPress gate is deliberately
	 * coarse — the ERP is the authority (an e-mail without an active
	 * `portal_identities` row is `identity_unresolved` there), so this only
	 * keeps obviously-wrong accounts (e.g. contributors of a blog) from
	 * minting. `subscriber` is included because the user_auth registration
	 * flow can create accounts that only become `customer` at checkout.
	 *
	 * @return string[]
	 */
	public static function allowed_roles() {
		$roles = apply_filters(
			'lalia_portal_sso_allowed_roles',
			array( 'customer', 'subscriber', 'administrator', 'shop_manager' )
		);
		return is_array( $roles ) ? array_values( array_filter( array_map( 'strval', $roles ) ) ) : array();
	}

	/**
	 * The shared secret, or '' when unset.
	 *
	 * @return string
	 */
	public static function secret() {
		$secret = get_option( Lalia_Portal_SSO::OPTION_SECRET, '' );
		return is_string( $secret ) ? $secret : '';
	}

	/**
	 * Can this user mint a token? true, or a WP_Error explaining why not.
	 *
	 * @param int $user_id
	 * @return true|WP_Error
	 */
	public static function validate_user( $user_id ) {
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', __( 'User not found.', 'lalia' ) );
		}
		$email = trim( (string) $user->user_email );
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'missing_email', __( 'Your account has no valid e-mail address.', 'lalia' ) );
		}
		$roles = is_array( $user->roles ) ? $user->roles : array();
		if ( ! array_intersect( self::allowed_roles(), $roles ) ) {
			return new WP_Error( 'not_customer', __( 'This account is not a LALIA customer account.', 'lalia' ) );
		}
		return true;
	}

	/**
	 * Build the claim set for a user (no signing). Exposed for the test suite
	 * and the settings page's self-check.
	 *
	 * @param int      $user_id
	 * @param int|null $now Unix seconds; defaults to time().
	 * @return array|WP_Error
	 */
	public static function claims( $user_id, $now = null ) {
		$valid = self::validate_user( $user_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$user = get_user_by( 'id', (int) $user_id );
		$iat  = null === $now ? time() : (int) $now;

		return array(
			'iss'        => self::ISSUER,
			'aud'        => self::AUDIENCE,
			'sub'        => (string) $user->ID,
			// The ERP lower-cases before lookup as well; do it here so the
			// claim matches what `portal_identities.email` holds.
			'email'      => strtolower( trim( (string) $user->user_email ) ),
			'first_name' => self::name_meta( $user->ID, 'first_name' ),
			'last_name'  => self::name_meta( $user->ID, 'last_name' ),
			'iat'        => $iat,
			'exp'        => $iat + self::TTL,
			'jti'        => wp_generate_uuid4(),
		);
	}

	/**
	 * Mint a signed handoff token for a user.
	 *
	 * @param int $user_id
	 * @return string|WP_Error
	 */
	public static function mint( $user_id ) {
		$secret = self::secret();
		if ( '' === $secret ) {
			return new WP_Error( 'no_secret', __( 'Portal SSO secret is not configured.', 'lalia' ) );
		}
		if ( ! class_exists( '\Firebase\JWT\JWT' ) ) {
			return new WP_Error( 'no_jwt_lib', __( 'JWT library not loaded.', 'lalia' ) );
		}
		$claims = self::claims( $user_id );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}
		try {
			return \Firebase\JWT\JWT::encode( $claims, $secret, 'HS256' );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'sign_failed', 'Token signing failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Decode + verify a token this side minted (self-check / tests only — the
	 * ERP is the real verifier). Pins HS256; the header's alg is never trusted.
	 *
	 * @param string $jwt
	 * @return array|WP_Error Decoded claims.
	 */
	public static function decode( $jwt ) {
		$secret = self::secret();
		if ( '' === $secret ) {
			return new WP_Error( 'no_secret', 'Portal SSO secret is not configured.' );
		}
		// \Throwable, not \Exception: which php-jwt answers here depends on load
		// order (the Hostinger AI Assistant plugin bundles its own copy and wins
		// the class_exists race on the live hosts), and newer versions raise
		// \Error subtypes for malformed input.
		try {
			$decoded = \Firebase\JWT\JWT::decode( (string) $jwt, new \Firebase\JWT\Key( $secret, 'HS256' ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'invalid_token', $e->getMessage() );
		}
		$claims = json_decode( wp_json_encode( $decoded ), true );
		return is_array( $claims ) ? $claims : new WP_Error( 'invalid_token', 'Malformed claims' );
	}

	/**
	 * first_name / last_name with the WooCommerce billing_* fallback the
	 * Zenler SSO module already uses.
	 */
	private static function name_meta( $user_id, $key ) {
		$value = get_user_meta( $user_id, $key, true );
		if ( '' === trim( (string) $value ) ) {
			$value = get_user_meta( $user_id, 'billing_' . $key, true );
		}
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, self::NAME_MAX_LEN );
		}
		return substr( $value, 0, self::NAME_MAX_LEN );
	}
}
