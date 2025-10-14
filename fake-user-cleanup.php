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

    private $progress_option_key = 'intersoccer_progress';

    private function generate_session_id() {
        return wp_generate_uuid4();
    }

    private function ensure_temp_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . $this->temp_table;
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL,
                email varchar(100) NOT NULL,
                registered datetime NOT NULL,
                PRIMARY KEY (id),
                KEY email_idx (email),
                KEY registered_idx (registered)
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
        
        add_action('wp_ajax_get_scan_status', array($this, 'ajax_get_scan_status'));
        add_action('wp_ajax_reset_scan', array($this, 'ajax_reset_scan'));
        // Add admin scripts for nonce
        add_action('admin_footer', array($this, 'admin_footer_script'));
    }

    public function ajax_get_scan_status() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $progress = get_option($this->progress_option_key, array());
        $incomplete = !empty($progress) && $progress['processed'] < $progress['total_users'] && $progress['status'] === 'running';
        wp_send_json_success(array('incomplete' => $incomplete, 'session' => $progress));
    }

    public function ajax_reset_scan() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}{$this->temp_table}");
        delete_option($this->progress_option_key);
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
                        <option value="50" selected>50 (Optimized)</option>
                        <option value="100">100 (Fast)</option>
                        <option value="25">25 (Conservative)</option>
                        <option value="10">10 (Very Safe)</option>
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
            let currentSessionId = null;

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
                        currentSessionId = response.data.session.session_id;
                        $('#resume-scan').show();
                        $('#reset-scan').show();
                        $('#scan-users').text('Resume Scan');
                        alert('Incomplete scan detected. Processed: ' + response.data.session.processed + '/' + response.data.session.total_users);
                    }
                }
            });

            $('#validate-date-range').click(function() {
                validateDateRange();
            });

            $('#scan-users').click(function() {
                if (!scanInProgress) {
                    if ($('#scan-users').text() === 'Resume Scan') {
                        resumeScan(currentSessionId);
                    } else {
                        startNewScan();
                    }
                }
            });

            $('#resume-scan').click(function() {
                if (!scanInProgress) {
                    resumeScan(currentSessionId);
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
                currentSessionId = '<?php echo wp_generate_uuid4(); ?>';
                processScanBatch(0, batchSize, true);
            }

            function resumeScan(sessionId) {
                scanInProgress = true;
                $('#scan-users').prop('disabled', true).text('Resuming scan...');
                $('#resume-scan, #reset-scan').hide();
                $('#scan-progress-container').show();
                $('#results-section, #cleanup-section, #debug-section').hide();
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
                            updateScanProgress({
                                percent: session.total_users > 0 ? (session.processed / session.total_users) * 100 : 0,
                                processed: session.processed,
                                fake_found: session.fake_found,
                                safe_found: session.safe_found,
                                memory_mb: 0
                            });
                            processScanBatch(session.last_offset, batchSize, false);
                        } else {
                            alert('Error resuming scan: ' + (response.data.message || 'Session not found'));
                            resetScanUI();
                        }
                    }
                });
            }

            function processScanBatch(offset, batchSize, isNewScan) {
                const startDate = $('#incident-start-date').val();
                const endDate = $('#incident-end-date').val();
                const detailedLogging = $('#detailed-logging').is(':checked');
                const debugFirst10 = $('#debug-first-10').is(':checked');
                $('#scan-users').text(isNewScan ? 'Scanning users...' : `Processing batch ${Math.floor(offset/batchSize) + 1}...`);
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    timeout: 120000,
                    data: {
                        action: 'scan_fake_users_enhanced',
                        nonce: window.intersoccerCleanup.nonce,
                        session_id: currentSessionId,
                        batch_size: batchSize,
                        offset: offset,
                        start_date: startDate,
                        end_date: endDate,
                        detailed_logging: detailedLogging ? 1 : 0,
                        debug_first_10: debugFirst10 ? 1 : 0,
                        is_new_scan: isNewScan ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            updateScanProgress(response.data.progress);
                            if (response.data.debug_info) {
                                displayDebugInfo(response.data.debug_info);
                            }
                            if (response.data.completed) {
                                completeScan(response.data.results);
                            } else {
                                setTimeout(function() {
                                    processScanBatch(response.data.next_offset, batchSize, false);
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
                const batchSize = parseInt($('#cleanup-batch-size').val());
                const dryRun = $('#dry-run').is(':checked');
                const forceCleanup = $('#force-cleanup').is(':checked');
                currentSessionId = '<?php echo wp_generate_uuid4(); ?>';
                processCleanupBatch(0, batchSize, true, dryRun, forceCleanup);
            }

            function processCleanupBatch(offset, batchSize, isNewCleanup, dryRun, forceCleanup) {
                $('#cleanup-users').text(isNewCleanup ? 'Cleaning up users...' : `Processing cleanup batch ${Math.floor(offset/batchSize) + 1}...`);
                $.ajax({
                    url: window.intersoccerCleanup.ajaxurl,
                    type: 'POST',
                    timeout: 120000,
                    data: {
                        action: 'cleanup_fake_users_enhanced',
                        nonce: window.intersoccerCleanup.nonce,
                        session_id: currentSessionId,
                        batch_size: batchSize,
                        offset: offset,
                        dry_run: dryRun ? 1 : 0,
                        force_cleanup: forceCleanup ? 1 : 0,
                        is_new_cleanup: isNewCleanup ? 1 : 0
                    },
                    success: function(response) {
                        if (response.success) {
                            updateCleanupProgress(response.data.progress);
                            if (response.data.completed) {
                                completeCleanup(response.data.results);
                            } else {
                                setTimeout(function() {
                                    processCleanupBatch(response.data.next_offset, batchSize, false, dryRun, forceCleanup);
                                }, dryRun ? 100 : 500);
                            }
                        } else {
                            alert('Error during cleanup: ' + response.data.message + '. Progress saved; you can resume.');
                            resetCleanupUI();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        alert('Network error during cleanup. Progress saved; you can resume.');
                        resetCleanupUI();
                    }
                });
            }

            function updateScanProgress(data) {
                const percent = data.percent || 0;
                $('#scan-progress-fill').css('width', percent + '%');
                $('#scan-progress-text').text(`${percent.toFixed(1)}% complete`);
                if (data.processed !== undefined) {
                    $('#scan-stats').text(`Processed: ${data.processed} | Fake found: ${data.fake_found || 0} | Safe: ${data.safe_found || 0}`);
                }
            }

            function updateCleanupProgress(data) {
                const percent = data.percent || 0;
                $('#cleanup-progress-fill').css('width', percent + '%');
                $('#cleanup-progress-text').text(`${percent.toFixed(1)}% complete`);
                if (data.processed !== undefined) {
                    $('#cleanup-stats').text(`Processed: ${data.processed} | Deleted: ${data.deleted || 0} | Skipped: ${data.skipped || 0}`);
                }
            }

            function completeScan(results) {
                resetScanUI();
                displayScanResults(results);
                $('#results-section, #cleanup-section').show();
            }

            function completeCleanup(results) {
                resetCleanupUI();
                const message = `Cleanup complete!\nProcessed: ${results.processed}\nDeleted: ${results.deleted}\nSkipped: ${results.skipped}\nErrors: ${results.errors}`;
                alert(message);
                if (confirm('Cleanup completed. Run new scan to verify?')) {
                    location.reload();
                }
            }

            function resetScanUI() {
                scanInProgress = false;
                $('#scan-users').prop('disabled', false).text('Start New Scan');
                $('#resume-scan, #reset-scan').show();
                $('#scan-progress-container').hide();
            }

            function resetCleanupUI() {
                cleanupInProgress = false;
                $('#cleanup-users').prop('disabled', false).text('Start Cleanup');
                $('#cleanup-progress-container').hide();
            }

            function displayScanResults(results) {
                // Display summary
                let summaryHtml = '<div class="summary-grid">';
                summaryHtml += `<div class="summary-item"><h4>Total Scanned</h4><div class="number">${results.total_scanned}</div></div>`;
                summaryHtml += `<div class="summary-item"><h4>Pattern Matches</h4><div class="number">${results.pattern_matches}</div></div>`;
                summaryHtml += `<div class="summary-item"><h4>Fake Users Found</h4><div class="number">${results.fake_users_count}</div></div>`;
                summaryHtml += `<div class="summary-item"><h4>Safe to Delete</h4><div class="number">${results.safe_to_delete}</div></div>`;
                summaryHtml += '</div>';
                $('#scan-summary').html(summaryHtml);
                
                // Display sample users with fake scores
                if (results.sample_users && results.sample_users.length > 0) {
                    let sampleHtml = '<h4>Sample Fake Users (with detection scores):</h4>';
                    results.sample_users.forEach(user => {
                        const statusClass = user.safe_to_delete ? 'status-safe' : 'status-warning';
                        const statusText = user.safe_to_delete ? 'Safe to Delete' : 'Unsafe';
                        sampleHtml += `<div class="user-item">
                            <span class="status-indicator ${statusClass}"></span>
                            <strong>ID ${user.ID}:</strong> ${user.user_email} 
                            <em>(Registered: ${new Date(user.user_registered).toLocaleDateString()})</em>
                            <br><small>Status: ${statusText}</small>
                        </div>`;
                    });
                    $('#sample-users').html(sampleHtml);
                }
                
                // Display safety breakdown
                if (results.safety_breakdown) {
                    let breakdownHtml = '<h4>Why Users Are Not Safe to Delete:</h4>';
                    Object.entries(results.safety_breakdown).forEach(([reason, count]) => {
                        breakdownHtml += `<div class="check-result failed">
                            <span>${reason.replace(/_/g, ' ')}: ${count} users</span>
                        </div>`;
                    });
                    $('#safety-check-breakdown').html(breakdownHtml);
                }
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
        
        // Process current batch - CORRECTED REGEX - Get user IDs only first
        $user_ids_query = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} 
            WHERE user_email REGEXP '^[a-z]{8}[0-9]{2}@(gmail|outlook|yahoo|hotmail)\\.com$'
            AND user_registered >= %s AND user_registered <= %s
            LIMIT %d OFFSET %d",
            $start_date, $end_date, $batch_size, $offset
        );
        
        $user_ids = $wpdb->get_col($user_ids_query);
        $this->log_message("Processing batch: " . count($user_ids) . " users from offset {$offset}");
        
        if (empty($user_ids)) {
            // No more users to process
            $percent = ($scan_data['processed'] / $total_users) * 100;
            wp_send_json_success(array(
                'completed' => true,
                'results' => array(
                    'total_scanned' => $scan_data['processed'],
                    'pattern_matches' => $total_users,
                    'fake_users_count' => $scan_data['fake_count'],
                    'safe_to_delete' => $scan_data['safe_to_delete_count'],
                    'sample_users' => $scan_data['sample_fake_users'],
                    'safety_breakdown' => $scan_data['safety_breakdown']
                ),
                'progress' => array(
                    'percent' => $percent,
                    'processed' => $scan_data['processed'],
                    'fake_found' => $scan_data['fake_count'],
                    'safe_found' => $scan_data['safe_to_delete_count']
                )
            ));
            return;
        }
        
        // OPTIMIZATION: Pre-load all required data in batches
        $user_ids_string = implode(',', array_map('intval', $user_ids));
        
        // Batch load user data
        $users_data = $wpdb->get_results("
            SELECT ID, user_email, user_registered, user_login, display_name 
            FROM {$wpdb->users} 
            WHERE ID IN ({$user_ids_string})
        ", OBJECT_K);
        
        // Batch load user meta (capabilities and player data)
        $user_meta = $wpdb->get_results("
            SELECT user_id, meta_key, meta_value 
            FROM {$wpdb->usermeta} 
            WHERE user_id IN ({$user_ids_string}) 
            AND meta_key IN ('{$wpdb->get_blog_prefix()}capabilities', 'intersoccer_players')
        ");
        
        // Organize meta data by user
        $meta_by_user = array();
        foreach ($user_meta as $meta) {
            if (!isset($meta_by_user[$meta->user_id])) {
                $meta_by_user[$meta->user_id] = array();
            }
            $meta_by_user[$meta->user_id][$meta->meta_key] = $meta->meta_value;
        }
        
        // Batch check for WooCommerce orders - OPTIMIZED QUERY
        $orders_check = $wpdb->get_results("
            SELECT DISTINCT pm.meta_value as user_id, COUNT(p.ID) as order_count
            FROM {$wpdb->postmeta} pm 
            JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
            WHERE pm.meta_key = '_customer_user' 
            AND pm.meta_value IN ({$user_ids_string})
            AND p.post_type = 'shop_order' 
            AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending')
            GROUP BY pm.meta_value
        ", OBJECT_K);
        
        // Batch check for posts
        $posts_check = $wpdb->get_results("
            SELECT post_author as user_id, COUNT(ID) as post_count
            FROM {$wpdb->posts} 
            WHERE post_author IN ({$user_ids_string})
            AND post_type IN ('post', 'page')
            AND post_status != 'trash'
            GROUP BY post_author
        ", OBJECT_K);
        
        // Batch check for comments
        $comments_check = $wpdb->get_results("
            SELECT user_id, COUNT(comment_ID) as comment_count
            FROM {$wpdb->comments} 
            WHERE user_id IN ({$user_ids_string})
            GROUP BY user_id
        ", OBJECT_K);
        
        $batch_fake_count = 0;
        $batch_safe_count = 0;
        $debug_info = array();
        $table_name = $wpdb->prefix . $this->temp_table;
        
        foreach ($user_ids as $user_id) {
            $user = isset($users_data[$user_id]) ? $users_data[$user_id] : null;
            if (!$user) {
                $this->log_message("User {$user_id} not found in batch data", 'WARNING');
                continue;
            }
            
            // Use pre-loaded data for validation
            $validation = $this->advanced_fake_user_detection(
                $user, 
                $meta_by_user[$user_id] ?? array(),
                $orders_check[$user_id] ?? null,
                $posts_check[$user_id] ?? null,
                $comments_check[$user_id] ?? null,
                $detailed_logging
            );
            
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
            unset($validation); // Aid GC
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
        
        $this->log_message("Batch complete: {$batch_fake_count} fake users found ({$batch_safe_count} safe to delete), Memory: {$memory_mb}MB, Time: {$time_elapsed}s");
        
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
        
        if (empty($batch_results)) {
            // No more users to process
            $cleanup_data = get_transient($this->transient_key . '_cleanup_data');
            $this->log_message("Cleanup complete! Processed: {$cleanup_data['processed']}, Deleted: {$cleanup_data['deleted']}, Skipped: {$cleanup_data['skipped']}, Errors: {$cleanup_data['errors']}");
            
            // Clean up transients
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
            return;
        }
        
        $batch = $batch_results;
        $batch_deleted = 0;
        $batch_skipped = 0;
        $batch_errors = 0;
        $cleanup_data = get_transient($this->transient_key . '_cleanup_data'); // Refresh for this batch
        
        // OPTIMIZATION: Pre-load user data for the batch
        $user_ids = array_column($batch, 'id');
        $user_ids_string = implode(',', array_map('intval', $user_ids));
        
        // Batch load user data and capabilities
        $users_data = $wpdb->get_results("
            SELECT u.ID, u.user_email, u.user_registered, u.user_login, u.display_name,
                   um.meta_value as capabilities
            FROM {$wpdb->users} u
            LEFT JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = '{$wpdb->get_blog_prefix()}capabilities'
            WHERE u.ID IN ({$user_ids_string})
        ", OBJECT_K);
        
        // Batch check for orders (only if not force mode)
        $orders_check = array();
        if (!$force_cleanup) {
            $orders_results = $wpdb->get_results("
                SELECT DISTINCT pm.meta_value as user_id, COUNT(p.ID) as order_count
                FROM {$wpdb->postmeta} pm 
                JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                WHERE pm.meta_key = '_customer_user' 
                AND pm.meta_value IN ({$user_ids_string})
                AND p.post_type = 'shop_order' 
                AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending')
                GROUP BY pm.meta_value
            ", OBJECT_K);
            $orders_check = $orders_results;
        }
        
        foreach ($batch as $row) {
            $user_id = $row->id;
            $user = isset($users_data[$user_id]) ? $users_data[$user_id] : null;
            
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
                $safe_to_delete = $force_cleanup || $this->optimized_final_safety_check($user, $orders_check[$user_id] ?? null, $force_cleanup);
                
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
            
            // Clean up transients
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

        $pattern = '/^[a-z]{8}\d{2}@(gmail\.com|outlook\.com|yahoo\.com|hotmail\.com)$/';
        $validation['checks']['email_pattern'] = array(
            'passed' => (bool) preg_match($pattern, $email),
            'description' => 'Email matches fake user pattern'
        );

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

        $validation['checks']['registration_date'] = array(
            'passed' => true,
            'description' => 'Registered during incident timeframe',
            'value' => $user->user_registered
        );

        global $wpdb;
        $has_orders = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm 
            JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
            WHERE pm.meta_key = '_customer_user' 
            AND pm.meta_value = %d 
            AND p.post_type = 'shop_order' 
            LIMIT 1",
            $user_id
        )) > 0;
        $validation['checks']['no_orders'] = array(
            'passed' => !$has_orders,
            'description' => 'No WooCommerce orders',
            'value' => $has_orders ? 'Has orders' : 'No orders'
        );
        if ($detailed_logging && $has_orders) {
            $this->log_message("User {$user_id} has WooCommerce orders - NOT safe to delete");
        }

        $player_data = get_user_meta($user_id, 'intersoccer_players', true);
        $has_player_data = false;
        if (!empty($player_data) && is_array($player_data)) {
            foreach ($player_data as $player) {
                if (is_array($player) && (!empty($player['first_name']) || !empty($player['last_name']) || !empty($player['dob']) || !empty($player['gender']))) {
                    $has_player_data = true;
                    break;
                }
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

        $capabilities_meta = get_user_meta($user_id, $wpdb->get_blog_prefix() . 'capabilities', true);
        $user_roles = $capabilities_meta ? array_keys(array_filter($capabilities_meta)) : array();
        $has_admin_caps = user_can($user_id, 'manage_options');
        $allowed_roles = array('subscriber', 'customer', 'coach', 'event_organizer', 'organization_intern', 'shop_manager', '');
        $has_elevated_role = !empty(array_diff($user_roles, $allowed_roles));
        $validation['checks']['not_admin'] = array(
            'passed' => !$has_admin_caps && !$has_elevated_role,
            'description' => 'Not an administrator or elevated user',
            'value' => 'Roles: ' . implode(', ', $user_roles)
        );

        $post_count = count_user_posts($user_id, array('post', 'page'), true);
        $validation['checks']['no_posts'] = array(
            'passed' => $post_count === 0,
            'description' => 'No posts or pages',
            'value' => $post_count . ' posts'
        );

        $comment_count = get_comments(array('user_id' => $user_id, 'count' => true));
        $validation['checks']['no_comments'] = array(
            'passed' => $comment_count === 0,
            'description' => 'No comments',
            'value' => $comment_count . ' comments'
        );

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
        if ($validation['is_fake'] && in_array('customer', $user_roles) && $validation['checks']['no_orders']['passed'] && $validation['checks']['no_player_data']['passed']) {
            $validation['safe_to_delete'] = true;
        } elseif ($validation['is_fake']) {
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

        static $logged_users = 0;
        if ($validation['is_fake'] && $logged_users < 20) {
            $failed_checks = array();
            foreach ($validation['checks'] as $check_name => $check_data) {
                if (!$check_data['passed'] && in_array($check_name, ['no_orders', 'no_player_data', 'not_admin', 'no_posts', 'no_comments'])) {
                    $value = isset($check_data['value']) ? " ({$check_data['value']})" : '';
                    $failed_checks[] = "{$check_name}: {$check_data['description']}{$value}";
                }
            }
            if (isset($validation['checks']['not_admin']) && !$validation['checks']['not_admin']['passed']) {
                $failed_checks[] = "not_admin_details: Roles=[" . implode(', ', $user_roles) . "], Capabilities=[" . implode(', ', array_keys($user->allcaps)) . "]";
            }
            if (!empty($failed_checks)) {
                $this->log_message("Fake user ID {$user_id} (Email: {$email}) NOT safe to delete. Failed checks: " . implode(', ', $failed_checks), 'INFO');
            } else {
                $this->log_message("Fake user ID {$user_id} (Email: {$email}) unexpectedly marked unsafe despite passing all safety checks", 'ERROR');
            }
            $logged_users++;
        }

        unset($user, $capabilities_meta, $player_data);
        return $validation;
    }
    
    private function optimized_user_validation($user, $user_meta, $orders_data, $posts_data, $comments_data, $detailed_logging = false) {
        $validation = array(
            'user_id' => $user->ID,
            'email' => $user->user_email,
            'is_fake' => false,
            'safe_to_delete' => false,
            'checks' => array()
        );

        $pattern = '/^[a-z]{8}\d{2}@(gmail\.com|outlook\.com|yahoo\.com|hotmail\.com)$/';
        $validation['checks']['email_pattern'] = array(
            'passed' => (bool) preg_match($pattern, $user->user_email),
            'description' => 'Email matches fake user pattern'
        );

        $validation['checks']['user_exists'] = array(
            'passed' => true,
            'description' => 'User exists in database'
        );

        $validation['checks']['registration_date'] = array(
            'passed' => true,
            'description' => 'Registered during incident timeframe',
            'value' => $user->user_registered
        );

        // Use pre-loaded orders data
        $has_orders = $orders_data && $orders_data->order_count > 0;
        $validation['checks']['no_orders'] = array(
            'passed' => !$has_orders,
            'description' => 'No WooCommerce orders',
            'value' => $has_orders ? 'Has orders' : 'No orders'
        );
        if ($detailed_logging && $has_orders) {
            $this->log_message("User {$user->ID} has WooCommerce orders - NOT safe to delete");
        }

        // Use pre-loaded player data
        $player_data = isset($user_meta['intersoccer_players']) ? maybe_unserialize($user_meta['intersoccer_players']) : null;
        $has_player_data = false;
        if (!empty($player_data) && is_array($player_data)) {
            foreach ($player_data as $player) {
                if (is_array($player) && (!empty($player['first_name']) || !empty($player['last_name']) || !empty($player['dob']) || !empty($player['gender']))) {
                    $has_player_data = true;
                    break;
                }
            }
        }
        $validation['checks']['no_player_data'] = array(
            'passed' => !$has_player_data,
            'description' => 'No intersoccer_players metadata',
            'value' => $has_player_data ? 'Has player data' : 'No player data'
        );
        if ($detailed_logging && $has_player_data) {
            $this->log_message("User {$user->ID} has player data: " . substr(json_encode($player_data), 0, 200));
        }

        // Use pre-loaded capabilities
        $capabilities_meta = isset($user_meta[$GLOBALS['wpdb']->get_blog_prefix() . 'capabilities']) ? 
                            maybe_unserialize($user_meta[$GLOBALS['wpdb']->get_blog_prefix() . 'capabilities']) : array();
        $user_roles = $capabilities_meta ? array_keys(array_filter($capabilities_meta)) : array();
        $has_admin_caps = !empty(array_intersect($user_roles, array('administrator', 'editor', 'author')));
        $allowed_roles = array('subscriber', 'customer', 'coach', 'event_organizer', 'organization_intern', 'shop_manager', '');
        $has_elevated_role = !empty(array_diff($user_roles, $allowed_roles));
        $validation['checks']['not_admin'] = array(
            'passed' => !$has_admin_caps && !$has_elevated_role,
            'description' => 'Not an administrator or elevated user',
            'value' => 'Roles: ' . implode(', ', $user_roles)
        );

        // Use pre-loaded posts data
        $post_count = $posts_data ? intval($posts_data->post_count) : 0;
        $validation['checks']['no_posts'] = array(
            'passed' => $post_count === 0,
            'description' => 'No posts or pages',
            'value' => $post_count . ' posts'
        );

        // Use pre-loaded comments data
        $comment_count = $comments_data ? intval($comments_data->comment_count) : 0;
        $validation['checks']['no_comments'] = array(
            'passed' => $comment_count === 0,
            'description' => 'No comments',
            'value' => $comment_count . ' comments'
        );

        $required_checks = array('email_pattern', 'registration_date', 'no_orders', 'no_player_data');
        $safety_checks = array('not_admin', 'no_posts', 'no_comments');
        $validation['is_fake'] = true;
        foreach ($required_checks as $check) {
            if (!isset($validation['checks'][$check]) || !$validation['checks'][$check]['passed']) {
                $validation['is_fake'] = false;
                if ($detailed_logging) {
                    $this->log_message("User {$user->ID} failed required check: {$check}");
                }
                break;
            }
        }

        $validation['safe_to_delete'] = $validation['is_fake'];
        if ($validation['is_fake'] && in_array('customer', $user_roles) && $validation['checks']['no_orders']['passed'] && $validation['checks']['no_player_data']['passed']) {
            $validation['safe_to_delete'] = true;
        } elseif ($validation['is_fake']) {
            foreach ($safety_checks as $check) {
                if (!isset($validation['checks'][$check]) || !$validation['checks'][$check]['passed']) {
                    $validation['safe_to_delete'] = false;
                    if ($detailed_logging) {
                        $this->log_message("User {$user->ID} failed safety check: {$check}");
                    }
                    break;
                }
            }
        }

        static $logged_users = 0;
        if ($validation['is_fake'] && $logged_users < 20) {
            $failed_checks = array();
            foreach ($validation['checks'] as $check_name => $check_data) {
                if (!$check_data['passed'] && in_array($check_name, ['no_orders', 'no_player_data', 'not_admin', 'no_posts', 'no_comments'])) {
                    $value = isset($check_data['value']) ? " ({$check_data['value']})" : '';
                    $failed_checks[] = "{$check_name}: {$check_data['description']}{$value}";
                }
            }
            if (isset($validation['checks']['not_admin']) && !$validation['checks']['not_admin']['passed']) {
                $failed_checks[] = "not_admin_details: Roles=[" . implode(', ', $user_roles) . "], Capabilities=[" . implode(', ', array_keys($user->allcaps)) . "]";
            }
            if (!empty($failed_checks)) {
                $this->log_message("Fake user ID {$user->ID} (Email: {$user->user_email}) NOT safe to delete. Failed checks: " . implode(', ', $failed_checks), 'INFO');
            } else {
                $this->log_message("Fake user ID {$user->ID} (Email: {$user->user_email}) unexpectedly marked unsafe despite passing all safety checks", 'ERROR');
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
    
    private function optimized_final_safety_check($user, $orders_data, $force_mode = false) {
        if ($force_mode) {
            $this->log_message("FORCE MODE: Bypassing some safety checks for user {$user->ID}", 'WARNING');
            
            // In force mode, only check the most critical things
            $capabilities = maybe_unserialize($user->capabilities);
            if (!empty($capabilities) && isset($capabilities['administrator'])) {
                $this->log_message("SAFETY BLOCK (FORCE): User {$user->ID} has administrator capability", 'ERROR');
                return false;
            }
            
            // Check for orders using pre-loaded data
            if ($orders_data && $orders_data->order_count > 0) {
                $this->log_message("SAFETY BLOCK (FORCE): User {$user->ID} has WooCommerce orders", 'ERROR');
                return false;
            }
            
            return true;
        }
        
        // Regular safety check using pre-loaded data
        $capabilities = maybe_unserialize($user->capabilities);
        $user_roles = $capabilities ? array_keys(array_filter($capabilities)) : array();
        
        // Check for admin capabilities
        if (!empty(array_intersect($user_roles, array('administrator', 'editor', 'author')))) {
            $this->log_message("SAFETY BLOCK: User {$user->ID} has elevated role: " . implode(', ', $user_roles), 'ERROR');
            return false;
        }
        
        // Check for orders using pre-loaded data
        if ($orders_data && $orders_data->order_count > 0) {
            $this->log_message("SAFETY BLOCK: User {$user->ID} has WooCommerce orders", 'ERROR');
            return false;
        }
        
        return true;
    }
    
    public function log_message($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $memory_mb = round(memory_get_usage() / 1024 / 1024, 2);
        $log_entry = "[{$timestamp}] [{$level}] {$message} (Memory: {$memory_mb}MB)\n";
        
        if (WP_DEBUG_LOG) {
            error_log("InterSoccer Enhanced Cleanup [{$level}]: {$message} (Memory: {$memory_mb}MB)");
        }
        
        @file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    private function advanced_fake_user_detection($user, $user_meta, $orders_data, $posts_data, $comments_data, $detailed_logging = false) {
        $validation = array(
            'user_id' => $user->ID,
            'email' => $user->user_email,
            'is_fake' => false,
            'safe_to_delete' => false,
            'fake_score' => 0, // 0-100 score of fakeness
            'checks' => array()
        );

        // === BASIC PATTERN CHECKS ===
        $pattern = '/^[a-z]{8}\d{2}@(gmail\.com|outlook\.com|yahoo\.com|hotmail\.com)$/';
        $email_pattern_match = (bool) preg_match($pattern, $user->user_email);
        $validation['checks']['email_pattern'] = array(
            'passed' => $email_pattern_match,
            'description' => 'Email matches suspicious pattern',
            'score' => $email_pattern_match ? 30 : 0
        );
        if ($email_pattern_match) $validation['fake_score'] += 30;

        // === BEHAVIORAL ANALYSIS ===
        
        // 1. Login Activity (check if user ever logged in)
        global $wpdb;
        $last_login = get_user_meta($user->ID, 'last_login', true);
        $login_count = get_user_meta($user->ID, 'login_count', true);
        $never_logged_in = empty($last_login) && empty($login_count);
        $validation['checks']['never_logged_in'] = array(
            'passed' => $never_logged_in,
            'description' => 'User never logged in',
            'score' => $never_logged_in ? 25 : 0,
            'value' => $never_logged_in ? 'Never logged in' : 'Has login activity'
        );
        if ($never_logged_in) $validation['fake_score'] += 25;

        // 2. Account Age vs Activity (old account, no activity)
        $account_age_days = (time() - strtotime($user->user_registered)) / (60 * 60 * 24);
        $old_inactive_account = $account_age_days > 30 && $never_logged_in;
        $validation['checks']['old_inactive'] = array(
            'passed' => $old_inactive_account,
            'description' => 'Old account with no activity',
            'score' => $old_inactive_account ? 15 : 0,
            'value' => "Age: {$account_age_days} days"
        );
        if ($old_inactive_account) $validation['fake_score'] += 15;

        // 3. Profile Completeness
        $empty_profile = empty($user->display_name) || $user->display_name === $user->user_login;
        $no_description = empty(get_user_meta($user->ID, 'description', true));
        $no_website = empty(get_user_meta($user->ID, 'user_url', true));
        $incomplete_profile = $empty_profile && $no_description && $no_website;
        $validation['checks']['incomplete_profile'] = array(
            'passed' => $incomplete_profile,
            'description' => 'Incomplete profile information',
            'score' => $incomplete_profile ? 10 : 0,
            'value' => $incomplete_profile ? 'Empty profile' : 'Has profile data'
        );
        if ($incomplete_profile) $validation['fake_score'] += 10;

        // === ACCOUNT CHARACTERISTICS ===
        
        // 4. Sequential User IDs (bulk creation pattern)
        $prev_user_id = $user->ID - 1;
        $next_user_id = $user->ID + 1;
        $sequential_pattern = false;
        
        // Check if surrounding IDs have similar registration times (within 1 hour)
        $surrounding_users = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, user_registered FROM {$wpdb->users} 
             WHERE ID IN (%d, %d, %d)",
            $prev_user_id, $user->ID, $next_user_id
        ));
        
        if (count($surrounding_users) >= 2) {
            $times = array_column($surrounding_users, 'user_registered', 'ID');
            $user_time = strtotime($times[$user->ID]);
            $time_diffs = [];
            
            if (isset($times[$prev_user_id])) {
                $time_diffs[] = abs($user_time - strtotime($times[$prev_user_id]));
            }
            if (isset($times[$next_user_id])) {
                $time_diffs[] = abs($user_time - strtotime($times[$next_user_id]));
            }
            
            // If registered within 5 minutes of neighboring accounts
            $sequential_pattern = !empty(array_filter($time_diffs, function($diff) { return $diff < 300; }));
        }
        
        $validation['checks']['sequential_registration'] = array(
            'passed' => $sequential_pattern,
            'description' => 'Sequential registration pattern',
            'score' => $sequential_pattern ? 20 : 0,
            'value' => $sequential_pattern ? 'Bulk registration pattern' : 'Normal registration'
        );
        if ($sequential_pattern) $validation['fake_score'] += 20;

        // 5. Default User Data
        $capabilities = isset($user_meta[$wpdb->get_blog_prefix() . 'capabilities']) ? 
                        maybe_unserialize($user_meta[$wpdb->get_blog_prefix() . 'capabilities']) : array();
        $only_customer_role = !empty($capabilities) && 
                             array_keys($capabilities) === ['customer'] && 
                             $capabilities['customer'] == 1;
        $validation['checks']['default_customer_role'] = array(
            'passed' => $only_customer_role,
            'description' => 'Only default customer role',
            'score' => $only_customer_role ? 5 : 0,
            'value' => $only_customer_role ? 'Only customer role' : 'Has other roles'
        );
        if ($only_customer_role) $validation['fake_score'] += 5;

        // === CONTENT ANALYSIS ===
        
        // 6. No Content Creation
        $post_count = $posts_data ? intval($posts_data->post_count) : 0;
        $comment_count = $comments_data ? intval($comments_data->comment_count) : 0;
        $no_content = $post_count === 0 && $comment_count === 0;
        $validation['checks']['no_content'] = array(
            'passed' => $no_content,
            'description' => 'No posts or comments',
            'score' => $no_content ? 10 : 0,
            'value' => "Posts: {$post_count}, Comments: {$comment_count}"
        );
        if ($no_content) $validation['fake_score'] += 10;

        // === EXISTING CHECKS (Orders & Player Data) ===
        
        // 7. No Orders
        $has_orders = $orders_data && $orders_data->order_count > 0;
        $validation['checks']['no_orders'] = array(
            'passed' => !$has_orders,
            'description' => 'No WooCommerce orders',
            'score' => !$has_orders ? 15 : 0,
            'value' => $has_orders ? 'Has orders' : 'No orders'
        );
        if (!$has_orders) $validation['fake_score'] += 15;

        // 8. No Player Data
        $player_data = isset($user_meta['intersoccer_players']) ? maybe_unserialize($user_meta['intersoccer_players']) : null;
        $has_player_data = false;
        if (!empty($player_data) && is_array($player_data)) {
            foreach ($player_data as $player) {
                if (is_array($player) && (!empty($player['first_name']) || !empty($player['last_name']) || !empty($player['dob']) || !empty($player['gender']))) {
                    $has_player_data = true;
                    break;
                }
            }
        }
        $validation['checks']['no_player_data'] = array(
            'passed' => !$has_player_data,
            'description' => 'No intersoccer_players metadata',
            'score' => !$has_player_data ? 15 : 0,
            'value' => $has_player_data ? 'Has player data' : 'No player data'
        );
        if (!$has_player_data) $validation['fake_score'] += 15;

        // === SCORING SYSTEM ===
        
        // Determine if fake based on score and critical criteria
        $critical_criteria = $email_pattern_match && !$has_orders && !$has_player_data;
        $high_score_fake = $validation['fake_score'] >= 70;
        
        $validation['is_fake'] = $critical_criteria || $high_score_fake;
        
        // Safety checks (must pass these even if fake)
        $capabilities_meta = maybe_unserialize($user->capabilities);
        $user_roles = $capabilities_meta ? array_keys(array_filter($capabilities_meta)) : array();
        $has_admin_caps = !empty(array_intersect($user_roles, array('administrator', 'editor', 'author')));
        $has_elevated_role = !empty(array_diff($user_roles, array('subscriber', 'customer', 'coach', 'event_organizer', 'organization_intern', 'shop_manager', '')));
        
        $validation['checks']['not_admin'] = array(
            'passed' => !$has_admin_caps && !$has_elevated_role,
            'description' => 'Not an administrator or elevated user',
            'score' => 0, // Safety check, not scoring
            'value' => 'Roles: ' . implode(', ', $user_roles)
        );
        
        // Final safety determination
        $validation['safe_to_delete'] = $validation['is_fake'] && 
                                       $validation['checks']['not_admin']['passed'] && 
                                       $validation['checks']['no_orders']['passed'] && 
                                       $validation['checks']['no_player_data']['passed'];

        // Add score to result
        $validation['fake_score_percentage'] = min(100, $validation['fake_score']);
        
        if ($detailed_logging && $validation['is_fake']) {
            $this->log_message("Advanced fake detection for user {$user->ID}: Score {$validation['fake_score']}%, Safe: " . ($validation['safe_to_delete'] ? 'YES' : 'NO'), 'INFO');
        }

        return $validation;
    }
}

// Initialize the enhanced plugin
new InterSoccer_Fake_User_Cleanup();
?>