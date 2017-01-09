<?php
/**
 * The following file contains utility functions specific to our Authorize.Net add-on
 * If you're building your own transaction-method addon, it's likely that you will
 * need to do similar things. This includes enqueueing scripts, formatting data for Authorize.net, etc.
 */

/**
 * Grab the Authorize.Net customer ID for a WP user
 *
 * @since 2.0.0
 *
 * @param integer $customer_id the WP customer ID
 * @param string  $mode
 *
 * @return integer
 */
function it_exchange_authorizenet_get_customer_profile_id( $customer_id, $mode = '' ) {

	$gateway = ITE_Gateways::get( 'authorizenet' );

	if ( ! $mode ) {
		$mode = $gateway->is_sandbox_mode() ? ITE_Const::P_MODE_SANDBOX : ITE_Const::P_MODE_LIVE;
	}

	$suffix = $mode === ITE_Const::P_MODE_SANDBOX ? '_test_mode' : '_live_mode';

	return get_user_meta( $customer_id, '_it_exchange_authorizenet_id' . $suffix, true );
}

/**
 * Add the Authorize.Net customer ID as user meta on a WP user
 *
 * @since 2.0.0
 *
 * @param integer $customer_id the WP user ID
 * @param integer $auth_net_id the Authorize.Net customer ID
 * @param string  $mode
 *
 * @return bool
 */
function it_exchange_authorizenet_set_customer_profile_id( $customer_id, $auth_net_id, $mode = '' ) {

	$gateway = ITE_Gateways::get( 'authorizenet' );

	if ( ! $mode ) {
		$mode = $gateway->is_sandbox_mode() ? ITE_Const::P_MODE_SANDBOX : ITE_Const::P_MODE_LIVE;
	}

	$suffix = $mode === ITE_Const::P_MODE_SANDBOX ? '_test_mode' : '_live_mode';

	return (booL) update_user_meta( $customer_id, '_it_exchange_authorizenet_id' . $suffix, $auth_net_id );
}

/**
 * Grab the Authorize.Net customer ID for a WP user
 *
 * @since      1.0.0
 * @deprecated 2.0.0
 *
 * @param integer $customer_id the WP customer ID
 * @param string  $mode
 *
 * @return integer
 */
function it_exchange_authorizenet_addon_get_authorizenet_customer_id( $customer_id, $mode = '' ) {
	return it_exchange_authorizenet_get_customer_profile_id( $customer_id, $mode );
}

/**
 * Add the Authorize.Net customer ID as user meta on a WP user
 *
 * @since      1.0.0
 * @deprecated 2.0.0
 *
 * @param integer $customer_id the WP user ID
 * @param integer $auth_net_id the Authorize.Net customer ID
 * @param string  $mode
 *
 * @return bool
 */
function it_exchange_authorizenet_addon_set_authorizenet_customer_id( $customer_id, $auth_net_id, $mode = '' ) {
	return it_exchange_authorizenet_set_customer_profile_id( $customer_id, $auth_net_id, $mode );
}

/**
 * Grab a transaction from the Authorize.Net transaction ID
 *
 * @since 1.0.0
 *
 * @param integer $auth_net_id id of Authorize.net transaction
 *
 * @return IT_Exchange_Transaction[]
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
 * @param string  $new_status  new status
 *
 * @return void
 */
function it_exchange_authorizenet_addon_update_transaction_status( $auth_net_id, $new_status ) {
	$transactions = it_exchange_authorizenet_addon_get_transaction_id( $auth_net_id );
	foreach ( $transactions as $transaction ) { //really only one
		$current_status = it_exchange_get_transaction_status( $transaction );
		if ( $new_status !== $current_status ) {
			it_exchange_update_transaction_status( $transaction, $new_status );
		}
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
	foreach ( $transactions as $transaction ) { //really only one

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
	foreach ( $transactions as $transaction ) { //really only one
		$customer_id = get_post_meta( $transaction->ID, '_it_exchange_customer_id', true );
		if ( false !== ( $current_auth_net_id = it_exchange_authorizenet_addon_get_authorizenet_customer_id( $customer_id ) ) ) {
			if ( $current_auth_net_id === $auth_net_id ) {
				delete_user_meta( $customer_id, '_it_exchange_authorizenet_id' . $mode );
			}
		}
	}
}

/**
 * Updates a subscription ID to post_meta for a paypal transaction
 *
 * @since 1.3.0
 *
 * @param string $paypal_standard_id PayPal Transaction ID
 * @param string $subscriber_id      PayPal Subscriber ID
 */
function it_exchange_authorizenet_addon_update_subscriber_id( $txn_id, $subscriber_id ) {
	$transactions = it_exchange_authorizenet_addon_get_transaction_id( $txn_id );
	foreach ( $transactions as $transaction ) { //really only one
		do_action( 'it_exchange_update_transaction_subscription_id', $transaction, $subscriber_id );
	}
}

/**
 * Add a new transaction, really only used for subscription payments.
 * If a subscription pays again, we want to create another transaction in Exchange
 * This transaction needs to be linked to the parent transaction.
 *
 * @since CHANGEME
 *
 * @param integer $method_id      id of Authorize.Net transaction
 * @param string  $payment_status new status
 * @param string  $subscriber_id  from PayPal (optional)
 * @param float   $amount
 *
 * @return bool
 */
function it_exchange_authorizenet_addon_add_child_transaction( $method_id, $payment_status, $subscriber_id, $amount ) {

	$transactions = it_exchange_authorizenet_addon_get_transaction_id( $method_id );

	if ( ! empty( $transactions ) ) {
		// This transaction DOES exist, don't try to create a new one, just update the status
		it_exchange_authorizenet_addon_update_transaction_status( $method_id, $payment_status );
	} else {

		$parent = null;

		$transactions = it_exchange_authorizenet_addon_get_transaction_id_by_subscriber_id( $subscriber_id );

		foreach ( $transactions as $transaction ) { // Really only one
			$parent = $transaction;
		}

		if ( $parent ) {

			it_exchange_add_subscription_renewal_payment( $parent, $method_id, $payment_status, $amount );

			return true;
		}
	}

	return false;
}

/**
 * Grab a transaction from the Authorize.Net subscriber ID
 *
 * @since CHANGEME
 *
 * @param integer $subscriber_id id of stripe transaction
 *
 * @return IT_Exchange_Transaction[] object
 */
function it_exchange_authorizenet_addon_get_transaction_id_by_subscriber_id( $subscriber_id ) {
	$args = array(
		'meta_key'    => '_it_exchange_transaction_subscriber_id',
		'meta_value'  => $subscriber_id,
		'numberposts' => 1, //we should only have one, so limit to 1
	);

	return it_exchange_get_transactions( $args );
}
