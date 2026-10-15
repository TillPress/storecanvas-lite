<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global option groups (CPT sclt_option_group).
 *
 * Uninstall keeps CPT posts (store content the merchant created).
 */
class SCLT_Option_Groups {

	const META_FIELDS   = '_sclt_group_fields';
	const META_PRODUCTS = '_sclt_group_product_ids';
	const META_CATS     = '_sclt_group_category_ids';
	const TRANSIENT     = 'sclt_option_groups_map_v1';

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
		add_action( 'save_post_' . SCLT_Plugin::CPT_OPTION_GROUP, array( $this, 'save' ), 10, 2 );
	}

	public function register_cpt() {
		register_post_type(
			SCLT_Plugin::CPT_OPTION_GROUP,
			array(
				'labels'              => array(
					'name'          => __( 'Option groups', 'storecanvas-lite' ),
					'singular_name' => __( 'Option group', 'storecanvas-lite' ),
					'add_new_item'  => __( 'Add option group', 'storecanvas-lite' ),
					'edit_item'     => __( 'Edit option group', 'storecanvas-lite' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=product',
				'capability_type'     => 'product',
				'map_meta_cap'        => true,
				'supports'            => array( 'title' ),
				'has_archive'         => false,
				'exclude_from_search' => true,
			)
		);
	}

	public function meta_boxes() {
		add_meta_box(
			'sclt_option_group_fields',
			__( 'Group fields & assignment', 'storecanvas-lite' ),
			array( $this, 'render_meta' ),
			SCLT_Plugin::CPT_OPTION_GROUP,
			'normal',
			'high'
		);
	}

	public function render_meta( $post ) {
		wp_nonce_field( 'sclt_option_group_save', 'sclt_option_group_nonce' );
		$sclt_fields = get_post_meta( $post->ID, self::META_FIELDS, true );
		$sclt_fields = is_array( $sclt_fields ) ? $sclt_fields : array();
		$sclt_fields = SCLT_Product_Options::stamp_stock_orig( $sclt_fields );
		$sclt_prods  = get_post_meta( $post->ID, self::META_PRODUCTS, true );
		$sclt_prods  = is_array( $sclt_prods ) ? implode( ',', array_map( 'absint', $sclt_prods ) ) : '';
		$sclt_cats   = get_post_meta( $post->ID, self::META_CATS, true );
		$sclt_cats   = is_array( $sclt_cats ) ? $sclt_cats : array();
		$sclt_terms  = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $sclt_terms ) ) {
			$sclt_terms = array();
		}

		echo '<p class="description">' . esc_html__( 'Fields use the same JSON shape as product option fields. Assign to products and/or categories. Product-local fields with the same id override group fields.', 'storecanvas-lite' ) . '</p>';
		echo '<p><label><strong>' . esc_html__( 'Fields JSON', 'storecanvas-lite' ) . '</strong></label><br />';
		echo '<textarea name="sclt_group_fields_json" class="large-text code" rows="12">' . esc_textarea( wp_json_encode( $sclt_fields, JSON_PRETTY_PRINT ) ) . '</textarea></p>';
		echo '<input type="hidden" name="sclt_group_stock_orig_json" value="' . esc_attr( wp_json_encode( SCLT_Product_Options::stock_orig_map( $sclt_fields ) ) ) . '" />';
		echo '<p><label><strong>' . esc_html__( 'Product IDs (comma-separated)', 'storecanvas-lite' ) . '</strong></label><br />';
		echo '<input type="text" class="large-text" name="sclt_group_product_ids" value="' . esc_attr( $sclt_prods ) . '" placeholder="12, 34, 56" /></p>';
		echo '<p><strong>' . esc_html__( 'Product categories', 'storecanvas-lite' ) . '</strong></p><div style="max-height:160px;overflow:auto;border:1px solid #ddd;padding:8px;">';
		foreach ( $sclt_terms as $sclt_term ) {
			echo '<label style="display:block;"><input type="checkbox" name="sclt_group_cats[]" value="' . esc_attr( (string) $sclt_term->term_id ) . '" ' . checked( in_array( (int) $sclt_term->term_id, array_map( 'intval', $sclt_cats ), true ), true, false ) . ' /> ' . esc_html( $sclt_term->name ) . '</label>';
		}
		if ( ! $sclt_terms ) {
			echo '<em>' . esc_html__( 'No product categories.', 'storecanvas-lite' ) . '</em>';
		}
		echo '</div>';
	}

	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['sclt_option_group_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sclt_option_group_nonce'] ) ), 'sclt_option_group_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array();
		if ( isset( $_POST['sclt_group_fields_json'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['sclt_group_fields_json'] ), true ); // phpcs:ignore
			if ( is_array( $decoded ) ) {
				// Accept either bare array of fields or {fields:[]}.
				$list = isset( $decoded['fields'] ) && is_array( $decoded['fields'] ) ? $decoded['fields'] : $decoded;
				foreach ( $list as $f ) {
					if ( is_array( $f ) ) {
						$fields[] = SCLT_Product_Options::sanitize_field_row( $f );
					}
				}
			}
		}
		$orig_map = array();
		if ( isset( $_POST['sclt_group_stock_orig_json'] ) ) {
			$decoded_orig = json_decode( wp_unslash( $_POST['sclt_group_stock_orig_json'] ), true ); // phpcs:ignore
			if ( is_array( $decoded_orig ) ) {
				$orig_map = $decoded_orig;
			}
		}
		SCLT_Product_Options::save_option_fields_cas( $post_id, self::META_FIELDS, $fields, $orig_map, false );

		$prod_ids = array();
		if ( ! empty( $_POST['sclt_group_product_ids'] ) ) {
			foreach ( explode( ',', sanitize_text_field( wp_unslash( $_POST['sclt_group_product_ids'] ) ) ) as $pid ) {
				$pid = absint( $pid );
				if ( $pid ) {
					$prod_ids[] = $pid;
				}
			}
		}
		update_post_meta( $post_id, self::META_PRODUCTS, array_values( array_unique( $prod_ids ) ) );

		$cat_ids = array();
		if ( ! empty( $_POST['sclt_group_cats'] ) && is_array( $_POST['sclt_group_cats'] ) ) {
			foreach ( $_POST['sclt_group_cats'] as $cid ) { // phpcs:ignore
				$cat_ids[] = absint( $cid );
			}
		}
		update_post_meta( $post_id, self::META_CATS, array_values( array_unique( $cat_ids ) ) );

		self::invalidate_cache();
	}

	public static function invalidate_cache() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * All published groups with assignment meta.
	 *
	 * @return array[]
	 */
	public static function all_groups() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$posts = get_posts(
			array(
				'post_type'      => SCLT_Plugin::CPT_OPTION_GROUP,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$fields = get_post_meta( $p->ID, self::META_FIELDS, true );
			$out[]  = array(
				'id'           => $p->ID,
				'title'        => $p->post_title,
				'fields'       => is_array( $fields ) ? $fields : array(),
				'product_ids'  => array_map( 'intval', (array) get_post_meta( $p->ID, self::META_PRODUCTS, true ) ),
				'category_ids' => array_map( 'intval', (array) get_post_meta( $p->ID, self::META_CATS, true ) ),
			);
		}
		set_transient( self::TRANSIENT, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Groups applicable to a product (category then product assignment order).
	 *
	 * @param int $product_id Product ID.
	 * @return array[]
	 */
	public static function groups_for_product( $product_id ) {
		$product_id = absint( $product_id );
		$cat_ids    = function_exists( 'wc_get_product_term_ids' ) ? wc_get_product_term_ids( $product_id, 'product_cat' ) : array();
		$explicit   = get_post_meta( $product_id, SCLT_Plugin::META_OPTION_GROUPS, true );
		$explicit   = is_array( $explicit ) ? array_map( 'intval', array_filter( $explicit ) ) : array();

		$by_cat  = array();
		$by_prod = array();
		foreach ( self::all_groups() as $g ) {
			$gid = (int) $g['id'];
			if ( array_intersect( $cat_ids, (array) $g['category_ids'] ) ) {
				$by_cat[ $gid ] = $g;
			}
			if ( in_array( $product_id, (array) $g['product_ids'], true ) ) {
				$by_prod[ $gid ] = $g;
			}
		}

		// Category first, then product assignment.
		$merged = $by_cat + $by_prod;

		// Product multi-select: also force-include those groups (and if only multi-select used, still include them).
		if ( $explicit ) {
			foreach ( self::all_groups() as $g ) {
				if ( in_array( (int) $g['id'], $explicit, true ) ) {
					$merged[ (int) $g['id'] ] = $g;
				}
			}
		}

		return array_values( $merged );
	}
}
