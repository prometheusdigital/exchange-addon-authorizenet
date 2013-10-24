<?php

/*
Provides an easy to use interface for communicating with the iThemes updater server.
Written by Chris Jean for iThemes.com
Version 1.0.0

Version History
	1.0.0 - 2013-04-11 - Chris Jean
		Release ready
*/


class Ithemes_Updater_API {
	private static $clear_cache_index = 'ithemes-updater-clear-cache';
	
	
	public static function activate_package( $username, $password, $packages ) {
		return self::get_response( 'activate_package', compact( 'username', 'password', 'packages' ) );
	}
	
	public static function deactivate_package( $username, $password, $packages ) {
		return self::get_response( 'deactivate_package', compact( 'username', 'password', 'packages' ) );
	}
	
	public static function get_package_details() {
		$packages = array();
		
		return self::get_response( 'get_package_details', compact( 'packages' ), true );
	}
	
	public static function get_response( $action, $args, $cache = false ) {
		if ( ! isset( $GLOBALS[self::$clear_cache_index] ) )
			$GLOBALS[self::$clear_cache_index] = false;
		
		
		require_once( dirname( __FILE__ ) . '/server.php' );
		require_once( dirname( __FILE__ ) . '/updates.php' );
		
		
		if ( isset( $args['packages'] ) )
			$args['packages'] = self::get_request_package_details( $args['packages'] );
		
		
		$response = false;
		$source = '';
		
		
		if ( false !== $cache ) {
			$cache_var = "ithemes-updater-$action";
			$cache_length = $GLOBALS['ithemes-updater-object']->get_option( 'server-cache' );
			$md5 = substr( md5( serialize( $args ) ), 0, 5 );
			$time = time();
			
			if ( ! $GLOBALS[self::$clear_cache_index] ) {
				if ( isset( $GLOBALS[$cache_var] ) ) {
					$response = $GLOBALS[$cache_var];
					$source = 'GLOBALS';
				}
				else if ( false !== ( $transient = get_site_transient( $cache_var ) ) ) {
					if ( isset( $transient['md5'] ) && isset( $transient['res'] ) && isset( $transient['time'] ) && ( $md5 == $transient['md5'] ) && ( $time < ( $transient['time'] + $cache_length ) ) ) {
						$response = $transient['res'];
						$source = 'transient';
					}
				}
			}
			
			if ( false === $response )
				$GLOBALS[self::$clear_cache_index] = false;
		}
		else {
			$GLOBALS[self::$clear_cache_index] = true;
		}
		
		
		if ( false === $response ) {
			$response = call_user_func_array( array( 'Ithemes_Updater_Server', $action ), $args );
			$source = 'server';
			
			if ( is_wp_error( $response ) )
				return $response;
			
			
			if ( false !== $cache ) {
				$GLOBALS[$cache_var] = $response;
				
				$transient = array(
					'time' => $time,
					'md5'  => $md5,
					'res'  => $response,
				);
				set_site_transient( $cache_var, $transient, $cache_length );
			}
		}
		
		
		Ithemes_Updater_Updates::process_server_response( $response );
		
		return $response;
	}
	
	private static function get_request_package_details( $desired_packages = array() ) {
		require_once( dirname( __FILE__ ) . '/packages.php' );
		require_once( dirname( __FILE__ ) . '/keys.php' );
		
		
		$all_packages = Ithemes_Updater_Packages::get_local_details();
		reset( $desired_packages );
		
		if ( empty( $desired_packages ) ) {
			$desired_packages = $all_packages;
		}
		else if ( is_numeric( key( $desired_packages ) ) ) {
			$new_desired_packages = array();
			
			foreach ( $all_packages as $path => $details ) {
				foreach ( $desired_packages as $package ) {
					if ( $package != $details['package'] )
						continue;
					
					$new_desired_packages[$path] = $details;
					
					break;
				}
			}
			
			$desired_packages = $new_desired_packages;
		}
		
		
		$packages = array();
		$keys = Ithemes_Updater_Keys::get();
		
		
		$package_slugs = array();
		
		foreach ( $desired_packages as $data )
			$package_slugs[] = $data['package'];
		
		$legacy_keys = Ithemes_Updater_Keys::get_legacy( $package_slugs );
		
		
		$active_themes = array(
			'stylesheet' => get_stylesheet_directory(),
			'template'   => get_template_directory(),
		);
		$active_themes = array_unique( $active_themes );
		
		foreach ( $active_themes as $index => $path )
			$active_themes[$index] = basename( $path );
		
		
		foreach ( $desired_packages as $path => $data ) {
			$key = ( isset( $keys[$data['package']] ) ) ? $keys[$data['package']] : '';
			
			$package = array(
				'ver' => $data['installed'],
				'key' => $key,
			);
			
			if ( ! empty( $legacy_keys[$data['package']] ) )
				$package['old-key'] = $legacy_keys[$data['package']];
			
			
			if ( 'plugins' == $data['type'] ) {
				$package['active'] = (int) is_plugin_active( $path );
			}
			else {
				$dir = dirname( $path );
				
				$package['active'] = (int) in_array( $dir, $active_themes );
				
				if ( $package['active'] && ( count( $active_themes ) > 1 ) ) {
					if ( $dir == $active_themes['stylesheet'] )
						$package['child-theme'] = 1;
					else
						$package['parent-theme'] = 1;
				}
			}
			
			
			$package_key = $data['package'];
			$counter = 0;
			
			while ( isset( $packages[$package_key] ) )
				$package_key = "{$data['package']} ||| " . ++$counter;
			
			$packages[$package_key] = $package;
		}
		
		
		if ( ! empty( $legacy_keys ) )
			Ithemes_Updater_Keys::delete_legacy( array_keys( $legacy_keys ) );
		
		
		return $packages;
	}
}
