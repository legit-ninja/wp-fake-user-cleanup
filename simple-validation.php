<?php
/**
 * Simple Fake User Cleanup Database Validation Script
 * Outputs plain text results for terminal execution
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
    require_once ABSPATH . 'wp-load.php';
}

class SimpleFakeUserCleanupValidator {

    private $wpdb;
    private $temp_table;
    private $audit_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->temp_table = $wpdb->prefix . 'intersoccer_temp_fake_users';
        $this->audit_table = $wpdb->prefix . 'intersoccer_cleanup_audit';
    }

    public function run_validation() {
        echo "=== FAKE USER CLEANUP DATABASE VALIDATION ===\n\n";

        $this->check_table_structure();
        $this->check_indexes();
        $this->check_data_integrity();
        $this->test_query_performance();
        $this->validate_cleanup_logic();

        echo "\n=== VALIDATION COMPLETE ===\n";
    }

    private function check_table_structure() {
        echo "TABLE STRUCTURE CHECK:\n";

        $temp_exists = $this->table_exists($this->temp_table);
        $audit_exists = $this->table_exists($this->audit_table);

        echo "- Temp table exists: " . ($temp_exists ? "YES" : "NO") . "\n";
        echo "- Audit table exists: " . ($audit_exists ? "YES" : "NO") . "\n";

        if ($temp_exists) {
            $columns = $this->get_table_columns($this->temp_table);
            echo "- Temp table columns: " . count($columns) . " found\n";
            echo "- Temp table structure valid: " . ($this->validate_temp_table_structure($columns) ? "YES" : "NO") . "\n";
        }

        if ($audit_exists) {
            $columns = $this->get_table_columns($this->audit_table);
            echo "- Audit table columns: " . count($columns) . " found\n";
            echo "- Audit table structure valid: " . ($this->validate_audit_table_structure($columns) ? "YES" : "NO") . "\n";
        }

        echo "\n";
    }

    private function check_indexes() {
        echo "INDEX CHECK:\n";

        if ($this->table_exists($this->temp_table)) {
            $indexes = $this->get_table_indexes($this->temp_table);
            echo "- Temp table indexes: " . count($indexes) . " found\n";
            echo "- Temp table indexes valid: " . ($this->validate_temp_table_indexes($indexes) ? "YES" : "NO") . "\n";
        }

        if ($this->table_exists($this->audit_table)) {
            $indexes = $this->get_table_indexes($this->audit_table);
            echo "- Audit table indexes: " . count($indexes) . " found\n";
            echo "- Audit table indexes valid: " . ($this->validate_audit_table_indexes($indexes) ? "YES" : "NO") . "\n";
        }

        echo "\n";
    }

    private function check_data_integrity() {
        echo "DATA INTEGRITY CHECK:\n";

        if ($this->table_exists($this->temp_table)) {
            $count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->temp_table}");
            echo "- Temp table records: $count\n";

            $orphaned = $this->wpdb->get_var("
                SELECT COUNT(*) FROM {$this->temp_table} t
                LEFT JOIN {$this->wpdb->users} u ON t.id = u.ID
                WHERE u.ID IS NULL
            ");
            echo "- Orphaned temp records: $orphaned\n";

            $duplicates = $this->wpdb->get_var("
                SELECT COUNT(*) - COUNT(DISTINCT email) as duplicates
                FROM {$this->temp_table}
            ");
            echo "- Duplicate emails: $duplicates\n";
        }

        if ($this->table_exists($this->audit_table)) {
            $count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->audit_table}");
            echo "- Audit table records: $count\n";
        }

        echo "\n";
    }

    private function test_query_performance() {
        echo "QUERY PERFORMANCE TEST:\n";

        if ($this->table_exists($this->temp_table)) {
            $batch_sizes = array(25, 50, 100);
            foreach ($batch_sizes as $batch_size) {
                $start_time = microtime(true);
                $results = $this->wpdb->get_results($this->wpdb->prepare(
                    "SELECT id, email FROM {$this->temp_table} LIMIT %d",
                    $batch_size
                ));
                $end_time = microtime(true);
                $duration = round(($end_time - $start_time) * 1000, 2);

                $performance = $duration < 100 ? 'GOOD' : ($duration < 500 ? 'ACCEPTABLE' : 'SLOW');
                echo "- Batch $batch_size query: {$duration}ms ($performance)\n";
            }

            $start_time = microtime(true);
            $results = $this->wpdb->get_results("
                SELECT id, email FROM {$this->temp_table}
                WHERE email LIKE 'test%@gmail.com'
                LIMIT 10
            ");
            $end_time = microtime(true);
            $duration = round(($end_time - $start_time) * 1000, 2);

            echo "- Indexed query: {$duration}ms\n";
        }

        echo "\n";
    }

    private function validate_cleanup_logic() {
        echo "CLEANUP LOGIC VALIDATION:\n";

        if ($this->table_exists($this->temp_table)) {
            $pattern = '/^[a-z]{8}\d{2}@(gmail\.com|outlook\.com|yahoo\.com|hotmail\.com)$/';

            $sample_emails = $this->wpdb->get_col("SELECT email FROM {$this->temp_table} LIMIT 100");

            $matches = 0;
            $non_matches = 0;

            foreach ($sample_emails as $email) {
                if (preg_match($pattern, $email)) {
                    $matches++;
                } else {
                    $non_matches++;
                }
            }

            $accuracy = $matches > $non_matches ? 'GOOD' : 'NEEDS_REVIEW';
            echo "- Pattern matches: $matches\n";
            echo "- Pattern non-matches: $non_matches\n";
            echo "- Pattern accuracy: $accuracy\n";
        }

        echo "\n";
    }

    private function table_exists($table_name) {
        return $this->wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }

    private function get_table_columns($table_name) {
        return $this->wpdb->get_results("DESCRIBE $table_name", ARRAY_A);
    }

    private function get_table_indexes($table_name) {
        return $this->wpdb->get_results("SHOW INDEX FROM $table_name", ARRAY_A);
    }

    private function validate_temp_table_structure($columns) {
        $required_columns = array('id', 'email', 'registered');
        $column_names = array_column($columns, 'Field');

        foreach ($required_columns as $required) {
            if (!in_array($required, $column_names)) {
                return false;
            }
        }

        $column_types = array_column($columns, 'Type', 'Field');

        return (
            strpos($column_types['id'], 'mediumint') !== false &&
            strpos($column_types['email'], 'varchar') !== false &&
            strpos($column_types['registered'], 'datetime') !== false
        );
    }

    private function validate_audit_table_structure($columns) {
        $required_columns = array('id', 'user_id', 'email', 'registered', 'deleted_at');
        $column_names = array_column($columns, 'Field');

        foreach ($required_columns as $required) {
            if (!in_array($required, $column_names)) {
                return false;
            }
        }

        return true;
    }

    private function validate_temp_table_indexes($indexes) {
        $index_names = array_column($indexes, 'Key_name');
        return in_array('email_idx', $index_names) && in_array('registered_idx', $index_names);
    }

    private function validate_audit_table_indexes($indexes) {
        $primary_indexes = array_filter($indexes, function($index) {
            return $index['Key_name'] === 'PRIMARY';
        });
        return !empty($primary_indexes);
    }
}

// Run validation
$validator = new SimpleFakeUserCleanupValidator();
$validator->run_validation();
?>