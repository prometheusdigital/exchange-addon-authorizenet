<?php
/**
 * Authorize.Net Webhook Handler.
 *
 * @since   1.5.0
 * @license GPLv2
 */

/**
 * Class ITE_AuthorizeNet_Webhook_Handler
 */
class ITE_AuthorizeNet_Webhook_Handler implements ITE_Gateway_Request_Handler {

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Webhook_Gateway_Request $request
	 */
	public function handle( $request ) {

		$webhook = $request->get_webhook_data();

		if ( ! empty( $webhook['x_trans_id'] ) && ! empty( $webhook['x_MD5_Hash'] ) ) {
			$settings = it_exchange_get_option( 'addon_authorizenet' );
			$secret   = empty( $settings['authorizenet-sandbox-mode'] ) ? $settings['authorizenet-md5-hash'] : $settings['authorizenet-sandbox-md5-hash'];
			$txn_id   = $webhook['x_trans_id'];
			$amount   = ! empty( $webhook['x_amount'] ) ? $webhook['x_amount'] : '0.00';

			$sent_md5 = strtoupper( $webhook['x_MD5_Hash'] );
			$made_md5 = strtoupper( md5( $secret . $txn_id . $amount ) );

			if ( ! hash_equals( $sent_md5, $made_md5 ) ) {

				$message = __( 'Unable to validate Silent Post from Authorize.Net.', 'it-l10n-ithemes-exchange' );
				$message .= sprintf(
					__( 'Please double check your MD5 Hash Value in the Authorize.Net settings in iThemes Exchange and your Authorize.Net account: %s', 'LION' ),
					maybe_serialize( $webhook )
				);

				error_log( $message );

				return;
			}

			$subscriber_id = ! empty( $webhook['x_subscription_id'] ) ? $webhook['x_subscription_id'] : false;
			$transactions  = it_exchange_authorizenet_addon_get_transaction_id_by_subscriber_id( $subscriber_id );

			foreach ( $transactions as $transaction ) {
				try {
					$subscription = it_exchange_get_subscription_by_transaction( $transaction );
					break;
				} catch ( Exception $e ) {
					return;
				}
			}

			if ( ! isset( $subscription ) ) {
				return;
			}

			switch ( (int) $webhook['x_response_code'] ) {
				case 1:
					$GLOBALS['it_exchange']['child_transaction'] = true;
					it_exchange_authorizenet_addon_add_child_transaction( $txn_id, '1', $subscriber_id, $amount ); //1 = Paid

					if ( $subscription->get_status() !== IT_Exchange_Subscription::STATUS_ACTIVE ) {
						$subscription->set_status( IT_Exchange_Subscription::STATUS_ACTIVE );
					}
					break;
				case 2:
				case 3:

					if ( $subscription->get_status() !== IT_Exchange_Subscription::STATUS_SUSPENDED ) {
						$subscription->set_status( IT_Exchange_Subscription::STATUS_SUSPENDED );
					}

					break;
			}

		} else {
			error_log( sprintf( __( 'Invalid Silent Post sent from Authorize.Net: %s', 'it-l10n-ithemes-exchange' ), maybe_serialize( $webhook ) ) );
		}
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'webhook'; }
}