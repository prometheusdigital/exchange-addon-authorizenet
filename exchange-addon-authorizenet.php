<?php
/*
 * Plugin Name: iThemes Exchange - Authorize.Net Add-on
 * Version: 2.0.0
 * Description: Adds the ability for users to checkout with Authorize.Net.
 * Plugin URI: http://ithemes.com/exchange/authorize-net/
 * Author: iThemes
 * Author URI: http://ithemes.com
 * iThemes Package: exchange-addon-authorizenet

 * Installation:
 * 1. Download and unzip the latest release zip file.
 * 2. If you use the WordPress plugin uploader to install this plugin skip to step 4.
 * 3. Upload the entire plugin directory to your `/wp-content/plugins/` directory.
 * 4. Activate the plugin through the 'Plugins' menu in WordPress Administration.
 *
*/

/**
 * Load the Authorize.Net plugin.
 *
 * @since 2.0.0
 */
function it_exchange_load_authorizenet() {
	if ( ! function_exists( 'it_exchange_load_deprecated' ) || it_exchange_load_deprecated() ) {
		require_once dirname( __FILE__ ) . '/deprecated/exchange-addon-authorizenet.php';
	} else {
		require_once dirname( __FILE__ ) . '/plugin.php';
	}
}

add_action( 'plugins_loaded', 'it_exchange_load_authorizenet' );

/**
 * Registers Plugin with iThemes updater class
 *
 * @since 1.0.0
 *
 * @param object $updater ithemes updater object
 * @return void
 */
function ithemes_exchange_addon_authorizenet_updater_register( $updater ) {
	$updater->register( 'exchange-addon-authorizenet', __FILE__ );
}
add_action( 'ithemes_updater_register', 'ithemes_exchange_addon_authorizenet_updater_register' );
require( dirname( __FILE__ ) . '/lib/updater/load.php' );

function ithemes_exchange_authorizenet_deactivate() {
	if ( empty( $_GET['remove-gateway'] ) || 'yes' !== $_GET['remove-gateway'] ) {
		$title = __( 'Payment Gateway Warning', 'LION' );
		$yes = '<a href="' . esc_url( add_query_arg( 'remove-gateway', 'yes' ) ) . '">' . __( 'Yes', 'LION' ) . '</a>';
		$no  = '<a href="javascript:history.back()">' . __( 'No', 'LION' ) . '</a>';
		$message = '<p>' . sprintf( __( 'Deactivating a payment gateway can cause customers to lose access to any membership products they have purchased using this payment gateway. Are you sure you want to proceed? %s | %s', 'LION' ), $yes, $no ) . '</p>';
		$args = array(
			'response'  => 200,
			'back_link' => false,
		);
		wp_die( $message, $title, $args );
	}
}
register_deactivation_hook( __FILE__, 'ithemes_exchange_authorizenet_deactivate' );
