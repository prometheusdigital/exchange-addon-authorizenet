<?php
/**
 * Authorize.Net Refund Handler.
 *
 * @since   1.5.0
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

		$id = it_exchange_create_unique_hash();

		$request = array(
			'createTransactionRequest' => array(
				'merchantAuthentication' => array(
					'name'           => $api_username,
					'transactionKey' => $api_password,
				),
				'refId'                  => $id,
				'transactionRequest'     => array(
					'transactionType' => 'refundTransaction',
					'amount'          => $request->get_amount(),
					'refTransId'      => $transaction->get_method_id(),
					'payment'         => array(
						'creditCard' => array(
							'cardNumber'     => $transaction->get_meta( 'authorize_net_last_4' ),
							'expirationDate' => 'XXXX',
						)
					)
				)
			)
		);

		$query = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode( $request ),
		);

		// Make sure we update the subscription before the webhook handler does.
		$response = wp_remote_post( $api_url, $query );

		if ( ! is_wp_error( $response ) ) {
			$body = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
			$obj  = json_decode( $body, true );

			if ( isset( $obj['messages'] ) && isset( $obj['messages']['resultCode'] ) && $obj['messages']['resultCode'] == 'Error' ) {
				if ( ! empty( $obj['messages']['message'] ) ) {
					$error = reset( $obj['messages']['message'] );

					throw new UnexpectedValueException( $error );
				}
			}
		} else {
			throw new UnexpectedValueException( $response->get_error_message() );
		}

		$refund = ITE_Refund::create( array(
			'transaction' => $transaction,
			'amount'      => $request->get_amount(),
			'gateway_id'  => $id,
			'reason'      => $request->get_reason(),
			'issued_by'   => $request->issued_by(),
		) );

		return $refund;
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'refund'; }
}