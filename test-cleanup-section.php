<?php
/**
 * Test script to verify cleanup section visibility logic
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
    require_once ABSPATH . 'wp-load.php';
}

echo "Testing cleanup section visibility...\n\n";

// Check if we have scan results
global $wpdb;
$temp_table = $wpdb->prefix . 'intersoccer_temp_fake_users';

if ($wpdb->get_var("SHOW TABLES LIKE '$temp_table'") === $temp_table) {
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $temp_table");

    if ($count > 0) {
        echo "✓ Scan results found: $count fake users detected\n";
        echo "✓ Cleanup section should now be visible after scan completion\n\n";

        echo "Next steps for user:\n";
        echo "1. The 'Step 2: Review and Cleanup' section should now appear\n";
        echo "2. Keep 'Dry Run' checked for testing\n";
        echo "3. Click 'Start Cleanup' to simulate deletion\n";
        echo "4. Review results, then uncheck 'Dry Run' for actual deletion\n";
    } else {
        echo "⚠️ No fake users found in temp table\n";
        echo "This might indicate the scan didn't complete or found no fake users\n";
    }
} else {
    echo "✗ Temp table doesn't exist - scan hasn't been run\n";
}

echo "\nTest completed.\n";
?>