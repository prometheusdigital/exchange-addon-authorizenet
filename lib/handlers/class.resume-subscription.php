<?php
/**
 * Resume Subscription request.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class ITE_AuthorizeNet_Resume_Subscription_Handler
 */
class ITE_AuthorizeNet_Resume_Subscription_Handler implements ITE_Gateway_Request_Handler {

	/** @var ITE_Gateway */
	private $gateway;

	/** @var ITE_Gateway_Request_Factory */
	private $request_factory;

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Resume_Subscription_Request $request
	 */
	public function handle( $request ) {

		$s           = $request->get_subscription();
		$transaction = $s->get_transaction();

		if ( ! $s->get_payment_token() || ! $s->get_expiry_date() ) {
			return false;
		}

		$repo = new ITE_Cart_Cached_Session_Repository(
			new IT_Exchange_In_Memory_Session( null ),
			$request->get_customer(),
			new ITE_Line_Item_Repository_Events()
		);

		$cart = $transaction->cart()->with_new_repository( $repo, true );

		/** @var ITE_Purchase_Request_Handler $purchase_handler */
		$purchase_handler = $this->gateway->get_handler_by_request_name( 'purchase' );
		$purchase_request = $this->request_factory->make( 'purchase', array(
			'cart'     => $cart,
			'token'    => $s->get_payment_token(),
			'nonce'    => $purchase_handler->get_nonce(),
			'child_of' => $transaction,
		) );

		add_filter( 'it_exchange_authorizenet_process_transaction_subscription_start_date', $fn = function () use ( $s ) {
			return $s->get_expiry_date()->getTimestamp();
		} );

		it_exchange_log( 'Making Authorize.Net subscription #{sub_id} renewal payment for transaction {txn_id}.', ITE_Log_Levels::DEBUG, array(
			'sub_id' => $s->get_subscriber_id(),
			'txn_id' => $s->get_transaction()->get_ID(),
			'_group' => 'subscription',
		) );

		$transaction = $purchase_handler->handle( $purchase_request );

		remove_filter( 'it_exchange_authorizenet_process_transaction_subscription_start_date', $fn );

		if ( $transaction ) {
			it_exchange_log( 'Resumed Authorize.Net subscription #{sub_id} for transaction {txn_id}.', ITE_Log_Levels::INFO, array(
				'sub_id' => $s->get_subscriber_id(),
				'txn_id' => $s->get_transaction()->get_ID(),
				'_group' => 'subscription',
			) );

			return true;
		}

		if ( $cart->get_feedback() && count( $cart->get_feedback()->errors() ) ) {
			$message = '';

			foreach ( $cart->get_feedback()->errors() as $error ) {
				$message .= $error . ' ';
			}

			it_exchange_log( 'Failed to resume Authorize.Net subscription #{sub_id} for transaction {txn_id}: {reason}.', array(
				'sub_id' => $s->get_subscriber_id(),
				'txn_id' => $s->get_transaction()->get_ID(),
				'_group' => 'subscription',
				'reason' => trim( $message ),
			) );

			throw new UnexpectedValueException( trim( $message ) );
		}

		it_exchange_log( 'Failed to resume Authorize.Net subscription #{sub_id} for transaction {txn_id}.', array(
			'sub_id' => $s->get_subscriber_id(),
			'txn_id' => $s->get_transaction()->get_ID(),
			'_group' => 'subscription',
		) );

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === ITE_Resume_Subscription_Request::get_name(); }
}