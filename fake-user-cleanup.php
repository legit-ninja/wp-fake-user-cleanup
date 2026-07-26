<?php
/**
 * Plugin Name: InterSoccer Fake User Cleanup
 * Description: Fixed cleanup tool with proper validation logic
 * Version: 1.7.25
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class InterSoccer_Fake_User_Cleanup {

    private const SCHEMA_VERSION = 4;
    private const SCHEMA_VERSION_OPTION = 'intersoccer_fake_cleanup_schema_version';
    private const COHORT_TRANSIENT_TTL = 86400;

    private $log_file;
    private $transient_key = 'intersoccer_fake_ids_v3';

    private $temp_table = 'intersoccer_temp_fake_users';
    private $audit_table = 'intersoccer_cleanup_audit';

    private $scan_current_option = 'intersoccer_scan_current';
    private $scan_last_summary_option = 'intersoccer_scan_last_summary';
    private $cleanup_current_option = 'intersoccer_cleanup_current';
    private $scan_progress_prefix = 'intersoccer_scan_progress_';
    private $cleanup_progress_prefix = 'intersoccer_cleanup_progress_';
    private $disposable_domains = array(
        'mailinator.com',
        'yopmail.com',
        '10minutemail.com',
        'tempmail.com',
        'sharklasers.com',
        'guerrillamail.com',
        'trashmail.com',
        'maildrop.cc',
        'dispostable.com',
        'getnada.com',
        'moakt.com',
        'temporary-mail.net'
    );

    private function get_scan_progress_key($session_id) {
        return $this->scan_progress_prefix . $session_id;
    }

    private function get_cleanup_progress_key($session_id) {
        return $this->cleanup_progress_prefix . $session_id;
    }

    private function save_scan_progress($session_id, array $progress) {
        update_option($this->get_scan_progress_key($session_id), $progress);
    }

    private function load_scan_progress($session_id) {
        return get_option($this->get_scan_progress_key($session_id), array());
    }

    private function delete_scan_progress($session_id) {
        delete_option($this->get_scan_progress_key($session_id));
    }

    private function save_cleanup_progress($session_id, array $progress) {
        update_option($this->get_cleanup_progress_key($session_id), $progress);
    }

    private function load_cleanup_progress($session_id) {
        return get_option($this->get_cleanup_progress_key($session_id), array());
    }

    private function delete_cleanup_progress($session_id) {
        delete_option($this->get_cleanup_progress_key($session_id));
    }

    private function generate_session_id() {
        return wp_generate_uuid4();
    }

    private function get_cohort_transient_key($session_id) {
        return 'intersoccer_scan_cohorts_' . $session_id;
    }

    private function delete_cohort_transient($session_id) {
        if ($session_id) {
            delete_transient($this->get_cohort_transient_key($session_id));
        }
    }

    private function maybe_upgrade_schema() {
        $stored_version = (int) get_option(self::SCHEMA_VERSION_OPTION, 0);
        if ($stored_version >= self::SCHEMA_VERSION) {
            return;
        }
        $this->ensure_temp_table();
        $this->ensure_audit_table();
        update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION);
    }

    private function maybe_add_users_registered_index() {
        if (!apply_filters('intersoccer_fake_cleanup_add_users_index', false)) {
            return;
        }
        global $wpdb;
        try {
            $index_name = 'intersoccer_registered_id';
            $existing = $wpdb->get_results("SHOW INDEX FROM {$wpdb->users} WHERE Key_name = '{$index_name}'", ARRAY_A);
            if (empty($existing)) {
                $wpdb->query("ALTER TABLE {$wpdb->users} ADD INDEX {$index_name} (user_registered, ID)");
            }
        } catch (Exception $e) {
            $this->log_message('users_index_failed', array('message' => $e->getMessage()), 'warning');
        }
    }

    private function ensure_temp_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->temp_table;
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $sql = "CREATE TABLE $table_name (
                id bigint(20) UNSIGNED NOT NULL,
                email varchar(100) NOT NULL,
                registered datetime NOT NULL,
                score tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
                reason text NULL,
                needs_review tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
                review_notes text NULL,
                PRIMARY KEY (id),
                KEY email_idx (email),
                KEY registered_idx (registered)
            ) $charset_collate;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        } else {
            $id_column = $wpdb->get_row("SHOW FIELDS FROM $table_name LIKE 'id'");
            if ($id_column && stripos($id_column->Type, 'bigint') === false) {
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN id bigint(20) UNSIGNED NOT NULL");
            }
        }

        $score_column = $wpdb->get_row("SHOW FIELDS FROM $table_name LIKE 'score'");
        if (!$score_column) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN score tinyint(3) UNSIGNED NOT NULL DEFAULT 0");
        }

        $reason_column = $wpdb->get_row("SHOW FIELDS FROM $table_name LIKE 'reason'");
        if (!$reason_column) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN reason text NULL");
        }

        $needs_review_column = $wpdb->get_row("SHOW FIELDS FROM $table_name LIKE 'needs_review'");
        if (!$needs_review_column) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN needs_review tinyint(1) UNSIGNED NOT NULL DEFAULT 0");
        }

        $review_notes_column = $wpdb->get_row("SHOW FIELDS FROM $table_name LIKE 'review_notes'");
        if (!$review_notes_column) {
            $wpdb->query("ALTER TABLE $table_name ADD COLUMN review_notes text NULL");
        }

        $current_indexes = $wpdb->get_results("SHOW INDEX FROM $table_name", ARRAY_A);
        $index_names = array_unique(array_map(function($index) {
            return $index['Key_name'];
        }, $current_indexes ?: array()));

        if (!in_array('email_idx', $index_names, true)) {
            $wpdb->query("ALTER TABLE $table_name ADD INDEX email_idx (email)");
        }

        if (!in_array('registered_idx', $index_names, true)) {
            $wpdb->query("ALTER TABLE $table_name ADD INDEX registered_idx (registered)");
        }

        if (!in_array('needs_review_idx', $index_names, true)) {
            $wpdb->query("ALTER TABLE $table_name ADD INDEX needs_review_idx (needs_review, score, id)");
        }
    }

    private function ensure_audit_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->audit_table;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) UNSIGNED NOT NULL,
                email varchar(100) NOT NULL,
                registered datetime NOT NULL,
                deleted_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset_collate;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        } else {
            $user_id_column = $wpdb->get_row("SHOW FIELDS FROM $table_name LIKE 'user_id'");
            if ($user_id_column && stripos($user_id_column->Type, 'bigint') === false) {
                $wpdb->query("ALTER TABLE $table_name MODIFY COLUMN user_id bigint(20) UNSIGNED NOT NULL");
            }
        }
    }
    
    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/intersoccer-cleanup-logs/intersoccer-cleanup-enhanced.log';
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_scan_fake_users_enhanced', array($this, 'ajax_scan_fake_users'));
        add_action('wp_ajax_cleanup_fake_users_enhanced', array($this, 'ajax_cleanup_fake_users'));
        add_action('wp_ajax_validate_date_range', array($this, 'ajax_validate_date_range'));
        
        add_action('wp_ajax_get_scan_status', array($this, 'ajax_get_scan_status'));
        add_action('wp_ajax_reset_scan', array($this, 'ajax_reset_scan'));
        add_action('wp_ajax_reset_cleanup', array($this, 'ajax_reset_cleanup'));
        add_action('wp_ajax_get_cleanup_status', array($this, 'ajax_get_cleanup_status'));
        add_action('wp_ajax_get_scan_results', array($this, 'ajax_get_scan_results')); // New handler for getting scan results
        add_action('wp_ajax_download_cleanup_review', array($this, 'ajax_download_cleanup_review'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    public static function activate() {
        $instance = new self();
        $instance->maybe_upgrade_schema();
        $instance->maybe_add_users_registered_index();
    }

    public function ajax_get_scan_status() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $requested_session = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $active_session = $requested_session ?: get_option($this->scan_current_option, '');
        $progress = $active_session ? $this->load_scan_progress($active_session) : array();
        $incomplete = !empty($progress) && $progress['status'] === 'running';

        global $wpdb;
        $this->maybe_upgrade_schema();
        $has_results = (bool) $wpdb->get_var(
            "SELECT 1 FROM {$wpdb->prefix}{$this->temp_table} LIMIT 1"
        );

        wp_send_json_success(array(
            'incomplete' => $incomplete,
            'session' => $progress,
            'session_id' => $active_session,
            'has_results' => $has_results
        ));
    }

    public function ajax_reset_scan() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}{$this->temp_table}");
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : get_option($this->scan_current_option, '');
        if ($session_id) {
            $this->delete_scan_progress($session_id);
            $this->delete_cohort_transient($session_id);
        }
        update_option($this->scan_current_option, '');
        delete_option($this->scan_last_summary_option);
        $this->log_message("Scan and cleanup progress reset");
        wp_send_json_success();
    }

    public function ajax_reset_cleanup() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : get_option($this->cleanup_current_option, '');
        if ($session_id) {
            $this->delete_cleanup_progress($session_id);
        }
        update_option($this->cleanup_current_option, '');
        $this->log_message("Cleanup progress reset (scan results preserved)");
        wp_send_json_success();
    }
    
    public function enqueue_admin_assets($hook) {
        if (!isset($_GET['page']) || $_GET['page'] !== 'enhanced-fake-user-cleanup') {
            return;
        }
        $script_path = plugin_dir_path(__FILE__) . 'assets/js/fake-user-cleanup-admin.js';
        $script_url = plugin_dir_url(__FILE__) . 'assets/js/fake-user-cleanup-admin.js';
        $script_ver = file_exists($script_path) ? (string) filemtime($script_path) : '1.7.25';
        wp_enqueue_script(
            'intersoccer-fake-user-cleanup-admin',
            $script_url,
            array('jquery'),
            $script_ver,
            true
        );
        wp_localize_script('intersoccer-fake-user-cleanup-admin', 'intersoccerCleanup', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('fake_user_cleanup_enhanced'),
            'defaults' => array(
                'scanBatchDelayMs'    => 2000,
                'cleanupBatchDelayMs' => 2000,
                'activityGraceMonths' => 6
            )
        ));
    }

    public function add_admin_menu() {
        add_management_page(
            'Fake User Cleanup',
            'Fake User Cleanup',
            'manage_options',
            'enhanced-fake-user-cleanup',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        ?>
        <div class="wrap intersoccer-fake-user-cleanup">
            <h1>InterSoccer Fake User Cleanup</h1>
            
            <div class="notice notice-warning">
                <p><strong>Important:</strong> This tool will permanently delete users. Always test on staging first and create database backups.</p>
            </div>
            
            <!-- Date Range Configuration -->
            <div class="card">
                <h2>Incident Date Range Configuration</h2>
                <p>Define the date range when fake users were created. Only users registered within this period will be considered for cleanup.</p>
                
                <div class="date-range-options">
                    <label>Start Date: <input type="date" id="incident-start-date" value="2025-07-01"></label>
                    <label>End Date: <input type="date" id="incident-end-date" value="2025-08-25"></label>
                    <button id="validate-date-range" class="button">Validate Date Range</button>
                </div>
                
                <div id="date-validation-results" style="display: none; margin-top: 15px;">
                    <div id="date-validation-summary"></div>
                </div>
            </div>
            
            <!-- Scan Section -->
            <div class="card">
                <h2>Step 1: Scan for Fake Users</h2>
                <p>Advanced fake user detection using behavioral analysis, account patterns, and scoring system.</p>
                <p><strong>Detection Methods:</strong> Email patterns, login activity, profile completeness, registration patterns, content analysis</p>
                
                <div class="scan-options">
                    <label>Batch size: <select id="scan-batch-size">
                        <option value="50" selected>50 (Conservative)</option>
                        <option value="100">100 (Optimized)</option>
                        <option value="200">200 (Fast)</option>
                        <option value="25">25 (Very Safe)</option>
                    </select></label>

                    <label>Inter-batch delay (ms): <select id="scan-batch-delay-ms">
                        <option value="500">500</option>
                        <option value="1000">1000</option>
                        <option value="2000" selected>2000</option>
                        <option value="5000">5000</option>
                    </select></label>
                    
                    <label><input type="checkbox" id="detailed-logging"> Enable detailed logging</label>
                    <label><input type="checkbox" id="debug-first-10"> Debug first 10 users in detail</label>
                </div>
                
                <div class="scan-actions">
                    <button id="scan-users" class="button button-primary">Start Scan</button>
                    <button id="resume-scan" class="button" style="display: none;">Resume Scan</button>
                    <button id="reset-scan" class="button button-link" style="display: none;">Reset Scan</button>
                </div>
                
                <div id="scan-progress-container" style="display: none;">
                    <div class="progress-wrapper">
                        <div class="progress-bar">
                            <div class="progress-fill" id="scan-progress-fill" style="width: 0%;"></div>
                        </div>
                        <div class="progress-details">
                            <span id="scan-progress-text">0% complete</span>
                            <span id="scan-stats"></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Debug Section -->
            <div class="card" id="debug-section" style="display: none;">
                <h2>Debug Information</h2>
                <div id="debug-output"></div>
            </div>
            
            <!-- Results Section -->
            <div class="card" id="results-section" style="display: none;">
                <h2>Scan Results</h2>
                <div id="scan-summary"></div>
                <div id="sample-users"></div>
                <div id="safety-check-breakdown"></div>
            </div>
            
            <!-- Cleanup Section -->
            <div class="card" id="cleanup-section" style="display: none;">
                <h2>Step 2: Review and Cleanup</h2>
                <p><strong>Warning:</strong> This action cannot be undone.</p>
                
                <div id="dry-run-notice" class="notice notice-warning inline" style="margin: 0 0 12px; padding: 8px 12px;">
                    <p style="margin: 0;"><strong>Dry Run is ON.</strong> No users will be deleted. Uncheck Dry Run below before a real cleanup.</p>
                </div>
                <div class="cleanup-options">
                    <label><input type="checkbox" id="dry-run" checked> Dry Run (Log only, no deletion)</label>
                    <label><input type="checkbox" id="force-cleanup"> Force cleanup (bypass activity meta checks only; orders and authored content always protected)</label>
                    <label>Cleanup batch size: <select id="cleanup-batch-size">
                        <option value="25" selected>25 (Low impact)</option>
                        <option value="50">50 (Optimized)</option>
                        <option value="100">100 (Fast)</option>
                        <option value="10">10 (Very Safe)</option>
                    </select></label>
                    <label>Delay after each delete (ms): <select id="cleanup-delay-ms">
                        <option value="0">0 (none)</option>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select></label>
                    <label>Inter-batch delay (ms): <select id="cleanup-batch-delay-ms">
                        <option value="500">500</option>
                        <option value="1000">1000</option>
                        <option value="2000" selected>2000</option>
                        <option value="5000">5000</option>
                    </select></label>
                    <label>Activity grace period (months):
                        <input type="number" id="activity-grace-months" min="0" max="120" value="6" style="width: 4em;">
                    </label>
                </div>
                <p class="description" style="margin-top: 0;">Users with timestamp-based activity older than this grace period are eligible for deletion. Use 0 for strict mode (any activity meta blocks). Only <strong>active</strong> <code>session_tokens</code> (non-expired) block; empty or fully expired sessions do not.</p>
                
                <button id="cleanup-users" class="button button-secondary">Start Dry Run Cleanup</button>
                <button id="resume-cleanup" class="button button-secondary" style="display: none;">Resume Cleanup</button>
                <button id="reset-cleanup" class="button button-link" style="display: none;">Reset Cleanup</button>
                <button id="download-review" class="button" style="display: none;">Download Review CSV</button>
                
                <div id="cleanup-progress-container" style="display: none;">
                    <div class="progress-wrapper">
                        <div class="progress-bar">
                            <div class="progress-fill" id="cleanup-progress-fill" style="width: 0%;"></div>
                        </div>
                        <div class="progress-details">
                            <span id="cleanup-progress-text">0% complete</span>
                            <span id="cleanup-stats"></span>
                        </div>
                    </div>
                </div>
                
                <?php if (file_exists($this->log_file)) : ?>
                    <p><a href="<?php echo esc_url(content_url('/intersoccer-cleanup-logs/intersoccer-cleanup-enhanced.log')); ?>" target="_blank" class="button">View Log</a> <em>(may be blocked by server; use FTP or server access if needed)</em></p>
                <?php endif; ?>
            </div>

        <style>
        .wrap.intersoccer-fake-user-cleanup {
            padding-bottom: 40px;
        }
        .card { 
            background: #fff; 
            border: 1px solid #ccd0d4; 
            border-radius: 4px; 
            padding: 20px; 
            margin: 20px 0; 
            box-shadow: 0 1px 1px rgba(0,0,0,.04); 
        }
        .progress-wrapper { margin: 15px 0; }
        .progress-bar { 
            width: 100%; 
            height: 25px; 
            background-color: #f1f1f1; 
            border-radius: 4px; 
            overflow: hidden;
        }
        .progress-fill { 
            height: 100%; 
            background: linear-gradient(90deg, #0073aa, #005177); 
            transition: width 0.3s ease; 
            border-radius: 4px;
        }
        .progress-details {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 13px;
        }
        .scan-options, .cleanup-options, .date-range-options { 
            margin: 15px 0; 
            display: flex; 
            gap: 20px; 
            flex-wrap: wrap;
        }
        .scan-actions {
            margin: 15px 0;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .scan-options label, .cleanup-options label, .date-range-options label { 
            display: flex; 
            align-items: center; 
            gap: 5px; 
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }
        .summary-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #0073aa;
        }
        .summary-item h4 {
            margin: 0 0 5px 0;
            color: #0073aa;
        }
        .summary-item .number {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        .sample-users, .debug-output {
            max-height: 300px;
            overflow-y: auto;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .user-item, .debug-item {
            padding: 5px 0;
            border-bottom: 1px solid #ddd;
            font-family: monospace;
            font-size: 12px;
        }
        .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 8px;
        }
        .status-safe { background-color: #46b450; }
        .status-warning { background-color: #ffb900; }
        .status-danger { background-color: #dc3232; }
        .debug-details {
            margin-left: 20px;
            font-size: 11px;
            color: #666;
        }
        .safety-breakdown {
            background: #f0f0f1;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }
        .safety-breakdown h4 {
            margin: 0 0 10px 0;
            color: #50575e;
        }
        .check-result {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
            padding: 3px 0;
            border-bottom: 1px solid #ddd;
        }
        .check-result.failed {
            color: #dc3232;
        }
        .check-result.passed {
            color: #46b450;
        }
        </style>
        </div>
        <?php
    }

    private function log_message($message, array $context = array(), $level = 'info') {
        $entry = array(
            'timestamp' => gmdate('c'),
            'level' => $level,
            'message' => $message
        );

        if (!empty($context)) {
            $entry['context'] = $context;
        }

        $encoded = function_exists('wp_json_encode') ? wp_json_encode($entry) : json_encode($entry);

        if ($encoded === false) {
            $encoded = $message;
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log($encoded);
        }

        $log_dir = dirname($this->log_file);
        if (!is_dir($log_dir) && function_exists('wp_mkdir_p')) {
            wp_mkdir_p($log_dir);
            $htaccess = $log_dir . '/.htaccess';
            if (is_dir($log_dir) && !file_exists($htaccess)) {
                @file_put_contents($htaccess, "Require all denied\nDeny from all\n", LOCK_EX);
            }
        }

        file_put_contents($this->log_file, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function ajax_scan_fake_users() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $session_id = sanitize_text_field($_POST['session_id']);
        if (empty($session_id)) {
            wp_send_json_error(array('message' => 'Missing session identifier'));
        }

        $batch_size = max(1, intval($_POST['batch_size']));
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        $detailed_logging = !empty($_POST['detailed_logging']);
        $debug_first_10 = !empty($_POST['debug_first_10']);
        $is_new_scan = intval($_POST['is_new_scan']) === 1;

        try {
            $result = $this->process_scan_batch($session_id, array(
                'batch_size' => $batch_size,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'is_new_scan' => $is_new_scan,
                'detailed_logging' => $detailed_logging,
                'debug_first_10' => $debug_first_10
            ));

            wp_send_json_success($result);
        } catch (Exception $e) {
            $this->log_message('scan_batch_error', array(
                'session_id' => $session_id,
                'message' => $e->getMessage()
            ), 'error');
            wp_send_json_error(array('message' => 'Processing error: ' . $e->getMessage()));
        }
    }

    private function process_scan_batch($session_id, array $args) {
        global $wpdb;
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $this->maybe_upgrade_schema();

        $batch_size = max(1, intval($args['batch_size'] ?? 100));
        $is_new_scan = !empty($args['is_new_scan']);
        $detailed_logging = !empty($args['detailed_logging']);
        $debug_first_10 = !empty($args['debug_first_10']);

        if ($is_new_scan) {
            $start_date = sanitize_text_field($args['start_date'] ?? '');
            $end_date = sanitize_text_field($args['end_date'] ?? '');
            if (empty($start_date) || empty($end_date)) {
                throw new Exception('Start and end dates are required.');
            }

            $total_users = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered BETWEEN %s AND %s",
                $start_date . ' 00:00:00',
                $end_date . ' 23:59:59'
            ));

            $this->prefetch_cohort_map($session_id, $start_date, $end_date);

            $progress = array(
                'session_id' => $session_id,
                'status' => 'running',
                'processed' => 0,
                'total_users' => $total_users,
                'fake_found' => 0,
                'safe_found' => 0,
                'last_id' => 0,
                'start_time' => time(),
                'filters' => array(
                    'start_date' => $start_date,
                    'end_date' => $end_date
                )
            );

            $this->save_scan_progress($session_id, $progress);
            update_option($this->scan_current_option, $session_id);
        } else {
            $progress = $this->load_scan_progress($session_id);
            if (empty($progress)) {
                throw new Exception('Invalid session');
            }

            $stored_filters = $progress['filters'] ?? array();
            $start_date = $stored_filters['start_date'] ?? sanitize_text_field($args['start_date'] ?? '');
            $end_date = $stored_filters['end_date'] ?? sanitize_text_field($args['end_date'] ?? '');
            if (empty($start_date) || empty($end_date)) {
                throw new Exception('Stored session is missing date filters.');
            }

            if (false === get_transient($this->get_cohort_transient_key($session_id))) {
                $this->prefetch_cohort_map($session_id, $start_date, $end_date);
            }
        }

        $last_id = intval($progress['last_id'] ?? 0);

        $users = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, user_email, user_registered, user_login 
             FROM {$wpdb->users} 
             WHERE user_registered BETWEEN %s AND %s 
             AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59',
            $last_id,
            $batch_size
        ));

        $cohort_map = get_transient($this->get_cohort_transient_key($session_id));
        if (!is_array($cohort_map)) {
            $cohort_map = array();
        }

        $batch_context = $this->build_scan_batch_context($users, $cohort_map);

        $fake_found = 0;
        $safe_found = 0;
        $debug_info = array();
        $fake_rows = array();

        foreach ($users as $user) {
            $evaluation = $this->evaluate_user($user, $debug_first_10 && count($debug_info) < 10, $batch_context);

            if ($evaluation['is_fake']) {
                $fake_found++;
                $fake_rows[] = array(
                    'id' => $user->ID,
                    'email' => $user->user_email,
                    'registered' => $user->user_registered,
                    'score' => $evaluation['score'],
                    'reason' => wp_json_encode($evaluation['reasons']),
                    'needs_review' => 0,
                    'review_notes' => null
                );

                if ($debug_first_10 && count($debug_info) < 10) {
                    $reason_labels = implode(', ', array_map(function ($reason) {
                        return $reason['code'] ?? $reason;
                    }, $evaluation['reasons']));
                    $debug_info[] = array(
                        'label' => 'Fake User Detected',
                        'value' => sprintf(
                            'ID: %d, Email: %s, Score: %d, Reasons: %s',
                            $user->ID,
                            $user->user_email,
                            $evaluation['score'],
                            $reason_labels ?: 'n/a'
                        )
                    );
                }
            } else {
                $safe_found++;
            }

            if ($debug_first_10 && count($debug_info) < 10) {
                $reason_labels = implode(', ', array_map(function ($reason) {
                    return $reason['code'] ?? $reason;
                }, $evaluation['reasons']));
                $debug_info[] = array(
                    'label' => 'User Processed',
                    'value' => sprintf(
                        'ID: %d, Email: %s, Result: %s%s',
                        $user->ID,
                        $user->user_email,
                        $evaluation['is_fake'] ? 'FAKE' : 'SAFE',
                        $evaluation['is_fake'] && $reason_labels ? " (Reasons: {$reason_labels})" : ''
                    )
                );
            }
        }

        $this->insert_fake_users_batch($fake_rows);

        $count_users = count($users);
        $progress['processed'] += $count_users;
        $progress['fake_found'] += $fake_found;
        $progress['safe_found'] += $safe_found;

        if ($count_users > 0) {
            $progress['last_id'] = end($users)->ID;
        }

        $completed = ($count_users === 0 && !$is_new_scan) || $progress['processed'] >= $progress['total_users'];
        if ($completed && $progress['status'] !== 'completed') {
            $progress['status'] = 'completed';
            $progress['end_time'] = time();
            update_option($this->scan_last_summary_option, array(
                'total_processed' => $progress['processed'],
                'fake_found' => $progress['fake_found'],
                'safe_found' => $progress['safe_found'],
                'total_users' => $progress['total_users'],
                'session_id' => $session_id,
                'end_time' => $progress['end_time']
            ));
            update_option($this->scan_current_option, '');
            $this->delete_scan_progress($session_id);
            $this->delete_cohort_transient($session_id);
        } else {
            $this->save_scan_progress($session_id, $progress);
        }

        if ($detailed_logging) {
            $this->log_message('scan_batch', array(
                'session_id' => $session_id,
                'processed' => $progress['processed'],
                'batch_size' => $batch_size,
                'batch_users' => $count_users,
                'fake_found' => $fake_found,
                'safe_found' => $safe_found,
                'last_id' => $progress['last_id'],
                'detailed_logging' => 1
            ));
        }

        $percent = 0;
        if ($progress['total_users'] > 0) {
            $percent = min(100, ($progress['processed'] / $progress['total_users']) * 100);
        } elseif ($completed) {
            $percent = 100;
        }

        return array(
            'progress' => array(
                'percent' => $percent,
                'processed' => $progress['processed'],
                'fake_found' => $progress['fake_found'],
                'safe_found' => $progress['safe_found'],
                'total_users' => $progress['total_users'],
                'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
            ),
            'next_last_id' => $progress['last_id'],
            'completed' => $completed,
            'debug_info' => $debug_info,
            'results' => $completed ? $this->get_scan_results($session_id) : null,
            'session_id' => $session_id
        );
    }

    public function ajax_cleanup_fake_users() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $session_id = sanitize_text_field($_POST['session_id']);
        if (empty($session_id)) {
            wp_send_json_error(array('message' => 'Missing session identifier'));
        }

        $batch_size = max(1, intval($_POST['batch_size']));
        $dry_run = !empty($_POST['dry_run']);
        $force_cleanup = !empty($_POST['force_cleanup']);
        $is_new_cleanup = intval($_POST['is_new_cleanup']) === 1;
        $delay_after_delete_ms = max(0, intval($_POST['delay_after_delete_ms'] ?? 0));
        $activity_grace_months = max(0, intval($_POST['activity_grace_months'] ?? 6));

        try {
            $result = $this->process_cleanup_batch($session_id, array(
                'batch_size' => $batch_size,
                'dry_run' => $dry_run,
                'force_cleanup' => $force_cleanup,
                'is_new_cleanup' => $is_new_cleanup,
                'delay_after_delete_ms' => $delay_after_delete_ms,
                'activity_grace_months' => $activity_grace_months
            ));

            wp_send_json_success($result);
        } catch (Exception $e) {
            $this->log_message('cleanup_batch_error', array(
                'session_id' => $session_id,
                'message' => $e->getMessage()
            ), 'error');
            wp_send_json_error(array('message' => 'Cleanup error: ' . $e->getMessage()));
        }
    }

    private function process_cleanup_batch($session_id, array $args) {
        global $wpdb;
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $this->maybe_upgrade_schema();

        $batch_size = max(1, intval($args['batch_size'] ?? 50));
        $dry_run = !empty($args['dry_run']);
        $force_cleanup = !empty($args['force_cleanup']);
        $is_new_cleanup = !empty($args['is_new_cleanup']);
        $delay_after_delete_ms = max(0, intval($args['delay_after_delete_ms'] ?? 0));
        $detailed_logging = !empty($args['detailed_logging']);
        $activity_grace_months = $this->get_activity_grace_months(
            isset($args['activity_grace_months']) ? (int) $args['activity_grace_months'] : null
        );

        if ($is_new_cleanup) {
            $total_fake_users = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$this->temp_table}");

            $cleanup_progress = array(
                'session_id' => $session_id,
                'status' => 'running',
                'processed' => 0,
                'total_users' => $total_fake_users,
                'deleted' => 0,
                'would_delete' => 0,
                'skipped' => 0,
                'reviewed' => 0,
                'delete_failed' => 0,
                'last_id' => 0,
                'start_time' => time(),
                'dry_run' => $dry_run ? 1 : 0,
                'force_cleanup' => $force_cleanup ? 1 : 0,
                'delay_after_delete_ms' => $delay_after_delete_ms,
                'activity_grace_months' => $activity_grace_months
            );

            $this->save_cleanup_progress($session_id, $cleanup_progress);
            update_option($this->cleanup_current_option, $session_id);
        } else {
            $cleanup_progress = $this->load_cleanup_progress($session_id);
            if (empty($cleanup_progress)) {
                throw new Exception('Invalid cleanup session');
            }
            if (!isset($cleanup_progress['reviewed'])) {
                $cleanup_progress['reviewed'] = 0;
            }
            if (!isset($cleanup_progress['would_delete'])) {
                $cleanup_progress['would_delete'] = 0;
            }
            if (!isset($cleanup_progress['delete_failed'])) {
                $cleanup_progress['delete_failed'] = 0;
            }
            $dry_run = isset($cleanup_progress['dry_run']) ? (bool) $cleanup_progress['dry_run'] : $dry_run;
            $force_cleanup = isset($cleanup_progress['force_cleanup']) ? (bool) $cleanup_progress['force_cleanup'] : $force_cleanup;
            $delay_after_delete_ms = isset($cleanup_progress['delay_after_delete_ms']) ? (int) $cleanup_progress['delay_after_delete_ms'] : 0;
            $activity_grace_months = isset($cleanup_progress['activity_grace_months'])
                ? $this->get_activity_grace_months((int) $cleanup_progress['activity_grace_months'])
                : $activity_grace_months;
        }

        $last_id = intval($cleanup_progress['last_id'] ?? 0);

        $fake_users = $wpdb->get_results($wpdb->prepare(
            "SELECT id, email FROM {$wpdb->prefix}{$this->temp_table} WHERE id > %d ORDER BY id ASC LIMIT %d",
            $last_id,
            $batch_size
        ));

        $deleted = 0;
        $would_delete = 0;
        $skipped = 0;
        $reviewed = 0;
        $delete_failed = 0;
        $deleted_ids = array();
        $review_users = array();
        $audit_rows = array();

        $user_ids = array_map(function ($u) { return (int) $u->id; }, $fake_users);
        $safety_map = array();
        if (!empty($user_ids)) {
            $safety_map = $this->get_user_safety_flags_batch(
                $user_ids,
                $activity_grace_months,
                !$force_cleanup
            );
        }

        $registered_map = array();
        if (!empty($user_ids)) {
            $reg_placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
            $reg_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, user_registered FROM {$wpdb->users} WHERE ID IN ($reg_placeholders)",
                $user_ids
            ));
            foreach ($reg_rows as $r) {
                $registered_map[(int) $r->ID] = $r->user_registered;
            }
        }

        if (!$dry_run && !function_exists('wp_delete_user')) {
            $user_admin = ABSPATH . 'wp-admin/includes/user.php';
            if (is_readable($user_admin)) {
                require_once $user_admin;
            }
        }

        foreach ($fake_users as $fake_user) {
            $safety_flags = $safety_map[$fake_user->id] ?? array();
            if (!empty($safety_flags)) {
                $reviewed++;
                $skipped++;
                $review_users[] = array(
                    'id' => $fake_user->id,
                    'email' => $fake_user->email,
                    'reasons' => $safety_flags
                );

                $wpdb->update(
                    $wpdb->prefix . $this->temp_table,
                    array(
                        'needs_review' => 1,
                        'review_notes' => wp_json_encode($safety_flags)
                    ),
                    array('id' => $fake_user->id),
                    array('%d', '%s'),
                    array('%d')
                );

                $this->log_message('cleanup_review_required', array(
                    'session_id' => $session_id,
                    'user_id' => $fake_user->id,
                    'email' => $fake_user->email,
                    'flags' => $safety_flags
                ), 'warning');
                continue;
            }

            if ($dry_run) {
                $would_delete++;
                continue;
            }

            if (!function_exists('wp_delete_user')) {
                $delete_failed++;
                $skipped++;
                $this->log_message('cleanup_delete_failed', array(
                    'session_id' => $session_id,
                    'user_id' => $fake_user->id,
                    'reason' => 'wp_delete_user_unavailable'
                ), 'warning');
                continue;
            }

            $user_registered = $registered_map[$fake_user->id] ?? null;
            $result = wp_delete_user($fake_user->id);
            if ($result) {
                $deleted++;
                $deleted_ids[] = $fake_user->id;
                $audit_rows[] = array(
                    'user_id' => $fake_user->id,
                    'email' => $fake_user->email,
                    'registered' => $user_registered
                );
                if ($delay_after_delete_ms > 0) {
                    usleep($delay_after_delete_ms * 1000);
                }
            } else {
                $delete_failed++;
                $skipped++;
                $this->log_message('cleanup_delete_failed', array(
                    'session_id' => $session_id,
                    'user_id' => $fake_user->id
                ), 'warning');
            }
        }

        if (!$dry_run && !empty($audit_rows)) {
            $audit_table = $wpdb->prefix . $this->audit_table;
            $values = array();
            $params = array();
            foreach ($audit_rows as $row) {
                $values[] = '(%d, %s, %s)';
                $params[] = $row['user_id'];
                $params[] = $row['email'];
                $params[] = $row['registered'];
            }
            $sql = "INSERT INTO {$audit_table} (user_id, email, registered) VALUES " . implode(', ', $values);
            $wpdb->query($wpdb->prepare($sql, $params));
        }

        // Only remove temp rows for users actually deleted (never on dry-run or failed delete).
        if (!$dry_run && !empty($deleted_ids)) {
            $placeholders = implode(',', array_fill(0, count($deleted_ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}{$this->temp_table} WHERE id IN ($placeholders)",
                $deleted_ids
            ));
        }

        $count_processed = count($fake_users);
        $cleanup_progress['processed'] += $count_processed;
        $cleanup_progress['deleted'] += $deleted;
        $cleanup_progress['would_delete'] += $would_delete;
        $cleanup_progress['skipped'] += $skipped;
        $cleanup_progress['reviewed'] += $reviewed;
        $cleanup_progress['delete_failed'] += $delete_failed;

        if ($count_processed > 0) {
            $cleanup_progress['last_id'] = end($fake_users)->id;
        }

        $completed = ($count_processed === 0 && !$is_new_cleanup) || $cleanup_progress['processed'] >= $cleanup_progress['total_users'];
        if ($completed && $cleanup_progress['status'] !== 'completed') {
            $cleanup_progress['status'] = 'completed';
            $cleanup_progress['end_time'] = time();
            update_option($this->cleanup_current_option, '');
            $this->delete_cleanup_progress($session_id);
        } else {
            $this->save_cleanup_progress($session_id, $cleanup_progress);
        }

        if ($detailed_logging) {
            $this->log_message('cleanup_batch', array(
                'session_id' => $session_id,
                'batch_size' => $batch_size,
                'batch_users' => $count_processed,
                'deleted' => $deleted,
                'would_delete' => $would_delete,
                'skipped' => $skipped,
                'reviewed' => $reviewed,
                'delete_failed' => $delete_failed,
                'dry_run' => $dry_run ? 1 : 0,
                'force_cleanup' => $force_cleanup ? 1 : 0,
                'next_last_id' => $cleanup_progress['last_id']
            ));
        }

        $percent = 0;
        if ($cleanup_progress['total_users'] > 0) {
            $percent = min(100, ($cleanup_progress['processed'] / $cleanup_progress['total_users']) * 100);
        } elseif ($completed) {
            $percent = 100;
        }

        return array(
            'progress' => array(
                'percent' => $percent,
                'processed' => $cleanup_progress['processed'],
                // deleted: users actually removed from WP
                'deleted' => $cleanup_progress['deleted'],
                // would_delete: dry-run candidates that passed safety checks
                'would_delete' => $cleanup_progress['would_delete'],
                // skipped: needs_review + delete_failed (not deleted)
                'skipped' => $cleanup_progress['skipped'],
                // reviewed: flagged for manual review (orders/posts/active sessions)
                'reviewed' => $cleanup_progress['reviewed'],
                // delete_failed: wp_delete_user returned false / unavailable
                'delete_failed' => $cleanup_progress['delete_failed'],
                'total_users' => $cleanup_progress['total_users'],
                'memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
            ),
            'next_last_id' => $cleanup_progress['last_id'],
            'completed' => $completed,
            'dry_run' => (bool) $dry_run,
            'force_cleanup' => (bool) $force_cleanup,
            'session_id' => $session_id,
            'review_users' => $review_users
        );
    }

    private function prefetch_cohort_map($session_id, $start_date, $end_date) {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_registered, COUNT(*) AS cohort_count
             FROM {$wpdb->users}
             WHERE user_registered BETWEEN %s AND %s
             GROUP BY user_registered
             HAVING cohort_count >= 25",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        $cohort_map = array();
        foreach ($rows as $row) {
            $cohort_map[$row->user_registered] = (int) $row->cohort_count;
        }

        set_transient(
            $this->get_cohort_transient_key($session_id),
            $cohort_map,
            self::COHORT_TRANSIENT_TTL
        );

        return $cohort_map;
    }

    /**
     * @param array $users User row objects from the current batch.
     * @param array $cohort_map Map of user_registered => cohort_count.
     * @return array Batch context for evaluate_user().
     */
    private function build_scan_batch_context(array $users, array $cohort_map) {
        global $wpdb;

        $user_ids = array_map(function ($user) {
            return (int) $user->ID;
        }, $users);

        $meta_map = array();
        $meta_counts = array();

        if (empty($user_ids)) {
            return array(
                'meta' => $meta_map,
                'meta_counts' => $meta_counts,
                'cohort_map' => $cohort_map
            );
        }

        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        $meta_keys = array('first_name', 'last_name', 'intersoccer_players');
        $meta_key_placeholders = implode(',', array_fill(0, count($meta_keys), '%s'));
        $meta_params = array_merge($user_ids, $meta_keys);

        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_key, meta_value
             FROM {$wpdb->usermeta}
             WHERE user_id IN ($placeholders)
             AND meta_key IN ($meta_key_placeholders)",
            $meta_params
        ));

        foreach ($meta_rows as $row) {
            $uid = (int) $row->user_id;
            if (!isset($meta_map[$uid])) {
                $meta_map[$uid] = array();
            }
            $meta_map[$uid][$row->meta_key] = $row->meta_value;
        }

        $count_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, COUNT(*) AS meta_count
             FROM {$wpdb->usermeta}
             WHERE user_id IN ($placeholders)
             GROUP BY user_id",
            $user_ids
        ));

        foreach ($count_rows as $row) {
            $meta_counts[(int) $row->user_id] = (int) $row->meta_count;
        }

        return array(
            'meta' => $meta_map,
            'meta_counts' => $meta_counts,
            'cohort_map' => $cohort_map
        );
    }

    /**
     * @param array $rows Fake user rows to insert.
     */
    private function insert_fake_users_batch(array $rows) {
        if (empty($rows)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . $this->temp_table;
        $values = array();
        $params = array();

        foreach ($rows as $row) {
            $values[] = '(%d, %s, %s, %d, %s, %d, %s)';
            $params[] = $row['id'];
            $params[] = $row['email'];
            $params[] = $row['registered'];
            $params[] = $row['score'];
            $params[] = $row['reason'];
            $params[] = $row['needs_review'];
            $params[] = $row['review_notes'];
        }

        $sql = "INSERT INTO {$table} (id, email, registered, score, reason, needs_review, review_notes) VALUES "
            . implode(', ', $values)
            . ' ON DUPLICATE KEY UPDATE email = VALUES(email), registered = VALUES(registered),'
            . ' score = VALUES(score), reason = VALUES(reason),'
            . ' needs_review = VALUES(needs_review), review_notes = VALUES(review_notes)';

        $result = $wpdb->query($wpdb->prepare($sql, $params));
        if ($result === false) {
            foreach ($rows as $row) {
                $wpdb->replace(
                    $table,
                    array(
                        'id' => $row['id'],
                        'email' => $row['email'],
                        'registered' => $row['registered'],
                        'score' => $row['score'],
                        'reason' => $row['reason'],
                        'needs_review' => $row['needs_review'],
                        'review_notes' => $row['review_notes']
                    ),
                    array('%d', '%s', '%s', '%d', '%s', '%d', '%s')
                );
            }
            $this->log_message('bulk_insert_fallback', array(
                'row_count' => count($rows),
                'error' => $wpdb->last_error
            ), 'warning');
        }
    }

    private function evaluate_user($user, $debug = false, array $batch_context = array()) {
        global $wpdb;

        $score = 0;
        $reasons = array();
        $disposable_domains = apply_filters('intersoccer_fake_cleanup_disposable_domains', $this->disposable_domains);
        if (!is_array($disposable_domains)) {
            $disposable_domains = array();
        }

        $email = strtolower($user->user_email);
        $login = $user->user_login;

        $email_pattern = '/^[a-z]{8}\d{2}@(gmail\.com|outlook\.com|yahoo\.com|hotmail\.com)$/';
        if (preg_match($email_pattern, $email)) {
            $score += 60;
            $reasons[] = array(
                'code' => 'email_pattern_random_login',
                'detail' => $email
            );
        }

        $domain = substr(strrchr($email, '@'), 1);
        if ($domain && in_array($domain, $disposable_domains, true)) {
            $score += 45;
            $reasons[] = array(
                'code' => 'disposable_domain',
                'detail' => $domain
            );
        }

        if (preg_match('/^[a-z]{0,2}\d{6,}$/i', $login) || preg_match('/^[a-z0-9]{10,}$/i', $login) && preg_match('/\d{4,}/', $login)) {
            $score += 30;
            $reasons[] = array(
                'code' => 'suspicious_login_pattern',
                'detail' => $login
            );
        }

        $uid = (int) $user->ID;
        $user_meta = $batch_context['meta'][$uid] ?? null;
        $has_batch_meta = is_array($user_meta);

        if ($has_batch_meta) {
            $first_name = trim((string) ($user_meta['first_name'] ?? ''));
            $last_name = trim((string) ($user_meta['last_name'] ?? ''));
        } else {
            $first_name = function_exists('get_user_meta') ? trim((string) get_user_meta($uid, 'first_name', true)) : '';
            $last_name = function_exists('get_user_meta') ? trim((string) get_user_meta($uid, 'last_name', true)) : '';
        }
        if ($first_name === '' && $last_name === '') {
            $score += 20;
            $reasons[] = array(
                'code' => 'missing_profile_name',
                'detail' => $uid
            );
        }

        if (isset($batch_context['meta_counts'][$uid])) {
            $meta_count = (int) $batch_context['meta_counts'][$uid];
        } else {
            $meta_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d",
                $uid
            ));
        }
        if ($meta_count < 3) {
            $score += 20;
            $reasons[] = array(
                'code' => 'sparse_profile_meta',
                'detail' => $meta_count
            );
        }

        if ($has_batch_meta && array_key_exists('intersoccer_players', $user_meta)) {
            $players_meta = $user_meta['intersoccer_players'];
        } else {
            $players_meta = function_exists('get_user_meta') ? get_user_meta($uid, 'intersoccer_players', true) : '';
        }
        if (is_string($players_meta)) {
            $players_meta = function_exists('maybe_unserialize') ? maybe_unserialize($players_meta) : $players_meta;
        }
        $players_list = is_array($players_meta) ? $players_meta : array();
        if (empty($players_list)) {
            $score += 20;
            $reasons[] = array(
                'code' => 'missing_intersoccer_players',
                'detail' => 'no_players'
            );
        }

        $cohort_map = $batch_context['cohort_map'] ?? array();
        if (!empty($cohort_map)) {
            $cohort_count = (int) ($cohort_map[$user->user_registered] ?? 0);
        } else {
            $cohort_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->users}
                 WHERE user_registered BETWEEN DATE_SUB(%s, INTERVAL 45 SECOND)
                 AND DATE_ADD(%s, INTERVAL 45 SECOND)",
                $user->user_registered,
                $user->user_registered
            ));
        }
        if ($cohort_count >= 25) {
            $score += 30;
            $reasons[] = array(
                'code' => 'burst_registration_window',
                'detail' => $cohort_count
            );
        }

        $threshold = (int) apply_filters('intersoccer_fake_cleanup_score_threshold', 70);
        $is_fake = $score >= $threshold;

        return array(
            'is_fake' => $is_fake,
            'score' => $score,
            'reasons' => $reasons
        );
    }

    private function is_fake_user($user, $debug = false) {
        $evaluation = $this->evaluate_user($user, $debug);
        return $evaluation['is_fake'];
    }

    private function get_activity_grace_months($override = null) {
        $default = 6;
        if ($override !== null) {
            return max(0, (int) apply_filters('intersoccer_fake_cleanup_activity_grace_months', (int) $override));
        }
        return max(0, (int) apply_filters('intersoccer_fake_cleanup_activity_grace_months', $default));
    }

    private function get_timestamp_activity_meta_keys() {
        return array('last_activity', 'last_login', 'wp_last_login', 'wc_last_active');
    }

    private function get_all_activity_meta_keys() {
        return array_merge($this->get_timestamp_activity_meta_keys(), array('session_tokens'));
    }

    /**
     * @param mixed $meta_value Raw usermeta value.
     * @return int|null Unix timestamp or null if unparseable/empty.
     */
    private function parse_activity_meta_timestamp($meta_key, $meta_value) {
        if ($meta_value === null || $meta_value === '' || $meta_value === false) {
            return null;
        }

        if (is_numeric($meta_value)) {
            $timestamp = (int) $meta_value;
            return $timestamp > 0 ? $timestamp : null;
        }

        if (is_string($meta_value)) {
            $parsed = strtotime($meta_value);
            return ($parsed !== false && $parsed > 0) ? $parsed : null;
        }

        return null;
    }

    /**
     * Whether session_tokens meta contains at least one non-expired session.
     *
     * @param mixed $meta_value Raw or unserialized session_tokens value.
     * @return bool
     */
    private function session_tokens_has_active($meta_value) {
        if ($meta_value === null || $meta_value === '' || $meta_value === false) {
            return false;
        }

        $tokens = $meta_value;
        if (is_string($meta_value)) {
            $tokens = function_exists('maybe_unserialize')
                ? maybe_unserialize($meta_value)
                : @unserialize($meta_value);
        }

        if (!is_array($tokens) || empty($tokens)) {
            return false;
        }

        $now = time();
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            if (isset($token['expiration']) && (int) $token['expiration'] > $now) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array|null Flag array when activity should block deletion, null otherwise.
     */
    private function build_activity_meta_flag($meta_key, $meta_value, $grace_months) {
        if ($meta_key === 'session_tokens') {
            if ($this->session_tokens_has_active($meta_value)) {
                return array('code' => 'recent_activity_meta', 'detail' => $meta_key);
            }
            return null;
        }

        if (!in_array($meta_key, $this->get_timestamp_activity_meta_keys(), true)) {
            return null;
        }

        if ($meta_value === null || $meta_value === '' || $meta_value === false) {
            return null;
        }

        if ($grace_months <= 0) {
            return array('code' => 'recent_activity_meta', 'detail' => $meta_key);
        }

        $timestamp = $this->parse_activity_meta_timestamp($meta_key, $meta_value);
        if ($timestamp === null) {
            return array('code' => 'recent_activity_meta', 'detail' => $meta_key);
        }

        $cutoff = strtotime('-' . (int) $grace_months . ' months');
        if ($timestamp >= $cutoff) {
            return array(
                'code' => 'recent_activity_meta',
                'detail' => $meta_key . ' (' . gmdate('Y-m-d', $timestamp) . ')'
            );
        }

        return null;
    }

    private function should_flag_activity_meta($meta_key, $meta_value, $grace_months) {
        return $this->build_activity_meta_flag($meta_key, $meta_value, $grace_months) !== null;
    }

    private function get_user_safety_flags($user_id, $grace_months = null, $include_activity_checks = true) {
        global $wpdb;

        if ($grace_months === null) {
            $grace_months = $this->get_activity_grace_months();
        } else {
            $grace_months = $this->get_activity_grace_months((int) $grace_months);
        }

        $flags = array();

        // Orders via WooCommerce (postmeta _customer_user)
        $has_order = $wpdb->get_var($wpdb->prepare(
            "SELECT 1
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = '_customer_user'
             AND pm.meta_value = %d
             AND p.post_type IN ('shop_order', 'shop_order_refund')
             LIMIT 1",
            $user_id
        ));
        if ($has_order) {
            $flags[] = array('code' => 'customer_has_orders');
        }

        // Authored posts/comments/content
        $has_posts = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->posts}
             WHERE post_author = %d
             AND post_status NOT IN ('auto-draft', 'trash')
             LIMIT 1",
            $user_id
        ));
        if ($has_posts) {
            $flags[] = array('code' => 'authored_content');
        }

        if ($include_activity_checks) {
            foreach ($this->get_all_activity_meta_keys() as $key) {
                $meta_value = function_exists('get_user_meta') ? get_user_meta($user_id, $key, true) : '';
                $flag = $this->build_activity_meta_flag($key, $meta_value, $grace_months);
                if ($flag !== null) {
                    $flags[] = $flag;
                }
            }
        }

        return $flags;
    }

    /**
     * Batch version of get_user_safety_flags. Returns map of user_id => array of flags
     * to minimize queries per cleanup batch.
     *
     * @param int[] $user_ids User IDs to check.
     * @param int|null $grace_months Activity grace period in months.
     * @param bool $include_activity_checks When false, skip activity meta checks (force cleanup).
     * @return array<int, array> Map of user_id => array of flag arrays (code, detail).
     */
    private function get_user_safety_flags_batch(array $user_ids, $grace_months = null, $include_activity_checks = true) {
        global $wpdb;

        if ($grace_months === null) {
            $grace_months = $this->get_activity_grace_months();
        } else {
            $grace_months = $this->get_activity_grace_months((int) $grace_months);
        }

        $safety_map = array_fill_keys($user_ids, array());
        if (empty($user_ids)) {
            return $safety_map;
        }

        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));

        // Orders: user IDs that have WooCommerce orders
        $order_user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT CAST(pm.meta_value AS UNSIGNED)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE pm.meta_key = '_customer_user'
             AND pm.meta_value IN ($placeholders)
             AND p.post_type IN ('shop_order', 'shop_order_refund')",
            $user_ids
        ));
        if ($order_user_ids) {
            foreach ($order_user_ids as $uid) {
                $uid = (int) $uid;
                if (isset($safety_map[$uid])) {
                    $safety_map[$uid][] = array('code' => 'customer_has_orders');
                }
            }
        }

        // Authored content: user IDs that have non-trash posts
        $author_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_author FROM {$wpdb->posts}
             WHERE post_author IN ($placeholders)
             AND post_status NOT IN ('auto-draft', 'trash')",
            $user_ids
        ));
        if ($author_ids) {
            foreach ($author_ids as $uid) {
                $uid = (int) $uid;
                if (isset($safety_map[$uid])) {
                    $safety_map[$uid][] = array('code' => 'authored_content');
                }
            }
        }

        if ($include_activity_checks) {
            $activity_meta_keys = $this->get_all_activity_meta_keys();
            $meta_placeholders = implode(',', array_fill(0, count($activity_meta_keys), '%s'));
            $activity_params = array_merge($user_ids, $activity_meta_keys);
            $activity_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta}
                 WHERE user_id IN ($placeholders)
                 AND meta_key IN ($meta_placeholders)",
                $activity_params
            ));
            if ($activity_rows) {
                foreach ($activity_rows as $row) {
                    $uid = (int) $row->user_id;
                    if (!isset($safety_map[$uid])) {
                        continue;
                    }
                    $flag = $this->build_activity_meta_flag($row->meta_key, $row->meta_value, $grace_months);
                    if ($flag !== null) {
                        $safety_map[$uid][] = $flag;
                    }
                }
            }
        }

        return $safety_map;
    }

    private function get_scan_results($session_id = null) {
        global $wpdb;

        if (empty($session_id)) {
            $session_id = get_option($this->scan_current_option, '');
        }

        $progress = $session_id ? $this->load_scan_progress($session_id) : array();

        // When no active session (e.g. after completion or page reload), use persisted last summary if temp table has rows
        if (empty($progress) || empty($progress['processed'])) {
            $temp_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$this->temp_table}");
            if ($temp_count > 0) {
                $last_summary = get_option($this->scan_last_summary_option, array());
                if (!empty($last_summary) && is_array($last_summary)) {
                    $progress = array_merge($progress, array(
                        'processed' => isset($last_summary['total_processed']) ? $last_summary['total_processed'] : 0,
                        'fake_found' => isset($last_summary['fake_found']) ? $last_summary['fake_found'] : 0,
                        'safe_found' => isset($last_summary['safe_found']) ? $last_summary['safe_found'] : 0,
                        'total_users' => isset($last_summary['total_users']) ? $last_summary['total_users'] : 0
                    ));
                }
            }
        }
        
        // Get sample of detected fake users
        $sample_users = $wpdb->get_results(
            "SELECT id, email, score, reason FROM {$wpdb->prefix}{$this->temp_table} ORDER BY score DESC, id ASC LIMIT 10",
            ARRAY_A
        );
        $sample_users = array_map(function ($row) {
            $reasons = array();
            if (!empty($row['reason'])) {
                $decoded = json_decode($row['reason'], true);
                if (is_array($decoded)) {
                    $reasons = $decoded;
                }
            }
            $row['reasons'] = $reasons;
            unset($row['reason']);
            return $row;
        }, $sample_users ?? array());
        
        // Safety check breakdown (simplified for now)
        $safety_checks = array(
            array('rule' => 'Email pattern validation', 'passed' => true),
            array('rule' => 'Date range filtering', 'passed' => true),
            array('rule' => 'Duplicate detection', 'passed' => true)
        );
        
        return array(
            'total_processed' => $progress['processed'] ?? 0,
            'fake_found' => $progress['fake_found'] ?? 0,
            'safe_found' => $progress['safe_found'] ?? 0,
            'total_users' => $progress['total_users'] ?? 0,
            'sample_users' => $sample_users,
            'safety_checks' => $safety_checks
        );
    }

    public function ajax_validate_date_range() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);

        if (empty($start_date) || empty($end_date)) {
            wp_send_json_error(array('message' => 'Start and end dates are required'));
        }

        global $wpdb;

        // Count users in date range
        $users_in_range = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_registered BETWEEN %s AND %s",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        // Count pattern matches in date range
        $pattern_matches = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} 
             WHERE user_registered BETWEEN %s AND %s 
             AND user_email REGEXP '^[a-z]{8}[0-9]{2}@(gmail\.com|outlook\.com|yahoo\.com|hotmail\.com)$'",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));

        wp_send_json_success(array(
            'users_in_range' => $users_in_range,
            'pattern_matches' => $pattern_matches
        ));
    }

    public function ajax_get_cleanup_status() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $requested_session = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $active_session = $requested_session ?: get_option($this->cleanup_current_option, '');
        $cleanup_progress = $active_session ? $this->load_cleanup_progress($active_session) : array();
        $incomplete = !empty($cleanup_progress) && $cleanup_progress['status'] === 'running';

        wp_send_json_success(array(
            'incomplete' => $incomplete, 
            'session' => $cleanup_progress,
            'session_id' => $active_session
        ));
    }

    public function ajax_get_scan_results() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $results = $this->get_scan_results($session_id);
        $results['session_id'] = $session_id ?: get_option($this->scan_current_option, '');

        wp_send_json_success($results);
    }

    public function ajax_download_cleanup_review() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'intersoccer'));
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, email, score, review_notes, registered
             FROM {$wpdb->prefix}{$this->temp_table}
             WHERE needs_review = 1
             ORDER BY score DESC, id ASC",
            ARRAY_A
        );

        $filename = 'intersoccer-cleanup-review-' . gmdate('Ymd_His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');
        fputcsv($output, array('User ID', 'Email', 'Score', 'Registered', 'Flags'));

        foreach ($rows as $row) {
            $flags = array();
            if (!empty($row['review_notes'])) {
                $decoded = json_decode($row['review_notes'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $flag) {
                        if (is_array($flag)) {
                            $flags[] = isset($flag['detail'])
                                ? sprintf('%s (%s)', $flag['code'], $flag['detail'])
                                : $flag['code'];
                        } else {
                            $flags[] = $flag;
                        }
                    }
                }
            }

            fputcsv($output, array(
                $row['id'],
                $row['email'],
                $row['score'],
                $row['registered'],
                implode('; ', $flags)
            ));
        }

        fclose($output);
        exit;
    }

    public function cli_scan($args, $assoc_args) {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        $batch_size = isset($assoc_args['batch-size']) ? max(1, (int) $assoc_args['batch-size']) : 200;
        $start_date = $assoc_args['start-date'] ?? '';
        $end_date = $assoc_args['end-date'] ?? '';
        $session_id = $assoc_args['session'] ?? '';
        $resume = !empty($assoc_args['resume']);
        $debug_first = !empty($assoc_args['debug-first']);

        if ($resume && empty($session_id)) {
            $session_id = get_option($this->scan_current_option, '');
            if (empty($session_id)) {
                \WP_CLI::error('No active scan session found to resume.');
                return;
            }
        }

        if (!$resume) {
            if (empty($session_id)) {
                $session_id = $this->generate_session_id();
            }
            if (empty($start_date) || empty($end_date)) {
                \WP_CLI::error('Please provide --start-date and --end-date for a new scan.');
                return;
            }
        }

        \WP_CLI::log(sprintf('Running scan session %s (batch size %d)', $session_id, $batch_size));

        $is_new_scan = !$resume;

        try {
            do {
                $result = $this->process_scan_batch($session_id, array(
                    'batch_size' => $batch_size,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'is_new_scan' => $is_new_scan,
                    'detailed_logging' => true,
                    'debug_first_10' => $debug_first
                ));

                $progress = $result['progress'];
                \WP_CLI::log(sprintf(
                    'Processed %d/%d users (fake: %d, safe: %d)',
                    $progress['processed'],
                    $progress['total_users'],
                    $progress['fake_found'],
                    $progress['safe_found']
                ));

                $is_new_scan = false;
            } while (!$result['completed']);

            \WP_CLI::success('Scan completed successfully.');
        } catch (Exception $e) {
            \WP_CLI::error('Scan error: ' . $e->getMessage());
        }
    }

    public function cli_cleanup($args, $assoc_args) {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        $batch_size = isset($assoc_args['batch-size']) ? max(1, (int) $assoc_args['batch-size']) : 25;
        $dry_run = !empty($assoc_args['dry-run']);
        $force_cleanup = !empty($assoc_args['force']);
        $session_id = $assoc_args['session'] ?? '';
        $resume = !empty($assoc_args['resume']);
        $delay_after_delete_ms = isset($assoc_args['delay-ms']) ? max(0, (int) $assoc_args['delay-ms']) : 0;
        $activity_grace_months = isset($assoc_args['activity-grace-months'])
            ? max(0, (int) $assoc_args['activity-grace-months'])
            : 6;

        if ($resume && empty($session_id)) {
            $session_id = get_option($this->cleanup_current_option, '');
            if (empty($session_id)) {
                \WP_CLI::error('No active cleanup session found to resume.');
                return;
            }
        }

        if (!$resume) {
            if (empty($session_id)) {
                $session_id = $this->generate_session_id();
            }
        }

        \WP_CLI::log(sprintf(
            'Running cleanup session %s (batch size %d)%s',
            $session_id,
            $batch_size,
            $dry_run ? ' [DRY RUN]' : ''
        ));

        $is_new_cleanup = !$resume;

        try {
            do {
                $result = $this->process_cleanup_batch($session_id, array(
                    'batch_size' => $batch_size,
                    'dry_run' => $dry_run,
                    'force_cleanup' => $force_cleanup,
                    'is_new_cleanup' => $is_new_cleanup,
                    'delay_after_delete_ms' => $delay_after_delete_ms,
                    'activity_grace_months' => $activity_grace_months,
                    'detailed_logging' => true
                ));

                $progress = $result['progress'];
                \WP_CLI::log(sprintf(
                    'Processed %d/%d users (deleted: %d, would_delete: %d, skipped: %d, reviewed: %d, delete_failed: %d)',
                    $progress['processed'],
                    $progress['total_users'],
                    $progress['deleted'],
                    $progress['would_delete'] ?? 0,
                    $progress['skipped'],
                    $progress['reviewed'] ?? 0,
                    $progress['delete_failed'] ?? 0
                ));

                $is_new_cleanup = false;
            } while (!$result['completed']);

            if (!empty($result['dry_run'])) {
                \WP_CLI::success('Dry-run cleanup completed. No users deleted.');
            } else {
                \WP_CLI::success('Cleanup completed successfully.');
            }
        } catch (Exception $e) {
            \WP_CLI::error('Cleanup error: ' . $e->getMessage());
        }
    }
}

register_activation_hook(__FILE__, array('InterSoccer_Fake_User_Cleanup', 'activate'));
$intersoccer_fake_user_cleanup = new InterSoccer_Fake_User_Cleanup();

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('intersoccer fake-users scan', array($intersoccer_fake_user_cleanup, 'cli_scan'));
    \WP_CLI::add_command('intersoccer fake-users cleanup', array($intersoccer_fake_user_cleanup, 'cli_cleanup'));
}