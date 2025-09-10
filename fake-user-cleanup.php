<?php
/**
 * Plugin Name: InterSoccer Fake User Cleanup - Fixed
 * Description: Fixed cleanup tool with proper validation logic
 * Version: 1.0.0
 * Author: InterSoccer Development Team
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

    private function ensure_temp_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->temp_table;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL,
                email varchar(100) NOT NULL,
                registered datetime NOT NULL,
                PRIMARY KEY (id)
            ) $charset_collate;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }

    private function ensure_audit_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->audit_table;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                user_id mediumint(9) NOT NULL,
                email varchar(100) NOT NULL,
                registered datetime NOT NULL,
                deleted_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset_collate;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/intersoccer-cleanup-enhanced.log';
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_scan_fake_users_enhanced', array($this, 'ajax_scan_fake_users'));
        add_action('wp_ajax_cleanup_fake_users_enhanced', array($this, 'ajax_cleanup_fake_users'));
        add_action('wp_ajax_validate_date_range', array($this, 'ajax_validate_date_range'));
        
        // Add admin scripts for nonce
        add_action('admin_footer', array($this, 'admin_footer_script'));
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
                <p>Identifies users matching the fake user pattern with no orders or player data.</p>
                
                <div class="scan-options">
                    <label>Batch size: <select id="scan-batch-size">
                        <option value="50">50 (Ultra Conservative)</option>
                        <option value="100" selected>100 (Conservative)</option>
                        <option value="200">200 (Recommended)</option>
                    </select></label>
                    
                    <label><input type="checkbox" id="detailed-logging" checked> Enable detailed logging</label>
                    <label><input type="checkbox" id="debug-first-10"> Debug first 10 users in detail</label>
                </div>
                
                <button id="scan-users" class="button button-primary">Start Scan</button>
                
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
                        <option value="25" selected>25 (Ultra Safe)</option>
                        <option value="50">50 (Safe)</option>
                        <option value="100">100 (Fast)</option>
                    </select></label>
                </div>
                
                <button id="cleanup-users" class="button button-secondary">Start Cleanup</button>
                
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
            
            $('#validate-date-range').click(function() {
                validateDateRange();
            });
            
            $('#scan-users').click(function() {
                if (!scanInProgress) {
                    startScan();
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
            
            function startScan() {
                scanInProgress = true;
                $('#scan-users').prop('disabled', true).text('Starting scan...');
                $('#scan-progress-container').show();
                $('#results-section, #cleanup-section, #debug-section').hide();
                
                const batchSize = parseInt($('#scan-batch-size').val());
                
                // Show immediate feedback
                updateScanProgress({
                    percent: 0,
                    processed: 0,
                    fake_found: 0,
                    memory_mb: 0
                });
                
                // Start real-time batch processing
                processScanBatch(0, batchSize);
            }
            
            function processScanBatch(offset, batchSize) {
                const startDate = $('#incident-start-date').val();
                const endDate = $('#incident-end-date').val();
                const detailedLogging = $('#detailed-logging').is(':checked');
                const debugFirst10 = $('#debug-first-10').is(':checked');
                
                if (offset === 0) {
                    $('#scan-users').text('Scanning users...');
                } else {
                    $('#scan-users').text(`Processing batch ${Math.floor(offset/batchSize) + 1}...`);
                }
                
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    timeout: 120000, // 2 minutes timeout
                    data: {
                        action: 'scan_fake_users_enhanced',
                        nonce: window.intersoccerCleanup.nonce,
                        batch_size: batchSize,
                        offset: offset,
                        start_date: startDate,
                        end_date: endDate,
                        detailed_logging: detailedLogging ? 1 : 0,
                        debug_first_10: debugFirst10 ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update progress immediately
                            updateScanProgress(response.data.progress);
                            
                            // Show debug info if available
                            if (response.data.debug_info) {
                                displayDebugInfo(response.data.debug_info);
                            }
                            
                            if (response.data.completed) {
                                // Scan complete
                                $('#scan-users').text('Scan completed!');
                                completeScan(response.data.results);
                            } else {
                                // Process next batch with delay to prevent timeouts
                                setTimeout(function() {
                                    processScanBatch(response.data.next_offset, batchSize);
                                }, 300);
                            }
                        } else {
                            alert('Error during scan: ' + response.data.message);
                            resetScanUI();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        console.error('Response:', xhr.responseText);
                        
                        if (status === 'timeout') {
                            alert('Request timed out. Try reducing batch size or check server resources.');
                        } else {
                            alert('Network error during scan. Check console and debug log.');
                        }
                        resetScanUI();
                    }
                });
            }
            
            function displayDebugInfo(debugInfo) {
                $('#debug-section').show();
                let debugHtml = '<div class="debug-output">';
                debugInfo.forEach(function(item) {
                    debugHtml += `<div class="debug-item">${item}</div>`;
                });
                debugHtml += '</div>';
                $('#debug-output').html(debugHtml);
            }
            
            function updateScanProgress(data) {
                const percent = data.percent || 0;
                $('#scan-progress-fill').css('width', percent + '%');
                $('#scan-progress-text').text(`${percent.toFixed(1)}% complete`);
                
                if (data.processed !== undefined) {
                    $('#scan-stats').text(`Processed: ${data.processed} | Fake found: ${data.fake_found || 0} | Safe: ${data.safe_found || 0} | Memory: ${data.memory_mb || 0}MB`);
                }
            }
            
            function completeScan(results) {
                resetScanUI();
                displayScanResults(results);
                $('#results-section, #cleanup-section').show();
            }
            
            function resetScanUI() {
                scanInProgress = false;
                $('#scan-users').prop('disabled', false).text('Start Scan');
                $('#scan-progress-container').hide();
            }
            
            function displayScanResults(data) {
                const summaryHtml = `
                    <div class="summary-grid">
                        <div class="summary-item">
                            <h4>Total Users Scanned</h4>
                            <div class="number">${data.total_scanned || 0}</div>
                        </div>
                        <div class="summary-item">
                            <h4>Pattern Matches</h4>
                            <div class="number">${data.pattern_matches || 0}</div>
                        </div>
                        <div class="summary-item">
                            <h4>Fake Users Found</h4>
                            <div class="number">${data.fake_users_count || 0}</div>
                        </div>
                        <div class="summary-item">
                            <h4>Safe to Delete</h4>
                            <div class="number">${data.safe_to_delete || 0}</div>
                        </div>
                    </div>
                `;
                $('#scan-summary').html(summaryHtml);
                
                if (data.sample_users && data.sample_users.length > 0) {
                    let sampleHtml = '<h4>Sample Users (first 20):</h4><div class="sample-users">';
                    data.sample_users.forEach(function(user) {
                        const statusClass = user.safe_to_delete ? 'status-safe' : 'status-warning';
                        sampleHtml += `<div class="user-item">
                            <span class="status-indicator ${statusClass}"></span>
                            ${user.user_email} (ID: ${user.ID}, Registered: ${user.user_registered})
                        </div>`;
                    });
                    sampleHtml += '</div>';
                    $('#sample-users').html(sampleHtml);
                }
                
                // Display safety check breakdown
                if (data.safety_breakdown) {
                    let breakdownHtml = '<div class="safety-breakdown"><h4>Safety Check Analysis:</h4>';
                    Object.keys(data.safety_breakdown).forEach(function(check) {
                        const count = data.safety_breakdown[check];
                        breakdownHtml += `<div class="check-result">
                            <span>${check.replace(/_/g, ' ')}</span>
                            <span>${count} users failed this check</span>
                        </div>`;
                    });
                    breakdownHtml += '</div>';
                    $('#safety-check-breakdown').html(breakdownHtml);
                }
            }
            
            function startCleanup() {
                cleanupInProgress = true;
                const dryRun = $('#dry-run').is(':checked');
                const forceCleanup = $('#force-cleanup').is(':checked');
                
                $('#cleanup-users').prop('disabled', true).text(dryRun ? 'Running Dry Run...' : 'Deleting...');
                $('#cleanup-progress-container').show();
                
                const batchSize = parseInt($('#cleanup-batch-size').val());
                
                // Start real-time batch processing
                processCleanupBatch(0, batchSize, dryRun, forceCleanup);
            }
            
            function processCleanupBatch(offset, batchSize, dryRun, forceCleanup) {
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    timeout: 120000, // 2 minutes timeout
                    data: {
                        action: 'cleanup_fake_users_enhanced',
                        nonce: window.intersoccerCleanup.nonce,
                        batch_size: batchSize,
                        dry_run: dryRun ? 1 : 0,
                        force_cleanup: forceCleanup ? 1 : 0,
                        offset: offset
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update progress immediately
                            updateCleanupProgress(response.data.progress);
                            
                            if (response.data.completed) {
                                // Cleanup complete
                                completeCleanup(response.data.results);
                            } else {
                                // Process next batch with delay
                                setTimeout(function() {
                                    processCleanupBatch(response.data.next_offset, batchSize, dryRun, forceCleanup);
                                }, dryRun ? 100 : 500);
                            }
                        } else {
                            alert('Error during cleanup: ' + response.data.message);
                            resetCleanupUI();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        if (status === 'timeout') {
                            alert('Cleanup request timed out. Check log for partial results.');
                        } else {
                            alert('Network error during cleanup. Check console.');
                        }
                        resetCleanupUI();
                    }
                });
            }
            
            function updateCleanupProgress(data) {
                const percent = data.percent || 0;
                $('#cleanup-progress-fill').css('width', percent + '%');
                $('#cleanup-progress-text').text(`${percent.toFixed(1)}% complete`);
                
                if (data.processed !== undefined) {
                    $('#cleanup-stats').text(`Processed: ${data.processed} | Deleted: ${data.deleted || 0} | Skipped: ${data.skipped || 0}`);
                }
            }
            
            function completeCleanup(results) {
                resetCleanupUI();
                
                const message = `Cleanup complete!\nProcessed: ${results.processed}\nDeleted: ${results.deleted}\nSkipped: ${results.skipped}\nErrors: ${results.errors}`;
                alert(message);
                
                if (confirm('Cleanup completed. Run new scan to verify?')) {
                    location.reload();
                }
            }
            
            function resetCleanupUI() {
                cleanupInProgress = false;
                $('#cleanup-users').prop('disabled', false).text('Start Cleanup');
                $('#cleanup-progress-container').hide();
            }
        });
        </script>
        <?php
    }
    
    public function ajax_validate_date_range() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        
        if (!$start_date || !$end_date) {
            wp_send_json_error(array('message' => 'Both start and end dates are required'));
        }
        
        global $wpdb;
        
        // Count users in date range
        $users_in_range = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} 
             WHERE user_registered >= %s AND user_registered <= %s",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));
        
        // Count pattern matches in date range - CORRECTED REGEX
        $pattern_matches = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->users} 
             WHERE user_email REGEXP '^[a-z]{8}[0-9]{2}@(gmail|outlook|yahoo|hotmail)\\.com$'
             AND user_registered >= %s AND user_registered <= %s",
            $start_date . ' 00:00:00',
            $end_date . ' 23:59:59'
        ));
        
        wp_send_json_success(array(
            'users_in_range' => intval($users_in_range),
            'pattern_matches' => intval($pattern_matches)
        ));
    }
    
    public function ajax_scan_fake_users() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // Increase limits for large operations
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');
        
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 100;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $start_date = sanitize_text_field($_POST['start_date']) . ' 00:00:00';
        $end_date = sanitize_text_field($_POST['end_date']) . ' 23:59:59';
        $detailed_logging = isset($_POST['detailed_logging']) && $_POST['detailed_logging'] == 1;
        $debug_first_10 = isset($_POST['debug_first_10']) && $_POST['debug_first_10'] == 1;
        $is_initial = $offset === 0;
        
        $start_time = microtime(true);
        $this->log_message('=== Starting Scan Batch ===');
        $this->log_message("Batch size: {$batch_size}, Offset: {$offset}, Date range: {$start_date} to {$end_date}");
        
        global $wpdb;
        
        // Get total count on first request - CORRECTED REGEX
        if ($is_initial) {
            $this->ensure_temp_table();
            $table_name = $wpdb->prefix . $this->temp_table;
            $wpdb->query("TRUNCATE TABLE $table_name");
            
            $total_query = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->users} 
                WHERE user_email REGEXP '^[a-z]{8}[0-9]{2}@(gmail|outlook|yahoo|hotmail)\\.com$'
                AND user_registered >= %s AND user_registered <= %s",
                $start_date, $end_date
            );
            $total_users = $wpdb->get_var($total_query);
            $this->log_message("Found {$total_users} users matching email pattern in date range");
            
            if ($total_users == 0) {
                wp_send_json_success(array(
                    'completed' => true,
                    'results' => array(
                        'total_scanned' => 0,
                        'pattern_matches' => 0,
                        'fake_users_count' => 0,
                        'safe_to_delete' => 0,
                        'sample_users' => array(),
                        'safety_breakdown' => array()
                    )
                ));
                return;
            }
            
            // Initialize scan data
            delete_transient($this->transient_key);
            delete_transient($this->transient_key . '_scan_data');
            
            set_transient($this->transient_key . '_total', $total_users, HOUR_IN_SECONDS);
            set_transient($this->transient_key . '_scan_data', array(
                'fake_count' => 0,
                'fake_users' => array(), // Legacy, but we'll use sample_fake_users
                'sample_fake_users' => array(),
                'safe_to_delete_count' => 0,
                'processed' => 0,
                'safety_breakdown' => array(),
                'debug_info' => array()
            ), HOUR_IN_SECONDS);
        } else {
            $total_users = get_transient($this->transient_key . '_total');
            if (!$total_users) {
                wp_send_json_error(array('message' => 'Scan session expired. Please restart.'));
                return;
            }
        }
        
        // Get current scan data
        $scan_data = get_transient($this->transient_key . '_scan_data');
        if (!$scan_data) {
            wp_send_json_error(array('message' => 'Scan data lost. Please restart.'));
            return;
        }
        
        // Process current batch - CORRECTED REGEX
        $query = $wpdb->prepare(
            "SELECT ID, user_email, user_registered FROM {$wpdb->users} 
            WHERE user_email REGEXP '^[a-z]{8}[0-9]{2}@(gmail|outlook|yahoo|hotmail)\\.com$'
            AND user_registered >= %s AND user_registered <= %s
            LIMIT %d OFFSET %d",
            $start_date, $end_date, $batch_size, $offset
        );
        
        $users = $wpdb->get_results($query);
        $this->log_message("Processing batch: " . count($users) . " users from offset {$offset}");
        
        $batch_fake_count = 0;
        $batch_safe_count = 0;
        $debug_info = array();
        $table_name = $wpdb->prefix . $this->temp_table;
        
        foreach ($users as $user) {
            $validation = $this->comprehensive_user_validation($user->ID, $user->user_email, $detailed_logging);
            
            // Debug first 10 users in detail
            if ($debug_first_10 && $scan_data['processed'] < 10) {
                $user_number = $scan_data['processed'] + 1;
                $debug_info[] = "=== DEBUG USER {$user_number} ===";
                $debug_info[] = "Email: {$user->user_email}, ID: {$user->ID}, Registered: {$user->user_registered}";
                $debug_info[] = "Is Fake: " . ($validation['is_fake'] ? 'YES' : 'NO');
                $debug_info[] = "Safe to Delete: " . ($validation['safe_to_delete'] ? 'YES' : 'NO');
                
                foreach ($validation['checks'] as $check_name => $check_data) {
                    $status = $check_data['passed'] ? 'PASS' : 'FAIL';
                    $value = isset($check_data['value']) ? " ({$check_data['value']})" : '';
                    $debug_info[] = "  {$check_name}: {$status}{$value}";
                }
                $debug_info[] = "---";
            }
            
            if ($validation['is_fake']) {
                $scan_data['fake_count']++;
                $batch_fake_count++;
                
                // Sample first 20 fake users (regardless of safe)
                if (count($scan_data['sample_fake_users']) < 20) {
                    $scan_data['sample_fake_users'][] = array(
                        'ID' => $user->ID,
                        'user_email' => $user->user_email,
                        'user_registered' => $user->user_registered,
                        'safe_to_delete' => $validation['safe_to_delete']
                    );
                }
                
                if ($validation['safe_to_delete']) {
                    // Insert only safe users to temp table
                    $wpdb->insert(
                        $table_name,
                        array(
                            'id' => $user->ID,
                            'email' => $user->user_email,
                            'registered' => $user->user_registered
                        ),
                        array('%d', '%s', '%s')
                    );
                    $scan_data['safe_to_delete_count']++;
                    $batch_safe_count++;
                } else {
                    // Track which safety checks are failing (only for fake but unsafe)
                    foreach ($validation['checks'] as $check_name => $check_data) {
                        if (!$check_data['passed'] && strpos($check_name, 'no_') !== 0 && $check_name !== 'email_pattern' && $check_name !== 'registration_date') {
                            if (!isset($scan_data['safety_breakdown'][$check_name])) {
                                $scan_data['safety_breakdown'][$check_name] = 0;
                            }
                            $scan_data['safety_breakdown'][$check_name]++;
                        }
                    }
                }
            }
            
            $scan_data['processed']++;
            unset($validation, $user); // Aid GC
        }
        
        // Store debug info
        if (!empty($debug_info)) {
            $scan_data['debug_info'] = array_merge($scan_data['debug_info'], $debug_info);
        }
        
        // Update scan data
        set_transient($this->transient_key . '_scan_data', $scan_data, HOUR_IN_SECONDS);
        
        $percent = ($scan_data['processed'] / $total_users) * 100;
        $memory_mb = round(memory_get_usage() / 1024 / 1024, 2);
        $time_elapsed = round(microtime(true) - $start_time, 2);
        
        $this->log_message("Batch complete: {$batch_fake_count} fake users found ({$batch_safe_count} safe), Memory: {$memory_mb}MB, Time: {$time_elapsed}s");
        
        // Check if scan is complete
        $next_offset = $offset + $batch_size;
        $completed = $next_offset >= $total_users;
        
        $response_data = array(
            'completed' => $completed,
            'progress' => array(
                'percent' => $percent,
                'processed' => $scan_data['processed'],
                'fake_found' => $scan_data['fake_count'],
                'safe_found' => $scan_data['safe_to_delete_count'],
                'memory_mb' => $memory_mb
            )
        );
        
        if (!empty($scan_data['debug_info'])) {
            $response_data['debug_info'] = $scan_data['debug_info'];
        }
        
        if ($completed) {
            // Final results from counters (no large arrays)
            $results = array(
                'total_scanned' => $scan_data['processed'],
                'pattern_matches' => $total_users,
                'fake_users_count' => $scan_data['fake_count'],
                'safe_to_delete' => $scan_data['safe_to_delete_count'],
                'sample_users' => $scan_data['sample_fake_users'],
                'safety_breakdown' => $scan_data['safety_breakdown']
            );
            
            $this->log_message("Scan complete! Found " . $scan_data['fake_count'] . " fake users (" . $scan_data['safe_to_delete_count'] . " safe to delete)");
            $this->log_message("Safety breakdown: " . json_encode($scan_data['safety_breakdown']));
            
            // Clean up temporary data
            delete_transient($this->transient_key . '_total');
            delete_transient($this->transient_key . '_scan_data');
            
            $response_data['results'] = $results;
        } else {
            // Return progress for next batch
            $response_data['next_offset'] = $next_offset;
        }
        
        wp_send_json_success($response_data);
    }
    
    public function ajax_cleanup_fake_users() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $fake_ids_transient_key = $this->transient_key; // Legacy reference, but we use table now
        @set_time_limit(120);
        @ini_set('memory_limit', '512M');
        
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 25;
        $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] == 1;
        $force_cleanup = isset($_POST['force_cleanup']) && $_POST['force_cleanup'] == 1;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $is_initial = $offset === 0;
        
        $start_time = microtime(true);
        global $wpdb;
        $table_name = $wpdb->prefix . $this->temp_table;
        
        if ($is_initial) {
            $this->log_message('=== Starting Cleanup ' . ($dry_run ? '(Dry Run)' : '') . ($force_cleanup ? ' (FORCE MODE)' : '') . ' ===');
            
            $total_users = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            if ($total_users == 0) {
                wp_send_json_error(array('message' => 'No safe fake users found in temp table. Please run scan first.'));
                return;
            }
            
            $this->log_message("Total safe users to process: $total_users");
            
            if (!$dry_run) {
                $this->ensure_audit_table();
            }
            
            // Initialize cleanup data with initial total for progress %
            set_transient($this->transient_key . '_cleanup_data', array(
                'processed' => 0,
                'deleted' => 0,
                'skipped' => 0,
                'errors' => 0,
                'initial_total' => $total_users
            ), HOUR_IN_SECONDS);
        } else {
            // Get current cleanup data
            $cleanup_data = get_transient($this->transient_key . '_cleanup_data');
            if (!$cleanup_data) {
                wp_send_json_error(array('message' => 'Cleanup session expired. Please restart.'));
                return;
            }
            $total_users = $cleanup_data['initial_total'];
        }
        
        $this->log_message("Processing cleanup batch: up to $batch_size users from offset $offset");
        
        // Get batch from temp table
        $batch_results = $wpdb->get_results($wpdb->prepare(
            "SELECT id, email FROM $table_name LIMIT %d OFFSET %d",
            $batch_size, $offset
        ));
        
        $batch = $batch_results;
        $batch_deleted = 0;
        $batch_skipped = 0;
        $batch_errors = 0;
        $cleanup_data = get_transient($this->transient_key . '_cleanup_data'); // Refresh for this batch
        
        foreach ($batch as $row) {
            $user_id = $row->id;
            $user = get_user_by('id', $user_id);
            if (!$user) {
                $wpdb->delete($table_name, array('id' => $user_id), array('%d'));
                $batch_skipped++;
                $cleanup_data['skipped']++;
                $cleanup_data['processed']++;
                continue;
            }
            
            if ($dry_run) {
                $this->log_message("Would delete user ID: {$user_id}, Email: {$user->user_email}");
                $batch_deleted++;
                $cleanup_data['deleted']++;
            } else {
                // Safety check (can be bypassed with force)
                $safe_to_delete = $force_cleanup || $this->final_safety_check($user_id, $force_cleanup);
                
                if ($safe_to_delete) {
                    if (wp_delete_user($user_id)) {
                        $wpdb->delete($table_name, array('id' => $user_id), array('%d'));
                        
                        // Audit log for successful deletion
                        $wpdb->insert(
                            $wpdb->prefix . $this->audit_table,
                            array(
                                'user_id' => $user_id,
                                'email' => $user->user_email,
                                'registered' => $user->user_registered
                            ),
                            array('%d', '%s', '%s')
                        );
                        
                        $batch_deleted++;
                        $cleanup_data['deleted']++;
                        $this->log_message("Deleted user ID: {$user_id}, Email: {$user->user_email}");
                    } else {
                        // Leave in temp table for retry
                        $batch_errors++;
                        $cleanup_data['errors']++;
                        $this->log_message("Failed to delete user ID: {$user_id}, Email: {$user->user_email}", 'ERROR');
                    }
                } else {
                    // Remove from temp (skipped, won't retry)
                    $wpdb->delete($table_name, array('id' => $user_id), array('%d'));
                    $batch_skipped++;
                    $cleanup_data['skipped']++;
                    $this->log_message("Safety check failed for user ID: {$user_id}, Email: {$user->user_email}", 'WARNING');
                }
            }
            
            $cleanup_data['processed']++;
            unset($user); // Aid GC
        }
        
        // Update cleanup data
        set_transient($this->transient_key . '_cleanup_data', $cleanup_data, HOUR_IN_SECONDS);
        
        $percent = ($cleanup_data['processed'] / $total_users) * 100;
        $time_elapsed = round(microtime(true) - $start_time, 2);
        
        $this->log_message("Batch complete: Deleted: {$batch_deleted}, Skipped: {$batch_skipped}, Errors: {$batch_errors}, Time: {$time_elapsed}s");
        
        // Check if cleanup is complete
        $next_offset = $offset + $batch_size;
        $completed = $cleanup_data['processed'] >= $total_users;
        
        if ($completed) {
            $this->log_message("Cleanup complete! Processed: {$cleanup_data['processed']}, Deleted: {$cleanup_data['deleted']}, Skipped: {$cleanup_data['skipped']}, Errors: {$cleanup_data['errors']}");
            
            // Clean up transients (temp table can be dropped manually post-cleanup)
            delete_transient($this->transient_key . '_cleanup_data');
            
            wp_send_json_success(array(
                'completed' => true,
                'results' => $cleanup_data,
                'progress' => array(
                    'percent' => 100,
                    'processed' => $cleanup_data['processed'],
                    'deleted' => $cleanup_data['deleted'],
                    'skipped' => $cleanup_data['skipped']
                )
            ));
        } else {
            // Return progress for next batch
            wp_send_json_success(array(
                'completed' => false,
                'next_offset' => $next_offset,
                'progress' => array(
                    'percent' => $percent,
                    'processed' => $cleanup_data['processed'],
                    'deleted' => $cleanup_data['deleted'],
                    'skipped' => $cleanup_data['skipped']
                )
            ));
        }
    }
    
    private function comprehensive_user_validation($user_id, $email, $detailed_logging = false) {
        $validation = array(
            'user_id' => $user_id,
            'email' => $email,
            'is_fake' => false,
            'safe_to_delete' => false,
            'checks' => array()
        );
        
        // Email pattern check - CORRECTED REGEX
        $pattern = '/^[a-z]{8}\d{2}@(gmail\.com|outlook\.com|yahoo\.com|hotmail\.com)$/';
        $validation['checks']['email_pattern'] = array(
            'passed' => (bool) preg_match($pattern, $email),
            'description' => 'Email matches fake user pattern'
        );
        
        // Get user object once
        $user = get_user_by('id', $user_id);
        if (!$user) {
            $validation['checks']['user_exists'] = array(
                'passed' => false,
                'description' => 'User exists in database'
            );
            return $validation;
        }
        
        $validation['checks']['user_exists'] = array(
            'passed' => true,
            'description' => 'User exists in database'
        );
        
        // Registration date check - already filtered by query, so this should always pass
        $validation['checks']['registration_date'] = array(
            'passed' => true,
            'description' => 'Registered during incident timeframe',
            'value' => $user->user_registered
        );
        
        // WooCommerce orders check - FIXED to be more reliable
        $has_orders = false;
        if (function_exists('wc_get_orders')) {
            try {
                $orders = wc_get_orders(array(
                    'customer_id' => $user_id,
                    'limit' => 1,
                    'status' => 'any',
                    'return' => 'ids'
                ));
                $has_orders = !empty($orders);
            } catch (Exception $e) {
                // Fallback to direct query
                global $wpdb;
                $order_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm 
                     JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                     WHERE pm.meta_key = '_customer_user' 
                     AND pm.meta_value = %d 
                     AND p.post_type = 'shop_order'",
                    $user_id
                ));
                $has_orders = $order_count > 0;
            }
        } else {
            // Direct database query fallback
            global $wpdb;
            $order_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm 
                 JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                 WHERE pm.meta_key = '_customer_user' 
                 AND pm.meta_value = %d 
                 AND p.post_type = 'shop_order'",
                $user_id
            ));
            $has_orders = $order_count > 0;
        }
        
        $validation['checks']['no_orders'] = array(
            'passed' => !$has_orders,
            'description' => 'No WooCommerce orders',
            'value' => $has_orders ? 'Has orders' : 'No orders'
        );
        
        if ($detailed_logging && $has_orders) {
            $this->log_message("User {$user_id} has WooCommerce orders - NOT safe to delete");
        }
        
        // InterSoccer players metadata check - FIXED to be more thorough
        $player_data = get_user_meta($user_id, 'intersoccer_players', true);
        $has_player_data = false;
        
        if (!empty($player_data)) {
            if (is_array($player_data) && count($player_data) > 0) {
                // Check if it's actually meaningful data
                foreach ($player_data as $player) {
                    if (is_array($player)) {
                        if (!empty($player['first_name']) || !empty($player['last_name']) || !empty($player['dob']) || !empty($player['gender'])) {
                            $has_player_data = true;
                            break;
                        }
                    }
                }
            } elseif (is_string($player_data) && trim($player_data) !== '' && $player_data !== 'a:0:{}') {
                $has_player_data = true;
            }
        }
        
        $validation['checks']['no_player_data'] = array(
            'passed' => !$has_player_data,
            'description' => 'No intersoccer_players metadata',
            'value' => $has_player_data ? 'Has player data' : 'No player data'
        );
        
        if ($detailed_logging && $has_player_data) {
            $this->log_message("User {$user_id} has player data: " . substr(json_encode($player_data), 0, 200));
        }
        
        // Admin capabilities check - FIXED to be less restrictive
        $has_admin_caps = user_can($user_id, 'manage_options') || user_can($user_id, 'edit_users') || user_can($user_id, 'delete_users');
        $user_roles = $user->roles;
        $standard_roles = array('subscriber', 'customer', '');
        $has_elevated_role = !empty(array_diff($user_roles, $standard_roles));
        
        $validation['checks']['not_admin'] = array(
            'passed' => !$has_admin_caps && !$has_elevated_role,
            'description' => 'Not an administrator or elevated user',
            'value' => 'Roles: ' . implode(', ', $user_roles)
        );
        
        // Posts/content check - simplified
        $post_count = count_user_posts($user_id, array('post', 'page'), true);
        $validation['checks']['no_posts'] = array(
            'passed' => $post_count === 0,
            'description' => 'No posts or pages',
            'value' => $post_count . ' posts'
        );
        
        // Comments check - simplified
        $comment_count = get_comments(array('user_id' => $user_id, 'count' => true));
        $validation['checks']['no_comments'] = array(
            'passed' => $comment_count === 0,
            'description' => 'No comments',
            'value' => $comment_count . ' comments'
        );
        
        // Minimal metadata check - LESS RESTRICTIVE
        $all_meta = get_user_meta($user_id);
        $suspicious_meta_count = 0;
        $standard_meta = array(
            'nickname', 'first_name', 'last_name', 'description', 'rich_editing', 
            'syntax_highlighting', 'comment_shortcuts', 'admin_color', 'use_ssl', 
            'show_admin_bar_front', 'locale', 'wp_capabilities', 'wp_user_level',
            'dismissed_wp_pointers', 'show_welcome_panel'
        );
        
        foreach ($all_meta as $key => $value) {
            if (!in_array($key, $standard_meta) && !empty($value[0])) {
                $suspicious_meta_count++;
            }
        }
        
        $validation['checks']['minimal_metadata'] = array(
            'passed' => $suspicious_meta_count <= 3, // More lenient - allow up to 3 extra meta keys
            'description' => 'Minimal suspicious user metadata',
            'value' => $suspicious_meta_count . ' suspicious meta keys'
        );
        
        // Determine if user is fake and safe to delete
        $required_checks = array('email_pattern', 'registration_date', 'no_orders', 'no_player_data');
        $safety_checks = array('not_admin', 'no_posts', 'no_comments');

        $validation['is_fake'] = true;
        foreach ($required_checks as $check) {
            if (!isset($validation['checks'][$check]) || !$validation['checks'][$check]['passed']) {
                $validation['is_fake'] = false;
                if ($detailed_logging) {
                    $this->log_message("User {$user_id} failed required check: {$check}");
                }
                break;
            }
        }

        $validation['safe_to_delete'] = $validation['is_fake'];
        if ($validation['is_fake']) {
            // Only block deletion if user has orders or player data (ignore other safety checks for customer role)
            $user = get_user_by('id', $user_id);
            if (in_array('customer', $user->roles) && $validation['checks']['no_orders']['passed'] && $validation['checks']['no_player_data']['passed']) {
                $validation['safe_to_delete'] = true;
            } else {
                foreach ($safety_checks as $check) {
                    if (!isset($validation['checks'][$check]) || !$validation['checks'][$check]['passed']) {
                        $validation['safe_to_delete'] = false;
                        if ($detailed_logging) {
                            $this->log_message("User {$user_id} failed safety check: {$check}");
                        }
                        break;
                    }
                }
            }
        }

        // Log detailed failure reasons for first 20 fake users
        static $logged_users = 0;
        if ($validation['is_fake'] && $logged_users < 20) {
            $failed_checks = array();
            foreach ($validation['checks'] as $check_name => $check_data) {
                if (!$check_data['passed'] && in_array($check_name, ['no_orders', 'no_player_data', 'not_admin', 'no_posts', 'no_comments'])) {
                    $value = isset($check_data['value']) ? " ({$check_data['value']})" : '';
                    $failed_checks[] = "{$check_name}: {$check_data['description']}{$value}";
                }
            }
            // Add detailed role/capability info for not_admin
            if (isset($validation['checks']['not_admin']) && !$validation['checks']['not_admin']['passed']) {
                $user = get_user_by('id', $user_id);
                $roles = $user ? $user->roles : [];
                $capabilities = $user ? array_keys($user->allcaps) : [];
                $failed_checks[] = "not_admin_details: Roles=[" . implode(', ', $roles) . "], Capabilities=[" . implode(', ', $capabilities) . "]";
            }
            if (!empty($failed_checks)) {
                $this->log_message("Fake user ID {$user_id} (Email: {$email}) NOT safe to delete. Failed checks: " . implode(', ', $failed_checks), 'INFO');
            } else {
                $this->log_message("Fake user ID {$user_id} (Email: {$email}) unexpectedly marked unsafe despite passing all safety checks", 'ERROR');
            }
            $logged_users++;
        }
        
        return $validation;
    }
    
    public function final_safety_check($user_id, $force_mode = false) {
        if ($force_mode) {
            $this->log_message("FORCE MODE: Bypassing some safety checks for user {$user_id}", 'WARNING');
            $user = get_user_by('id', $user_id);
            if (!$user) return false;
            
            // In force mode, only check the most critical things
            if (user_can($user_id, 'manage_options')) {
                $this->log_message("SAFETY BLOCK (FORCE): User {$user_id} has manage_options capability", 'ERROR');
                return false;
            }
            
            // Check for orders
            if (function_exists('wc_get_orders')) {
                $orders = wc_get_orders(array('customer_id' => $user_id, 'limit' => 1, 'return' => 'ids'));
                if (!empty($orders)) {
                    $this->log_message("SAFETY BLOCK (FORCE): User {$user_id} has WooCommerce orders", 'ERROR');
                    return false;
                }
            }
            
            return true;
        }
        
        // Regular safety check
        $user = get_user_by('id', $user_id);
        if (!$user) return false;
        
        // Use comprehensive validation
        $validation = $this->comprehensive_user_validation($user_id, $user->user_email, true);
        
        if (!$validation['safe_to_delete']) {
            $failed_checks = array();
            foreach ($validation['checks'] as $check_name => $check_data) {
                if (!$check_data['passed']) {
                    $failed_checks[] = $check_name;
                }
            }
            $this->log_message("SAFETY BLOCK: User {$user_id} failed checks: " . implode(', ', $failed_checks), 'ERROR');
        }
        
        return $validation['safe_to_delete'];
    }
    
    public function log_message($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$level}] {$message}\n";
        
        if (WP_DEBUG_LOG) {
            error_log("InterSoccer Enhanced Cleanup [{$level}]: {$message}");
        }
        
        @file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
}

// Initialize the enhanced plugin
new InterSoccer_Fake_User_Cleanup();
?>