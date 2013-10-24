<?php

/*
Provides details formatted for use in "View version *** details" boxes.
Written by Chris Jean for iThemes.com
Version 1.0.0

Version History
	1.0.0 - 2013-04-11 - Chris Jean
		Release ready
*/


class Ithemes_Updater_Information {
	public static function get_plugin_information( $path ) {
		require_once( dirname( __FILE__ ) . '/packages.php' );
		$details = Ithemes_Updater_Packages::get_full_details();

		if ( ! isset( $details['packages'][$path] ) )
			return false;


		$package = $details['packages'][$path];

		$url = "http://package-info.ithemes.com/{$package['package']}/information.json";
		$response = wp_remote_get( $url );

		if ( ! is_wp_error( $response ) && isset( $response['body'] ) ) {
			$info = json_decode( $response['body'] );

			if ( is_object( $info ) && ! empty( $info->name ) && ! empty( $info->version ) ) {
				$info->slug = dirname( $path );
				$info->download_link = $package['package-url'];

				return $info;
			}
		}


		require_once( dirname( __FILE__ ) . '/functions.php' );

		$info = array(
			'name'          => Ithemes_Updater_Functions::get_package_name( $package['package'] ),
			'slug'          => dirname( $path ),
			'version'       => $package['available'],
			'author'        => '<a href="http://ithemes.com/">iThemes</a>',
			'download_link' => $package['package-url'],

			'sections' => array(
				'changelog'    => __( 'These details will be available soon.', 'LION' ),
			),
		);


		return (object) $info;
	}
}
