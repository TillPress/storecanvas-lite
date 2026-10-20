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
 * Customer design canvas: removes the per-product canvas settings, the signing
 * secret and folder key options, the daily cleanup event, the guest upload
 * throttle transients and every file in uploads/sclt-designs (customer previews
 * and uploaded images, ordered or not). Download the design files of open orders
 * first, or define SCLT_KEEP_DESIGNS_ON_UNINSTALL as true in wp-config.php to keep
 * the files. The order-item design record (_sclt_design) stays with the order.
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
delete_post_meta_by_key( '_sclt_qty_tiers' );

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
foreach ( array( 'sclt_installed_at', 'sclt_review_nudge_dismissed', 'sclt_review_nudge_snoozed_until', 'sclt_review_nudge_orders' ) as $sclt_nudge_opt ) {
	delete_option( $sclt_nudge_opt );
}
delete_metadata( 'user', 0, 'sclt_offer_dismissed', '', true );

// Customer design canvas.
delete_post_meta_by_key( '_sclt_canvas' );
wp_clear_scheduled_hook( 'sclt_canvas_cleanup' );
delete_option( 'sclt_canvas_secret' );

if ( ! defined( 'SCLT_KEEP_DESIGNS_ON_UNINSTALL' ) || ! SCLT_KEEP_DESIGNS_ON_UNINSTALL ) {
	$sclt_uploads = wp_upload_dir( null, false );
	$sclt_root    = ! empty( $sclt_uploads['basedir'] ) ? untrailingslashit( $sclt_uploads['basedir'] ) . '/sclt-designs' : '';
	if ( '' !== $sclt_root && is_dir( $sclt_root ) && 'sclt-designs' === basename( $sclt_root ) ) {
		$sclt_items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $sclt_root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $sclt_items as $sclt_item ) {
			if ( $sclt_item->isLink() || $sclt_item->isFile() ) {
				wp_delete_file( $sclt_item->getPathname() );
			} elseif ( $sclt_item->isDir() ) {
				rmdir( $sclt_item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own uploads folder on uninstall.
			}
		}
		rmdir( $sclt_root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own uploads folder on uninstall.
	}
	delete_option( 'sclt_canvas_dir' );
}

// Guest upload throttle counters (expire within an hour anyway).
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sclt\\_cv\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sclt\\_cv\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall cleanup of this plugin's transients.
