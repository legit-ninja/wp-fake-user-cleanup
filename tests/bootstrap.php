<?php

define('ABSPATH', __DIR__ . '/fixtures/');
define('WP_PLUGIN_DIR', dirname(__DIR__));
define('WP_CONTENT_DIR', dirname(WP_PLUGIN_DIR));

require_once __DIR__ . '/fixtures/wp-admin/includes/upgrade.php';

global $mock_options;
if (!isset($mock_options)) {
    $mock_options = [];
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('add_action')) {
    function add_action(...$args) {
        // No-op for testing.
    }
}

if (!function_exists('add_management_page')) {
    function add_management_page(...$args) {
        // No-op for testing.
    }
}

if (!function_exists('admin_url')) {
    function admin_url($path = '') {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = '') {
        return 'test-nonce';
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() {
        return '00000000-0000-4000-8000-000000000000';
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return true;
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = '', $query_arg = false, $die = true) {
        return true;
    }
}

class FakeJsonResponse extends Exception {
    public $success;
    public $payload;

    public function __construct($success, $payload) {
        parent::__construct($success ? 'success' : 'error');
        $this->success = $success;
        $this->payload = $payload;
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null) {
        throw new FakeJsonResponse(true, $data);
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null) {
        throw new FakeJsonResponse(false, $data);
    }
}

if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        global $mock_options;
        return array_key_exists($key, $mock_options) ? $mock_options[$key] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($key, $value) {
        global $mock_options;
        $mock_options[$key] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($key) {
        global $mock_options;
        unset($mock_options[$key]);
        return true;
    }
}

if (!function_exists('content_url')) {
    function content_url($path = '') {
        return 'https://example.com/wp-content' . $path;
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {
        $GLOBALS['mock_activation_hook'] = $callback;
    }
}

if (!function_exists('wp_delete_user')) {
    function wp_delete_user($user_id) {
        $GLOBALS['mock_deleted_users'][] = $user_id;
        return true;
    }
}

require_once __DIR__ . '/../fake-user-cleanup.php';

