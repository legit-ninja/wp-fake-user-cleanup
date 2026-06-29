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
                if (response.success && !response.data.incomplete && response.data.has_results) {
                    $('#results-section').show();
                    $('#cleanup-section').show();
                    loadExistingScanResults();
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
                confirmMsg += '\n\nWARNING: Force cleanup is enabled - activity meta checks will be bypassed. Users with orders or authored content are still protected.';
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
        if (confirm('Reset cleanup progress? Scan results will be kept; you can start cleanup again.')) {
            $.ajax({
                url: window.intersoccerCleanup.ajaxurl,
                type: 'POST',
                data: {
                    action: 'reset_cleanup',
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
                        const delayMs = parseInt($('#scan-batch-delay-ms').val(), 10)
                            || (window.intersoccerCleanup.defaults && window.intersoccerCleanup.defaults.scanBatchDelayMs)
                            || 2000;
                        setTimeout(function() {
                            processScanBatch(batchSize, false);
                        }, delayMs);
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
                is_new_cleanup: isNewCleanup ? 1 : 0,
                delay_after_delete_ms: parseInt($('#cleanup-delay-ms').val(), 10) || 0,
                activity_grace_months: parseInt($('#activity-grace-months').val(), 10)
                    || (window.intersoccerCleanup.defaults && window.intersoccerCleanup.defaults.activityGraceMonths)
                    || 6
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
                        const delayMs = parseInt($('#cleanup-batch-delay-ms').val(), 10)
                            || (window.intersoccerCleanup.defaults && window.intersoccerCleanup.defaults.cleanupBatchDelayMs)
                            || 2000;
                        setTimeout(function() {
                            processCleanupBatch(batchSize, false, dryRun, forceCleanup);
                        }, delayMs);
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
