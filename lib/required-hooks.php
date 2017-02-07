<?php
/**
 * Exchange Transaction Add-ons require several hooks in order to work properly.
 * Most of these hooks are called in api/transactions.php and are named dynamically
 * so that individual add-ons can target them. eg: it_exchange_refund_url_for_authorizenet
 * We've placed them all in one file to help add-on devs identify them more easily
 */

add_action( 'it_exchange_register_gateways', function ( ITE_Gateways $gateways ) {

	require_once dirname( __FILE__ ) . '/class.gateway.php';
	require_once dirname( __FILE__ ) . '/handlers/class.helper.php';
	require_once dirname( __FILE__ ) . '/handlers/class.purchase.php';
	require_once dirname( __FILE__ ) . '/handlers/class.tokenize.php';
	require_once dirname( __FILE__ ) . '/handlers/class.silentPost.php';
	require_once dirname( __FILE__ ) . '/handlers/class.webhook.php';
	require_once dirname( __FILE__ ) . '/handlers/class.refund.php';
	require_once dirname( __FILE__ ) . '/handlers/class.update-subscription-payment-method.php';
	require_once dirname( __FILE__ ) . '/handlers/class.pause-subscription.php';
	require_once dirname( __FILE__ ) . '/handlers/class.resume-subscription.php';
	require_once dirname( __FILE__ ) . '/handlers/class.cancel-subscription.php';

	$gateways::register( new ITE_AuthorizeNet_Gateway() );
} );

if ( it_exchange_is_gateway_accepting_payments( 'authorizenet' ) ) {
	// Auth.Net always required a billing address for verification.
	add_filter( 'it_exchange_billing_address_purchase_requirement_enabled', '__return_true' );
}

/**
 * Can the authorize.net transaction be refunded.
 *
 * @since 2.0.0
 *
 * @param bool                    $eligible
 * @param IT_Exchange_Transaction $transaction
 *
 * @return bool
 */
function it_exchange_authorizenet_transaction_can_be_refunded( $eligible, IT_Exchange_Transaction $transaction ) {

	if ( ! $eligible ) {
		return $eligible;
	}

	$source = $transaction->get_payment_source();

	if ( ! $source instanceof ITE_Gateway_Card && ! $source instanceof ITE_Payment_Token ) {
		return false;
	}

	if ( $source instanceof ITE_Gateway_Card && ! $source->get_redacted_number() ) {
		return false;
	}

	$method_id = $transaction->get_method_id();

	if ( ! is_numeric( $method_id ) ) {
		return false;
	}

	$now    = new DateTime();
	$placed = $transaction->order_date;

	$diff = $placed->diff( $now );

	return $diff->days < 179 && $diff->days > 2;
}

add_filter( 'it_exchange_authorizenet_transaction_can_be_refunded', 'it_exchange_authorizenet_transaction_can_be_refunded', 10, 2 );

/**
 * Can a subscription be paused.
 *
 * @since 2.0.0
 *
 * @param bool                     $can
 * @param IT_Exchange_Subscription $subscription
 *
 * @return bool
 */
function it_exchange_authorizenet_subscription_can_be_paused( $can, IT_Exchange_Subscription $subscription ) {

	if ( ! $can ) {
		return $can;
	}

	$gateway = $subscription->get_transaction()->get_gateway();

	if ( ! $gateway || $gateway->get_slug() !== 'authorizenet' ) {
		return $can;
	}

	if ( ! $subscription->get_payment_token() ) {
		return false;
	}

	return true;
}

add_filter( 'it_exchange_subscription_can_be_paused', 'it_exchange_authorizenet_subscription_can_be_paused', 10, 2 );

/**
 * Enqueue Accept.JS
 *
 * @since 2.0.0
 */
function it_exchange_authorizenet_enqueue_accept_js() {

	if (
		! wp_script_is( 'it-exchange-rest', 'done' ) &&
		! it_exchange_is_page( 'product' ) &&
		! it_exchange_is_page( 'checkout' )
	) {
		return;
	}

	$gateway = ITE_Gateways::get( 'authorizenet' );

	if ( ! $gateway->settings()->get( 'acceptjs' ) ) {
		return;
	}

	if ( $gateway->is_sandbox_mode() ) {
		$script = esc_url( 'https://jstest.authorize.net/v1/Accept.js' );
	} else {
		$script = esc_url( 'https://js.authorize.net/v1/Accept.js' );
	}

	echo "<script type='text/javascript' src='{$script}' charset='utf-8'></script>";
}

add_action( 'admin_footer', 'it_exchange_authorizenet_enqueue_accept_js', 100 );
add_action( 'wp_footer', 'it_exchange_authorizenet_enqueue_accept_js', 100 );

/**
 * Enqueues admin scripts on Settings page
 *
 * @since 1.1.24
 *
 * @return void
 */
function it_exchange_authorizenet_addon_admin_enqueue_script( $hook ) {
	if ( 'exchange_page_it-exchange-addons' === $hook
	     && ! empty( $_REQUEST['add-on-settings'] ) && 'authorizenet' === $_REQUEST['add-on-settings']
	) {
		wp_enqueue_script( 'authorizenet-addon-settings-js', ITUtility::get_url_from_file( dirname( __FILE__ ) ) . '/js/settings.js' );
		wp_enqueue_style( 'authorizenet-addon-settings-css', ITUtility::get_url_from_file( dirname( __FILE__ ) ) . '/css/settings.css' );
	}
}

add_action( 'admin_enqueue_scripts', 'it_exchange_authorizenet_addon_admin_enqueue_script' );

/**
 * Loads minimal front-end styling
 *
 * @uses  wp_enqueue_style()
 * @since 1.0.0
 * @return void
 */
function it_exchange_authorizenet_addon_wp_enqueue_script() {
	if ( it_exchange_is_page( 'product' ) || it_exchange_is_page( 'cart' ) || it_exchange_is_page( 'checkout' )
	     || ( class_exists( 'IT_Exchange_SW_Shortcode' ) && IT_Exchange_SW_Shortcode::has_shortcode() )
	) {
		wp_enqueue_style( 'it_exchange_authorize', plugins_url( 'css/authorize.css', __FILE__ ) );
	}
}

add_action( 'wp_enqueue_scripts', 'it_exchange_authorizenet_addon_wp_enqueue_script' );

/**
 * Adds actions to the plugins page for the iThemes Exchange Authorize.Net plugin
 *
 * @since 1.0.0
 *
 * @param array  $meta        Existing meta
 * @param string $plugin_file the wp plugin slug (path)
 * @param array  $plugin_data the data WP harvested from the plugin header
 * @param string $context
 *
 * @return array
 */
function it_exchange_authorizenet_plugin_row_actions( $actions, $plugin_file, $plugin_data, $context ) {

	$actions['setup_addon'] = '<a href="' . esc_url( admin_url( 'admin.php?page=it-exchange-addons&add-on-settings=authorizenet' ) ) . '">' . __( 'Setup Add-on', 'LION' ) . '</a>';

	return $actions;

}

add_filter( 'plugin_action_links_exchange-addon-authorizenet/exchange-addon-authorizenet.php', 'it_exchange_authorizenet_plugin_row_actions', 10, 4 );

/**
 * Gets the interpreted transaction status from valid Authorize.net transaction statuses
 *
 * For future reference, here are all of the Authorize.net Transaction Statuses, along with explanations.
 * Only the valid ones for the 1.0 release of the Authorize.net plugin are utilized in this function.
 *
 * - Approved Review
 * -– This status is specific to eCheck.Net. Transactions with this status were approved while awaiting processing.
 * - Authorized/Pending Capture
 * -– Transactions with this status have been authorized by the processor but will not be sent for settlement until a
 * capture is performed.
 * - Authorized/Held Pending Release
 * -—Transactions with this status are part of a larger order. Each individual transaction pays for part of the total
 * order amount.
 * - Captured/Pending Settlement
 * -– Transactions with this status have been approved and captured, and will be picked up and sent for settlement at
 * the transaction cut-off time.
 * - Could Not Void
 * -– Transactions with this status experienced a processing error during a payment gateway generated void. These voids
 * may be resubmitted if the batch is still open.
 * - Declined
 * -– Transactions with this status were not approved at the processor. These transactions may not be captured and
 * submitted for settlement.
 * - Expired
 * -– Transactions that are expired were authorized but never submitted for capture. Transactions typically expire
 * approximately 30 days after the initial authorization.
 * - FDS - Authorized/Pending Review
 * -– This status is specific to the Fraud Detection Suite (FDS). Transactions with this status triggered one or more
 * fraud filters with the “Authorize and hold for review” filter action, and are placed in this state once they are
 * successfully authorized by the processor.
 * - FDS - Pending Review
 * -– This status is specific to the FDS. Transactions with this status triggered one or more fraud filters with the
 * ”Do not authorize, but hold for review” filter action, and are placed in this state prior to being sent for
 * authorization.
 * - Failed Review
 * -– This status is specific to eCheck.Net. Transactions with this status failed review while awaiting processing.
 * - Order Not Complete
 * -– This status applies to transactions that are part of an order that is not complete because only part of the total
 * amount has been authorized.
 * - Refund
 * -– Transactions with this status have been submitted and authorized for refund.
 * - Refund/Pending Settlement
 * -– Transactions with this status have been submitted for refund and will be picked up and sent for settlement at the
 * transaction cut-off time.
 * - Settled Successfully
 * -– Transactions with this status have been approved and successfully settled.
 * - Under Review
 * -– This status is specific to eCheck.Net. Transactions with this status are currently being reviewed before being
 * submitted for processing.
 * - Voided
 * -– Transactions with this status have been voided and will not be sent for settlement. No further action may be
 * taken for a voided transaction.
 *
 * Most gateway transaction stati are going to be lowercase, one word strings.
 * Hooking a function to the it_exchange_transaction_status_label_[addon-slug] filter
 * will allow add-ons to return the human readable label for a given transaction status.
 *
 * @since 1.0.0
 * @todo  Chat with Glenn on this.  Authorize.net treats statuses differently than most other gateways.  It's more
 *        pass/fail, and then the TransactionDetails API is necessary to get the actual status
 *
 * @param string $status the string of the Authorize.net transaction
 *
 * @return string translaction transaction status
 */
function it_exchange_authorizenet_addon_transaction_status_label( $status ) {
	switch ( $status ) {
		case '1' :
			return __( 'Paid', 'LION' );
		case '2' :
			return __( 'Declined', 'LION' );
		case '3' :
			return __( 'Error', 'LION' );
		case '4' :
			return __( 'Held: The transaction funds are currently held or under review.', 'LION' );
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
 * @param object  $transaction
 *
 * @return boolean
 */
function it_exchange_authorizenet_transaction_is_cleared_for_delivery( $cleared, $transaction ) {
	$valid_stati = array( 1 );

	return in_array( it_exchange_get_transaction_status( $transaction ), $valid_stati );
}

add_filter( 'it_exchange_authorizenet_transaction_is_cleared_for_delivery', 'it_exchange_authorizenet_transaction_is_cleared_for_delivery', 10, 2 );
