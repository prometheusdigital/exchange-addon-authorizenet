<?php

/*
Provides a simple interface for connecting iThemes' packages with the updater API.
Written by Chris Jean for iThemes.com
Version 1.0.1

Version History
	1.0.0 - 2013-04-11 - Chris Jean
		Release ready
	1.0.1 - 2013-05-01 - Chris Jean
		Fixed a bug where some plugins caused the filter_update_plugins and filter_update_themes to run when load hadn't run, causing errors.
*/


if ( defined( 'ITHEMES_UPDATER_DISABLE' ) && ITHEMES_UPDATER_DISABLE )
	return;

if ( ! is_admin() )
	return;

if ( defined( 'DOING_AJAX' ) && DOING_AJAX )
	return;


class Ithemes_Updater_Init {
	private $page_name = 'ithemes-licensing';
	private $option_name = 'ithemes-updater-cache';

	private $packages = array();
	private $new_packages = array();
	private $package_details = false;
	private $options = false;
	private $options_modified = false;
	private $do_flush = false;
	private $registration_link = false;

	private $default_options = array(
		'server-cache'   => 30,
		'expiration'     => 0,
		'timestamp'      => 0,
		'packages'       => array(),
		'update_themes'  => array(),
		'update_plugins' => array(),
		'show_on_sites'  => false,
		'use_ca_patch'   => false,
		'use_ssl'        => true,
	);

	private $page_ref;


	public function __construct() {
//		$this->reset();

		$GLOBALS['ithemes-updater-object'] = $this;

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_pages' ) );
		add_action( 'network_admin_menu', array( $this, 'add_network_admin_pages' ) );

		add_action( 'admin_head-plugins.php', array( $this, 'show_activation_message' ) );
		add_action( 'admin_head-themes.php', array( $this, 'show_activation_message' ) );
		add_action( 'deactivated_plugin', array( $this, 'clear_activation_package' ) );

		add_filter( 'site_transient_update_plugins', array( $this, 'filter_update_plugins' ) );
		add_filter( 'site_transient_update_themes', array( $this, 'filter_update_themes' ) );
		add_filter( 'upgrader_pre_install', array( $this, 'filter_upgrader_pre_install' ) );
		add_filter( 'upgrader_post_install', array( $this, 'filter_upgrader_post_install' ), 10, 3 );
		add_filter( 'plugin_action_links', array( $this, 'filter_plugin_action_links' ), 10, 4 );
		add_filter( 'theme_action_links', array( $this, 'filter_theme_action_links' ), 10, 2 );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );

		add_action( 'shutdown', array( $this, 'shutdown' ) );
	}

	public function filter_plugins_api( $value, $action, $args ) {
		if ( empty( $this->options['update_plugins'] ) )
			return $value;

		foreach ( $this->options['update_plugins'] as $path => $data ) {
			if ( $data->slug == $args->slug ) {
				require_once( dirname( __FILE__ ) . '/information.php' );
				return Ithemes_Updater_Information::get_plugin_information( $path );
			}
		}

		return $value;
	}

	public function init() {
		$this->load();

		do_action( 'ithemes_updater_register', $this );

		$this->new_packages = array_diff( array_keys( $this->packages ), $this->options['packages'] );

		if ( $this->options['use_ca_patch'] )
			$this->enable_ssl_ca_patch();


		if ( ! empty( $_GET['ithemes-updater-force-refresh'] ) && current_user_can( 'manage_options' ) )
			$this->flush( 'forced' );
		else if ( empty( $this->options['expiration'] ) || ( $this->options['expiration'] <= time() ) )
			$this->flush( 'expired' );
		else if ( $this->is_expired( $this->options['timestamp'] ) )
			$this->flush( 'got stale' );
		else if ( ! empty( $this->new_packages ) )
			$this->flush( 'new packages' );
	}

	public function shutdown() {
		if ( $this->do_flush )
			$this->flush();

		if ( $this->options_modified )
			update_site_option( $this->option_name, $this->options );
	}

	public function load() {
		if ( false !== $this->options )
			return;

		$this->options = get_site_option( $this->option_name, false );

		if ( ( false === $this->options ) || ! is_array( $this->options ) )
			$this->options = array();

		$this->options = array_merge( $this->default_options, $this->options );

		if ( 0 == $this->options['timestamp'] )
			$this->update();
	}

	public function flush( $reason = '' ) {
		$this->do_flush = false;

		$this->update();
	}

	public function update() {
		require_once( dirname( __FILE__ ) . '/updates.php' );

		Ithemes_Updater_Updates::run_update();
	}

	public function get_option( $var ) {
		if ( isset( $this->options[$var] ) )
			return $this->options[$var];

		return null;
	}

	public function update_options( $updates ) {
		$this->options = array_merge( $this->options, $updates );
		$this->options_modified = true;
	}

	public function add_ca_patch_to_curl_opts( $handle ) {
		$url = curl_getinfo( $handle, CURLINFO_EFFECTIVE_URL );

		if ( ! preg_match( '/^https.*(api|downloads)\.ithemes\.com/', $url ) )
			return;

		curl_setopt( $handle, CURLOPT_CAINFO, dirname( __FILE__ ) . '/ca/cacert.crt' );
	}

	public function enable_ssl_ca_patch() {
		add_action( 'http_api_curl', array( $this, 'add_ca_patch_to_curl_opts' ) );
	}

	public function disable_ssl_ca_patch() {
		remove_action( 'http_api_curl', array( $this, 'add_ca_patch_to_curl_opts' ) );
	}

	public function filter_upgrader_pre_install( $value ) {
		$this->set_package_details();

		return $value;
	}
	public function filter_upgrader_post_install( $value, $hook_extra, $result ) {
		$this->do_flush = true;

		return $value;
	}

	public function clear_activation_package( $deactivated_path ) {
		$deactivated_path = WP_PLUGIN_DIR . "/$deactivated_path";

		foreach ( $this->packages as $package => $paths ) {
			if ( ! in_array( $deactivated_path, $paths ) || ( count( $paths ) > 1 ) )
				continue;

			$index = array_search( $package, $this->options['packages'] );

			if ( false === $index )
				return;

			unset( $this->options['packages'][$index] );
			$this->options_modified = true;

			return;
		}
	}

	public function show_activation_message() {
		if ( empty( $this->new_packages ) )
			return;


		natcasesort( $this->new_packages );
		require_once( dirname( __FILE__ ) . '/functions.php' );
		$names = array();

		foreach ( $this->new_packages as $package )
			$names = Ithemes_Updater_Functions::get_package_name( $package );

		if ( is_multisite() && is_network_admin() )
			$url = network_admin_url( 'settings.php' ) . "?page={$this->page_name}";
		else
			$url = admin_url( 'options-general.php' ) . "?page={$this->page_name}";

		echo '<div class="updated fade"><p>' . wp_sprintf( __( 'To receive automatic updates for %l, use the <a href="%s">iThemes Licensing</a> page found in the Settings menu.', 'LION' ), $names, $url ) . '</p></div>';


		$this->options['packages'] = array_keys( $this->packages );
		$this->options_modified = true;
	}

	public function add_admin_pages() {
		if ( is_multisite() && ! $this->options['show_on_sites'] )
			return;

		$this->page_ref = add_options_page( __( 'iThemes Licensing', 'LION' ), __( 'iThemes Licensing', 'LION' ), 'manage_options', $this->page_name, array( $this, 'settings_index' ) );

		add_action( "load-{$this->page_ref}", array( $this, 'load_settings_page' ) );
	}

	public function add_network_admin_pages() {
		$this->page_ref = add_submenu_page( 'settings.php', __( 'iThemes Licensing', 'LION' ), __( 'iThemes Licensing', 'LION' ), 'manage_options', $this->page_name, array( $this, 'settings_index' ) );

		add_action( "load-{$this->page_ref}", array( $this, 'load_settings_page' ) );
	}

	public function load_settings_page() {
		require( dirname( __FILE__ ) . '/settings-page.php' );
	}

	public function settings_index() {
		do_action( 'ithemes_updater_settings_page_index' );
	}

	public function register( $slug, $file ) {
		$this->packages[$slug][] = $file;
	}

	public function filter_update_plugins( $update_plugins ) {
		if ( ! is_object( $update_plugins ) )
			return $update_plugins;

		if ( ! isset( $update_plugins->response ) || ! is_array( $update_plugins->response ) )
			$update_plugins->response = array();

		if ( $this->do_flush )
			$this->flush();

		if ( ! is_array( $this->options ) || ! isset( $this->options['update_plugins'] ) )
			$this->load();

		if ( isset( $this->options['update_plugins'] ) && is_array( $this->options['update_plugins'] ) )
			$update_plugins->response = array_merge( $update_plugins->response, $this->options['update_plugins'] );

		return $update_plugins;
	}

	public function filter_update_themes( $update_themes ) {
		if ( ! is_object( $update_themes ) )
			return $update_themes;

		if ( ! isset( $update_themes->response ) || ! is_array( $update_themes->response ) )
			$update_themes->response = array();

		if ( $this->do_flush )
			$this->flush();

		if ( ! is_array( $this->options ) || ! isset( $this->options['update_themes'] ) )
			$this->load();

		if ( isset( $this->options['update_themes'] ) && is_array( $this->options['update_themes'] ) )
			$update_themes->response = array_merge( $update_themes->response, $this->options['update_themes'] );

		return $update_themes;
	}

	private function set_package_details() {
		if ( false !== $this->package_details )
			return;

		require_once( dirname( __FILE__ ) . '/packages.php' );
		$this->package_details = Ithemes_Updater_Packages::get_local_details();
	}

	private function set_registration_link() {
		if ( false !== $this->registration_link )
			return;

		$url = admin_url( 'options-general.php' ) . "?page={$this->page_name}";
		$this->registration_link = sprintf( '<a href="%1$s" title="%2$s">%3$s</a>', $url, __( 'Manage iThemes product licenses to receive automatic upgrade support', 'LION' ), __( 'License', 'LION' ) );
	}

	public function filter_plugin_action_links( $actions, $plugin_file, $plugin_data, $context ) {
		$this->set_package_details();
		$this->set_registration_link();

		if ( isset( $this->package_details[$plugin_file] ) )
			$actions[] = $this->registration_link;

		return $actions;
	}

	public function filter_theme_action_links( $actions, $theme ) {
		$this->set_package_details();
		$this->set_registration_link();

		if ( is_object( $theme ) )
			$path = basename( $theme->get_stylesheet_directory() ) . '/style.css';
		else if ( is_array( $theme ) && isset( $theme['Stylesheet Dir'] ) )
			$path = $theme['Stylesheet Dir'] . '/style.css';
		else
			$path = '';

		if ( isset( $this->package_details[$path] ) )
			$actions[] = $this->registration_link;

		return $actions;
	}

	private function is_expired( $timestamp ) {
		$page = empty( $_GET['page'] ) ? $GLOBALS['pagenow'] : $_GET['page'];

		switch ( $page ) {
			case 'update-core.php' :
			case 'ithemes-licensing' :
				$timeout = 60;
				break;
			case 'plugins.php' :
			case 'themes.php' :
			case 'update.php' :
				$timeout = 3600;
				break;
			default :
				$timeout = 12 * 3600;
		}

		$time = time();
		$age = $time - $timestamp;
		$time_remaining = $timeout - $age;

		if ( $timestamp <= ( time() - $timeout ) )
			return true;

		return false;
	}
}

new Ithemes_Updater_Init();
