<?php
/**
 * Class WP_Logify_Admin
 *
 * This class handles the WP Logify admin functionality.
 */
class WP_Logify_Admin {

	/**
	 * Initializes the WP Logify admin functionality.
	 *
	 * This method adds various actions and filters to set up the WP Logify plugin
	 * in the WordPress admin area.
	 *
	 * admin area.
	 *
	 * It registers the admin menu, settings, dashboard widget, assets, screen option,
	 * AJAX endpoint, access restriction, and log reset functionality.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_dashboard_widget' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'set-screen-option', array( __CLASS__, 'set_screen_option' ), 10, 3 );
		add_action( 'wp_ajax_wp_logify_fetch_logs', array( __CLASS__, 'fetch_logs' ) );
		add_action( 'admin_init', array( __CLASS__, 'restrict_access' ) );
		add_action( 'admin_post_wp_logify_reset_logs', array( __CLASS__, 'reset_logs' ) );
	}

	/**
	 * Fetches logs from the database based on the provided search criteria.
	 *
	 * @return void
	 */
	public static function fetch_logs() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'wp_logify_activities';
		$columns    = array(
			'id',
			'date_time',
			'user_id',
			'user_role',
			'source_ip',
			'event',
			'object',
			'editor',
		);

		$limit        = isset( $_POST['length'] ) ? intval( $_POST['length'] ) : 10;
		$offset       = isset( $_POST['start'] ) ? intval( $_POST['start'] ) : 0;
		$order_by     = $columns[ $_POST['order'][0]['column'] ] ?? 'date_time';
		$order        = isset( $_POST['order'][0]['dir'] ) && in_array( $_POST['order'][0]['dir'], array( 'asc', 'desc' ) ) ? $_POST['order'][0]['dir'] : 'desc';
		$search_value = $_POST['search']['value'] ?? '';
		// $search_value_like = '%' . $wpdb->esc_like($search_value) . '%';

		echo "search value = $search_value\n";
		echo 'escaped search value = ' . $wpdb->esc_like( $search_value ) . "\n";

		// Get the log records from the database.
		$sql = "SELECT * FROM $table_name";

		echo $sql . "\n";

		// Filter by the search value, if provided.
		if ( ! empty( $search_value ) ) {
			$like_value = '%' . $wpdb->esc_like( $search_value ) . '%';

			echo "$like_value\n";

			$sql .= $wpdb->prepare(
				' WHERE id LIKE %s OR date_time LIKE %s OR user_id LIKE %s OR user_role LIKE %s OR source_ip LIKE %s OR event LIKE %s OR object LIKE %s OR editor LIKE %s',
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value
			);
		}

		echo $sql . "\n";

		// Add the order by parameters.
		$sql .= " ORDER BY $order_by $order LIMIT %d OFFSET %d";
		$sql  = $wpdb->prepare( $sql, $limit, $offset );

		echo $sql . "\n";

		// Get the requested records.
		$results = $wpdb->get_results( $sql, ARRAY_A );

		// Get the total record count.
		$total_records = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

		// Get the record count after filtering on the search value.
		if ( empty( $search_value ) ) {
			$filtered_records = $total_records;
		} else {
			$filtered_sql     = "SELECT COUNT(*) FROM $table_name WHERE id LIKE %s OR date_time LIKE %s OR user_id LIKE %s OR user_role LIKE %s OR source_ip LIKE %s OR event LIKE %s OR object LIKE %s OR editor LIKE %s";
			$filtered_sql     = $wpdb->prepare(
				$filtered_sql,
				'%' . $wpdb->esc_like( $search_value ) . '%',
				'%' . $wpdb->esc_like( $search_value ) . '%',
				'%' . $wpdb->esc_like( $search_value ) . '%',
				'%' . $wpdb->esc_like( $search_value ) . '%',
				'%' . $wpdb->esc_like( $search_value ) . '%',
				'%' . $wpdb->esc_like( $search_value ) . '%',
				'%' . $wpdb->esc_like( $search_value ) . '%',
				'%' . $wpdb->esc_like( $search_value ) . '%'
			);
			$filtered_records = $wpdb->get_var( $filtered_sql );
		}

		$data = array();
		foreach ( $results as $row ) {
			if ( ! empty( $row['id'] ) ) {
				$date_time        = new DateTime( $row['date_time'], new DateTimeZone( wp_timezone_string() ) );
				$time_ago         = human_time_diff( $date_time->getTimestamp(), current_time( 'timestamp' ) ) . ' ago';
				$time_format      = $date_time->format( get_option( 'time_format' ) );
				$date_format      = $date_time->format( get_option( 'date_format' ) );
				$row['date_time'] = "<div>$time_ago</div><div>$time_format</div><div>$date_format</div>";

				$user_profile_url = admin_url( 'user-edit.php?user_id=' . $row['user_id'] );
				$username         = esc_html( self::get_username( $row['user_id'] ) );
				$user_role        = esc_html( ucwords( $row['user_role'] ) );
				$row['user']      = get_avatar( $row['user_id'], 32 ) . ' <div class="wp-logify-user-info"><a href="' . $user_profile_url . '">' . $username . '</a><br><span class="wp-logify-user-role">' . $user_role . '</span></div>';
				$row['source_ip'] = '<a href="https://whatismyipaddress.com/ip/' . esc_html( $row['source_ip'] ) . '" target="_blank">' . esc_html( $row['source_ip'] ) . '</a>';
				$data[]           = $row;
			}
		}

		wp_send_json(
			array(
				'draw'            => intval( $_POST['draw'] ),
				'recordsTotal'    => intval( $total_records ),
				'recordsFiltered' => intval( $filtered_records ),
				'data'            => $data,
			)
		);
	}

	/**
	 * Adds the admin menu for the WP Logify plugin.
	 *
	 * This method adds the main menu page and submenu pages for the WP Logify plugin
	 * in the WordPress admin dashboard. It also checks the user's access roles and
	 * only displays the menu if the current user has the required access.
	 *
	 * @return void
	 */
	public static function add_admin_menu() {
		$access_roles = get_option( 'wp_logify_view_roles', array( 'administrator' ) );
		if ( ! self::current_user_has_access( $access_roles ) ) {
			return;
		}

		$hook = add_menu_page( 'WP Logify', 'WP Logify', 'manage_options', 'wp-logify', array( __CLASS__, 'display_log_page' ), 'dashicons-list-view' );
		add_submenu_page( 'wp-logify', 'Log', 'Log', 'manage_options', 'wp-logify', array( __CLASS__, 'display_log_page' ) );
		add_submenu_page( 'wp-logify', 'Settings', 'Settings', 'manage_options', 'wp-logify-settings', array( __CLASS__, 'display_settings_page' ) );
		add_action( "load-$hook", array( __CLASS__, 'add_screen_options' ) );
	}

	/**
	 * Registers the settings for the WP Logify plugin.
	 */
	public static function register_settings() {
		register_setting( 'wp_logify_settings_group', 'wp_logify_api_key' );
		register_setting( 'wp_logify_settings_group', 'wp_logify_delete_on_uninstall' );
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_roles_to_track',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'wp_logify_sanitize_roles' ),
				'default'           => array( 'administrator' ),
			)
		);
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_view_roles',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'wp_logify_sanitize_roles' ),
				'default'           => array( 'administrator' ),
			)
		);
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_keep_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 30,
			)
		);
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_wp_cron_tracking',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
	}

	/**
	 * Sanitizes the given array of roles by filtering out any invalid roles.
	 *
	 * @param array $roles The array of roles to be sanitized.
	 * @return array The sanitized array of roles.
	 */
	public static function wp_logify_sanitize_roles( $roles ) {
		$valid_roles = array_keys( wp_roles()->roles );
		return array_filter(
			$roles,
			function ( $role ) use ( $valid_roles ) {
				return in_array( $role, $valid_roles );
			}
		);
	}

	/**
	 * Adds screen options for the WP Logify plugin.
	 *
	 * This function adds a screen option for the number of activities to display per page in the
	 * WP Logify plugin.
	 *
	 * @return void
	 */
	public static function add_screen_options() {
		$option = 'per_page';
		$args   = array(
			'label'   => __( 'Activities per page', 'wp-logify' ),
			'default' => 20,
			'option'  => 'activities_per_page',
		);
		add_screen_option( $option, $args );
	}

	/**
	 * Sets the screen option value for a specific option.
	 *
	 * @param mixed  $status The current status of the screen option.
	 * @param string $option The name of the screen option.
	 * @param mixed  $value The new value to set for the screen option.
	 * @return mixed The updated status of the screen option.
	 */
	public static function set_screen_option( $status, $option, $value ) {
		if ( 'activities_per_page' == $option ) {
			return $value;
		}
		return $status;
	}

	/**
	 * Display the log page.
	 *
	 * This function is responsible for displaying the log page in the WordPress admin area.
	 * It retrieves the necessary data from the database and includes the log page template.
	 *
	 * @since 1.0.0
	 */
	public static function display_log_page() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_logify_activities';
		$per_page   = (int) get_user_option( 'activities_per_page', get_current_user_id(), 'edit_wp-logify' );
		if ( ! $per_page ) {
			$per_page = 20;
		}
		// $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
		$paged = isset( $_GET['paged'] ) ? max( 0, intval( $_GET['paged'] ) - 1 ) : 0;
		// $offset = $paged * $per_page;

		include plugin_dir_path( __FILE__ ) . '../templates/log-page.php';
	}

	/**
	 * Displays the settings page for the WP Logify plugin.
	 *
	 * This method includes the settings-page.php template file to render the settings page.
	 *
	 * @return void
	 */
	public static function display_settings_page() {
		include plugin_dir_path( __FILE__ ) . '../templates/settings-page.php';
	}

	/**
	 * Adds a dashboard widget for WP Logify plugin.
	 *
	 * This function checks the user's access roles and adds the dashboard widget only if the user
	 * has the required access.
	 *
	 * The dashboard widget displays recent site activities.
	 *
	 * @return void
	 */
	public static function add_dashboard_widget() {
		$access_roles = get_option( 'wp_logify_view_roles', array( 'administrator' ) );
		if ( ! self::current_user_has_access( $access_roles ) ) {
			return;
		}

		wp_add_dashboard_widget( 'wp_logify_dashboard_widget', 'WP Logify - Recent Site Activities', array( __CLASS__, 'display_dashboard_widget' ) );
	}

	/**
	 * Displays the dashboard widget for WP Logify plugin.
	 */
	public static function display_dashboard_widget() {
		include plugin_dir_path( __FILE__ ) . '../templates/dashboard-widget.php';
	}

	/**
	 * Enqueues the necessary assets for the WP Logify admin pages.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( $hook != 'toplevel_page_wp-logify' && $hook != 'wp-logify_page_wp-logify-settings' ) {
			return;
		}
		wp_enqueue_style( 'wp-logify-admin-css', plugin_dir_url( __FILE__ ) . '../assets/css/admin.css' );
		wp_enqueue_script( 'wp-logify-admin-js', plugin_dir_url( __FILE__ ) . '../assets/js/admin.js', array( 'jquery' ), null, true );

		// Enqueue DataTables CSS and JS from local files.
		wp_enqueue_style( 'datatables-css', plugin_dir_url( __FILE__ ) . '../assets/css/jquery.dataTables.min.css' );
		wp_enqueue_script( 'datatables-js', plugin_dir_url( __FILE__ ) . '../assets/js/jquery.dataTables.min.js', array( 'jquery' ), null, true );

		// Localize script to provide ajaxurl.
		wp_localize_script( 'wp-logify-admin-js', 'wpLogifyAdmin', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );
	}

	/**
	 * Formats a given datetime string into the specified date and time format.
	 *
	 * @param string $datetime The datetime string to format.
	 * @return string The formatted datetime string.
	 */
	private static function format_datetime( $datetime ) {
		$timestamp   = strtotime( $datetime );
		$timezone    = wp_timezone();
		$wp_datetime = new DateTime( 'now', $timezone );
		$wp_datetime->setTimestamp( $timestamp );
		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );
		return $wp_datetime->format( "{$date_format} {$time_format}" );
	}

	/**
	 * Retrieves the username associated with a given user ID.
	 *
	 * @param int $user_id The ID of the user.
	 * @return string The username if found, otherwise 'Unknown'.
	 */
	private static function get_username( $user_id ) {
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : 'Unknown';
	}

	/**
	 * Restricts access to the WP Logify plugin based on user roles or plugin installer status.
	 *
	 * This method checks the current screen and redirects the user to the admin dashboard if access
	 * is restricted.
	 *
	 * The access control can be set to either 'only_me' or 'user_roles' in the plugin settings.
	 * If 'only_me' is selected, only the plugin installer has access.
	 * If 'user_roles' is selected, only users with specific roles defined in the plugin settings
	 * have access.
	 *
	 * @return void
	 */
	public static function restrict_access() {
		$screen = get_current_screen();
		if ( strpos( $screen->id, 'wp-logify' ) !== false ) {
			$access_control = get_option( 'wp_logify_access_control', 'only_me' );
			if ( $access_control === 'only_me' && ! self::is_plugin_installer() ) {
				wp_redirect( admin_url() );
				exit;
			} elseif ( $access_control === 'user_roles' && ! self::current_user_has_access( get_option( 'wp_logify_view_roles', array( 'administrator' ) ) ) ) {
				wp_redirect( admin_url() );
				exit;
			}
		}
	}

	/**
	 * Resets logs by truncating the wp_logify_activities table and redirects to the settings page.
	 *
	 * @return void
	 */
	public static function reset_logs() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wp_logify_activities';
		$wpdb->query( "TRUNCATE TABLE $table_name" );
		wp_redirect( admin_url( 'admin.php?page=wp-logify-settings&reset=success' ) );
		exit;
	}

	/**
	 * Hides the plugin from the list of installed plugins based on access control settings.
	 *
	 * @param array $plugins The array of installed plugins.
	 * @return array The modified array of installed plugins.
	 */
	public static function hide_plugin_from_list( $plugins ) {
		// Retrieve the access control setting from the options.
		$access_control = get_option( 'wp_logify_access_control', 'only_me' );

		// Check if the access control is set to 'only_me' and the current user is not the plugin
		// installer.
		if ( $access_control === 'only_me' && ! self::is_plugin_installer() ) {
			// Remove the plugin from the list of installed plugins.
			unset( $plugins[ plugin_basename( __FILE__ ) ] );
		}
		// Check if the access control is set to 'user_roles' and the current user does not have
		// access based on the specified roles.
		elseif ( $access_control === 'user_roles' && ! self::current_user_has_access( get_option( 'wp_logify_view_roles', array( 'administrator' ) ) ) ) {
			// Remove the plugin from the list of installed plugins.
			unset( $plugins[ plugin_basename( __FILE__ ) ] );
		}

		// Return the modified array of installed plugins.
		return $plugins;
	}

	/**
	 * Checks if the current user has access based on their roles.
	 *
	 * @param array $roles An array of roles to check against.
	 * @return bool Returns true if the current user has any of the specified roles, false otherwise.
	 */
	private static function current_user_has_access( $roles ) {
		$user = wp_get_current_user();
		foreach ( $roles as $role ) {
			if ( in_array( $role, $user->roles ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Checks if the current user is the plugin installer.
	 *
	 * @return bool Returns true if the current user is the plugin installer, false otherwise.
	 */
	private static function is_plugin_installer() {
		$plugin_installer = get_option( 'wp_logify_plugin_installer' );
		return $plugin_installer && get_current_user_id() == $plugin_installer;
	}
}

// Initialize the plugin
WP_Logify_Admin::init();