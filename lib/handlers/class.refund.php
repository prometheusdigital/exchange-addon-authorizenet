<?php
/**
 * Authorize.Net Refund Handler.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class ITE_AuthorizeNet_Refund_Request_Handler
 */
class ITE_AuthorizeNet_Refund_Request_Handler implements ITE_Gateway_Request_Handler {

	/** @var ITE_Gateway */
	private $gateway;

	/**
	 * ITE_AuthorizeNet_Refund_Request_Handler constructor.
	 *
	 * @param ITE_Gateway $gateway
	 */
	public function __construct( ITE_Gateway $gateway ) { $this->gateway = $gateway; }

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Gateway_Refund_Request $request
	 */
	public function handle( $request ) {

		$transaction = $request->get_transaction();

		$settings = $this->gateway->settings()->all();

		if ( $transaction->is_sandbox_purchase() ) {
			$is_sandbox = true;
		} elseif ( $transaction->is_live_purchase() ) {
			$is_sandbox = false;
		} else {
			$is_sandbox = $this->gateway->is_sandbox_mode();
		}

		$api_url      = $is_sandbox ? AUTHORIZE_NET_AIM_API_SANDBOX_URL : AUTHORIZE_NET_AIM_API_LIVE_URL;
		$api_username = $is_sandbox ? $settings['authorizenet-sandbox-api-login-id'] : $settings['authorizenet-api-login-id'];
		$api_password = $is_sandbox ? $settings['authorizenet-sandbox-transaction-key'] : $settings['authorizenet-transaction-key'];

		$card_number = '';
		$source      = $transaction->get_payment_source();

		if ( $source instanceof ITE_Gateway_Card ) {
			$card_number = $source->get_redacted_number();
		} elseif ( $source instanceof ITE_Payment_Token ) {
			$card_number = $source->redacted;
		}

		if ( ! $card_number ) {
			throw new InvalidArgumentException( 'Transaction unable to be refunded.' );
		}

		$body = array(
			'createTransactionRequest' => array(
				'merchantAuthentication' => array(
					'name'           => $api_username,
					'transactionKey' => $api_password,
				),
				'transactionRequest'     => array(
					'transactionType' => 'refundTransaction',
					'amount'          => $request->get_amount(),
					'payment'         => array(
						'creditCard' => array(
							'cardNumber'     => $card_number,
							'expirationDate' => 'XXXX',
						)
					),
					'refTransId'      => $transaction->get_method_id(),
				)
			)
		);

		$query = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode( $body ),
		);

		// Make sure we update the subscription before the webhook handler does.
		$response = wp_remote_post( $api_url, $query );

		if ( is_wp_error( $response ) ) {
			throw new UnexpectedValueException( $response->get_error_message() );
		}

		$body     = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
		$response = json_decode( $body, true );

		if ( isset( $response['messages'], $response['messages']['resultCode'] ) && $response['messages']['resultCode'] === 'Error' ) {
			if ( ! empty( $response['messages']['message'] ) ) {
				$error = reset( $response['messages']['message'] );

				if ( $error && is_string( $error ) ) {
					throw new UnexpectedValueException( $error );
				} elseif ( is_array( $error ) && isset( $error['text'] ) ) {
					throw new UnexpectedValueException( $error['text'] );
				}
			}

			return null;
		}

		return ITE_Refund::create( array(
			'transaction' => $transaction,
			'amount'      => $request->get_amount(),
			'gateway_id'  => $response['transactionResponse']['transId'],
			'reason'      => $request->get_reason(),
			'issued_by'   => $request->issued_by(),
		) );
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'refund'; }
}