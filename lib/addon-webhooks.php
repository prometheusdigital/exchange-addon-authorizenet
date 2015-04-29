<?php
/**
 * Most Payment Gateway APIs use some concept of webhooks or notifications to communicate with
 * clients. While add-ons are not required to use the Exchange API, we have created a couple of functions
 * to register and listen for these webooks. The Authorize.Net add-on uses this API and we have placed the 
 * registering and processing functions in this file.
*/

/*
 * Adds the Authorize.Net webhook key to the global array of keys to listen for
 * Authorize.Net calls this a Silent Post
 *
 * If your add-on wants to use our API for listening and initing webhooks,
 * You'll need to register it by using the following API method
 * - it_exchange_register_webhook( $key, $param );
 * The first param is your addon-slug. The second param is the REQUEST key
 * Exchange will listen for (we've just passed it through a filter for stripe).
 *
 * @since CHANGEME
 *
 * @param array $webhooks existing
 * @return array
*/
function it_exchange_authorizenet_addon_register_webhook_key() {
    $key   = 'authorizenet';
    $param = apply_filters( 'it_exchange_authorizenet_addon_webhook', 'it_exchange_authorizenet' );
    it_exchange_register_webhook( $key, $param );
}
add_filter( 'init', 'it_exchange_authorizenet_addon_register_webhook_key' );

/**
 * Processes webhooks for Authorize.Net
 *
 * This function gets called when Exchange detects an incoming request
 * from the payment gateway. It recognizes the request because we registerd it above.
 * This function gets called because we hooked it to the following filter:
 * - it_exchange_webhook_it_exchange_[addon-slug]
 *
 * @since CHANGEME
 * @todo actually handle the exceptions
 *
 * @param array $request really just passing  $_REQUEST
 */
function it_exchange_authorizenet_addon_process_webhook( $request ) {
	
	if ( !empty( $request['x_trans_id'] ) && !empty( $request['x_MD5_Hash'] ) ) {
		$settings = it_exchange_get_option( 'addon_authorizenet' );
		$secret = empty( $settings['authorizenet-sandbox-mode'] ) ? $settings['authorizenet-md5-hash'] : $settings['authorizenet-sandbox-md5-hash'];
		$txn_id = $request['x_trans_id'];
		$amount = !empty( $request['x_amount'] ) ? $request['x_amount'] : '0.00';
		
		$sent_md5 = strtopupper( $request['x_MD5_Hash'] );
		$made_md5 = strtopupper( md5( $secret . $txn_id . $amount ) );
		
		if ( $sent_md5 === $made_md5 ) {
			
			$email = $request['x_email'];
			$cust_id = $request['x_cust_id'];
			$subscriber_id = !empty( $request['x_subscription_id'] ) ? $request['x_subscription_id'] : false;

			switch ( $request['x_response_code'] ) {
				
				case 1:
					global $ite_child_transaction;
					$ite_child_transaction = true;
					wp_mail( 'lew@ithemes.com', 'authorize $request', print_r( $request, true ) );
					it_exchange_authorizenet_addon_add_child_transaction( $txn_id, '1', $subscriber_id, $amount ); //1 = Paid
					it_exchange_authorizenet_addon_update_subscriber_status( $subscriber_id, 'active' );
					break;
				
			}	
					
		} else {
			
			error_log( sprintf( __( 'Unable to validate Silent Post from Authorize.Net. Please double check your MD5 Hash Value in the Authorize.Net settings in iThemes Exchange and your Authorize.Net account: %s', 'it-l10n-ithemes-exchange' ), maybe_serialize( $request ) ) );
	
		}
		
	} else {
		
		error_log( sprintf( __( 'Invalid Silent Post sent from Authorize.Net: %s', 'it-l10n-ithemes-exchange' ), maybe_serialize( $request ) ) );

	}

}
add_action( 'it_exchange_webhook_it_exchange_authorizenet', 'it_exchange_authorizenet_addon_process_webhook' );