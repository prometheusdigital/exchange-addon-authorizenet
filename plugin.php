<?php
/**
 * Load the authorize.net plugin.
 *
 * @since 2.0.0
 * @license GPLv2
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