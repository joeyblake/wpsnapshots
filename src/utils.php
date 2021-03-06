<?php

namespace WPSnapshots\Utils;

use WPSnapshots\Error;
use Requests;

/**
 * Check if object is of type Error
 *
 * @param  Object $obj
 * @return boolean
 */
function is_error( $obj ) {
	return ( $obj instanceof Error );
}

/**
 * Normalizes paths. Note that we DO always add a trailing slash here
 *
 * /
 * ./
 * ~/
 * ./test/
 * ~/test
 *
 * @param  string $path
 * @return string
 */
function normalize_path( $path ) {
	$path = trim( $path );

	if ( '/' === $path ) {
		return $path;
	}

	if ( '/' !== substr( $path, -1 ) ) {
		$path .= '/';
	}

	/**
	 * Replace ~ with home directory
	 */
	if ( '~' === substr( $path, 0, 1 ) ) {
		$path = ltrim( $path, '~' );

		$home = rtrim( $_SERVER['HOME'], '/' );

		$path = $home . $path;
	}

	return $path;
}

/**
 * Validator for Symfony Question
 *
 * @param  string $answer
 * @return string
 */
function not_empty_validator( $answer ) {
	if ( '' === trim( $answer ) ) {
		throw new \RuntimeException(
			'A valid answer is required.'
		);
	}

	return $answer;
}

/**
 * Validator for slugs
 *
 * @param  string $answer
 * @return string
 */
function slug_validator( $answer ) {
	if ( ! preg_match( '#^[a-z0-9\-_]+$#i', $answer ) ) {
		throw new \RuntimeException(
			'A valid non-empty slug is required (letters, numbers, -, and _).'
		);
	}

	return strtolower( $answer );
}

/**
 * Create a wp-config.php with constants based on a template file
 *
 * @param  string $path
 * @param  string $path_to_template
 * @param  array  $constants
 */
function create_config_file( $path, $path_to_template, $constants = [] ) {
	$template = file_get_contents( $path_to_template );

	$template_code = explode( "\n", $template );
	$new_file = [];

	foreach ( $template_code as $line ) {
		if ( preg_match( '/^\s*require.+wp-settings\.php/', $line ) ) {
			continue;
		}

		foreach ( $constants as $config_constant => $config_constant_value ) {
			if ( preg_match( '#define\(.*?("|\')' . $config_constant . '("|\').*?\).*?;#', $line ) ) {
				continue 2;
			}
		}

		$new_file[] = $line;
	}

	foreach ( $constants as $config_constant => $config_constant_value ) {
		if ( ! is_bool( $config_constant_value ) && ! is_int( $config_constant_value ) ) {
			$config_constant_value = "'" . addslashes( $config_constant_value ) . "'";
		}

		$new_file[] = "define( '" . addslashes( $config_constant ) . "', $config_constant_value );";
	}

	$new_file[] = "require_once(ABSPATH . 'wp-settings.php');";

	file_put_contents( $path, implode( "\n", $new_file ) );
}

/**
 * Get download url for WP
 *
 * @param  string $version
 * @param  string $locale
 * @return string|bool
 */
function get_download_url( $version = 'latest', $locale = 'en_US' ) {
	if ( 'nightly' === $version ) {
		return 'https://wordpress.org/nightly-builds/wordpress-latest.zip';
	}

	if ( 'latest' === $version ) {
		$headers = [ 'Accept' => 'application/json' ];

		try {
			$request = Requests::get( 'https://api.wordpress.org/core/version-check/1.6/?locale=' . $locale, $headers );
		} catch ( \Exception $e ) {
			return false;
		}

		if ( 200 !== (int) $request->status_code ) {
			return false;
		}

		$request_body = unserialize( $request->body );

		if ( empty( $request_body['offers'] ) || empty( $request_body['offers'][0] ) || empty( $request_body['offers'][0]['download'] ) ) {
			return false;
		}

		return str_replace( '.zip', '.tar.gz', $request_body['offers'][0]['download'] );
	}

	if ( 'en_US' === $locale ) {
		$url = 'https://wordpress.org/wordpress-' . $version . '.tar.gz';

		return $url;
	} else {
		$url = sprintf(
			'https://%s.wordpress.org/wordpress-%s-%s.tar.gz',
			substr( $locale, 0, 2 ),
			$version,
			$locale
		);

		return $url;
	}
}

/**
 * Is WordPress in the directory?
 *
 * @param  string $path
 * @return boolean
 */
function is_wp_present( $path ) {
	return ( file_exists( $path . 'wp-settings.php' ) );
}

/**
 * Find wp-config.php
 *
 * @return string
 */
function locate_wp_config( $path ) {
	if ( file_exists( $path . 'wp-config.php' ) ) {
		$path =  $path . 'wp-config.php';
	} elseif ( file_exists( $path . '../wp-config.php' ) ) {
		$path = $path . '../wp-config.php';
	} else {
		return false;
	}

	return realpath( $path );
}

/**
 * Remove .wpsnapshots temp folder. The folder stores temporary backup files
 *
 * @return Error|bool
 */
function remove_temp_folder( $path ) {
	$temp_path = $path . '.wpsnapshots';

	if ( file_exists( $temp_path ) ) {
		try {
			foreach ( glob( $temp_path . '/{,.}*', GLOB_BRACE ) as $filename ) {
			    if ( is_file( $filename ) ) {
			        unlink( $filename );
			    }
			}

			rmdir( $temp_path );
		} catch ( \Exception $e ) {
			return new Error( 0, 'Could not remove dir' );
		}
	}

	return true;
}

/**
 * Run MySQL command via proc given associative command line args
 *
 * @param  string $cmd
 * @param  array  $assoc_args
 * @param  string $append
 * @param  bool   $exit_on_error
 */
function run_mysql_command( $cmd, $assoc_args, $append = '', $exit_on_error = true ) {
	check_proc_available( 'run_mysql_command' );

	if ( isset( $assoc_args['host'] ) ) {
		$assoc_args = array_merge( $assoc_args, mysql_host_to_cli_args( $assoc_args['host'] ) );
	}

	$pass = $assoc_args['pass'];
	unset( $assoc_args['pass'] );

	$old_pass = getenv( 'MYSQL_PWD' );
	putenv( 'MYSQL_PWD=' . $pass );

	$final_cmd = force_env_on_nix_systems( $cmd ) . assoc_args_to_str( $assoc_args ) . $append;

	$proc = proc_open( $final_cmd, [ STDIN, STDOUT, STDERR ], $pipes );

	if ( $exit_on_error && ! $proc ) {
		exit( 1 );
	}

	$r = proc_close( $proc );

	putenv( 'MYSQL_PWD=' . $old_pass );

	if ( $exit_on_error ) {
		if ( $r ) {
			exit( $r );
		}
	} else {
		return $r;
	}
}

/**
 * Returns tables
 *
 * @return array
 */
function get_tables( $wp = true ) {
	global $wpdb;

	$tables = [];

	$results = $wpdb->get_results( 'SHOW TABLES', ARRAY_A );

	foreach ( $results as $table_info ) {
		$table_info = array_values( $table_info );
		$table = $table_info[0];

		if ( $wp ) {
			if ( 0 === strpos( $table, $GLOBALS['table_prefix'] ) ) {
				$tables[] = $table;
			}
		} else {
			$tables[] = $table;
		}
	}

	return $tables;
}

/**
 * Translate mysql host to cli args
 *
 * @param  string $raw_host
 * @return array
 */
function mysql_host_to_cli_args( $raw_host ) {
	$assoc_args = array();
	$host_parts = explode( ':',  $raw_host );

	if ( count( $host_parts ) == 2 ) {
		list( $assoc_args['host'], $extra ) = $host_parts;
		$extra = trim( $extra );

		if ( is_numeric( $extra ) ) {
			$assoc_args['port'] = intval( $extra );
			$assoc_args['protocol'] = 'tcp';
		} elseif ( $extra !== '' ) {
			$assoc_args['socket'] = $extra;
		}
	} else {
		$assoc_args['host'] = $raw_host;
	}

	return $assoc_args;
}

/**
 * Shell escape command as an array
 *
 * @param  array $cmd
 * @return array
 */
function esc_cmd( $cmd ) {
	if ( func_num_args() < 2 ) {
		trigger_error( 'esc_cmd() requires at least two arguments.', E_USER_WARNING );
	}

	$args = func_get_args();
	$cmd = array_shift( $args );

	return vsprintf( $cmd, array_map( 'escapeshellarg', $args ) );
}

/**
 * Make sure env path is used on *nix
 *
 * @param  string $command
 * @return string
 */
function force_env_on_nix_systems( $command ) {
	$env_prefix = '/usr/bin/env ';
	$env_prefix_len = strlen( $env_prefix );

	if ( is_windows() ) {
		if ( 0 === strncmp( $command, $env_prefix, $env_prefix_len ) ) {
			$command = substr( $command, $env_prefix_len );
		}
	} else {
		if ( 0 !== strncmp( $command, $env_prefix, $env_prefix_len ) ) {
			$command = $env_prefix . $command;
		}
	}

	return $command;
}

/**
 * Determine if we are on windows
 *
 * @return bool
 */
function is_windows() {
	return strtoupper( substr( PHP_OS, 0, 3 ) ) === 'WIN';
}

/**
 * Convert assoc array to string to command
 *
 * @param  array $assoc_args
 * @return string
 */
function assoc_args_to_str( $assoc_args ) {
	$str = '';

	foreach ( $assoc_args as $key => $value ) {
		if ( true === $value ) {
			$str .= " --$key";
		} elseif ( is_array( $value ) ) {
			foreach ( $value as $_ => $v ) {
				$str .= assoc_args_to_str( array( $key => $v ) );
			}
		} else {
			$str .= " --$key=" . escapeshellarg( $value );
		}
	}

	return $str;
}

/**
 * Determine if proc is available
 *
 * @return bool
 */
function check_proc_available() {
	if ( ! function_exists( 'proc_open' ) || ! function_exists( 'proc_close' ) ) {
		return false;
	}

	return true;
}
