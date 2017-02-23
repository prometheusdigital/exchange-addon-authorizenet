<?php
/**
 * Purchase Request Handler.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class ITE_AuthorizeNet_Purchase_Request_Handler
 */
class ITE_AuthorizeNet_Purchase_Request_Handler extends ITE_Dialog_Purchase_Request_Handler implements ITE_Gateway_JS_Tokenize_Handler {

	/** @var ITE_AuthorizeNet_Request_Helper */
	private $helper;

	/**
	 * @inheritDoc
	 */
	public function __construct( ITE_Gateway $gateway, ITE_Gateway_Request_Factory $factory, ITE_AuthorizeNet_Request_Helper $js_tokenizer ) {
		parent::__construct( $gateway, $factory );
		$this->helper = $js_tokenizer;
	}

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Gateway_Purchase_Request $request
	 */
	public function handle( $request ) {

		$token = $card_payment = null;

		if ( $request->get_one_time_token() ) {
			$card_payment = $this->generate_payment( $request );
		} elseif ( $request->get_token() ) {
			$token = $request->get_token();
		} elseif ( ( $tokenize = $request->get_tokenize() ) && $this->get_gateway()->can_handle( 'tokenize' ) ) {
			$token = $this->get_gateway()->get_handler_for( $tokenize )->handle( $tokenize );
		} elseif ( $request->get_card() ) {
			$card_payment = $this->generate_payment( $request );
		} else {

			$request->get_cart()->get_feedback()->add_error( __( 'Invalid payment method.', 'LION' ) );

			return null;
		}

		// remove this because it screws up the product titles
		remove_filter( 'the_title', 'wptexturize' );

		$is_subscription = false;
		$request_args    = array(
			'token'        => $token,
			'card_payment' => $card_payment,
		);

		if ( $body = $this->generate_create_subscription( $request, $request_args ) ) {
			$reference_id    = $body['ARBCreateSubscriptionRequest']['refId'];
			$is_subscription = true;

			// this subscription requires a separate create transaction request
			if ( isset( $body['createTransactionRequest'] ) ) {
				$obj = $this->make_request( $request, array( 'createTransactionRequest' => $body['createTransactionRequest'] ) );

				if ( ! $obj || ! isset( $obj['transactionResponse'] ) ) {
					return null;
				}

				$transaction = $obj['transactionResponse'];
				$method_id   = $transaction['transId'];

				if ( empty( $method_id ) ) { // transId is 0 for all test requests. Generate a random one.
					$method_id = substr( uniqid( 'test_' ), 0, 12 );
				}

				switch ( $transaction['responseCode'] ) {
					case '1': //Approved
					case '4': //Held for Review
						$reference_id = $method_id;
						break;
					case '2': //Declined
					case '3': //Error
						try {
							$this->helper->check_for_errors( $transaction );
						} catch ( Exception $e ) {
							$request->get_cart()->get_feedback()->add_error( $e->getMessage() );
						}

						return null;
					default:
						return null;
				}

				unset( $body['createTransactionRequest'] );
			}
		} else {
			$body         = $this->generate_create_transaction( $request, $request_args );
			$reference_id = $body['createTransactionRequest']['refId'];
		}

		$obj = $this->make_request( $request, $body );

		add_filter( 'the_title', 'wptexturize' );

		if ( ! $obj ) {
			return null;
		}

		$transaction = isset( $obj['transactionResponse'] ) ? $obj['transactionResponse'] : null;
		$txn_id      = null;
		$txn_args    = array();

		if ( $request->get_card() ) {
			$txn_args['card'] = $request->get_card();
		} elseif ( $token ) {
			$txn_args['payment_token'] = $token;
		} elseif ( $transaction && ! empty( $transaction['transId'] ) && $request->get_one_time_token() ) {
			$txn_args['card'] = new ITE_Gateway_Card( $transaction['accountNumber'], 0, 0, 0 );
		}

		if ( $is_subscription ) {
			if ( ! empty( $obj['subscriptionId'] ) ) {
				$txn_id = $this->add_transaction( $request, $reference_id, 1, $txn_args );

				if ( function_exists( 'it_exchange_get_transaction_subscriptions' ) ) {
					$subscriptions = it_exchange_get_transaction_subscriptions( it_exchange_get_transaction( $txn_id ) );

					// should be only one
					foreach ( $subscriptions as $subscription ) {
						$subscription->set_subscriber_id( $obj['subscriptionId'] );
						$subscription->set_status_from_gateway_update( IT_Exchange_Subscription::STATUS_ACTIVE );
					}
				}
			} else {

				if ( $transaction ) {
					try {
						$this->helper->check_for_errors( $transaction );
					} catch ( Exception $e ) {
						$request->get_cart()->get_feedback()->add_error( $e->getMessage() );
					}
				}

				return null;
			}
		} elseif ( $transaction ) {
			$method_id = $transaction['transId'];

			if ( empty( $method_id ) ) { // transId is 0 for all test requests. Generate a random one.
				$method_id = substr( uniqid( 'test_' ), 0, 12 );
			}

			switch ( $transaction['responseCode'] ) {
				case '1': //Approved
				case '4': //Held for Review
					$txn_id = $this->add_transaction( $request, $method_id, $transaction['responseCode'], $txn_args );
					break;
				case '2': //Declined
				case '3': //Error
					try {
						$this->helper->check_for_errors( $transaction );
					} catch ( Exception $e ) {
						$request->get_cart()->get_feedback()->add_error( $e->getMessage() );
					}

					return null;
			}
		}

		if ( ! $txn_id ) {
			return null;
		}

		return it_exchange_get_transaction( $txn_id );
	}

	/**
	 * @inheritDoc
	 */
	public function get_tokenize_js_function() { return $this->helper->get_tokenize_js_function(); }

	/**
	 * @inheritDoc
	 */
	public function is_js_tokenizer_configured() { return $this->helper->is_js_tokenizer_configured(); }

	/**
	 * Add the transaction.
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Gateway_Purchase_Request $request
	 * @param string                       $method_id
	 * @param int                          $status
	 * @param array                        $args
	 *
	 * @return int|false
	 */
	protected function add_transaction( ITE_Gateway_Purchase_Request $request, $method_id, $status, $args ) {

		$cart = $request->get_cart();

		if ( $p = $request->get_child_of() ) {
			return it_exchange_add_child_transaction( 'authorizenet', $method_id, $status, $cart, $p->ID, $args );
		} else {
			return it_exchange_add_transaction( 'authorizenet', $method_id, $status, $cart, null, $args );
		}
	}

	/**
	 * Make a request to the API.
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Gateway_Purchase_Request $request
	 * @param array                        $body
	 *
	 * @return array|null
	 */
	protected function make_request( ITE_Gateway_Purchase_Request $request, $body ) {

		$api_url = $this->get_gateway()->is_sandbox_mode() ? AUTHORIZE_NET_AIM_API_SANDBOX_URL : AUTHORIZE_NET_AIM_API_LIVE_URL;
		$body    = apply_filters( 'it_exchange_authorizenet_transaction_fields', $body, $request );

		$query = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode( $body ),
			'timeout' => 30
		);

		$response = wp_remote_post( $api_url, $query );

		if ( is_wp_error( $response ) ) {
			$request->get_cart()->get_feedback()->add_error( $response->get_error_message() );

			return null;
		}

		$body = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
		$obj  = json_decode( $body, true );

		try {
			$this->helper->check_for_errors( $obj );
		} catch ( Exception $e ) {
			$request->get_cart()->get_feedback()->add_error( $e->getMessage() );

			return null;
		}

		return $obj;
	}

	/**
	 * Generate the create transaction request body.
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Gateway_Purchase_Request $request
	 * @param array                        $args
	 *
	 * @return array
	 */
	protected function generate_create_transaction( ITE_Gateway_Purchase_Request $request, array $args ) {

		$customer = $request->get_customer();
		$cart     = $request->get_cart();

		$body = array(
			'createTransactionRequest' => array(
				'merchantAuthentication' => $this->generate_merchant_authentication( $request ),
				'refId'                  => substr( it_exchange_create_unique_hash(), 20 ),
				'transactionRequest'     => array(
					'transactionType' => 'authCaptureTransaction',
					'amount'          => isset( $args['total'] ) ? $args['total'] : $cart->get_total(),
					'payment'         => null,
					'profile'         => null,
					'order'           => array(
						'description' => it_exchange_get_cart_description( array( 'cart' => $cart ) ),
					),
					'customer'        => array(
						'id'    => null,
						'email' => null,
					),
					'billTo'          => $this->generate_bill_to( $request->get_cart() ),
				),
			),
		);

		if ( $customer ) {
			$body['createTransactionRequest']['transactionRequest']['customer']['email'] = $customer->get_email();
			if ( is_numeric( $customer->get_ID() ) ) {
				$body['createTransactionRequest']['transactionRequest']['customer']['id'] = $customer->get_ID();
			} else {
				unset( $body['createTransactionRequest']['transactionRequest']['customer']['id'] );
			}
		} else {
			unset( $body['createTransactionRequest']['transactionRequest']['customer'] );
		}

		if ( isset( $args['token'] ) ) {
			$body['createTransactionRequest']['transactionRequest']['profile'] = array(
				'customerProfileId' => it_exchange_authorizenet_get_customer_profile_id( $customer->get_ID() ),
				'paymentProfile'    => array( 'paymentProfileId' => $args['token']->token, ),
			);
			unset( $body['createTransactionRequest']['transactionRequest']['billTo'] );
			unset( $body['createTransactionRequest']['transactionRequest']['payment'] );
		} elseif ( isset( $args['card_payment'] ) ) {
			$body['createTransactionRequest']['transactionRequest']['payment'] = $args['card_payment'];
			unset( $body['createTransactionRequest']['transactionRequest']['profile'] );
		}

		if ( $shipping = $this->generate_ship_to( $cart ) ) {
			$body['createTransactionRequest']['transactionRequest']['shipTo'] = $shipping;
		}

		$body['createTransactionRequest']['transactionRequest']['retail']['marketType'] = 0; // ecommerce
		$body['createTransactionRequest']['transactionRequest']['retail']['deviceType'] = 8; // Website

		$settings = $this->get_gateway()->settings()->all();

		if ( ! empty( $settings['authorizenet-test-mode'] ) ) {
			$body['createTransactionRequest']['transactionRequest']['transactionSettings'] = array(
				'setting' => array(
					'settingName'  => 'testRequest',
					'settingValue' => true
				)
			);
		}

		return $body;
	}

	/**
	 * Generate create subscription request.
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Gateway_Purchase_Request $request
	 * @param array                        $args
	 *
	 * @return array
	 */
	protected function generate_create_subscription( ITE_Gateway_Purchase_Request $request, array $args ) {

		/** @var ITE_Cart_Product $subscription_product */
		$sub_payment_schedule = $this->generate_payment_schedule( $request, $subscription_product );

		if ( ! $sub_payment_schedule ) {
			return array();
		}

		$cart     = $request->get_cart();
		$customer = $request->get_customer();

		$total       = $cart->get_total();
		$one_time    = $subscription_product->get_line_items()->with_only( 'fee' )
		                                    ->filter( function ( ITE_Fee_Line_Item $fee ) { return ! $fee->is_recurring(); } );
		$sign_up_fee = $one_time->not_having_param( 'is_free_trial' );

		$otf_total        = $one_time->total();
		$otf_sum          = $one_time->flatten()->summary_only()->total();
		$recurring_amount = $total - ( $otf_total + $otf_sum );

		$body = array(
			'ARBCreateSubscriptionRequest' => array(
				'merchantAuthentication' => $this->generate_merchant_authentication( $request ),
				'refId'                  => substr( it_exchange_create_unique_hash(), 20 ),
				'subscription'           => array(
					'name'            => it_exchange_get_cart_description( array( 'cart' => $cart ) ),
					'paymentSchedule' => $sub_payment_schedule,
					'amount'          => $recurring_amount,
					'order'           => array(
						'description' => it_exchange_get_cart_description( array( 'cart' => $cart ) ),
					),
					'customer'        => array(
						'id'    => null,
						'email' => null,
					),
					'billTo'          => $this->generate_bill_to( $cart ),
				),
			),
		);

		if ( $customer ) {
			$body['ARBCreateSubscriptionRequest']['subscription']['customer']['email'] = $customer->get_email();
			if ( is_numeric( $customer->get_ID() ) ) {
				$body['ARBCreateSubscriptionRequest']['subscription']['customer']['id'] = $customer->get_ID();
			} else {
				unset( $body['ARBCreateSubscriptionRequest']['subscription']['customer']['id'] );
			}
		} else {
			unset( $body['ARBCreateSubscriptionRequest']['subscription']['customer'] );
		}

		if ( isset( $args['token'] ) ) {
			$body['ARBCreateSubscriptionRequest']['subscription']['profile'] = array(
				'customerProfileId'        => it_exchange_authorizenet_get_customer_profile_id( $customer->get_ID() ),
				'customerPaymentProfileId' => $args['token']->token,
			);
			unset( $body['ARBCreateSubscriptionRequest']['subscription']['billTo'] );
			unset( $body['ARBCreateSubscriptionRequest']['subscription']['customer'] );
		} elseif ( isset( $args['card_payment'] ) ) {
			$body['ARBCreateSubscriptionRequest']['subscription']['payment'] = $args['card_payment'];
		}

		if ( $shipping = $this->generate_ship_to( $cart ) ) {
			$body['ARBCreateSubscriptionRequest']['subscription']['shipTo'] = $shipping;
		}

		if ( $sign_up_fee->total() ) {
			$args['total'] = $sign_up_fee->total() + $sign_up_fee->flatten()->summary_only()->total();
			$transaction   = $this->generate_create_transaction( $request, $args );

			$body['createTransactionRequest'] = $transaction['createTransactionRequest'];
		}

		return $body;
	}

	/**
	 * Generate the merchant authentication packet.
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Gateway_Purchase_Request $request
	 *
	 * @return array
	 */
	protected function generate_merchant_authentication( ITE_Gateway_Purchase_Request $request ) {

		$settings   = $this->get_gateway()->settings()->all();
		$is_sandbox = $this->get_gateway()->is_sandbox_mode();

		$api_username = $is_sandbox ? $settings['authorizenet-sandbox-api-login-id'] : $settings['authorizenet-api-login-id'];
		$api_password = $is_sandbox ? $settings['authorizenet-sandbox-transaction-key'] : $settings['authorizenet-transaction-key'];

		return array(
			'name'           => $api_username,
			'transactionKey' => $api_password,
		);
	}

	/**
	 * Generate the payment schedule.
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Gateway_Purchase_Request $request
	 * @param ITE_Cart_Product             $cart_product
	 *
	 * @return array
	 */
	protected function generate_payment_schedule( ITE_Gateway_Purchase_Request $request, &$cart_product ) {

		$cart = $request->get_cart();

		/** @var ITE_Cart_Product $cart_product */
		$cart_product = $cart->get_items( 'product' )->filter( function ( ITE_Cart_Product $product ) {
			return $product->get_product()->has_feature( 'recurring-payments', array( 'setting' => 'auto-renew' ) );
		} )->first();

		if ( ! $cart_product ) {
			return array();
		}

		$product = $cart_product->get_product();
		$bc      = $cart_product->bc();

		$interval       = $product->get_feature( 'recurring-payments', array( 'setting' => 'interval' ) );
		$interval_count = $product->get_feature( 'recurring-payments', array( 'setting' => 'interval-count' ) );

		switch ( $interval ) {
			case 'year':
				$duration = 12; //The max you can do in Authorize.Net is 12 months (1 year)
				$unit     = 'months'; //lame
				break;
			case 'week':
				$duration = $interval_count * 7;
				$unit     = 'days'; //lame
				break;
			case 'day':
				$duration = $interval_count;
				$unit     = 'days';
				break;
			case 'month':
			default:
				$duration = $interval_count;
				$unit     = 'months';
				break;
		}

		$duration   = apply_filters( 'it_exchange_authorizenet_addon_process_transaction_subscription_duration', $duration, $bc, $request );
		$start_date = time();

		$trial_profile = it_exchange_get_recurring_product_trial_profile( $product );

		if ( $trial_profile ) {

			$allow_trial = it_exchange_is_customer_eligible_for_trial( $product, $cart->get_customer() );
			$allow_trial = apply_filters( 'it_exchange_authorizenet_addon_process_transaction_allow_trial', $allow_trial, $product->get_ID(), $request );

			if ( $allow_trial ) {
				$start_date += $trial_profile->get_interval_seconds();
			}
		}

		if ( $request instanceof ITE_Gateway_Prorate_Purchase_Request && ( $prorates = $request->get_prorate_requests() ) ) {
			if ( $end_at = $this->get_trial_end_at_for_prorate( $request ) ) {
				$start_date = $end_at;
			}
		}

		/**
		 * Filter the start date for the subscription.
		 *
		 * @since 2.0.0
		 *
		 * @param int                          $start_date
		 * @param ITE_Gateway_Purchase_Request $request
		 */
		$start_date = apply_filters( 'it_exchange_authorizenet_process_transaction_subscription_start_date', $start_date, $request );

		// Set the start date. Time Zone is set to Authorize.Net's Server timezone which is Mountain.
		$start_date = new DateTime( "@{$start_date}", new DateTimeZone( 'America/Denver' ) );

		return array(
			'interval'         => array(
				'length' => $duration,
				'unit'   => $unit,
			),
			'startDate'        => $start_date->format( 'Y-m-d' ),
			// To submit a subscription with no end date (an ongoing subscription), this field must be submitted with a value of “9999.”
			'totalOccurrences' => 9999,
		);
	}

	/**
	 * Get the trial end at time for a prorate purchase request.
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Gateway_Prorate_Purchase_Request $request
	 *
	 * @return int
	 */
	protected function get_trial_end_at_for_prorate( ITE_Gateway_Prorate_Purchase_Request $request ) {

		/** @var ITE_Cart_Product $cart_product */
		$cart_product = $request->get_cart()->get_items( 'product' )->filter( function ( ITE_Cart_Product $product ) {
			return $product->get_product()->has_feature( 'recurring-payments', array( 'setting' => 'auto-renew' ) );
		} )->first();

		if ( $cart_product && $cart_product->get_product() ) {

			$product = $cart_product->get_product();

			if ( isset( $prorates[ $product->ID ] ) && $prorates[ $product->ID ]->get_credit_type() === 'days' ) {

				if ( $prorates[ $product->ID ]->get_free_days() ) {
					return time() + ( $prorates[ $product->ID ]->get_free_days() * DAY_IN_SECONDS );
				}
			}
		}

		return 0;
	}

	/**
	 * Generate the payment info.
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Gateway_Purchase_Request $request
	 *
	 * @return array
	 */
	protected function generate_payment( ITE_Gateway_Purchase_Request $request ) {

		$cc = $request->get_card();

		if ( $cc ) {
			return array(
				'creditCard' => array(
					'cardNumber'     => $cc->get_number(),
					'expirationDate' => $cc->get_expiration_month() . $cc->get_expiration_year(),
					'cardCode'       => $cc->get_cvc(),
				),
			);
		} elseif ( $one_time = $request->get_one_time_token() ) {
			return array(
				'opaqueData' => array(
					'dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
					'dataValue'      => $one_time,
				)
			);
		} else {
			return array();
		}
	}

	/**
	 * Generate the ship to address.
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Cart $cart
	 *
	 * @return array
	 */
	protected function generate_ship_to( ITE_Cart $cart ) {

		$shipping = $cart->get_shipping_address();

		if ( ! $shipping ) {
			return array();
		}

		return array(
			'address' => $shipping['address1'] . $shipping['address2'] ? ', ' . $shipping['address2'] : '',
			'city'    => $shipping['city'],
			'state'   => $shipping['state'],
			'zip'     => preg_replace( '/[^A-Za-z0-9\-]/', '', $shipping['zip'] ),
			'country' => $shipping['country'],
		);
	}

	/**
	 * Generate the ship to address.
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Cart $cart
	 *
	 * @return array
	 */
	protected function generate_bill_to( ITE_Cart $cart ) {

		$billing = $cart->get_billing_address();

		if ( ! $billing ) {
			return array();
		}

		$country = $billing['country'];

		if ( $this->get_gateway()->settings()->get( 'evosnap-international' ) && function_exists( 'it_exchange_convert_country_code' ) ) {
			$country = it_exchange_convert_country_code( $country );
		}

		return array(
			'firstName' => $billing['first-name'],
			'lastName'  => $billing['last-name'],
			'address'   => $billing['address1'] . $billing['address2'] ? ', ' . $billing['address2'] : '',
			'city'      => $billing['city'],
			'state'     => $billing['state'],
			'zip'       => preg_replace( '/[^A-Za-z0-9\-]/', '', $billing['zip'] ),
			'country'   => $country,
		);
	}
}