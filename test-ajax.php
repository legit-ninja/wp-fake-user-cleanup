<?php
/**
 * Test script to verify AJAX handlers are working
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
    require_once ABSPATH . 'wp-load.php';
}

echo "Testing AJAX handlers...\n\n";

// Test if the class exists
if (class_exists('InterSoccer_Fake_User_Cleanup')) {
    echo "✓ InterSoccer_Fake_User_Cleanup class exists\n";
} else {
    echo "✗ InterSoccer_Fake_User_Cleanup class not found\n";
}

// Test if AJAX actions are registered
global $wp_filter;

$actions_to_check = array(
    'wp_ajax_scan_fake_users_enhanced',
    'wp_ajax_cleanup_fake_users_enhanced',
    'wp_ajax_validate_date_range',
    'wp_ajax_get_scan_status',
    'wp_ajax_reset_scan',
    'wp_ajax_get_cleanup_status'
);

foreach ($actions_to_check as $action) {
    if (isset($wp_filter[$action])) {
        echo "✓ AJAX action '$action' is registered\n";
    } else {
        echo "✗ AJAX action '$action' is NOT registered\n";
    }
}

echo "\nTesting nonce generation...\n";
$nonce = wp_create_nonce('fake_user_cleanup_enhanced');
if (!empty($nonce)) {
    echo "✓ Nonce generated successfully: " . substr($nonce, 0, 10) . "...\n";
} else {
    echo "✗ Nonce generation failed\n";
}

echo "\nTesting database tables...\n";
global $wpdb;

$temp_table = $wpdb->prefix . 'intersoccer_temp_fake_users';
$audit_table = $wpdb->prefix . 'intersoccer_cleanup_audit';

if ($wpdb->get_var("SHOW TABLES LIKE '$temp_table'") === $temp_table) {
    echo "✓ Temp table exists\n";
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $temp_table");
    echo "  - Records: $count\n";
} else {
    echo "✗ Temp table does not exist\n";
}

if ($wpdb->get_var("SHOW TABLES LIKE '$audit_table'") === $audit_table) {
    echo "✓ Audit table exists\n";
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $audit_table");
    echo "  - Records: $count\n";
} else {
    echo "✗ Audit table does not exist\n";
}

echo "\nTest completed.\n";
?>