<?php
/**
 * Class WP_Logify_Post_Events
 *
 * This class provides basic tracking functionalities for WordPress.
 * It tracks changes to posts and user logins.
 */
class WP_Logify_Post_Events {
	/**
	 * Link the events we want to log to methods.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'track_post_save' ), 10, 3 );
		add_action( 'delete_post', array( __CLASS__, 'track_post_delete' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'track_post_trash' ), 10, 2 );
		add_action( 'draft_to_publish', array( __CLASS__, 'track_post_publish' ), 10, 1 );
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

		// Get the author.
		$author = get_userdata( $post->post_author );

		// Create the details array.
		return array(
			'Post'      => "<a href='/?p={$post->ID}'>{$post->post_title}</a>",
			'Post ID'   => $post->ID,
			'Post type' => $post->post_type,
			'Author'    => "<a href='/?author={$post->post_author}'>{$author->display_name}</a>",
			'Status'    => $post->post_status,
			'Created'   => WP_Logify_DateTime::format_datetime_site( WP_Logify_DateTime::create_datetime( $post->post_date ) ),
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
		debug_log( $revisions );

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
		$event_type = $creating ? 'Post Created' : 'Post Updated';

		// If updating, provide a link to the revision comparison page.
		if ( ! $creating ) {
			$details['View changes'] = "<a href='" . admin_url( "/revision.php?revision={$revision->ID}" ) . "'>Revision {$revision->ID}</a>";
		}

		// Log the event.
		WP_Logify_Logger::log_event( $event_type, 'post', $parent->ID, $details );

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

		// Collect details.
		$details = self::get_post_details( $post );

		// Log the event.
		WP_Logify_Logger::log_event( 'Post Deleted', 'post', $post_id, $details );

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

		// Collect details.
		$details                    = self::get_post_details( $post_id );
		$details['Previous status'] = $previous_status;

		// Log the event.
		WP_Logify_Logger::log_event( 'Post Trashed', 'post', $post_id, $details );

		// Set a flag to prevent duplicate logging.
		$_SESSION['post event logged'] = true;
	}

	/**
	 * Log the publishing of a post.
	 *
	 * @param WP_Post $post The post object that was published.
	 */
	public static function track_post_publish( WP_Post $post ) {
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

		// Log the event.
		WP_Logify_Logger::log_event( 'Post Published', 'post', $post->ID, $details );

		// Set a flag to prevent duplicate logging.
		$_SESSION['post event logged'] = true;
	}
}
