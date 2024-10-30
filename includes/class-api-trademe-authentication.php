<?php

class TradeMe_Authentication extends API_Authentication {
    public function get_auth_fields() {
        return array(
            'consumer_key' => array('label' => 'Consumer Key', 'type' => 'text'),
            'consumer_secret' => array('label' => 'Consumer Secret', 'type' => 'password'),
            'token' => array('label' => 'Access Token', 'type' => 'text'),
            'token_secret' => array('label' => 'Access Token Secret', 'type' => 'password'),
            'auth_type' => array(
                'label' => 'Authentication Type',
                'type' => 'select',
                'options' => array(
                    'application' => 'Application Authenticated',
                    'member' => 'Member Authenticated'
                )
            )
        );
    }

    public function prepare_request($url, $method, $headers, $body) {
        $auth_params = array(
            'oauth_consumer_key' => $this->auth_data['consumer_key'],
            'oauth_signature_method' => 'PLAINTEXT',
            'oauth_timestamp' => time(),
            'oauth_nonce' => wp_generate_password(12, false),
            'oauth_version' => '1.0'
        );

        if ($this->auth_data['auth_type'] === 'member' && !empty($this->auth_data['token'])) {
            $auth_params['oauth_token'] = $this->auth_data['token'];
            $signature = $this->auth_data['consumer_secret'] . '&' . $this->auth_data['token_secret'];
        } else {
            $signature = $this->auth_data['consumer_secret'] . '&';
        }

        $auth_params['oauth_signature'] = $signature;

        $auth_string = 'OAuth ' . implode(', ', array_map(function($k, $v) {
            return "$k=\"" . rawurlencode($v) . "\"";
        }, array_keys($auth_params), $auth_params));

        $headers['Authorization'] = $auth_string;

        // Ensure HTTPS is used
        $url = set_url_scheme($url, 'https');

        return array($url, $method, $headers, $body);
    }
}