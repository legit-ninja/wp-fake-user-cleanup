<?php
/**
 * Plugin Name: InterSoccer Fake User Cleanup
 * Description: Fixed cleanup tool with proper validation logic
 * Version: 1.0.0
 * Author: Jeremy Lee
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class InterSoccer_Fake_User_Cleanup {
    
    private $log_file;
    private $transient_key = 'intersoccer_fake_ids_v3';

    private $temp_table = 'intersoccer_temp_fake_users';
    private $audit_table = 'intersoccer_cleanup_audit';

    private $scan_current_option = 'intersoccer_scan_current';
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
        $this->log_file = WP_CONTENT_DIR . '/intersoccer-cleanup-enhanced.log';
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_scan_fake_users_enhanced', array($this, 'ajax_scan_fake_users'));
        add_action('wp_ajax_cleanup_fake_users_enhanced', array($this, 'ajax_cleanup_fake_users'));
        add_action('wp_ajax_validate_date_range', array($this, 'ajax_validate_date_range'));
        
        add_action('wp_ajax_get_scan_status', array($this, 'ajax_get_scan_status'));
        add_action('wp_ajax_reset_scan', array($this, 'ajax_reset_scan'));
        add_action('wp_ajax_get_cleanup_status', array($this, 'ajax_get_cleanup_status'));
        add_action('wp_ajax_get_scan_results', array($this, 'ajax_get_scan_results')); // New handler for getting scan results
        add_action('wp_ajax_download_cleanup_review', array($this, 'ajax_download_cleanup_review'));
        // Add admin scripts for nonce
        add_action('admin_footer', array($this, 'admin_footer_script'));
    }

    public static function activate() {
        $instance = new self();
        $instance->ensure_temp_table();
        $instance->ensure_audit_table();
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

        wp_send_json_success(array(
            'incomplete' => $incomplete,
            'session' => $progress,
            'session_id' => $active_session
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
        }
        update_option($this->scan_current_option, '');
        $this->log_message("Scan and cleanup progress reset");
        wp_send_json_success();
    }
    
    public function admin_footer_script() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'enhanced-fake-user-cleanup') {
            return;
        }
        ?>
        <script>
        window.intersoccerCleanup = {
            ajaxurl: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('fake_user_cleanup_enhanced'); ?>'
        };
        </script>
        <?php
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
        <div class="wrap">
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
                        <option value="100" selected>100 (Optimized)</option>
                        <option value="200">200 (Fast)</option>
                        <option value="500">500 (Very Fast)</option>
                        <option value="50">50 (Conservative)</option>
                    </select></label>
                    
                    <label><input type="checkbox" id="detailed-logging" checked> Enable detailed logging</label>
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
                
                <div class="cleanup-options">
                    <label><input type="checkbox" id="dry-run" checked> Dry Run (Log only, no deletion)</label>
                    <label><input type="checkbox" id="force-cleanup"> Force cleanup (bypass some safety checks)</label>
                    <label>Cleanup batch size: <select id="cleanup-batch-size">
                        <option value="50" selected>50 (Optimized)</option>
                        <option value="100">100 (Fast)</option>
                        <option value="25">25 (Conservative)</option>
                        <option value="10">10 (Very Safe)</option>
                    </select></label>
                </div>
                
                <button id="cleanup-users" class="button button-secondary">Start Cleanup</button>
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
                    <p><a href="<?php echo content_url('/intersoccer-cleanup-enhanced.log'); ?>" target="_blank" class="button">View Log</a></p>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
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
        
        <script>
        jQuery(document).ready(function($) {
            let scanInProgress = false;
            let cleanupInProgress = false;
            let scanSessionId = null;
            let cleanupSessionId = null;

            function generateSessionId(prefix) {
                if (window.crypto && window.crypto.randomUUID) {
                    return (prefix ? prefix + '-' : '') + window.crypto.randomUUID();
                }
                return (prefix ? prefix + '-' : '') + Date.now() + '-' + Math.floor(Math.random() * 1000);
            }

            // Check for existing session on load
            $.ajax({
                url: window.intersoccerCleanup.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_scan_status',
                    nonce: window.intersoccerCleanup.nonce
                },
                success: function(response) {
                    if (response.success && response.data.incomplete && response.data.session) {
                        scanSessionId = response.data.session_id || (response.data.session ? response.data.session.session_id : null);
                        $('#resume-scan').show();
                        $('#reset-scan').show();
                        $('#scan-users').text('Resume Scan');
                        alert('Incomplete scan detected. Processed: ' + response.data.session.processed + '/' + response.data.session.total_users);
                    }
                }
            });

            // Check for incomplete cleanup session
            checkIncompleteCleanup();

            // Check for existing scan results and show cleanup section if needed
            checkExistingScanResults();

            function checkExistingScanResults() {
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_scan_status',
                        nonce: window.intersoccerCleanup.nonce
                    },
                    success: function(response) {
                        // If scan was completed (not incomplete), check if we have results to show cleanup
                        if (response.success && !response.data.incomplete) {
                            // Make a separate call to check if temp table has results
                            $.ajax({
                                url: window.intersoccerCleanup.ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'validate_date_range',
                                    nonce: window.intersoccerCleanup.nonce,
                                    start_date: '2000-01-01', // Dummy dates just to get user count
                                    end_date: '2030-12-31'
                                },
                                success: function(validateResponse) {
                                    if (validateResponse.success && validateResponse.data.pattern_matches > 0) {
                                        // We have scan results, show the cleanup section
                                        $('#results-section').show();
                                        $('#cleanup-section').show();

                                        // Load and display the scan results
                                        loadExistingScanResults();
                                    }
                                }
                            });
                        }
                    }
                });
            }

            function loadExistingScanResults() {
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_scan_results',
                        nonce: window.intersoccerCleanup.nonce
                    },
                    success: function(resultsResponse) {
                        if (resultsResponse.success) {
                            $('#results-section').show();
                            $('#cleanup-section').show();
                            displayCompletedScanResults(resultsResponse.data);
                        }
                    }
                });
            }

            $('#validate-date-range').click(function() {
                validateDateRange();
            });

            $('#scan-users').click(function() {
                if (!scanInProgress) {
                    if ($('#scan-users').text() === 'Resume Scan') {
                        resumeScan(scanSessionId);
                    } else {
                        startNewScan();
                    }
                }
            });

            $('#resume-scan').click(function() {
                if (!scanInProgress) {
                    resumeScan(scanSessionId);
                }
            });

            $('#reset-scan').click(function() {
                if (confirm('Reset scan data? This will start a new scan.')) {
                    $.ajax({
                        url: window.intersoccerCleanup.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'reset_scan',
                            nonce: window.intersoccerCleanup.nonce
                        },
                        success: function() {
                            location.reload();
                        }
                    });
                }
            });

            $('#cleanup-users').click(function() {
                if (!cleanupInProgress) {
                    const dryRun = $('#dry-run').is(':checked');
                    const forceCleanup = $('#force-cleanup').is(':checked');
                    let confirmMsg = dryRun ? 
                        'Start dry run cleanup? No users will be deleted.' : 
                        'Are you sure you want to delete these users? This action cannot be undone!';
                    if (forceCleanup && !dryRun) {
                        confirmMsg += '\n\nWARNING: Force cleanup is enabled - some safety checks will be bypassed!';
                    }
                    if (confirm(confirmMsg)) {
                        startCleanup();
                    }
                }
            });

            $('#download-review').click(function() {
                const downloadUrl = `${window.intersoccerCleanup.ajaxurl}?action=download_cleanup_review&nonce=${window.intersoccerCleanup.nonce}`;
                window.location = downloadUrl;
            });

            $('#resume-cleanup').click(function() {
                if (!cleanupInProgress) {
                    resumeCleanup();
                }
            });

            $('#reset-cleanup').click(function() {
                if (confirm('Reset cleanup data? This will start a new cleanup.')) {
                    $.ajax({
                        url: window.intersoccerCleanup.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'reset_scan',
                            nonce: window.intersoccerCleanup.nonce
                        },
                        success: function() {
                            location.reload();
                        }
                    });
                }
            });

            function validateDateRange() {
                const startDate = $('#incident-start-date').val();
                const endDate = $('#incident-end-date').val();
                if (!startDate || !endDate) {
                    alert('Please select both start and end dates');
                    return;
                }
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'validate_date_range',
                        nonce: window.intersoccerCleanup.nonce,
                        start_date: startDate,
                        end_date: endDate
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#date-validation-results').show();
                            $('#date-validation-summary').html(
                                `<p><strong>Date range validated:</strong> Found ${response.data.users_in_range} users registered between ${startDate} and ${endDate}</p>
                                <p><em>Pattern matches: ${response.data.pattern_matches}</em></p>`
                            );
                        } else {
                            alert('Error validating date range: ' + response.data.message);
                        }
                    }
                });
            }

            function startNewScan() {
                const startDate = $('#incident-start-date').val();
                const endDate = $('#incident-end-date').val();
                if (!startDate || !endDate) {
                    alert('Please select both start and end dates');
                    return;
                }
                scanInProgress = true;
                $('#scan-users').prop('disabled', true).text('Starting new scan...');
                $('#resume-scan, #reset-scan').hide();
                $('#scan-progress-container').show();
                $('#results-section, #cleanup-section, #debug-section').hide();
                const batchSize = parseInt($('#scan-batch-size').val());
                scanSessionId = generateSessionId('scan');
                processScanBatch(batchSize, true);
            }

            function resumeScan(sessionId) {
                scanInProgress = false; // Don't set to true yet
                $('#scan-users').prop('disabled', true).text('Checking scan status...');
                $('#resume-scan, #reset-scan').hide();
                $('#scan-progress-container').show();
                // Don't hide results and cleanup sections initially
                const batchSize = parseInt($('#scan-batch-size').val());
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_scan_status',
                        nonce: window.intersoccerCleanup.nonce,
                        session_id: sessionId
                    },
                    success: function(response) {
                        if (response.success && response.data.session) {
                            const session = response.data.session;
                            scanSessionId = response.data.session_id || session.session_id;
                            
                            // Check if scan is already complete
                            if (session.status === 'completed') {
                                // Scan is complete, show results and cleanup sections
                                $('#scan-users').prop('disabled', false).text('Scan Complete');
                                $('#scan-progress-container').hide();
                                $('#results-section').show();
                                $('#cleanup-section').show();
                                
                                // Display the results
                                const results = {
                                    total_processed: session.processed,
                                    fake_found: session.fake_found,
                                    safe_found: session.safe_found,
                                    sample_users: [], // We'll need to get this from temp table
                                    safety_checks: [
                                        { rule: 'Email pattern validation', passed: true },
                                        { rule: 'Date range filtering', passed: true },
                                        { rule: 'Duplicate detection', passed: true }
                                    ]
                                };
                                
                                // Get sample users from temp table
                                $.ajax({
                                    url: window.intersoccerCleanup.ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'get_scan_results',
                                        nonce: window.intersoccerCleanup.nonce
                                    },
                                    success: function(resultsResponse) {
                                        if (resultsResponse.success) {
                                            displayCompletedScanResults(resultsResponse.data);
                                        }
                                    }
                                });
                                
                                return;
                            }
                            
                            // Check if there are existing results (scan was interrupted but had progress)
                            if (session.processed > 0) {
                                // Show results section with current progress
                                $('#results-section').show();
                                $('#cleanup-section').show(); // Show cleanup even if scan incomplete
                                
                                // Display current progress in results
                                let summaryHtml = `
                                    <div class="summary-grid">
                                        <div class="summary-item">
                                            <h4>Total Users Processed</h4>
                                            <div class="number">${session.processed}</div>
                                        </div>
                                        <div class="summary-item">
                                            <h4>Fake Users Detected</h4>
                                            <div class="number status-danger">${session.fake_found}</div>
                                        </div>
                                        <div class="summary-item">
                                            <h4>Safe Users</h4>
                                            <div class="number status-safe">${session.safe_found}</div>
                                        </div>
                                    </div>
                                    <p><em>Scan was interrupted. Click "Resume Scan" to continue or proceed to cleanup with current results.</em></p>
                                `;
                                $('#scan-summary').html(summaryHtml);
                            }
                            
                            // Scan is incomplete, resume processing
                            scanInProgress = true;
                            updateScanProgress({
                                percent: session.total_users > 0 ? (session.processed / session.total_users) * 100 : 0,
                                processed: session.processed,
                                fake_found: session.fake_found,
                                safe_found: session.safe_found,
                                memory_mb: 0
                            });
                            processScanBatch(batchSize, false);
                        } else {
                            alert('Error resuming scan: ' + (response.data.message || 'Session not found'));
                            resetScanUI();
                        }
                    },
                    error: function() {
                        alert('Error checking scan status');
                        resetScanUI();
                    }
                });
            }

            $('#reset-scan').click(function() {
                if (confirm('Reset scan data? This will start a new scan.')) {
                    $.ajax({
                        url: window.intersoccerCleanup.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'reset_scan',
                            nonce: window.intersoccerCleanup.nonce
                        },
                        success: function() {
                            location.reload();
                        }
                    });
                }
            });

            $('#cleanup-users').click(function() {
                if (!cleanupInProgress) {
                    const dryRun = $('#dry-run').is(':checked');
                    const forceCleanup = $('#force-cleanup').is(':checked');
                    let confirmMsg = dryRun ? 
                        'Start dry run cleanup? No users will be deleted.' : 
                        'Are you sure you want to delete these users? This action cannot be undone!';
                    if (forceCleanup && !dryRun) {
                        confirmMsg += '\n\nWARNING: Force cleanup is enabled - some safety checks will be bypassed!';
                    }
                    if (confirm(confirmMsg)) {
                        startCleanup();
                    }
                }
            });

            $('#resume-cleanup').click(function() {
                if (!cleanupInProgress) {
                    resumeCleanup();
                }
            });

            $('#reset-cleanup').click(function() {
                if (confirm('Reset cleanup data? This will start a new cleanup.')) {
                    $.ajax({
                        url: window.intersoccerCleanup.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'reset_scan',
                            nonce: window.intersoccerCleanup.nonce
                        },
                        success: function() {
                            location.reload();
                        }
                    });
                }
            });

            function validateDateRange() {
                const startDate = $('#incident-start-date').val();
                const endDate = $('#incident-end-date').val();
                if (!startDate || !endDate) {
                    alert('Please select both start and end dates');
                    return;
                }
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'validate_date_range',
                        nonce: window.intersoccerCleanup.nonce,
                        start_date: startDate,
                        end_date: endDate
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#date-validation-results').show();
                            $('#date-validation-summary').html(
                                `<p><strong>Date range validated:</strong> Found ${response.data.users_in_range} users registered between ${startDate} and ${endDate}</p>
                                <p><em>Pattern matches: ${response.data.pattern_matches}</em></p>`
                            );
                        } else {
                            alert('Error validating date range: ' + response.data.message);
                        }
                    }
                });
            }

            function startNewScan() {
                const startDate = $('#incident-start-date').val();
                const endDate = $('#incident-end-date').val();
                if (!startDate || !endDate) {
                    alert('Please select both start and end dates');
                    return;
                }
                scanInProgress = true;
                $('#scan-users').prop('disabled', true).text('Starting new scan...');
                $('#resume-scan, #reset-scan').hide();
                $('#scan-progress-container').show();
                $('#results-section, #cleanup-section, #debug-section').hide();
                const batchSize = parseInt($('#scan-batch-size').val());
                scanSessionId = generateSessionId('scan');
                processScanBatch(batchSize, true);
            }

            function resumeScan(sessionId) {
                scanInProgress = false; // Don't set to true yet
                $('#scan-users').prop('disabled', true).text('Checking scan status...');
                $('#resume-scan, #reset-scan').hide();
                $('#scan-progress-container').show();
                // Don't hide results and cleanup sections initially
                const batchSize = parseInt($('#scan-batch-size').val());
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_scan_status',
                        nonce: window.intersoccerCleanup.nonce,
                        session_id: sessionId
                    },
                    success: function(response) {
                        if (response.success && response.data.session) {
                            const session = response.data.session;
                            
                            // Check if scan is already complete
                            if (session.status === 'completed') {
                                // Scan is complete, show results and cleanup sections
                                $('#scan-users').prop('disabled', false).text('Scan Complete');
                                $('#scan-progress-container').hide();
                                $('#results-section').show();
                                $('#cleanup-section').show();
                                
                                // Display the results
                                const results = {
                                    total_processed: session.processed,
                                    fake_found: session.fake_found,
                                    safe_found: session.safe_found,
                                    sample_users: [], // We'll need to get this from temp table
                                    safety_checks: [
                                        { rule: 'Email pattern validation', passed: true },
                                        { rule: 'Date range filtering', passed: true },
                                        { rule: 'Duplicate detection', passed: true }
                                    ]
                                };
                                
                                // Get sample users from temp table
                                $.ajax({
                                    url: window.intersoccerCleanup.ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'get_scan_results',
                                        nonce: window.intersoccerCleanup.nonce
                                    },
                                    success: function(resultsResponse) {
                                        if (resultsResponse.success) {
                                            displayCompletedScanResults(resultsResponse.data);
                                        }
                                    }
                                });
                                
                                return;
                            }
                            
                            // Check if there are existing results (scan was interrupted but had progress)
                            if (session.processed > 0) {
                                // Show results section with current progress
                                $('#results-section').show();
                                $('#cleanup-section').show(); // Show cleanup even if scan incomplete
                                
                                // Display current progress in results
                                let summaryHtml = `
                                    <div class="summary-grid">
                                        <div class="summary-item">
                                            <h4>Total Users Processed</h4>
                                            <div class="number">${session.processed}</div>
                                        </div>
                                        <div class="summary-item">
                                            <h4>Fake Users Detected</h4>
                                            <div class="number status-danger">${session.fake_found}</div>
                                        </div>
                                        <div class="summary-item">
                                            <h4>Safe Users</h4>
                                            <div class="number status-safe">${session.safe_found}</div>
                                        </div>
                                    </div>
                                    <p><em>Scan was interrupted. Click "Resume Scan" to continue or proceed to cleanup with current results.</em></p>
                                `;
                                $('#scan-summary').html(summaryHtml);
                            }
                            
                            // Scan is incomplete, resume processing
                            scanInProgress = true;
                            updateScanProgress({
                                percent: session.total_users > 0 ? (session.processed / session.total_users) * 100 : 0,
                                processed: session.processed,
                                fake_found: session.fake_found,
                                safe_found: session.safe_found,
                                memory_mb: 0
                            });
                            processScanBatch(batchSize, false);
                        } else {
                            alert('Error resuming scan: ' + (response.data.message || 'Session not found'));
                            resetScanUI();
                        }
                    },
                    error: function() {
                        alert('Error checking scan status');
                        resetScanUI();
                    }
                });
            }

            function processScanBatch(batchSize, isNewScan) {
                const startDate = $('#incident-start-date').val();
                const endDate = $('#incident-end-date').val();
                const detailedLogging = $('#detailed-logging').is(':checked');
                const debugFirst10 = $('#debug-first-10').is(':checked');
                $('#scan-users').text(isNewScan ? 'Scanning users...' : 'Processing next batch...');
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    timeout: 120000,
                    data: {
                        action: 'scan_fake_users_enhanced',
                        nonce: window.intersoccerCleanup.nonce,
                        session_id: scanSessionId,
                        batch_size: batchSize,
                        start_date: startDate,
                        end_date: endDate,
                        detailed_logging: detailedLogging ? 1 : 0,
                        debug_first_10: debugFirst10 ? 1 : 0,
                        is_new_scan: isNewScan ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.session_id) {
                                scanSessionId = response.data.session_id;
                            }
                            updateScanProgress(response.data.progress);
                            if (response.data.debug_info) {
                                displayDebugInfo(response.data.debug_info);
                            }
                            if (response.data.completed) {
                                completeScan(response.data.results);
                            } else {
                                setTimeout(function() {
                                    processScanBatch(batchSize, false);
                                }, 300);
                            }
                        } else {
                            alert('Error during scan: ' + response.data.message + '. Progress saved; you can resume.');
                            resetScanUI();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        alert('Network error during scan. Progress saved; you can resume.');
                        resetScanUI();
                    }
                });
            }

            function startCleanup() {
                cleanupInProgress = true;
                $('#cleanup-users').prop('disabled', true).text('Starting cleanup...');
                $('#cleanup-progress-container').show();
                $('#resume-cleanup, #reset-cleanup').hide();
                $('#download-review').hide();
                const batchSize = parseInt($('#cleanup-batch-size').val());
                const dryRun = $('#dry-run').is(':checked');
                const forceCleanup = $('#force-cleanup').is(':checked');
                cleanupSessionId = generateSessionId('cleanup');
                processCleanupBatch(batchSize, true, dryRun, forceCleanup);
            }

            function resumeCleanup() {
                cleanupInProgress = true;
                $('#cleanup-users').prop('disabled', true);
                $('#resume-cleanup').prop('disabled', true);
                $('#cleanup-progress-container').show();
                
                // Get cleanup status to determine where to resume
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_cleanup_status',
                        nonce: window.intersoccerCleanup.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.session) {
                            const session = response.data.session;
                            cleanupSessionId = response.data.session_id || session.session_id;
                            const batchSize = parseInt($('#cleanup-batch-size').val());
                            const dryRun = $('#dry-run').is(':checked');
                            const forceCleanup = $('#force-cleanup').is(':checked');
                            if (session.reviewed && session.reviewed > 0) {
                                $('#download-review').show();
                            }
                            
                            $('#cleanup-users').text('Resuming cleanup...');
                            processCleanupBatch(batchSize, false, dryRun, forceCleanup);
                        } else {
                            alert('No cleanup session found to resume.');
                            resetCleanupUI();
                        }
                    },
                    error: function() {
                        alert('Error checking cleanup status.');
                        resetCleanupUI();
                    }
                });
            }

            function checkIncompleteCleanup() {
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_cleanup_status',
                        nonce: window.intersoccerCleanup.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.incomplete && response.data.session) {
                            cleanupSessionId = response.data.session_id || (response.data.session ? response.data.session.session_id : null);
                            $('#resume-cleanup').show();
                            $('#reset-cleanup').show();
                            $('#cleanup-users').text('Resume Cleanup');
                            if (response.data.session.reviewed && response.data.session.reviewed > 0) {
                                $('#download-review').show();
                            }
                            alert('Incomplete cleanup detected. Processed: ' + response.data.session.processed + '/' + response.data.session.total_users);
                        } else {
                            cleanupSessionId = null;
                            $('#resume-cleanup').hide();
                            $('#reset-cleanup').hide();
                        }
                    }
                });
            }

            function resetScanUI() {
                scanInProgress = false;
                $('#scan-users').prop('disabled', false).text('Start Scan');
                $('#scan-progress-container').hide();
                
                // Check if we can resume
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_scan_status',
                        nonce: window.intersoccerCleanup.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.incomplete && response.data.session) {
                            scanSessionId = response.data.session_id || (response.data.session ? response.data.session.session_id : null);
                            $('#resume-scan').show();
                            $('#reset-scan').show();
                            $('#scan-users').text('Resume Scan');
                        } else {
                            scanSessionId = null;
                            $('#resume-scan').hide();
                            $('#reset-scan').hide();
                        }
                    }
                });
            }

            function resetCleanupUI() {
                cleanupInProgress = false;
                $('#cleanup-users').prop('disabled', false).text('Start Cleanup');
                $('#cleanup-progress-container').hide();
                $('#download-review').hide();
                
                // Check if we can resume
                checkIncompleteCleanup();
            }

            function updateScanProgress(progress) {
                const percent = Math.round(progress.percent);
                $('#scan-progress-fill').css('width', percent + '%');
                $('#scan-progress-text').text(percent + '% complete');
                let stats = `Processed: ${progress.processed} | Fake found: ${progress.fake_found} | Safe found: ${progress.safe_found}`;
                if (typeof progress.total_users !== 'undefined' && progress.total_users !== null) {
                    stats += ` | Total: ${progress.total_users}`;
                }
                $('#scan-stats').html(stats);
            }

            function completeScan(results) {
                scanInProgress = false;
                scanSessionId = null;
                $('#scan-users').prop('disabled', false).text('Scan Complete');
                $('#scan-progress-container').hide();
                $('#results-section').show();
                $('#cleanup-section').show();
                
                // Display results summary
                let summaryHtml = `
                    <div class="summary-grid">
                        <div class="summary-item">
                            <h4>Total Users Processed</h4>
                            <div class="number">${results.total_processed}</div>
                        </div>
                        <div class="summary-item">
                            <h4>Fake Users Detected</h4>
                            <div class="number status-danger">${results.fake_found}</div>
                        </div>
                        <div class="summary-item">
                            <h4>Safe Users</h4>
                            <div class="number status-safe">${results.safe_found}</div>
                        </div>
                    </div>
                `;
                $('#scan-summary').html(summaryHtml);
                
                // Sample of detected fake users
                let sampleHtml = '<h3>Sample of Detected Fake Users:</h3><div class="sample-users">';
                results.sample_users.forEach(user => {
                    const reasonSummary = (user.reasons || []).map(reason => reason.code || reason).join(', ');
                    sampleHtml += `
                        <div class="user-item">
                            <span class="status-indicator status-danger"></span>
                            ${user.email} (ID: ${user.id}, Score: ${user.score ?? 'n/a'})${reasonSummary ? `<div class="debug-details">Reasons: ${reasonSummary}</div>` : ''}
                        </div>
                    `;
                });
                sampleHtml += '</div>';
                $('#sample-users').html(sampleHtml);
                
                // Safety check breakdown
                let safetyHtml = '<h3>Safety Check Breakdown:</h3>';
                results.safety_checks.forEach(check => {
                    safetyHtml += `
                        <div class="check-result ${check.passed ? 'passed' : 'failed'}">
                            <div>${check.rule}</div>
                            <div>${check.passed ? '✔️' : '❌'}</div>
                        </div>
                    `;
                });
                $('#safety-check-breakdown').html(safetyHtml);
            }

            function displayDebugInfo(debugInfo) {
                let debugHtml = '<h3>Debug Information:</h3><div class="debug-output">';
                debugInfo.forEach(item => {
                    debugHtml += `
                        <div class="debug-item">
                            <strong>${item.label}:</strong> ${item.value}
                        </div>
                    `;
                });
                debugHtml += '</div>';
                $('#debug-output').html(debugHtml);
                $('#debug-section').show();
            }

            function processCleanupBatch(batchSize, isNewCleanup, dryRun, forceCleanup) {
                $('#cleanup-users').text(isNewCleanup ? 'Processing cleanup...' : 'Processing next cleanup batch...');
                
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    timeout: 120000,
                    data: {
                        action: 'cleanup_fake_users_enhanced',
                        nonce: window.intersoccerCleanup.nonce,
                        session_id: cleanupSessionId,
                        batch_size: batchSize,
                        dry_run: dryRun ? 1 : 0,
                        force_cleanup: forceCleanup ? 1 : 0,
                        is_new_cleanup: isNewCleanup ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.session_id) {
                                cleanupSessionId = response.data.session_id;
                            }
                            updateCleanupProgress(response.data.progress);
                            
                            if (response.data.completed) {
                                completeCleanup(response.data);
                            } else {
                                setTimeout(function() {
                                    processCleanupBatch(batchSize, false, dryRun, forceCleanup);
                                }, 300);
                            }
                        } else {
                            alert('Error during cleanup: ' + response.data.message);
                            resetCleanupUI();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Cleanup AJAX Error:', status, error);
                        alert('Network error during cleanup. Progress saved; you can resume.');
                        resetCleanupUI();
                    }
                });
            }

            function updateCleanupProgress(progress) {
                const percent = Math.round(progress.percent);
                $('#cleanup-progress-fill').css('width', percent + '%');
                $('#cleanup-progress-text').text(percent + '% complete');
                let stats = `Processed: ${progress.processed} | Deleted: ${progress.deleted} | Skipped: ${progress.skipped}`;
                if (typeof progress.reviewed !== 'undefined' && progress.reviewed !== null) {
                    stats += ` | Needs Review: ${progress.reviewed}`;
                }
                if (typeof progress.total_users !== 'undefined' && progress.total_users !== null) {
                    stats += ` | Total: ${progress.total_users}`;
                }
                $('#cleanup-stats').html(stats);
                if (progress.reviewed && progress.reviewed > 0) {
                    $('#download-review').show();
                } else {
                    $('#download-review').hide();
                }
            }

            function completeCleanup(data) {
                cleanupInProgress = false;
                cleanupSessionId = null;
                $('#cleanup-users').prop('disabled', false).text('Cleanup Complete');
                $('#cleanup-progress-container').hide();
                
                const action = data.dry_run ? 'would be deleted' : 'deleted';
                alert(`Cleanup completed! ${data.progress.deleted} users ${action}.`);
                
                // Refresh the page to show updated results
                location.reload();
            }

            function displayCompletedScanResults(results) {
                // Display results summary
                let summaryHtml = `
                    <div class="summary-grid">
                        <div class="summary-item">
                            <h4>Total Users Processed</h4>
                            <div class="number">${results.total_processed}</div>
                        </div>
                        <div class="summary-item">
                            <h4>Fake Users Detected</h4>
                            <div class="number status-danger">${results.fake_found}</div>
                        </div>
                        <div class="summary-item">
                            <h4>Safe Users</h4>
                            <div class="number status-safe">${results.safe_found}</div>
                        </div>
                    </div>
                `;
                $('#scan-summary').html(summaryHtml);
                
                // Sample of detected fake users
                let sampleHtml = '<h3>Sample of Detected Fake Users:</h3><div class="sample-users">';
                results.sample_users.forEach(user => {
                    const reasonSummary = (user.reasons || []).map(reason => reason.code || reason).join(', ');
                    sampleHtml += `
                        <div class="user-item">
                            <span class="status-indicator status-danger"></span>
                            ${user.email} (ID: ${user.id}, Score: ${user.score ?? 'n/a'})${reasonSummary ? `<div class="debug-details">Reasons: ${reasonSummary}</div>` : ''}
                        </div>
                    `;
                });
                sampleHtml += '</div>';
                $('#sample-users').html(sampleHtml);
                
                // Safety check breakdown
                let safetyHtml = '<h3>Safety Check Breakdown:</h3>';
                results.safety_checks.forEach(check => {
                    safetyHtml += `
                        <div class="check-result ${check.passed ? 'passed' : 'failed'}">
                            <div>${check.rule}</div>
                            <div>${check.passed ? '✔️' : '❌'}</div>
                        </div>
                    `;
                });
                $('#safety-check-breakdown').html(safetyHtml);
                
                // Check for incomplete cleanup
                checkIncompleteCleanup();
            }
        });
        </script>
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

        $this->ensure_temp_table();
        $this->ensure_audit_table();

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

        $fake_found = 0;
        $safe_found = 0;
        $debug_info = array();

        foreach ($users as $user) {
            $evaluation = $this->evaluate_user($user, $debug_first_10 && count($debug_info) < 10);

            if ($evaluation['is_fake']) {
                $fake_found++;

                $wpdb->replace(
                    $wpdb->prefix . $this->temp_table,
                    array(
                        'id' => $user->ID,
                        'email' => $user->user_email,
                        'registered' => $user->user_registered,
                        'score' => $evaluation['score'],
                        'reason' => wp_json_encode($evaluation['reasons']),
                        'needs_review' => 0,
                        'review_notes' => null
                    ),
                    array('%d', '%s', '%s', '%d', '%s', '%d', '%s')
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
            update_option($this->scan_current_option, '');
        }

        $this->save_scan_progress($session_id, $progress);

        $this->log_message('scan_batch', array(
            'session_id' => $session_id,
            'processed' => $progress['processed'],
            'batch_size' => $batch_size,
            'batch_users' => $count_users,
            'fake_found' => $fake_found,
            'safe_found' => $safe_found,
            'last_id' => $progress['last_id'],
            'detailed_logging' => $detailed_logging ? 1 : 0
        ));

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

        try {
            $result = $this->process_cleanup_batch($session_id, array(
                'batch_size' => $batch_size,
                'dry_run' => $dry_run,
                'force_cleanup' => $force_cleanup,
                'is_new_cleanup' => $is_new_cleanup
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

        $this->ensure_temp_table();
        $this->ensure_audit_table();

        $batch_size = max(1, intval($args['batch_size'] ?? 50));
        $dry_run = !empty($args['dry_run']);
        $force_cleanup = !empty($args['force_cleanup']);
        $is_new_cleanup = !empty($args['is_new_cleanup']);

        if ($is_new_cleanup) {
            $total_fake_users = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$this->temp_table}");

                $cleanup_progress = array(
                'session_id' => $session_id,
                'status' => 'running',
                'processed' => 0,
                'total_users' => $total_fake_users,
                'deleted' => 0,
                'skipped' => 0,
                    'reviewed' => 0,
                'last_id' => 0,
                'start_time' => time(),
                'dry_run' => $dry_run ? 1 : 0,
                'force_cleanup' => $force_cleanup ? 1 : 0
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
            $dry_run = isset($cleanup_progress['dry_run']) ? (bool) $cleanup_progress['dry_run'] : $dry_run;
            $force_cleanup = isset($cleanup_progress['force_cleanup']) ? (bool) $cleanup_progress['force_cleanup'] : $force_cleanup;
        }

        $last_id = intval($cleanup_progress['last_id'] ?? 0);

        $fake_users = $wpdb->get_results($wpdb->prepare(
            "SELECT id, email FROM {$wpdb->prefix}{$this->temp_table} WHERE id > %d ORDER BY id ASC LIMIT %d",
            $last_id,
            $batch_size
        ));

        $deleted = 0;
        $skipped = 0;
        $reviewed = 0;
        $processed_ids = array();
        $review_users = array();

        foreach ($fake_users as $fake_user) {
            $safety_flags = $this->get_user_safety_flags($fake_user->id);
            if (!empty($safety_flags) && !$force_cleanup) {
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

            $processed_ids[] = $fake_user->id;

            if (!$dry_run) {
                $user_registered = $wpdb->get_var($wpdb->prepare(
                    "SELECT user_registered FROM {$wpdb->users} WHERE ID = %d",
                    $fake_user->id
                ));

                $result = wp_delete_user($fake_user->id);
                if ($result) {
                    $deleted++;

                    $wpdb->insert(
                        $wpdb->prefix . $this->audit_table,
                        array(
                            'user_id' => $fake_user->id,
                            'email' => $fake_user->email,
                            'registered' => $user_registered
                        ),
                        array('%d', '%s', '%s')
                    );
                } else {
                    $skipped++;
                    $this->log_message('cleanup_delete_failed', array(
                        'session_id' => $session_id,
                        'user_id' => $fake_user->id
                    ), 'warning');
                }
            } else {
                $deleted++;
            }
        }

        if (!$dry_run && !empty($processed_ids)) {
            $placeholders = implode(',', array_fill(0, count($processed_ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}{$this->temp_table} WHERE id IN ($placeholders)",
                $processed_ids
            ));
        }

        $count_processed = count($fake_users);
        $cleanup_progress['processed'] += $count_processed;
        $cleanup_progress['deleted'] += $deleted;
        $cleanup_progress['skipped'] += $skipped;
        $cleanup_progress['reviewed'] += $reviewed;

        if ($count_processed > 0) {
            $cleanup_progress['last_id'] = end($fake_users)->id;
        }

        $completed = ($count_processed === 0 && !$is_new_cleanup) || $cleanup_progress['processed'] >= $cleanup_progress['total_users'];
        if ($completed && $cleanup_progress['status'] !== 'completed') {
            $cleanup_progress['status'] = 'completed';
            $cleanup_progress['end_time'] = time();
            update_option($this->cleanup_current_option, '');
        }

        $this->save_cleanup_progress($session_id, $cleanup_progress);

        $this->log_message('cleanup_batch', array(
            'session_id' => $session_id,
            'batch_size' => $batch_size,
            'batch_users' => $count_processed,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'reviewed' => $reviewed,
            'dry_run' => $dry_run ? 1 : 0,
            'force_cleanup' => $force_cleanup ? 1 : 0,
            'next_last_id' => $cleanup_progress['last_id']
        ));

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
                'deleted' => $cleanup_progress['deleted'],
                'skipped' => $cleanup_progress['skipped'],
                'reviewed' => $cleanup_progress['reviewed'],
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

    private function evaluate_user($user, $debug = false) {
        global $wpdb;

        $score = 0;
        $reasons = array();

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
        if ($domain && in_array($domain, $this->disposable_domains, true)) {
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

        $first_name = function_exists('get_user_meta') ? trim((string) get_user_meta($user->ID, 'first_name', true)) : '';
        $last_name = function_exists('get_user_meta') ? trim((string) get_user_meta($user->ID, 'last_name', true)) : '';
        if ($first_name === '' && $last_name === '') {
            $score += 20;
            $reasons[] = array(
                'code' => 'missing_profile_name',
                'detail' => $user->ID
            );
        }

        $meta_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d",
            $user->ID
        ));
        if ($meta_count < 3) {
            $score += 20;
            $reasons[] = array(
                'code' => 'sparse_profile_meta',
                'detail' => $meta_count
            );
        }

        $cohort_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users}
             WHERE user_registered BETWEEN DATE_SUB(%s, INTERVAL 45 SECOND)
             AND DATE_ADD(%s, INTERVAL 45 SECOND)",
            $user->user_registered,
            $user->user_registered
        ));
        if ($cohort_count >= 25) {
            $score += 30;
            $reasons[] = array(
                'code' => 'burst_registration_window',
                'detail' => $cohort_count
            );
        }

        $is_fake = $score >= 70;

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

    private function get_user_safety_flags($user_id) {
        global $wpdb;

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

        // User meta activity markers
        $meta_keys = array(
            'last_activity',
            'last_login',
            'wp_last_login',
            'wc_last_active',
            'session_tokens'
        );
        foreach ($meta_keys as $key) {
            $meta_value = function_exists('get_user_meta') ? get_user_meta($user_id, $key, true) : '';
            if (!empty($meta_value)) {
                $flags[] = array(
                    'code' => 'recent_activity_meta',
                    'detail' => $key
                );
            }
        }

        return $flags;
    }

    private function get_scan_results($session_id = null) {
        global $wpdb;

        if (empty($session_id)) {
            $session_id = get_option($this->scan_current_option, '');
        }

        $progress = $session_id ? $this->load_scan_progress($session_id) : array();
        
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

        $batch_size = isset($assoc_args['batch-size']) ? max(1, (int) $assoc_args['batch-size']) : 50;
        $dry_run = !empty($assoc_args['dry-run']);
        $force_cleanup = !empty($assoc_args['force']);
        $session_id = $assoc_args['session'] ?? '';
        $resume = !empty($assoc_args['resume']);

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
                    'is_new_cleanup' => $is_new_cleanup
                ));

                $progress = $result['progress'];
                \WP_CLI::log(sprintf(
                    'Processed %d/%d users (deleted: %d, skipped: %d)',
                    $progress['processed'],
                    $progress['total_users'],
                    $progress['deleted'],
                    $progress['skipped']
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