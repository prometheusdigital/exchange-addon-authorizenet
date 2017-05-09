<?php
/**
 * Authorize.Net Tokenize Handler.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class ITE_AuthorizeNet_Tokenize_Request_Handler
 */
class ITE_AuthorizeNet_Tokenize_Request_Handler implements ITE_Gateway_Request_Handler, ITE_Gateway_JS_Tokenize_Handler {

	/** @var ITE_Gateway */
	private $gateway;

	/** @var ITE_AuthorizeNet_Request_Helper */
	private $helper;

	/**
	 * ITE_AuthorizeNet_Tokenize_Request_Handler constructor.
	 *
	 * @param ITE_Gateway                     $gateway
	 * @param ITE_AuthorizeNet_Request_Helper $helper
	 */
	public function __construct( ITE_Gateway $gateway, ITE_AuthorizeNet_Request_Helper $helper ) {
		$this->gateway = $gateway;
		$this->helper  = $helper;
	}

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Gateway_Tokenize_Request $request
	 *
	 * @throws \InvalidArgumentException
	 * @throws \UnexpectedValueException
	 */
	public function handle( $request ) {

		$customer_profile_id = it_exchange_authorizenet_get_customer_profile_id( $request->get_customer()->get_ID() );

		try {
			if ( ! $customer_profile_id ) {
				$customer_profile_id = $this->create_customer_profile( $request );
			}

			return $this->create_payment_profile( $customer_profile_id, $request );
		} catch ( UnexpectedValueException $e ) {

			it_exchange_log( 'Authorize.Net returned unexpected response while creating token for {customer}: {exception}.', array(
				'response' => $e,
				'customer' => $request->get_customer()->get_ID(),
				'_group'   => 'token',
			) );

			throw $e;
		}
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
	 * @throws \UnexpectedValueException
	 * @throws \InvalidArgumentException
	 */
	protected function create_payment_profile( $customer_profile_id, ITE_Gateway_Tokenize_Request $request ) {

		$payment = $this->generate_payment_profile( $request->get_source_to_tokenize() );

		if ( ! $payment ) {
			it_exchange_log( 'Not enough information provided to create Authorize.Net token.', array(
				'_group' => 'token'
			) );
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

		$billing = $request->get_address();
		$billing = $billing ?: $request->get_customer()->get_billing_address();
		$billing = $billing ?: $request->get_customer()->get_shipping_address();

		if ( $billing && $bill_to = $this->generate_address( $billing ) ) {
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
			it_exchange_log( 'Network error while creating Authorize.Net payment profile for {profile_id}: {error}', ITE_Log_Levels::WARNING, array(
				'_group'     => 'refund',
				'error'      => $response->get_error_message(),
				'profile_id' => $customer_profile_id,
			) );
			throw new UnexpectedValueException( $response->get_error_message() );
		}

		$body     = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
		$response = json_decode( $body, true );

		$this->helper->check_for_errors( $response );

		return $this->create_token( $response['customerPaymentProfileId'], $request );
	}

	/**
	 * Create a customer profile in Auth.net
	 *
	 * @since 2.0.0
	 *
	 * @param ITE_Gateway_Tokenize_Request $request
	 *
	 * @return ITE_Payment_Token
	 * @throws \UnexpectedValueException
	 * @throws \InvalidArgumentException
	 */
	protected function create_customer_profile( ITE_Gateway_Tokenize_Request $request ) {

		$payment = $this->generate_payment_profile( $request->get_source_to_tokenize() );

		if ( ! $payment ) {
			it_exchange_log( 'Not enough information provided to create Authorize.Net token.', array(
				'_group' => 'token'
			) );
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
			it_exchange_log( 'Network error while creating Authorize.Net customer profile for #{customer}: {error}', ITE_Log_Levels::WARNING, array(
				'_group'     => 'refund',
				'error'      => $response->get_error_message(),
				'profile_id' => $request->get_customer()->get_ID(),
			) );
			throw new UnexpectedValueException( $response->get_error_message() );
		}

		$body     = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
		$response = json_decode( $body, true );

		$this->helper->check_for_errors( $response );

		if ( empty( $response['customerProfileId'] ) ) {
			it_exchange_log( 'Authorize.Net returned unexpected response while creating customer profile for #{customer}: {response}.', array(
				'response' => wp_json_encode( $response ),
				'customer' => $request->get_customer()->get_ID(),
				'_group'   => 'token',
			) );
			throw new UnexpectedValueException( 'Unknown error.' );
		}

		it_exchange_log( 'Authorize.Net customer profile #{profile_id} created for #{customer}.', ITE_Log_Levels::DEBUG, array(
			'profile_id' => $response['customerProfileId'],
			'customer'   => $request->get_customer()->get_ID(),
			'_group'     => 'token',
		) );

		it_exchange_authorizenet_set_customer_profile_id( $request->get_customer()->get_ID(), $response['customerProfileId'] );

		return $response['customerProfileId'];
	}

	/**
	 * Get details about a payment profile.
	 *
	 * @since 2.0.0
	 *
	 * @param int $customer_profile_id
	 * @param int $payment_profile_id
	 *
	 * @return array
	 * @throws \UnexpectedValueException
	 */
	protected function get_payment_profile_details( $customer_profile_id, $payment_profile_id ) {

		$body = array(
			'getCustomerPaymentProfileRequest' => array(
				'merchantAuthentication'   => $this->get_merchant_auth(),
				'customerProfileId'        => $customer_profile_id,
				'customerPaymentProfileId' => $payment_profile_id,
				'unmaskExpirationDate'     => true,
			),
		);

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

		$this->helper->check_for_errors( $response );

		return $response['paymentProfile'];
	}

	/**
	 * Create the token in Exchange.
	 *
	 * @since 2.0.0
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
			'mode'     => $this->get_gateway()->is_sandbox_mode() ? 'sandbox' : 'live',
		);

		$for = 'unknown';

		if ( is_string( $source ) ) {

			$details = $this->get_payment_profile_details(
				it_exchange_authorizenet_get_customer_profile_id( $request->get_customer()->get_ID() ),
				$profile_id
			);

			$attr['redacted'] = substr( $details['payment']['creditCard']['cardNumber'], - 4 );

			$token = ITE_Payment_Token_Card::create( $attr );

			$expires = $details['payment']['creditCard']['expirationDate'];

			list( $year, $month ) = explode( '-', $expires );

			if ( $month && $year ) {
				$token->set_expiration( $month, $year );
			}

			$for = 'Accept.JS';

		} elseif ( $source instanceof ITE_Gateway_Card ) {
			$attr['redacted'] = $source->get_redacted_number();

			$token = ITE_Payment_Token_Card::create( $attr );

			if ( $token ) {
				$token->set_expiration( $source->get_expiration_month(), $source->get_expiration_year() );
			}

			$for = 'card';

		} elseif ( $source instanceof ITE_Gateway_Bank_Account ) {
			$attr['redacted'] = $source->get_redacted_account_number();

			$token = ITE_Payment_Token_Bank_Account::create( $attr );

			if ( $token ) {
				$token->set_account_type( $source->get_type() );
			}

			$for = 'bank account';
		}

		if ( $token && $request->should_set_as_primary() ) {
			$token->make_primary();
		}

		it_exchange_log( 'Authorize.Net tokenize request for {for} resulted in token #{token}', ITE_Log_Levels::INFO, array(
			'for'    => $for,
			'token'  => $token->get_ID(),
			'_group' => 'token',
		) );

		return $token;
	}

	/**
	 * Generate a payment profile for a tokenization source.
	 *
	 * @since 2.0.0
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
	 * @since 2.0.0
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
	 * @since 2.0.0
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
	 * @since 2.0.0
	 *
	 * @return ITE_Gateway
	 */
	public function get_gateway() {
		return $this->gateway;
	}

	/**
	 * Generate an address formatted for Auth.net from an ITE_Location.
	 *
	 * @since 2.0.0
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
	public function get_tokenize_js_function() { return $this->helper->get_tokenize_js_function(); }

	/**
	 * @inheritDoc
	 */
	public function is_js_tokenizer_configured() { return $this->helper->is_js_tokenizer_configured(); }

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === ITE_Gateway_Tokenize_Request::get_name(); }
}