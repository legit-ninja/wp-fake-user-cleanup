<?php
/**
 * Plugin Name: InterSoccer Fake User Cleanup - Enhanced
 * Description: Enhanced cleanup tool for fake users with real-time progress
 * Version: 2.0.0
 * Author: InterSoccer Development Team
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class InterSoccer_Enhanced_Fake_User_Cleanup {
    
    private $log_file;
    private $transient_key = 'intersoccer_fake_ids_v2';
    
    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/intersoccer-cleanup-enhanced.log';
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_scan_fake_users_enhanced', array($this, 'ajax_scan_fake_users'));
        add_action('wp_ajax_cleanup_fake_users_enhanced', array($this, 'ajax_cleanup_fake_users'));
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
            
            <!-- Scan Section -->
            <div class="card">
                <h2>Step 1: Scan for Fake Users</h2>
                <p>Identifies users matching the fake user pattern with no orders or player data.</p>
                
                <div class="scan-options">
                    <label>Batch size: <select id="scan-batch-size">
                        <option value="250">250 (Conservative)</option>
                        <option value="500" selected>500 (Recommended)</option>
                        <option value="1000">1000 (Fast)</option>
                    </select></label>
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
            
            <!-- Results Section -->
            <div class="card" id="results-section" style="display: none;">
                <h2>Scan Results</h2>
                <div id="scan-summary"></div>
                <div id="sample-users"></div>
            </div>
            
            <!-- Cleanup Section -->
            <div class="card" id="cleanup-section" style="display: none;">
                <h2>Step 2: Review and Cleanup</h2>
                <p><strong>Warning:</strong> This action cannot be undone.</p>
                
                <div class="cleanup-options">
                    <label><input type="checkbox" id="dry-run" checked> Dry Run (Log only, no deletion)</label>
                    <label>Cleanup batch size: <select id="cleanup-batch-size">
                        <option value="50">50 (Safe)</option>
                        <option value="100" selected>100 (Recommended)</option>
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
        .scan-options, .cleanup-options { 
            margin: 15px 0; 
            display: flex; 
            gap: 20px; 
            flex-wrap: wrap;
        }
        .scan-options label, .cleanup-options label { 
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
        .sample-users {
            max-height: 200px;
            overflow-y: auto;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .user-item {
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
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            let scanInProgress = false;
            let cleanupInProgress = false;
            
            $('#scan-users').click(function() {
                if (!scanInProgress) {
                    startScan();
                }
            });
            
            $('#cleanup-users').click(function() {
                if (!cleanupInProgress) {
                    const dryRun = $('#dry-run').is(':checked');
                    const confirmMsg = dryRun ? 
                        'Start dry run cleanup? No users will be deleted.' : 
                        'Are you sure you want to delete these users? This action cannot be undone!';
                    
                    if (confirm(confirmMsg)) {
                        startCleanup();
                    }
                }
            });
            
            function startScan() {
                scanInProgress = true;
                $('#scan-users').prop('disabled', true).text('Starting scan...');
                $('#scan-progress-container').show();
                $('#results-section, #cleanup-section').hide();
                
                // Show immediate feedback
                updateScanProgress({
                    percent: 0,
                    processed: 0,
                    fake_found: 0,
                    memory_mb: 0
                });
                
                const batchSize = parseInt($('#scan-batch-size').val());
                
                // Start real-time batch processing
                processScanBatch(0, batchSize);
            }
            
            function processScanBatch(offset, batchSize) {
                // Update button text to show current batch
                if (offset === 0) {
                    $('#scan-users').text('Scanning users...');
                } else {
                    $('#scan-users').text(`Processing batch ${Math.floor(offset/batchSize) + 1}...`);
                }
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'scan_fake_users_enhanced',
                        nonce: '<?php echo wp_create_nonce('fake_user_cleanup_enhanced'); ?>',
                        batch_size: batchSize,
                        offset: offset
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update progress immediately
                            updateScanProgress(response.data.progress);
                            
                            if (response.data.completed) {
                                // Scan complete
                                $('#scan-users').text('Scan completed!');
                                completeScan(response.data.results);
                            } else {
                                // Process next batch
                                setTimeout(function() {
                                    processScanBatch(response.data.next_offset, batchSize);
                                }, 100);
                            }
                        } else {
                            alert('Error during scan: ' + response.data.message);
                            resetScanUI();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        console.error('Response:', xhr.responseText);
                        alert('Network error during scan. Check console and debug log.');
                        resetScanUI();
                    }
                });
            }
            
            function updateScanProgress(data) {
                const percent = data.percent || 0;
                $('#scan-progress-fill').css('width', percent + '%');
                $('#scan-progress-text').text(`${percent.toFixed(1)}% complete`);
                
                if (data.processed !== undefined) {
                    $('#scan-stats').text(`Processed: ${data.processed} | Fake found: ${data.fake_found || 0} | Memory: ${data.memory_mb || 0}MB`);
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
                    let sampleHtml = '<h4>Sample Users (first 10):</h4><div class="sample-users">';
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
            }
            
            function startCleanup() {
                cleanupInProgress = true;
                const dryRun = $('#dry-run').is(':checked');
                
                $('#cleanup-users').prop('disabled', true).text(dryRun ? 'Running Dry Run...' : 'Deleting...');
                $('#cleanup-progress-container').show();
                
                const batchSize = parseInt($('#cleanup-batch-size').val());
                
                // Start real-time batch processing
                processCleanupBatch(0, batchSize, dryRun);
            }
            
            function processCleanupBatch(offset, batchSize, dryRun) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cleanup_fake_users_enhanced',
                        nonce: '<?php echo wp_create_nonce('fake_user_cleanup_enhanced'); ?>',
                        batch_size: batchSize,
                        dry_run: dryRun ? 1 : 0,
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
                                // Process next batch
                                setTimeout(function() {
                                    processCleanupBatch(response.data.next_offset, batchSize, dryRun);
                                }, dryRun ? 50 : 200);
                            }
                        } else {
                            alert('Error during cleanup: ' + response.data.message);
                            resetCleanupUI();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        alert('Network error during cleanup. Check console.');
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
    
    public function ajax_scan_fake_users() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        // Increase limits for large operations
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 500;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $is_initial = $offset === 0;
        
        $start_time = microtime(true);
        $this->log_message('=== Starting Scan Batch ===');
        $this->log_message("Batch size: {$batch_size}, Offset: {$offset}");
        
        global $wpdb;
        
        // Get total count on first request
        if ($is_initial) {
            $total_query = "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email REGEXP '^[a-z]{8}[0-9]{2}@(gmail|outlook|yahoo|hotmail)\\.com$'";
            $total_users = $wpdb->get_var($total_query);
            $this->log_message("Found {$total_users} users matching email pattern");
            
            if ($total_users == 0) {
                wp_send_json_success(array(
                    'completed' => true,
                    'results' => array(
                        'total_scanned' => 0,
                        'pattern_matches' => 0,
                        'fake_users_count' => 0,
                        'safe_to_delete' => 0,
                        'sample_users' => array()
                    )
                ));
                return;
            }
            
            // Initialize scan data
            delete_transient($this->transient_key);
            delete_transient($this->transient_key . '_scan_data');
            
            set_transient($this->transient_key . '_total', $total_users, HOUR_IN_SECONDS);
            set_transient($this->transient_key . '_scan_data', array(
                'fake_users' => array(),
                'fake_ids' => array(),
                'safe_to_delete_count' => 0,
                'processed' => 0
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
        
        // Process current batch
        $query = "SELECT ID, user_email, user_registered FROM {$wpdb->users} 
                  WHERE user_email REGEXP '^[a-z]{8}[0-9]{2}@(gmail|outlook|yahoo|hotmail)\\.com$' 
                  LIMIT %d OFFSET %d";
        
        $users = $wpdb->get_results($wpdb->prepare($query, $batch_size, $offset));
        $this->log_message("Processing batch: " . count($users) . " users from offset {$offset}");
        
        $batch_fake_count = 0;
        $batch_safe_count = 0;
        
        foreach ($users as $user) {
            $validation = $this->comprehensive_user_validation($user->ID, $user->user_email);
            
            if ($validation['is_fake']) {
                $scan_data['fake_users'][] = array(
                    'ID' => $user->ID,
                    'user_email' => $user->user_email,
                    'user_registered' => $user->user_registered,
                    'safe_to_delete' => $validation['safe_to_delete']
                );
                $scan_data['fake_ids'][] = $user->ID;
                $batch_fake_count++;
                
                if ($validation['safe_to_delete']) {
                    $scan_data['safe_to_delete_count']++;
                    $batch_safe_count++;
                }
            }
            
            $scan_data['processed']++;
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
        
        if ($completed) {
            // Store final results
            set_transient($this->transient_key, $scan_data['fake_ids'], HOUR_IN_SECONDS);
            
            $results = array(
                'total_scanned' => $scan_data['processed'],
                'pattern_matches' => $total_users,
                'fake_users_count' => count($scan_data['fake_users']),
                'safe_to_delete' => $scan_data['safe_to_delete_count'],
                'sample_users' => array_slice($scan_data['fake_users'], 0, 10)
            );
            
            $this->log_message("Scan complete! Found " . count($scan_data['fake_users']) . " fake users (" . $scan_data['safe_to_delete_count'] . " safe to delete)");
            
            // Clean up temporary data
            delete_transient($this->transient_key . '_total');
            delete_transient($this->transient_key . '_scan_data');
            
            wp_send_json_success(array(
                'completed' => true,
                'results' => $results,
                'progress' => array(
                    'percent' => 100,
                    'processed' => $scan_data['processed'],
                    'fake_found' => count($scan_data['fake_users']),
                    'memory_mb' => $memory_mb
                )
            ));
        } else {
            // Return progress for next batch
            wp_send_json_success(array(
                'completed' => false,
                'next_offset' => $next_offset,
                'progress' => array(
                    'percent' => $percent,
                    'processed' => $scan_data['processed'],
                    'fake_found' => count($scan_data['fake_users']),
                    'memory_mb' => $memory_mb
                )
            ));
        }
    }
    
    public function ajax_cleanup_fake_users() {
        check_ajax_referer('fake_user_cleanup_enhanced', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        $fake_ids = get_transient($this->transient_key);
        if (empty($fake_ids)) {
            wp_send_json_error(array('message' => 'No scan results found. Please run scan first.'));
        }
        
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 100;
        $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] == 1;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $is_initial = $offset === 0;
        
        $start_time = microtime(true);
        
        if ($is_initial) {
            $this->log_message('=== Starting Cleanup ' . ($dry_run ? '(Dry Run)' : '') . ' ===');
            $this->log_message("Total users to process: " . count($fake_ids));
            
            // Initialize cleanup data
            set_transient($this->transient_key . '_cleanup_data', array(
                'processed' => 0,
                'deleted' => 0,
                'skipped' => 0,
                'errors' => 0
            ), HOUR_IN_SECONDS);
        }
        
        // Get current cleanup data
        $cleanup_data = get_transient($this->transient_key . '_cleanup_data');
        if (!$cleanup_data) {
            wp_send_json_error(array('message' => 'Cleanup session expired. Please restart.'));
            return;
        }
        
        $total_users = count($fake_ids);
        $batch = array_slice($fake_ids, $offset, $batch_size);
        
        $this->log_message("Processing cleanup batch: " . count($batch) . " users from offset {$offset}");
        
        $batch_deleted = 0;
        $batch_skipped = 0;
        $batch_errors = 0;
        
        foreach ($batch as $user_id) {
            $user = get_user_by('id', $user_id);
            if (!$user) {
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
                // Final safety check
                if ($this->final_safety_check($user_id)) {
                    if (wp_delete_user($user_id)) {
                        $batch_deleted++;
                        $cleanup_data['deleted']++;
                        $this->log_message("Deleted user ID: {$user_id}, Email: {$user->user_email}");
                    } else {
                        $batch_errors++;
                        $cleanup_data['errors']++;
                        $this->log_message("Failed to delete user ID: {$user_id}, Email: {$user->user_email}", 'ERROR');
                    }
                } else {
                    $batch_skipped++;
                    $cleanup_data['skipped']++;
                    $this->log_message("Final safety check failed for user ID: {$user_id}, Email: {$user->user_email}", 'WARNING');
                }
            }
            
            $cleanup_data['processed']++;
        }
        
        // Update cleanup data
        set_transient($this->transient_key . '_cleanup_data', $cleanup_data, HOUR_IN_SECONDS);
        
        $percent = ($cleanup_data['processed'] / $total_users) * 100;
        $time_elapsed = round(microtime(true) - $start_time, 2);
        
        $this->log_message("Batch complete: Deleted: {$batch_deleted}, Skipped: {$batch_skipped}, Errors: {$batch_errors}, Time: {$time_elapsed}s");
        
        // Check if cleanup is complete
        $next_offset = $offset + $batch_size;
        $completed = $next_offset >= $total_users;
        
        if ($completed) {
            $this->log_message("Cleanup complete! Processed: {$cleanup_data['processed']}, Deleted: {$cleanup_data['deleted']}, Skipped: {$cleanup_data['skipped']}, Errors: {$cleanup_data['errors']}");
            
            // Clean up transients
            delete_transient($this->transient_key);
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
    
    private function comprehensive_user_validation($user_id, $email) {
        $validation = array(
            'user_id' => $user_id,
            'email' => $email,
            'is_fake' => false,
            'safe_to_delete' => false,
            'checks' => array()
        );
        
        // Email pattern check
        $pattern = '/^[a-z]{8}\d{2}@(gmail\.com|outlook\.com|yahoo\.com|hotmail\.com)$/';
        $validation['checks']['email_pattern'] = array(
            'passed' => (bool) preg_match($pattern, $email),
            'description' => 'Email matches fake user pattern'
        );
        
        // Registration date check (incident timeframe)
        $user = get_user_by('id', $user_id);
        $reg_date = strtotime($user->user_registered);
        $incident_start = strtotime('2025-07-01');
        $incident_end = strtotime('2025-08-25');
        $validation['checks']['registration_date'] = array(
            'passed' => ($reg_date >= $incident_start && $reg_date <= $incident_end),
            'description' => 'Registered during incident timeframe (July-August 2025)',
            'value' => $user->user_registered
        );
        
        // WooCommerce orders check
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'limit' => 1,
            'return' => 'ids'
        ));
        $validation['checks']['no_orders'] = array(
            'passed' => empty($orders),
            'description' => 'No WooCommerce orders',
            'value' => count($orders) . ' orders found'
        );
        
        // InterSoccer players metadata check
        $player_data = get_user_meta($user_id, 'intersoccer_players', true);
        $validation['checks']['no_player_data'] = array(
            'passed' => empty($player_data),
            'description' => 'No intersoccer_players metadata',
            'value' => empty($player_data) ? 'No player data' : 'Has player data: ' . (is_array($player_data) ? count($player_data) . ' players' : 'Invalid format')
        );
        
        // Admin capabilities check
        $validation['checks']['not_admin'] = array(
            'passed' => !user_can($user_id, 'manage_options'),
            'description' => 'Not an administrator'
        );
        
        // Posts/content check
        $post_count = count_user_posts($user_id);
        $validation['checks']['no_posts'] = array(
            'passed' => $post_count === 0,
            'description' => 'No posts or content',
            'value' => $post_count . ' posts'
        );
        
        // Comments check
        $comment_count = get_comments(array('user_id' => $user_id, 'count' => true));
        $validation['checks']['no_comments'] = array(
            'passed' => $comment_count === 0,
            'description' => 'No comments',
            'value' => $comment_count . ' comments'
        );
        
        // Determine if user is fake and safe to delete
        $required_checks = array('email_pattern', 'registration_date', 'no_orders', 'no_player_data');
        $safety_checks = array('not_admin', 'no_posts', 'no_comments');
        
        $validation['is_fake'] = true;
        foreach ($required_checks as $check) {
            if (!$validation['checks'][$check]['passed']) {
                $validation['is_fake'] = false;
                break;
            }
        }
        
        $validation['safe_to_delete'] = $validation['is_fake'];
        foreach ($safety_checks as $check) {
            if (!$validation['checks'][$check]['passed']) {
                $validation['safe_to_delete'] = false;
                break;
            }
        }
        
        return $validation;
    }
    
    public function final_safety_check($user_id) {
        // Extra safety check before actual deletion
        $user = get_user_by('id', $user_id);
        if (!$user) return false;
        
        // Check if user has any administrative capabilities beyond basic customer/subscriber
        if (user_can($user_id, 'manage_options') || 
            user_can($user_id, 'edit_users') || 
            user_can($user_id, 'delete_users') ||
            user_can($user_id, 'edit_posts') ||
            user_can($user_id, 'publish_posts')) {
            $this->log_message("SAFETY BLOCK: User {$user_id} has administrative capabilities", 'ERROR');
            return false;
        }
        
        // Check for any WooCommerce orders (double-check)
        $orders = wc_get_orders(array(
            'customer_id' => $user_id,
            'limit' => 1,
            'return' => 'ids'
        ));
        if (!empty($orders)) {
            $this->log_message("SAFETY BLOCK: User {$user_id} has WooCommerce orders", 'ERROR');
            return false;
        }
        
        // Check for intersoccer_players metadata (double-check)
        $player_data = get_user_meta($user_id, 'intersoccer_players', true);
        if (!empty($player_data)) {
            $this->log_message("SAFETY BLOCK: User {$user_id} has intersoccer_players data", 'ERROR');
            return false;
        }
        
        // Check for any posts or comments
        $post_count = count_user_posts($user_id);
        if ($post_count > 0) {
            $this->log_message("SAFETY BLOCK: User {$user_id} has {$post_count} posts", 'ERROR');
            return false;
        }
        
        $comment_count = get_comments(array('user_id' => $user_id, 'count' => true));
        if ($comment_count > 0) {
            $this->log_message("SAFETY BLOCK: User {$user_id} has {$comment_count} comments", 'ERROR');
            return false;
        }
        
        // Check registration date is within incident window
        $reg_date = strtotime($user->user_registered);
        $incident_start = strtotime('2025-07-01');
        $incident_end = strtotime('2025-08-25');
        if ($reg_date < $incident_start || $reg_date > $incident_end) {
            $this->log_message("SAFETY BLOCK: User {$user_id} registered outside incident window ({$user->user_registered})", 'ERROR');
            return false;
        }
        
        // Email pattern check (final verification)
        $pattern = '/^[a-z]{8}\d{2}@(gmail\.com|outlook\.com|yahoo\.com|hotmail\.com)$/';
        if (!preg_match($pattern, $user->user_email)) {
            $this->log_message("SAFETY BLOCK: User {$user_id} doesn't match fake email pattern", 'ERROR');
            return false;
        }
        
        return true;
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
new InterSoccer_Enhanced_Fake_User_Cleanup();
?>