<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include other authentication classes
require_once plugin_dir_path(__FILE__) . 'class-api-trademe-authentication.php';

abstract class API_Authentication {
    protected $auth_data;

    public function __construct($auth_data = array()) {
        $this->auth_data = $auth_data;
    }

    abstract public function get_auth_fields();
    abstract public function prepare_request($url, $method, $headers, $body);
}

class None_Authentication extends API_Authentication {
    public function get_auth_fields() {
        return array();
    }

    public function prepare_request($url, $method, $headers, $body) {
        return array($url, $method, $headers, $body);
    }
}

class Basic_Authentication extends API_Authentication {
    public function get_auth_fields() {
        return array(
            'username' => array('label' => 'Username', 'type' => 'text'),
            'password' => array('label' => 'Password', 'type' => 'password')
        );
    }

    public function prepare_request($url, $method, $headers, $body) {
        $headers['Authorization'] = 'Basic ' . base64_encode($this->auth_data['username'] . ':' . $this->auth_data['password']);
        return array($url, $method, $headers, $body);
    }
}

class OAuth1_Authentication extends API_Authentication {
    public function get_auth_fields() {
        return array(
            'consumer_key' => array('label' => 'Consumer Key', 'type' => 'text'),
            'consumer_secret' => array('label' => 'Consumer Secret', 'type' => 'password'),
            'token' => array('label' => 'Access Token', 'type' => 'text'),
            'token_secret' => array('label' => 'Access Token Secret', 'type' => 'password'),
            'signature_method' => array(
                'label' => 'Signature Method',
                'type' => 'select',
                'options' => array(
                    'HMAC-SHA1' => 'HMAC-SHA1',
                    'RSA-SHA1' => 'RSA-SHA1',
                    'PLAINTEXT' => 'PLAINTEXT'
                )
            )
        );
    }

    public function prepare_request($url, $method, $headers, $body) {
        error_log('OAuth1 Prepare Request - Start');
        error_log('Auth Data: ' . print_r($this->auth_data, true));
    
        $oauth_params = array(
            'oauth_consumer_key' => $this->auth_data['consumer_key'],
            'oauth_token' => $this->auth_data['token'],
            'oauth_signature_method' => $this->auth_data['signature_method'],
            'oauth_timestamp' => time(),
            'oauth_nonce' => wp_generate_password(12, false),
            'oauth_version' => '1.0'
        );
    
        error_log('OAuth Params: ' . print_r($oauth_params, true));
    
        $base_string = $this->generate_signature_base_string($method, $url, array_merge($oauth_params, $body));
        error_log('Base String: ' . $base_string);
    
        $signing_key = $this->auth_data['consumer_secret'] . '&' . $this->auth_data['token_secret'];
        error_log('Signing Key: ' . $signing_key);
    
        if ($this->auth_data['signature_method'] === 'PLAINTEXT') {
            $oauth_params['oauth_signature'] = $signing_key;
        } else {
            $oauth_params['oauth_signature'] = base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));
        }
    
        error_log('OAuth Signature: ' . $oauth_params['oauth_signature']);
    
        $auth_header = 'OAuth ' . implode(', ', array_map(function($k, $v) {
            return "$k=\"" . rawurlencode($v) . "\"";
        }, array_keys($oauth_params), $oauth_params));
    
        $headers['Authorization'] = $auth_header;
    
        error_log('Final Authorization Header: ' . $auth_header);
        error_log('OAuth1 Prepare Request - End');
    
        return array($url, $method, $headers, $body);
    }

    private function generate_signature_base_string($method, $url, $params) {
        $parts = array(
            strtoupper($method),
            rawurlencode($url),
            rawurlencode(http_build_query($params))
        );
        return implode('&', $parts);
    }
}

class OAuth2_Authentication extends API_Authentication {
    public function get_auth_fields() {
        return array(
            'client_id' => array('label' => 'Client ID', 'type' => 'text'),
            'client_secret' => array('label' => 'Client Secret', 'type' => 'password'),
            'access_token' => array('label' => 'Access Token', 'type' => 'text'),
            'refresh_token' => array('label' => 'Refresh Token', 'type' => 'text'),
            'token_url' => array('label' => 'Token URL', 'type' => 'url'),
            'authorize_url' => array('label' => 'Authorize URL', 'type' => 'url')
        );
    }

    public function prepare_request($url, $method, $headers, $body) {
        $headers['Authorization'] = 'Bearer ' . $this->auth_data['access_token'];
        return array($url, $method, $headers, $body);
    }
}

class APIKey_Authentication extends API_Authentication {
    public function get_auth_fields() {
        return array(
            'api_key' => array('label' => 'API Key', 'type' => 'text'),
            'api_key_location' => array('label' => 'API Key Location', 'type' => 'select', 'options' => array(
                'query' => 'Query Parameter',
                'header' => 'Header'
            )),
            'api_key_name' => array('label' => 'API Key Name', 'type' => 'text')
        );
    }

    public function prepare_request($url, $method, $headers, $body) {
        if ($this->auth_data['api_key_location'] === 'query') {
            $url = add_query_arg($this->auth_data['api_key_name'], $this->auth_data['api_key'], $url);
        } else {
            $headers[$this->auth_data['api_key_name']] = $this->auth_data['api_key'];
        }
        return array($url, $method, $headers, $body);
    }
}

class API_Authentication_Factory {
    public static function get_auth_class($auth_type, $auth_data = array()) {
        switch ($auth_type) {
            case 'basic':
                return new Basic_Authentication($auth_data);
            case 'oauth1':
                return new OAuth1_Authentication($auth_data);
            case 'oauth2':
                return new OAuth2_Authentication($auth_data);
            case 'api_key':
                return new APIKey_Authentication($auth_data);
            case 'trademe':
                return new TradeMe_Authentication($auth_data);
            default:
                return new None_Authentication($auth_data);
        }
    }
}