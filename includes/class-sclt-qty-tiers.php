<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quantity tier pricing ("buy 10 or more, get 10% off each").
 *
 * Tiers live on the product (META). The tier is picked from the total quantity of that
 * product in the cart, across every line and variation, so a buyer ordering five small
 * and five large shirts gets the 10+ price. The discount applies to the whole unit price
 * (catalog price plus option charges) and is recomputed from the fresh catalog price on
 * every totals pass, so it never compounds.
 */
class SCLT_Qty_Tiers {

	const META      = '_sclt_qty_tiers';
	const CART_KEY  = 'sclt_qty_tier';
	const MAX_TIERS = 10;
	const MAX_MIN   = 100000;

	private static $instance = null;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Lite's own option repricing runs at 5; this runs after it so the discount sees the final unit price.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_to_cart' ), 6, 1 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_item_data' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'to_order_item' ), 10, 4 );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_table' ), 16 );
	}

	/* ---------- pure helpers ---------- */

	/**
	 * Clean tier rows from the admin form or stored meta (pure).
	 *
	 * Keeps rows with a minimum quantity of 2 or more and a positive amount; a percent
	 * discount is capped at 100. A repeated minimum keeps the last row. Sorted by minimum.
	 *
	 * @param mixed $rows Rows of array( 'min' => int, 'type' => 'percent'|'fixed', 'amount' => float ).
	 * @return array
	 */
	public static function sanitize_tiers( $rows ) {
		$by_min = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$min_raw = trim( (string) ( $row['min'] ?? '' ) );
			$amt_raw = trim( str_replace( ',', '.', (string) ( $row['amount'] ?? '' ) ) );
			if ( ! preg_match( '/^\d+$/', $min_raw ) || ! is_numeric( $amt_raw ) ) {
				continue;
			}
			$min    = (int) $min_raw;
			$amount = (float) $amt_raw;
			$type   = 'fixed' === ( $row['type'] ?? '' ) ? 'fixed' : 'percent';
			if ( $min < 2 || $min > self::MAX_MIN || ! is_finite( $amount ) || $amount <= 0 ) {
				continue;
			}
			if ( 'percent' === $type && $amount > 100 ) {
				$amount = 100.0;
			}
			$by_min[ $min ] = array(
				'min'    => $min,
				'type'   => $type,
				'amount' => round( $amount, 4 ),
			);
		}
		ksort( $by_min );
		return array_slice( array_values( $by_min ), 0, self::MAX_TIERS );
	}

	/**
	 * The tier that applies at this quantity: the one with the highest minimum not above it (pure).
	 *
	 * @param array $tiers Sanitized tiers.
	 * @param int   $qty   Quantity.
	 * @return array|null
	 */
	public static function tier_for( $tiers, $qty ) {
		$qty  = (int) $qty;
		$best = null;
		foreach ( (array) $tiers as $t ) {
			if ( is_array( $t ) && isset( $t['min'] ) && $qty >= (int) $t['min'] ) {
				$best = $t;
			}
		}
		return $best;
	}

	/**
	 * Unit price after a tier discount, never below zero (pure).
	 *
	 * @param float      $unit Unit price before the discount.
	 * @param array|null $tier Tier or null.
	 * @return float
	 */
	public static function discounted_unit( $unit, $tier ) {
		$unit = (float) $unit;
		if ( ! is_array( $tier ) ) {
			return $unit < 0 ? 0.0 : $unit;
		}
		$amount = (float) ( $tier['amount'] ?? 0 );
		if ( 'fixed' === ( $tier['type'] ?? '' ) ) {
			$unit -= $amount;
		} else {
			$unit -= $unit * ( min( 100.0, $amount ) / 100 );
		}
		return $unit < 0 ? 0.0 : $unit;
	}

	/**
	 * Total quantity per product ID across cart lines (pure). Variations count toward their parent.
	 *
	 * @param array $cart_items Cart items.
	 * @return array<int,int>
	 */
	public static function product_quantities( $cart_items ) {
		$out = array();
		foreach ( (array) $cart_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$pid = (int) ( $item['product_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			$out[ $pid ] = ( $out[ $pid ] ?? 0 ) + max( 0, (int) ( $item['quantity'] ?? 0 ) );
		}
		return $out;
	}

	/**
	 * Rows for the storefront table: quantity ranges with their discount (pure).
	 *
	 * @param array $tiers Sanitized tiers.
	 * @return array List of array( 'from' => int, 'to' => int|null, 'tier' => array|null ).
	 */
	public static function table_rows( $tiers ) {
		$tiers = array_values( (array) $tiers );
		if ( ! $tiers ) {
			return array();
		}
		$rows = array(
			array(
				'from' => 1,
				'to'   => (int) $tiers[0]['min'] - 1,
				'tier' => null,
			),
		);
		$n = count( $tiers );
		foreach ( $tiers as $i => $t ) {
			$rows[] = array(
				'from' => (int) $t['min'],
				'to'   => $i + 1 < $n ? (int) $tiers[ $i + 1 ]['min'] - 1 : null,
				'tier' => $t,
			);
		}
		return $rows;
	}

	/**
	 * Short label for a tier, e.g. "10% off" or "$2.00 off each".
	 *
	 * @param array $tier Tier.
	 * @return string
	 */
	public static function tier_label( $tier ) {
		$amount = (float) ( $tier['amount'] ?? 0 );
		if ( 'fixed' === ( $tier['type'] ?? '' ) ) {
			$money = function_exists( 'wc_price' ) ? html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) : number_format( $amount, 2 );
			/* translators: %s: money amount */
			return sprintf( __( '%s off each', 'storecanvas-lite' ), $money );
		}
		$pct = rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' );
		/* translators: %s: percentage number */
		return sprintf( __( '%s%% off', 'storecanvas-lite' ), $pct );
	}

	/* ---------- storage ---------- */

	/**
	 * @param int $product_id Product ID (a variation's parent is used).
	 * @return array
	 */
	public static function get_tiers( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return array();
		}
		return self::sanitize_tiers( get_post_meta( $product_id, self::META, true ) );
	}

	/* ---------- cart ---------- */

	/**
	 * Set each tiered line's unit price from the fresh catalog price, its option charges and its tier.
	 *
	 * @param mixed $cart WC_Cart.
	 */
	public function apply_to_cart( $cart ) {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		$items = $cart->get_cart();
		$qtys  = self::product_quantities( $items );
		$cache = array();
		foreach ( $items as $key => $item ) {
			$pid = (int) ( $item['product_id'] ?? 0 );
			if ( ! isset( $cache[ $pid ] ) ) {
				$cache[ $pid ] = self::get_tiers( $pid );
			}
			$updated = self::price_line( $item, $cache[ $pid ], $qtys[ $pid ] ?? 0 );
			if ( isset( $cart->cart_contents[ $key ] ) ) {
				$cart->cart_contents[ $key ] = $updated;
			}
		}
	}

	/**
	 * Price one cart line for its tier. Lines of products without tiers are left alone.
	 *
	 * @param array $item  Cart item.
	 * @param array $tiers Sanitized tiers for its product.
	 * @param int   $qty   Total quantity of its product in the cart.
	 * @return array
	 */
	public static function price_line( $item, $tiers, $qty ) {
		if ( ! is_array( $item ) ) {
			return $item;
		}
		unset( $item[ self::CART_KEY ] );
		if ( ! $tiers || empty( $item['data'] ) || ! is_object( $item['data'] ) || ! method_exists( $item['data'], 'set_price' ) ) {
			return $item;
		}
		$fresh = SCLT_Cart_Order::fresh_catalog_price( $item );
		if ( null === $fresh ) {
			return $item;
		}
		$extra = isset( $item['sclt_price_extra'] ) ? (float) $item['sclt_price_extra'] : 0.0;
		$unit  = SCLT_Cart_Order::line_price_from_base( (float) $fresh, $extra );
		$tier  = self::tier_for( $tiers, $qty );
		// Always written (even with no tier) so a quantity that drops below a tier is repriced up again.
		$item['data']->set_price( self::discounted_unit( $unit, $tier ) );
		if ( $tier ) {
			$item[ self::CART_KEY ] = $tier;
		}
		return $item;
	}

	public function display_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item[ self::CART_KEY ] ) && is_array( $cart_item[ self::CART_KEY ] ) ) {
			$tier        = $cart_item[ self::CART_KEY ];
			$item_data[] = array(
				'key'   => __( 'Quantity discount', 'storecanvas-lite' ),
				'value' => sprintf(
					/* translators: 1: discount such as "10% off", 2: minimum quantity */
					__( '%1$s (%2$d or more)', 'storecanvas-lite' ),
					self::tier_label( $tier ),
					(int) $tier['min']
				),
			);
		}
		return $item_data;
	}

	public function to_order_item( $item, $cart_item_key, $values, $order ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature.
		if ( ! empty( $values[ self::CART_KEY ] ) && is_array( $values[ self::CART_KEY ] ) && is_object( $item ) && method_exists( $item, 'add_meta_data' ) ) {
			$tier = $values[ self::CART_KEY ];
			$item->add_meta_data(
				__( 'Quantity discount', 'storecanvas-lite' ),
				sprintf(
					/* translators: 1: discount such as "10% off", 2: minimum quantity */
					__( '%1$s (%2$d or more)', 'storecanvas-lite' ),
					self::tier_label( $tier ),
					(int) $tier['min']
				),
				true
			);
		}
	}

	/* ---------- storefront ---------- */

	public function render_table() {
		global $product;
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}
		$rows = self::table_rows( self::get_tiers( $product->get_id() ) );
		if ( ! $rows ) {
			return;
		}
		?>
		<div class="sclt-qty-tiers">
			<table class="sclt-qty-tiers__table">
				<caption><?php esc_html_e( 'Buy more, pay less', 'storecanvas-lite' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Quantity', 'storecanvas-lite' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Price', 'storecanvas-lite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr data-sclt-tier-min="<?php echo esc_attr( (string) $row['from'] ); ?>">
							<td>
								<?php
								if ( null === $row['to'] ) {
									/* translators: %d: minimum quantity */
									echo esc_html( sprintf( __( '%d+', 'storecanvas-lite' ), $row['from'] ) );
								} elseif ( $row['to'] === $row['from'] ) {
									echo esc_html( (string) $row['from'] );
								} else {
									echo esc_html( $row['from'] . '–' . $row['to'] );
								}
								?>
							</td>
							<td><?php echo esc_html( $row['tier'] ? self::tier_label( $row['tier'] ) : __( 'Regular price', 'storecanvas-lite' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="sclt-qty-tiers__note description"><?php esc_html_e( 'Counts every item of this product in your cart, in any option or size.', 'storecanvas-lite' ); ?></p>
		</div>
		<?php
	}

	/* ---------- admin ---------- */

	/**
	 * Tier rows in the product's StoreCanvas Lite tab.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function render_admin_section( $product_id ) {
		$tiers = self::get_tiers( $product_id );
		$rows  = max( 5, count( $tiers ) + 2 );
		$rows  = min( self::MAX_TIERS, $rows );
		?>
		<div class="options_group sclt-admin-section sclt-qty-tiers-admin">
			<h4 style="padding-left:12px;"><?php esc_html_e( 'Quantity discounts', 'storecanvas-lite' ); ?></h4>
			<p class="description" style="padding:0 12px;">
				<span><?php esc_html_e( 'Lower the price when a customer buys more. Each row starts at a quantity (2 or more) and takes a percentage or an amount off each item, options included. The quantity counts every item of this product in the cart. Leave a row empty to skip it.', 'storecanvas-lite' ); ?></span>
			</p>
			<table class="widefat striped" style="margin:0 12px 12px;width:auto;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'From quantity', 'storecanvas-lite' ); ?></th>
						<th><?php esc_html_e( 'Discount', 'storecanvas-lite' ); ?></th>
						<th><?php esc_html_e( 'Type', 'storecanvas-lite' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php for ( $i = 0; $i < $rows; $i++ ) : ?>
						<?php $t = $tiers[ $i ] ?? array(); ?>
						<tr>
							<td><input type="number" min="2" step="1" style="width:90px;" name="sclt_qty_tiers[<?php echo (int) $i; ?>][min]" value="<?php echo esc_attr( isset( $t['min'] ) ? (string) $t['min'] : '' ); ?>" aria-label="<?php esc_attr_e( 'From quantity', 'storecanvas-lite' ); ?>" /></td>
							<td><input type="number" min="0" step="any" style="width:90px;" name="sclt_qty_tiers[<?php echo (int) $i; ?>][amount]" value="<?php echo esc_attr( isset( $t['amount'] ) ? (string) $t['amount'] : '' ); ?>" aria-label="<?php esc_attr_e( 'Discount', 'storecanvas-lite' ); ?>" /></td>
							<td>
								<select name="sclt_qty_tiers[<?php echo (int) $i; ?>][type]" aria-label="<?php esc_attr_e( 'Type', 'storecanvas-lite' ); ?>">
									<option value="percent" <?php selected( ( $t['type'] ?? 'percent' ), 'percent' ); ?>><?php esc_html_e( '% off each', 'storecanvas-lite' ); ?></option>
									<option value="fixed" <?php selected( ( $t['type'] ?? '' ), 'fixed' ); ?>><?php echo esc_html( sprintf( /* translators: %s: currency symbol */ __( '%s off each', 'storecanvas-lite' ), function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '$' ) ); ?></option>
								</select>
							</td>
						</tr>
					<?php endfor; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Save tier rows (called from the product save handler, after its capability check).
	 *
	 * @param int $post_id Product ID.
	 */
	public static function save_settings( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- runs on woocommerce_process_product_meta; core verifies the product-edit nonce.
		if ( ! isset( $_POST['sclt_qty_tiers'] ) || ! is_array( $_POST['sclt_qty_tiers'] ) ) {
			return;
		}
		$rows = array();
		foreach ( wp_unslash( $_POST['sclt_qty_tiers'] ) as $row ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- each value is sanitized below.
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'min'    => sanitize_text_field( (string) ( $row['min'] ?? '' ) ),
				'amount' => sanitize_text_field( (string) ( $row['amount'] ?? '' ) ),
				'type'   => sanitize_key( (string) ( $row['type'] ?? '' ) ),
			);
		}
		$tiers = self::sanitize_tiers( $rows );
		if ( $tiers ) {
			update_post_meta( $post_id, self::META, $tiers );
		} else {
			delete_post_meta( $post_id, self::META );
		}
	}

	/**
	 * Tiers for the live price preview.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function live_price_config( $product_id ) {
		$out = array();
		foreach ( self::get_tiers( $product_id ) as $t ) {
			$out[] = array(
				'min'    => (int) $t['min'],
				'type'   => $t['type'],
				'amount' => (float) $t['amount'],
			);
		}
		return $out;
	}
}
