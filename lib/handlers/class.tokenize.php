<?php
/**
 * Authorize.Net Tokenize Handler.
 *
 * @since   1.5.0
 * @license GPLv2
 */

/**
 * Class ITE_AuthorizeNet_Tokenize_Request_Handler
 */
class ITE_AuthorizeNet_Tokenize_Request_Handler implements ITE_Gateway_Request_Handler, ITE_Gateway_JS_Tokenize_Handler {

	/** @var ITE_Gateway */
	private $gateway;

	/**
	 * ITE_AuthorizeNet_Tokenize_Request_Handler constructor.
	 *
	 * @param ITE_Gateway $gateway
	 */
	public function __construct( ITE_Gateway $gateway ) { $this->gateway = $gateway; }

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Gateway_Tokenize_Request $request
	 */
	public function handle( $request ) {

		$customer_profile_id = it_exchange_authorizenet_get_customer_profile_id( $request->get_customer()->get_ID() );

		if ( ! $customer_profile_id ) {
			$customer_profile_id = $this->create_customer_profile( $request );
		}

		return $this->create_payment_profile( $customer_profile_id, $request );
	}

	/**
	 * Create the payment profile.
	 *
	 * @since 2.0.0
	 *
	 * @param int                          $customer_profile_id
	 * @param ITE_Gateway_Tokenize_Request $request
	 *
	 * @return ITE_Payment_Token
	 */
	protected function create_payment_profile( $customer_profile_id, ITE_Gateway_Tokenize_Request $request ) {

		$payment = $this->generate_payment_profile( $request->get_source_to_tokenize() );

		if ( ! $payment ) {
			throw new InvalidArgumentException( 'Invalid tokenization source.' );
		}

		$body = array(
			'createCustomerPaymentProfileRequest' => array(
				'merchantAuthentication' => $this->get_merchant_auth(),
				'customerProfileId'      => $customer_profile_id,
				'paymentProfile'         => array(
					'customerType' => 'individual',
					'billTo'       => null,
					'payment'      => $payment,
				),
				'validationMode'         => $this->get_gateway()->is_sandbox_mode() ? 'testMode' : 'liveMode',
			),
		);

		if ( $bill_to = $this->generate_address( $request->get_address() ) ) {
			$body['createCustomerPaymentProfileRequest']['paymentProfile']['billTo'] = $bill_to;
		} else {
			unset( $body['createCustomerPaymentProfileRequest']['paymentProfile']['billTo'] );
		}

		$query = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode( $body ),
			'timeout' => 30
		);

		$response = wp_remote_post( $this->get_api_url(), $query );

		if ( is_wp_error( $response ) ) {
			throw new UnexpectedValueException( $response->get_error_message() );
		}

		$body     = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
		$response = json_decode( $body, true );

		$this->check_for_errors( $response );

		return $this->create_token( $response['customerPaymentProfileId'], $request );
	}

	/**
	 * Create a customer profile in Auth.net
	 *
	 * @since 1.5.0
	 *
	 * @param ITE_Gateway_Tokenize_Request $request
	 *
	 * @return ITE_Payment_Token
	 */
	protected function create_customer_profile( ITE_Gateway_Tokenize_Request $request ) {

		$payment = $this->generate_payment_profile( $request->get_source_to_tokenize() );

		if ( ! $payment ) {
			throw new InvalidArgumentException( 'Invalid tokenization source.' );
		}

		$body = array(
			'createCustomerProfileRequest' => array(
				'merchantAuthentication' => $this->get_merchant_auth(),
				'profile'                => array(
					'merchantCustomerId' => $request->get_customer()->get_ID(),
					'email'              => $request->get_customer()->get_email(),
				),
			),
		);

		if ( $ship_to = $this->generate_address( $request->get_customer()->get_shipping_address() ) ) {
			$body['createCustomerProfileRequest']['profile']['shipToList'] = $ship_to;
		}

		$query = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode( $body ),
			'timeout' => 30
		);

		$response = wp_remote_post( $this->get_api_url(), $query );

		if ( is_wp_error( $response ) ) {
			throw new UnexpectedValueException( $response->get_error_message() );
		}

		$body     = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
		$response = json_decode( $body, true );

		$this->check_for_errors( $response );

		if ( empty( $response['customerProfileId'] ) ) {
			throw new UnexpectedValueException( 'Unknown error.' );
		}

		it_exchange_authorizenet_set_customer_profile_id( $request->get_customer()->get_ID(), $response['customerProfileId'] );

		return $response['customerProfileId'];
	}

	/**
	 * Check for errors in the Auth.Net Response.
	 *
	 * @since 1.5.0
	 *
	 * @param array $response
	 *
	 * @throws UnexpectedValueException
	 */
	protected function check_for_errors( $response ) {
		if ( isset( $response['messages'] ) && isset( $response['messages']['resultCode'] ) && $response['messages']['resultCode'] == 'Error' ) {
			if ( ! empty( $response['messages']['message'] ) ) {
				$error = reset( $response['messages']['message'] );

				if ( $error && is_string( $error ) ) {
					throw new UnexpectedValueException( $error );
				} elseif ( is_array( $error ) && isset( $error['text'] ) ) {
					throw new UnexpectedValueException( $error['text'] );
				}
			}

			throw new UnexpectedValueException( 'Unknown error.' );
		}
	}

	/**
	 * Create the token in Exchange.
	 *
	 * @since 1.5.0
	 *
	 * @param int                           $profile_id
	 * @param \ITE_Gateway_Tokenize_Request $request
	 *
	 * @return ITE_Payment_Token
	 */
	protected function create_token( $profile_id, ITE_Gateway_Tokenize_Request $request ) {

		$token  = null;
		$source = $request->get_source_to_tokenize();

		$attr = array(
			'gateway'  => $this->get_gateway()->get_slug(),
			'customer' => $request->get_customer()->get_ID(),
			'token'    => $profile_id,
			'label'    => $request->get_label(),
		);

		if ( is_string( $source ) ) {
			$token = ITE_Payment_Token_Card::create( $attr );
		} elseif ( $source instanceof ITE_Gateway_Card ) {
			$attr['redacted'] = $source->get_redacted_number();

			$token = ITE_Payment_Token_Card::create( $attr );

			if ( $token ) {
				$token->set_expiration_month( $source->get_expiration_month() );
				$token->set_expiration_year( $source->get_expiration_year() );
			}

		} elseif ( $source instanceof ITE_Gateway_Bank_Account ) {
			$attr['redacted'] = $source->get_redacted_account_number();

			$token = ITE_Payment_Token_Bank_Account::create( $attr );

			if ( $token ) {
				$token->set_account_type( $source->get_type() );
			}
		}

		if ( $token && $request->should_set_as_primary() ) {
			$token->make_primary();
		}

		return $token;
	}

	/**
	 * Generate a payment profile for a tokenization source.
	 *
	 * @since 1.5.0
	 *
	 * @param string|ITE_Gateway_Card|ITE_Gateway_Bank_Account $source
	 *
	 * @return array
	 */
	protected function generate_payment_profile( $source ) {

		if ( is_string( $source ) ) {
			return array(
				'opaqueData' => array(
					'dataDescriptor' => 'COMMON.ACCEPT.INAPP.PAYMENT',
					'dataValue'      => $source,
				),
			);
		}

		if ( $source instanceof ITE_Gateway_Card ) {
			return array(
				'creditCard' => array(
					'cardNumber'     => $source->get_number(),
					'expirationDate' => $source->get_expiration_month() . $source->get_expiration_year(),
					'cardCode'       => $source->get_cvc(),
				),
			);
		}

		if ( $source instanceof ITE_Gateway_Bank_Account ) {
			return array(
				'bankAccount' => array(
					'accountType'   => $source->get_type() === 'company' ? 'businessChecking' : 'checking',
					'routingNumber' => $source->get_routing_number(),
					'accountNumber' => $source->get_account_number(),
					'nameOnAccount' => $source->get_holder_name(),
					'echeckType'    => 'WEB',
				),
			);
		}

		return array();
	}

	/**
	 * Get merchant authentication.
	 *
	 * @since 1.5.0
	 *
	 * @return array
	 */
	protected function get_merchant_auth() {

		$settings   = $this->get_gateway()->settings()->all();
		$is_sandbox = $this->get_gateway()->is_sandbox_mode();

		$api_username = $is_sandbox ? $settings['authorizenet-sandbox-api-login-id'] : $settings['authorizenet-api-login-id'];
		$api_password = $is_sandbox ? $settings['authorizenet-sandbox-transaction-key'] : $settings['authorizenet-transaction-key'];

		return array( 'name' => $api_username, 'transactionKey' => $api_password );
	}

	/**
	 * Get the API url.
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	protected function get_api_url() {
		$is_sandbox = $this->get_gateway()->is_sandbox_mode();

		return $is_sandbox ? AUTHORIZE_NET_AIM_API_SANDBOX_URL : AUTHORIZE_NET_AIM_API_LIVE_URL;
	}

	/**
	 * Get the gateway.
	 *
	 * @since 1.5.0
	 *
	 * @return ITE_Gateway
	 */
	public function get_gateway() {
		return $this->gateway;
	}

	/**
	 * Generate an address formatted for Auth.net from an ITE_Location.
	 *
	 * @since 1.5.0
	 *
	 * @param ITE_Location|null $location
	 *
	 * @return array
	 */
	protected function generate_address( ITE_Location $location = null ) {

		if ( ! $location || empty( $location['address1'] ) ) {
			return array();
		}

		$country = $location['country'];

		if ( $this->get_gateway()->settings()->get( 'evosnap-international' ) && function_exists( 'it_exchange_convert_country_code' ) ) {
			$country = it_exchange_convert_country_code( $country );
		}

		return array(
			'firstName' => $location['first-name'],
			'lastName'  => $location['last-name'],
			'address'   => $location['address1'] . $location['address2'] ? ', ' . $location['address2'] : '',
			'city'      => $location['city'],
			'state'     => $location['state'],
			'zip'       => preg_replace( '/[^A-Za-z0-9\-]/', '', $location['zip'] ),
			'country'   => $country,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_js() {

		if ( $this->gateway->is_sandbox_mode() ) {
			$public_key = esc_js( $this->gateway->settings()->get( 'sandbox-public-key' ) );
			$login_id   = esc_js( $this->gateway->settings()->get( 'authorizenet-sandbox-api-login-id' ) );
			$script     = esc_js( 'https://jstest.authorize.net/v1/Accept.js' );
		} else {
			$public_key = esc_js( $this->gateway->settings()->get( 'public-key' ) );
			$script     = esc_js( 'https://js.authorize.net/v1/Accept.js' );
			$login_id   = esc_js( $this->gateway->settings()->get( 'authorizenet-api-login-id' ) );
		}

		return <<<JS
		
		function( type, tokenize ) {
		
			var deferred = jQuery.Deferred();
			
			var fn = function() {
				
				var cardTransform = {
					number: 'cardNumber',
					cvc: 'cardCode',
					year: 'year',
					month: 'month',
				};
				
				var secureData = {}, authData = {}, cardData = {};
				
				authData.clientKey  = '$public_key';
				authData.apiLoginID = '$login_id';
				
				if ( tokenize.name ) {
					cardData.fullName = tokenize.name;
				}
			
				if ( type === 'card' ) {
					for ( var from in cardTransform ) {
						if ( ! cardTransform.hasOwnProperty( from ) ) {
							continue;
						}
						
						var to = cardTransform[ from ];
						
						if ( tokenize[from] ) {
							cardData[to] = tokenize[from];
						} else {
							deferred.reject( 'Missing property ' + from );
							
							return;
						}
					}
					
					if ( cardData.year > 2000 ) {
						cardData.year = cardData.year - 2000;
					}
					
					secureData.cardData = cardData;				
					secureData.authData = authData;
					
					Accept.dispatchData( secureData, function( response ) {
						if (response.messages.resultCode === 'Error') {
							var error = '';
							
					        for (var i = 0; i < response.messages.message.length; i++) {
					            error += response.messages.message[i].text + ' ';
					        }
					        
					        deferred.reject( error );
					    } else {
					        deferred.resolve( response.opaqueData.dataValue );
					    }
					} );
				} else {
					deferred.reject( 'Unknown token request type.' );
				}
			};
			
			if ( ! window.hasOwnProperty( 'Accept' ) ) {
				jQuery.ajax( {
					url: '$script',
					dataType: 'script',
					scriptCharset: "UTF-8",
  				} );
			} else {
				fn();
			}
			
			return deferred.promise();
		}
JS;
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === ITE_Gateway_Tokenize_Request::get_name(); }
}