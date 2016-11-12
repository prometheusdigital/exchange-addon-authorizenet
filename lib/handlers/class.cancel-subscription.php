<?php
/**
 * Authorize.Net Cancel Subscription Handler.
 *
 * @since   1.5.0
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

		$settings   = $this->gateway->settings()->all();
		$is_sandbox = $subscription->get_transaction()->is_sandbox_purchase() ? true : $settings['authorizenet-sandbox-mode'];

		$api_url      = $is_sandbox ? AUTHORIZE_NET_AIM_API_SANDBOX_URL : AUTHORIZE_NET_AIM_API_LIVE_URL;
		$api_username = $is_sandbox ? $settings['authorizenet-sandbox-api-login-id'] : $settings['authorizenet-api-login-id'];
		$api_password = $is_sandbox ? $settings['authorizenet-sandbox-transaction-key'] : $settings['authorizenet-transaction-key'];

		$request = array(
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
			'body'    => json_encode( $request ),
		);

		// Make sure we update the subscription before the webhook handler does.
		it_exchange_lock( "authorizenet-cancel-subscription-{$subscription->get_transaction()->ID}", 2 );
		$response = wp_remote_post( $api_url, $query );

		if ( ! is_wp_error( $response ) ) {
			$body = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
			$obj  = json_decode( $body, true );

			if ( isset( $obj['messages'] ) && isset( $obj['messages']['resultCode'] ) && $obj['messages']['resultCode'] == 'Error' ) {
				if ( ! empty( $obj['messages']['message'] ) ) {
					$error = reset( $obj['messages']['message'] );
					return false;
				}
			}
		} else {
			throw new UnexpectedValueException( $response->get_error_message() );
		}

		$subscription->set_status( IT_Exchange_Subscription::STATUS_CANCELLED );

		if ( $request->get_reason() ) {
			$subscription->set_cancellation_reason( $request->get_reason() );
		}

		if ( $request->get_cancelled_by() ) {
			$subscription->set_cancelled_by( $request->get_cancelled_by() );
		}

		it_exchange_release_lock( "authorizenet-cancel-subscription-{$subscription->get_transaction()->ID}" );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'cancel-subscription'; }
}