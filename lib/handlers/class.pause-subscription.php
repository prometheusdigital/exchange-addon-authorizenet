<?php
/**
 * Pause Subscription Handler.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class ITE_AuthorizeNet_Pause_Subscription_Handler
 */
class ITE_AuthorizeNet_Pause_Subscription_Handler implements ITE_Gateway_Request_Handler {

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Pause_Subscription_Request $request
	 */
	public function handle( $request ) {

		$subscription = $request->get_subscription();

		if ( ! $subscription->get_subscriber_id() ) {
			return false;
		}

		$cancelled = $subscription->cancel(
			null,
			__( 'Authorize.Net Recurring Payment cancelled due to pause subscription request.', 'LION' ),
			false
		);

		if ( ! $cancelled ) {
			return false;
		}

		$subscription->set_paused_by( $request->get_paused_by() );

		return true;
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === ITE_Pause_Subscription_Request::get_name(); }
}