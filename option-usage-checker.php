<?php
/**
 * Plugin Name: Option Usage Checker
 * Description: Make sure that options are less than 1MB when adding and updating them. This is specifically intended for when using an external object cache, like Memcached, where 1MB is the max bucket size (e.g. this is the case on WordPress.com). This ensures compatibility with Memcached's 1MB limit. Also make sure that all options are explicitly added before they are updated. Define Option_Usage_Checker_OBJECT_CACHE_BUCKET_MAX_SIZE (number of bytes) in wp-config.php to override the default of 1MB. Option usage violations will throw exceptions if WP_DEBUG is turned on by default; otherwise PHP warnings will be issued.
 * Version: 0.1
 * Author: X-Team WP
 * Author URI: http://x-team.com/wordpress/
 * License: GPLv2+
 */

class Option_Usage_Checker_Plugin {

	/**
	 * @var Option_Usage_Checker_Plugin
	 */
	private static $_instance;

	/**
	 * @return Option_Usage_Checker_Plugin
	 */
	static function get_instance() {
		if ( empty( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Value goes through option_value_max_size filter when used. Set to
	 * OPTION_USAGE_CHECKER_OBJECT_CACHE_BUCKET_MAX_SIZE or 1MB. Value is in bytes.
	 *
	 * @var int
	 */
	public $default_option_value_max_size;

	/**
	 * Whether or not exceptions should be thrown. If not, then PHP warnings will
	 * be issued instead. Default value is whether WP_DEBUG is enabled.
	 * Can be filtered by option_usage_checker_throw_exceptions.
	 *
	 * @var bool
	 */
	public $default_throw_exceptions;

	/**
	 * Add hooks for plugin.
	 */
	protected function __construct() {
		if ( defined( 'OPTION_USAGE_CHECKER_OBJECT_CACHE_BUCKET_MAX_SIZE' ) ) {
			$this->default_option_value_max_size = OPTION_USAGE_CHECKER_OBJECT_CACHE_BUCKET_MAX_SIZE;
		} else {
			$this->default_option_value_max_size = pow( 2, 20 ); // 1MB for Memcached
		}

		$this->default_throw_exceptions = ( defined( 'WP_DEBUG' ) && WP_DEBUG );

		add_action( 'add_option', array( $this, 'action_add_option' ), 10, 2 );
		add_filter( 'pre_update_option', array( $this, 'filter_pre_update_option' ), 999, 2 );

		// @todo Add check if adding/updating autoloaded option would cause alloptions to be larger than 1MB
	}

	/**
	 * Callback for before an option is added.
	 *
	 * @param $name
	 * @param $value
	 */
	function action_add_option( $name, $value ) {
		$this->check_option_size( $name, $value );
	}

	/**
	 * Callback for before an option is updated.
	 *
	 * @param string $name
	 * @param mixed $value
	 * @throws Option_Usage_Checker_Plugin_Exception
	 * @return mixed
	 */
	function filter_pre_update_option( $value, $name ) {
		$this->check_option_size( $name, $value );
		if ( ! $this->option_exists( $name ) ) {
			$this->handle_error( "Option '$name' does not exist. You must call add_option() before you can call update_option().'" );
		}
		return $value;
	}

	/**
	 * @param string $name option name
	 * @param mixed $value pending option value
	 *
	 * @throws Option_Usage_Checker_Plugin_Exception if the serialized value is larger than the memcached bucket size
	 */
	function check_option_size( $name, $value ) {
		/**
		 * Max size for an option's value.
		 *
		 * @param int $option_value_max_size
		 */
		$option_value_max_size = apply_filters( 'option_value_max_size', $this->default_option_value_max_size );

		$option_size = strlen( maybe_serialize( $value ) );
		if ( $option_size > $option_value_max_size ) {
			$this->handle_error( "Attempted to set option '$name' which is too big ($option_size bytes). There is a $option_value_max_size byte limit." );
		}
	}

	/**
	 * Check whether an option has been previously added.
	 *
	 * @param string $option_name
	 * @return bool
	 */
	function option_exists( $option_name ) {
		$exists = false;
		$default_value = new stdClass();
		$existing_value = get_option( $option_name, $default_value );
		if ( $existing_value !== $default_value ) {
			$exists = true;
		}
		return $exists;
	}

	/**
	 * @param string $message
	 * @throws Option_Usage_Checker_Plugin_Exception
	 */
	function handle_error( $message ) {
		/**
		 * Throw exceptions up option usage errors.
		 *
		 * @param bool $throw_exceptions
		 */
		$throw_exception = apply_filters( 'option_usage_checker_throw_exceptions', $this->default_throw_exceptions );

		if ( $throw_exception ) {
			throw new Option_Usage_Checker_Plugin_Exception( $message );
		} else {
			trigger_error( $message, E_USER_WARNING );
		}
	}
};

class Option_Usage_Checker_Plugin_Exception extends Exception {}

add_action( 'muplugins_loaded', array( 'Option_Usage_Checker_Plugin', 'get_instance' ) );
