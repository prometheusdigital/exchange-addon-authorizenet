<?php
/**
 * The following file contains utility functions specific to our Authorize.Net add-on
 * If you're building your own transaction-method addon, it's likely that you will
 * need to do similar things. This includes enqueueing scripts, formatting data for Authorize.net, etc.
*/

/**
 * Adds actions to the plugins page for the iThemes Exchange Authorize.Net plugin
 *
 * @since 1.0.0
 *
 * @param array $meta Existing meta
 * @param string $plugin_file the wp plugin slug (path)
 * @param array $plugin_data the data WP harvested from the plugin header
 * @param string $context
 * @return array
*/
function it_exchange_authorizenet_plugin_row_actions( $actions, $plugin_file, $plugin_data, $context ) {

	$actions['setup_addon'] = '<a href="' . esc_url( admin_url( 'admin.php?page=it-exchange-addons&add-on-settings=authorizenet' ) ) . '">' . __( 'Setup Add-on', 'it-l10n-exchange-addon-authorize-net' ) . '</a>';

	return $actions;

}
add_filter( 'plugin_action_links_exchange-addon-authorizenet/exchange-addon-authorizenet.php', 'it_exchange_authorizenet_plugin_row_actions', 10, 4 );

/**
 * Grab the Authorize.Net customer ID for a WP user
 *
 * @since 1.0.0
 *
 * @param integer $customer_id the WP customer ID
 * @return integer
*/
function it_exchange_authorizenet_addon_get_authorizenet_customer_id( $customer_id ) {
	$settings = it_exchange_get_option( 'addon_authorizenet' );
	$mode     = ( $settings['authorizenet-test-mode'] ) ? '_test_mode' : '_live_mode';
	$mode     = ( $settings['authorizenet-sandbox-mode'] ) ? '_developer_mode' : $mode;

	return get_user_meta( $customer_id, '_it_exchange_authorizenet_id' . $mode, true );
}

/**
 * Add the Authorize.Net customer ID as user meta on a WP user
 *
 * @since 1.0.0
 *
 * @param integer $customer_id the WP user ID
 * @param integer $auth_net_id the Authorize.Net customer ID
 * @return boolean
*/
function it_exchange_authorizenet_addon_set_authorizenet_customer_id( $customer_id, $auth_net_id ) {
	$settings = it_exchange_get_option( 'addon_authorizenet' );
	$mode     = ( $settings['authorizenet-test-mode'] ) ? '_test_mode' : '_live_mode';
	$mode     = ( $settings['authorizenet-sandbox-mode'] ) ? '_developer_mode' : $mode;

	return update_user_meta( $customer_id, '_it_exchange_authorizenet_id' . $mode, $auth_net_id );
}

/**
 * Grab a transaction from the Authorize.Net transaction ID
 *
 * @since 1.0.0
 *
 * @param integer $auth_net_id id of Authorize.net transaction
 * @return transaction object
*/
function it_exchange_authorizenet_addon_get_transaction_id( $auth_net_id ) {
	$args = array(
		'meta_key'    => '_it_exchange_transaction_method_id',
		'meta_value'  => $auth_net_id,
		'numberposts' => 1, //we should only have one, so limit to 1
	);
	return it_exchange_get_transactions( $args );
}

/**
 * Updates an Authorize.Net transaction status based on Authorize.Net ID
 *
 * @since 1.0.0
 *
 * @param integer $auth_net_id id of Authorize.net transaction
 * @param string $new_status new status
 * @return void
*/
function it_exchange_authorizenet_addon_update_transaction_status( $auth_net_id, $new_status ) {
	$transactions = it_exchange_authorizenet_addon_get_transaction_id( $auth_net_id );
	foreach( $transactions as $transaction ) { //really only one
		$current_status = it_exchange_get_transaction_status( $transaction );
		if ( $new_status !== $current_status )
			it_exchange_update_transaction_status( $transaction, $new_status );
	}
}

/**
 * Adds a refund to post_meta for an Authorize.Net transaction
 *
 * @since 1.0.0
*/
function it_exchange_authorizenet_addon_add_refund_to_transaction( $auth_net_id, $refund ) {

	// Grab transaction
	$transactions = it_exchange_authorizenet_addon_get_transaction_id( $auth_net_id );
	foreach( $transactions as $transaction ) { //really only one

		// This refund is already formated on the way in. Don't reformat.
		it_exchange_add_refund_to_transaction( $transaction, $refund );
	}
}

/**
 * Removes an Authorize.Net Customer ID from a WP user
 *
 * @since 1.0.0
 *
 * @param integer $auth_net_id the id of the Authorize.Net transaction
*/
function it_exchange_authorizenet_addon_delete_authorizenet_id_from_customer( $auth_net_id ) {
	$settings = it_exchange_get_option( 'addon_authorizenet' );
	$mode     = ( $settings['authorizenet-test-mode'] ) ? '_test_mode' : '_live_mode';
	$mode     = ( $settings['authorizenet-sandbox-mode'] ) ? '_developer_mode' : $mode;

	$transactions = it_exchange_authorizenet_addon_get_transaction_id( $auth_net_id );
	foreach( $transactions as $transaction ) { //really only one
		$customer_id = get_post_meta( $transaction->ID, '_it_exchange_customer_id', true );
		if ( false !== ( $current_auth_net_id = it_exchange_authorizenet_addon_get_authorizenet_customer_id( $customer_id ) ) ) {

			if ( $current_auth_net_id === $auth_net_id )
				delete_user_meta( $customer_id, '_it_exchange_authorizenet_id' . $mode );

		}
	}
}

/**
 * Loads minimal front-end styling
 *
 * @uses wp_enqueue_style()
 * @since 1.0.0
 * @return void
*/
function it_exchange_authorize_net_css() {
	if ( it_exchange_is_page( 'product' ) || it_exchange_is_page( 'cart' ) || it_exchange_is_page( 'checkout' ) )
		wp_enqueue_style( 'it_exchange_authorize', plugins_url( 'css/authorize.css', __FILE__ ) );
}

add_action( 'wp_enqueue_scripts', 'it_exchange_authorize_net_css' );