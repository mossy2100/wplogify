<?php
/**
 * Contains the Posts class.
 *
 * @package WP_Logify
 */

namespace WP_Logify;

use DateTime;
use WP_Post;

/**
 * Class WP_Logify\Posts
 *
 * Provides tracking of events related to posts.
 */
class Posts {
	/**
	 * Link the events we want to log to methods.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'track_post_save' ), 10, 3 );
		add_action( 'delete_post', array( __CLASS__, 'track_post_delete' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'track_post_trash' ), 10, 2 );
		add_action( 'draft_to_publish', array( __CLASS__, 'track_post_publish' ), 10, 1 );
		add_action( 'publish_to_draft', array( __CLASS__, 'track_post_unpublish' ), 10, 1 );
	}

	/**
	 * Get the singular name of a custom post type.
	 *
	 * @param string $post_type The post type.
	 * @return string The singular name of the post type.
	 */
	public static function get_post_type_singular_name( string $post_type ): string {
		$post_type_object = get_post_type_object( $post_type );
		if ( $post_type_object && isset( $post_type_object->labels->singular_name ) ) {
			return $post_type_object->labels->singular_name;
		}
		return '';
	}

	/**
	 * Get the datetime a post was created.
	 *
	 * This function ignores the post_date and post_date_gmt fields in the parent post record, which
	 * seem to show the last time the post was updated, not the time it was created.
	 *
	 * @param WP_Post $post The post object.
	 * @return DateTime The datetime the post was created.
	 */
	public static function get_post_created_datetime( WP_Post $post ): DateTime {
		global $wpdb;
		$table_name       = $wpdb->prefix . 'posts';
		$sql              = $wpdb->prepare(
			"SELECT MIN(post_date) FROM %i WHERE (ID=%d OR post_parent=%d) AND post_date != '0000-00-00 00:00:00'",
			$table_name,
			$post->ID,
			$post->ID
		);
		$created_datetime = $wpdb->get_var( $sql );
		return DateTimes::create_datetime( $created_datetime );
	}

	/**
	 * Get the datetime a post was last modified.
	 *
	 * @param WP_Post $post The post object.
	 * @return DateTime The datetime the post was last modified.
	 */
	public static function get_post_last_modified_datetime( WP_Post $post ): DateTime {
		global $wpdb;
		$table_name             = $wpdb->prefix . 'posts';
		$sql                    = $wpdb->prepare(
			"SELECT MAX(post_modified) FROM %i WHERE (ID=%d OR post_parent=%d) AND post_modified != '0000-00-00 00:00:00'",
			$table_name,
			$post->ID,
			$post->ID
		);
		$last_modified_datetime = $wpdb->get_var( $sql );
		return DateTimes::create_datetime( $last_modified_datetime );
	}

	/**
	 * Get the details of a post to show in the log.
	 *
	 * @param WP_Post|int $post The post object or ID.
	 * @return array The details of the post.
	 */
	private static function get_post_details( WP_Post|int $post ): array {
		// Load the post if necessary.
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}

		// Create the details array.
		return array(
			'Post ID'       => $post->ID,
			'Post type'     => $post->post_type,
			'Author'        => Users::get_user_profile_link( $post->post_author ),
			'Status'        => $post->post_status,
			'Created'       => DateTimes::format_datetime_site( self::get_post_created_datetime( $post ), true ),
			'Last modified' => DateTimes::format_datetime_site( self::get_post_last_modified_datetime( $post ), true ),
		);
	}

	/**
	 * Log the creation and update of a post.
	 *
	 * @param int     $post_id The ID of the post being saved.
	 * @param WP_Post $post The post object.
	 * @param bool    $update Whether this is an update or a new post.
	 */
	public static function track_post_save( $post_id, $post, $update ) {
		// Check we haven't already logged an event.
		if ( ! empty( $_SESSION['post event logged'] ) ) {
			return;
		}

		// Ignore autosave events.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check if the post is published or updated.
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}

		// Ensure this is not a trash event, which we'll record separately.
		if ( $post->post_status === 'trash' ) {
			return;
		}

		// Only look at revisions.
		if ( ! wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Clearly name the revision and parent post.
		$revision = $post;
		$parent   = get_post( $post->post_parent );

		// Collect details.
		$details = self::get_post_details( $parent );

		// Get all revisions for the parent post.
		$revisions = wp_get_post_revisions( $parent );

		// Determine if this revision is the first save.
		$creating = true;
		foreach ( $revisions as $revision2 ) {
			// Ignore autosaves.
			if ( $revision2->post_status === 'inherit' && strpos( $revision2->post_name, 'autosave' ) !== false ) {
				continue;
			}
			// Check if there are any revisions that are not auto-drafts, and not equal to the
			// revision just saved. If so, the user is creating a new post.
			if ( $revision2->post_status !== 'auto-draft' && $revision2->ID !== $revision->ID ) {
				$creating = false;
				break;
			}
		}

		// Get the event type.
		$event_type = self::get_post_type_singular_name( $parent->post_type ) . ' ' . ( $creating ? 'Created' : 'Updated' );

		// If updating, provide a link to the revision comparison page.
		if ( ! $creating ) {
			$details['Changes'] = "<a href='" . admin_url( "/revision.php?revision={$revision->ID}" ) . "'>Compare revisions</a>";
		}

		// Log the event.
		Logger::log_event( $event_type, 'post', $parent->ID, $parent->post_title, $details );

		// Set a flag to prevent duplicate logging.
		$_SESSION['post event logged'] = true;
	}

	/**
	 * Log the deletion of an existing post.
	 *
	 * @param int     $post_id The ID of the post that was deleted.
	 * @param WP_Post $post The post object that was deleted.
	 */
	public static function track_post_delete( int $post_id, WP_Post $post ) {
		// Check we haven't already logged this event.
		if ( ! empty( $_SESSION['post event logged'] ) ) {
			return;
		}

		// Ensure this is not a revision.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Get the event type.
		$event_type = self::get_post_type_singular_name( $post->post_type ) . ' Deleted';

		// Collect details.
		$details = self::get_post_details( $post );

		// Update a couple of details to show the changed status of the post.
		$details['Status']        = 'delete';
		$details['Last modified'] = DateTimes::format_datetime_site( 'now', true );

		// Log the event.
		Logger::log_event( $event_type, 'post', $post_id, $post->post_title, $details );

		// Set a flag to prevent duplicate logging.
		$_SESSION['post event logged'] = true;
	}

	/**
	 * Log the deletion of an existing post.
	 *
	 * @param int    $post_id The ID of the post that was deleted.
	 * @param string $previous_status The previous status of the post.
	 */
	public static function track_post_trash( int $post_id, string $previous_status ) {
		// Check we haven't already logged this event.
		if ( ! empty( $_SESSION['post event logged'] ) ) {
			return;
		}

		// Ensure this is not a revision.
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Load the post.
		$post = get_post( $post_id );

		// Collect details.
		$details                    = self::get_post_details( $post );
		$details['Previous status'] = $previous_status;

		// Get the event type.
		$event_type = self::get_post_type_singular_name( $post->post_type ) . ' Trashed';

		// Log the event.
		Logger::log_event( $event_type, 'post', $post_id, $post->post_title, $details );

		// Set a flag to prevent duplicate logging.
		$_SESSION['post event logged'] = true;
	}

	/**
	 * Log the publishing of a post.
	 *
	 * @param WP_Post $post The post object that was published.
	 * @param bool    $publish Whether the post was published or unpublished (default: true).
	 */
	public static function track_post_publish( WP_Post $post, bool $publish = true ) {
		// Check we haven't already logged this event.
		if ( ! empty( $_SESSION['post event logged'] ) ) {
			return;
		}

		// Ensure this is not a revision.
		if ( wp_is_post_revision( $post ) ) {
			return;
		}

		// Collect details.
		$details = self::get_post_details( $post );

		// Get the event type.
		$event_type = self::get_post_type_singular_name( $post->post_type ) . ( $publish ? ' Published' : ' Unpublished' );

		// Log the event.
		Logger::log_event( $event_type, 'post', $post->ID, $post->post_title, $details );

		// Set a flag to prevent duplicate logging.
		$_SESSION['post event logged'] = true;
	}

	/**
	 * Log the unpublishing of a post.
	 *
	 * @param WP_Post $post The post object that was published.
	 */
	public static function track_post_unpublish( WP_Post $post ) {
		self::track_post_publish( $post, false );
	}
}