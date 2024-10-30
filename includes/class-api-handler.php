<?php

class API_Handler {
    private $rate_limit_remaining;
    private $rate_limit_reset;

    public function fetch_data($api_config) {
        $url = $api_config['url'];
        $method = $api_config['method'];
        $headers = json_decode($api_config['headers'], true) ?: array();
        $body = json_decode($api_config['body'], true) ?: array();
    
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => 30,
        );
    
        $response = wp_remote_request($url, $args);
    
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
                'response_code' => null,
                'data' => null,
            );
        }
    
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
    
        if ($response_code < 200 || $response_code >= 300) {
            return array(
                'success' => false,
                'message' => "HTTP Error: $response_code",
                'response_code' => $response_code,
                'data' => $response_body,
            );
        }
    
        $data = json_decode($response_body, true);
    
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'message' => 'Invalid JSON response',
                'response_code' => $response_code,
                'data' => $response_body,
            );
        }
    
        return array(
            'success' => true,
            'message' => 'API request successful',
            'response_code' => $response_code,
            'data' => $data,
        );
    }
    
    private function get_header_value($headers, $key) {
        if (is_array($headers)) {
            return isset($headers[$key]) ? $headers[$key] : null;
        } elseif (is_object($headers) && method_exists($headers, 'get')) {
            return $headers->get($key);
        } elseif (is_object($headers) && method_exists($headers, 'getAll')) {
            $values = $headers->getAll($key);
            return !empty($values) ? $values[0] : null;
        }
        return null;
    }

    private function check_rate_limit($api_slug, $wait_time = 10) {
        $last_request_time = get_transient('api_last_request_' . $api_slug);
        if ($last_request_time !== false) {
            $time_since_last_request = time() - $last_request_time;
            if ($time_since_last_request < $wait_time) {
                throw new Exception("Rate limit exceeded. Please wait " . ($wait_time - $time_since_last_request) . " seconds before trying again.");
            }
        }
        set_transient('api_last_request_' . $api_slug, time(), 60);
    }

    private function get_auth_class($auth_type, $auth_data) {
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