<?php
/**
 * Contains deprecated functions.
 *
 * @since   2.0.0
 * @license GPLv2
 */

/**
 * This processes an Authorize.net transaction.
 *
 * We rely less on the customer ID here than Stripe does because the APIs approach customers with pretty significant
 * distinction Once we're ready to integrate CIM, it's probably worth changing this up a bit.
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
 * @deprecated 2.0.0
 *
 * @param string $status             passed by WP filter.
 * @param object $transaction_object The transaction object
 *
 * @return bool
 */
function it_exchange_authorizenet_addon_process_transaction( $status, $transaction_object ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	// If this has been modified as true already, return.
	if ( $status ) {
		return $status;
	}

	// Do we have valid CC fields?
	if ( ! it_exchange_submitted_purchase_dialog_values_are_valid( 'authorizenet' ) ) {
		return false;
	}

	// Grab CC data
	$cc_data = it_exchange_get_purchase_dialog_submitted_values( 'authorizenet' );

	// Make sure we have the correct $_POST argument
	if ( ! empty( $_POST[ it_exchange_get_field_name( 'transaction_method' ) ] ) && 'authorizenet' == $_POST[ it_exchange_get_field_name( 'transaction_method' ) ] ) {
		try {
			$settings = it_exchange_get_option( 'addon_authorizenet' );

			$api_url              = ! empty( $settings['authorizenet-sandbox-mode'] ) ? AUTHORIZE_NET_AIM_API_SANDBOX_URL : AUTHORIZE_NET_AIM_API_LIVE_URL;
			$api_username         = ! empty( $settings['authorizenet-sandbox-mode'] ) ? $settings['authorizenet-sandbox-api-login-id'] : $settings['authorizenet-api-login-id'];
			$api_password         = ! empty( $settings['authorizenet-sandbox-mode'] ) ? $settings['authorizenet-sandbox-transaction-key'] : $settings['authorizenet-transaction-key'];
			$it_exchange_customer = it_exchange_get_current_customer();

			$subscription         = false;
			$it_exchange_customer = it_exchange_get_current_customer();
			$reference_id         = substr( it_exchange_create_unique_hash(), 20 );

			remove_filter( 'the_title', 'wptexturize' ); // remove this because it screws up the product titles in PayPal
			$cart = it_exchange_get_cart_products();
			if ( 1 === absint( count( $cart ) ) ) {
				foreach ( $cart as $product ) {
					if ( it_exchange_product_supports_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'auto-renew' ) ) ) {
						if ( it_exchange_product_has_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'auto-renew' ) ) ) {
							$trial_enabled        = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'trial-enabled' ) );
							$trial_interval       = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'trial-interval' ) );
							$trial_interval_count = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'trial-interval-count' ) );
							$auto_renew           = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'auto-renew' ) );
							$interval             = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'interval' ) );
							$interval_count       = it_exchange_get_product_feature( $product['product_id'], 'recurring-payments', array( 'setting' => 'interval-count' ) );

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
							$duration = apply_filters( 'it_exchange_authorizenet_addon_process_transaction_subscription_duration', $duration, $product );

							$trial_unit     = null;
							$trial_duration = 0;
							if ( $trial_enabled ) {
								$allow_trial = true;
								//Should we all trials?
								if ( 'membership-product-type' === it_exchange_get_product_type( $product['product_id'] ) ) {
									if ( is_user_logged_in() ) {
										if ( function_exists( 'it_exchange_get_session_data' ) ) {
											$member_access = it_exchange_get_session_data( 'member_access' );
											$children      = (array) it_exchange_membership_addon_get_all_the_children( $product['product_id'] );
											$parents       = (array) it_exchange_membership_addon_get_all_the_parents( $product['product_id'] );
											foreach ( $member_access as $prod_id => $txn_id ) {
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
							$product_id   = $product['product_id'];
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
				if ( ! empty( $upgrade_downgrade ) ) {
					foreach ( $cart as $product ) {
						if ( ! empty( $upgrade_downgrade[ $product['product_id'] ] ) ) {
							$product_id = $product['product_id'];
							if ( ! empty( $upgrade_downgrade[ $product_id ]['old_transaction_id'] )
							     && ! empty( $upgrade_downgrade[ $product_id ]['old_transaction_method'] )
							) {
								$transaction_fields = array(
									'ARBUpdateSubscriptionRequest' => array(
										'merchantAuthentication' => array(
											'name'           => $api_username,
											'transactionKey' => $api_password,
										),
										'refId'                  => $reference_id,
										'subscriptionId'         => $upgrade_downgrade[ $product_id ]['old_transaction_id'],
										'subscription'           => array(
											'name'            => it_exchange_get_cart_description(),
											'paymentSchedule' => array(
												'interval'         => array(
													'length' => $duration,
													'unit'   => $unit,
												),
												'startDate'        => date_i18n( 'Y-m-d' ),
												'totalOccurrences' => 9999,
												// To submit a subscription with no end date (an ongoing subscription), this field must be submitted with a value of “9999.”
												'trialOccurrences' => $trial_duration,
												//optional
											),
											'amount'          => $transaction_object->total,
											'trialAmount'     => 0.00,
											'payment'         => array(
												'creditCard' => array(
													'cardNumber'     => $cc_data['number'],
													'expirationDate' => $cc_data['expiration-month'] . $cc_data['expiration-year'],
													'cardCode'       => $cc_data['code'],
												),
											),
											'order'           => array(
												'description' => it_exchange_get_cart_description(),
											),
											'customer'        => array(
												'id'    => $it_exchange_customer->ID,
												'email' => $it_exchange_customer->data->user_email,
											),
											'billTo'          => array(
												'firstName' => $cc_data['first-name'],
												'lastName'  => $cc_data['last-name'],
												'address'   => $transaction_object->billing_address['address1'] . ( ! empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->billing_address['address2'] : '' ),
												'city'      => $transaction_object->billing_address['city'],
												'state'     => $transaction_object->billing_address['state'],
												'zip'       => $billing_zip,
												'country'   => $country,
											),
										),
									),
								);
							}
						}
					}

					// If we have the shipping info, we may as well include it in the fields sent to Authorize.Net
					if ( ! empty( $transaction_object->shipping_address ) ) {
						$transaction_fields['ARBUpdateSubscriptionRequest']['subscription']['shipTo']['address'] = $transaction_object->shipping_address['address1'] . ( ! empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->shipping_address['address2'] : '' );
						$transaction_fields['ARBUpdateSubscriptionRequest']['subscription']['shipTo']['city']    = $transaction_object->shipping_address['city'];
						$transaction_fields['ARBUpdateSubscriptionRequest']['subscription']['shipTo']['state']   = $transaction_object->shipping_address['state'];
						$transaction_fields['ARBUpdateSubscriptionRequest']['subscription']['shipTo']['zip']     = $shipping_zip;
						$transaction_fields['ARBUpdateSubscriptionRequest']['subscription']['shipTo']['country'] = $transaction_object->shipping_address['country'];
					}
				} else {
					$transaction_fields = array(
						'ARBCreateSubscriptionRequest' => array(
							'merchantAuthentication' => array(
								'name'           => $api_username,
								'transactionKey' => $api_password,
							),
							'refId'                  => $reference_id,
							'subscription'           => array(
								'name'            => it_exchange_get_cart_description(),
								'paymentSchedule' => array(
									'interval'         => array(
										'length' => $duration,
										'unit'   => $unit,
									),
									'startDate'        => date_i18n( 'Y-m-d' ),
									'totalOccurrences' => 9999,
									// To submit a subscription with no end date (an ongoing subscription), this field must be submitted with a value of “9999.”
									'trialOccurrences' => $trial_duration,
									//optional
								),
								'amount'          => $transaction_object->total,
								'trialAmount'     => 0.00,
								'payment'         => array(
									'creditCard' => array(
										'cardNumber'     => $cc_data['number'],
										'expirationDate' => $cc_data['expiration-month'] . $cc_data['expiration-year'],
										'cardCode'       => $cc_data['code'],
									),
								),
								'order'           => array(
									'description' => it_exchange_get_cart_description(),
								),
								'customer'        => array(
									'id'    => $it_exchange_customer->ID,
									'email' => $it_exchange_customer->data->user_email,
								),
								'billTo'          => array(
									'firstName' => $cc_data['first-name'],
									'lastName'  => $cc_data['last-name'],
									'address'   => $transaction_object->billing_address['address1'] . ( ! empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->billing_address['address2'] : '' ),
									'city'      => $transaction_object->billing_address['city'],
									'state'     => $transaction_object->billing_address['state'],
									'zip'       => $billing_zip,
									'country'   => $country,
								),
							),
						),
					);

					// If we have the shipping info, we may as well include it in the fields sent to Authorize.Net
					if ( ! empty( $transaction_object->shipping_address ) ) {
						$transaction_fields['ARBCreateSubscriptionRequest']['subscription']['shipTo']['address'] = $transaction_object->shipping_address['address1'] . ( ! empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->shipping_address['address2'] : '' );
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
							'name'           => $api_username,
							'transactionKey' => $api_password,
						),
						'refId'                  => $reference_id,
						'transactionRequest'     => array(
							'transactionType' => 'authCaptureTransaction',
							'amount'          => $transaction_object->total,
							'payment'         => array(
								'creditCard' => array(
									'cardNumber'     => $cc_data['number'],
									'expirationDate' => $cc_data['expiration-month'] . $cc_data['expiration-year'],
									'cardCode'       => $cc_data['code'],
								),
							),
							'order'           => array(
								'description' => it_exchange_get_cart_description(),
							),
							'customer'        => array(
								'id'    => $it_exchange_customer->ID,
								'email' => $it_exchange_customer->data->user_email,
							),
							'billTo'          => array(
								'firstName' => $cc_data['first-name'],
								'lastName'  => $cc_data['last-name'],
								'address'   => $transaction_object->billing_address['address1'] . ( ! empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->billing_address['address2'] : '' ),
								'city'      => $transaction_object->billing_address['city'],
								'state'     => $transaction_object->billing_address['state'],
								'zip'       => $billing_zip,
								'country'   => $country,
							),
						),
					),
				);
				// If we have the shipping info, we may as well include it in the fields sent to Authorize.Net
				if ( ! empty( $transaction_object->shipping_address ) ) {
					$transaction_fields['createTransactionRequest']['transactionRequest']['shipTo']['address'] = $transaction_object->shipping_address['address1'] . ( ! empty( $transaction_object->shipping_address['address2'] ) ? ', ' . $transaction_object->shipping_address['address2'] : '' );
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
							'settingName'  => 'testRequest',
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
				'body'    => json_encode( $transaction_fields ),
				'timeout' => 30
			);

			$response = wp_remote_post( $api_url, $query );

			if ( ! is_wp_error( $response ) ) {
				$body = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
				$obj  = json_decode( $body, true );

				if ( isset( $obj['messages'] ) && isset( $obj['messages']['resultCode'] ) && $obj['messages']['resultCode'] == 'Error' ) {
					if ( ! empty( $obj['messages']['message'] ) ) {
						$error = reset( $obj['messages']['message'] );
						it_exchange_add_message( 'error', $error['text'] );

						return false;
					}
				}

				if ( $subscription ) {
					if ( ! empty( $obj['subscriptionId'] ) ) {
						$txn_id = it_exchange_add_transaction( 'authorizenet', $reference_id, 1, $it_exchange_customer->id, $transaction_object );
						it_exchange_recurring_payments_addon_update_transaction_subscription_id( $txn_id, $obj['subscriptionId'] );
						it_exchange_authorizenet_addon_update_subscriber_id( $txn_id, $obj['subscriptionId'] );

						return $txn_id;
					} else {
						if ( ! empty( $transaction['messages'] ) ) {
							foreach ( $transaction['messages'] as $message ) {
								$exception[] = '<p>' . $message['text'] . '</p>';
							}
						}
						throw new Exception( implode( $exception ) );
					}
				} else {
					$transaction    = $obj['transactionResponse'];
					$transaction_id = $transaction['transId'];

					if ( empty( $transaction_id ) ) { // transId is 0 for all test requests. Generate a random one.
						$transaction_id = substr( uniqid( 'test_' ), 0, 12 );
					}

					switch ( $transaction['responseCode'] ) {
						case '1': //Approved
						case '4': //Held for Review
							//Might want to store the account number - $transaction['accountNumber']
							return it_exchange_add_transaction( 'authorizenet', $transaction_id, $transaction['responseCode'], $it_exchange_customer->id, $transaction_object );
						case '2': //Declined
						case '3': //Error
							if ( ! empty( $transaction['messages'] ) ) {
								foreach ( $transaction['messages'] as $message ) {
									$exception[] = '<p>' . $message['description'] . '</p>';
								}
							}
							if ( ! empty( $transaction['errors'] ) ) {
								foreach ( $transaction['errors'] as $error ) {
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
		} catch ( Exception $e ) {
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

/**
 * Process a cancel subscription request.
 *
 * We only listen for ones that pass the subscription object. The other ones are fired from the
 * it_exchange_add_transaction() function, and we take care of those cancellations in our communications
 * with Authorize.net. So we don't want to cancel them here.
 *
 * @since 1.4.2
 *
 * @deprecated 2.0.0
 *
 * @param array $details
 *
 * @throws Exception
 */
function it_exchange_authorizenet_addon_cancel_subscription( $details ) {

	_deprecated_function( __FUNCTION__, '2.0.0', 'IT_Exchange_Subscription::cancel' );

	if ( empty( $details['subscription'] ) || ! $details['subscription'] instanceof IT_Exchange_Subscription ) {
		return;
	}

	if ( ! $details['subscription']->get_subscriber_id() ) {
		return;
	}

	$settings = it_exchange_get_option( 'addon_authorizenet' );

	$api_url      = ! empty( $settings['authorizenet-sandbox-mode'] ) ? AUTHORIZE_NET_AIM_API_SANDBOX_URL : AUTHORIZE_NET_AIM_API_LIVE_URL;
	$api_username = ! empty( $settings['authorizenet-sandbox-mode'] ) ? $settings['authorizenet-sandbox-api-login-id'] : $settings['authorizenet-api-login-id'];
	$api_password = ! empty( $settings['authorizenet-sandbox-mode'] ) ? $settings['authorizenet-sandbox-transaction-key'] : $settings['authorizenet-transaction-key'];

	$request = array(
		'ARBCancelSubscriptionRequest' => array(
			'merchantAuthentication' => array(
				'name'           => $api_username,
				'transactionKey' => $api_password,
			),
			'subscriptionId'         => $details['subscription']->get_subscriber_id()
		),
	);

	$query = array(
		'headers' => array(
			'Content-Type' => 'application/json',
		),
		'body'    => json_encode( $request ),
	);

	$response = wp_remote_post( $api_url, $query );

	if ( ! is_wp_error( $response ) ) {
		$body = preg_replace( '/\xEF\xBB\xBF/', '', $response['body'] );
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
 * @deprecated 2.0.0
 *
 * @param array $options
 *
 * @return string HTML button
 */
function it_exchange_authorizenet_addon_make_payment_button( $options ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

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


/**
 * This is the function registered in the options array when it_exchange_register_addon was called for Authorize.net
 *
 * It tells Exchange where to find the settings page
 *
 * @deprecated 2.0.0
 *
 * @return void
 */
function it_exchange_authorizenet_addon_settings_callback() {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	$IT_Exchange_AuthorizeNet_Add_On = new IT_Exchange_AuthorizeNet_Add_On();
	$IT_Exchange_AuthorizeNet_Add_On->print_settings_page();
}

/**
 * Outputs wizard settings for Authorize.Net
 *
 * Exchange allows add-ons to add a small amount of settings to the wizard.
 * You can add these settings to the wizard by hooking into the following action:
 * - it_exchange_print_[addon-slug]_wizard_settings
 * Exchange exspects you to print your fields here.
 *
 * @since 1.0.0
 *
 * @deprecated 2.0.0
 *
 * @param object $form Current IT Form object
 * @return void
 */
function it_exchange_print_authorizenet_wizard_settings( $form ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	$IT_Exchange_AuthorizeNet_Add_On = new IT_Exchange_AuthorizeNet_Add_On();
	$settings    = it_exchange_get_option( 'addon_authorizenet', true );
	$form_values = ITUtility::merge_defaults( ITForm::get_post_data(), $settings );
	$hide_if_js  =  it_exchange_is_addon_enabled( 'authorizenet' ) ? '' : 'hide-if-js';
	?>
	<div class="field authorizenet-wizard <?php echo $hide_if_js; ?>">
		<?php if ( empty( $hide_if_js ) ) { ?>
			<input class="enable-authorizenet" type="hidden" name="it-exchange-transaction-methods[]" value="authorizenet" />
		<?php } ?>
		<?php $IT_Exchange_AuthorizeNet_Add_On->get_authorizenet_payment_form_table( $form, $form_values ); ?>
	</div>
	<?php
}

/**
 * Saves Authorize.Net settings when the Wizard is saved
 *
 * @since 1.0.0
 *
 * @deprecated 2.0.0
 *
 * @return void
 */
function it_exchange_save_authorizenet_wizard_settings( $errors ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	if ( ! empty( $errors ) )
		return $errors;

	$IT_Exchange_AuthorizeNet_Add_On = new IT_Exchange_AuthorizeNet_Add_On();
	return $IT_Exchange_AuthorizeNet_Add_On->authorizenet_save_wizard_settings();
}

/**
 * Default settings for Authorize.Net
 *
 * @since 1.0.0
 *
 * @deprecated 2.0.0
 *
 * @param array $values
 * @return array
 */
function it_exchange_authorizenet_addon_default_settings( $values ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	$defaults = array(
		'authorizenet-api-login-id'            => '',
		'authorizenet-transaction-key'         => '',
		'authorizenet-md5-hash'                => '',
		'authorizenet-test-mode'               => false,
		'authorizenet-sandbox-mode'            => false,
		'authorizenet-sandbox-api-login-id'    => '',
		'authorizenet-sandbox-transaction-key' => '',
		'authorizenet-sandbox-md5-hash'        => '',
		'authorizenet-purchase-button-label'   => __( 'Purchase', 'LION' ),
		'evosnap-international'                => false
	);
	$values = ITUtility::merge_defaults( $values, $defaults );
	return $values;
}

/**
 * Filters default currencies to only display those supported by Authorize.Net
 *
 * @since 1.0.0
 *
 * @deprecated 2.0.0
 *
 * @param array $default_currencies Array of default currencies supplied by iThemes Exchange
 * @return array filtered list of currencies only supported by Authorize.Net
 */
function it_exchange_authorizenet_addon_get_currency_options( $default_currencies ) {

	_deprecated_function( __FUNCTION__, '2.0.0' );

	$IT_Exchange_AuthorizeNet_Add_On = new IT_Exchange_AuthorizeNet_Add_On();
	$authnet_currencies = $IT_Exchange_AuthorizeNet_Add_On->get_supported_currency_options();
	return array_intersect_key( $default_currencies, $authnet_currencies );
}