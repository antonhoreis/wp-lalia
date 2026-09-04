<?php
/**
 * Portal SSO — ERP-signed user-erasure endpoint (GDPR Art. 17, L20-1025).
 *
 * `POST /wp-json/lalia/v1/portal/erase-user` lets the LALIA ERP delete the
 * WordPress account of a customer whose erasure request it is fulfilling.
 * The caller authenticates with a short-lived HS256 bearer JWT minted with
 * the SAME shared secret as the Portal SSO handoff (`lalia_portal_sso_secret`,
 * ERP `PORTAL_SSO_SECRET`) but with its own issuer/audience pair, so a
 * handoff token can never erase and an erase token can never log in:
 *
 *   { iss: "lalia-erp", aud: "lalia-wp-erase", sub: "<wp user id>" | "",
 *     email: "<lower-cased e-mail>", iat, exp (<= iat + 300), jti: <uuid> }
 *
 * A jti is single-use (remembered for 600 s — longer than any token can
 * live). The module is independent of the Portal SSO toggle — production
 * runs with SSO off — and ships DISABLED: `lalia_enable_portal_erasure`
 * defaults to 'no' and the route only exists while it is 'yes'.
 *
 * What deletion means here: wp_delete_user() without reassignment. Orders
 * are accounting records and are kept — WooCommerce listens on
 * `deleted_user`, unlinks the customer's orders (customer_id → 0) and drops
 * its customer-lookup row and payment tokens. All user meta, including any
 * `_woocommerce_persistent_cart_*` rows, goes with the user row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lalia_Portal_Erasure {

	const OPTION_ENABLED = 'lalia_enable_portal_erasure';

	const REST_NAMESPACE = 'lalia/v1';
	const REST_ROUTE     = '/portal/erase-user';

	const ISSUER   = 'lalia-erp';
	const AUDIENCE = 'lalia-wp-erase';
	/** Seconds. Hard cap on exp - iat, whatever the minter did. */
	const MAX_TTL = 300;
	/** Seconds of clock skew tolerated on iat / exp (same as the ERP verifier). */
	const CLOCK_SKEW = 30;
	/** Seconds a consumed jti is remembered; must exceed MAX_TTL + CLOCK_SKEW. */
	const JTI_TTL = 600;
	const JTI_TRANSIENT_PREFIX = 'lalia_erase_jti_';

	/** Event name in the Portal SSO log table (Lalia → Portal SSO Logs). */
	const LOG_EVENT = 'erase';

	const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	private static $booted = false;

	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function is_enabled() {
		return get_option( self::OPTION_ENABLED, 'no' ) === 'yes';
	}

	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_request' ),
				// Authentication is the bearer token, verified in the callback;
				// there is no WordPress user on this request.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Roles that are never deleted through this endpoint. The filter can only
	 * ADD roles — the base list stays protected whatever it returns.
	 *
	 * @return string[]
	 */
	public static function protected_roles() {
		$base  = array( 'administrator', 'shop_manager', 'editor', 'author', 'contributor' );
		$extra = apply_filters( 'lalia_portal_erasure_protected_roles', array() );
		$extra = is_array( $extra ) ? array_filter( array_map( 'strval', $extra ) ) : array();
		return array_values( array_unique( array_merge( $base, $extra ) ) );
	}

	/**
	 * @param WP_User $user
	 * @return bool
	 */
	public static function is_protected( $user ) {
		$roles = is_array( $user->roles ) ? $user->roles : array();
		if ( array_intersect( self::protected_roles(), $roles ) ) {
			return true;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return true;
		}
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return true;
		}
		return false;
	}

	// ── token ────────────────────────────────────────────────────────────────

	/** The shared secret (the Portal SSO option), or '' when unset. */
	public static function secret() {
		if ( class_exists( 'Lalia_Portal_SSO_Token' ) ) {
			return Lalia_Portal_SSO_Token::secret();
		}
		$secret = get_option( 'lalia_portal_sso_secret', '' );
		return is_string( $secret ) ? $secret : '';
	}

	/**
	 * Pure claim validation — no WordPress calls, no clock: `$now` is passed
	 * in so the rules are testable. Signature and alg are decode()'s job.
	 *
	 * Error codes name the failing rule (iss, aud, sub, email, jti, iat, exp,
	 * ttl, not_yet_valid, expired); the endpoint collapses them all to
	 * `invalid_token` for the caller and only logs the reason.
	 *
	 * @param array $claims Decoded JWT payload.
	 * @param int   $now    Unix seconds.
	 * @return true|WP_Error
	 */
	public static function validate_claims( array $claims, $now ) {
		$now = (int) $now;
		if ( ! isset( $claims['iss'] ) || self::ISSUER !== $claims['iss'] ) {
			return self::claim_error( 'iss', 'Unexpected issuer.' );
		}
		if ( ! isset( $claims['aud'] ) || self::AUDIENCE !== $claims['aud'] ) {
			return self::claim_error( 'aud', 'Unexpected audience.' );
		}
		// sub: the WordPress user id as a string, or '' for an e-mail-only lookup.
		$sub = isset( $claims['sub'] ) ? $claims['sub'] : '';
		if ( is_int( $sub ) ) {
			$sub = (string) $sub;
		}
		if ( ! is_string( $sub ) || ( '' !== $sub && ! preg_match( '/^[1-9][0-9]{0,18}$/', $sub ) ) ) {
			return self::claim_error( 'sub', 'sub must be a positive integer user id or empty.' );
		}
		$email = isset( $claims['email'] ) ? $claims['email'] : null;
		if ( ! is_string( $email ) || '' === trim( $email ) || false === strpos( $email, '@' ) || preg_match( '/\s/', $email ) || strlen( $email ) > 254 ) {
			return self::claim_error( 'email', 'email is required.' );
		}
		$jti = isset( $claims['jti'] ) ? $claims['jti'] : null;
		if ( ! is_string( $jti ) || ! preg_match( self::UUID_PATTERN, $jti ) ) {
			return self::claim_error( 'jti', 'jti must be a UUID.' );
		}
		$iat = isset( $claims['iat'] ) ? $claims['iat'] : null;
		$exp = isset( $claims['exp'] ) ? $claims['exp'] : null;
		if ( ! self::is_timestamp( $iat ) ) {
			return self::claim_error( 'iat', 'iat is required.' );
		}
		if ( ! self::is_timestamp( $exp ) ) {
			return self::claim_error( 'exp', 'exp is required.' );
		}
		$iat = (int) $iat;
		$exp = (int) $exp;
		if ( $exp <= $iat || $exp - $iat > self::MAX_TTL ) {
			return self::claim_error( 'ttl', 'exp - iat must be within (0, ' . self::MAX_TTL . '] seconds.' );
		}
		if ( $iat > $now + self::CLOCK_SKEW ) {
			return self::claim_error( 'not_yet_valid', 'Token is not valid yet.' );
		}
		if ( $exp < $now - self::CLOCK_SKEW ) {
			return self::claim_error( 'expired', 'Token has expired.' );
		}
		return true;
	}

	private static function is_timestamp( $value ) {
		return ( is_int( $value ) || is_float( $value ) ) && is_finite( $value ) && $value > 0;
	}

	private static function claim_error( $reason, $message ) {
		return new WP_Error( $reason, $message, array( 'status' => 401 ) );
	}

	/**
	 * Verify signature + structure and return the claims. HS256 is pinned —
	 * the header's alg is never trusted. php-jwt's leeway (global state shared
	 * with the other JWT-consuming modules) is raised to CLOCK_SKEW for this
	 * call only and restored afterwards.
	 *
	 * @param string $jwt
	 * @return array|WP_Error
	 */
	public static function decode( $jwt ) {
		$secret = self::secret();
		if ( '' === $secret ) {
			return new WP_Error( 'no_secret', 'Portal SSO secret is not configured.', array( 'status' => 503 ) );
		}
		if ( ! class_exists( '\Firebase\JWT\JWT' ) ) {
			return new WP_Error( 'no_jwt_lib', 'JWT library not loaded.', array( 'status' => 503 ) );
		}
		$leeway                    = \Firebase\JWT\JWT::$leeway;
		\Firebase\JWT\JWT::$leeway = self::CLOCK_SKEW;
		// \Throwable, not \Exception: see Lalia_Portal_SSO_Token::decode().
		try {
			$decoded = \Firebase\JWT\JWT::decode( (string) $jwt, new \Firebase\JWT\Key( $secret, 'HS256' ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'invalid_token', $e->getMessage(), array( 'status' => 401 ) );
		} finally {
			\Firebase\JWT\JWT::$leeway = $leeway;
		}
		$claims = json_decode( wp_json_encode( $decoded ), true );
		return is_array( $claims ) ? $claims : new WP_Error( 'invalid_token', 'Malformed claims', array( 'status' => 401 ) );
	}

	/**
	 * The token from `Authorization: Bearer …`. Some Apache/CGI hosts strip
	 * the Authorization header before PHP sees it; the same token may then be
	 * sent as the JSON body field `token` instead.
	 *
	 * @param WP_REST_Request $request
	 * @return string '' when absent.
	 */
	private static function bearer_token( $request ) {
		$header = trim( (string) $request->get_header( 'authorization' ) );
		if ( '' !== $header && 0 === stripos( $header, 'Bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}
		$param = $request->get_param( 'token' );
		return is_string( $param ) ? trim( $param ) : '';
	}

	// ── endpoint ─────────────────────────────────────────────────────────────

	/**
	 * POST /lalia/v1/portal/erase-user
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_request( $request ) {
		if ( '' === self::secret() ) {
			self::log( 0, 'failed', 'no_secret' );
			return self::error( 'no_secret', 'Portal SSO secret is not configured.', 503 );
		}
		$token = self::bearer_token( $request );
		if ( '' === $token ) {
			self::log( 0, 'failed', 'invalid_token: missing bearer token' );
			return self::error( 'invalid_token', 'Missing bearer token.', 401 );
		}
		$claims = self::decode( $token );
		if ( is_wp_error( $claims ) ) {
			self::log( 0, 'failed', $claims->get_error_code() . ': ' . $claims->get_error_message() );
			if ( 'invalid_token' === $claims->get_error_code() ) {
				return self::error( 'invalid_token', 'Invalid token.', 401 );
			}
			return $claims;
		}
		$valid = self::validate_claims( $claims, time() );
		if ( is_wp_error( $valid ) ) {
			self::log( 0, 'failed', 'invalid_token (' . $valid->get_error_code() . '): ' . $valid->get_error_message() );
			return self::error( 'invalid_token', 'Invalid token.', 401 );
		}
		$jti   = strtolower( (string) $claims['jti'] );
		$email = strtolower( trim( (string) $claims['email'] ) );
		$sub   = isset( $claims['sub'] ) ? trim( (string) $claims['sub'] ) : '';

		if ( ! self::consume_jti( $jti ) ) {
			self::log( 0, 'failed', 'replayed (jti ' . $jti . ')' );
			return self::error( 'replayed', 'This token has already been used.', 409 );
		}

		$user = '' === $sub ? get_user_by( 'email', $email ) : get_user_by( 'id', (int) $sub );
		if ( ! $user ) {
			// Idempotent: the ERP treats "already gone" as done.
			self::log( '' === $sub ? 0 : (int) $sub, 'success', 'not_found (jti ' . $jti . ')' );
			return self::response(
				array(
					'ok'      => true,
					'deleted' => false,
					'reason'  => 'not_found',
				)
			);
		}
		if ( strtolower( trim( (string) $user->user_email ) ) !== $email ) {
			self::log( $user->ID, 'failed', 'email_mismatch (jti ' . $jti . ')' );
			return self::error( 'email_mismatch', 'The account e-mail does not match the token.', 409 );
		}
		if ( self::is_protected( $user ) ) {
			self::log( $user->ID, 'failed', 'protected_role: ' . implode( ',', (array) $user->roles ) . ' (jti ' . $jti . ')' );
			return self::error( 'protected_role', 'This account cannot be deleted through the erasure endpoint.', 403 );
		}

		$user_id = (int) $user->ID;
		if ( ! self::delete_user( $user_id ) ) {
			self::log( $user_id, 'failed', 'delete_failed (jti ' . $jti . ')' );
			return self::error( 'delete_failed', 'WordPress could not delete the user.', 500 );
		}
		// The user row is gone, so the logger cannot resolve the e-mail itself —
		// and it must not: the point of the request was to stop holding it.
		self::log( $user_id, 'success', 'deleted wp_user_id=' . $user_id . ' (jti ' . $jti . ')' );
		return self::response(
			array(
				'ok'         => true,
				'deleted'    => true,
				'wp_user_id' => $user_id,
			)
		);
	}

	/** First use of a jti wins; every later one within JTI_TTL is a replay. */
	private static function consume_jti( $jti ) {
		$key = self::JTI_TRANSIENT_PREFIX . $jti;
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, time(), self::JTI_TTL );
		return true;
	}

	private static function delete_user( $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
			return (bool) wpmu_delete_user( $user_id );
		}
		// No reassignment: a customer authors nothing (the content roles are
		// protected above). WooCommerce keeps the orders — see the file header.
		return (bool) wp_delete_user( $user_id );
	}

	private static function error( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => (int) $status ) );
	}

	private static function response( array $data ) {
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Portal SSO log table when the logger is present and enabled, otherwise
	 * the PHP error log. Never token material.
	 */
	private static function log( $user_id, $status, $message ) {
		if ( class_exists( 'Lalia_Portal_SSO_Logger' ) && Lalia_Portal_SSO_Logger::logging_enabled() ) {
			Lalia_Portal_SSO_Logger::get_instance()->log( (int) $user_id, self::LOG_EVENT, $status, $message );
			return;
		}
		error_log( sprintf( '[lalia-portal-erasure] %s user_id=%d %s', $status, (int) $user_id, $message ) );
	}
}
