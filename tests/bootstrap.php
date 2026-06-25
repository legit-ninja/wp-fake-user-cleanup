<?php

define('ABSPATH', __DIR__ . '/fixtures/');
define('WP_PLUGIN_DIR', dirname(__DIR__));
define('WP_CONTENT_DIR', dirname(WP_PLUGIN_DIR));

require_once __DIR__ . '/fixtures/wp-admin/includes/upgrade.php';

global $mock_options;
if (!isset($mock_options)) {
    $mock_options = [];
}

global $mock_transients;
if (!isset($mock_transients)) {
    $mock_transients = [];
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

if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) {
        global $mock_transients;
        $mock_transients[$key] = $value;
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($key) {
        global $mock_transients;
        return array_key_exists($key, $mock_transients) ? $mock_transients[$key] : false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($key) {
        global $mock_transients;
        unset($mock_transients[$key]);
        return true;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
    }
}

if (!function_exists('content_url')) {
    function content_url($path = '') {
        return 'https://example.com/wp-content' . $path;
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) {
        return 'https://example.com/wp-content/plugins/fake-user-cleanup/';
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false) {
        return true;
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $data) {
        return true;
    }
}

if (!function_exists('wp_print_scripts')) {
    function wp_print_scripts($handle = null) {
        return null;
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

if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key = '', $single = false) {
        global $mock_user_meta;
        if (!isset($mock_user_meta)) {
            $mock_user_meta = [];
        }
        $uid = (int) $user_id;
        if (!isset($mock_user_meta[$uid])) {
            return $single ? '' : [];
        }
        if ($key === '') {
            return $single ? $mock_user_meta[$uid] : $mock_user_meta[$uid];
        }
        $row = $mock_user_meta[$uid];
        if (!is_array($row) || !array_key_exists($key, $row)) {
            return $single ? '' : [];
        }
        return $single ? $row[$key] : [$row[$key]];
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($data) {
        if (is_serialized($data)) {
            return @unserialize($data);
        }
        return $data;
    }
}

if (!function_exists('is_serialized')) {
    function is_serialized($data) {
        if (!is_string($data)) {
            return false;
        }
        return preg_match('/^[aOs]:\d+:/', $data) === 1;
    }
}

require_once __DIR__ . '/../fake-user-cleanup.php';

