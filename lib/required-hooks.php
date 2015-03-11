<?php
/**
 * Exchange Transaction Add-ons require several hooks in order to work properly.
 * Most of these hooks are called in api/transactions.php and are named dynamically
 * so that individual add-ons can target them. eg: it_exchange_refund_url_for_authorizenet
 * We've placed them all in one file to help add-on devs identify them more easily
*/

//For verifying CC... 
//incase a product doesn't have a shipping address and the shipping add-on is not enabled
add_filter( 'it_exchange_billing_address_purchase_requirement_enabled', '__return_true' );

/**
 * Authorize.Net Endpoint URL to perform refunds
 *
 * The it_exchange_refund_url_for_[addon-slug] filter is
 * used to generate the link for the 'Refund Transaction' button
 * found in the admin under Customer Payments
 *
 * Worth noting here that in order to submit the refund (CREDIT method call to standard AIM endpoint),
 * we need to capture and return the last four digits of the CC.
 *
 * @since 1.0.0
 *
 * @param string $url passed by WP filter.
 * @param string $url transaction URL
*/
function it_exchange_refund_url_for_authorizenet( $url ) {

	$settings = it_exchange_get_option( 'addon_authorizenet' );
	$url      = $settings['authorizenet-test-mode'] ? AuthorizeNetAIM::LIVE_URL : AuthorizeNetAIM::SANDBOX_URL;

	return $url;
}

add_filter( 'it_exchange_refund_url_for_authorizenet', 'it_exchange_refund_url_for_authorizenet' );

/**
 * This processes an Authorize.net transaction.
 *
 * We rely less on the customer ID here than Stripe does because the APIs approach customers with pretty significant distinction
 * Once we're ready to integrate CIM, it's probably worth changing this up a bit.
 *
 * The it_exchange_do_transaction_[addon-slug] action is called when
 * the site visitor clicks a specific add-ons 'purchase' button. It is
 * passed the default status of false along with the transaction object
 * The transaction object is a package of data describing what was in the user's cart
 *
 * Exchange expects your add-on to either return false if the transaction failed or to
 * call it_exchange_add_transaction() and return the transaction ID
 *
 * @since 1.0.0
 *
 * @param string $status passed by WP filter.
 * @param object $transaction_object The transaction object
*/
function it_exchange_authorizenet_addon_process_transaction( $status, $transaction_object ) {

	// If this has been modified as true already, return.
	if ( $status )
		return $status;

	// Do we have valid CC fields?
	if ( ! it_exchange_submitted_purchase_dialog_values_are_valid( 'authorizenet' ) )
		return false;

	// Grab CC data
	$cc_data = it_exchange_get_purchase_dialog_submitted_values( 'authorizenet' );

	// Make sure we have the correct $_POST argument
	if ( ! empty( $_POST[it_exchange_get_field_name('transaction_method')] ) && 'authorizenet' == $_POST[it_exchange_get_field_name('transaction_method')] ) {

		$general_settings = it_exchange_get_option( 'settings_general' );
		$settings         = it_exchange_get_option( 'addon_authorizenet' );

		define( 'AUTHORIZENET_API_LOGIN_ID'   , $settings['authorizenet-api-login-id'] );
		define( 'AUTHORIZENET_TRANSACTION_KEY', $settings['authorizenet-transaction-key'] );
		define( 'AUTHORIZENET_SANDBOX'        , (bool) $settings['authorizenet-test-mode'] );

		$transaction = new AuthorizeNetAIM;

		$it_exchange_authorizenet_transaction_fields = array(
			'amount'     => $transaction_object->total,
			'card_num'   => $cc_data['number'], //$_POST['x_card_num'],
			'exp_date'   => $cc_data['expiration-month'] . '/' . $cc_data['expiration-year'], //_POST['x_exp_date'],
			'first_name' => $cc_data['first-name'], //$_POST['x_first_name'],
			'last_name'  => $cc_data['last-name'], //$_POST['x_last_name'],
			'address'    => $transaction_object->billing_address['address1'] . ( !empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->billing_address['address2'] : '' ),
			'city'       => $transaction_object->billing_address['city'],
			'state'      => $transaction_object->billing_address['state'],
			'zip'        => $transaction_object->billing_address['zip'],
			'country'    => $transaction_object->billing_address['country'],
			'card_code'  => $cc_data['code'] //$_POST['x_card_code'],
			//'currency_e' => $general_settings['default-currency']
		);
		
		// If we have the shipping info, we may as well include it in the fields sent to Authorize.Net
		if ( !empty( $transaction_object->shipping_address ) ) {
			$it_exchange_authorizenet_transaction_fields['ship_to_address'] = $transaction_object->shipping_address['address1'] . ( !empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->shipping_address['address2'] : '' );			
			$it_exchange_authorizenet_transaction_fields['ship_to_city']    = $transaction_object->shipping_address['city'];			
			$it_exchange_authorizenet_transaction_fields['ship_to_state']   = $transaction_object->shipping_address['state'];			
			$it_exchange_authorizenet_transaction_fields['ship_to_zip']     = $transaction_object->shipping_address['zip'];			
			$it_exchange_authorizenet_transaction_fields['ship_to_country'] = $transaction_object->shipping_address['country'];			
		}
		
		$fields = apply_filters( 'it_exchange_authorizenet_transaction_fields', $it_exchange_authorizenet_transaction_fields );

		$transaction->setFields( $fields );

		$response = $transaction->authorizeAndCapture();

		if ( ! $response->approved ) {
			it_exchange_add_message( 'error', $response->response_reason_text );
			it_exchange_flag_purchase_dialog_error( 'authorizenet' );
			return false;
		} else {
			$it_exchange_customer = it_exchange_get_current_customer();
			error_log( it_exchange_get_current_customer_id() );
			error_log( $it_exchange_customer->id );
			return it_exchange_add_transaction( 'authorizenet', $response->transaction_id, AuthorizeNetAIM_Response::APPROVED, $it_exchange_customer->id, $transaction_object );
		}

	} else {
		it_exchange_flag_purchase_dialog_error( 'authorizenet' );
		it_exchange_add_message( 'error', __( 'Unknown error. Please try again later.', 'LION' ) );
	}
	return false;

}
add_action( 'it_exchange_do_transaction_authorizenet', 'it_exchange_authorizenet_addon_process_transaction', 10, 2 );

/**
 * Returns the button for making the payment
 *
 * Exchange will loop through activated Payment Methods on the checkout page
 * and ask each transaction method to return a button using the following filter:
 * - it_exchange_get_[addon-slug]_make_payment_button
 * Transaction Method add-ons must return a button hooked to this filter if they
 * want people to be able to make purchases.
 *
 * @since 1.0.0
 *
 * @param array $options
 * @return string HTML button
*/
function it_exchange_authorizenet_addon_make_payment_button( $options ) {

	// Make sure we have items in the cart
	if ( 0 >= it_exchange_get_cart_total( false ) )
		return;

	// Use the ITExchange Purchase Dialog for CC fields
	if ( function_exists( 'it_exchange_generate_purchase_dialog' ) )
		return it_exchange_generate_purchase_dialog( 'authorizenet' );
}

add_filter( 'it_exchange_get_authorizenet_make_payment_button', 'it_exchange_authorizenet_addon_make_payment_button', 10, 2 );

/**
 * Gets the interpreted transaction status from valid Authorize.net transaction statuses
 *
 * For future reference, here are all of the Authorize.net Transaction Statuses, along with explanations.
 * Only the valid ones for the 1.0 release of the Authorize.net plugin are utilized in this function.
 *
 * - Approved Review
 * -– This status is specific to eCheck.Net. Transactions with this status were approved while awaiting processing.
 * - Authorized/Pending Capture
 * -– Transactions with this status have been authorized by the processor but will not be sent for settlement until a capture is performed.
 * - Authorized/Held Pending Release
 * -—Transactions with this status are part of a larger order. Each individual transaction pays for part of the total order amount.
 * - Captured/Pending Settlement
 * -– Transactions with this status have been approved and captured, and will be picked up and sent for settlement at the transaction cut-off time.
 * - Could Not Void
 * -– Transactions with this status experienced a processing error during a payment gateway generated void. These voids may be resubmitted if the batch is still open.
 * - Declined
 * -– Transactions with this status were not approved at the processor. These transactions may not be captured and submitted for settlement.
 * - Expired
 * -– Transactions that are expired were authorized but never submitted for capture. Transactions typically expire approximately 30 days after the initial authorization.
 * - FDS - Authorized/Pending Review
 * -– This status is specific to the Fraud Detection Suite (FDS). Transactions with this status triggered one or more fraud filters with the “Authorize and hold for review” filter action, and are placed in this state once they are successfully authorized by the processor.
 * - FDS - Pending Review
 * -– This status is specific to the FDS. Transactions with this status triggered one or more fraud filters with the ”Do not authorize, but hold for review” filter action, and are placed in this state prior to being sent for authorization.
 * - Failed Review
 * -– This status is specific to eCheck.Net. Transactions with this status failed review while awaiting processing.
 * - Order Not Complete
 * -– This status applies to transactions that are part of an order that is not complete because only part of the total amount has been authorized.
 * - Refund
 * -– Transactions with this status have been submitted and authorized for refund.
 * - Refund/Pending Settlement
 * -– Transactions with this status have been submitted for refund and will be picked up and sent for settlement at the transaction cut-off time.
 * - Settled Successfully
 * -– Transactions with this status have been approved and successfully settled.
 * - Under Review
 * -– This status is specific to eCheck.Net. Transactions with this status are currently being reviewed before being submitted for processing.
 * - Voided
 * -– Transactions with this status have been voided and will not be sent for settlement. No further action may be taken for a voided transaction.
 *
 * Most gateway transaction stati are going to be lowercase, one word strings.
 * Hooking a function to the it_exchange_transaction_status_label_[addon-slug] filter
 * will allow add-ons to return the human readable label for a given transaction status.
 *
 * @since 1.0.0
 * @todo Chat with Glenn on this.  Authorize.net treats statuses differently than most other gateways.  It's more pass/fail, and then the TransactionDetails API is necessary to get the actual status
 *
 * @param string $status the string of the Authorize.net transaction
 * @return string translaction transaction status
*/
function it_exchange_authorizenet_addon_transaction_status_label( $status ) {
	switch ( $status ) {
		case AuthorizeNetAIM_Response::APPROVED :
			return __( 'Paid', 'LION' );
		case AuthorizeNetAIM_Response::DECLINED :
			return __( 'Declined', 'LION' );
		case AuthorizeNetAIM_Response::ERROR    :
			return __( 'Error', 'LION' );
		case AuthorizeNetAIM_Response::HELD     :
			return __( 'Held: The transasction funds are currently held or under review.', 'LION' );
		default:
			return __( 'Unknown', 'LION' );
	}
}
add_filter( 'it_exchange_transaction_status_label_authorizenet', 'it_exchange_authorizenet_addon_transaction_status_label' );

/**
 * Returns a boolean. Is this transaction a status that warrants delivery of any products attached to it?
 *
 * Just because a transaction gets added to the DB doesn't mean that the admin is ready to give over
 * the goods yet. Each payment gateway will have different transaction stati. Exchange uses the following
 * filter to ask transaction-methods if a current status is cleared for delivery. Return true if the status
 * means its okay to give the download link out, ship the product, etc. Return false if we need to wait.
 * - it_exchange_[addon-slug]_transaction_is_cleared_for_delivery
 *
 * @since 1.0.0
 *
 * @param boolean $cleared passed in through WP filter. Ignored here.
 * @param object $transaction
 * @return boolean
*/
function it_exchange_authorizenet_transaction_is_cleared_for_delivery( $cleared, $transaction ) {
	$valid_stati = array( AuthorizeNetAIM_Response::APPROVED );
	return in_array( it_exchange_get_transaction_status( $transaction ), $valid_stati );
}
add_filter( 'it_exchange_authorizenet_transaction_is_cleared_for_delivery', 'it_exchange_authorizenet_transaction_is_cleared_for_delivery', 10, 2 );
