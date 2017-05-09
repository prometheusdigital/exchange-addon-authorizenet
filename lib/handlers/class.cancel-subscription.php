<?php
/**
 * Authorize.Net Cancel Subscription Handler.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class ITE_AuthorizeNet_Cancel_Subscription_Request_Handler
 */
class ITE_AuthorizeNet_Cancel_Subscription_Request_Handler implements ITE_Gateway_Request_Handler {

	/** @var ITE_Gateway */
	private $gateway;

	/**
	 * ITE_AuthorizeNet_Cancel_Subscription_Handler constructor.
	 *
	 * @param ITE_Gateway $gateway
	 */
	public function __construct( ITE_Gateway $gateway ) { $this->gateway = $gateway; }

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Cancel_Subscription_Request $request
	 */
	public function handle( $request ) {

		$subscription = $request->get_subscription();

		if ( ! $subscription->get_subscriber_id() ) {
			return false;
		}

		$settings = $this->gateway->settings()->all();

		if ( $subscription->get_transaction()->is_sandbox_purchase() ) {
			$is_sandbox = true;
		} elseif ( $subscription->get_transaction()->is_live_purchase() ) {
			$is_sandbox = false;
		} else {
			$is_sandbox = $settings['authorizenet-sandbox-mode'];
		}

		$api_url      = $is_sandbox ? AUTHORIZE_NET_AIM_API_SANDBOX_URL : AUTHORIZE_NET_AIM_API_LIVE_URL;
		$api_username = $is_sandbox ? $settings['authorizenet-sandbox-api-login-id'] : $settings['authorizenet-api-login-id'];
		$api_password = $is_sandbox ? $settings['authorizenet-sandbox-transaction-key'] : $settings['authorizenet-transaction-key'];

		$body = array(
			'ARBCancelSubscriptionRequest' => array(
				'merchantAuthentication' => array(
					'name'           => $api_username,
					'transactionKey' => $api_password,
				),
				'subscriptionId'         => $subscription->get_subscriber_id()
			),
		);

		$query = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode( $body ),
		);

		// Make sure we update the subscription before the webhook handler does.
		it_exchange_lock( "authorizenet-cancel-subscription-{$subscription->get_transaction()->ID}", 2 );
		it_exchange_log( 'Acquiring Authorize.Net cancel subscription #{sub_id} lock for transaction #{txn_id}', ITE_Log_Levels::DEBUG, array(
			'txn_id' => $subscription->get_transaction()->get_ID(),
			'sub_id' => $subscription->get_subscriber_id(),
			'_group' => 'subscription',
		) );

		$response = wp_remote_post( $api_url, $query );

		if ( is_wp_error( $response ) ) {
			it_exchange_log( 'Network error while cancelling Authorize.Net subscription: {error}', ITE_Log_Levels::WARNING, array(
				'_group' => 'refund',
				'error'  => $response->get_error_message()
			) );

			throw new UnexpectedValueException( $response->get_error_message() );
		}

		$body = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
		$obj  = json_decode( $body, true );

		if ( isset( $obj['messages'] ) && isset( $obj['messages']['resultCode'] ) && $obj['messages']['resultCode'] === 'Error' ) {
			it_exchange_log( 'Failed to cancel Authorize.Net subscription #{sub_id} lock for transaction #{txn_id}: {response}', array(
				'txn_id'   => $subscription->get_transaction()->get_ID(),
				'sub_id'   => $subscription->get_subscriber_id(),
				'response' => wp_json_encode( $obj['messages'] ),
				'_group'   => 'subscription',
			) );

			return false;
		}

		if ( $request->should_set_status() ) {
			$subscription->set_status( IT_Exchange_Subscription::STATUS_CANCELLED );
		}

		if ( $request->get_reason() ) {
			$subscription->set_cancellation_reason( $request->get_reason() );
		}

		if ( $request->get_cancelled_by() ) {
			$subscription->set_cancelled_by( $request->get_cancelled_by() );
		}

		it_exchange_release_lock( "authorizenet-cancel-subscription-{$subscription->get_transaction()->ID}" );

		it_exchange_log( 'Cancelled Authorize.Net subscription #{sub_id} lock for transaction #{txn_id}', ITE_Log_Levels::INFO, array(
			'txn_id' => $subscription->get_transaction()->get_ID(),
			'sub_id' => $subscription->get_subscriber_id(),
			'_group' => 'subscription',
		) );


		return true;
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'cancel-subscription'; }
}