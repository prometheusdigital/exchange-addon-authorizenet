<?php

/*
Provides a simple interface for connecting iThemes' packages with the updater API.
Written by Chris Jean for iThemes.com
Version 1.0.0

Version History
	1.0.0 - 2013-04-11 - Chris Jean
		Release ready
*/


class Ithemes_Updater_Updates {
	public static function run_update() {
		require_once( dirname( __FILE__ ) . '/api.php' );
		require_once( dirname( __FILE__ ) . '/packages.php' );
		require_once( dirname( __FILE__ ) . '/keys.php' );
		
		
		$keys = Ithemes_Updater_Keys::get();
		$legacy_keys = Ithemes_Updater_Keys::get_legacy();
		
		if ( empty( $keys ) && empty( $legacy_keys ) )
			return;
		
		
		Ithemes_Updater_API::get_package_details();
	}
	
	public static function process_server_response( $response ) {
		if ( empty( $response['packages'] ) )
			return;
		
		
		require_once( dirname( __FILE__ ) . '/keys.php' );
		require_once( dirname( __FILE__ ) . '/packages.php' );
		
		
		$keys = array();
		
		foreach ( $response['packages'] as $package => $data ) {
			if ( isset( $data['key'] ) )
				$keys[$package] = $data['key'];
			else if ( isset( $data['status'] ) && ( 'inactive' == $data['status'] ) )
				$keys[$package] = '';
		}
		
		Ithemes_Updater_Keys::set( $keys );
		
		
		$details = Ithemes_Updater_Packages::get_full_details( $response );
		
		$updates = array(
			'update_themes'  => array(),
			'update_plugins' => array(),
			'expiration'     => $details['expiration'],
			'timestamp'      => time(),
		);
		
		
		if ( isset( $response['min_poll_time'] ) )
			$updates['server-cache'] = $response['min_poll_time'];
		
		if ( ! isset( $updates['server-cache'] ) || ( $updates['server-cache'] < 30 ) )
			$updates['server-cache'] = 30;
		
		$use_ssl = $GLOBALS['ithemes-updater-object']->get_option( 'use_ssl' );
		
		
		foreach ( $details['packages'] as $path => $data ) {
			if ( empty( $data['package-url'] ) || version_compare( $data['installed'], $data['available'], '>=' ) )
				continue;
			
			if ( ! $use_ssl )
				$data['package-url'] = preg_replace( '/^https/', 'http', $data['package-url'] );
			
			if ( 'plugin' == $data['type'] ) {
				$update = array(
					'id'          => 0,
					'slug'        => dirname( $path ),
					'new_version' => $data['available'],
					'url'         => $data['info-url'],
					'package'     => $data['package-url'],
				);
				$update = (object) $update;
			}
			else {
				$update = array(
					'new_version' => $data['available'],
					'url'         => $data['info-url'],
					'package'     => $data['package-url'],
				);
				
				$path = dirname( $path );
			}
			
			$updates["update_{$data['type']}s"][$path] = $update;
		}
		
		
		$GLOBALS['ithemes-updater-object']->update_options( $updates );
	}
}
