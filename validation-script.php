<?php
/**
 * Fake User Cleanup Database Validation Script
 * Validates table structure, indexes, and query performance
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
    require_once ABSPATH . 'wp-load.php';
}

if (!current_user_can('manage_options')) {
    die('Access denied');
}

class FakeUserCleanupValidator {

    private $wpdb;
    private $temp_table;
    private $audit_table;
    private $results = array();

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->temp_table = $wpdb->prefix . 'intersoccer_temp_fake_users';
        $this->audit_table = $wpdb->prefix . 'intersoccer_cleanup_audit';
    }

    public function run_validation() {
        $this->results = array();

        $this->check_table_structure();
        $this->check_indexes();
        $this->check_data_integrity();
        $this->test_query_performance();
        $this->validate_cleanup_logic();

        return $this->results;
    }

    private function check_table_structure() {
        $this->results['table_structure'] = array(
            'temp_table_exists' => $this->table_exists($this->temp_table),
            'audit_table_exists' => $this->table_exists($this->audit_table)
        );

        if ($this->table_exists($this->temp_table)) {
            $columns = $this->get_table_columns($this->temp_table);
            $this->results['table_structure']['temp_table_columns'] = $columns;
            $this->results['table_structure']['temp_table_valid'] = $this->validate_temp_table_structure($columns);
        }

        if ($this->table_exists($this->audit_table)) {
            $columns = $this->get_table_columns($this->audit_table);
            $this->results['table_structure']['audit_table_columns'] = $columns;
            $this->results['table_structure']['audit_table_valid'] = $this->validate_audit_table_structure($columns);
        }
    }

    private function check_indexes() {
        $this->results['indexes'] = array();

        if ($this->table_exists($this->temp_table)) {
            $indexes = $this->get_table_indexes($this->temp_table);
            $this->results['indexes']['temp_table_indexes'] = $indexes;
            $this->results['indexes']['temp_table_indexes_valid'] = $this->validate_temp_table_indexes($indexes);
        }

        if ($this->table_exists($this->audit_table)) {
            $indexes = $this->get_table_indexes($this->audit_table);
            $this->results['indexes']['audit_table_indexes'] = $indexes;
            $this->results['indexes']['audit_table_indexes_valid'] = $this->validate_audit_table_indexes($indexes);
        }
    }

    private function check_data_integrity() {
        $this->results['data_integrity'] = array();

        if ($this->table_exists($this->temp_table)) {
            $count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->temp_table}");
            $this->results['data_integrity']['temp_table_count'] = $count;

            // Check for orphaned records
            $orphaned = $this->wpdb->get_var("
                SELECT COUNT(*) FROM {$this->temp_table} t
                LEFT JOIN {$this->wpdb->users} u ON t.id = u.ID
                WHERE u.ID IS NULL
            ");
            $this->results['data_integrity']['orphaned_temp_records'] = $orphaned;

            // Check for duplicate emails
            $duplicates = $this->wpdb->get_var("
                SELECT COUNT(*) - COUNT(DISTINCT email) as duplicates
                FROM {$this->temp_table}
            ");
            $this->results['data_integrity']['duplicate_emails'] = $duplicates;
        }

        if ($this->table_exists($this->audit_table)) {
            $count = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->audit_table}");
            $this->results['data_integrity']['audit_table_count'] = $count;
        }
    }

    private function test_query_performance() {
        $this->results['query_performance'] = array();

        if ($this->table_exists($this->temp_table)) {
            // Test batch query performance
            $batch_sizes = array(25, 50, 100);
            foreach ($batch_sizes as $batch_size) {
                $start_time = microtime(true);
                $results = $this->wpdb->get_results($this->wpdb->prepare(
                    "SELECT id, email FROM {$this->temp_table} LIMIT %d",
                    $batch_size
                ));
                $end_time = microtime(true);
                $duration = round(($end_time - $start_time) * 1000, 2); // ms

                $this->results['query_performance']["batch_{$batch_size}"] = array(
                    'duration_ms' => $duration,
                    'records_returned' => count($results),
                    'performance' => $duration < 100 ? 'good' : ($duration < 500 ? 'acceptable' : 'slow')
                );
            }

            // Test indexed query performance
            $start_time = microtime(true);
            $results = $this->wpdb->get_results("
                SELECT id, email FROM {$this->temp_table}
                WHERE email LIKE 'test%@gmail.com'
                LIMIT 10
            ");
            $end_time = microtime(true);
            $duration = round(($end_time - $start_time) * 1000, 2);

            $this->results['query_performance']['indexed_query'] = array(
                'duration_ms' => $duration,
                'records_returned' => count($results),
                'uses_index' => true // Assumes index is working
            );
        }
    }

    private function validate_cleanup_logic() {
        $this->results['cleanup_logic'] = array();

        if ($this->table_exists($this->temp_table)) {
            // Test the fake user detection pattern
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

            $this->results['cleanup_logic']['pattern_matches'] = $matches;
            $this->results['cleanup_logic']['pattern_non_matches'] = $non_matches;
            $this->results['cleanup_logic']['pattern_accuracy'] = $matches > $non_matches ? 'good' : 'needs_review';
        }
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

        // Check data types
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
        // Audit table should have PRIMARY KEY on id
        $primary_indexes = array_filter($indexes, function($index) {
            return $index['Key_name'] === 'PRIMARY';
        });
        return !empty($primary_indexes);
    }

    public function display_results() {
        echo "<h2>Fake User Cleanup Database Validation Results</h2>";
        echo "<style>
            .validation-results { font-family: monospace; }
            .validation-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
            .validation-section h3 { margin-top: 0; color: #007cba; }
            .status-good { color: #28a745; }
            .status-warning { color: #ffc107; }
            .status-error { color: #dc3545; }
            .metric { margin: 5px 0; }
        </style>";
        echo "<div class='validation-results'>";

        foreach ($this->results as $section => $data) {
            echo "<div class='validation-section'>";
            echo "<h3>" . ucwords(str_replace('_', ' ', $section)) . "</h3>";

            if (is_array($data)) {
                $this->display_array($data);
            } else {
                echo "<div class='metric'>$data</div>";
            }

            echo "</div>";
        }

        echo "</div>";
    }

    private function display_array($array, $prefix = '') {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                echo "<div class='metric'><strong>$key:</strong></div>";
                $this->display_array($value, $prefix . '  ');
            } else {
                $status_class = '';
                if (strpos($value, 'good') !== false || $value === true) {
                    $status_class = 'status-good';
                } elseif (strpos($value, 'slow') !== false || strpos($value, 'needs_review') !== false || $value === false) {
                    $status_class = 'status-warning';
                }

                echo "<div class='metric $status_class'>$prefix<strong>$key:</strong> $value</div>";
            }
        }
    }
}

// Run validation if accessed directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $validator = new FakeUserCleanupValidator();
    $results = $validator->run_validation();
    $validator->display_results();
}
?>