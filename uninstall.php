<?php
/**
 *  Gateway for Wise on WooCommerce -- Uninstaller
 *
 */

# no direct page access
defined( 'ABSPATH' ) || exit;
#
# Wipe wp_options from all custom settings stored by this plugin
$wise_settings = get_option("woocommerce_ew_wise_settings");
if( isset( $wise_settings['wipe_data'] ) 
        && $wise_settings['wipe_data'] == 'yes' ) 
{
    delete_option( "woocommerce_ew_wise_settings" );
    delete_option( "woocommerce_ew_wise_accounts" );
}

/* bye! */
