<?php

namespace WPProjects;

class WordPressConfig {

	/**
	 * Singleton
	 */
	private function __construct() { }

	/**
	 * Bootstrap WordPress just enough to get what we need.
	 *
	 * This method loads wp-config.php without wp-settings.php ensuring we get the constants we need. It
	 * also loads $wpdb so we can perform database operations.
	 */
	public function load() {
		$wp_config_code = explode( "\n", file_get_contents( Utils\locate_wp_config() ) );

		$found_wp_settings = false;
		$lines_to_run = [];

		foreach ( $wp_config_code as $line ) {
			if ( preg_match( '/^\s*require.+wp-settings\.php/', $line ) ) {
				continue;
			}

			$lines_to_run[] = $line;
		}

		$source = implode( "\n", $lines_to_run );

		if ( file_exists( getcwd() . '/wp-config.php' ) ) {
			define( 'ABSPATH', getcwd() . '/' );
		} else {
			define( 'ABSPATH', getcwd() . '/../' );
		}

		eval( preg_replace( '|^\s*\<\?php\s*|', '', $source ) );

		if ( defined( 'DOMAIN_CURRENT_SITE' ) ) {
			$url = DOMAIN_CURRENT_SITE;
			if ( defined( 'PATH_CURRENT_SITE' ) ) {
				$url .= PATH_CURRENT_SITE;
			}

			$url_parts = parse_url( $url );

			if ( !isset( $url_parts['scheme'] ) ) {
				$url_parts = parse_url( 'http://' . $url );
			}

			if ( isset( $url_parts['host'] ) ) {
				if ( isset( $url_parts['scheme'] ) && 'https' === strtolower( $url_parts['scheme'] ) ) {
					$_SERVER['HTTPS'] = 'on';
				}

				$_SERVER['HTTP_HOST'] = $url_parts['host'];
				if ( isset( $url_parts['port'] ) ) {
					$_SERVER['HTTP_HOST'] .= ':' . $url_parts['port'];
				}

				$_SERVER['SERVER_NAME'] = $url_parts['host'];
			}

			$_SERVER['REQUEST_URI'] = $url_parts['path'] . ( isset( $url_parts['query'] ) ? '?' . $url_parts['query'] : '' );
			$_SERVER['SERVER_PORT'] = ( isset( $url_parts['port'] ) ) ? $url_parts['port'] : 80;
			$_SERVER['QUERY_STRING'] = ( isset( $url_parts['query'] ) ) ? $url_parts['query'] : '';
		}

		// We can require settings after we fake $_SERVER keys
		require_once( ABSPATH . 'wp-settings.php' );
	}

	/**
	 * Return singleton instance of class
	 *
	 * @return object
	 */
	public static function instance() {
		static $instance;

		if ( empty( $instance ) ) {
			$instance = new self;
		}

		return $instance;
	}
}