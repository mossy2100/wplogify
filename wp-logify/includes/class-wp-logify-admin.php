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

		$table_name = WP_Logify_Logger::get_table_name();
		$columns    = array(
			'id',
			'date_time',
			'user',
			'source_ip',
			'event_type',
			'object',
		);

		$limit        = isset( $_POST['length'] ) ? intval( $_POST['length'] ) : 10;
		$offset       = isset( $_POST['start'] ) ? intval( $_POST['start'] ) : 0;
		$order_by     = isset( $_POST['order'][0]['column'] ) ? $columns[ wp_unslash( $_POST['order'][0]['column'] ) ] : 'date_time';
		$order_dir    = isset( $_POST['order'][0]['dir'] ) && in_array( $_POST['order'][0]['dir'], array( 'asc', 'desc' ), true ) ? $_POST['order'][0]['dir'] : 'desc';
		$search_value = isset( $_POST['search']['value'] ) ? wp_unslash( $_POST['search']['value'] ) : '';

		// Get the log records from the database.
		$sql  = 'SELECT * FROM %i';
		$args = array( $table_name );

		// Filter by the search value, if provided.
		if ( ! empty( $search_value ) ) {
			$like_value = '%' . $wpdb->esc_like( $search_value ) . '%';
			$sql       .= ' WHERE date_time LIKE %s OR user_role LIKE %s OR source_ip LIKE %s OR event_type LIKE %s OR object_type LIKE %s OR details LIKE %s';
			$args       = array(
				...$args,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
				$like_value,
			);
		} else {
			$args = array();
		}

		// Add the order by parameters.
		$sql .= ' ORDER BY %i %i LIMIT %d OFFSET %d';
		$args = array( ...$args, $order_by, $order_dir, $limit, $offset );

		// Prepare the statement.
		$sql = $wpdb->prepare( $sql, $args );

		// Get the requested records.
		$results = $wpdb->get_results( $sql );

		// Get the number of results.
		$num_filtered_records = count( $results );

		// Get the total number of records in the table.
		$num_total_records = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) );

		// Construct the data array to return to the client.
		$data = array();
		foreach ( $results as $row ) {
			if ( ! empty( $row->id ) ) {
				// Date and time.
				$date_time          = WP_Logify_DateTime::create_datetime( $row->date_time );
				$formatted_datetime = WP_Logify_DateTime::format_datetime_site( $date_time );
				$time_ago           = human_time_diff( $date_time->getTimestamp() ) . ' ago';
				$row->date_time     = "<div>$formatted_datetime ($time_ago)</div>";

				// User details.
				$user             = get_userdata( $row->user_id );
				$user_profile_url = site_url( '/?author=' . $row->user_id );
				$username         = esc_html( self::get_username( $user ) );
				$user_role        = esc_html( ucwords( $row->user_role ) );
				$row->user        = get_avatar( $row->user_id, 32 )
					. ' <div class="wp-logify-user-info"><a href="' . $user_profile_url . '">'
					. $username . '</a><br><span class="wp-logify-user-role">' . $user_role
					. '</span></div>';

				// Source IP.
				$row->source_ip = '<a href="https://whatismyipaddress.com/ip/'
					. esc_html( $row->source_ip ) . '" target="_blank">'
					. esc_html( $row->source_ip ) . '</a>';

				// Get the object link.
				$row->object = self::get_object_link( $row );

				// Format the data.
				$row->details = self::format_details( $row->details );

				$data[] = $row;
			}
		}

		wp_send_json(
			array(
				'draw'            => intval( $_POST['draw'] ),
				'recordsTotal'    => intval( $num_total_records ),
				'recordsFiltered' => intval( $num_filtered_records ),
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
			'wp_logify_keep_forever',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
			)
		);
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_keep_period_quantity',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 1,
			)
		);
		register_setting(
			'wp_logify_settings_group',
			'wp_logify_keep_period_units',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'year',
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
		$table_name = WP_Logify_Logger::get_table_name();
		$per_page   = (int) get_user_option( 'activities_per_page', get_current_user_id(), 'edit_wp-logify' );
		if ( ! $per_page ) {
			$per_page = 20;
		}
		$total_items = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
		$paged       = isset( $_GET['paged'] ) ? max( 0, intval( $_GET['paged'] ) - 1 ) : 0;
		$offset      = $paged * $per_page;

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

		wp_add_dashboard_widget( 'wp_logify_dashboard_widget', 'WP Logify - Recent Site Activity', array( __CLASS__, 'display_dashboard_widget' ) );
	}

	/**
	 * Displays the dashboard widget for WP Logify plugin.
	 */
	public static function display_dashboard_widget() {
		include plugin_dir_path( __FILE__ ) . '../templates/dashboard-widget.php';
	}

	/**
	 * Converts a filename to a handle for WP Logify plugin.
	 *
	 * This function takes a filename and converts it into a handle by replacing dots with dashes and converting it to lowercase.
	 * The resulting handle is prefixed with 'wp-logify-'.
	 *
	 * @param string $filename The filename to convert.
	 * @return string The handle for the WP Logify plugin.
	 */
	private static function filename_to_handle( $filename ) {
		return 'wp-logify-' . strtolower( str_replace( '.', '-', pathinfo( $filename, PATHINFO_FILENAME ) ) );
	}

	/**
	 * Enqueues a stylesheet for the WP Logify plugin.
	 *
	 * @param string           $src    The source file path of the stylesheet.
	 * @param array            $deps   Optional. An array of dependencies for the stylesheet. Default is an empty array.
	 * @param string|bool|null $ver    Optional. The version number of the stylesheet.
	 *      If set to false (default), the WordPress version number will be used.
	 *      If set to 'auto', it will use the last modified time of the source file as the version.
	 *      If set to null, no version number will be added.
	 * @param string           $media  Optional. The media type for which the stylesheet is defined. Default is 'all'.
	 */
	public static function enqueue_style( $src, $deps = array(), $ver = 'auto', $media = 'all' ) {
		$handle   = self::filename_to_handle( $src );
		$src_url  = plugin_dir_url( __FILE__ ) . '../assets/css/' . $src;
		$src_path = plugin_dir_path( __FILE__ ) . '../assets/css/' . $src;
		$ver      = 'auto' === $ver ? filemtime( $src_path ) : $ver;
		wp_enqueue_style( $handle, $src_url, $deps, $ver, $media );
	}

	/**
	 * Enqueues a script in WordPress.
	 *
	 * @param string           $src The source file path of the script.
	 * @param array            $deps Optional. An array of script handles that this script depends on.
	 * @param string|bool|null $ver Optional. The version number of the script.
	 *      If set to false (default), the WordPress version number will be used.
	 *      If set to 'auto', it will use the last modified time of the source file as the version.
	 *      If set to null, no version number will be added.
	 * @param array|bool       $args Optional. Additional arguments for the script.
	 * @return void
	 */
	public static function enqueue_script( $src, $deps = array(), $ver = 'auto', $args = array() ) {
		$handle   = self::filename_to_handle( $src );
		$src_url  = plugin_dir_url( __FILE__ ) . '../assets/js/' . $src;
		$src_path = plugin_dir_path( __FILE__ ) . '../assets/js/' . $src;
		$ver      = 'auto' === $ver ? filemtime( $src_path ) : $ver;
		wp_enqueue_script( $handle, $src_url, $deps, $ver, $args );
	}

	/**
	 * Enqueues the necessary assets for the WP Logify admin pages.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		$valid_hooks = array( 'toplevel_page_wp-logify', 'wp-logify_page_wp-logify-settings', 'index.php' );
		if ( ! in_array( $hook, $valid_hooks ) ) {
			return;
		}

		// Common styles.
		self::enqueue_style( 'admin.css', array() );

		// Dashboard widget.
		if ( $hook === 'index.php' ) {
			self::enqueue_style( 'dashboard-widget.css', array() );
		}

		// Settings.
		if ( $hook === 'wp-logify_page_wp-logify-settings' ) {
			// self::enqueue_style( 'settings.css', array() );
		}

		// Main activity log styles.
		if ( $hook === 'toplevel_page_wp-logify' ) {
			// Styles.
			self::enqueue_style( 'log.css', array() );

			// Scripts.
			self::enqueue_script( 'admin.js', array( 'jquery' ), 'auto', true );
			// The handle here must match the handle of the JS script to attach to.
			// So we must remember that the self::enqueue_script() method prepends 'wp-logify-' to the handle.
			wp_localize_script( 'wp-logify-admin', 'wpLogifyAdmin', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) );

			// Enqueue DataTables assets.
			self::enqueue_style( 'jquery.dataTables.min.css', array(), null );
			self::enqueue_script( 'jquery.dataTables.min.js', array( 'jquery' ), null, true );
		}
	}

	/**
	 * Retrieves the username associated with a given user ID.
	 *
	 * @param int|WP_User $user The ID of the user or the user object.
	 * @return string The username if found, otherwise 'Unknown'.
	 */
	public static function get_username( int|WP_User $user ) {
		// Load the user if necessary.
		if ( is_int( $user ) ) {
			$user = get_userdata( $user );
		}

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

		if ( $screen === null ) {
			return;
		}

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
	 * Resets logs by truncating the wp_logify_events table and redirects to the settings page.
	 *
	 * @return void
	 */
	public static function reset_logs() {
		global $wpdb;
		$table_name = WP_Logify_Logger::get_table_name();
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

	/**
	 * Formats the details of a log entry.
	 *
	 * @param string $details The details of the log entry as a JSON string.
	 * @return string The formatted details as an HTML table.
	 */
	public static function format_details( WP_User $user, string $details ): string {
		$details_array = json_decode( $details );
		$html          = "<table class='wp_logify_details'>";
		foreach ( $details_array as $key => $value ) {
			$html .= "<tr><th>$key</th><td>$value</td></tr>";
		}
		$html .= '</table>';
		return $html;
	}

	/**
	 * Retrieves the link to an object based on its type and ID.
	 *
	 * NB: The ID will be an integer (as a string) for posts and users, and a string for themes and plugins.
	 *
	 * @param object $event The event object from the database.
	 */
	public static function get_object_link( object $event ) {
		// Check for valid object type.
		if ( ! in_array( $event->object_type, WP_Logify_Logger::VALID_OBJECT_TYPES, ) ) {
			throw new InvalidArgumentException( "Invalid object type: $event->object_type" );
		}

		// Check for valid object ID or name.
		if ( $event->object_id === null ) {
			throw new InvalidArgumentException( 'Object ID or name cannot be null.' );
		}

		// Generate the link based on the object type.
		switch ( $event->object_type ) {
			case 'post':
				$post = get_post( $event->object_id );

				// Get the URL based on the post status.
				switch ( $post->post_status ) {
					case 'publish':
						// View the post.
						$url = "/?p={$post->ID}";
						break;

					case 'trash':
						// List trashed posts.
						$url = '/wp-admin/edit.php?post_status=trash&post_type=post';
						break;

					default:
						// post_status should be draft or auto-draft. Go to the edit page.
						$url = "/wp-admin/post.php?post={$post->ID}&action=edit";
						break;
				}

				return "<a href='$url'>{$post->post_title}</a>";

			case 'user':
				$user = get_userdata( $event->object_id );
				return "<a href='/wp-admin/user-edit.php?user_id={$user->ID}'>{$user->display_name}</a>";

			case 'theme':
				$theme = wp_get_theme( $event->object_id );
				return "<a href='/wp-admin/theme-editor.php?theme={$theme->stylesheet}'>{$theme->name}</a>";

			case 'plugin':
				$plugin = get_plugin_data( $event->object_id );
				return $plugin['Name'];
		}
	}
}

// Initialize the plugin
WP_Logify_Admin::init();
