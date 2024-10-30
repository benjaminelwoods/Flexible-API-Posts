<?php

class Admin_Settings {
    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    
        add_action('admin_init', array($this, 'register_settings'));
        add_action('wp_ajax_test_api', array($this, 'test_api'));
        add_action('wp_ajax_get_auth_fields', array($this, 'ajax_get_auth_fields'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function register_settings() {
        register_setting(
            'flexible_api_posts_options',  // Option group
            'flexible_api_posts_apis',     // Option name
            array($this, 'sanitize_apis')  // Sanitize callback
        );

        add_settings_section(
            'flexible_api_posts_section',  // ID
            'API Configurations',          // Title
            array($this, 'settings_section_callback'), // Callback
            'flexible_api_posts'           // Page
        );

        add_settings_field(
            'flexible_api_posts_apis',     // ID
            'API Configurations',          // Title
            array($this, 'apis_callback'), // Callback
            'flexible_api_posts',          // Page
            'flexible_api_posts_section'   // Section
        );
    }

    public function test_api() {
        check_ajax_referer('flexible_api_posts_nonce', 'nonce');
    
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You do not have permission to perform this action.'));
        }
    
        $api_config = array(
            'url' => sanitize_url($_POST['url']),
            'method' => sanitize_text_field($_POST['method']),
            'headers' => sanitize_textarea_field($_POST['headers']),
            'body' => sanitize_textarea_field($_POST['body']),
            'auth_type' => sanitize_text_field($_POST['auth_type']),
            'auth_data' => isset($_POST['auth_data']) ? $this->sanitize_auth_data($_POST['auth_data']) : array(),
        );
    
        try {
            $api_handler = new API_Handler();
            $response = $api_handler->fetch_data($api_config);
            
            if ($response['success']) {
                wp_send_json_success(array(
                    'message' => 'API request successful',
                    'response_code' => $response['response_code'],
                    'response_body' => $response['data'],
                ));
            } else {
                wp_send_json_error(array(
                    'message' => 'API request failed: ' . $response['message'],
                    'response_code' => $response['response_code'] ?? null,
                    'response_body' => $response['data'] ?? null,
                ));
            }
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'API test failed: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ));
        }
    }
    
    private function sanitize_auth_data($auth_data) {
        $sanitized = array();
        foreach ($auth_data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize_auth_data($value);
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }
        return $sanitized;
    }

    private function get_post_types() {
        $post_types = get_post_types(array('public' => true), 'objects');
        $options = array();
        foreach ($post_types as $post_type) {
            $options[$post_type->name] = $post_type->label;
        }
        return $options;
    }

    public function sanitize_apis($input) {
        $sanitized_input = array();
    
        if (is_array($input)) {
            foreach ($input as $api_slug => $api_config) {
                $sanitized_input[$api_slug] = array(
                    'name' => sanitize_text_field($api_config['name']),
                    'url' => esc_url_raw($api_config['url']),
                    'method' => in_array($api_config['method'], array('GET', 'POST')) ? $api_config['method'] : 'GET',
                    'headers' => sanitize_textarea_field($api_config['headers']),
                    'body' => sanitize_textarea_field($api_config['body']),
                    'frequency' => in_array($api_config['frequency'], array('hourly', 'twicedaily', 'daily')) ? $api_config['frequency'] : 'daily',
                    'mapping' => sanitize_textarea_field($api_config['mapping']),
                    'post_type' => sanitize_text_field($api_config['post_type']),
                    'object_path' => sanitize_text_field($api_config['object_path']),
                    'auth_type' => sanitize_text_field($api_config['auth_type']),
                    'auth_data' => $this->sanitize_auth_data($api_config['auth_data']),
                    'rate_limit_wait' => intval($api_config['rate_limit_wait']),
                    'rate_limit_requests' => intval($api_config['rate_limit_requests'])
                );
    
                // Validate required fields
                $required_fields = array('name', 'url', 'method', 'post_type', 'object_path', 'mapping');
                foreach ($required_fields as $field) {
                    if (empty($sanitized_input[$api_slug][$field])) {
                        add_settings_error(
                            'flexible_api_posts_options',
                            'missing_' . $field,
                            sprintf(__('The %s field is required for API "%s".', 'flexible-api-posts'), $field, $api_config['name']),
                            'error'
                        );
                    }
                }
            }
        }
    
        return $sanitized_input;
    }

    public function settings_section_callback() {
        echo '<p>Configure your API settings here.</p>';
    }

    public function apis_callback() {
        // This is where we'll render our API configurations
        $apis = get_option('flexible_api_posts_apis', array());
        ?>
        <div id="api-configs">
            <?php
            if (!empty($apis)) {
                foreach ($apis as $api_slug => $api_config) {
                    $this->render_api_config($api_slug, $api_config);
                }
            }
            ?>
        </div>
        <p class="submit">
            <button type="button" id="add-api" class="button button-secondary"><?php _e('Add New API', 'flexible-api-posts'); ?></button>
            <?php submit_button('Save Changes', 'primary', 'submit', false); ?>
            <button type="button" id="trigger-fetch" class="button button-secondary"><?php _e('Trigger API Fetch', 'flexible-api-posts'); ?></button>
        </p>
        <?php
    }

    public function add_plugin_admin_menu() {
        add_menu_page(
            'Flexible API Posts Settings',
            'Flexible API Posts',
            'manage_options',
            $this->plugin_name,
            array($this, 'display_plugin_setup_page'),
            'dashicons-admin-generic',
            6
        );
    }

    public function display_plugin_setup_page() {
        include_once('partials/admin-display.php');
    }

    public function enqueue_admin_scripts($hook) {
        // Only enqueue on our plugin's admin page
        if ($hook !== 'toplevel_page_' . $this->plugin_name) {
            return;
        }
    
        wp_enqueue_style(
            'flexible-api-posts-admin',
            plugin_dir_url(__FILE__) . 'css/flexible-api-posts-admin.css',
            array(),
            $this->version,
            'all'
        );

        wp_enqueue_script('jquery-ui-draggable');
        wp_enqueue_script('jquery-ui-droppable');
    
        wp_enqueue_script(
            'flexible-api-posts-admin',
            plugin_dir_url(__FILE__) . 'js/flexible-api-posts-admin.js',
            array('jquery', 'jquery-ui-draggable', 'jquery-ui-droppable'),
            $this->version,
            true
        );
    
        $api_config_template = $this->render_api_config('{{index}}', array(), false);
        wp_localize_script(
            'flexible-api-posts-admin',
            'flexibleApiPosts',
            array(
                'apiConfigTemplate' => $api_config_template,
                'nonce' => wp_create_nonce('flexible_api_posts_nonce')
            )
        );
    
        // For debugging
        error_log('Enqueued scripts and styles for Flexible API Posts');
        error_log('API Config Template: ' . $api_config_template);
    }

    public function register_and_build_fields() {
        register_setting($this->plugin_name, 'flexible_api_posts_apis', array($this, 'validate_apis'));

        add_settings_section(
            'flexible_api_posts_general',
            'API Configuration',
            array($this, 'flexible_api_posts_general_cb'),
            $this->plugin_name
        );

        add_settings_field(
            'flexible_api_posts_apis',
            'APIs',
            array($this, 'flexible_api_posts_apis_cb'),
            $this->plugin_name,
            'flexible_api_posts_general',
            array('label_for' => 'flexible_api_posts_apis')
        );
    }

    public function flexible_api_posts_general_cb() {
        echo '<p>Configure your APIs here. Each API will create its own custom post type.</p>';
    }

    public function flexible_api_posts_apis_cb() {
        $apis = get_option('flexible_api_posts_apis', array());
        ?>
        <div id="api-configs">
            <?php foreach ($apis as $api_slug => $api_config): ?>
                <?php $this->render_api_config($api_slug, $api_config); ?>
            <?php endforeach; ?>
        </div>
        <button type="button" id="add-api" class="button button-secondary">Add New API</button>
        <?php
        $this->enqueue_admin_scripts();
    }

    public function render_api_config($api_slug = '', $api_config = array(), $echo = true) {
        $api_config = wp_parse_args($api_config, array(
            'name' => '',
            'url' => '',
            'method' => 'GET',
            'headers' => '{}',
            'body' => '{}',
            'frequency' => 'daily',
            'mapping' => '{}',
            'auth_type' => 'none',
            'auth_data' => array(),
            'rate_limit_wait' => 10,
            'rate_limit_requests' => 1000,
            'post_type' => 'post',
            'object_path' => ''
        ));
    
        $post_types = $this->get_post_types();
    
        ob_start();
        ?>
        <div class="api-config postbox">
            <h2 class="hndle"><?php echo $api_config['name'] ? esc_html($api_config['name']) : __('New API Configuration', 'flexible-api-posts'); ?></h2>
            <div class="inside">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($api_slug); ?>-name"><?php _e('API Name', 'flexible-api-posts'); ?></label></th>
                        <td><input type="text" id="<?php echo esc_attr($api_slug); ?>-name" name="flexible_api_posts_apis[<?php echo esc_attr($api_slug); ?>][name]" value="<?php echo esc_attr($api_config['name']); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($api_slug); ?>-post_type"><?php _e('Post Type', 'flexible-api-posts'); ?></label></th>
                        <td>
                            <select id="<?php echo esc_attr($api_slug); ?>-post_type" name="flexible_api_posts_apis[<?php echo esc_attr($api_slug); ?>][post_type]" required>
                                <?php foreach ($post_types as $type => $label) : ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php selected($api_config['post_type'], $type); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($api_slug); ?>-url"><?php _e('API URL', 'flexible-api-posts'); ?></label></th>
                        <td><input type="url" id="<?php echo esc_attr($api_slug); ?>-url" name="flexible_api_posts_apis[<?php echo esc_attr($api_slug); ?>][url]" value="<?php echo esc_url($api_config['url']); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($api_slug); ?>-method"><?php _e('Method', 'flexible-api-posts'); ?></label></th>
                        <td>
                            <select id="<?php echo esc_attr($api_slug); ?>-method" name="flexible_api_posts_apis[<?php echo esc_attr($api_slug); ?>][method]" required>
                                <option value="GET" <?php selected($api_config['method'], 'GET'); ?>>GET</option>
                                <option value="POST" <?php selected($api_config['method'], 'POST'); ?>>POST</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($api_slug); ?>-headers"><?php _e('Headers', 'flexible-api-posts'); ?></label></th>
                        <td><textarea id="<?php echo esc_attr($api_slug); ?>-headers" name="flexible_api_posts_apis[<?php echo esc_attr($api_slug); ?>][headers]" rows="5" class="large-text code"><?php echo esc_textarea($api_config['headers']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($api_slug); ?>-body"><?php _e('Body', 'flexible-api-posts'); ?></label></th>
                        <td><textarea id="<?php echo esc_attr($api_slug); ?>-body" name="flexible_api_posts_apis[<?php echo esc_attr($api_slug); ?>][body]" rows="5" class="large-text code"><?php echo esc_textarea($api_config['body']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($api_slug); ?>-frequency"><?php _e('Frequency', 'flexible-api-posts'); ?></label></th>
                        <td>
                            <select id="<?php echo esc_attr($api_slug); ?>-frequency" name="flexible_api_posts_apis[<?php echo esc_attr($api_slug); ?>][frequency]" required>
                                <option value="hourly" <?php selected($api_config['frequency'], 'hourly'); ?>><?php _e('Hourly', 'flexible-api-posts'); ?></option>
                                <option value="twicedaily" <?php selected($api_config['frequency'], 'twicedaily'); ?>><?php _e('Twice Daily', 'flexible-api-posts'); ?></option>
                                <option value="daily" <?php selected($api_config['frequency'], 'daily'); ?>><?php _e('Daily', 'flexible-api-posts'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($api_slug); ?>-rate_limit_wait"><?php _e('Rate Limit Wait (seconds)', 'flexible-api-posts'); ?></label></th>
                        <td><input type="number" id="<?php echo esc_attr($api_slug); ?>-rate_limit_wait" name="flexible_api_posts_apis[<?php echo esc_attr($api_slug); ?>][rate_limit_wait]" value="<?php echo esc_attr($api_config['rate_limit_wait']); ?>" min="1"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($api_slug); ?>-rate_limit_requests"><?php _e('Rate Limit Requests', 'flexible-api-posts'); ?></label></th>
                        <td><input type="number" id="<?php echo esc_attr($api_slug); ?>-rate_limit_requests" name="flexible_api_posts_apis[<?php echo esc_attr($api_slug); ?>][rate_limit_requests]" value="<?php echo esc_attr($api_config['rate_limit_requests']); ?>" min="1"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($api_slug); ?>-auth_type"><?php _e('Authentication Type', 'flexible-api-posts'); ?></label></th>
                        <td>
                            <select id="<?php echo esc_attr($api_slug); ?>-auth_type" name="flexible_api_posts_apis[<?php echo esc_attr($api_slug); ?>][auth_type]" class="auth-type-select">
                                <option value="none" <?php selected($api_config['auth_type'], 'none'); ?>><?php _e('None', 'flexible-api-posts'); ?></option>
                                <option value="basic" <?php selected($api_config['auth_type'], 'basic'); ?>><?php _e('Basic Auth', 'flexible-api-posts'); ?></option>
                                <option value="oauth1" <?php selected($api_config['auth_type'], 'oauth1'); ?>><?php _e('OAuth 1.0a', 'flexible-api-posts'); ?></option>
                                <option value="oauth2" <?php selected($api_config['auth_type'], 'oauth2'); ?>><?php _e('OAuth 2.0', 'flexible-api-posts'); ?></option>
                                <option value="api_key" <?php selected($api_config['auth_type'], 'api_key'); ?>><?php _e('API Key', 'flexible-api-posts'); ?></option>
                                <option value="trademe" <?php selected($api_config['auth_type'], 'trademe'); ?>><?php _e('Trade Me', 'flexible-api-posts'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <table class="auth-fields" data-auth-type="<?php echo esc_attr($api_config['auth_type']); ?>">
                                <!-- Dynamic auth fields will be loaded here -->
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr($api_slug); ?>-object_path"><?php _e('Object Path', 'flexible-api-posts'); ?></label></th>
                        <td>
                            <input type="text" id="<?php echo esc_attr($api_slug); ?>-object_path" name="flexible_api_posts_apis[<?php echo esc_attr($api_slug); ?>][object_path]" value="<?php echo esc_attr($api_config['object_path']); ?>" class="regular-text" required>
                            <p class="description"><?php _e('Enter the path to the array of objects in the API response (e.g., "result.reviews" for Google Places API)', 'flexible-api-posts'); ?></p>
                        </td>
                    </tr>
                </table>
                <h3><?php _e('Field Mapping', 'flexible-api-posts'); ?></h3>
                <div class="field-mapping-container">
                    <div class="api-response-structure">
                        <h4><?php _e('API Response Structure', 'flexible-api-posts'); ?></h4>
                        <!-- This will be populated by JavaScript after an API test -->
                    </div>
                    <div class="post-fields">
                        <h4><?php _e('Post Fields', 'flexible-api-posts'); ?></h4>
                        <!-- This will be populated by JavaScript when a post type is selected -->
                    </div>
                </div>
                <textarea id="<?php echo esc_attr($api_slug); ?>-mapping" name="flexible_api_posts_apis[<?php echo esc_attr($api_slug); ?>][mapping]" rows="5" class="large-text code" required><?php echo esc_textarea($api_config['mapping']); ?></textarea>
                <p>
                    <button type="button" class="button test-api" data-slug="<?php echo esc_attr($api_slug); ?>"><?php _e('Test API', 'flexible-api-posts'); ?></button>
                    <button type="button" class="button remove-api"><?php _e('Remove API', 'flexible-api-posts'); ?></button>
                    <span class="spinner" style="float: none; display: none;"></span>
                </p>
                <div class="api-test-results" style="display: none;">
                    <h3><?php _e('API Test Results', 'flexible-api-posts'); ?></h3>
                    <pre class="api-response"></pre>
                </div>
            </div>
        </div>
        <?php
    
        $output = ob_get_clean();
            
        if ($echo) {
            echo $output;
        }
    
        return $output;
    }

    public function render_field_mapping($api_slug, $api_config) {
        ?>
        <div class="field-mapping-container">
            <div class="api-response-structure">
                <!-- This will be populated by JavaScript -->
            </div>
            <div class="post-fields">
                <!-- This will be populated based on the selected post type -->
            </div>
        </div>
        <?php
    }

    public function validate_apis($input) {
        $valid_input = array();

        foreach ($input as $api_slug => $api_config) {
            $api_slug = sanitize_key($api_slug);

            $valid_input[$api_slug] = array(
                'name' => sanitize_text_field($api_config['name']),
                'url' => esc_url_raw($api_config['url']),
                'method' => in_array($api_config['method'], array('GET', 'POST')) ? $api_config['method'] : 'GET',
                'headers' => $this->validate_json($api_config['headers'], '{}'),
                'body' => $this->validate_json($api_config['body'], '{}'),
                'frequency' => in_array($api_config['frequency'], array('hourly', 'twicedaily', 'daily')) ? $api_config['frequency'] : 'daily',
                'mapping' => $this->validate_json($api_config['mapping'], '{}')
            );
        }

        return $valid_input;
    }

    private function validate_json($json, $default = '{}') {
        $decoded = json_decode($json, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $json : $default;
    }

    public function ajax_get_auth_fields() {
        check_ajax_referer('flexible_api_posts_nonce', 'nonce');

        if (!isset($_POST['auth_type']) || !isset($_POST['api_slug'])) {
            wp_send_json_error(array('message' => 'Missing required parameters'));
            return;
        }

        $auth_type = sanitize_text_field($_POST['auth_type']);
        $api_slug = sanitize_text_field($_POST['api_slug']);

        $auth_class = API_Authentication_Factory::get_auth_class($auth_type);
        $fields = $auth_class->get_auth_fields();

        $html = '';
        foreach ($fields as $key => $field) {
            $html .= '<tr>';
            $html .= '<th scope="row"><label for="' . esc_attr($api_slug . '-' . $key) . '">' . esc_html($field['label']) . '</label></th>';
            $html .= '<td>';
            
            if ($field['type'] === 'select' && isset($field['options'])) {
                $html .= '<select id="' . esc_attr($api_slug . '-' . $key) . '" name="flexible_api_posts_apis[' . esc_attr($api_slug) . '][auth_data][' . esc_attr($key) . ']">';
                foreach ($field['options'] as $option_value => $option_label) {
                    $html .= '<option value="' . esc_attr($option_value) . '">' . esc_html($option_label) . '</option>';
                }
                $html .= '</select>';
            } else {
                $html .= '<input type="' . esc_attr($field['type']) . '" id="' . esc_attr($api_slug . '-' . $key) . '" name="flexible_api_posts_apis[' . esc_attr($api_slug) . '][auth_data][' . esc_attr($key) . ']">';
            }
            
            $html .= '</td>';
            $html .= '</tr>';
        }

        wp_send_json_success(array('fields' => $html));
    }

    private function get_auth_class($auth_type, $auth_data = array()) {
        switch ($auth_type) {
            case 'basic':
                return new Basic_Authentication($auth_data);
            case 'oauth1':
                return new OAuth1_Authentication($auth_data);
            case 'oauth2':
                return new OAuth2_Authentication($auth_data);
            case 'api_key':
                return new APIKey_Authentication($auth_data);
            default:
                return new None_Authentication($auth_data);
        }
    }
}