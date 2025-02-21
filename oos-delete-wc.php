<?php
/**
 * Plugin Name: Auto-Delete Out of Stock for WooCommerce
 * Description: Automatically deletes products that have been out of stock for more than 120 days.
 * Version: 1.0
 * Author: Eduard V. Doloc
 * Author URI: https://uprise.ro
 * License: GPL2
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Schedule cron job on plugin activation
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'wc_delete_old_oos_products' ) ) {
		wp_schedule_event( time(), 'daily', 'wc_delete_old_oos_products' );
	}
} );

// Unschedule cron job on plugin deactivation
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'wc_delete_old_oos_products' );
} );

// Main function to delete out-of-stock products
function wc_delete_old_oos_products() {
	global $wpdb;

	// Get all out-of-stock products older than 120 days
	$products = $wpdb->get_col( "
        SELECT p.ID 
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE p.post_type = 'product'
        AND p.post_status = 'publish'
        AND pm.meta_key = '_stock_status'
        AND pm.meta_value = 'outofstock'
        AND p.post_modified < NOW() - INTERVAL 120 DAY
    " );

	if ( ! empty( $products ) ) {
		foreach ( $products as $product_id ) {
			wp_delete_post( $product_id, true ); // true = force delete
//			error_log( "Deleted out-of-stock product ID: $product_id" );
		}
	}
}

// Add new column in WooCommerce Product Admin
add_filter( 'manage_edit-product_columns', function ( $columns ) {
	$columns['days_out_of_stock'] = 'Zile fără stoc';

	return $columns;
} );

// Populate the column with the number of days out of stock
add_action( 'manage_product_posts_custom_column', function ( $column, $post_id ) {
	if ( 'days_out_of_stock' === $column ) {
		global $wpdb;


		$stock_status = get_post_meta( $post_id, '_stock_status', true );

		if ( $stock_status === 'outofstock' ) {

			$last_modified = get_post_field( 'post_modified', $post_id );

			if ( $last_modified ) {
				$last_modified_time = strtotime( $last_modified );
				$current_time       = current_time( 'timestamp' );
				$days_out_of_stock  = floor( ( $current_time - $last_modified_time ) / DAY_IN_SECONDS );

				echo $days_out_of_stock . ' zile';
			} else {
				echo 'N/A';
			}
		} else {
			echo '-';
		}
	}
}, 10, 2 );

// Make column sortable
add_filter( 'manage_edit-product_sortable_columns', function ( $columns ) {
	$columns['days_out_of_stock'] = 'days_out_of_stock';

	return $columns;
} );

// Enable sorting by "Days Out of Stock"
add_action( 'pre_get_posts', function ( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( 'days_out_of_stock' === $query->get( 'orderby' ) ) {
		$query->set( 'meta_key', '_stock_status' );
		$query->set( 'orderby', 'post_modified' );
	}
} );

// Hook function into the scheduled cron event
add_action( 'wc_delete_old_oos_products', 'wc_delete_old_oos_products' );

// Allow running via WP-CLI
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'wc-delete-oos', function () {
		wc_delete_old_oos_products();
		WP_CLI::success( "Out-of-stock products older than 120 days deleted!" );
	} );
}
