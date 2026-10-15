<?php
/**
 * Demo data for StoreCanvas Lite: one hidden product with options + one option group.
 *
 * @package StoreCanvasLite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Demo product create/remove + report lines.
 */
class SCLT_Demo {

	const OPTION = 'sclt_demo_ids';
	const META   = '_sclt_demo';

	/**
	 * Stored demo object IDs.
	 *
	 * @return array{products:int[],groups:int[]}
	 */
	public static function ids() {
		$ids = get_option( self::OPTION, array() );
		$ids = is_array( $ids ) ? $ids : array();
		return array(
			'products' => array_values( array_filter( array_map( 'absint', (array) ( $ids['products'] ?? array() ) ) ) ),
			'groups'   => array_values( array_filter( array_map( 'absint', (array) ( $ids['groups'] ?? array() ) ) ) ),
		);
	}

	/**
	 * Whether demo data exists.
	 *
	 * @return bool
	 */
	public static function exists() {
		$ids = self::ids();
		foreach ( $ids['products'] as $id ) {
			if ( get_post( $id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Demo field definitions (raw; sanitized on save). Pure.
	 *
	 * @return array[]
	 */
	public static function product_fields() {
		return array(
			array(
				'id'    => 'demo_heading',
				'type'  => 'heading',
				'label' => 'Personalise your mug',
			),
			array(
				'id'         => 'demo_size',
				'type'       => 'select',
				'label'      => 'Size',
				'required'   => 1,
				'price_type' => 'lookup',
				'choices'    => array(
					array(
						'value' => 'small',
						'label' => '11 oz',
						'price' => 0,
					),
					array(
						'value' => 'large',
						'label' => '15 oz',
						'price' => 4,
					),
				),
			),
			array(
				'id'         => 'demo_colour',
				'type'       => 'radio',
				'label'      => 'Glaze (option stock: White is sold out)',
				'required'   => 1,
				'price_type' => 'none',
				'choices'    => array(
					array(
						'value'     => 'black',
						'label'     => 'Black',
						'stock_qty' => 5,
					),
					array(
						'value'     => 'white',
						'label'     => 'White',
						'stock_qty' => 0,
					),
				),
			),
			array(
				'id'         => 'demo_engraving',
				'type'       => 'text',
				'label'      => 'Engraving ($0.25 per character, max 20)',
				'price_type' => 'per_char',
				'price'      => 0.25,
				'max_chars'  => 20,
			),
			array(
				'id'         => 'demo_gift',
				'type'       => 'checkbox',
				'label'      => 'Gift wrap (+$3)',
				'price_type' => 'flat',
				'price'      => 3,
			),
			array(
				'id'         => 'demo_gift_note',
				'type'       => 'textarea',
				'label'      => 'Gift note (shows only when Gift wrap is ticked)',
				'price_type' => 'none',
				'max_chars'  => 140,
				'show_if'    => array(
					'field' => 'demo_gift',
					'value' => '1',
				),
			),
		);
	}

	/**
	 * Demo option group fields (raw). Pure.
	 *
	 * @return array[]
	 */
	public static function group_fields() {
		return array(
			array(
				'id'         => 'demo_speed',
				'type'       => 'radio',
				'label'      => 'Production speed (from the reusable option group)',
				'required'   => 1,
				'price_type' => 'lookup',
				'choices'    => array(
					array(
						'value' => 'standard',
						'label' => 'Standard (5 days)',
						'price' => 0,
					),
					array(
						'value' => 'rush',
						'label' => 'Rush (2 days)',
						'price' => 6,
					),
				),
			),
		);
	}

	/**
	 * Sanitize a list of raw fields through the plugin's own sanitizer.
	 *
	 * @param array[] $fields Raw fields.
	 * @return array[]
	 */
	private static function clean( $fields ) {
		return array_values( array_filter( array_map( array( 'SCLT_Product_Options', 'sanitize_field_row' ), $fields ) ) );
	}

	/**
	 * Create the demo product and option group.
	 *
	 * @return int|WP_Error Product ID.
	 */
	public static function create() {
		if ( ! class_exists( 'WC_Product_Simple' ) || ! class_exists( 'SCLT_Product_Options' ) ) {
			return new WP_Error( 'sclt_demo_no_wc', 'WooCommerce is not active.' );
		}
		if ( self::exists() ) {
			$ids = self::ids();
			return $ids['products'][0];
		}
		$product = new WC_Product_Simple();
		$product->set_name( __( 'Demo: Engraved mug (StoreCanvas Lite)', 'storecanvas-lite' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( '24' );
		$product->set_short_description( __( 'Demo product created by StoreCanvas Lite. It is hidden from your shop and search. Remove it from Products > StoreCanvas Lite help.', 'storecanvas-lite' ) );
		$product->update_meta_data( self::META, 1 );
		$product_id = $product->save();
		if ( ! $product_id ) {
			return new WP_Error( 'sclt_demo_product', 'Could not create the demo product.' );
		}
		update_post_meta( $product_id, SCLT_Plugin::META_OPTIONS, array( 'fields' => self::clean( self::product_fields() ) ) );

		$group_id = wp_insert_post(
			array(
				'post_type'   => SCLT_Plugin::CPT_OPTION_GROUP,
				'post_status' => 'publish',
				'post_title'  => __( 'Demo: Production speed', 'storecanvas-lite' ),
			),
			true
		);
		$groups = array();
		if ( ! is_wp_error( $group_id ) && $group_id ) {
			update_post_meta( $group_id, SCLT_Option_Groups::META_FIELDS, self::clean( self::group_fields() ) );
			update_post_meta( $group_id, SCLT_Option_Groups::META_PRODUCTS, array( (int) $product_id ) );
			update_post_meta( $group_id, SCLT_Option_Groups::META_CATS, array() );
			update_post_meta( $group_id, self::META, 1 );
			$groups[] = (int) $group_id;
		}
		SCLT_Option_Groups::invalidate_cache();

		update_option(
			self::OPTION,
			array(
				'products' => array( (int) $product_id ),
				'groups'   => $groups,
			),
			false
		);
		return (int) $product_id;
	}

	/**
	 * Delete demo data (only posts carrying the demo marker).
	 */
	public static function remove() {
		$ids = self::ids();
		foreach ( $ids['products'] as $id ) {
			if ( get_post_meta( $id, self::META, true ) ) {
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
				if ( $product ) {
					$product->delete( true );
				} else {
					wp_delete_post( $id, true );
				}
			}
		}
		foreach ( $ids['groups'] as $id ) {
			if ( get_post_meta( $id, self::META, true ) ) {
				wp_delete_post( $id, true );
			}
		}
		if ( class_exists( 'SCLT_Option_Groups' ) ) {
			SCLT_Option_Groups::invalidate_cache();
		}
		delete_option( self::OPTION );
	}

	/**
	 * Links shown when demo data exists.
	 *
	 * @return array[]
	 */
	public static function links() {
		$out = array();
		$ids = self::ids();
		foreach ( $ids['products'] as $id ) {
			if ( get_post( $id ) ) {
				$out[] = array(
					'label'   => __( 'View the demo product page (live price, show-if, option stock)', 'storecanvas-lite' ),
					'url'     => get_permalink( $id ),
					'new_tab' => true,
				);
				$out[] = array(
					'label' => __( 'Edit its fields in the StoreCanvas Lite product tab', 'storecanvas-lite' ),
					'url'   => get_edit_post_link( $id, 'raw' ),
				);
			}
		}
		foreach ( $ids['groups'] as $id ) {
			if ( get_post( $id ) ) {
				$out[] = array(
					'label' => __( 'Edit the reusable option group', 'storecanvas-lite' ),
					'url'   => get_edit_post_link( $id, 'raw' ),
				);
			}
		}
		return $out;
	}

	/**
	 * No extra demo output.
	 */
	public static function render_extra() {
		echo '<p class="description">' . esc_html__( 'Try it: pick 15 oz (+$4), type an engraving, tick Gift wrap to reveal the gift note, choose Rush (+$6), then add to cart. The cart, checkout, emails, and order screen show every choice.', 'storecanvas-lite' ) . '</p>';
	}

	/**
	 * Diagnostics for the support report.
	 *
	 * @return array
	 */
	public static function report_lines() {
		$products     = wp_count_posts( 'product' );
		$product_total = $products ? (int) ( $products->publish ?? 0 ) + (int) ( $products->private ?? 0 ) + (int) ( $products->draft ?? 0 ) : 0;
		$groups = class_exists( 'SCLT_Option_Groups' ) ? SCLT_Option_Groups::all_groups() : array();
		return array(
			'Products (published/private/draft)' => $product_total,
			'Option groups'                      => count( $groups ),
			'Paid StoreCanvas active'            => function_exists( 'sclt_pro_is_active' ) ? sclt_pro_is_active() : false,
			'Demo data present'                  => self::exists(),
		);
	}
}
