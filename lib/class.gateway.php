<?php
/**
 * Gateway class.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * Class ITE_AuthorizeNet_Gateway
 */
class ITE_AuthorizeNet_Gateway extends ITE_Gateway {

	/** @var ITE_Gateway_Request_Handler[] */
	private $handlers = array();

	/** @var array */
	private $fields = array();

	/**
	 * ITE_AuthorizeNet_Gateway constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {

		$factory = new ITE_Gateway_Request_Factory();

		$this->handlers[] = new ITE_AuthorizeNet_Purchase_Request_Handler( $this, $factory );
		$this->handlers[] = new ITE_AuthorizeNet_Webhook_Handler( $this );
		$this->handlers[] = new ITE_AuthorizeNet_Refund_Request_Handler( $this );

		if ( class_exists( 'ITE_Daily_Price_Calculator' ) && class_exists( 'ITE_Update_Subscription_Payment_Method_Request' ) ) {
			$this->handlers[] = new ITE_AuthorizeNet_Update_Subscription_Payment_Method_Handler(
				$this, new ITE_Daily_Price_Calculator(), $factory
			);
		}

		if ( class_exists( 'ITE_Cancel_Subscription_Request' ) ) {
			$this->handlers[] = new ITE_AuthorizeNet_Cancel_Subscription_Request_Handler( $this );
		}

		if ( $this->settings()->has( 'cim' ) && $this->settings()->get( 'cim' ) ) {
			$this->handlers[] = new ITE_AuthorizeNet_Tokenize_Request_Handler( $this );

			if ( class_exists( 'ITE_Pause_Subscription_Request' ) ) {
				$this->handlers[] = new ITE_AuthorizeNet_Pause_Subscription_Handler();
			}


			if ( class_exists( 'ITE_Resume_Subscription_Request' ) ) {
				$this->handlers[] = new ITE_AuthorizeNet_Resume_Subscription_Handler();
			}
		}

		add_action(
			"it_exchange_validate_admin_form_settings_for_{$this->get_settings_name()}",
			array( $this, 'create_webhooks' ),
			10, 2
		);

		parent::__construct();
	}

	/**
	 * @inheritDoc
	 */
	public function get_name() { return __( 'Authorize.Net', 'LION' ); }

	/**
	 * @inheritDoc
	 */
	public function get_slug() { return 'authorizenet'; }

	/**
	 * @inheritDoc
	 */
	public function get_addon() { return it_exchange_get_addon( $this->get_slug() ); }

	/**
	 * @inheritDoc
	 */
	public function get_handlers() { return $this->handlers; }

	/**
	 * @inheritDoc
	 */
	public function is_sandbox_mode() { return $this->settings()->get( 'authorizenet-sandbox-mode' ); }

	/**
	 * @inheritDoc
	 */
	public function get_webhook_param() { return 'it_exchange_authorizenet'; }

	/**
	 * @inheritDoc
	 */
	public function get_webhook_options() {
		return array(
			'use_path' => true,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_ssl_mode() { return self::SSL_REQUIRED; }

	/**
	 * @inheritDoc
	 */
	public function is_currency_support_limited() { return true; }

	/**
	 * @inheritDoc
	 */
	public function get_supported_currencies() {
		return array( 'USD', 'CAD', 'GBP', 'EUR' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_wizard_settings() {
		$fields = array(
			'preamble',
			'step1',
			'authorizenet-api-login-id',
			'authorizenet-api-transaction-key',
			'authorizenet-api-md5-hash',
			'evosnap-international',
			'step2',
			'step3',
			'authorizenet-purchase-button-label'
		);

		$wizard = array();

		foreach ( $this->get_settings_fields() as $field ) {
			if ( in_array( $field['slug'], $fields ) ) {
				$wizard[] = $field;
			}
		}

		return $wizard;
	}

	/**
	 * @inheritDoc
	 */
	public function get_settings_fields() {

		if ( $this->fields ) {
			return $this->fields;
		}

		$fields = array(
			array(
				'slug' => 'preamble',
				'type' => 'html',
				'html' => '<p>' . __( 'To get Authorize.Net set up for use with Exchange, you\'ll need to add the following information from your Authorize.Net account.', 'LION' ) .
				          '<br><br>' . __( 'Video:', 'LION' ) . '<a href="http://ithemes.com/tutorials/setting-up-authorizenet-in-exchange/" target="_blank">' .
				          __( 'Setting Up Authorize.Net in Exchange', 'LION' ) . '</a></p><p>' .
				          __( 'Don\'t have an Authorize.Net account yet?', 'LION' ) . '<a href="http://authorize.net" target="_blank">' . __( 'Go set one up here', 'LION' ) .
				          '</a>.</p>'
			),
			array(
				'slug' => 'step1',
				'type' => 'html',
				'html' => '<h4>' . __( 'Step 1. Fill out your Authorize.Net API Credentials', 'LION' ) . '</h4>'
			),
			array(
				'slug'     => 'authorizenet-api-login-id',
				'type'     => 'text_box',
				'label'    => __( 'API Login ID', 'LION' ),
				'tooltip'  => __( 'Your API Login ID can be found under the Setting Menu on your Merchant Interface (in your Authorize.net account).', 'LION' ),
				'required' => true,
			),
			array(
				'slug'     => 'authorizenet-transaction-key',
				'type'     => 'text_box',
				'label'    => __( 'Transaction Key', 'LION' ),
				'tooltip'  => __( 'Your Transaction Key can be found under the Setting Menu on your Merchant Interface (in your Authorize.net account).', 'LION' ),
				'required' => true,
			),
			array(
				'slug'     => 'authorizenet-md5-hash',
				'type'     => 'text_box',
				'label'    => __( 'MD5 Hash Value', 'LION' ),
				'tooltip'  => __( 'The MD5 Hash Value should match the value you set in your Authorize.Net account at Account -> MD5-Hash.', 'LION' ),
				'desc'     => __( 'The hash can be up to 20 characters long, including upper- and lower-case letters, numbers, spaces, and punctuation. More complex values will be more secure.', 'LION' ),
				'required' => true,
			),
			array(
				'slug'     => 'signature',
				'type'     => 'text_box',
				'label'    => __( 'Signature Key', 'LION' ),
				'tooltip'  => __( 'Your signature key can be obtained in the Authorize.Net Merchant Interface, at Account > Settings > Security Settings > General Security Settings > API Credentials and Keys', 'LION' ),
				'required' => true,
			),
			array(
				'slug'    => 'evosnap-international',
				'type'    => 'check_box',
				'label'   => __( 'EVOSnap International Account', 'LION' ),
				'desc'    => __( "Mark yes if your Authorize.net payment processor is an EVOSnap International account. If you don't know what your payment processor is, contact Authorize.net.", 'LION' ),
				'default' => false,
			),
			array(
				'slug'    => 'cim',
				'type'    => 'check_box',
				'label'   => __( 'Enable Customer Information Manager (CIM)', 'LION' ),
				'desc'    => __( "Enable this option if your Authorize.net account supports CIM and you'd like to support payment tokens.", 'LION' ),
				'default' => false,
			),
			array(
				'slug'    => 'acceptjs',
				'type'    => 'check_box',
				'label'   => __( 'Enable Accept.js support.', 'LION' ),
				'desc'    => __( 'Accept.js helps minimize your PCI compliance because it sends payment data directly to Authorize.Net.', 'LION' ),
				'default' => false,
				'show_if' => array( 'field' => 'cim', 'value' => true, 'compare' => '=' ),
			),
			array(
				'slug'     => 'public-key',
				'type'     => 'text_box',
				'label'    => __( 'Public Client Key', 'LION' ),
				'tooltip'  => __( 'Your Public Client Key can be found under Account -> Settings -> Security Settings -> General Security Settings -> Manage Public Client Key', 'LION' ),
				'show_if'  => array(
					array( 'field' => 'cim', 'value' => true, 'compare' => '=' ),
					array( 'field' => 'acceptjs', 'value' => true, 'compare' => '=' ),
				),
				'required' => true,
			),
			array(
				'slug' => 'step2',
				'type' => 'html',
				'html' => '<h4>' . __( 'Step 2. Enable Transaction Details API', 'LION' ) . '</h4><p>' .
				          __( 'Enable the Transaction Details API under Account -> Settings -> Security Settings -> General Security Settings -> Transaction Details API.', 'LION' ) .
				          '</p><p>' .
				          __( 'This must be enabled for your sandbox account as well if in use.', 'LION' )
				          . '</p>'
			),
			array(
				'slug' => 'step3',
				'type' => 'html',
				'html' => '<h4>' . __( 'Step 3. Optional Configuration', 'LION' ) . '</h4>'
			),
			array(
				'slug'     => 'authorizenet-purchase-button-label',
				'type'     => 'text_box',
				'label'    => __( 'Purchase Button Label', 'LION' ),
				'default'  => __( 'Purchase', 'LION' ),
				'required' => true,
			),
			array(
				'slug'    => 'authorizenet-test-mode',
				'type'    => 'check_box',
				'label'   => __( 'Enable Test Mode', 'LION' ),
				'desc'    => __( 'Use this mode for testing your store with Live credentials. Recurring payments do not support test mode and will still charge the customer.', 'LION' ) .
				             ' ' . __( 'This mode needs to be disabled when the store is ready to process customer payments.', 'LION' ) .
				             ' ' . __( 'In the majority of cases, creating a Sandbox account is preferred to using Test Mode.', 'LION' ),
				'default' => false,
			),
			array(
				'slug'    => 'authorizenet-sandbox-mode',
				'type'    => 'check_box',
				'label'   => __( 'Enable Sandbox Mode', 'LION' ),
				'desc'    => __( 'Use this mode for testing your store with Sandbox credentials.', 'LION' ) .
				             ' ' . __( 'This mode needs to be disabled when the store is ready to process customer payments.', 'LION' ),
				'default' => false,
			),
			array(
				'slug'     => 'authorizenet-sandbox-api-login-id',
				'type'     => 'text_box',
				'label'    => __( 'Sandbox API Login ID', 'LION' ),
				'tooltip'  => __( 'Your Sandbox API Login ID can be found under the Setting Menu on your Merchant Interface (in your Sandbox Authorize.net account).', 'LION' ),
				'required' => true,
				'show_if'  => array( 'field' => 'authorizenet-sandbox-mode', 'value' => true, 'compare' => '=' ),
			),
			array(
				'slug'     => 'authorizenet-sandbox-transaction-key',
				'type'     => 'text_box',
				'label'    => __( 'Sandbox Transaction Key', 'LION' ),
				'tooltip'  => __( 'Your Sandbox Transaction Key can be found under the Setting Menu on your Merchant Interface (in your Sandbox Authorize.net account).', 'LION' ),
				'required' => true,
				'show_if'  => array( 'field' => 'authorizenet-sandbox-mode', 'value' => true, 'compare' => '=' ),
			),
			array(
				'slug'     => 'authorizenet-sandbox-md5-hash',
				'type'     => 'text_box',
				'label'    => __( 'Sandbox MD5 Hash Value', 'LION' ),
				'tooltip'  => __( 'The MD5 Hash Value should match the value you set in your Sandbox Authorize.Net account at Account -> MD5-Hash.', 'LION' ),
				'desc'     => __( 'The hash can be up to 20 characters long, including upper- and lower-case letters, numbers, spaces, and punctuation. More complex values will be more secure.', 'LION' ),
				'required' => true,
				'show_if'  => array( 'field' => 'authorizenet-sandbox-mode', 'value' => true, 'compare' => '=' ),
			),
			array(
				'slug'     => 'sandbox-signature',
				'type'     => 'text_box',
				'label'    => __( 'Sandbox Signature Key', 'LION' ),
				'tooltip'  => __( 'Your sandbox signature key can be obtained in the Authorize.Net Merchant Interface, at Account > Settings > Security Settings > General Security Settings > API Credentials and Keys', 'LION' ),
				'required' => true,
			),
			array(
				'slug'     => 'sandbox-public-key',
				'type'     => 'text_box',
				'label'    => __( 'Sandbox Public Client Key', 'LION' ),
				'tooltip'  => __( 'Your Sandbox Public Client Key can be found under Account -> Settings -> Security Settings -> General Security Settings -> Manage Public Client Key', 'LION' ),
				'show_if'  => array(
					array( 'field' => 'cim', 'value' => true, 'compare' => '=' ),
					array( 'field' => 'acceptjs', 'value' => true, 'compare' => '=' ),
					array( 'field' => 'authorizenet-sandbox-mode', 'value' => true, 'compare' => '=' ),
				),
				'required' => true,
			),
		);

		$this->fields = $fields;

		return $fields;
	}

	/**
	 * @inheritDoc
	 */
	public function get_settings_name() { return 'addon_authorizenet'; }

	/**
	 * Create webhooks if necessary.
	 *
	 * @since 2.0.0
	 *
	 * @param null|WP_Error $errors
	 * @param array         $settings
	 *
	 * @return array|WP_Error
	 */
	public function create_webhooks( $errors, $settings ) {

		if ( is_wp_error( $errors ) ) {
			return $errors;
		}

		/** @var ITE_AuthorizeNet_Webhook_Handler $webhook */
		$webhook = $this->get_handler_by_request_name( 'webhook' );

		if ( $webhook->get_webhook_id() ) {
			return $settings;
		}

		try {
			$webhook->subscribe( array(
				'settings' => $settings,
			) );
		} catch ( Exception $e ) {
			return new WP_Error( $e->getMessage() );
		}

		return $errors;
	}

	/**
	 * @inheritDoc
	 */
	public function supports_feature( ITE_Optionally_Supported_Feature $feature ) {

		switch ( $feature->get_feature_slug() ) {
			case 'recurring-payments':
				return true;
		}

		return parent::supports_feature( $feature );
	}

	/**
	 * @inheritDoc
	 */
	public function supports_feature_and_detail( ITE_Optionally_Supported_Feature $feature, $slug, $detail ) {

		switch ( $feature->get_feature_slug() ) {
			case 'recurring-payments':
				switch ( $slug ) {
					case 'profile':

						/** @var $detail IT_Exchange_Recurring_Profile */
						switch ( $detail->get_interval_type() ) {
							case IT_Exchange_Recurring_Profile::TYPE_DAY:
								return $detail->get_interval_count() >= 7 && $detail->get_interval_count() <= 365;
							case IT_Exchange_Recurring_Profile::TYPE_WEEK:
								return $detail->get_interval_count() <= 52;
							case IT_Exchange_Recurring_Profile::TYPE_MONTH:
								return $detail->get_interval_count() <= 12;
							case IT_Exchange_Recurring_Profile::TYPE_YEAR:
								return $detail->get_interval_count() <= 1;
							default:
								return false;
						}

					case 'auto-renew':
					case 'trial':
					case 'trial-profile':
					case 'max-occurrences':
						return true;
					default:
						return false;
				}
		}

		return parent::supports_feature( $feature );
	}
}
