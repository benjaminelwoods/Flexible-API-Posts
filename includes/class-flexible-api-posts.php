<?php

class Flexible_API_Posts {
    protected $plugin_name;
    protected $version;
    protected $admin_settings;

    public function __construct() {
        $this->plugin_name = 'flexible-api-posts';
        $this->version = FLEXIBLE_API_POSTS_VERSION;
        $this->load_dependencies();
        $this->define_admin_hooks();
        
        add_filter('cron_schedules', array($this, 'add_custom_cron_interval'));
    }

    private function load_dependencies() {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-api-handler.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-post-creator.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-api-authentication.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-admin-settings.php';
    }

    private function define_admin_hooks() {
        $this->admin_settings = new Admin_Settings($this->plugin_name, $this->version);
        add_action('admin_menu', array($this->admin_settings, 'add_plugin_admin_menu'));
    }

    public function run() {
        $this->schedule_api_fetches();
    }

    public function register_custom_post_types() {
        $apis = get_option('flexible_api_posts_apis', array());
        foreach ($apis as $api_slug => $api_config) {
            $labels = array(
                'name' => $api_config['name'],
                'singular_name' => $api_config['name'] . ' Post',
            );
            $args = array(
                'labels' => $labels,
                'public' => true,
                'has_archive' => true,
                'menu_icon' => 'dashicons-admin-site',
                'supports' => array('title', 'editor', 'custom-fields'),
            );
            register_post_type($api_slug, $args);
        }
    }

    private function schedule_api_fetches() {
        $apis = get_option('flexible_api_posts_apis', array());
        foreach ($apis as $api_slug => $api_config) {
            $schedule_name = 'fetch_api_data_' . $api_slug;
            
            // Clear existing schedule
            wp_clear_scheduled_hook($schedule_name);
    
            // Calculate interval based on rate limit
            $requests_per_day = isset($api_config['rate_limit_requests']) ? intval($api_config['rate_limit_requests']) : 1000;
            $interval_seconds = max(86400 / $requests_per_day, 60); // Minimum interval of 1 minute
    
            // Schedule new event
            if (!wp_next_scheduled($schedule_name)) {
                wp_schedule_event(time(), 'every_' . $interval_seconds . '_seconds', $schedule_name, array($api_slug));
            }
        }
    }

    public function add_custom_cron_interval($schedules) {
        $apis = get_option('flexible_api_posts_apis', array());
        foreach ($apis as $api_slug => $api_config) {
            $requests_per_day = isset($api_config['rate_limit_requests']) ? intval($api_config['rate_limit_requests']) : 1000;
            $interval_seconds = max(86400 / $requests_per_day, 60);
            $schedules['every_' . $interval_seconds . '_seconds'] = array(
                'interval' => $interval_seconds,
                'display' => sprintf(__('Every %d seconds', 'flexible-api-posts'), $interval_seconds)
            );
        }
        return $schedules;
    }

    public function fetch_and_create_posts($api_slug) {
        $api_handler = new API_Handler();
        $post_creator = new Post_Creator();
    
        $apis = get_option('flexible_api_posts_apis', array());
        $api_config = $apis[$api_slug];
    
        $response = $api_handler->fetch_data($api_config);
        if ($response['success']) {
            $data = $response['data'];
            $mapping = json_decode($api_config['mapping'], true);
            $post_creator->create_posts($api_slug, $data, $mapping);
        } else {
            error_log("Failed to fetch data for API: $api_slug. Error: " . $response['message']);
        }
    }

    public function get_plugin_name() {
        return $this->plugin_name;
    }

    public function get_version() {
        return $this->version;
    }
}