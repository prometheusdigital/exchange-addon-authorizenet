<?php
/*
 * Plugin Name: iThemes Exchange - Authorize.Net Add-on
 * Version: 1.3.0
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
 * This registers our plugin as an Authorize.Net addon
 *
 * To learn how to create your own-addon, visit http://ithemes.com/codex/page/Exchange_Custom_Add-ons:_Overview
 *
 * @since 1.0.0
 *
 * @return void
*/
function it_exchange_register_authorizenet_addon() {
	$options = array(
		'name'              => __( 'Authorize.Net', 'LION' ),
		'description'       => __( 'Process transactions via Authorize.Net, a robust and powerful payment gateway.', 'LION' ),
		'author'            => 'iThemes',
		'author_url'        => 'http://ithemes.com/exchange/authorize_net/',
		'icon'              => ITUtility::get_url_from_file( dirname( __FILE__ ) . '/lib/images/authorize-net.png' ),
		'wizard-icon'       => ITUtility::get_url_from_file( dirname( __FILE__ ) . '/lib/images/authorize-settings.png' ),
		'file'              => dirname( __FILE__ ) . '/init.php',
		'category'          => 'transaction-methods',
		'settings-callback' => 'it_exchange_authorizenet_addon_settings_callback',
	);
	it_exchange_register_addon( 'authorizenet', $options );
}

add_action( 'it_exchange_register_addons', 'it_exchange_register_authorizenet_addon' );

/**
 * Loads the translation data for WordPress
 *
 * @uses load_plugin_textdomain()
 * @since 1.0.0
 * @return void
*/
function it_exchange_authorizenet_set_textdomain() {
	load_plugin_textdomain( 'it-l10n-exchange-authorize_net', false, dirname( plugin_basename( __FILE__  ) ) . '/lang/' );
}
add_action( 'plugins_loaded', 'it_exchange_authorizenet_set_textdomain' );

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
