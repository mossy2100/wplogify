<?php
/**
 * File doc comment
 *
 * @package WP_Logify
 */

global $wpdb;
$table_name = WP_Logify_Logger::get_table_name();

// Fetch the total activities for the last hour and last 24 hours.
$one_hour_ago          = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );
$twenty_four_hours_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );

$activities_last_hour     = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE date_time > %s", $one_hour_ago ) );
$activities_last_24_hours = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table_name WHERE date_time > %s", $twenty_four_hours_ago ) );

// Fetch the last 10 activities.
$results = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY date_time DESC LIMIT 10" );
?>

<div class="wp-logify-dashboard-widget">

<!-- Display the total activities -->
<div class="wp-logify-stats">
	<div class="wp-logify-stats-box">
		<div class="wp-logify-stats-number"><?php echo esc_html( $activities_last_hour ); ?></div>
		<div class="wp-logify-stats-title">Events in the last hour</div>
	</div>
	<div class="wp-logify-stats-box">
		<div class="wp-logify-stats-number"><?php echo esc_html( $activities_last_24_hours ); ?></div>
		<div class="wp-logify-stats-title">Events in the last 24 hours</div>
	</div>
</div>

<!-- Display the last 10 activities in a table -->
<table class="wp-logify-activity-table">
	<thead>
		<tr>
			<th>Date & Time</th>
			<th>User</th>
			<th>Event type</th>
			<!-- <th>Object</th> -->
		</tr>
	</thead>
	<tbody>
		<?php if ( $results ) : ?>
			<?php foreach ( $results as $activity ) : ?>
				<?php
					$user_profile_url = admin_url( 'user-edit.php?user_id=' . $activity->user_id );
					$username         = esc_html( WP_Logify_Admin::get_username( $activity->user_id ) );
					$user_info        = '<div class="wp-logify-user-info"><a href="' . esc_url( $user_profile_url ) . '">' . $username . '</a></div>';
				?>
				<tr>
					<td><?php echo esc_html( WP_Logify_Admin::format_datetime( $activity->date_time ) ); ?></td>
					<td><?php echo $user_info; ?></td>
					<td><?php echo esc_html( $activity->event_type ); ?></td>
					<!-- <td><?php echo esc_html( $activity->object ); ?></td> -->
				</tr>
			<?php endforeach; ?>
		<?php else : ?>
			<tr><td colspan="4">No activities found.</td></tr>
		<?php endif; ?>
	</tbody>
</table>

<!-- Add the "View all Site Activity" link -->
<ul class="wp-logify-widget-links">
	<li class="wp-logify-widget-link">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-logify' ) ); ?>">View all site activity</a>
	</li>
	<li class="wp-logify-separator">|</li>
	<li class="wp-logify-widget-link">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-logify-settings' ) ); ?>">Settings</a>
	</li>  	
</ul>

<?php
function wp_logify_dashboard_widget() {
	$access_roles = get_option( 'wp_logify_view_roles', array( 'administrator' ) );
	if ( ! current_user_has_access( $access_roles ) ) {
		return;
	}

	wp_add_dashboard_widget( 'wp_logify_dashboard_widget', 'WP Logify - Recent Site Activity', 'wp_logify_display_dashboard_widget' );
}

function wp_logify_display_dashboard_widget() {
	include plugin_dir_path( __FILE__ ) . '../templates/dashboard-widget.php';
}

function current_user_has_access( $roles ) {
	$user = wp_get_current_user();
	foreach ( $roles as $role ) {
		if ( in_array( $role, $user->roles ) ) {
			return true;
		}
	}
	return false;
}

add_action( 'wp_dashboard_setup', 'wp_logify_dashboard_widget' );