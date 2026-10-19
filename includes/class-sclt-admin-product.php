<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product edit: visual Options builder.
 */
class SCLT_Admin_Product {

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), 10, 2 );
	}

	public function add_tab( $tabs ) {
		$tabs['storecanvas-lite'] = array(
			'label'    => __( 'StoreCanvas Lite', 'storecanvas-lite' ),
			'target'   => 'sclt_product_data',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	public function panel() {
		global $post;
		$sclt_product_id  = $post ? (int) $post->ID : 0;
		// The builder edits ONLY this product's own fields. Group fields stay in their group (merged at
		// render/cart time), so saving a product never copies group fields into it.
		$sclt_options     = SCLT_Product_Options::get_local_config( $sclt_product_id );
		$sclt_inherited   = array();
		foreach ( (array) ( SCLT_Product_Options::get_config( $sclt_product_id )['fields'] ?? array() ) as $sclt_f ) {
			if ( ! empty( $sclt_f['_sc_group_id'] ) ) {
				$sclt_inherited[] = $sclt_f;
			}
		}
		$sclt_field_count = count( (array) ( $sclt_options['fields'] ?? array() ) ) + count( $sclt_inherited );

		?>
		<div id="sclt_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="form-field">
					<strong><?php esc_html_e( 'StoreCanvas Lite', 'storecanvas-lite' ); ?></strong>
					<span class="description"> — <?php esc_html_e( 'Product options: field types, pricing, conditional logic, option stock, and a customer design canvas.', 'storecanvas-lite' ); ?></span>
				</p>
				<p class="form-field sclt-status-line" style="padding-left:12px;">
					<code>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: option field count */
							__( 'Status: Options: %d fields', 'storecanvas-lite' ),
							(int) $sclt_field_count
						)
					);
					?>
					</code>
				</p>
			</div>

			<div class="options_group sclt-admin-section">
				<h4 style="padding-left:12px;"><?php esc_html_e( 'Global option groups', 'storecanvas-lite' ); ?></h4>
				<p class="form-field" style="padding-left:12px;">
					<span class="description"><?php esc_html_e( 'Groups assigned to this product or its category apply automatically (listed below). Tick a group here to add it to this product even when it is not assigned. A field on this product with the same ID replaces the group\'s field.', 'storecanvas-lite' ); ?></span>
				</p>
				<p class="form-field" style="padding-left:12px;max-height:160px;overflow:auto;">
					<?php
					$sclt_groups_all = class_exists( 'SCLT_Option_Groups' ) ? SCLT_Option_Groups::all_groups() : array();
					$sclt_groups_sel = get_post_meta( $sclt_product_id, SCLT_Plugin::META_OPTION_GROUPS, true );
					$sclt_groups_sel = is_array( $sclt_groups_sel ) ? array_map( 'intval', $sclt_groups_sel ) : array();
					if ( empty( $sclt_groups_all ) ) :
						?>
						<em><?php esc_html_e( 'No option groups yet.', 'storecanvas-lite' ); ?></em>
					<?php else : ?>
						<?php foreach ( $sclt_groups_all as $sclt_group ) : ?>
							<label style="display:block;margin:2px 0;">
								<input type="checkbox" name="sclt_option_group_ids[]" value="<?php echo esc_attr( (string) $sclt_group['id'] ); ?>" <?php checked( in_array( (int) $sclt_group['id'], $sclt_groups_sel, true ) ); ?> />
								<?php echo esc_html( $sclt_group['title'] ); ?>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</p>
			</div>

			<div class="options_group sclt-admin-section">
				<?php if ( $sclt_inherited ) : ?>
					<p class="form-field sclt-inherited" style="padding-left:12px;">
						<span class="description"><?php esc_html_e( 'Fields this product gets from option groups (edit them in the group, not here):', 'storecanvas-lite' ); ?></span><br />
						<?php foreach ( $sclt_inherited as $sclt_f ) : ?>
							<code><?php echo esc_html( $sclt_f['id'] ); ?></code> <?php echo esc_html( (string) ( $sclt_f['label'] ?? '' ) ); ?> &middot; <a href="<?php echo esc_url( (string) get_edit_post_link( (int) $sclt_f['_sc_group_id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $sclt_f['_sc_group_id'] ) ); ?></a><br />
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
				<h4 style="padding-left:12px;"><?php esc_html_e( 'Option fields (local)', 'storecanvas-lite' ); ?></h4>
				<p class="form-field" style="padding-left:12px;">
					<span class="description"><?php esc_html_e( 'Choices are comma-separated. Add =price for a per-choice price (Large=5) and @stock for a stock count (Large=5@20); leave @stock off for unlimited. Click "Add condition" to show a field only when other fields match: choose "all" to need every rule (AND) or "any" for one of them (OR). A ticked checkbox counts as 1. File upload fields let you pick the allowed file types and a size limit.', 'storecanvas-lite' ); ?></span>
				</p>
				<div id="sclt-fields-builder" class="sclt-builder" style="padding:0 12px 12px;">
					<p>
						<button type="button" class="button button-primary" id="sclt-add-field"><?php esc_html_e( 'Add field', 'storecanvas-lite' ); ?></button>
					</p>
					<div id="sclt-fields-list"></div>
				</div>
				<input type="hidden" name="sclt_options_fields_json" id="sclt_options_fields_json" value="<?php echo esc_attr( wp_json_encode( SCLT_Product_Options::stamp_stock_orig( (array) ( $sclt_options['fields'] ?? array() ) ) ) ); ?>" />
				<input type="hidden" name="sclt_options_stock_orig_json" id="sclt_options_stock_orig_json" value="<?php echo esc_attr( wp_json_encode( SCLT_Product_Options::stock_orig_map( (array) ( $sclt_options['fields'] ?? array() ) ) ) ); ?>" />
			</div>

			<?php
			if ( class_exists( 'SCLT_Canvas' ) ) {
				SCLT_Canvas::render_admin_section( $sclt_product_id );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Save handler on woocommerce_process_product_meta (nonce checked by WP core
	 * for the product edit screen before this action fires).
	 *
	 * @param int     $post_id Product ID.
	 * @param WP_Post $post    Product post object (unused; hook signature).
	 */
	public function save( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- runs on woocommerce_process_product_meta; core verifies the product-edit nonce before firing it.
		if ( isset( $_POST['sclt_options_fields_json'] ) ) {
			$decoded = json_decode( wp_unslash( $_POST['sclt_options_fields_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $f ) {
					if ( ! is_array( $f ) ) {
						continue;
					}
					$row = SCLT_Product_Options::sanitize_field_row( $f );
					if ( ! empty( $row['id'] ) ) {
						$fields[] = $row;
					}
				}
			}
		}
		$orig_map = array();
		if ( isset( $_POST['sclt_options_stock_orig_json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$decoded_orig = json_decode( wp_unslash( $_POST['sclt_options_stock_orig_json'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing
			if ( is_array( $decoded_orig ) ) {
				$orig_map = $decoded_orig;
			}
		}
		SCLT_Product_Options::save_option_fields_cas( $post_id, SCLT_Plugin::META_OPTIONS, $fields, $orig_map, true );

		$group_ids = array();
		if ( isset( $_POST['sclt_option_group_ids'] ) && is_array( $_POST['sclt_option_group_ids'] ) ) { // phpcs:ignore
			foreach ( $_POST['sclt_option_group_ids'] as $gid ) { // phpcs:ignore
				$group_ids[] = absint( $gid );
			}
		}
		update_post_meta( $post_id, SCLT_Plugin::META_OPTION_GROUPS, array_values( array_unique( array_filter( $group_ids ) ) ) );

		if ( class_exists( 'SCLT_Canvas' ) ) {
			SCLT_Canvas::save_settings( $post_id );
		}
	}
}
