<?php
/**
 * Add Missing Indexes to Fake User Cleanup Tables
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
    require_once ABSPATH . 'wp-load.php';
}

global $wpdb;
$temp_table = $wpdb->prefix . 'intersoccer_temp_fake_users';

// Check current indexes
$current_indexes = $wpdb->get_results("SHOW INDEX FROM $temp_table", ARRAY_A);
$index_names = array_column($current_indexes, 'Key_name');

echo "=== ADDING MISSING INDEXES ===\n";
echo "Current indexes on $temp_table: " . implode(', ', array_unique($index_names)) . "\n\n";

// Add email index if missing
if (!in_array('email_idx', $index_names)) {
    echo "Adding email_idx index...\n";
    $result = $wpdb->query("ALTER TABLE $temp_table ADD INDEX email_idx (email)");
    echo "Result: " . ($result ? "SUCCESS" : "FAILED") . "\n";
} else {
    echo "email_idx already exists\n";
}

// Add registered index if missing
if (!in_array('registered_idx', $index_names)) {
    echo "Adding registered_idx index...\n";
    $result = $wpdb->query("ALTER TABLE $temp_table ADD INDEX registered_idx (registered)");
    echo "Result: " . ($result ? "SUCCESS" : "FAILED") . "\n";
} else {
    echo "registered_idx already exists\n";
}

echo "\n=== INDEX ADDITION COMPLETE ===\n";

// Verify indexes after addition
$current_indexes = $wpdb->get_results("SHOW INDEX FROM $temp_table", ARRAY_A);
$index_names = array_column($current_indexes, 'Key_name');
echo "Final indexes on $temp_table: " . implode(', ', array_unique($index_names)) . "\n";
?>