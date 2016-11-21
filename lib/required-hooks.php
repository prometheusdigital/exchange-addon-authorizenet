<?php
/**
 * Exchange Transaction Add-ons require several hooks in order to work properly.
 * Most of these hooks are called in api/transactions.php and are named dynamically
 * so that individual add-ons can target them. eg: it_exchange_refund_url_for_authorizenet
 * We've placed them all in one file to help add-on devs identify them more easily
*/

add_action( 'it_exchange_register_gateways', function( ITE_Gateways $gateways ) {

	require_once dirname( __FILE__ ) . '/class.gateway.php';
	require_once dirname( __FILE__ ) . '/handlers/class.purchase.php';
	require_once dirname( __FILE__ ) . '/handlers/class.webhook.php';
	require_once dirname( __FILE__ ) . '/handlers/class.refund.php';
	require_once dirname( __FILE__ ) . '/handlers/class.update-subscription-payment-method.php';
	require_once dirname( __FILE__ ) . '/handlers/class.cancel-subscription.php';

	$gateways::register( new ITE_AuthorizeNet_Gateway() );
} );

//For verifying CC... 
//incase a product doesn't have a shipping address and the shipping add-on is not enabled
add_filter( 'it_exchange_billing_address_purchase_requirement_enabled', '__return_true' );

/**
 * Can the authorize.net transaction be refunded.
 *
 * @since 1.5.0
 *
 * @param bool                    $eligible
 * @param IT_Exchange_Transaction $transaction
 *
 * @return bool
 */
function it_exchange_authorizenet_transaction_can_be_refunded( $eligible, IT_Exchange_Transaction $transaction ) {

	if ( ! $eligible ) {
		return $eligible;
	}

	if ( ! $transaction->get_card() || ! $transaction->get_card()->get_redacted_number() ) {
		return false;
	}

	$now    = new DateTime();
	$placed = $transaction->order_date;

	$diff = $placed->diff( $now );

	return $diff->days < 179;
}

add_filter( 'it_exchange_authorizenet_transaction_can_be_refunded', 'it_exchange_authorizenet_transaction_can_be_refunded', 10, 2 );

/**
 * Enqueues admin scripts on Settings page
 *
 * @since 1.1.24
 *
 * @return void
*/
function it_exchange_authorizenet_addon_admin_enqueue_script( $hook ) {
	if ( 'exchange_page_it-exchange-addons' === $hook
		&& !empty( $_REQUEST['add-on-settings'] ) && 'authorizenet' === $_REQUEST['add-on-settings'] ) {
	    wp_enqueue_script( 'authorizenet-addon-settings-js', ITUtility::get_url_from_file( dirname( __FILE__ ) ) . '/js/settings.js' );
	    wp_enqueue_style( 'authorizenet-addon-settings-css', ITUtility::get_url_from_file( dirname( __FILE__ ) ) . '/css/settings.css' );
	}
}
add_action( 'admin_enqueue_scripts', 'it_exchange_authorizenet_addon_admin_enqueue_script' );

/**
 * Loads minimal front-end styling
 *
 * @uses wp_enqueue_style()
 * @since 1.0.0
 * @return void
*/
function it_exchange_authorizenet_addon_wp_enqueue_script() {
	if ( it_exchange_is_page( 'product' ) || it_exchange_is_page( 'cart' ) || it_exchange_is_page( 'checkout' )
	     || ( class_exists( 'IT_Exchange_SW_Shortcode' ) && IT_Exchange_SW_Shortcode::has_shortcode() )
	) {
		wp_enqueue_style( 'it_exchange_authorize', plugins_url( 'css/authorize.css', __FILE__ ) );
	}
}
add_action( 'wp_enqueue_scripts', 'it_exchange_authorizenet_addon_wp_enqueue_script' );

/**
 * Adds actions to the plugins page for the iThemes Exchange Authorize.Net plugin
 *
 * @since 1.0.0
 *
 * @param array $meta Existing meta
 * @param string $plugin_file the wp plugin slug (path)
 * @param array $plugin_data the data WP harvested from the plugin header
 * @param string $context
 * @return array
*/
function it_exchange_authorizenet_plugin_row_actions( $actions, $plugin_file, $plugin_data, $context ) {

	$actions['setup_addon'] = '<a href="' . esc_url( admin_url( 'admin.php?page=it-exchange-addons&add-on-settings=authorizenet' ) ) . '">' . __( 'Setup Add-on', 'LION' ) . '</a>';

	return $actions;

}
add_filter( 'plugin_action_links_exchange-addon-authorizenet/exchange-addon-authorizenet.php', 'it_exchange_authorizenet_plugin_row_actions', 10, 4 );

/**
 * This processes an Authorize.net transaction.
 *
 * We rely less on the customer ID here than Stripe does because the APIs approach customers with pretty significant distinction
 * Once we're ready to integrate CIM, it's probably worth changing this up a bit.
 *
 * The it_exchange_do_transaction_[addon-slug] action is called when
 * the site visitor clicks a specific add-ons 'purchase' button. It is
 * passed the default status of false along with the transaction object
 * The transaction object is a package of data describing what was in the user's cart
 *
 * Exchange expects your add-on to either return false if the transaction failed or to
 * call it_exchange_add_transaction() and return the transaction ID
 *
 * @since 1.0.0
 *
 * @param string $status passed by WP filter.
 * @param object $transaction_object The transaction object
*/
function it_exchange_authorizenet_addon_process_transaction( $status, $transaction_object ) {
	// If this has been modified as true already, return.
	if ( $status )
		return $status;

	// Do we have valid CC fields?
	if ( ! it_exchange_submitted_purchase_dialog_values_are_valid( 'authorizenet' ) )
		return false;

	// Grab CC data
	$cc_data = it_exchange_get_purchase_dialog_submitted_values( 'authorizenet' );

	// Make sure we have the correct $_POST argument
	if ( ! empty( $_POST[it_exchange_get_field_name('transaction_method')] ) && 'authorizenet' == $_POST[it_exchange_get_field_name('transaction_method')] ) {
		try {
			$settings         = it_exchange_get_option( 'addon_authorizenet' );

			$api_url       = !empty( $settings['authorizenet-sandbox-mode'] ) ? AUTHORIZE_NET_AIM_API_SANDBOX_URL : AUTHORIZE_NET_AIM_API_LIVE_URL;
			$api_username  = !empty( $settings['authorizenet-sandbox-mode'] ) ? $settings['authorizenet-sandbox-api-login-id'] : $settings['authorizenet-api-login-id'];
			$api_password  = !empty( $settings['authorizenet-sandbox-mode'] ) ? $settings['authorizenet-sandbox-transaction-key'] : $settings['authorizenet-transaction-key'];
			$it_exchange_customer = it_exchange_get_current_customer();

			$subscription = false;
			$it_exchange_customer = it_exchange_get_current_customer();
			$reference_id = substr( it_exchange_create_unique_hash(), 20 );

			remove_filter( 'the_title', 'wptexturize' ); // remove this because it screws up the product titles in PayPal
			$cart = it_exchange_get_cart_products();
			if ( 1 === absint( count( $cart ) ) ) {
				foreach( $cart as $product ) {
					if ( it_exchange_product_supports_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'auto-renew' ) ) ) {
						if ( it_exchange_product_has_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'auto-renew' ) ) ) {
							$trial_enabled = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'trial-enabled' ) );
							$trial_interval = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'trial-interval' ) );
							$trial_interval_count = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'trial-interval-count' ) );
							$auto_renew = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'auto-renew' ) );
							$interval = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'interval' ) );
							$interval_count = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'interval-count' ) );

							switch( $interval ) {
								case 'year':
									$duration = 12; //The max you can do in Authorize.Net is 12 months (1 year)
									$unit = 'months'; //lame
									break;
								case 'week':
									$duration = $interval_count * 7;
									$unit = 'days'; //lame
									break;
								case 'day':
									$duration = $interval_count;
									$unit = 'days';
									break;
								case 'month':
								default:
									$duration = $interval_count;
									$unit = 'months';
									break;
							}
							$duration = apply_filters( 'it_exchange_authorizenet_addon_process_transaction_subscription_duration', $duration, $product );

							$trial_unit = NULL;
							$trial_duration = 0;
							if ( $trial_enabled ) {
								$allow_trial = true;
								//Should we all trials?
								if ( 'membership-product-type' === it_exchange_get_product_type( $product['product_id'] ) ) {
									if ( is_user_logged_in() ) {
										if ( function_exists( 'it_exchange_get_session_data' ) ) {
											$member_access = it_exchange_get_session_data( 'member_access' );
											$children = (array)it_exchange_membership_addon_get_all_the_children( $product['product_id'] );
											$parents = (array)it_exchange_membership_addon_get_all_the_parents( $product['product_id'] );
											foreach( $member_access as $prod_id => $txn_id ) {
												if ( $prod_id === $product['product_id'] || in_array( $prod_id, $children ) || in_array( $prod_id, $parents ) ) {
													$allow_trial = false;
													break;
												}
											}
										}
									}
								}

								$allow_trial = apply_filters( 'it_exchange_authorizenet_addon_process_transaction_allow_trial', $allow_trial, $product['product_id'] );
								if ( $allow_trial && 0 < $trial_interval_count ) {
									switch ( $trial_interval ) {
										case 'year':
											$trial_duration = 12; //The max you can do in Authorize.Net is 12 months (1 year)
											//$trial_unit = 'months'; //lame
											break;
										case 'week':
											$trial_duration = $interval_count * 7;
											//$trial_unit = 'days'; //lame
											break;
										case 'day':
										case 'month':
										default:
											$trial_duration = $interval_count;
											break;
									}
									$trial_duration = apply_filters( 'it_exchange_authorizenet_addon_process_transaction_subscription_trial_duration', $trial_duration, $product );
								}
							}

							$subscription = true;
							$product_id = $product['product_id'];
						}
					}
				}
			}

			if ( $settings['evosnap-international'] && function_exists( 'it_exchange_convert_country_code' ) ) {
				$country = it_exchange_convert_country_code( $transaction_object->billing_address['country'] );
			} else {
				$country = $transaction_object->billing_address['country'];
			}

			$billing_zip = preg_replace( '/[^A-Za-z0-9\-]/', '', $transaction_object->billing_address['zip'] );

			if ( ! empty( $transaction_object->shipping_address ) ) {
				$shipping_zip = preg_replace( '/[^A-Za-z0-9\-]/', '', $transaction_object->shipping_address['zip'] );
			} else {
				$shipping_zip = '';
			}

			if ( $subscription ) {
				$upgrade_downgrade = it_exchange_get_session_data( 'updowngrade_details' );
				if ( !empty( $upgrade_downgrade ) ) {
					foreach( $cart as $product ) {
						if ( !empty( $upgrade_downgrade[$product['product_id']] ) ) {
							$product_id = $product['product_id'];
							if (   !empty( $upgrade_downgrade[$product_id]['old_transaction_id'] )
								&& !empty( $upgrade_downgrade[$product_id]['old_transaction_method'] ) ) {
								$transaction_fields = array(
									'ARBUpdateSubscriptionRequest' => array(
										'merchantAuthentication' => array(
											'name'			     => $api_username,
											'transactionKey'     => $api_password,
										),
										'refId' => $reference_id,
										'subscriptionId' => $upgrade_downgrade[$product_id]['old_transaction_id'],
										'subscription' => array(
											'name' => it_exchange_get_cart_description(),
											'paymentSchedule' => array(
												'interval' => array(
													'length'   => $duration,
													'unit'     => $unit,
												),
												'startDate'        => date_i18n( 'Y-m-d' ),
												'totalOccurrences' => 9999, // To submit a subscription with no end date (an ongoing subscription), this field must be submitted with a value of “9999.”
												'trialOccurrences' => $trial_duration, //optional
											),
											'amount'      => $transaction_object->total,
											'trialAmount' => 0.00,
											'payment'        => array(
												'creditCard'     => array(
													'cardNumber'     => $cc_data['number'],
													'expirationDate' => $cc_data['expiration-month'] . $cc_data['expiration-year'],
													'cardCode'       => $cc_data['code'],
												),
											),
											'order'          => array(
												'description'    => it_exchange_get_cart_description(),
											),
											'customer'       => array(
												'id'               => $it_exchange_customer->ID,
												'email'            => $it_exchange_customer->data->user_email,
											),
											'billTo'         => array(
												'firstName'        => $cc_data['first-name'],
												'lastName'         => $cc_data['last-name'],
												'address'          => $transaction_object->billing_address['address1'] . ( !empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->billing_address['address2'] : '' ),
												'city'             => $transaction_object->billing_address['city'],
												'state'            => $transaction_object->billing_address['state'],
												'zip'              => $billing_zip,
												'country'          => $country,
											),
										),
									),
								);
							}
						}
					}

					// If we have the shipping info, we may as well include it in the fields sent to Authorize.Net
					if ( !empty( $transaction_object->shipping_address ) ) {
						$transaction_fields['ARBUpdateSubscriptionRequest']['subscription']['shipTo']['address'] = $transaction_object->shipping_address['address1'] . ( !empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->shipping_address['address2'] : '' );
						$transaction_fields['ARBUpdateSubscriptionRequest']['subscription']['shipTo']['city']    = $transaction_object->shipping_address['city'];
						$transaction_fields['ARBUpdateSubscriptionRequest']['subscription']['shipTo']['state']   = $transaction_object->shipping_address['state'];
						$transaction_fields['ARBUpdateSubscriptionRequest']['subscription']['shipTo']['zip']     = $shipping_zip;
						$transaction_fields['ARBUpdateSubscriptionRequest']['subscription']['shipTo']['country'] = $transaction_object->shipping_address['country'];
					}
				} else {
					$transaction_fields = array(
						'ARBCreateSubscriptionRequest' => array(
							'merchantAuthentication' => array(
								'name'			     => $api_username,
								'transactionKey'     => $api_password,
							),
							'refId' => $reference_id,
							'subscription' => array(
								'name' => it_exchange_get_cart_description(),
								'paymentSchedule' => array(
									'interval' => array(
										'length'   => $duration,
										'unit'     => $unit,
									),
									'startDate'        => date_i18n( 'Y-m-d' ),
									'totalOccurrences' => 9999, // To submit a subscription with no end date (an ongoing subscription), this field must be submitted with a value of “9999.”
									'trialOccurrences' => $trial_duration, //optional
								),
								'amount'      => $transaction_object->total,
								'trialAmount' => 0.00,
								'payment'        => array(
									'creditCard'     => array(
										'cardNumber'     => $cc_data['number'],
										'expirationDate' => $cc_data['expiration-month'] . $cc_data['expiration-year'],
										'cardCode'       => $cc_data['code'],
									),
								),
								'order'          => array(
									'description'    => it_exchange_get_cart_description(),
								),
								'customer'       => array(
									'id'               => $it_exchange_customer->ID,
									'email'            => $it_exchange_customer->data->user_email,
								),
								'billTo'         => array(
									'firstName'        => $cc_data['first-name'],
									'lastName'         => $cc_data['last-name'],
									'address'          => $transaction_object->billing_address['address1'] . ( !empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->billing_address['address2'] : '' ),
									'city'             => $transaction_object->billing_address['city'],
									'state'            => $transaction_object->billing_address['state'],
									'zip'              => $billing_zip,
									'country'          => $country,
								),
							),
						),
					);

					// If we have the shipping info, we may as well include it in the fields sent to Authorize.Net
					if ( !empty( $transaction_object->shipping_address ) ) {
						$transaction_fields['ARBCreateSubscriptionRequest']['subscription']['shipTo']['address'] = $transaction_object->shipping_address['address1'] . ( !empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->shipping_address['address2'] : '' );
						$transaction_fields['ARBCreateSubscriptionRequest']['subscription']['shipTo']['city']    = $transaction_object->shipping_address['city'];
						$transaction_fields['ARBCreateSubscriptionRequest']['subscription']['shipTo']['state']   = $transaction_object->shipping_address['state'];
						$transaction_fields['ARBCreateSubscriptionRequest']['subscription']['shipTo']['zip']     = $shipping_zip;
						$transaction_fields['ARBCreateSubscriptionRequest']['subscription']['shipTo']['country'] = $transaction_object->shipping_address['country'];
					}
				}
			} else {
				$transaction_fields = array(
					'createTransactionRequest' => array(
						'merchantAuthentication' => array(
							'name'			     => $api_username,
							'transactionKey'     => $api_password,
						),
						'refId' => $reference_id,
						'transactionRequest' => array(
							'transactionType'    => 'authCaptureTransaction',
							'amount'             => $transaction_object->total,
							'payment'        => array(
								'creditCard'     => array(
									'cardNumber'     => $cc_data['number'],
									'expirationDate' => $cc_data['expiration-month'] . $cc_data['expiration-year'],
									'cardCode'       => $cc_data['code'],
								),
							),
							'order'          => array(
								'description'    => it_exchange_get_cart_description(),
							),
							'customer'       => array(
								'id'               => $it_exchange_customer->ID,
								'email'               => $it_exchange_customer->data->user_email,
							),
							'billTo'         => array(
								'firstName'        => $cc_data['first-name'],
								'lastName'         => $cc_data['last-name'],
								'address'          => $transaction_object->billing_address['address1'] . ( !empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->billing_address['address2'] : '' ),
								'city'             => $transaction_object->billing_address['city'],
								'state'            => $transaction_object->billing_address['state'],
								'zip'              => $billing_zip,
								'country'          => $country,
							),
						),
					),
				);
				// If we have the shipping info, we may as well include it in the fields sent to Authorize.Net
				if ( !empty( $transaction_object->shipping_address ) ) {
					$transaction_fields['createTransactionRequest']['transactionRequest']['shipTo']['address'] = $transaction_object->shipping_address['address1'] . ( !empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->shipping_address['address2'] : '' );
					$transaction_fields['createTransactionRequest']['transactionRequest']['shipTo']['city']    = $transaction_object->shipping_address['city'];
					$transaction_fields['createTransactionRequest']['transactionRequest']['shipTo']['state']   = $transaction_object->shipping_address['state'];
					$transaction_fields['createTransactionRequest']['transactionRequest']['shipTo']['zip']     = $shipping_zip;
					$transaction_fields['createTransactionRequest']['transactionRequest']['shipTo']['country'] = $transaction_object->shipping_address['country'];
				}

				$transaction_fields['createTransactionRequest']['transactionRequest']['retail']['marketType'] = 0; //ecommerce
				$transaction_fields['createTransactionRequest']['transactionRequest']['retail']['deviceType'] = 8; //Website

				if ( $settings['authorizenet-test-mode'] ) {
					$transaction_fields['createTransactionRequest']['transactionRequest']['transactionSettings'] = array(
						'setting' => array(
							'settingName' => 'testRequest',
							'settingValue' => true
						)
					);
				}
			}

			$transaction_fields = apply_filters( 'it_exchange_authorizenet_transaction_fields', $transaction_fields );

			$query = array(
		        'headers' => array(
		            'Content-Type' => 'application/json',
				),
	            'body' => json_encode( $transaction_fields ),
				'timeout' => 30
			);

			$response = wp_remote_post( $api_url, $query );

			if ( !is_wp_error( $response ) ) {
				$body = preg_replace('/\xEF\xBB\xBF/', '', $response['body']);
				$obj = json_decode( $body, true );

				if ( isset( $obj['messages'] ) && isset( $obj['messages']['resultCode'] ) && $obj['messages']['resultCode'] == 'Error' ) {
					if ( ! empty( $obj['messages']['message'] ) ) {
						$error = reset( $obj['messages']['message'] );
						it_exchange_add_message( 'error', $error['text'] );

						return false;
					}
				}

				if ( $subscription ) {
					if ( !empty( $obj['subscriptionId'] ) ) {
						$txn_id = it_exchange_add_transaction( 'authorizenet', $reference_id, 1, $it_exchange_customer->id, $transaction_object );
						it_exchange_recurring_payments_addon_update_transaction_subscription_id( $txn_id, $obj['subscriptionId'] );
						it_exchange_authorizenet_addon_update_subscriber_id( $txn_id, $obj['subscriptionId'] );
						return $txn_id;
					} else {
						if ( !empty( $transaction['messages'] ) ) {
							foreach( $transaction['messages'] as $message ) {
								$exception[] = '<p>' . $message['text'] . '</p>';
							}
						}
						throw new Exception( implode( $exception ) );
					}
				} else {
					$transaction = $obj['transactionResponse'];
					$transaction_id = $transaction['transId'];

					if ( empty( $transaction_id ) ) { // transId is 0 for all test requests. Generate a random one.
						$transaction_id = substr( uniqid( 'test_' ), 0, 12 );
					}

					switch( $transaction['responseCode'] ) {
						case '1': //Approved
						case '4': //Held for Review
							//Might want to store the account number - $transaction['accountNumber']
							return it_exchange_add_transaction( 'authorizenet', $transaction_id, $transaction['responseCode'], $it_exchange_customer->id, $transaction_object );
						case '2': //Declined
						case '3': //Error
							if ( !empty( $transaction['messages'] ) ) {
								foreach( $transaction['messages'] as $message ) {
									$exception[] = '<p>' . $message['description'] . '</p>';
								}
							}
							if ( !empty( $transaction['errors'] ) ) {
								foreach( $transaction['errors'] as $error ) {
									$exception[] = '<p>' . $error['errorText'] . '</p>';
								}
							}
							throw new Exception( implode( $exception ) );
							break;
					}
				}
			} else {
				throw new Exception( $response->get_error_message() );
			}
		}
		catch( Exception $e ) {
			it_exchange_add_message( 'error', $e->getMessage() );
			it_exchange_flag_purchase_dialog_error( 'authorizenet' );
			return false;
		}

	} else {
		it_exchange_add_message( 'error', __( 'Unknown error. Please try again later.', 'LION' ) );
		it_exchange_flag_purchase_dialog_error( 'authorizenet' );
	}
	return false;

}
//add_action( 'it_exchange_do_transaction_authorizenet', 'it_exchange_authorizenet_addon_process_transaction', 10, 2 );

/**
 * Process a cancel subscription request.
 *
 * We only listen for ones that pass the subscription object. The other ones are fired from the
 * it_exchange_add_transaction() function, and we take care of those cancellations in our communications
 * with Authorize.net. So we don't want to cancel them here.
 *
 * @since 1.4.2
 *
 * @param array $details
 *
 * @throws Exception
 */
function it_exchange_authorizenet_addon_cancel_subscription( $details ) {

	if ( empty( $details['subscription'] ) || ! $details['subscription'] instanceof IT_Exchange_Subscription ) {
		return;
	}

	if ( ! $details['subscription']->get_subscriber_id() ) {
		return;
	}

	$settings = it_exchange_get_option( 'addon_authorizenet' );

	$api_url       = ! empty( $settings['authorizenet-sandbox-mode'] ) ? AUTHORIZE_NET_AIM_API_SANDBOX_URL : AUTHORIZE_NET_AIM_API_LIVE_URL;
	$api_username  = ! empty( $settings['authorizenet-sandbox-mode'] ) ? $settings['authorizenet-sandbox-api-login-id'] : $settings['authorizenet-api-login-id'];
	$api_password  = ! empty( $settings['authorizenet-sandbox-mode'] ) ? $settings['authorizenet-sandbox-transaction-key'] : $settings['authorizenet-transaction-key'];

	$request = array(
		'ARBCancelSubscriptionRequest' => array(
			'merchantAuthentication' => array(
				'name'			     => $api_username,
				'transactionKey'     => $api_password,
			),
			'subscriptionId' => $details['subscription']->get_subscriber_id()
		),
	);

	$query = array(
		'headers' => array(
			'Content-Type' => 'application/json',
		),
		'body' => json_encode( $request ),
	);

	$response = wp_remote_post( $api_url, $query );

	if ( ! is_wp_error( $response ) ) {
		$body = preg_replace('/\xEF\xBB\xBF/', '', $response['body']);
		$obj  = json_decode( $body, true );

		if ( isset( $obj['messages'] ) && isset( $obj['messages']['resultCode'] ) && $obj['messages']['resultCode'] == 'Error' ) {
			if ( ! empty( $obj['messages']['message'] ) ) {
				$error = reset( $obj['messages']['message'] );
				it_exchange_add_message( 'error', $error['text'] );
			}
		}
	} else {
		throw new Exception( $response->get_error_message() );
	}
}

add_action( 'it_exchange_cancel_authorizenet_subscription', 'it_exchange_authorizenet_addon_cancel_subscription' );

/**
 * Returns the button for making the payment
 *
 * Exchange will loop through activated Payment Methods on the checkout page
 * and ask each transaction method to return a button using the following filter:
 * - it_exchange_get_[addon-slug]_make_payment_button
 * Transaction Method add-ons must return a button hooked to this filter if they
 * want people to be able to make purchases.
 *
 * @since 1.0.0
 *
 * @param array $options
 * @return string HTML button
*/
function it_exchange_authorizenet_addon_make_payment_button( $options ) {
	// Make sure we have items in the cart
	if ( 0 >= it_exchange_get_cart_total( false ) ) {
		return;
	}
	// Use the ITExchange Purchase Dialog for CC fields
	if ( function_exists( 'it_exchange_generate_purchase_dialog' ) ) {

		$settings = it_exchange_get_option( 'addon_authorizenet' );

		return it_exchange_generate_purchase_dialog( 'authorizenet', array(
			'purchase-label' => $settings['authorizenet-purchase-button-label']
		) );
	}
}

//add_filter( 'it_exchange_get_authorizenet_make_payment_button', 'it_exchange_authorizenet_addon_make_payment_button', 10, 2 );

/**
 * Gets the interpreted transaction status from valid Authorize.net transaction statuses
 *
 * For future reference, here are all of the Authorize.net Transaction Statuses, along with explanations.
 * Only the valid ones for the 1.0 release of the Authorize.net plugin are utilized in this function.
 *
 * - Approved Review
 * -– This status is specific to eCheck.Net. Transactions with this status were approved while awaiting processing.
 * - Authorized/Pending Capture
 * -– Transactions with this status have been authorized by the processor but will not be sent for settlement until a capture is performed.
 * - Authorized/Held Pending Release
 * -—Transactions with this status are part of a larger order. Each individual transaction pays for part of the total order amount.
 * - Captured/Pending Settlement
 * -– Transactions with this status have been approved and captured, and will be picked up and sent for settlement at the transaction cut-off time.
 * - Could Not Void
 * -– Transactions with this status experienced a processing error during a payment gateway generated void. These voids may be resubmitted if the batch is still open.
 * - Declined
 * -– Transactions with this status were not approved at the processor. These transactions may not be captured and submitted for settlement.
 * - Expired
 * -– Transactions that are expired were authorized but never submitted for capture. Transactions typically expire approximately 30 days after the initial authorization.
 * - FDS - Authorized/Pending Review
 * -– This status is specific to the Fraud Detection Suite (FDS). Transactions with this status triggered one or more fraud filters with the “Authorize and hold for review” filter action, and are placed in this state once they are successfully authorized by the processor.
 * - FDS - Pending Review
 * -– This status is specific to the FDS. Transactions with this status triggered one or more fraud filters with the ”Do not authorize, but hold for review” filter action, and are placed in this state prior to being sent for authorization.
 * - Failed Review
 * -– This status is specific to eCheck.Net. Transactions with this status failed review while awaiting processing.
 * - Order Not Complete
 * -– This status applies to transactions that are part of an order that is not complete because only part of the total amount has been authorized.
 * - Refund
 * -– Transactions with this status have been submitted and authorized for refund.
 * - Refund/Pending Settlement
 * -– Transactions with this status have been submitted for refund and will be picked up and sent for settlement at the transaction cut-off time.
 * - Settled Successfully
 * -– Transactions with this status have been approved and successfully settled.
 * - Under Review
 * -– This status is specific to eCheck.Net. Transactions with this status are currently being reviewed before being submitted for processing.
 * - Voided
 * -– Transactions with this status have been voided and will not be sent for settlement. No further action may be taken for a voided transaction.
 *
 * Most gateway transaction stati are going to be lowercase, one word strings.
 * Hooking a function to the it_exchange_transaction_status_label_[addon-slug] filter
 * will allow add-ons to return the human readable label for a given transaction status.
 *
 * @since 1.0.0
 * @todo Chat with Glenn on this.  Authorize.net treats statuses differently than most other gateways.  It's more pass/fail, and then the TransactionDetails API is necessary to get the actual status
 *
 * @param string $status the string of the Authorize.net transaction
 * @return string translaction transaction status
*/
function it_exchange_authorizenet_addon_transaction_status_label( $status ) {
	switch ( $status ) {
		case '1' :
			return __( 'Paid', 'LION' );
		case '2' :
			return __( 'Declined', 'LION' );
		case '3' :
			return __( 'Error', 'LION' );
		case '4' :
			return __( 'Held: The transaction funds are currently held or under review.', 'LION' );
		default:
			return __( 'Unknown', 'LION' );
	}
}
add_filter( 'it_exchange_transaction_status_label_authorizenet', 'it_exchange_authorizenet_addon_transaction_status_label' );

/**
 * Returns a boolean. Is this transaction a status that warrants delivery of any products attached to it?
 *
 * Just because a transaction gets added to the DB doesn't mean that the admin is ready to give over
 * the goods yet. Each payment gateway will have different transaction stati. Exchange uses the following
 * filter to ask transaction-methods if a current status is cleared for delivery. Return true if the status
 * means its okay to give the download link out, ship the product, etc. Return false if we need to wait.
 * - it_exchange_[addon-slug]_transaction_is_cleared_for_delivery
 *
 * @since 1.0.0
 *
 * @param boolean $cleared passed in through WP filter. Ignored here.
 * @param object $transaction
 * @return boolean
*/
function it_exchange_authorizenet_transaction_is_cleared_for_delivery( $cleared, $transaction ) {
	$valid_stati = array( 1 );
	return in_array( it_exchange_get_transaction_status( $transaction ), $valid_stati );
}
add_filter( 'it_exchange_authorizenet_transaction_is_cleared_for_delivery', 'it_exchange_authorizenet_transaction_is_cleared_for_delivery', 10, 2 );
