<?php
/**
 * Uninstall StoreCanvas Lite.
 *
 * Removes the sclt_ transient cache and the plugin's product meta
 * (option field definitions and group assignments). Option group posts
 * (sclt_option_group CPT) are kept — they are store content the merchant
 * created. Order and order-item meta (chosen options, labels, price extras,
 * and the _sclt_option_stock_applied / _sclt_option_stock_restored flags) is
 * kept — it is part of the order record a store may need to retain.
 *
 * Does not touch the paid StoreCanvas plugin's data.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_transient( 'sclt_option_groups_map_v1' );

// Product meta created by this plugin.
delete_post_meta_by_key( '_sclt_options' );
delete_post_meta_by_key( '_sclt_option_group_ids' );

// Option-group CPT meta (only meaningful if the merchant also deletes the
// group posts; harmless otherwise since the posts are kept).
delete_post_meta_by_key( '_sclt_group_fields' );
delete_post_meta_by_key( '_sclt_group_product_ids' );
delete_post_meta_by_key( '_sclt_group_category_ids' );

// Help & demo page: demo data (only objects carrying the demo marker) and the per-user offer dismissal.
$sclt_demo = get_option( 'sclt_demo_ids', array() );
if ( is_array( $sclt_demo ) ) {
	foreach ( array_merge( (array) ( $sclt_demo['products'] ?? array() ), (array) ( $sclt_demo['groups'] ?? array() ) ) as $sclt_id ) {
		$sclt_id = absint( $sclt_id );
		if ( $sclt_id && get_post_meta( $sclt_id, '_sclt_demo', true ) ) {
			wp_delete_post( $sclt_id, true );
		}
	}
}
delete_option( 'sclt_demo_ids' );
delete_metadata( 'user', 0, 'sclt_offer_dismissed', '', true );
