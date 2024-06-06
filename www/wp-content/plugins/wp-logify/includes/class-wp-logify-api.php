<?php
class WP_Logify_API {
    public static function init() {
        // Hook into WordPress actions to track advanced functionalities
        add_action('save_post', [__CLASS__, 'track_post_changes']);
        add_action('user_register', [__CLASS__, 'track_user_registration']);
        // Add other hooks for advanced tracking as needed
    }

    public static function send_data_to_saas($data) {
        $api_url = 'https://your-saas-platform.com/api/track';
        $api_key = get_option('wp_logify_api_key');

        $response = wp_remote_post($api_url, [
            'method' => 'POST',
            'body' => json_encode($data),
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            // Handle error
        } else {
            // Handle successful response
        }
    }

    public static function track_post_changes($post_id) {
        $post = get_post($post_id);
        $event = (wp_is_post_revision($post_id)) ? 'Post Updated' : 'Post Added';
        $data = [
            'event' => $event,
            'object' => "Post ID: $post_id",
            'user_id' => get_current_user_id(),
            'source_ip' => $_SERVER['REMOTE_ADDR'],
            'date_time' => current_time('mysql', true)
        ];
        self::send_data_to_saas($data);
    }

    public static function track_user_registration($user_id) {
        $data = [
            'event' => 'User Registered',
            'object' => "User ID: $user_id",
            'user_id' => $user_id,
            'source_ip' => $_SERVER['REMOTE_ADDR'],
            'date_time' => current_time('mysql', true)
        ];
        self::send_data_to_saas($data);
    }
}
