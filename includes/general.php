<?php
/**
 * @package Pods\Global\Functions\General
 */

use Pods\Admin\Settings;
use Pods\API\Whatsit\Value_Field;
use Pods\Whatsit;
use Pods\Whatsit\Field;
use Pods\Whatsit\Pod;
use Pods\Whatsit\Store;
use Pods\Permissions;
use Pods\Static_Cache;

/**
 * Standardize queries and error reporting. It replaces @wp_ with $wpdb->prefix.
 *
 * @see   PodsData::query
 *
 * @param string $sql              SQL Query
 * @param string $error            (optional) The failure message
 * @param string $results_error    (optional) Throw an error if a records are found
 * @param string $no_results_error (optional) Throw an error if no records are found
 *
 * @return array|bool|mixed|null|void
 * @since 2.0.0
 */
function pods_query( $sql, $error = 'Database Error', $results_error = null, $no_results_error = null ) {
	try {
		$podsdata = pods_data( null, null, true, false );
	} catch ( Exception $exception ) {
		return null;
	}

	$sql = apply_filters( 'pods_query_sql', $sql, $error, $results_error, $no_results_error );
	$sql = $podsdata->get_sql( $sql );

	if ( is_array( $error ) ) {
		if ( ! is_array( $sql ) ) {
			$sql = array( $sql, $error );
		}

		$error = 'Database Error';
	}

	if ( 1 === (int) pods_v( 'pods_debug_sql_all' ) && is_user_logged_in() && pods_is_admin( array( 'pods' ) ) ) {
		$debug_sql = $sql;

		echo '<textarea cols="100" rows="24">';

		if ( is_array( $debug_sql ) ) {
			$debug_sql = print_r( $debug_sql, true );
		}

		echo esc_textarea( $debug_sql );

		echo '</textarea>';
	}

	return PodsData::query( $sql, $error, $results_error, $no_results_error );
}

/**
 * Standardize filters / actions
 *
 * @param string $scope Scope of the filter / action (ui for PodsUI, api for PodsAPI, etc..)
 * @param string $name  Name of filter / action to run
 * @param mixed  $args  (optional) Arguments to send to filter / action
 * @param object $obj   (optional) Object to reference for filter / action
 *
 * @return mixed
 * @since 2.0.0
 * @todo  Need to figure out how to handle $scope = 'pods' for the Pods class
 */
function pods_do_hook( $scope, $name, $args = null, $obj = null ) {
	// Add filter name
	array_unshift( $args, "pods_{$scope}_{$name}" );

	// Add object
	$args[] = $obj;

	// Run apply_filters and give it all the arguments
	$args = call_user_func_array( 'apply_filters', $args );

	return $args;
}

/**
 * Message / Notice handling for Admin UI
 *
 * @param string $message The notice / error message shown
 * @param string $type    Message type
 *
 * @return void
 */
function pods_message( $message, $type = null ) {
	if ( empty( $type ) || ! in_array( $type, array( 'notice', 'error' ), true ) ) {
		$type = 'notice';
	}

	$class = '';

	if ( 'notice' === $type ) {
		$class = 'updated';
	} elseif ( 'error' === $type ) {
		$class = 'error';
	}

	echo '<div id="message" class="' . esc_attr( $class ) . ' fade"><p>' . $message . '</p></div>';
}

$GLOBALS['pods_errors'] = array();

/**
 * Error Handling which throws / displays errors
 *
 * @param string|array        $error The error message(s) to be thrown / displayed.
 * @param object|boolean|null $obj   If $obj->display_errors is set and is set to true it will display errors,
 *                                   if boolean and is set to true it will display errors.
 *
 * @return mixed
 *
 * @throws Exception Throws exception for developer-oriented error handling.
 *
 * @since 2.0.0
 */
function pods_error( $error, $obj = null ) {
	global $pods_errors;

	$display_errors = $obj;
	if ( is_object( $obj ) && isset( $obj->display_errors ) ) {
		$display_errors = $obj->display_errors;
	}

	$error_mode = 'exception';

	if ( true === $display_errors ) {
		$error_mode = 'exit';
	} elseif ( false === $display_errors ) {
		$error_mode = 'exception';
	} elseif ( is_string( $display_errors ) ) {
		$error_mode = $display_errors;
	}

	if ( is_object( $error ) && 'Exception' === get_class( $error ) ) {
		/** @var Exception $error */
		$error = $error->getMessage();

		$error_mode = 'exception';
	}

	/**
	 * @var string $error_mode Throw an exception, exit with the message, return false, or return WP_Error
	 */
	if ( ! in_array( $error_mode, array( 'exception', 'exit', 'false', 'wp_error', 'json' ), true ) ) {
		$error_mode = 'exception';
	}

	/**
	 * When running a Pods shortcode, never exit and only return exception.
	 */
	if ( pods_doing_shortcode() ) {
		$error_mode = 'exception';
	} elseif ( pods_doing_json() ) {
		$error_mode = 'json';
	}

	/**
	 * Filter the error mode used by pods_error.
	 *
	 * @param string                     $error_mode Error mode
	 * @param string|array               $error      Error message(s)
	 * @param object|boolean|string|null $obj
	 */
	$error_mode = apply_filters( 'pods_error_mode', $error_mode, $error, $obj );

	if ( is_array( $error ) ) {
		$error = array_map( 'wp_kses_post', $error );

		if ( 1 === count( $error ) ) {
			$error = current( $error );

			// Create WP_Error for use later.
			$wp_error = new WP_Error( 'pods-error-' . md5( $error ), $error );
		} else {
			// Create WP_Error for use later.
			$wp_error = new WP_Error();

			foreach ( $error as $error_message ) {
				$wp_error->add( 'pods-error-' . md5( $error_message ), $error_message );
			}

			if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
				$error = __( 'The following issue occurred:', 'pods' ) . "\n\n- " . implode( "\n- ", $error );
			} else {
				$error = __( 'The following issues occurred:', 'pods' ) . "\n<ul><li>" . implode( "</li>\n<li>", $error ) . '</li></ul>';
			}
		}
	} else {
		if ( is_object( $error ) ) {
			$error = __( 'An unknown error has occurred', 'pods' );
		}

		$error = wp_kses_post( $error );

		// Create WP_Error for use later.
		$wp_error = new WP_Error( 'pods-error-' . md5( $error ), $error );
	}//end if

	$last_error = $pods_errors;

	$pods_errors = array();

	if ( $last_error === $error && 'exception' === $error_mode ) {
		$error_mode = 'exit';
	}

	// Support testing debug messages.
	if ( function_exists( 'codecept_debug' ) ) {
		codecept_debug( 'Pods Debug Error: ' . $error );
	}

	if ( ! empty( $error ) ) {
		if ( 'exception' === $error_mode ) {
			$exception_bypass = apply_filters( 'pods_error_exception', null, $error );

			if ( null !== $exception_bypass ) {
				return $exception_bypass;
			}

			$pods_errors = $error;

			/**
			 * Allow filtering whether the fallback is enabled to catch uncaught exceptions.
			 *
			 * @since 2.8.0
			 *
			 * @param bool   $exception_fallback_enabled Whether the fallback is enabled to catch uncaught exceptions.
			 * @param string $error                      The error information.
			 */
			$exception_fallback_enabled = apply_filters( 'pods_error_exception_fallback_enabled', true, $error );

			if ( $exception_fallback_enabled ) {
				set_exception_handler( 'pods_error' );
			}

			throw new Exception( $error );
		} elseif ( 'exit' === $error_mode ) {
			$die_bypass = apply_filters( 'pods_error_die', null, $error );

			if ( null !== $die_bypass ) {
				return $die_bypass;
			}

			// die with error
			if ( ! defined( 'DOING_AJAX' ) && ! headers_sent() && ( is_admin() || false !== strpos( $_SERVER['REQUEST_URI'], 'wp-comments-post.php' ) ) ) {
				wp_die( $error, '', array( 'back_link' => true ) );
			} else {
				die( sprintf( '<e>%s</e>', $error ) );
			}
		} elseif ( 'wp_error' === $error_mode ) {
			return $wp_error;
		} elseif ( 'json' === $error_mode ) {
			$meta_box_loader_compat = (int) pods_v( 'meta-box-loader', 'request', 0 );

			// Check if this is a back-compat meta box save request.
			if ( 1 === $meta_box_loader_compat ) {
				// Do not block this page.
				error_log( 'Pods Meta Save Error:' . $error );
			} else {
				wp_send_json( [
					'message' => $error,
				], 500 );
			}
		}//end if
	}//end if

	return false;
}

/**
 * Debug variable used in pods_debug to count the instances debug is used
 */
global $pods_debug;
$pods_debug = 0;
/**
 * Debugging common issues using this function saves a few lines and is compatible with
 *
 * @param mixed   $debug The error message to be thrown / displayed
 * @param boolean $die   If set to true, a die() will occur, if set to (int) 2 then a wp_die() will occur
 * @param string  $prefix
 *
 * @return void
 *
 * @since 2.0.0
 */
function pods_debug( $debug = '_null', $die = false, $prefix = '_null' ) {
	global $pods_debug;

	$pods_debug ++;

	if ( function_exists( 'codecept_debug' ) ) {
		if ( ! is_string( $debug ) ) {
			$debug = var_export( $debug, true );
		}

		codecept_debug( 'Pods Debug: ' . $debug );

		return;
	}

	ob_start();

	if ( '_null' !== $prefix ) {
		var_dump( $prefix );
	}

	if ( '_null' !== $debug ) {
		var_dump( $debug );
	} else {
		var_dump( 'Pods Debug #' . $pods_debug );
	}

	$debug = ob_get_clean();

	if ( false === strpos( $debug, "<pre class='xdebug-var-dump'" ) ) {
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			$debug = esc_html( $debug );
		}

		$debug = '<pre>' . $debug . '</pre>';
	}

	$debug = '<e>' . $debug;

	if ( 2 === $die ) {
		wp_die( $debug, '', array( 'back_link' => true ) );
	} elseif ( true === $die ) {
		die( $debug );
	}

	echo $debug;
}

/**
 * Check if debug is enabled and should be displayed.
 *
 * @return bool
 * @since  2.7.13
 */
function pods_is_debug_display() {
	return ( WP_DEBUG && WP_DEBUG_DISPLAY );
}

/**
 * Determine if user has admin access
 *
 * @param string|array $cap Additional capabilities to check
 *
 * @return bool Whether user has admin access
 *
 * @since 2.3.5
 */
function pods_is_admin( $cap = null ) {
	if ( is_user_logged_in() ) {

		if ( is_multisite() && is_super_admin() ) {
			return apply_filters( 'pods_is_admin', true, $cap, '_super_admin' );
		}

		$pods_admin_capabilities = array();

		if ( ! is_multisite() ) {
			// Default is_super_admin() checks against this capability.
			$pods_admin_capabilities[] = 'delete_users';
		}

		$pods_admin_capabilities = apply_filters( 'pods_admin_capabilities', $pods_admin_capabilities, $cap );

		if ( empty( $cap ) ) {
			$cap = array();
		} else {
			$cap = (array) $cap;
		}

		$cap = array_unique( array_filter( array_merge( $pods_admin_capabilities, $cap ) ) );

		foreach ( $cap as $capability ) {
			if ( current_user_can( $capability ) ) {
				return apply_filters( 'pods_is_admin', true, $cap, $capability );
			}
		}
	}//end if

	return apply_filters( 'pods_is_admin', false, $cap, null );
}

/**
 * Determine if Developer Mode is enabled
 *
 * @return bool Whether Developer Mode is enabled
 *
 * @since 2.3.0
 */
function pods_developer() {
	if ( defined( 'PODS_DEVELOPER' ) && PODS_DEVELOPER ) {
		return true;
	}

	return false;
}

/**
 * Determine if Tableless Mode is enabled
 *
 * @return bool Whether Tableless Mode is enabled
 *
 * @since 2.3.0
 */
function pods_tableless() {
	if ( defined( 'PODS_TABLELESS' ) && PODS_TABLELESS ) {
		return true;
	}

	return false;
}

/**
 * Determine whether the wp_podsrel table is enabled.
 *
 * @since 2.8.0
 *
 * @return bool Whether the wp_podsrel table is enabled.
 */
function pods_podsrel_enabled() {
	// Disabled when Pods Tableless mode is on.
	if ( pods_tableless() ) {
		return false;
	}

	/**
	 * Allow filtering of whether the wp_podsrel table is enabled.
	 *
	 * @since 2.8.0
	 *
	 * @param bool $enabled Whether the wp_podsrel table is enabled.
	 */
	return apply_filters( 'pods_podsrel_enabled', true );
}

/**
 * Determine whether relationship meta storage is enabled.
 *
 * @since 2.8.0
 *
 * @param null|array|Field $field The field object.
 * @param null|array|Pod   $pod   The pod object.
 *
 * @return bool Whether relationship meta storage is enabled.
 */
function pods_relationship_meta_storage_enabled( $field = null, $pod = null ) {
	/**
	 * Allow filtering of whether relationship meta storage is enabled.
	 *
	 * @since 2.8.0
	 *
	 * @param bool             $enabled Whether relationship meta storage table is enabled.
	 * @param null|array|Field $field   The field object.
	 * @param null|array|Pod   $pod     The pod object.
	 */
	return apply_filters( 'pods_relationship_meta_storage_enabled', true, $field, $pod );
}

/**
 * Determine if Light Mode is enabled
 *
 * @return bool Whether Light Mode is enabled
 *
 * @since 2.7.13
 */
function pods_light() {
	if ( defined( 'PODS_LIGHT' ) && PODS_LIGHT ) {
		return true;
	}

	return false;
}

/**
 * Determine if Strict Mode is enabled
 *
 * @param bool $include_debug Whether to include WP_DEBUG in strictness level
 *
 * @return bool Whether Strict Mode is enabled
 *
 * @since 2.3.5
 */
function pods_strict( $include_debug = true ) {
	$strict = false;

	if ( defined( 'PODS_STRICT' ) && PODS_STRICT ) {
		// Deprecated PODS_STRICT_MODE since 2.3.5
		$strict = true;
	} elseif ( defined( 'PODS_STRICT_MODE' ) && PODS_STRICT_MODE && pods_allow_deprecated( false ) ) {
		$strict = true;
	} elseif ( $include_debug && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$strict = true;
	}

	/**
	 * Allow filtering of whether strict mode is enabled.
	 *
	 * @param boolean $strict Whether strict mode is enabled.
	 *
	 * @since 2.8.0
	 */
	return apply_filters( 'pods_strict_mode', $strict );
}

/**
 * Determine if Deprecated Mode is enabled
 *
 * @param bool $include_debug Whether to include strict mode
 *
 * @return bool Whether Deprecated Mode is enabled
 *
 * @since 2.3.10
 */
function pods_allow_deprecated( $strict = true ) {
	if ( $strict && pods_strict( false ) ) {
		return false;
	} elseif ( ! defined( 'PODS_DEPRECATED' ) || PODS_DEPRECATED ) {
		return true;
	}

	return false;
}

/**
 * Determine if Pods API Caching is enabled
 *
 * @return bool Whether Pods API Caching is enabled
 *
 * @since 2.3.9
 */
function pods_api_cache() {
	if ( defined( 'PODS_API_CACHE' ) && ! PODS_API_CACHE ) {
		return false;
	}

	/**
	 * Filter whether to use the Pods API cache.
	 *
	 * @param boolean $use_cache Whether to use the Pods API cache.
	 *
	 * @since 2.8.0
	 */
	return apply_filters( 'pods_api_cache', true );
}

/**
 * Determine if Pods shortcodes can evaluate magic tags.
 *
 * @since 2.7.16
 *
 * @return bool
 */
function pods_shortcode_allow_evaluate_tags() {
	if ( defined( 'PODS_SHORTCODE_ALLOW_EVALUATE_TAGS' ) && PODS_SHORTCODE_ALLOW_EVALUATE_TAGS ) {
		return true;
	}

	return false;
}

/**
 * Marks a function as deprecated and informs when it has been used.
 *
 * There is a hook deprecated_function_run that will be called that can be used
 * to get the backtrace up to what file and function called the deprecated
 * function.
 *
 * The current behavior is to trigger a user error if WP_DEBUG is true.
 *
 * This function is to be used in every function that is deprecated.
 *
 * @uses  do_action() Calls 'deprecated_function_run' and passes the function name, what to use instead,
 *   and the version the function was deprecated in.
 * @uses  apply_filters() Calls 'deprecated_function_trigger_error' and expects boolean value of true to do
 *   trigger or false to not trigger error.
 *
 * @param string $function    The function that was called
 * @param string $version     The version of WordPress that deprecated the function
 * @param string $replacement Optional. The function that should have been called
 *
 * @since 2.0.0
 */
function pods_deprecated( $function, $version, $replacement = null ) {
	if ( ! version_compare( $version, PODS_VERSION, '<=' ) && ! version_compare( $version . '-a-0', PODS_VERSION, '<=' ) ) {
		return;
	}

	do_action( 'deprecated_function_run', $function, $replacement, $version );

	// Allow plugin to filter the output error trigger
	if ( WP_DEBUG && apply_filters( 'deprecated_function_trigger_error', true ) ) {
		if ( ! is_null( $replacement ) ) {
			$error = __( '%1$s has been <strong>deprecated</strong> since Pods version %2$s! Use %3$s instead.', 'pods' );
		} else {
			$error = __( '%1$s has been <strong>deprecated</strong> since Pods version %2$s with no alternative available.', 'pods' );
		}

		trigger_error( sprintf( $error, $function, $version, $replacement ), E_USER_DEPRECATED );
	}
}

/**
 * Inline help
 *
 * @param string $text Help text
 * @param string $url  Documentation URL
 *
 * @return void
 *
 * @since 2.0.0
 */
function pods_help( $text, $url = null ) {
	if ( ! wp_script_is( 'jquery-qtip2', 'registered' ) ) {
		wp_register_script( 'jquery-qtip2', PODS_URL . 'ui/js/qtip/jquery.qtip.min.js', array( 'jquery' ), '3.0.3' );
	} elseif ( ! wp_script_is( 'jquery-qtip2', 'queue' ) && ! wp_script_is( 'jquery-qtip2', 'to_do' ) && ! wp_script_is( 'jquery-qtip2', 'done' ) ) {
		wp_enqueue_script( 'jquery-qtip2' );
	}

	if ( ! wp_script_is( 'pods-qtip-init', 'registered' ) ) {
		wp_register_script( 'pods-qtip-init', PODS_URL . 'ui/js/qtip.js', array(
			'jquery',
			'jquery-qtip2',
		), PODS_VERSION );
		pods_form_enqueue_script( 'pods-qtip-init' );
	} elseif ( ! wp_script_is( 'pods-qtip-init', 'queue' ) && ! wp_script_is( 'pods-qtip-init', 'to_do' ) && ! wp_script_is( 'pods-qtip-init', 'done' ) ) {
		pods_form_enqueue_script( 'pods-qtip-init' );
	}

	if ( is_array( $text ) ) {
		if ( isset( $text[1] ) ) {
			$url = $text[1];
		}

		$text = $text[0];
	}

	if ( 'help' === $text ) {
		return;
	}

	if ( 0 < strlen( $url ) ) {
		$text .= '<br /><br /><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Find out more', 'pods' ) . ' &raquo;</a>';
	}

	echo '<img src="' . esc_url( PODS_URL ) . 'ui/images/help.png" alt="' . esc_attr( $text ) . '" class="pods-icon pods-qtip" />';
}

/**
 * Check whether something is a specific version minimum and/or maximum
 *
 * @param string $minimum_version Minimum version
 * @param string $comparison      Comparison operator
 * @param string $maximum_version Maximum version
 *
 * @return bool
 */
function pods_version_check( $what, $minimum_version, $comparison = '<=', $maximum_version = null ) {
	global $wp_version, $wpdb;

	if ( 'php' === $what ) {
		$version = phpversion();
	} elseif ( 'mysql' === $what ) {
		$version = $wpdb->db_version();
	} else {
		$version = $wp_version;
	}

	if ( ! empty( $minimum_version ) && ! version_compare( $minimum_version, $version, $comparison ) ) {
		return false;
	}

	if ( ! empty( $maximum_version ) && ! version_compare( $version, $maximum_version, $comparison ) ) {
		return false;
	}

	return true;
}

/**
 * Run a Pods Helper
 *
 * @param string $helper_name Helper Name
 * @param string $value       Value to run Helper on
 * @param string $name        Field name.
 *
 * @return bool
 * @since 1.7.5
 */
function pods_helper( $helper_name, $value = null, $name = null ) {
	return pods()->helper( $helper_name, $value, $name );
}

/**
 * Get the current hostname.
 *
 * @since 2.8.0
 *
 * @return string The current hostname.
 */
function pods_current_host() {
	if ( empty( $_SERVER['HTTP_HOST'] ) ) {
		$host = wp_parse_url( get_site_url(), PHP_URL_HOST );

		if ( empty( $host ) ) {
			return 'localhost';
		}

		return $host;
	}

	return $_SERVER['HTTP_HOST'];
}

/**
 * Get the full path of the current page.
 *
 * @since 2.8.0
 *
 * @return string Full path of the current page.
 */
function pods_current_path() {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return '/';
	}

	return $_SERVER['REQUEST_URI'];
}

/**
 * Get the full URL of the current page.
 *
 * @since 2.3.0
 *
 * @return string Full URL of the current page.
 */
function pods_current_url() {
	$url = 'http';

	if ( isset( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] && 0 !== $_SERVER['HTTPS'] ) {
		$url = 'https';
	}

	$url .= '://' . pods_current_host() . pods_current_path();

	return apply_filters( 'pods_current_url', $url );
}

/**
 * Find out if the current page has a valid $pods
 *
 * @param object $object The Pod Object currently checking (optional)
 *
 * @return bool
 * @since 2.0.0
 */
function is_pod( $object = null ) {
	global $pods, $post;

	if ( is_object( $object ) && isset( $object->pod ) && ! empty( $object->pod ) ) {
		return true;
	} elseif ( is_object( $pods ) && isset( $pods->pod ) && ! empty( $pods->pod ) ) {
		return true;
	} elseif ( is_object( $post ) && isset( $post->post_type ) && pods_api()->pod_exists( $post->post_type, 'post_type' ) ) {
		return true;
	}

	return false;
}

/**
 * See if the current user has a certain privilege
 *
 * @param mixed  $privs  The privilege name or names (array if multiple)
 * @param string $method The access method ("AND", "OR")
 *
 * @return bool
 * @since 1.2.0
 */
function pods_access( $privs, $method = 'OR' ) {
	// Convert $privs to an array
	$privs = (array) $privs;

	// Convert $method to uppercase
	$method = strtoupper( $method );

	$check = apply_filters( 'pods_access', null, $privs, $method );
	if ( null !== $check && is_bool( $check ) ) {
		return $check;
	}

	if ( ! is_user_logged_in() ) {
		return false;
	}

	if ( pods_is_admin( array( 'pods', 'pods_content' ) ) ) {
		return true;
	}

	// Store approved privs when using "AND"
	$approved_privs = array();

	// Loop through the user's roles
	foreach ( $privs as $priv ) {
		if ( 0 === strpos( $priv, 'pod_' ) ) {
			$priv = pods_str_replace( 'pod_', 'pods_edit_', $priv, 1 );
		}

		if ( 0 === strpos( $priv, 'manage_' ) ) {
			$priv = pods_str_replace( 'manage_', 'pods_', $priv, 1 );
		}

		if ( current_user_can( $priv ) ) {
			if ( 'OR' === $method ) {
				return true;
			}

			$approved_privs[ $priv ] = true;
		}
	}
	if ( 'AND' === strtoupper( $method ) ) {
		foreach ( $privs as $priv ) {
			if ( 0 === strpos( $priv, 'pod_' ) ) {
				$priv = pods_str_replace( 'pod_', 'pods_edit_', $priv, 1 );
			}

			if ( 0 === strpos( $priv, 'manage_' ) ) {
				$priv = pods_str_replace( 'manage_', 'pods_', $priv, 1 );
			}

			if ( ! isset( $approved_privs[ $priv ] ) ) {
				return false;
			}
		}

		return true;
	}

	return false;
}

/**
 * Check whether a Pods shortcode is currently being parsed.
 * If a boolean is passed it overwrites the status.
 *
 * @param bool $bool
 *
 * @return bool
 * @since  2.7.13
 */
function pods_doing_shortcode( $bool = null ) {
	static $check = false;
	if ( null !== $bool ) {
		$check = (bool) $bool;
	}
	return $check;
}

/**
 * Check whether we are currently in a JSON request.
 *
 * @since  2.8.0
 *
 * @return bool Whether we are in a REST API or JSON request.
 */
function pods_doing_json() {
	// Check whether we are doing a REST API request.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}

	// Return whether we are doing a JSON request.
	return wp_is_json_request();
}

/**
 * Shortcode support for use anywhere that support WP Shortcodes.
 * Will return error message on failure.
 *
 * @since 1.6.7
 * @since 2.7.13 Try/Catch.
 *
 * @param array  $tags    An associative array of shortcode properties.
 * @param string $content A string that represents a template override.
 *
 * @return string
 */
function pods_shortcode( $tags, $content = null ) {
	pods_doing_shortcode( true );

	try {
		$return = pods_shortcode_run( $tags, $content );
	} catch ( Exception $exception ) {
		$return = $exception->getMessage();

		if ( ! pods_is_debug_display() ) {
			// Logs message.
			pods_debug( $return );
			$return = '';
		}
	}

	pods_doing_shortcode( false );

	return $return;
}

/**
 * Shortcode support for use anywhere that support WP Shortcodes.
 *
 * @since 2.7.13
 *
 * @param array  $tags    An associative array of shortcode properties.
 * @param string $content A string that represents a template override.
 *
 * @return string
 */
function pods_shortcode_run( $tags, $content = null ) {
	if ( defined( 'PODS_DISABLE_SHORTCODE' ) && PODS_DISABLE_SHORTCODE ) {
		return '';
	}

	// For enforcing pagination parameters when not displaying pagination
	$page   = 1;
	$offset = 0;

	if ( isset( $tags['page'] ) ) {
		$page = (int) $tags['page'];
		$page = max( $page, 1 );
	}

	if ( isset( $tags['offset'] ) ) {
		$offset = (int) $tags['offset'];
		$offset = max( $offset, 0 );
	}

	// Query related tags separated to use later.
	$default_query_tags = array(
		'use_current'         => false,
		'name'                => null,
		'id'                  => null,
		'slug'                => null,
		'select'              => null,
		'join'                => null,
		'order'               => null,
		'orderby'             => null,
		'limit'               => null,
		'where'               => null,
		'having'              => null,
		'groupby'             => null,
		'search'              => true,
		'pagination'          => false,
		'page'                => null,
		'offset'              => null,
		'filters_enable'      => null,
		'filters'             => '',
		'filters_label'       => null,
		'filters_location'    => 'before',
		'pagination_label'    => null,
		'pagination_type'     => null,
		'pagination_location' => 'after',
	);

	$default_other_tags = [
		'blog_id'          => null,
		'field'            => null,
		'col'              => null,
		'template'         => null,
		'pods_page'        => null,
		'helper'           => null,
		'form'             => null,
		'form_output_type' => 'div',
		'fields'           => null,
		'label'            => null,
		'thank_you'        => null,
		'not_found'        => null,
		'view'             => null,
		'cache_mode'       => 'none',
		'expires'          => 0,
		'shortcodes'       => false,
	];

	$defaults = array_merge( $default_other_tags, $default_query_tags );

	if ( ! empty( $tags ) ) {
		$tags = array_merge( $defaults, $tags );
	} else {
		$tags = $defaults;
	}

	$tags = apply_filters( 'pods_shortcode', $tags );

	$tags['pagination']  = filter_var( $tags['pagination'], FILTER_VALIDATE_BOOLEAN );
	$tags['search']      = filter_var( $tags['search'], FILTER_VALIDATE_BOOLEAN );
	$tags['use_current'] = filter_var( $tags['use_current'], FILTER_VALIDATE_BOOLEAN );

	if ( empty( $content ) ) {
		$content = null;
	}

	// Allow views only if not targeting a file path (must be within theme)
	if ( 0 < strlen( $tags['view'] ) ) {
		$return = '';

		if ( ! file_exists( $tags['view'] ) ) {
			$return = pods_view( $tags['view'], null, (int) $tags['expires'], $tags['cache_mode'], true );

			if ( $tags['shortcodes'] && defined( 'PODS_SHORTCODE_ALLOW_SUB_SHORTCODES' ) && PODS_SHORTCODE_ALLOW_SUB_SHORTCODES ) {
				$return = do_shortcode( $return );
			}
		}

		/**
		 * Allow customization of shortcode output based on shortcode attributes.
		 *
		 * @since 2.7.9
		 *
		 * @param string    $return  Shortcode output to return.
		 * @param array     $tags    Shortcode attributes.
		 * @param null|Pods $pod     Pods object, or null if 'view' context.
		 * @param string    $context The shortcode context (form, field, pods-page, view, or list).
		 */
		return apply_filters( 'pods_shortcode_output', $return, $tags, null, 'view' );
	}

	$blog_is_switched = false;

	if ( defined( 'PODS_SHORTCODE_ALLOW_BLOG_SWITCHING' ) && PODS_SHORTCODE_ALLOW_BLOG_SWITCHING && is_multisite() ) {
		if ( ! empty( $tags['blog_id'] ) && is_numeric( $tags['blog_id'] ) && (int) get_current_blog_id() !== (int) $tags['blog_id'] ) {
			switch_to_blog( (int) $tags['blog_id'] );

			$blog_is_switched = true;
		}
	}

	if ( ! $tags['use_current'] && empty( $tags['name'] ) ) {
		$has_query_tags = array_intersect_key( array_diff( $tags, $defaults ), $default_query_tags );

		// Only allow revert to current object if there are no query tags.
		if ( ! $has_query_tags ) {
			/**
			 * Allow filtering whether to detect the pod name / item ID from the current post object.
			 *
			 * @since 2.7.26
			 *
			 * @param bool  $detect_from_current  Whether to detect the pod name / item ID from the current post object.
			 * @param array $shortcode_attributes The list of attributes used for the shortcode.
			 */
			$detect_from_current = apply_filters( 'pods_shortcode_detect_from_current_post', in_the_loop(), $tags );

			// Archives, Post type archives, singular posts.
			if ( $detect_from_current ) {
				$pod = pods( get_post_type(), get_the_ID(), false );

				if ( ! empty( $pod ) ) {
					$id           = get_the_ID();
					$tags['id']   = $id;
					$tags['name'] = get_post_type();
				}
			} else {
				$tags['use_current'] = true;
			}
		}

		if ( ! $tags['use_current'] && empty( $tags['name'] ) ) {
			if ( $blog_is_switched ) {
				restore_current_blog();
			}

			return '<p>' . esc_html__( 'Pods embed error: Please provide a Pod name', 'pods' ) . '</p>';
		}
	}

	if ( ! empty( $tags['col'] ) ) {
		$tags['field'] = $tags['col'];

		unset( $tags['col'] );
	}

	if ( ! empty( $tags['order'] ) ) {
		$tags['orderby'] = $tags['order'];

		unset( $tags['order'] );
	}

	if ( empty( $content ) && empty( $tags['pods_page'] ) && empty( $tags['template'] ) && empty( $tags['field'] ) && empty( $tags['form'] ) ) {
		if ( $blog_is_switched ) {
			restore_current_blog();
		}

		return '<p>' . esc_html__( 'Pods embed error: Please provide either a template or field name', 'pods' ) . '</p>';
	}

	if ( ! $tags['use_current'] && ! isset( $id ) ) {
		// id > slug (if both exist)
		$id = null;

		$evaluate_tags_args = array(
			'sanitize'        => true,
			'fallback'        => null,
			'use_current_pod' => true,
		);

		if ( ! empty( $tags['slug'] ) ) {
			$id = $tags['slug'];

			if ( pods_shortcode_allow_evaluate_tags() ) {
				$id = pods_evaluate_tags( $id, $evaluate_tags_args );
			}
		}

		if ( ! empty( $tags['id'] ) ) {
			$id = $tags['id'];

			if ( pods_shortcode_allow_evaluate_tags() ) {
				$id = pods_evaluate_tags( $id, $evaluate_tags_args );
			}

			if ( is_numeric( $id ) ) {
				$id = absint( $id );
			}
		}
	}//end if

	if ( ! isset( $pod ) ) {
		if ( ! $tags['use_current'] ) {
			$pod = pods( $tags['name'], $id );
		} else {
			$pod = pods();
			$id  = $pod->id();
		}
	}

	if ( empty( $pod ) || ! $pod->valid() ) {
		if ( $blog_is_switched ) {
			restore_current_blog();
		}

		return '<p>' . esc_html__( 'Pods embed error: Pod not found', 'pods' ) . '</p>';
	}

	$found = 0;
	$filters = false;

	$is_singular = ( ! empty( $id ) || $tags['use_current'] );

	if ( ! $is_singular ) {
		$params = array();

		if ( ! defined( 'PODS_DISABLE_SHORTCODE_SQL' ) || ! PODS_DISABLE_SHORTCODE_SQL ) {
			$evaluate_tags_args = array(
				'sanitize'        => true,
				'fallback'        => '""',
				'use_current_pod' => true,
			);

			if ( 0 < strlen( $tags['orderby'] ) ) {
				$params['orderby'] = $tags['orderby'];
			}

			if ( 0 < strlen( $tags['where'] ) ) {
				$params['where'] = $tags['where'];

				if ( pods_shortcode_allow_evaluate_tags() ) {
					$params['where'] = pods_evaluate_tags_sql( html_entity_decode( $params['where'] ), $evaluate_tags_args );
				}
			}

			if ( 0 < strlen( $tags['having'] ) ) {
				$params['having'] = $tags['having'];

				if ( pods_shortcode_allow_evaluate_tags() ) {
					$params['having'] = pods_evaluate_tags_sql( html_entity_decode( $params['having'] ), $evaluate_tags_args );
				}
			}

			if ( 0 < strlen( $tags['groupby'] ) ) {
				$params['groupby'] = $tags['groupby'];
			}

			if ( 0 < strlen( $tags['select'] ) ) {
				$params['select'] = $tags['select'];
			}
			if ( 0 < strlen( $tags['join'] ) ) {
				$params['join'] = $tags['join'];
			}
		}//end if

		// Load filters and return HTML for later use.
		if (
			true === (bool) $tags['filters_enable']
			|| (
				! empty( $tags['filters'] )
				&& null === $tags['filters_enable']
			)
		) {
			$filters_params = [
				'fields' => (string) $tags['filters'],
				'label'  => (string) $tags['filters_label'],
			];

			$filters = $pod->filters( $filters_params );
		}

		// Forms require params set
		if ( ! empty( $params ) || empty( $tags['form'] ) ) {
			if ( ! empty( $tags['limit'] ) ) {
				$params['limit'] = (int) $tags['limit'];
			}

			$params['search'] = $tags['search'];

			$params['pagination'] = $tags['pagination'];

			// If we aren't displaying pagination, we need to enforce page/offset
			if ( ! $params['pagination'] ) {
				$params['page']   = $page;
				$params['offset'] = $offset;

				// Force pagination on, we need it and we're enforcing page/offset
				$params['pagination'] = true;
			} else {
				// If we are displaying pagination, allow page/offset override only if *set*
				if ( isset( $tags['page'] ) ) {
					$params['page'] = (int) $tags['page'];
					$params['page'] = max( $params['page'], 1 );
				}

				if ( isset( $tags['offset'] ) ) {
					$params['offset'] = (int) $tags['offset'];
					$params['offset'] = max( $params['offset'], 0 );
				}
			}

			if ( ! empty( $tags['cache_mode'] ) && 'none' !== $tags['cache_mode'] ) {
				$params['cache_mode'] = $tags['cache_mode'];
				$params['expires']    = (int) $tags['expires'];
			}

			$params = apply_filters( 'pods_shortcode_findrecords_params', $params, $pod, $tags );

			$pod->find( $params );

			$found = $pod->total_found();
		}//end if
	}//end if

	// Handle form output.
	if ( ! empty( $tags['form'] ) ) {
		if ( 'user' === $pod->pod ) {
			if ( false !== strpos( $tags['fields'], '_capabilities' ) || false !== strpos( $tags['fields'], '_user_level' ) ) {
				if ( $blog_is_switched ) {
					restore_current_blog();
				}

				// Further hardening of User-based forms
				return '';
			} elseif ( $is_singular && ( ! defined( 'PODS_SHORTCODE_ALLOW_USER_EDIT' ) || ! PODS_SHORTCODE_ALLOW_USER_EDIT ) ) {
				if ( $blog_is_switched ) {
					restore_current_blog();
				}

				// Only explicitly allow user edit forms
				return '';
			}
		}

		$form_params = [
			'fields'      => $tags['fields'],
			'label'       => $tags['label'],
			'thank_you'   => $tags['thank_you'],
			'output_type' => ! empty( $tags['form_output_type'] ) ? $tags['form_output_type'] : 'div',
		];

		$return = $pod->form( $form_params );

		/**
		 * Allow customization of shortcode output based on shortcode attributes.
		 *
		 * @since 2.7.9
		 *
		 * @param string    $return  Shortcode output to return.
		 * @param array     $tags    Shortcode attributes.
		 * @param null|Pods $pod     Pods object, or null if 'view' context.
		 * @param string    $context The shortcode context (form, field, pods-page, view, or list).
		 */
		return apply_filters( 'pods_shortcode_output', $return, $tags, $pod, 'form' );
	}

	// Handle field output.
	if ( ! empty( $tags['field'] ) ) {
		if ( $tags['template'] || $content ) {
			$return  = '';
			$related = $pod->field( $tags['field'], array( 'output' => 'find' ) );

			if ( $related instanceof Pods && $related->valid() ) {
				// Content is null by default.
				$return .= $related->template( $tags['template'], $content );
			}
		} elseif ( empty( $tags['helper'] ) ) {
			$return = $pod->display( $tags['field'] );
		} else {
			$return = $pod->helper( $tags['helper'], $pod->field( $tags['field'] ), $tags['field'] );
		}

		// @todo $blog_is_switched >> Switch back before running other shortcodes?
		if ( $tags['shortcodes'] && defined( 'PODS_SHORTCODE_ALLOW_SUB_SHORTCODES' ) && PODS_SHORTCODE_ALLOW_SUB_SHORTCODES ) {
			$return = do_shortcode( $return );
		}

		if ( $blog_is_switched ) {
			restore_current_blog();
		}

		/**
		 * Allow customization of shortcode output based on shortcode attributes.
		 *
		 * @since 2.7.9
		 *
		 * @param string    $return  Shortcode output to return.
		 * @param array     $tags    Shortcode attributes.
		 * @param null|Pods $pod     Pods object, or null if 'view' context.
		 * @param string    $context The shortcode context (form, field, pods-page, view, or list).
		 */
		return apply_filters( 'pods_shortcode_output', $return, $tags, $pod, 'field' );
	}

	// Handle Pods Page output.
	if ( ! empty( $tags['pods_page'] ) && class_exists( 'Pods_Pages' ) ) {
		$pods_page = Pods_Pages::exists( $tags['pods_page'] );

		if ( empty( $pods_page ) ) {
			if ( $blog_is_switched ) {
				restore_current_blog();
			}

			return '<p>' . esc_html__( 'Pods embed error: Pods Page not found.', 'pods' ) . '</p>';
		}

		$return = Pods_Pages::content( true, $pods_page );

		// @todo $blog_is_switched >> Switch back before running other shortcodes?
		if ( $tags['shortcodes'] && defined( 'PODS_SHORTCODE_ALLOW_SUB_SHORTCODES' ) && PODS_SHORTCODE_ALLOW_SUB_SHORTCODES ) {
			$return = do_shortcode( $return );
		}

		if ( $blog_is_switched ) {
			restore_current_blog();
		}

		/**
		 * Allow customization of shortcode output based on shortcode attributes.
		 *
		 * @since 2.7.9
		 *
		 * @param string    $return  Shortcode output to return.
		 * @param array     $tags    Shortcode attributes.
		 * @param null|Pods $pod     Pods object, or null if 'view' context.
		 * @param string    $context The shortcode context (form, field, pods-page, view, or list).
		 */
		return apply_filters( 'pods_shortcode_output', $return, $tags, $pod, 'pods-page' );
	}//end if

	$pagination = false;

	// Only handle pagination on non-singular shortcodes where items were found.
	if (
		! $is_singular
		&& 0 < $found
		&& (
			empty( $params['limit'] )
			|| (
				0 < $params['limit']
				&& $params['limit'] < $found
			)
		)
		&& true === $tags['pagination']
	) {
		$pagination_params = array(
			'label' => pods_v( 'pagination_label', $tags, null ),
			'type'  => pods_v( 'pagination_type', $tags, null ),
		);

		// Remove empty params.
		$pagination_params = array_filter( $pagination_params );

		$pagination = $pod->pagination( $pagination_params );
	}

	ob_start();

	if ( $filters && 'before' === $tags['filters_location'] ) {
		// phpcs:ignore
		echo $filters;
	}

	if ( $pagination && in_array( $tags['pagination_location'], [ 'before', 'both' ], true ) ) {
		// phpcs:ignore
		echo $pagination;
	}

	$content = $pod->template( $tags['template'], $content );

	if ( '' === trim( $content ) && ! empty( $tags['not_found'] ) ) {
		$content = $pod->do_magic_tags( $tags['not_found'] );
	}

	// phpcs:ignore
	echo $content;

	if ( $pagination && in_array( $tags['pagination_location'], [ 'after', 'both' ], true ) ) {
		// phpcs:ignore
		echo $pagination;
	}

	if ( $filters && 'after' === $tags['filters_location'] ) {
		// phpcs:ignore
		echo $filters;
	}

	$return = ob_get_clean();

	if ( $tags['shortcodes'] && defined( 'PODS_SHORTCODE_ALLOW_SUB_SHORTCODES' ) && PODS_SHORTCODE_ALLOW_SUB_SHORTCODES ) {
		$return = do_shortcode( $return );
	}

	if ( $blog_is_switched ) {
		restore_current_blog();
	}

	/**
	 * Allow customization of shortcode output based on shortcode attributes.
	 *
	 * @since 2.7.9
	 *
	 * @param string    $return  Shortcode output to return.
	 * @param array     $tags    Shortcode attributes.
	 * @param null|Pods $pod     Pods object, or null if 'view' context.
	 * @param string    $context The shortcode context (form, field, pods-page, view, or list).
	 */
	return apply_filters( 'pods_shortcode_output', $return, $tags, $pod, 'list' );
}

/**
 * Form Shortcode support for use anywhere that support WP Shortcodes.
 *
 * @param array  $tags    An associative array of shortcode properties.
 * @param string $content Not currently used.
 *
 * @return string
 * @since 2.3.0
 */
function pods_shortcode_form( $tags, $content = null ) {
	$tags['form'] = 1;

	return pods_shortcode( $tags, $content );
}

/**
 * Fork of WordPress do_shortcode that allows specifying which shortcodes are ran.
 *
 * Search content for shortcodes and filter shortcodes through their hooks.
 *
 * If there are no shortcode tags defined, then the content will be returned
 * without any filtering. This might cause issues when plugins are disabled but
 * the shortcode will still show up in the post or content.
 *
 * @since 2.4.3
 *
 * @uses  $shortcode_tags
 * @uses  get_shortcode_regex() Gets the search pattern for searching shortcodes.
 *
 * @param string $content            Content to search for shortcodes.
 * @param array  $shortcodes         Array of shortcodes to run.
 * @param array  $ignored_shortcodes Array of shortcodes to ignore.
 *
 * @return string Content with shortcodes filtered out.
 */
function pods_do_shortcode( $content, $shortcodes = [], $ignored_shortcodes = [] ) {
	global $shortcode_tags;

	// No shortcodes in content
	if ( false === strpos( $content, '[' ) ) {
		return $content;
	}

	// No shortcodes registered
	if ( empty( $shortcode_tags ) || ! is_array( $shortcode_tags ) ) {
		return $content;
	}

	$only_shortcodes   = ! empty( $shortcodes );
	$ignore_shortcodes = ! empty( $ignored_shortcodes );

	if ( $only_shortcodes || $ignore_shortcodes ) {
		$temp_shortcode_filter = static function ( $return, $tag, $attr, $m ) use ( $only_shortcodes, $shortcodes, $ignore_shortcodes, $ignored_shortcodes ) {
			if ( $only_shortcodes && in_array( $m[2], $shortcodes, true ) ) {
				// If shortcode being called is in list, return false to allow it to run.
				return false;
			}

			if ( $ignore_shortcodes && ! in_array( $m[2], $ignored_shortcodes, true ) ) {
				// If shortcode being called is not in ignore list, return false to allow it to run.
				return false;
			}

			// Return original shortcode string if we aren't going to handle at this time
			return $m[0];
		};

		add_filter( 'pre_do_shortcode_tag', $temp_shortcode_filter, 10, 4 );
	}

	// Build Shortcode regex pattern just for the shortcodes we want
	$pattern = get_shortcode_regex();

	// Call shortcode callbacks just for the shortcodes we want
	$content = preg_replace_callback( "/$pattern/s", 'do_shortcode_tag', $content );

	if ( isset( $temp_shortcode_filter ) ) {
		remove_filter( 'pre_do_shortcode_tag', $temp_shortcode_filter );
	}

	return $content;
}

/**
 * Check if Pods is compatible with WP / PHP / MySQL or not
 *
 * @return bool
 *
 * @since 1.10
 */
function pods_compatibility_check() {
	$compatible = true;

	if ( ! pods_version_check( 'wp', PODS_WP_VERSION_MINIMUM ) ) {
		$compatible = false;

		add_action( 'admin_notices', 'pods_version_notice_wp' );
	}

	if ( ! pods_version_check( 'php', PODS_PHP_VERSION_MINIMUM ) ) {
		$compatible = false;

		add_action( 'admin_notices', 'pods_version_notice_php' );
	}

	if ( ! pods_version_check( 'mysql', PODS_MYSQL_VERSION_MINIMUM ) ) {
		$compatible = false;

		add_action( 'admin_notices', 'pods_version_notice_mysql' );
	}

	return $compatible;
}

/**
 * Show WP notice if WP version is incompatible
 *
 * @return void
 *
 * @since 1.10
 */
function pods_version_notice_wp() {
	global $wp_version;
	?>
	<div class="error fade">
		<p>
			<strong><?php _e( 'NOTICE', 'pods' ); ?>:</strong> Pods <?php echo esc_html( PODS_VERSION ); ?> <?php _e( 'requires a minimum of', 'pods' ); ?>
			<strong>WordPress <?php echo esc_html( PODS_WP_VERSION_MINIMUM ); ?>+</strong> <?php _e( 'to function. You are currently running', 'pods' ); ?>
			<strong>WordPress <?php echo esc_html( $wp_version ); ?></strong> - <?php _e( 'Please upgrade your WordPress to continue.', 'pods' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Show WP notice if PHP version is incompatible
 *
 * @return void
 *
 * @since 1.10
 */
function pods_version_notice_php() {
	?>
	<div class="error fade">
		<p>
			<strong><?php _e( 'NOTICE', 'pods' ); ?>:</strong> Pods <?php echo esc_html( PODS_VERSION ); ?> <?php _e( 'requires a minimum of', 'pods' ); ?>
			<strong>PHP <?php echo esc_html( PODS_PHP_VERSION_MINIMUM ); ?>+</strong> <?php _e( 'to function. You are currently running', 'pods' ); ?>
			<strong>PHP <?php echo esc_html( phpversion() ); ?></strong> - <?php _e( 'Please upgrade (or have your Hosting Provider upgrade it for you) your PHP version to continue.', 'pods' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Show WP notice if MySQL version is incompatible
 *
 * @return void
 *
 * @since 1.10
 */
function pods_version_notice_mysql() {
	global $wpdb;
	$mysql = $wpdb->db_version();
	?>
	<div class="error fade">
		<p>
			<strong><?php _e( 'NOTICE', 'pods' ); ?>:</strong> Pods <?php echo esc_html( PODS_VERSION ); ?> <?php _e( 'requires a minimum of', 'pods' ); ?>
			<strong>MySQL <?php echo esc_html( PODS_MYSQL_VERSION_MINIMUM ); ?>+</strong> <?php _e( 'to function. You are currently running', 'pods' ); ?>
			<strong>MySQL <?php echo esc_html( $mysql ); ?></strong> - <?php _e( 'Please upgrade (or have your Hosting Provider upgrade it for you) your MySQL version to continue.', 'pods' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Check if a Function exists or File exists in Theme / Child Theme
 *
 * @param string $function_or_file Function or file name to look for.
 * @param string $function_name    (optional) Function name to look for.
 * @param string $file_dir         (optional) Drectory to look into
 * @param string $file_name        (optional) Filename to look for
 *
 * @return mixed
 *
 * @since 1.12
 */
function pods_function_or_file( $function_or_file, $function_name = null, $file_dir = null, $file_name = null ) {
	$found            = false;
	$function_or_file = (string) $function_or_file;
	if ( false !== $function_name ) {
		if ( null === $function_name ) {
			$function_name = $function_or_file;
		}
		$function_name = str_replace( array(
			'__',
			'__',
			'__',
		), '_', preg_replace( '/[^a-z^A-Z^_][^a-z^A-Z^0-9^_]*/', '_', (string) $function_name ) );
		if ( function_exists( 'pods_custom_' . $function_name ) ) {
			$found = array( 'function' => 'pods_custom_' . $function_name );
		} elseif ( function_exists( $function_name ) ) {
			$found = array( 'function' => $function_name );
		}
	}
	if ( false !== $file_name && false === $found ) {
		if ( null === $file_name ) {
			$file_name = $function_or_file;
		}
		$file_name       = str_replace( array(
				'__',
				'__',
				'__',
			), '_', preg_replace( '/[^a-z^A-Z^0-9^_]*/', '_', (string) $file_name ) ) . '.php';
		$custom_location = apply_filters( 'pods_file_directory', null, $function_or_file, $function_name, $file_dir, $file_name );
		if ( defined( 'PODS_FILE_DIRECTORY' ) && false !== PODS_FILE_DIRECTORY ) {
			$custom_location = PODS_FILE_DIRECTORY;
		}
		if ( ! empty( $custom_location ) && locate_template( trim( $custom_location, '/' ) . '/' . ( ! empty( $file_dir ) ? $file_dir . '/' : '' ) . $file_name ) ) {
			$found = array( 'file' => trim( $custom_location, '/' ) . '/' . ( ! empty( $file_dir ) ? $file_dir . '/' : '' ) . $file_name );
		} elseif ( locate_template( 'pods/' . ( ! empty( $file_dir ) ? $file_dir . '/' : '' ) . $file_name ) ) {
			$found = array( 'file' => 'pods/' . ( ! empty( $file_dir ) ? $file_dir . '/' : '' ) . $file_name );
		} elseif ( locate_template( 'pods-' . ( ! empty( $file_dir ) ? $file_dir . '-' : '' ) . $file_name ) ) {
			$found = array( 'file' => 'pods-' . ( ! empty( $file_dir ) ? $file_dir . '-' : '' ) . $file_name );
		} elseif ( locate_template( 'pods/' . ( ! empty( $file_dir ) ? $file_dir . '-' : '' ) . $file_name ) ) {
			$found = array( 'file' => 'pods/' . ( ! empty( $file_dir ) ? $file_dir . '-' : '' ) . $file_name );
		}
	}//end if

	return apply_filters( 'pods_function_or_file', $found, $function_or_file, $function_name, $file_name );
}

/**
 * Redirects to another page.
 *
 * @param string  $location The path to redirect to
 * @param int     $status   Status code to use
 * @param boolean $die      If true, PHP code exection will stop
 *
 * @return void
 *
 * @since 2.0.0
 */
function pods_redirect( $location, $status = 302, $die = true ) {
	if ( ! headers_sent() ) {
		wp_redirect( $location, $status );
		if ( $die ) {
			die();
		}
	} else {
		echo '<script type="text/javascript">' . 'document.location = "' . str_replace( '&amp;', '&', esc_js( $location ) ) . '";' . '</script>';
		if ( $die ) {
			die();
		}
	}
}

/**
 * Check if a user has permission to be doing something based on standard permission options
 *
 * @param array|Whatsit $object The object data.
 *
 * @return bool Whether the user has permissions.
 *
 * @since 2.0.5
 */
function pods_permission( $object ) {
	$permissions = tribe( Permissions::class );

	return $permissions->user_has_permission( $object );
}

/**
 * Check if permissions are restricted for an object.
 *
 * @since 2.3.4
 *
 * @param array|Whatsit $object The object data.
 *
 * @return bool Whether the permissions are restricted for an object.
 */
function pods_has_permissions( $object ) {
	$permissions = tribe( Permissions::class );

	return $permissions->are_permissions_restricted( $object );
}

/**
 * A fork of get_page_by_title that excludes items unavailable via access rights (by status)
 *
 * @see   get_page_by_title
 *
 * @param string       $title   Title of item to get
 * @param string       $output  Optional. Output type. OBJECT, ARRAY_N, or ARRAY_A. Default OBJECT.
 * @param string       $type    Post Type
 * @param string|array $status  Post statuses to include (default is what user has access to)
 * @param bool         $return  Whether to return the 'id' or 'post'.
 *
 * @return WP_Post|null WP_Post on success or null on failure
 *
 * @since 2.3.4
 */
function pods_by_title( $title, $output = OBJECT, $type = 'page', $status = null, $return = 'post' ) {
	// @todo support Pod item lookups, not just Post Types
	/**
	 * @var $wpdb WPDB
	 */
	global $wpdb;

	if ( empty( $status ) ) {
		$status = array(
			'publish',
		);

		if ( current_user_can( 'read_private_' . $type . 's' ) ) {
			$status[] = 'private';
		}

		if ( current_user_can( 'edit_' . $type . 's' ) ) {
			$status[] = 'draft';
		}
	}

	$status = (array) $status;

	$status_sql = ' AND `post_status` IN ( %s' . str_repeat( ', %s', count( $status ) - 1 ) . ' )';

	$orderby_sql = ' ORDER BY ( `post_status` = %s ) DESC' . str_repeat( ', ( `post_status` = %s ) DESC', count( $status ) - 1 ) . ', `ID` DESC';

	// Once for WHERE, once for ORDER BY
	$prepared = array_merge( array( $title, $type ), $status, $status );

	$page = (int) $wpdb->get_var( $wpdb->prepare( "SELECT `ID` FROM `{$wpdb->posts}` WHERE `post_title` = %s AND `post_type` = %s" . $status_sql . $orderby_sql, $prepared ) );

	if ( 0 < $page ) {
		if ( 'id' === $return ) {
			return $page;
		}

		return get_post( $page, $output );
	}

	return null;
}

/**
 * Get a field value from a Pod.
 *
 * @param string|null  $pod    The pod name.
 * @param mixed|null   $id     The ID or slug of the item.
 * @param string|array $name   The field name, or an associative array of parameters.
 * @param boolean      $single For tableless fields, to return the whole array or the just the first item.
 *
 * @return mixed Field value.
 *
 * @since 2.1.0
 */
function pods_field( $pod, $id = null, $name = null, $single = false ) {
	// allow for pods_field( 'field_name' );
	if ( null === $name ) {
		$name   = $pod;
		$single = (boolean) $id;

		$pod = null;
		$id  = null;
	}

	if ( null === $pod && null === $id ) {
		$pod = get_post_type();
		$id  = get_the_ID();
	}

	$pod_object = pods( $pod, $id );

	if ( is_object( $pod_object ) && $pod_object->exists() ) {
		return $pod_object->field( $name, $single );
	}

	return null;
}

/**
 * Get a field display value from a Pod.
 *
 * @param string|null  $pod    The pod name.
 * @param mixed|null   $id     The ID or slug of the item.
 * @param string|array $name   The field name, or an associative array of parameters.
 * @param boolean      $single For tableless fields, to return the whole array or the just the first item.
 *
 * @return mixed Field value.
 *
 * @since 2.1.0
 */
function pods_field_display( $pod, $id = null, $name = null, $single = false ) {
	// allow for pods_field_display( 'field_name' );
	if ( null === $name ) {
		$name   = $pod;
		$single = (boolean) $id;

		$pod = null;
		$id  = null;
	}

	if ( null === $pod && null === $id ) {
		$pod = get_post_type();
		$id  = get_the_ID();
	}

	$pod_object = pods( $pod, $id );

	if ( is_object( $pod_object ) && $pod_object->exists() ) {
		return $pod_object->display( $name, $single );
	}

	return null;
}

/**
 * Get a field raw value from a Pod.
 *
 * @param string|null  $pod    The pod name.
 * @param mixed|null   $id     The ID or slug of the item.
 * @param string|array $name   The field name, or an associative array of parameters.
 * @param boolean      $single For tableless fields, to return the whole array or the just the first item.
 *
 * @return mixed Field value.
 *
 * @since 2.1.0
 */
function pods_field_raw( $pod, $id = null, $name = null, $single = false ) {
	// allow for pods_field_raw( 'field_name' );
	if ( null === $name ) {
		$name   = $pod;
		$single = (boolean) $id;

		$pod = null;
		$id  = null;
	}

	if ( null === $pod && null === $id ) {
		$pod = get_post_type();
		$id  = get_the_ID();
	}

	$pod_object = pods( $pod, $id );

	if ( is_object( $pod_object ) && $pod_object->exists() ) {
		return $pod_object->raw( $name, $single );
	}

	return null;

}

/**
 * Update a field value for a Pod.
 *
 * @param string|null  $pod   The pod name.
 * @param mixed|null   $id    The ID or slug of the item.
 * @param string|array $name  The field name, or an associative array of parameters.
 * @param boolean      $value Value to save.
 *
 * @return int|false The item ID or false if not saved.
 *
 * @since 2.7.17
 */
function pods_field_update( $pod, $id = null, $name = null, $value = null ) {

	// allow for pods_field( 'field_name' );
	if ( null === $name ) {
		$name  = $pod;
		$value = $id;

		$pod = null;
		$id  = null;
	}

	if ( null === $pod && null === $id ) {
		$pod = get_post_type();
		$id  = get_the_ID();
	}

	$pod_object = pods( $pod, $id );

	if ( is_object( $pod_object ) && $pod_object->exists() ) {
		return $pod_object->save( $name, $value );
	}

	return false;
}

/**
 * Set a cached value
 *
 * @see   PodsView::set
 *
 * @param string $key        Key for the cache
 * @param mixed  $value      Value to add to the cache
 * @param int    $expires    (optional) Time in seconds for the cache to expire, if 0 no expiration.
 * @param string $cache_mode (optional) Decides the caching method to use for the view.
 * @param string $group      (optional) Key for the group
 *
 * @return bool|mixed|null|string|void
 *
 * @since 2.0.0
 */
function pods_view_set( $key, $value, $expires = 0, $cache_mode = 'cache', $group = '' ) {
	return PodsView::set( $key, $value, $expires, $cache_mode, $group );
}

/**
 * Get a cached value
 *
 * @see   PodsView::get
 *
 * @param string $key        Key for the cache
 * @param string $cache_mode (optional) Decides the caching method to use for the view.
 * @param string $group      (optional) Key for the group
 * @param string $callback   (optional) Callback function to run to set the value if not cached
 *
 * @return bool|mixed|null|void
 *
 * @since 2.0.0
 */
function pods_view_get( $key, $cache_mode = 'cache', $group = '', $callback = null ) {
	return PodsView::get( $key, $cache_mode, $group, $callback );
}

/**
 * Clear a cached value
 *
 * @see   PodsView::clear
 *
 * @param string|bool $key        Key for the cache
 * @param string      $cache_mode (optional) Decides the caching method to use for the view.
 * @param string      $group      (optional) Key for the group
 *
 * @return bool
 *
 * @since 2.0.0
 */
function pods_view_clear( $key = true, $cache_mode = 'cache', $group = '' ) {
	return PodsView::clear( $key, $cache_mode, $group );
}

/**
 * Set a cached value
 *
 * @see   PodsView::set
 *
 * @param string $key     Key for the cache
 * @param mixed  $value   Value to add to the cache
 * @param string $group   (optional) Key for the group
 * @param int    $expires (optional) Time in seconds for the cache to expire, if 0 no expiration.
 *
 * @return bool|mixed|null|string|void
 *
 * @since 2.0.0
 */
function pods_cache_set( $key, $value, $group = '', $expires = 0 ) {
	return pods_view_set( $key, $value, $expires, 'cache', $group );
}

/**
 * Get a cached value
 *
 * @see   PodsView::get
 *
 * @param string $key      Key for the cache
 * @param string $group    (optional) Key for the group
 * @param string $callback (optional) Callback function to run to set the value if not cached
 *
 * @return bool
 *
 * @since 2.0.0
 */
function pods_cache_get( $key, $group = '', $callback = null ) {
	return pods_view_get( $key, 'cache', $group, $callback );
}

/**
 * Clear a cached value
 *
 * @see   PodsView::clear
 *
 * @param string|bool $key   Key for the cache
 * @param string      $group (optional) Key for the group
 *
 * @return bool|mixed|null|void
 *
 * @since 2.0.0
 */
function pods_cache_clear( $key = true, $group = '' ) {
	return pods_view_clear( $key, 'cache', $group );
}

/**
 * Set a cached value
 *
 * @see   PodsView::set
 *
 * @param string $key     Key for the cache
 * @param mixed  $value   Value to add to the cache
 * @param int    $expires (optional) Time in seconds for the cache to expire, if 0 no expiration.
 *
 * @return bool|mixed|null|string|void
 *
 * @since 2.0.0
 */
function pods_transient_set( $key, $value, $expires = 0 ) {
	return pods_view_set( $key, $value, $expires, 'transient' );
}

/**
 * Get a cached value
 *
 * @see   PodsView::get
 *
 * @param string $key      Key for the cache
 * @param string $callback (optional) Callback function to run to set the value if not cached
 *
 * @return bool|mixed|null|void
 *
 * @since 2.0.0
 */
function pods_transient_get( $key, $callback = null ) {
	return pods_view_get( $key, 'transient', '', $callback );
}

/**
 * Clear a cached value
 *
 * @see   PodsView::clear
 *
 * @param string|bool $key Key for the cache
 *
 * @return bool
 *
 * @since 2.0.0
 */
function pods_transient_clear( $key = true ) {
	return pods_view_clear( $key, 'transient' );
}

/**
 * Set a cached value
 *
 * @see   PodsView::set
 *
 * @param string $key     Key for the cache
 * @param mixed  $value   Value to add to the cache
 * @param int    $expires (optional) Time in seconds for the cache to expire, if 0 no expiration.
 *
 * @return bool|mixed|null|string|void
 *
 * @since 2.3.10
 */
function pods_site_transient_set( $key, $value, $expires = 0 ) {
	return pods_view_set( $key, $value, $expires, 'site-transient' );
}

/**
 * Get a cached value
 *
 * @see   PodsView::get
 *
 * @param string $key      Key for the cache
 * @param string $callback (optional) Callback function to run to set the value if not cached
 *
 * @return bool|mixed|null|void
 *
 * @since 2.3.10
 */
function pods_site_transient_get( $key, $callback = null ) {
	return pods_view_get( $key, 'site-transient', '', $callback );
}

/**
 * Clear a cached value
 *
 * @see   PodsView::clear
 *
 * @param string|bool $key Key for the cache
 *
 * @return bool
 *
 * @since 2.3.10
 */
function pods_site_transient_clear( $key = true ) {
	return pods_view_clear( $key, 'site-transient' );
}

/**
 * Set a cached value
 *
 * @see   PodsView::set
 *
 * @param string $key     Key for the cache
 * @param mixed  $value   Value to add to the cache
 * @param int    $expires (optional) Time in seconds for the cache to expire, if 0 no expiration.
 * @param string $group   (optional) Key for the group
 *
 * @return bool|mixed|null|string|void
 *
 * @since 2.3.10
 */
function pods_option_cache_set( $key, $value, $expires = 0, $group = '' ) {
	return pods_view_set( $key, $value, $expires, 'option-cache', $group );
}

/**
 * Get a cached value
 *
 * @see   PodsView::get
 *
 * @param string $key      Key for the cache
 * @param string $group    (optional) Key for the group
 * @param string $callback (optional) Callback function to run to set the value if not cached
 *
 * @return bool|mixed|null|void
 *
 * @since 2.3.10
 */
function pods_option_cache_get( $key, $group = '', $callback = null ) {
	return pods_view_get( $key, 'option-cache', $group, $callback );
}

/**
 * Clear a cached value
 *
 * @see   PodsView::clear
 *
 * @param string|bool $key   Key for the cache
 * @param string      $group (optional) Key for the group
 *
 * @return bool
 *
 * @since 2.3.10
 */
function pods_option_cache_clear( $key = true, $group = '' ) {
	return pods_view_clear( $key, 'option-cache', $group );
}

/**
 * Scope variables and include a template like get_template_part that's child-theme aware
 *
 * @see   get_template_part
 *
 * @param string|array $template Template names (see get_template_part)
 * @param array        $data     Data to scope to the include
 * @param bool         $return   Whether to return the output (echo by default)
 *
 * @return string|null Template output
 *
 * @since 2.3.9
 */
function pods_template_part( $template, $data = null, $return = false ) {
	$part = PodsView::get_template_part( $template, $data );

	if ( ! $return ) {
		echo $part;

		return null;
	}

	return $part;
}

/**
 * Add a new Pod outside of the DB
 *
 * @see   PodsMeta::register
 *
 * @param string $type   The pod type ('post_type', 'taxonomy', 'media', 'user', 'comment')
 * @param string $name   The pod name
 * @param array  $object (optional) Pod array, including any 'fields' arrays
 *
 * @return array|boolean Pod data or false if unsuccessful
 * @since 2.1.0
 */
function pods_register_type( $type, $name, $object = null ) {
	if ( empty( $object ) ) {
		$object = array();
	}

	if ( ! empty( $name ) ) {
		$object['name'] = $name;
	}

	return pods_meta()->register( $type, $object );
}

/**
 * Add a new Pod field outside of the DB
 *
 * @see   PodsMeta::register_field
 *
 * @param string|array $pod    The pod name or array of pod names
 * @param string       $name   The name of the Pod
 * @param array        $object (optional) Pod array, including any 'fields' arrays
 *
 * @return array|boolean Field data or false if unsuccessful
 * @since 2.1.0
 */
function pods_register_field( $pod, $name, $field = null ) {
	if ( empty( $field ) ) {
		$field = array();
	}

	if ( ! empty( $name ) ) {
		$field['name'] = $name;
	}

	return pods_meta()->register_field( $pod, $field );
}

/**
 * Add a new Pod field type
 *
 * @see   PodsForm::register_field_type
 *
 * @param string $type The new field type identifier
 * @param string $file The new field type class file location
 *
 * @return array Field type array
 * @since 2.3.0
 */
function pods_register_field_type( $type, $file = null ) {
	return PodsForm::register_field_type( $type, $file );
}

/**
 * Register a related object
 *
 * @param string $name    Object name
 * @param string $label   Object label
 * @param array  $options Object options
 *
 * @return array|boolean Object array or false if unsuccessful
 * @since 2.3.0
 */
function pods_register_related_object( $name, $label, $options = null ) {
	return PodsForm::field_method( 'pick', 'register_related_object', $name, $label, $options );
}

/**
 * Register an object with Pods.
 *
 * @since 2.8.0
 *
 * @param array  $object The object configuration.
 * @param string $type   The object type.
 *
 * @return true|WP_Error True if successful, or else an WP_Error with the problem.
 */
function pods_register_object( array $object, $type ) {
	$object['object_type']  = $type;
	$object['object_storage_type'] = 'collection';

	$object_collection = Store::get_instance();
	$object_collection->register_object( $object );

	return true;
}

/**
 * Register a group and it's fields with Pods.
 *
 * @since 2.8.0
 *
 * @param array  $group The group configuration.
 * @param string $pod   The pod to register to.
 * @param array  $field The list of group fields.
 *
 * @return true|WP_Error True if successful, or else an WP_Error with the problem.
 */
function pods_register_group( array $group, $pod, array $fields ) {
	$group['parent'] = 'pod/' . $pod;

	pods_register_object( $group, 'group' );

	foreach ( $fields as $field ) {
		pods_register_group_field( $field, $group['name'], $pod );
	}

	return true;
}

/**
 * Register a field with Pods.
 *
 * @since 2.8.0
 *
 * @param array  $field The field configuration.
 * @param string $group The group to register to.
 * @param string $pod   The pod to register to.
 *
 * @return true|WP_Error True if successful, or else an WP_Error with the problem.
 */
function pods_register_group_field( array $field, $group, $pod ) {
	$field['parent'] = 'pod/' . $pod;
	$field['group']  = $group;

	pods_register_object( $field, 'field' );

	return true;
}

/**
 * Register a block type with Pods. Always register during the `pods_blocks_api_init` action.
 *
 * @since 2.8.0
 *
 * @param array $block  The block configuration, compatible with `register_block_type()`.
 * @param array $fields List of fields to use for inspector controls.
 *
 * @return true|WP_Error True if successful, or else an WP_Error with the problem.
 *
 * @see register_block_type
 * @see Pods\Blocks\Types\Base
 */
function pods_register_block_type( array $block, array $fields = [] ) {
	if ( empty( $block['namespace'] ) ) {
		return new WP_Error( 'pods-blocks-api-block-type-invalid', __( 'Invalid block type configuration provided', 'pods' ) );
	}

	$block['object_type']  = 'block';
	$block['object_storage_type'] = 'collection';
	$block['name']         = pods_v( 'name', $block, pods_v( 'slug', $block ) );
	$block['label']        = pods_v( 'label', $block, pods_v( 'title', $block ) );
	$block['category']     = pods_v( 'category', $block, pods_v( 'collection', $block ) );

	$object_collection = Store::get_instance();
	$object_collection->register_object( $block );

	foreach ( $fields as $field ) {
		$field['object_type']  = 'block-field';
		$field['object_storage_type'] = 'collection';
		$field['parent']       = 'block/' . $block['name'];
		$field['name']         = pods_v( 'name', $field, pods_v( 'slug', $field ) );
		$field['label']        = pods_v( 'label', $field, pods_v( 'title', $field ) );

		$object_collection->register_object( $field );
	}

	return true;
}

/**
 * Register a block collection with Pods. Always register during the `pods_blocks_api_init` action.
 *
 * @since 2.8.0
 *
 * @param array $collection The block collection configuration, compatible with `block_categories` filter.
 *
 * @return true|WP_Error True if successful, or else an WP_Error with the problem.
 *
 * @see Pods\Blocks\Collections\Base
 */
function pods_register_block_collection( array $collection ) {
	if ( empty( $collection['namespace'] ) ) {
		return new WP_Error( 'pods-blocks-api-block-collection-invalid', __( 'Invalid block collection configuration provided', 'pods' ) );
	}

	$collection['object_type']  = 'block-collection';
	$collection['object_storage_type'] = 'collection';
	$collection['label']        = pods_v( 'label', $collection, pods_v( 'title', $collection ) );

	$object_collection = Store::get_instance();
	$object_collection->register_object( $collection );

	return true;
}

/**
 * Require a component (always-on)
 *
 * @param string $component Component ID
 *
 * @return void
 *
 * @since 2.3.0
 */
function pods_require_component( $component ) {
	add_filter( 'pods_component_require_' . $component, '__return_true' );
}

/**
 * Add a meta group of fields to add/edit forms
 *
 * @see   PodsMeta::group_add
 *
 * @param string|array $pod      The pod or type of element to attach the group to.
 * @param string       $label    Title of the edit screen section, visible to user.
 * @param string|array $fields   Either a comma separated list of text fields or an associative array containing field
 *                               information.
 * @param string       $context  (optional) The part of the page where the edit screen section should be shown
 *                               ('normal', 'advanced', or 'side').
 * @param string       $priority (optional) The priority within the context where the boxes should show ('high',
 *                               'core', 'default' or 'low').
 * @param string       $type     (optional) Type of the post to attach to.
 *
 * @return void
 *
 * @since 2.0.0
 * @link  https://docs.pods.io/code/general-functions/pods-group-add/
 */
function pods_group_add( $pod, $label, $fields, $context = 'normal', $priority = 'default', $type = null ) {
	pods_meta()->group_add( $pod, $label, $fields, $context, $priority );
}

/**
 * Check if a plugin is active on non-admin pages (is_plugin_active() only available in admin)
 *
 * @param string $plugin Plugin name.
 *
 * @return bool
 *
 * @since 2.0.0
 */
function pods_is_plugin_active( $plugin ) {
	$active = false;

	if ( function_exists( 'is_plugin_active' ) ) {
		$active = is_plugin_active( $plugin );
	}

	if ( ! $active ) {
		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( in_array( $plugin, $active_plugins, true ) ) {
			$active = true;
		}

		if ( ! $active && is_multisite() ) {
			$plugins = get_site_option( 'active_sitewide_plugins' );

			if ( isset( $plugins[ $plugin ] ) ) {
				$active = true;
			}
		}
	}

	return $active;
}

/**
 * Check if Pods no conflict is on or not
 *
 * @param string $object_type
 *
 * @return bool
 *
 * @since 2.3.0
 */
function pods_no_conflict_check( $object_type = 'post' ) {
	if ( 'post_type' === $object_type ) {
		$object_type = 'post';
	} elseif ( 'term' === $object_type ) {
		$object_type = 'taxonomy';
	}

	if ( ! class_exists( 'PodsInit' ) ) {
		pods_init();
	}

	if ( ! empty( PodsInit::$no_conflict[ $object_type ] ) ) {
		return true;
	}

	return false;
}

/**
 * Get the list of meta hooks to add/remove for a specific object type.
 *
 * @since 2.8.0
 *
 * @param string      $object_type The object type.
 * @param string|null $object      The object name.
 *
 * @return array List of filters and actions for a specific object type.
 */
function pods_meta_hook_list( $object_type = 'post', $object = null ) {
	if ( 'post_type' === $object_type ) {
		$object_type = 'post';
	} elseif ( 'term' === $object_type ) {
		$object_type = 'taxonomy';
	}

	$hooks = [
		'filter' => [],
		'action' => [],
	];

	// Filters = Usually get/update/delete meta functions
	// Actions = Usually insert/update/save/delete object functions
	if ( 'post' === $object_type || 'media' === $object_type  || 'all' === $object_type ) {
		// Handle *_post_meta
		if ( apply_filters( 'pods_meta_handler', true, 'post' ) ) {
			if ( apply_filters( 'pods_meta_handler_get', true, 'post' ) ) {
				$hooks['filter']['get_post_metadata'] = [ 'get_post_metadata', [ PodsInit::$meta, 'get_post_meta' ], 10, 4 ];
			}

			if ( ! pods_tableless() ) {
				$hooks['filter']['add_post_metadata']          = [ 'add_post_metadata', [ PodsInit::$meta, 'add_post_meta' ], 10, 5 ];
				$hooks['filter']['update_post_metadata']       = [ 'update_post_metadata', [ PodsInit::$meta, 'update_post_meta' ], 10, 5 ];
				$hooks['filter']['update_post_metadata_by_id'] = [ 'update_post_metadata_by_id', [ PodsInit::$meta, 'update_post_meta_by_id' ], 10, 4 ];
				$hooks['filter']['delete_post_metadata']       = [ 'delete_post_metadata', [ PodsInit::$meta, 'delete_post_meta' ], 10, 5 ];
				$hooks['filter']['delete_post_metadata_by_id'] = [ 'delete_post_metadata_by_id', [ PodsInit::$meta, 'delete_post_meta_by_id' ], 10, 2 ];
			}
		}

		// Add meta box groups.
		$hooks['action'][] = [ 'add_meta_boxes', [ PodsInit::$meta, 'meta_post_add' ], 10, 1 ];

		// Other post-related hooks.
		$hooks['filter'][] = [ 'enter_title_here', [ PodsInit::$meta, 'meta_post_enter_title_here' ], 10, 2 ];
		$hooks['filter'][] = [ 'wp_revisions_to_keep', [ PodsInit::$meta, 'meta_post_revisions_to_keep' ], 10, 2 ];

		if ( 'post' === $object_type || 'all' === $object_type ) {
			// Handle detecting new post.
			$hooks['action'][] = [ 'transition_post_status', [ PodsInit::$meta, 'save_post_detect_new' ], 10, 3 ];

			// Handle save.
			$hooks['action'][] = [ 'save_post', [ PodsInit::$meta, 'save_post' ], 10, 3 ];

			// Handle delete from relationships.
			$hooks['action'][] = [ 'delete_post', [ PodsInit::$meta, 'delete_post' ], 10, 1 ];

			// Track changed fields.
			$hooks['action'][] = [
				'wp_insert_post_data',
				[ PodsInit::$meta, 'save_post_track_changed_fields' ],
				10,
				2,
			];
		}
	}

	if ( 'taxonomy' === $object_type || 'all' === $object_type ) {
		// Handle *_term_meta
		if ( apply_filters( 'pods_meta_handler', true, 'term' ) ) {
			if ( apply_filters( 'pods_meta_handler_get', true, 'term' ) ) {
				$hooks['filter'][] = [ 'get_term_metadata', [ PodsInit::$meta, 'get_term_meta' ], 10, 4 ];
			}

			if ( ! pods_tableless() ) {
				$hooks['filter']['add_term_metadata']          = [ 'add_term_metadata', [ PodsInit::$meta, 'add_term_meta' ], 10, 5 ];
				$hooks['filter']['update_term_metadata']       = [ 'update_term_metadata', [ PodsInit::$meta, 'update_term_meta' ], 10, 5 ];
				$hooks['filter']['update_term_metadata_by_id'] = [ 'update_term_metadata_by_id', [ PodsInit::$meta, 'update_term_meta_by_id' ], 10, 4 ];
				$hooks['filter']['delete_term_metadata']       = [ 'delete_term_metadata', [ PodsInit::$meta, 'delete_term_meta' ], 10, 5 ];
				$hooks['filter']['delete_term_metadata_by_id'] = [ 'delete_term_metadata_by_id', [ PodsInit::$meta, 'delete_term_meta_by_id' ], 10, 2 ];
			}
		}

		// Handle save.
		$hooks['action'][] = [ 'edited_term', [ PodsInit::$meta, 'save_taxonomy' ], 10, 3 ];
		$hooks['action'][] = [ 'create_term', [ PodsInit::$meta, 'save_taxonomy' ], 10, 3 ];

		// Handle delete from relationships.
		$hooks['action'][] = [ 'delete_term_taxonomy', [ PodsInit::$meta, 'delete_taxonomy' ], 10, 1 ];

		// Handle form fields specific to the taxonomy.
		if ( $object ) {
			$hooks['action'][] = [ $object . '_edit_form_fields', [ PodsInit::$meta, 'meta_taxonomy' ], 10, 2 ];
			$hooks['action'][] = [ $object . '_add_form_fields', [ PodsInit::$meta, 'meta_taxonomy' ], 10, 1 ];
		}

		// Track changed fields.
		$hooks['action'][] = [
			'edit_terms',
			[ PodsInit::$meta, 'save_taxonomy_track_changed_fields' ],
			10,
			2,
		];

		/**
		 * Fires after a previously shared taxonomy term is split into two separate terms.
		 *
		 * @since 4.2.0
		 *
		 * @param int    $term_id          ID of the formerly shared term.
		 * @param int    $new_term_id      ID of the new term created for the $term_taxonomy_id.
		 * @param int    $term_taxonomy_id ID for the term_taxonomy row affected by the split.
		 * @param string $taxonomy         Taxonomy for the split term.
		 */
		$hooks['action'][] = [ 'split_shared_term', [ PodsInit::$meta, 'split_shared_term' ], 10, 4 ];
	}

	if ( 'media' === $object_type || 'all' === $object_type ) {
		// Handle old AJAX attachment saving.
		$hooks['action'][] = [ 'wp_ajax_save-attachment-compat', [ PodsInit::$meta, 'save_media_ajax' ], 0, 1 ];

		// Handle showing meta fields in modal.
		$hooks['filter'][] = [ 'attachment_fields_to_edit', [ PodsInit::$meta, 'meta_media' ], 10, 2 ];

		// Handle saving meta fields from modal.
		$hooks['filter'][] = [ 'attachment_fields_to_save', [ PodsInit::$meta, 'save_media' ], 10, 2 ];

		// Handle saving attachment metadata.
		$hooks['filter'][] = [ 'wp_update_attachment_metadata', [ PodsInit::$meta, 'save_media' ], 10, 2 ];

		// Handle delete.
		$hooks['action'][] = [ 'delete_attachment', [ PodsInit::$meta, 'delete_media' ], 10, 1 ];

		// Track changed fields.
		$hooks['filter'][] = [
			'wp_insert_attachment_data',
			[ PodsInit::$meta, 'save_post_track_changed_fields' ],
			10,
			2,
		];
	}

	if ( 'user' === $object_type || 'all' === $object_type ) {
		// Handle *_user_meta.
		if ( apply_filters( 'pods_meta_handler', true, 'user' ) ) {
			if ( apply_filters( 'pods_meta_handler_get', true, 'user' ) ) {
				$hooks['filter'][] = [ 'get_user_metadata', [ PodsInit::$meta, 'get_user_meta' ], 10, 4 ];
			}

			if ( ! pods_tableless() ) {
				$hooks['filter']['add_user_metadata']          = [ 'add_user_metadata', [ PodsInit::$meta, 'add_user_meta' ], 10, 5 ];
				$hooks['filter']['update_user_metadata']       = [ 'update_user_metadata', [ PodsInit::$meta, 'update_user_meta' ], 10, 5 ];
				$hooks['filter']['update_user_metadata_by_id'] = [ 'update_user_metadata_by_id', [ PodsInit::$meta, 'update_user_meta_by_id' ], 10, 4 ];
				$hooks['filter']['delete_user_metadata']       = [ 'delete_user_metadata', [ PodsInit::$meta, 'delete_user_meta' ], 10, 5 ];
				$hooks['filter']['delete_user_metadata_by_id'] = [ 'delete_user_metadata_by_id', [ PodsInit::$meta, 'delete_user_meta_by_id' ], 10, 2 ];
			}
		}

		// Handle showing fields in form.
		$hooks['action'][] = [ 'show_user_profile', [ PodsInit::$meta, 'meta_user' ], 10, 1 ];
		$hooks['action'][] = [ 'edit_user_profile', [ PodsInit::$meta, 'meta_user' ], 10, 1 ];

		// Handle saving from registration form.
		$hooks['action'][] = [ 'user_register', [ PodsInit::$meta, 'save_user' ], 10, 1 ];

		// Handle saving from profile update.
		$hooks['action'][] = [ 'profile_update', [ PodsInit::$meta, 'save_user' ], 10, 2 ];

		// Track changed fields.
		$hooks['filter'][] = [ 'pre_user_login', [ PodsInit::$meta, 'save_user_track_changed_fields' ], 10, 1 ];
	}

	if ( 'comment' === $object_type || 'all' === $object_type ) {
		if ( apply_filters( 'pods_meta_handler', true, 'comment' ) ) {
			// Handle *_comment_meta
			if ( apply_filters( 'pods_meta_handler_get', true, 'comment' ) ) {
				$hooks['filter'][] = [ 'get_comment_metadata', [ PodsInit::$meta, 'get_comment_meta' ], 10, 4 ];
			}

			if ( ! pods_tableless() ) {
				$hooks['filter']['add_comment_metadata']           = [ 'add_comment_metadata', [ PodsInit::$meta, 'add_comment_meta' ], 10, 5 ];
				$hooks['filter']['update_comment_metadata']        = [ 'update_comment_metadata', [ PodsInit::$meta, 'update_comment_meta' ], 10, 5 ];
				$hooks['filter']['update_comment_metadata_by_id']  = [ 'update_comment_metadata_by_id', [ PodsInit::$meta, 'update_comment_meta_by_id' ], 10, 4 ];
				$hooks['filter']['delete_comment_metadata']        = [ 'delete_comment_metadata', [ PodsInit::$meta, 'delete_comment_meta' ], 10, 5 ];
				$hooks['filter']['delete_comment_metadata_by_id']  = [ 'delete_comment_metadata_by_id', [ PodsInit::$meta, 'delete_comment_meta_by_id' ], 10, 2 ];
			}
		}

		// Handle showing fields in form.
		$hooks['filter'][] = [ 'comment_form_submit_field', [ PodsInit::$meta, 'meta_comment_new' ], 10, 1 ];

		// Add meta box groups.
		$hooks['action'][] = [ 'add_meta_boxes_comment', [ PodsInit::$meta, 'meta_comment_add' ], 10, 1 ];

		// Handle validation for fields.
		$hooks['filter'][] = [ 'pre_comment_approved', [ PodsInit::$meta, 'validate_comment' ], 10, 2 ];

		// Handle saving comment from frontend.
		$hooks['action'][] = [ 'comment_post', [ PodsInit::$meta, 'save_comment' ], 10, 1 ];

		// Handle saving comment from admin.
		$hooks['action'][] = [ 'edit_comment', [ PodsInit::$meta, 'save_comment' ], 10, 1 ];

		// Track changed fields.
		$hooks['action'][] = [ 'wp_update_comment_data', [ PodsInit::$meta, 'save_comment_track_changed_fields' ], 10, 3 ];
	}

	if ( 'settings' === $object_type || 'all' === $object_type ) {
		// @todo Patch core to provide $option back in filters, patch core to add filter pre_add_option to add_option.

		// Undesirable way to do things which is heavy and requires access to looping through all fields, pulled from PodsMeta::core().
		/*foreach ( self::$settings as $setting_pod ) {
			foreach ( $setting_pod[ 'fields' ] as $option ) {
				add_filter( 'pre_option_' . $setting_pod[ 'name' ] . '_' . $option[ 'name' ], array( PodsInit::$meta, 'get_option' ), 10, 1 );
				add_action( 'add_option_' . $setting_pod[ 'name' ] . '_' . $option[ 'name' ], array( PodsInit::$meta, 'add_option' ), 10, 2 );
				add_filter( 'pre_update_option_' . $setting_pod[ 'name' ] . '_' . $option[ 'name' ], array( PodsInit::$meta, 'update_option' ), 10, 2 );
			}
		}*/
	}

	return $hooks;
}

/**
 * Turn off conflicting / recursive actions for an object type that Pods hooks into
 *
 * @since 2.0.0
 *
 * @param string      $object_type The object type.
 * @param string|null $object      The object name.
 *
 * @return bool Whether no conflict mode was turned on.
 */
function pods_no_conflict_on( $object_type = 'post', $object = null ) {
	if ( 'post_type' === $object_type ) {
		$object_type = 'post';
	} elseif ( 'term' === $object_type ) {
		$object_type = 'taxonomy';
	}

	if ( ! class_exists( 'PodsInit' ) ) {
		pods_init();
	}

	if ( ! empty( PodsInit::$no_conflict[ $object_type ] ) ) {
		return true;
	}

	if ( ! is_object( PodsInit::$meta ) ) {
		return false;
	}

	$no_conflict = pods_meta_hook_list( $object_type );

	$conflicted = false;

	foreach ( $no_conflict as $action_filter => $conflicts ) {
		foreach ( $conflicts as $k => $args ) {
			if ( call_user_func_array( 'has_' . $action_filter, array_slice( $args, 0, 2 ) ) ) {
				call_user_func_array( 'remove_' . $action_filter, array_slice( $args, 0, 3 ) );

				$conflicted = true;
			} else {
				unset( $no_conflict[ $action_filter ][ $k ] );
			}
		}
	}

	if ( $conflicted ) {
		PodsInit::$no_conflict[ $object_type ] = $no_conflict;

		return true;
	}

	return false;
}

/**
 * Turn on actions after running code during pods_conflict
 *
 * @since 2.0.0
 *
 * @param string      $object_type The object type.
 * @param string|null $object      The object name.
 * @param bool        $force       Whether to force turning all hooks back on even if they were already off.
 *
 * @return bool Whether no conflict mode was on and was successfully turned off.
 */
function pods_no_conflict_off( $object_type = 'post', $object = null, $force = false ) {
	if ( 'post_type' === $object_type ) {
		$object_type = 'post';
	} elseif ( 'term' === $object_type ) {
		$object_type = 'taxonomy';
	}

	if ( ! class_exists( 'PodsInit' ) ) {
		pods_init();
	}

	if ( ! is_object( PodsInit::$meta ) ) {
		return false;
	}

	if ( $force ) {
		// Turn ALL hooks back on.
		$no_conflict = pods_meta_hook_list( $object_type, $object );
	} else {
		// No conflict mode was not already on.
		if ( empty( PodsInit::$no_conflict[ $object_type ] ) ) {
			return false;
		}

		// Only turn on the hooks we turned off for no conflict mode.
		$no_conflict = PodsInit::$no_conflict[ $object_type ];
	}

	$conflicted = false;

	foreach ( $no_conflict as $action_filter => $conflicts ) {
		foreach ( $conflicts as $args ) {
			if ( ! call_user_func_array( 'has_' . $action_filter, array_slice( $args, 0, 2 ) ) ) {
				call_user_func_array( 'add_' . $action_filter, $args );

				$conflicted = true;
			}
		}
	}

	if ( isset( PodsInit::$no_conflict['all'] ) ) {
		unset( PodsInit::$no_conflict['all'] );
	}

	if ( $conflicted ) {
		unset( PodsInit::$no_conflict[ $object_type ] );

		return true;
	}

	return false;
}

/**
 * Returns a list of all WordPress and Pods reserved keywords.
 *
 * @link https://codex.wordpress.org/Reserved_Terms
 *
 * @since 2.7.15
 *
 * @param null|string $context The reserved keyword context.
 *
 * @return array
 */
function pods_reserved_keywords( $context = null ) {
	// WordPress Post keywords.
	$wp_post_keywords = [
		'id',
		'ID',
		'attachment',
		'attachment_id',
		'author',
		'author_name',
		'category',
		'link_category',
		'name',
		'p',
		'page',
		'paged',
		'post',
		'post_format',
		'post_mime_type',
		'post_status',
		'post_tag',
		'post_thumbnail',
		'post_thumbnail_url',
		'post_type',
		's',
		'search',
		'tag',
		'taxonomy',
		'term',
		'terms',
		'title',
		'type',
	];

	// WordPress keywords.
	$wp_keywords = [
		'calendar',
		'cat',
		'category__and',
		'category__in',
		'category__not_in',
		'category_name',
		'comments_per_page',
		'comments_popup',
		'cpage',
		'custom',
		'customize_messenger_channel',
		'customized',
		'day',
		'debug',
		'embed',
		'error',
		'exact',
		'feed',
		'hour',
		'm',
		'minute',
		'monthnum',
		'more',
		'nav_menu',
		'nonce',
		'nopaging',
		'offset',
		'order',
		'orderby',
		'page_id',
		'pagename',
		'pb',
		'perm',
		'post__in',
		'post__not_in',
		'posts',
		'posts_per_archive_page',
		'posts_per_page',
		'preview',
		'robots',
		'second',
		'sentence',
		'showposts',
		'static',
		'subpost',
		'subpost_id',
		'tag__and',
		'tag__in',
		'tag__not_in',
		'tag_id',
		'tag_slug__and',
		'tag_slug__in',
		'tb',
		'theme',
		'w',
		'withcomments',
		'withoutcomments',
		'year',
	];

	// Pods keywords.
	$pods_keywords = [
		'id',
		'ID',
	];

	$keywords = [];

	// Add keywords for WP context.
	if ( in_array( $context, [ null, 'wp' ], true ) ) {
		$keywords = array_merge( $keywords, $wp_keywords );
	}

	// Add keywords for WP Post contexts (also applies to WP context).
	if ( in_array( $context, [ null, 'wp', 'wp-post' ], true ) ) {
		$keywords = array_merge( $keywords, $wp_post_keywords );
	}

	// Add keywords for Pods context.
	if ( in_array( $context, [ null, 'pods' ], true ) ) {
		$keywords = array_merge( $keywords, $pods_keywords );
	}

	$keywords = array_filter( array_unique( $keywords ) );

	/**
	 * Filter the WordPress and Pods reserved keywords.
	 *
	 * @since 2.7.15
	 *
	 * @param array       $keywords List of WordPress and Pods reserved keywords.
	 * @param null|string $context  The reserved keyword context.
	 */
	return apply_filters( 'pods_reserved_keywords', $keywords, $context );
}

/**
 * Get the value for a Pods setting.
 *
 * @since 2.8.0
 *
 * @param string $setting_name The setting name.
 * @param null   $default      The default value if the setting is not yet set.
 *
 * @return mixed The setting value.
 */
function pods_get_setting( $setting_name, $default = null ) {
	$settings = tribe( Settings::class );

	return $settings->get_setting( $setting_name, $default );
}

/**
 * Get the Pods settings.
 *
 * @since 2.8.0
 *
 * @return array The setting values.
 */
function pods_get_settings() {
	$settings = tribe( Settings::class );

	return $settings->get_settings();
}

/**
 * Update the value for a Pods setting.
 *
 * @since 2.8.0
 *
 * @param string $setting_name  The setting name.
 * @param mixed  $setting_value The setting value.
 */
function pods_update_setting( $setting_name, $setting_value ) {
	$settings = tribe( Settings::class );

	$settings->update_setting( $setting_name, $setting_value );
}

/**
 * Update the settings for a Pods.
 *
 * @since 2.8.0
 *
 * @param array $setting_values The list of settings to update, pass null as a value to remove it.
 */
function pods_update_settings( $setting_values ) {
	$settings = tribe( Settings::class );

	$settings->update_settings( $setting_values );
}

/**
 * Determine if Session Auto Start is enabled.
 *
 * @param bool $check_constant_only Whether to only check the constant.
 *
 * @since 2.8.0
 *
 * @return bool|string|null Boolean if it is set to on or off, "auto" if set to auto, and null if $check_constant_only and constant is not set.
 */
function pods_session_auto_start( $check_constant_only = false ) {
	if ( defined( 'PODS_SESSION_AUTO_START' ) ) {
		return PODS_SESSION_AUTO_START;
	}

	if ( $check_constant_only ) {
		return null;
	}

	$auto_start = pods_get_setting( 'session_auto_start', 'auto' );

	// Check for "auto" string and return that.
	if ( 'auto' === $auto_start ) {
		return $auto_start;
	}

	return FILTER_VAR( $auto_start, FILTER_VALIDATE_BOOLEAN );
}

/**
 * Determine if we can use PHP sessions.
 *
 * @since 2.8.0
 *
 * @param bool $only_env_check Whether to ignore constant/option/logged in checks.
 *
 * @return bool Whether we can use sessions.
 */
function pods_can_use_sessions( $only_env_check = false ) {
	// Maybe check non-environment factors.
	if ( ! $only_env_check ) {
		// We do not need a session ID if there is a valid user logged in.
		if ( is_user_logged_in() ) {
			return false;
		}

		// Allow for bypassing Pods session auto-starting.
		if ( ! pods_session_auto_start() ) {
			return false;
		}
	}

	if ( ! defined( 'PANTHEON_SESSIONS_ENABLED' ) || ! PANTHEON_SESSIONS_ENABLED ) {
		// We aren't using Pantheon WP Native Sessions plugin so let's check if the normal session will work.

		$save_path = session_save_path();

		if ( ! function_exists( 'session_status' ) || PHP_SESSION_DISABLED === session_status() ) {
			// Sessions are disabled.
			return false;
		}

		if ( 0 === strpos( $save_path, 'tcp://' ) ) {
			// Allow for non-file based sessions, like Memcache.
			// This is OK, but we don't want to check if file_exists on next statement.
		} elseif ( empty( $save_path ) || ! @file_exists( $save_path ) || ! is_writable( $save_path ) ) {
			// Check if session path exists and can be written to, avoiding PHP fatal errors.
			return false;
		}
	}

	// Allow sessions.
	return true;
}

/**
 * Safely start a new session (without white screening on certain hosts,
 * which have no session path or the path is not writable).
 *
 * @since 2.3.10
 *
 * @return boolean Whether the session was started.
 */
function pods_session_start() {
	if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
		return true;
	}

	if ( false !== headers_sent() ) {
		// Check if headers were sent.
		return false;
	}

	if ( ! pods_can_use_sessions() ) {
		return false;
	}

	// Start session
	return @session_start();
}

/**
 * Get current session ID.
 *
 * @since 2.7.23
 *
 * @return string The session ID.
 */
function pods_session_id() {
	if ( false === pods_session_start() ) {
		return '';
	}

	return @session_id();
}

/**
 * @todo  : replace string literal with a defined constant
 *
 * @return bool
 *
 * @since 2.7.0
 */
function pods_is_modal_window() {
	$is_modal_window = false;

	if ( ! empty( $_GET['pods_modal'] ) || ! empty( $_POST['pods_modal'] ) ) {
		$is_modal_window = true;
	}

	return $is_modal_window;
}

/**
 * Check if the pod object is valid and the pod exists.
 *
 * @param Pods|mixed $pod The pod object or something that isn't a pod object
 *
 * @return bool Whether the pod object is valid and exists
 *
 * @since 2.7.0
 */
function pod_is_valid( $pod ) {
	$is_valid = false;

	if ( $pod && $pod instanceof Pods && $pod->valid() ) {
		$is_valid = true;
	}

	return $is_valid;
}

/**
 * Check if the pod object has item(s).
 *
 * @param Pods|mixed $pod The pod object or something that isn't a pod object
 *
 * @return bool Whether the pod object has items
 *
 * @since 2.7.0
 */
function pod_has_items( $pod ) {
	$has_items = false;

	if ( pod_is_valid( $pod ) && ( $pod->id && $pod->exists() ) || ( ! empty( $pod->params ) && 0 < $pod->total() ) ) {
		$has_items = true;
	}

	return $has_items;
}

/**
 * Merge one config into another for purposes of overriding certain arguments.
 *
 * @since 2.8.0
 *
 * @param array|Field $config_to_merge_into The config to merge into.
 * @param array|Field $config_to_merge_from The config to merge from.
 *
 * @return array|Field The final config result.
 */
function pods_config_merge_data( $config_to_merge_into, $config_to_merge_from ) {
	// The configs already match.
	if ( $config_to_merge_into === $config_to_merge_from ) {
		return $config_to_merge_into;
	}

	// Merge the config into the destination config if both are Value_Field.
	if ( $config_to_merge_into instanceof Value_Field && $config_to_merge_from instanceof Value_Field ) {
		$field_object_into = $config_to_merge_into->get_field_object();
		$field_value_into  = $config_to_merge_into->get_field_value();

		$field_object_from = $config_to_merge_from->get_field_object();
		$field_value_from  = $config_to_merge_from->get_field_value();

		$config_to_merge_into->set_field_object( pods_config_merge_data( $field_object_into, $field_object_from ) );
		$config_to_merge_into->set_field_value( $field_value_from );

		return $config_to_merge_into;
	}

	// Merge the config into the destination config.
	if ( $config_to_merge_into instanceof Whatsit ) {
		return $config_to_merge_into->set_args( $config_to_merge_from );
	}

	// Merge the destination config into the config but don't replace data.
	if ( $config_to_merge_from instanceof Whatsit ) {
		return $config_to_merge_from->set_args( $config_to_merge_into, false );
	}

	// The config was not an array.
	if ( ! is_array( $config_to_merge_from ) ) {
		return $config_to_merge_into;
	}

	// The config was not an array.
	if ( ! is_array( $config_to_merge_into ) ) {
		return $config_to_merge_from;
	}

	// Merge the config arrays together.
	return array_merge( $config_to_merge_into, $config_to_merge_from );
}

/**
 * Merge multiple configs into others for purposes of overriding certain arguments.
 *
 * @since 2.8.0
 *
 * @param array[]|Field[] $configs_to_merge_into The configs to merge into.
 * @param array[]|Field[] $configs_to_merge_from The configs to merge from.
 *
 * @return array[]|Field[] The final config results.
 */
function pods_config_merge_fields( $configs_to_merge_into, $configs_to_merge_from ) {
	// The configs already match.
	if ( $configs_to_merge_into === $configs_to_merge_from ) {
		return $configs_to_merge_into;
	}

	foreach ( $configs_to_merge_from as $key => $config_to_merge_from ) {
		if ( ! isset( $config_to_merge_into[ $key ] ) ) {
			$configs_to_merge_into[ $key ] = $config_to_merge_from;

			continue;
		}

		$configs_to_merge_into[ $key ] = pods_config_merge_data( $config_to_merge_into[ $key ], $config_to_merge_from );
	}

	return $configs_to_merge_into;
}

/**
 * Get the list of all fields, including object fields, from a Pod configuration.
 *
 * @since 2.8.0
 *
 * @param array|Pod|Pods $pod The Pod configuration array or object.
 *
 * @return array[]|Field[] The list of all fields, including object fields.
 */
function pods_config_get_all_fields( $pod ) {
	if ( $pod instanceof Pod ) {
		return $pod->get_all_fields();
	}

	$fields        = (array) pods_v( 'fields', $pod, [] );
	$object_fields = (array) pods_v( 'object_fields', $pod, [] );

	return pods_config_merge_fields( $fields, $object_fields );
}

/**
 * Get the field object from a value field object.
 *
 * @since 2.8.0
 *
 * @param Value_Field|Field $value_field The value field object.
 *
 * @return Field The field object.
 */
function pods_config_get_field_from_value_field( $value_field ) {
	// Maybe get the field object.
	if ( $value_field instanceof Value_Field ) {
		return $value_field->get_field_object();
	}

	return $value_field;
}

/**
 * Get the list of all field objects from a list of value field objects.
 *
 * @since 2.8.0
 *
 * @param Value_Field[]|Field[] $value_fields The list of value field objects.
 *
 * @return Field[] The list of all field objects.
 */
function pods_config_get_fields_from_value_fields( array $value_fields ) {
	$all_fields = [];

	foreach ( $value_fields as $key => $field ) {
		// Maybe get the field object.
		if ( $field instanceof Value_Field ) {
			$field = $field->get_field_object();
		}

		if ( is_int( $key ) ) {
			$all_fields[] = $field;
		} else {
			$all_fields[ $key ] = $field;
		}
	}

	return $all_fields;
}

/**
 * Get the field data for a specific field matching from all fields, including object fields, from a Pod configuration.
 *
 * @since 2.8.0
 *
 * @param string         $field The field name to get.
 * @param array|Pod|Pods $pod   The Pod configuration array or object.
 * @param null|string    $arg   The field argument to use when getting the field.
 *
 * @return array|Field|null The field data or null if not found.
 */
function pods_config_get_field_from_all_fields( $field, $pod, $arg = null ) {
	// Get the pod data from the Pods object if it's there.
	if ( $pod instanceof Pods ) {
		$pod = $pod->pod_data;
	}

	// Get the field directly from the Pod.
	if ( $pod instanceof Pod ) {
		return $pod->get_field( $field, $arg );
	}

	// The pod isn't there or valid.
	if ( empty( $pod ) ) {
		return null;
	}

	$fields        = (array) pods_v( 'fields', $pod, [] );
	$object_fields = (array) pods_v( 'object_fields', $pod, [] );

	// Return the object field.
	if ( isset( $object_fields[ $field ] ) ) {
		return $object_fields[ $field ];
	}

	// Return the pod field.
	if ( isset( $fields[ $field ] ) ) {
		return $fields[ $field ];
	}

	// No field found.
	return null;
}

/**
 * Get a normalized Pod configuration.
 *
 * @since 2.8.0
 *
 * @param Pod|Pods|string $pod The Pod configuration object, Pods() object, or name.
 *
 * @return false|Pod The Pod object.
 */
function pods_config_for_pod( $pod ) {
	if ( $pod instanceof Pod ) {
		return $pod;
	}

	if ( $pod instanceof Pods ) {
		// Check if the $pod is invalid.
		if ( ! $pod->valid() ) {
			return false;
		}

		return $pod->pod_data;
	}

	if ( is_string( $pod ) ) {
		try {
			$api = pods_api();

			$pod = $api->load_pod( [ 'name' => $pod ] );
		} catch ( Exception $exception ) {
			return false;
		}

		// Check if the $pod is invalid.
		if ( ! $pod ) {
			return false;
		}

		return $pod;
	}

	// @todo Support arrays in the future by migrating them into a Pod() object.

	return false;
}

function is_pods_alternative_cache_activated() {
	return function_exists( 'pods_alternative_cache_init' );
}

function is_pods_alternative_cache_activated_test() {
	$result = [
		'label'       => __( 'You are using the Pods Team recommended Pods Alternative Cache plugin', 'pods' ),
		'status'      => 'good',
		'badge'       => [
			'label' => __( 'Security' ),
			'color' => 'blue',
		],
		'description' => sprintf( '<p>%s</p>', __( 'The Pods Alternative Cache plugin is useful to many who use Pods.' ) ),
		'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'plugins.php' ) ), __( 'Manage your plugins' ) ),
		'test'        => 'is_pods_alternative_cache_activated',
	];

	return $result;
}

/**
 * Get the SVG icon data (base64 or svg itself) for the icon or the dashicon default class.
 *
 * @since 2.8.1
 *
 * @param string $icon_path The icon name or the SVG full file path to use.
 * @param string $default   The dashicons helper class (dashicons-database) to use if SVG not found.
 * @param string $mode      How to return the SVG (base64 or svg).
 *
 * @return string The SVG icon data (base64 or svg itself) for the icon or the dashicon default class.
 */
function pods_svg_icon( $icon_path, $default = 'dashicons-database', $mode = 'base64' ) {
	// Return the default when doing WP-CLI requests and Codeception testing.
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || function_exists( 'codecept_debug' ) ) {
		return $default;
	}

	if ( 'pods' === $icon_path ) {
		$icon_path = PODS_DIR . '/ui/images/icon-menu.svg';
	}

	$static_cache = tribe( Static_Cache::class );

	$icon = $static_cache->get( $icon_path, __FUNCTION__ . '/' . $mode );

	// If the cached icon did not exist, use default.
	if ( '404-not-exists' === $icon ) {
		return $default;
	}

	// If the cached icon was found and is not empty, return it.
	if ( is_string( $icon ) && '' !== $icon ) {
		return $icon;
	}

	/**
	 * Allow filtering the SVG icon used and bypass the normal functionality.
	 *
	 * @since 2.8.2
	 *
	 * @param null|string $icon      The icon to use.
	 * @param string      $icon_path The SVG full file path to use.
	 * @param string      $default   The dashicons helper class (dashicons-database) to use if SVG not found.
	 * @param string      $mode      How to return the SVG (base64 or svg).
	 */
	$icon = apply_filters( 'pods_svg_icon', null, $icon_path, $default, $mode );

	if ( null !== $icon ) {
		return $icon;
	}

	if ( ! file_exists( $icon_path ) ) {
		$static_cache->set( '404-not-exists', $icon, __FUNCTION__ . '/' . $mode );

		return $default;
	}

	$svg_data = file_get_contents( $icon_path );

	if ( ! $svg_data ) {
		$static_cache->set( '404-not-exists', $icon, __FUNCTION__ . '/' . $mode );

		return $default;
	}

	$static_cache->set( $icon_path, $icon, __FUNCTION__ . '/' . $mode );

	// If mode is SVG data, return that.
	if ( 'svg' === $mode ) {
		return $svg_data;
	}

	// Default mode is base64.
	return 'data:image/svg+xml;base64,' . base64_encode( $svg_data );
}
