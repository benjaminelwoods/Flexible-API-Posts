<?php
/**
 * Plugin Name: Flexible API Posts
 * Plugin URI: http://beflow.studio
 * Description: Fetches data from configurable APIs and creates WordPress posts.
 * Version: 1.0.0
 * Author: Ben Elwood
 * Author URI: http://beflow.studio
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('FLEXIBLE_API_POSTS_VERSION', '1.0.0');

/**
 * The core plugin class.
 */
require plugin_dir_path(__FILE__) . 'includes/class-flexible-api-posts.php';

/**
 * Begins execution of the plugin.
 */
function run_flexible_api_posts() {
    $plugin = new Flexible_API_Posts();
    $plugin->run();
}

// Wrap the execution in a try-catch block to catch any exceptions
try {
    run_flexible_api_posts();
} catch (Exception $e) {
    // Log the error
    error_log('Flexible API Posts Plugin Error: ' . $e->getMessage());
    // Display the error
    echo 'An error occurred while activating the Flexible API Posts plugin: ' . $e->getMessage();
}

add_action('wp_ajax_get_post_fields', 'flexible_api_posts_get_post_fields');


/**
 * Handles the Get Post Fields
 */
function flexible_api_posts_get_post_fields() {
    check_ajax_referer('flexible_api_posts_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You do not have permission to perform this action.'));
    }

    $post_type = isset($_POST['post_type']) ? sanitize_text_field($_POST['post_type']) : 'post';

    $fields = array(
        'post_title',
        'post_content',
        'post_excerpt',
        'post_date',
        'post_author'
    );

    // Get custom fields (post meta)
    $post_type_object = get_post_type_object($post_type);
    if ($post_type_object) {
        $custom_fields = get_registered_meta_keys('post', $post_type);
        foreach ($custom_fields as $key => $args) {
            $fields[] = $key;
        }
    }

    // Get taxonomy fields
    $taxonomies = get_object_taxonomies($post_type);
    foreach ($taxonomies as $taxonomy) {
        $fields[] = 'taxonomy_' . $taxonomy;
    }

    wp_send_json_success(array('fields' => $fields));
}

function flexible_api_posts_schedule_event() {
    if (!wp_next_scheduled('flexible_api_posts_cron_hook')) {
        wp_schedule_event(time(), 'hourly', 'flexible_api_posts_cron_hook');
    }
}
add_action('wp', 'flexible_api_posts_schedule_event');

function flexible_api_posts_deactivation() {
    $timestamp = wp_next_scheduled('flexible_api_posts_cron_hook');
    wp_unschedule_event($timestamp, 'flexible_api_posts_cron_hook');
}
register_deactivation_hook(__FILE__, 'flexible_api_posts_deactivation');

add_action('flexible_api_posts_cron_hook', 'flexible_api_posts_fetch_and_create');

function flexible_api_posts_fetch_and_create() {
    $apis = get_option('flexible_api_posts_apis', array());
    
    if (empty($apis)) {
        error_log("No APIs configured.");
        echo "No APIs configured.\n";
        return;
    }
    
    foreach ($apis as $api_slug => $api_config) {
        echo "Processing API: $api_slug\n";
        error_log("Processing API: $api_slug");
        
        $api_handler = new API_Handler();
        $post_creator = new Post_Creator();
        
        try {
            $response = $api_handler->fetch_data($api_config);
            if ($response['success']) {
                echo "Successfully fetched data for API: $api_slug\n";
                error_log("Successfully fetched data for API: $api_slug");
                $data = $response['data'];
                $mapping = json_decode($api_config['mapping'], true);
                
                if (empty($mapping)) {
                    echo "Warning: Mapping is empty for API: $api_slug\n";
                    error_log("Warning: Mapping is empty for API: $api_slug");
                }
                
                echo "Attempting to create posts...\n";
                error_log("Attempting to create posts for API: $api_slug");
                $result = $post_creator->create_posts($api_config['post_type'], $data, $mapping, $api_config['object_path']);
                echo "Posts creation result: " . print_r($result, true) . "\n";
                error_log("Posts creation result for API $api_slug: " . print_r($result, true));
            } else {
                echo "Failed to fetch data for API: $api_slug. Error: " . $response['message'] . "\n";
                error_log("Failed to fetch data for API: $api_slug. Error: " . $response['message']);
            }
        } catch (Exception $e) {
            echo "Exception occurred while processing API $api_slug: " . $e->getMessage() . "\n";
            error_log("Exception occurred while processing API $api_slug: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
    }
}

// Add this to allow manual triggering of the fetch and create process
add_action('wp_ajax_trigger_api_fetch', 'flexible_api_posts_ajax_trigger_fetch');

function flexible_api_posts_ajax_trigger_fetch() {
    check_ajax_referer('flexible_api_posts_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You do not have permission to perform this action.'));
    }
    
    try {
        ob_start(); // Start output buffering
        flexible_api_posts_fetch_and_create();
        $output = ob_get_clean(); // Get the output and clear the buffer
        
        wp_send_json_success(array(
            'message' => 'API fetch and post creation process triggered successfully.',
            'debug_output' => $output
        ));
    } catch (Exception $e) {
        wp_send_json_error(array(
            'message' => 'Error occurred during API fetch: ' . $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ));
    }
}