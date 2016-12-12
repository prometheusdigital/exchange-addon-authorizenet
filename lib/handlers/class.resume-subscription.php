<?php
/**
 * Resume Subscription request.
 *
 * @since   1.5.0
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

		$s = $request->get_subscription();
		$transaction  = $s->get_transaction();

		if ( ! $s->get_payment_token() || ! $s->get_expiry_date() ) {
			return false;
		}

		$repo = new ITE_Line_Item_Cached_Session_Repository(
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

		$transaction = $purchase_handler->handle( $purchase_request );

		remove_filter( 'it_exchange_authorizenet_process_transaction_subscription_start_date', $fn );

		if ( $transaction ) {
			return true;
		}

		if ( $cart->get_feedback() && count( $cart->get_feedback()->errors() ) ) {
			$message = '';

			foreach ( $cart->get_feedback()->errors() as $error ) {
				$message .= $error;
			}

			throw new UnexpectedValueException( $message );
		}

		return false;
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === ITE_Resume_Subscription_Request::get_name(); }
}