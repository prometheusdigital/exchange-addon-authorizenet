<?php
/**
 * Update Subscription Payment Method.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class ITE_AuthorizeNet_Update_Subscription_Payment_Method_Handler
 */
class ITE_AuthorizeNet_Update_Subscription_Payment_Method_Handler implements ITE_Gateway_Request_Handler {

	/** @var ITE_Gateway */
	private $gateway;

	/** @var ITE_Daily_Price_Calculator */
	private $daily_cost;

	/** @var ITE_Gateway_Request_Factory */
	private $factory;

	/**
	 * ITE_AuthorizeNet_Update_Subscription_Payment_Method_Handler constructor.
	 *
	 * @param ITE_Gateway                 $gateway
	 * @param ITE_Daily_Price_Calculator  $daily_cost
	 * @param ITE_Gateway_Request_Factory $factory
	 */
	public function __construct( ITE_Gateway $gateway, ITE_Daily_Price_Calculator $daily_cost, ITE_Gateway_Request_Factory $factory ) {
		$this->gateway    = $gateway;
		$this->daily_cost = $daily_cost;
		$this->factory    = $factory;
	}

	/**
	 * @inheritDoc
	 *
	 * @param $request ITE_Update_Subscription_Payment_Method_Request
	 *
	 * @throws \InvalidArgumentException
	 * @throws \UnexpectedValueException
	 */
	public function handle( $request ) {

		$subscription = $request->get_subscription();

		if ( ! $subscription->get_subscriber_id() ) {
			return false;
		}

		$card  = $request->get_card();
		$token = $request->get_payment_token();

		if ( $tokenize = $request->get_tokenize() ) {
			$token = $this->gateway->get_handler_for( $tokenize )->handle( $tokenize );
		}

		if ( ! $token && ! $card ) {
			throw new InvalidArgumentException( 'Unable to update payment with given information.' );
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

		if (
			$subscription->is_status( IT_Exchange_Subscription::STATUS_PAYMENT_FAILED ) &&
			$cost = $this->calculate_cost_for_days_missed( $subscription )
		) {

			$cart = ITE_Cart::create(
				new ITE_Line_Item_Cached_Session_Repository(
					new IT_Exchange_In_Memory_Session( null ),
					$subscription->get_customer(),
					new ITE_Line_Item_Repository_Events()
				),
				$subscription->get_customer()
			);

			$fee = ITE_Fee_Line_Item::create( __( 'Subscription Reactivation', 'LION' ), $cost );
			$cart->add_item( $fee );

			/** @var ITE_Purchase_Request_Handler $handler */
			$handler = $this->gateway->get_handler_by_request_name( 'purchase' );

			$charge = $this->factory->make( 'purchase', array(
				'cart'     => $cart,
				'nonce'    => $handler->get_nonce(),
				'card'     => $card,
				'token'    => $token,
				'child_of' => $subscription->get_transaction(),
			) );

			$transaction = $handler->handle( $charge );

			if ( ! $transaction ) {
				throw new UnexpectedValueException( 'Unable to process reactivation transaction.' );
			}
		}

		$sub_data = array();

		if ( $token ) {
			$sub_data['profile'] = array(
				'customerProfileId'        => it_exchange_authorizenet_get_customer_profile_id( $subscription->get_customer()->get_ID() ),
				'customerPaymentProfileId' => $token->token,
			);
		} elseif ( $card ) {
			$sub_data['payment']['creditCard'] = array(
				'cardNumber'     => $card->get_number(),
				'expirationDate' => $card->get_expiration_year() . '-' . $card->get_expiration_month(),
				'cardCode'       => $card->get_cvc(),
			);
		}

		$api_request = array(
			'ARBUpdateSubscriptionRequest' => array(
				'merchantAuthentication' => array(
					'name'           => $api_username,
					'transactionKey' => $api_password,
				),
				'subscriptionId'         => $subscription->get_subscriber_id(),
				'subscription'           => $sub_data,
			)
		);

		$query = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode( $api_request ),
		);

		$response = wp_remote_post( $api_url, $query );

		if ( ! is_wp_error( $response ) ) {
			$body = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
			$obj  = json_decode( $body, true );

			if ( isset( $obj['messages'], $obj['messages']['resultCode'] ) && $obj['messages']['resultCode'] === 'Error' ) {
				if ( ! empty( $obj['messages']['message'] ) ) {
					$error = reset( $obj['messages']['message'] );

					if ( $error ) {
						throw new InvalidArgumentException( $error );
					}

					return false;
				}

				return false;
			}
		} else {
			throw new UnexpectedValueException( $response->get_error_message() );
		}

		if ( $subscription->is_status( IT_Exchange_Subscription::STATUS_PAYMENT_FAILED ) ) {
			$subscription->set_status( IT_Exchange_Subscription::STATUS_ACTIVE );
		}

		$subscription->set_card( $card );
		$subscription->set_payment_token( $token );

		return true;
	}

	/**
	 * Calculate the cost for the days that will be missed by Authorize.Net.
	 *
	 * Auth.net won't retry the subscription until the next payment cycle, so we must charge the customer
	 * ourselves for the time that will be missed in the Auth.net window.
	 *
	 * Take for example a weekly subscription. The subscription was initially purchased on January 1st.
	 * One January 8th ( 1 week later ), the retry in Authorize.Net failed. The customer has until January 15th
	 * to update their payment method. On January 10th, the customer updates their payment method. Auth.net won't retry
	 * their subscription payment until the 15th, but the customer expects that they will have access immediately. So,
	 * we must charge the customer the daily cost of the subscription for the 5 days of access before the subscription
	 * will be reattempted.
	 *
	 * @since 2.0.0
	 *
	 * @param IT_Exchange_Subscription $subscription
	 *
	 * @return float
	 */
	protected function calculate_cost_for_days_missed( IT_Exchange_Subscription $subscription ) {

		// In our example, this would be January 8
		$expired          = $subscription->get_expiry_date();
		$today            = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$profile          = $subscription->get_recurring_profile();
		$recurring_amount = $subscription->calculate_recurring_amount_paid();

		// 7 days.
		$days       = floor( $profile->get_interval_seconds() / DAY_IN_SECONDS );
		$daily_cost = $this->daily_cost->calculate( $profile, $recurring_amount );

		$days_unused = $expired->diff( $today )->days; // 2 days
		$days_left   = $days - $days_unused;           // 5 days

		return max( $days_left * $daily_cost, 0 );
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) {
		return $request_name === ITE_Update_Subscription_Payment_Method_Request::get_name();
	}
}