<?php
/**
 * ExchangeWP Authorize.Net Add-on
 * @package IT_Exchange_Addon_AuthorizeNet
 * @since 1.0.0
*/

// developer accounts: https://test.authorize.net/gateway/transact.dll
// for real accounts (even in test mode), please make sure that you are
// posting to: https://secure.authorize.net/gateway/transact.dll
if ( !defined( 'AUTHORIZE_NET_AIM_API_LIVE_URL' ) )
	define( 'AUTHORIZE_NET_AIM_API_LIVE_URL', 'https://api.authorize.net/xml/v1/request.api' );
if ( !defined( 'AUTHORIZE_NET_AIM_API_SANDBOX_URL' ) )
	define( 'AUTHORIZE_NET_AIM_API_SANDBOX_URL', 'https://apitest.authorize.net/xml/v1/request.api' );

/**
 * Exchange Transaction Add-ons require several hooks in order to work properly.
 * Most of these hooks are called in api/transactions.php and are named dynamically
 * so that individual add-ons can target them. eg: it_exchange_refund_url_for_authorizenet
 * We've placed them all in one file to help add-on devs identify them more easily
*/
include( 'lib/required-hooks.php' );

/**
 * Exchange will build your add-on's settings page for you and link to it from our add-on
 * screen. You are free to link from it elsewhere as well if you'd like... or to not use our API
 * at all. This file has all the functions related to registering the page, printing the form, and saving
 * the options. This includes the wizard settings. Additionally, we use the Exchange storage API to
 * save / retreive options. Add-ons are not required to do this.
*/
include( 'lib/addon-settings.php' );

/**
 * Most Payment Gateway APIs use some concept of webhooks or notifications to communicate with
 * clients. While add-ons are not required to use the Exchange API, we have created a couple of functions
 * to register and listen for these webooks. The stripe add-on uses this API and we have placed the
 * registering and processing functions in this file.
*/
include( 'lib/addon-webhooks.php' );

/**
 * The following file contains utility functions specific to our Authorize.Net add-on
 * If you're building your own transaction-method addon, it's likely that you will
 * need to do similar things. This includes enqueueing scripts, formatting data for Authorize.Net, etc.
*/
include( 'lib/addon-functions.php' );
