<?php
/**
 * WC → Stripe: inject LALIA package_id into PaymentIntent metadata.
 *
 * The LALIA ERP `stripe-sales-event-worker` resolves
 * `purchases.package_id` from `pi.metadata.package_id` directly when
 * present (priority-1 path, added in PR #168). WooCommerce checkouts
 * don't use Stripe Products/Prices, so without this filter the worker
 * leaves `purchases.payment_status='draft_pending_resolution'`, the
 * Lexoffice push is skipped, and no customer invoice email goes out.
 *
 * This module reads a per-product `_lalia_package_id` post meta value
 * and copies it into the PaymentIntent metadata at checkout via the
 * standard `wc_stripe_payment_intent_metadata` filter exposed by the
 * official WooCommerce Stripe Gateway plugin.
 *
 * Setup: in WP Admin → Products → (each product) → Custom Fields,
 * add `_lalia_package_id` with the LALIA package UUID.
 *
 * Single-package LALIA carts only: multi-line-item orders take the
 * first item's value and log a notice for the rest (the LALIA worker
 * currently models one purchase row per Stripe charge).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'LaliaStripePackageId' ) ) {
	class LaliaStripePackageId {

		const PRODUCT_META_KEY = '_lalia_package_id';

		public function __construct() {
			// We're instantiated from Lalia_Plugin::bootstrap(), which
			// itself runs on `plugins_loaded`. By the time bootstrap
			// fires, every active plugin's main file has already been
			// loaded (WordPress loads all plugin files BEFORE firing
			// `plugins_loaded`), so WooCommerce's class is defined here.
			// Register hooks directly — adding another `plugins_loaded`
			// callback from within `plugins_loaded` is the footgun that
			// previously left the field unrendered on the product edit
			// screen (incident 2026-05-21).
			if ( ! class_exists( 'WooCommerce' ) ) {
				return;
			}

			add_filter(
				'wc_stripe_payment_intent_metadata',
				array( $this, 'inject_package_id' ),
				10,
				2
			);

			// Surface the custom field on the product edit screen so ops
			// doesn't have to enable the WP "Custom Fields" meta-box.
			add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_field' ) );
			add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_field' ) );
		}

		/**
		 * @param array         $metadata  Existing PI metadata supplied by the Stripe plugin.
		 * @param \WC_Order|null $order    Order being paid for. Null for non-order intents (e.g. saved-card setup).
		 * @return array
		 */
		public function inject_package_id( $metadata, $order ) {
			if ( ! ( $order instanceof WC_Order ) ) {
				return $metadata;
			}

			$items = $order->get_items();
			if ( count( $items ) === 0 ) {
				return $metadata;
			}
			if ( count( $items ) > 1 ) {
				error_log(
					sprintf(
						'[lalia-stripe-package-id] order %d has %d line items; only the first will map to a LALIA package',
						$order->get_id(),
						count( $items )
					)
				);
			}

			$first = reset( $items );
			if ( ! method_exists( $first, 'get_product' ) ) {
				return $metadata;
			}
			$product = $first->get_product();
			if ( ! $product ) {
				return $metadata;
			}

			$pkg_id = get_post_meta( $product->get_id(), self::PRODUCT_META_KEY, true );
			if ( is_string( $pkg_id ) && $this->is_valid_identifier( $pkg_id ) ) {
				$metadata['package_id'] = $pkg_id;
			} elseif ( $pkg_id !== '' ) {
				// Non-empty but malformed — log so the ops team can spot a
				// typo at the source. Accept either a uuid or a slug-shaped
				// code (`package_types.code`); anything else gets dropped.
				error_log(
					sprintf(
						'[lalia-stripe-package-id] product %d has malformed _lalia_package_id %s; not forwarding',
						$product->get_id(),
						$pkg_id
					)
				);
			}

			return $metadata;
		}

		/** Product-edit UI: text input for the LALIA package type identifier. */
		public function render_product_field() {
			woocommerce_wp_text_input(
				array(
					'id'          => self::PRODUCT_META_KEY,
					'label'       => __( 'LALIA package type', 'lalia' ),
					'desc_tip'    => true,
					'description' => __( "Slug `code` (e.g. `gpa-30`) or UUID of the LALIA package_types row this product maps to. Forwarded to Stripe as pi.metadata.package_id so the ERP can credit the right package on purchase. Manage codes under LALIA Settings → Package Types.", 'lalia' ),
					'placeholder' => 'gpa-30',
				)
			);
		}

		/** Persist the LALIA package type identifier on product save. Empty string clears the mapping. */
		public function save_product_field( $product ) {
			if ( ! isset( $_POST[ self::PRODUCT_META_KEY ] ) ) {
				return;
			}
			$raw = sanitize_text_field( wp_unslash( $_POST[ self::PRODUCT_META_KEY ] ) );
			if ( $raw === '' || $this->is_valid_identifier( $raw ) ) {
				$product->update_meta_data( self::PRODUCT_META_KEY, $raw );
			}
		}

		/** Accepts either a UUID (package_types.id) or a slug code (package_types.code). */
		private function is_valid_identifier( $value ) {
			return is_string( $value ) && (
				(bool) preg_match(
					'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
					$value
				)
				|| (bool) preg_match(
					'/^[a-z0-9][a-z0-9_-]*$/',
					$value
				)
			);
		}
	}
}
