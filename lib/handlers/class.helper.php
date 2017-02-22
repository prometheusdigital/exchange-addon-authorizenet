<?php
/**
 * Authorize.Net JS tokenizer.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class ITE_AuthorizeNet_Request_Helper
 */
class ITE_AuthorizeNet_Request_Helper {

	/** @var ITE_Gateway */
	private $gateway;

	/**
	 * ITE_AuthorizeNet_JS_Tokenizer constructor.
	 *
	 * @param ITE_Gateway $gateway
	 */
	public function __construct( ITE_Gateway $gateway ) { $this->gateway = $gateway; }

	/**
	 * Get details about a transaction in Auth.net
	 *
	 * @since 2.0.0
	 *
	 * @param int  $method_id
	 * @param bool $is_sandbox
	 *
	 * @return array
	 */
	public function get_transaction_details( $method_id, $is_sandbox ) {

		$settings = $this->gateway->settings()->all();

		$api_url      = $is_sandbox ? AUTHORIZE_NET_AIM_API_SANDBOX_URL : AUTHORIZE_NET_AIM_API_LIVE_URL;
		$api_username = $is_sandbox ? $settings['authorizenet-sandbox-api-login-id'] : $settings['authorizenet-api-login-id'];
		$api_password = $is_sandbox ? $settings['authorizenet-sandbox-transaction-key'] : $settings['authorizenet-transaction-key'];

		$body = array(
			'getTransactionDetailsRequest' => array(
				'merchantAuthentication' => array(
					'name'           => $api_username,
					'transactionKey' => $api_password,
				),
				'transId'                => $method_id,
			)
		);

		$query = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode( $body ),
			'timeout' => 30
		);

		$response = wp_remote_post( $api_url, $query );

		if ( is_wp_error( $response ) ) {
			throw new UnexpectedValueException( $response->get_error_message() );
		}

		$body     = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
		$response = json_decode( $body, true );

		$this->check_for_errors( $response );

		return $response['transaction'];
	}

	/**
	 * Get details about a subscription.
	 *
	 * @since 2.0.0
	 *
	 * @param int  $subscriber_id
	 * @param bool $is_sandbox
	 *
	 * @return array
	 * @throws \UnexpectedValueException
	 */
	public function get_subscription_details( $subscriber_id, $is_sandbox ) {

		$settings = $this->gateway->settings()->all();

		$api_url      = $is_sandbox ? AUTHORIZE_NET_AIM_API_SANDBOX_URL : AUTHORIZE_NET_AIM_API_LIVE_URL;
		$api_username = $is_sandbox ? $settings['authorizenet-sandbox-api-login-id'] : $settings['authorizenet-api-login-id'];
		$api_password = $is_sandbox ? $settings['authorizenet-sandbox-transaction-key'] : $settings['authorizenet-transaction-key'];

		$body = array(
			'ARBGetSubscriptionRequest' => array(
				'merchantAuthentication' => array(
					'name'           => $api_username,
					'transactionKey' => $api_password,
				),
				'subscriptionId'         => $subscriber_id,
			)
		);

		$query = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => json_encode( $body ),
			'timeout' => 30
		);

		$response = wp_remote_post( $api_url, $query );

		if ( is_wp_error( $response ) ) {
			throw new UnexpectedValueException( $response->get_error_message() );
		}

		$body     = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
		$response = json_decode( $body, true );

		$this->check_for_errors( $response );

		return $response['ARBGetSubscriptionResponse']['subscription'];
	}

	/**
	 * Check for errors in the Auth.Net Response.
	 *
	 * @since 2.0.0
	 *
	 * @param array $response
	 *
	 * @throws UnexpectedValueException
	 */
	public function check_for_errors( $response ) {

		if ( ! isset( $response['messages'] ) ) {
			return;
		}

		if ( isset( $response['messages']['resultCode'] ) && $response['messages']['resultCode'] === 'Error' ) {
			if ( ! empty( $response['messages']['message'] ) ) {
				$error = reset( $response['messages']['message'] );

				if ( $error && is_string( $error ) ) {
					throw new UnexpectedValueException( $error );
				} elseif ( is_array( $error ) && isset( $error['text'] ) ) {
					throw new UnexpectedValueException( $error['text'] );
				}
			}

			throw new UnexpectedValueException( 'Unknown error.' );
		} elseif ( is_array( $response['messages'] ) ) {
			$error = reset( $response['messages'] );

			if ( isset( $error['description'] ) && is_string( $error['description'] ) ) {
				throw new UnexpectedValueException( $error['description'] );
			} elseif ( isset( $error['code'] ) ) {
				throw new UnexpectedValueException( "Authorize.Net Error Code {$error['code']}" );
			}
		}
	}

	/**
	 * Get tokenize JS function.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_tokenize_js_function() {

		if ( $this->gateway->is_sandbox_mode() ) {
			$public_key = esc_js( $this->gateway->settings()->get( 'sandbox-public-key' ) );
			$login_id   = esc_js( $this->gateway->settings()->get( 'authorizenet-sandbox-api-login-id' ) );
		} else {
			$public_key = esc_js( $this->gateway->settings()->get( 'public-key' ) );
			$login_id   = esc_js( $this->gateway->settings()->get( 'authorizenet-api-login-id' ) );
		}

		return <<<JS
		
		function( type, tokenize ) {
		
			var deferred = jQuery.Deferred();
			
			window.itExchangeAcceptJsTokenize = function() {
				
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
					
					cardData.year = Number.parseInt( cardData.year );
					
					if ( cardData.year > 2000 ) {
						cardData.year = cardData.year - 2000;
					}
					
					cardData.year += '';
					
					secureData.cardData = cardData;				
					secureData.authData = authData;
					
					window.itExchangeAcceptJSCallback = function( response ) {
						if (response.messages.resultCode === 'Error') {
							var error = '';
							
					        for (var i = 0; i < response.messages.message.length; i++) {
					            error += response.messages.message[i].code + ':' + response.messages.message[i].text + ' ';
					        }
					        
					        deferred.reject( error );
					    } else {
					        deferred.resolve( response.opaqueData.dataValue );
					    }
					}
					
					Accept.dispatchData( secureData, 'itExchangeAcceptJSCallback' );
				} else {
					deferred.reject( 'Unknown token request type.' );
				}
			};
			
			window.itExchangeAcceptJsTokenize();
			
			return deferred.promise();
		}
JS;
	}

	/**
	 * Is the JS tokenizer configured.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_js_tokenizer_configured() {

		if ( ! $this->gateway->settings()->has( 'acceptjs' ) || ! $this->gateway->settings()->get( 'acceptjs' ) ) {
			return false;
		}

		if ( $this->gateway->is_sandbox_mode() ) {
			$public_key = $this->gateway->settings()->get( 'sandbox-public-key' );
			$login_id   = $this->gateway->settings()->get( 'authorizenet-sandbox-api-login-id' );
		} else {
			$public_key = $this->gateway->settings()->get( 'public-key' );
			$login_id   = $this->gateway->settings()->get( 'authorizenet-api-login-id' );
		}

		return $public_key && $login_id;
	}
}