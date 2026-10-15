<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin helpers and constants for meta keys.
 *
 * Product meta:
 * - META_OPTIONS       (_sclt_options)          — option field definitions
 * - META_OPTION_GROUPS (_sclt_option_group_ids) — assigned global group IDs
 *
 * Cart / order item keys (also used as order item meta where applicable):
 * - CART_OPTIONS (sclt_options)
 * - sclt_option_labels, sclt_price_extra (see SCLT_Cart_Order)
 */
class SCLT_Plugin {

	const META_OPTIONS       = '_sclt_options';
	const META_OPTION_GROUPS = '_sclt_option_group_ids'; // product: assigned global group IDs
	const CPT_OPTION_GROUP   = 'sclt_option_group';

	/** Cart/order item keys */
	const CART_OPTIONS = 'sclt_options';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
	}

	public static function empty_options() {
		return array(
			'fields' => array(),
		);
	}

	public function enqueue_front() {
		if ( ! is_product() ) {
			return;
		}
		global $product;
		$product_id = 0;
		if ( $product instanceof WC_Product ) {
			$product_id = $product->get_id();
		} elseif ( get_the_ID() ) {
			$product_id = (int) get_the_ID();
		}

		wp_enqueue_style(
			'sclt-front',
			SCLT_PLUGIN_URL . 'assets/admin.css',
			array(),
			SCLT_VERSION
		);
		wp_enqueue_style( 'sclt-options', SCLT_PLUGIN_URL . 'assets/options.css', array(), SCLT_VERSION );

		// Live price preview for options pricing.
		$pricing_fields = $product_id ? SCLT_Product_Options::pricing_config_for_product( $product_id ) : array();
		if ( $pricing_fields ) {
			$wc_product = $product_id ? wc_get_product( $product_id ) : null;
			$base       = $wc_product ? (float) $wc_product->get_price() : 0.0;
			wp_enqueue_script(
				'sclt-live-price',
				SCLT_PLUGIN_URL . 'assets/live-price.js',
				array(),
				SCLT_VERSION,
				true
			);
			wp_localize_script(
				'sclt-live-price',
				'scltLivePrice',
				array(
					'productId'   => $product_id,
					'basePrice'   => $base,
					'fields'      => $pricing_fields,
					'symbol'      => get_woocommerce_currency_symbol(),
					'currencyPos' => get_option( 'woocommerce_currency_pos', 'left' ),
					'decimals'    => wc_get_price_decimals(),
					'i18n'        => array(
						'base'   => __( 'Base', 'storecanvas-lite' ),
						'extras' => __( 'options', 'storecanvas-lite' ),
						'line'   => __( 'line', 'storecanvas-lite' ),
					),
				)
			);
		}
	}

	public function enqueue_admin( $hook ) {
		global $post;
		$on_group_screen = is_string( $hook ) && false !== strpos( $hook, self::CPT_OPTION_GROUP );
		$on_product      = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
			&& $post && 'product' === get_post_type( $post );

		if ( ! $on_group_screen && ! $on_product ) {
			return;
		}

		wp_enqueue_style( 'sclt-admin', SCLT_PLUGIN_URL . 'assets/admin.css', array(), SCLT_VERSION );

		if ( ! $on_product ) {
			return;
		}
		wp_enqueue_script( 'sclt-admin', SCLT_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), SCLT_VERSION, true );
		wp_add_inline_script(
			'sclt-admin',
			'window.scltBuilderTypes = ' . wp_json_encode(
				array(
					'fields' => SCLT_Product_Options::field_types(),
					'prices' => SCLT_Product_Options::price_types(),
				)
			) . ';',
			'before'
		);
	}
}
