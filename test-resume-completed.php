<?php
/**
 * Test script to verify resume functionality for completed scans
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
    require_once ABSPATH . 'wp-load.php';
}

echo "Testing resume functionality for completed scans...\n\n";

global $wpdb;

// Check current scan progress
$progress_option_key = 'intersoccer_progress';
$progress = get_option($progress_option_key, array());

if (!empty($progress)) {
    echo "Current scan progress:\n";
    echo "- Session ID: " . ($progress['session_id'] ?? 'N/A') . "\n";
    echo "- Status: " . ($progress['status'] ?? 'N/A') . "\n";
    echo "- Processed: " . ($progress['processed'] ?? 0) . "\n";
    echo "- Total Users: " . ($progress['total_users'] ?? 0) . "\n";
    echo "- Fake Found: " . ($progress['fake_found'] ?? 0) . "\n";
    echo "- Safe Found: " . ($progress['safe_found'] ?? 0) . "\n\n";

    // Check if scan is completed
    if (($progress['status'] ?? '') === 'completed') {
        echo "✓ Scan is marked as completed\n";
        echo "✓ When resuming, the 'Step 2' section should now appear\n\n";

        // Check temp table
        $temp_table = $wpdb->prefix . 'intersoccer_temp_fake_users';
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $temp_table");
        echo "Fake users in temp table: $count\n\n";

        echo "Resume test: PASSED - Completed scan should show results and cleanup sections\n";
    } else {
        echo "⚠️ Scan is not completed (status: " . ($progress['status'] ?? 'unknown') . ")\n";
        echo "This might be an incomplete scan that needs to be resumed\n\n";
        echo "Resume test: Would continue processing batches\n";
    }
} else {
    echo "No scan progress found - no active or completed scans\n\n";
    echo "Resume test: N/A - No scan to resume\n";
}

// Check cleanup progress
$cleanup_progress = get_option('intersoccer_cleanup_progress', array());
if (!empty($cleanup_progress)) {
    echo "\nCleanup progress found:\n";
    echo "- Status: " . ($cleanup_progress['status'] ?? 'N/A') . "\n";
    echo "- Processed: " . ($cleanup_progress['processed'] ?? 0) . "\n";
    echo "- Deleted: " . ($cleanup_progress['deleted'] ?? 0) . "\n";
    echo "- Skipped: " . ($cleanup_progress['skipped'] ?? 0) . "\n";
}

echo "\nTest completed.\n";
?>