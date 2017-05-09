<?php
/**
 * Authorize.Net webhook handler.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class ITE_AuthorizeNet_Webhook_Handler
 */
class ITE_AuthorizeNet_Webhook_Handler implements ITE_Gateway_Request_Handler {

	const LIVE = 'https://api.authorize.net/rest/v1/webhooks';
	const TEST = 'https://apitest.authorize.net/rest/v1/webhooks';

	/** @var ITE_Gateway */
	private $gateway;

	/**
	 * @var ITE_AuthorizeNet_Request_Helper
	 */
	private $helper;

	/**
	 * ITE_AuthorizeNet_Webhook_Handler constructor.
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
	 * @param ITE_Webhook_Gateway_Request $request
	 */
	public function handle( $request ) {

		$body = $request->get_raw_post_data();
		$hash = $request->get_header( 'X-ANET-Signature' );

		if ( ! $hash ) {
			it_exchange_log( 'Authorize.Net webhook signature missing: {webhook}', array(
				'webhook' => $body,
				'_group'  => 'webhook',
			) );

			return new WP_HTTP_Response( null, 400 );
		}

		$setting = $this->gateway->is_sandbox_mode() ? 'sandbox-signature' : 'signature';

		if ( ! $this->gateway->settings()->has( $setting ) || ! ( $signature = $this->gateway->settings()->get( $setting ) ) ) {
			it_exchange_log( 'No Authorize.Net credentials provided.', ITE_Log_Levels::ALERT, array(
				'_group' => 'webhook',
			) );

			return new WP_HTTP_Response( null, 500 );
		}

		// The has is of format sha512=HASHHERE
		list( , $hash ) = explode( '=', $hash );
		$computed_hash = hash_hmac( 'sha512', $body, $signature );

		if ( ! hash_equals( strtolower( $hash ), strtolower( $computed_hash ) ) ) {
			it_exchange_log( 'Authorize.Net webhook signature mismatch. Given: {given} Expected: {expected} - {webhook}', array(
				'given'    => $hash,
				'expected' => $computed_hash,
				'webhook'  => $body,
				'_group'   => 'webhook',
			) );

			return new WP_HTTP_Response( null, 400 );
		}

		$webhook = json_decode( $body, true );

		if ( ! $webhook ) {
			it_exchange_log( 'Authorize.Net webhook invalid payload.', array(
				'_group' => 'webhook',
			) );

			return new WP_HTTP_Response( null, 400 );
		}

		$webhook_id = $webhook['webhookId'];

		if ( $webhook_id === $this->get_webhook_id( 'live' ) ) {
			$is_sandbox = false;
		} elseif ( $webhook_id === $this->get_webhook_id( 'sandbox' ) ) {
			$is_sandbox = true;
		} else {
			it_exchange_log( 'Authorize.Net webhook invalid webhook id {webhook_id}.', ITE_Log_Levels::DEBUG, array(
				'_group'     => 'webhook',
				'webhook_id' => $webhook_id,
			) );

			return new WP_HTTP_Response( null, 400 );
		}

		it_exchange_log( 'Authorize.Net processing {type} webhook {webhook}', ITE_Log_Levels::DEBUG, array(
			'type'    => $webhook['eventType'],
			'webhook' => wp_json_encode( $webhook ),
			'_group'  => 'webhook'
		) );

		switch ( $webhook['eventType'] ) {
			case 'net.authorize.customer.subscription.suspended':

				$s = it_exchange_get_subscription_by_subscriber_id( 'authorizenet', $webhook['payload']['id'] );
				$s->set_status_from_gateway_update( $s::STATUS_PAYMENT_FAILED );

				break;
			case 'net.authorize.customer.subscription.terminated':
			case 'net.authorize.customer.subscription.cancelled':

				$s = it_exchange_get_subscription_by_subscriber_id( 'authorizenet', $webhook['payload']['id'] );

				if ( ! $s ) {
					break;
				}

				$status = $webhook['payload']['status'] === 'expired' ? $s::STATUS_DEACTIVATED : $s::STATUS_CANCELLED;
				$s->set_status_from_gateway_update( $status );

				break;
			case 'net.authorize.payment.authcapture.created':

				$method_id = $webhook['payload']['id'];
				$details   = $this->helper->get_transaction_details( $method_id, $is_sandbox );

				if ( ! isset( $details['subscription'] ) ) {
					break;
				}

				$subscriber_id = $details['subscription']['id'];
				$subscription  = it_exchange_get_subscription_by_subscriber_id( 'authorizenet', (string) $subscriber_id );

				if ( ! $subscription ) {
					break;
				}

				// recurringBilling determines if this is the first transaction for a subscription or not.
				if ( empty( $details['recurringBilling'] ) ) {
					$subscription->get_transaction()->update_method_id( $method_id );
				} else {

					$args = array(
						'date' => new DateTime( $details['submitTimeUTC'], new DateTimeZone( 'UTC' ) ),
					);

					if ( $token = $subscription->get_payment_token() ) {
						$args['payment_token'] = $token;
					} elseif ( $card = $subscription->get_card() ) {
						$args['card'] = $card;
					}

					it_exchange_add_subscription_renewal_payment(
						$subscription->get_transaction(),
						$method_id,
						$details['responseCode'],
						$details['authAmount'],
						$args
					);
				}

				break;

			case 'net.authorize.payment.void.created':

				$void_method_id = $webhook['payload']['id'];
				$details        = $this->helper->get_transaction_details( $void_method_id, $is_sandbox );
				$method_id      = $details['reftransId'];

				$transaction = it_exchange_get_transaction_by_method_id( 'authorizenet', $method_id );

				if ( ! $transaction ) {
					break;
				}

				$transaction->update_status( '2' );
				break;
		}

		return new WP_HTTP_Response( null, 200 );
	}

	/**
	 * Subscribe to a list of events in Auth.net.
	 *
	 * @since 2.0.0
	 *
	 * @param array $options
	 *
	 * @return string The webhook ID in Auth.net.
	 *
	 * @throws Exception
	 */
	public function subscribe( array $options = array() ) {

		$request = array(
			'url'        => it_exchange_get_webhook_url( $this->gateway->get_slug() ),
			'status'     => 'active',
			'eventTypes' => array(
				'net.authorize.customer.subscription.suspended',
				'net.authorize.customer.subscription.terminated',
				'net.authorize.customer.subscription.cancelled',
				'net.authorize.customer.subscription.expiring',
				'net.authorize.payment.authcapture.created',
				'net.authorize.payment.authorization.created',
				'net.authorize.payment.capture.created',
				'net.authorize.payment.void.created',
				'net.authorize.customer.subscription.updated',
			),
		);

		/**
		 * Filter the request used to signup for webhooks.
		 *
		 * @since 2.0.0
		 *
		 * @param array                            $request
		 * @param ITE_AuthorizeNet_Webhook_Handler $this
		 */
		$request = apply_filters( 'it_exchange_authorizenet_webhook_subscribe_request', $request, $this );

		$settings   = isset( $options['settings'] ) ? $options['settings'] : $this->gateway->settings()->all();
		$is_sandbox = ! empty( $settings['authorizenet-sandbox-mode'] );

		$login_id        = $is_sandbox ? $settings['authorizenet-sandbox-api-login-id'] : $settings['authorizenet-api-login-id'];
		$transaction_key = $is_sandbox ? $settings['authorizenet-sandbox-transaction-key'] : $settings['authorizenet-transaction-key'];

		$authorization = base64_encode( "{$login_id}:{$transaction_key}" );

		$response = wp_safe_remote_post( $is_sandbox ? self::TEST : self::LIVE, array(
			'body'    => wp_json_encode( $request ),
			'headers' => array(
				'Authorization' => "Basic {$authorization}",
				'Content-Type'  => 'application/json',
				'Cache-Control' => 'no-cache',
			),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			throw new UnexpectedValueException( $response->get_error_message() );
		}

		$response_body = wp_remote_retrieve_body( $response );

		if ( ! $response_body ) {
			throw new UnexpectedValueException( 'Invalid response from Authorize.Net' );
		}

		$response_body = json_decode( $response_body, true );

		if ( ! $response_body ) {
			throw new UnexpectedValueException( 'Failed to parse json from Authorize.Net' );
		}

		if ( empty( $response_body['status'] ) || $response_body['status'] !== 'active' ) {
			throw new UnexpectedValueException( 'Failed to create active webhook.' );
		}

		$id = $response_body['webhookId'];

		update_option( 'it_exchange_authnet_webhook_id_' . ( $is_sandbox ? 'sandbox' : 'live' ), $id );

		return $id;
	}

	/**
	 * Get the webhook ID.
	 *
	 * @since 2.0.0
	 *
	 * @param string $mode Either 'sandbox', 'live', or empty string to use current mode.
	 *
	 * @return string
	 */
	public function get_webhook_id( $mode = '' ) {
		$mode = $mode ?: ( $this->gateway->is_sandbox_mode() ? 'sandbox' : 'live' );

		return get_option( "it_exchange_authnet_webhook_id_{$mode}", '' );
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'webhook'; }
}