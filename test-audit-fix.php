<?php
/**
 * Test script to verify audit table insert fix
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
    require_once ABSPATH . 'wp-load.php';
}

echo "Testing audit table insert fix...\n\n";

global $wpdb;
$temp_table = $wpdb->prefix . 'intersoccer_temp_fake_users';
$audit_table = $wpdb->prefix . 'intersoccer_cleanup_audit';

// Get a sample fake user from temp table
$sample_user = $wpdb->get_row("SELECT id, email FROM $temp_table LIMIT 1");

if ($sample_user) {
    echo "Testing with sample user: ID {$sample_user->id}, Email {$sample_user->email}\n\n";

    // Test getting registration date BEFORE deletion (like the fix does)
    $user_registered = $wpdb->get_var($wpdb->prepare(
        "SELECT user_registered FROM {$wpdb->users} WHERE ID = %d",
        $sample_user->id
    ));

    if ($user_registered) {
        echo "✓ Successfully retrieved user_registered: $user_registered\n";

        // Test inserting into audit table (without actually deleting the user)
        $result = $wpdb->insert(
            $audit_table,
            array(
                'user_id' => $sample_user->id,
                'email' => $sample_user->email,
                'registered' => $user_registered
            ),
            array('%d', '%s', '%s')
        );

        if ($result) {
            echo "✓ Successfully inserted into audit table\n";
            echo "  - Insert ID: " . $wpdb->insert_id . "\n";

            // Clean up the test record
            $wpdb->delete($audit_table, array('id' => $wpdb->insert_id));
            echo "✓ Test record cleaned up\n";
        } else {
            echo "✗ Failed to insert into audit table\n";
            echo "  - Error: " . $wpdb->last_error . "\n";
        }
    } else {
        echo "✗ Could not retrieve user_registered (user might not exist)\n";
    }
} else {
    echo "✗ No fake users found in temp table to test with\n";
}

echo "\nAudit table insert fix test completed.\n";
?>