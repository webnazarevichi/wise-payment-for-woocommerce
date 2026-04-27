<?php
/**
 * Wise Payment for WooCommerce Uninstall
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$options = get_option( 'woocommerce_wise_bacs_settings', array() );

if ( ! empty( $options['wipe_data'] ) && 'yes' === $options['wipe_data'] ) {
    delete_option( 'woocommerce_wise_bacs_settings' );
}
