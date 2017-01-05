<?php
/**
 * Authorize.Net webhook handler.
 *
 * @since   1.5.0
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
	 * ITE_AuthorizeNet_Webhook_Handler constructor.
	 *
	 * @param ITE_Gateway $gateway
	 */
	public function __construct( ITE_Gateway $gateway ) { $this->gateway = $gateway; }

	/**
	 * @inheritDoc
	 *
	 * @param ITE_Webhook_Gateway_Request $request
	 */
	public function handle( $request ) {

		$body = $request->get_raw_post_data();
		$hash = $request->get_header( 'X-ANET-Signature' );

		if ( ! $hash ) {
			return new WP_HTTP_Response( null, 400 );
		}

		$setting = $this->gateway->is_sandbox_mode() ? 'sandbox-signature' : 'signature';

		if ( ! $this->gateway->settings()->has( $setting ) || ! ( $signature = $this->gateway->settings()->get( $setting ) ) ) {
			return new WP_HTTP_Response( null, 500 );
		}

		$computed_hash = hash_hmac( 'sha512', $body, $signature );

		if ( ! hash_equals( $hash, $computed_hash ) ) {
			return new WP_HTTP_Response( null, 400 );
		}

		$webhook = json_decode( $body, true );

		if ( ! $webhook ) {
			return new WP_HTTP_Response( null, 400 );
		}

		$webhook_id = $webhook['webhookId'];

		if ( $webhook_id === $this->get_webhook_id( 'live' ) ) {
			$is_sandbox = false;
		} elseif ( $webhook_id === $this->get_webhook_id( 'sandbox' ) ) {
			$is_sandbox = true;
		} else {
			return new WP_HTTP_Response( null, 400 );
		}

		switch ( $webhook['eventType'] ) {
			case 'net.authorize.customer.subscription.suspended':

				$s = it_exchange_get_subscription_by_subscriber_id( 'authorizenet', $webhook['payload']['id'] );
				$s->set_status( $s::STATUS_PAYMENT_FAILED );

				break;
			case 'net.authorize.customer.subscription.terminated':
			case 'net.authorize.customer.subscription.cancelled':

				$s      = it_exchange_get_subscription_by_subscriber_id( 'authorizenet', $webhook['payload']['id'] );
				$status = $webhook['payload']['status'] === 'expired' ? $s::STATUS_DEACTIVATED : $s::STATUS_CANCELLED;

				if ( ! $s->is_status( $status ) ) {
					$s->set_status( $status );
				}

				break;
		}

		return new WP_HTTP_Response( null, 200 );
	}

	/**
	 * Subscribe to a list of events in Auth.net.
	 *
	 * @since 1.5.0
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
			),
		);

		$settings   = isset( $options['settings'] ) ? $options['settings'] : $this->gateway->settings()->all();
		$is_sandbox = $this->gateway->is_sandbox_mode();

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
		$mode = $mode || ( $this->gateway->is_sandbox_mode() ? 'sandbox' : 'live' );

		return get_option( "it_exchange_authnet_webhook_id_{$mode}", '' );
	}

	/**
	 * @inheritDoc
	 */
	public static function can_handle( $request_name ) { return $request_name === 'webhook'; }
}