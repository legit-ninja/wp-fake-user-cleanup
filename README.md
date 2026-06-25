Fake User Cleanup Plugin
========================

Enterprise-grade tooling for identifying and removing large volumes of bot-created WordPress accounts. The plugin ships with a two-step workflow (scan + cleanup), resilient resume/retry support, operator safety nets, and full CLI automation.

---

## Feature Highlights

- **Adaptive detection heuristics**
  - Randomised email/login pattern matching
  - Disposable-domain blacklist
  - Sparse-profile scoring (missing names, limited usermeta)
  - Missing `intersoccer_players` usermeta (no players on profile)
  - Registration-burst detection (per-second cohort analysis)
  - Activity metadata checks (last login, WooCommerce usage, authored content)
  - Per-user score and reason trail stored in the temp table for audits

- **High-leverage performance improvements**
  - ID-based pagination (no `OFFSET` re-scan penalties)
  - Batched usermeta prefetch and cohort map precomputation during scan (few queries per batch instead of per-user)
  - Bulk INSERT for detected fake users
  - Schema version gate (no per-batch `SHOW TABLES` introspection)
  - Configurable inter-batch delays (default 2000ms) to reduce shared-hosting load
  - Durable session state and resumable batches (via AJAX or CLI)
  - Structured JSON logging (`wp-content/intersoccer-cleanup-logs/intersoccer-cleanup-enhanced.log`; directory protected by `.htaccess`)
  - WP-CLI commands for long-running scans/cleanup

- **Safety-first cleanup**
  - Pre-delete verification of orders, authored content, and activity
  - Automatic review queue (`needs_review` bookkeeping) instead of risky deletes
  - One-click CSV export for manual audit before retrying cleanup

---

## Installation & Setup

1. **Install dependencies (optional for deployment)**  
   ```bash
   composer install
   ```
   PHPUnit is required only if you run the test suite locally. Deployment script will attempt to install dev dependencies automatically if `vendor/bin/phpunit` is missing and Composer is available.

2. **Activate the plugin**  
   Activation ensures the necessary tables exist with all supporting columns.

3. **Permissions**  
   All routes are constrained to administrators (`manage_options`) and require a nonce (`fake_user_cleanup_enhanced`).

---

## Admin Workflow

1. **Configure date range**  
   Choose the incident window (start & end dates) and click **Validate Date Range** to review user counts and pattern matches.

2. **Scan**  
   - Default batch size is **50** with a **2000ms** inter-batch delay (shared-hosting safe).  
   - Select batch size, inter-batch delay, and optional debug flags.  
   - Click **Start Scan** to launch the AJAX-driven batching process.  
   - Resume buttons appear automatically if a scan is interrupted.  
   - Detailed reasons, scores, and top offenders are shown in the results section.  
   - **Detailed logging** is off by default; enable only when troubleshooting.

3. **Cleanup**  
   - Cleanup runs in **batches** (N users per request); batch size, per-delete delay, and inter-batch delay are configurable.  
   - Run in **Dry Run** mode first to collect flags (no deletions).  
   - `Needs Review` counters surface users with suspicious activity — use the **Download Review CSV** button to inspect them.  
   - When satisfied, uncheck dry run and rerun cleanup.  
   - Smaller batch sizes (e.g. 25, default) and "Delay after each delete" reduce load on the database.  
   - `Force cleanup` bypasses safety net checks (use with caution).

4. **Logging**  
   - Batch-level JSON entries are written only when **Detailed logging** is enabled (or when using WP-CLI).  
   - Warnings and errors (failed deletes, review flags) are always logged.  
   - The log directory is created on first write and protected with `.htaccess` (Require all denied) to prevent direct web access.

---

## WP-CLI Commands

The plugin registers two commands when WP-CLI is available:

### Scan
```bash
wp intersoccer fake-users scan \
    --start-date=2025-07-01 \
    --end-date=2025-08-25 \
    --batch-size=250 \
    [--resume] \
    [--session=<session-id>] \
    [--debug-first]
```
- `--resume`: continue the most recent session (or specify `--session`).  
- `--debug-first`: dump detailed evaluation data for the earliest batch.

### Cleanup
```bash
wp intersoccer fake-users cleanup \
    [--batch-size=25] \
    [--delay-ms=0] \
    [--dry-run] \
    [--force] \
    [--resume] \
    [--session=<session-id>]
```
- `--batch-size`: users per batch (default 25 for low impact).  
- `--delay-ms`: milliseconds to wait after each delete (e.g. 25) to reduce DB contention; 0 = disabled.  
- `--dry-run`: run in verification mode only (default safety posture).  
- `--force`: delete even if safety flags (orders/posts/activity) exist.

Logs, scores, and review counts are emitted to the CLI output and JSON log simultaneously.

---

## Deployment

The plugin includes a `deploy.sh` script borrowed from the Product Variations toolkit.

1. Copy `deploy.sh` to the plugin root (already present).  
2. Create `deploy.local.sh` with environment-specific credentials:
   ```bash
   cat > deploy.local.sh <<'EOF'
   SERVER_USER="username"
   SERVER_HOST="example.com"
   SERVER_PATH="/var/www/html/wp-content/plugins/fake-user-cleanup"
   SSH_PORT="22"
   SSH_KEY="~/.ssh/id_rsa"
   EOF
   ```
3. Run:
   ```bash
   ./deploy.sh            # Always runs PHPUnit before rsync
   ./deploy.sh --dry-run  # Upload preview
   ./deploy.sh --test     # Deploy + Cypress UI automation (optional)
   ```

---

## Testing

### PHPUnit
```bash
vendor/bin/phpunit -c phpunit.xml
```
Coverage currently verifies:
- Table schema creation/upgrade paths and version-gated schema checks
- Session progress persistence utilities
- Batched scan evaluation context and bulk insert helpers
- Cohort transient prefetch and cleanup

### Manual validation
- Use the thousands of staged fake accounts to re-run scan/cleanup.  
- Confirm review counts rise for legitimate-looking users (orders/posts).  
- Download the CSV and spot-check flagged accounts before forced deletion.

---

## Filters

You can tune detection without editing the plugin:

- **`intersoccer_fake_cleanup_disposable_domains`**  
  (array) Override or extend the list of disposable email domains. Pass the full array to use; default is the built-in list.

- **`intersoccer_fake_cleanup_score_threshold`**  
  (int) Minimum score for a user to be classified as fake (default `70`). Increase to reduce false positives; decrease to catch more borderline cases.

- **`intersoccer_fake_cleanup_add_users_index`**  
  (bool) When `true`, activation attempts to add `intersoccer_registered_id (user_registered, ID)` on `wp_users`. Default `false`; many shared hosts restrict ALTER on core tables.

---

## Performance (shared hosting)

The scan phase prefetches usermeta and registration cohorts once per session, then evaluates each batch with a handful of queries instead of ~5 per user. Fake users are bulk-inserted per batch. Schema checks run on activation or version bump only.

**Recommended admin settings for shared hosting:**

| Setting | Recommended |
|---------|-------------|
| Scan batch size | 25–50 |
| Scan inter-batch delay | 2000–5000 ms |
| Cleanup batch size | 10–25 |
| Cleanup inter-batch delay | 2000 ms |
| Per-delete delay | 25–50 ms |
| Detailed logging | Off unless debugging |

**Off-peak alternative:** run via WP-CLI over SSH to avoid competing with web PHP workers:

```bash
wp intersoccer fake-users scan --start-date=2025-07-01 --end-date=2025-08-25 --batch-size=50
wp intersoccer fake-users cleanup --batch-size=10 --delay-ms=25 --dry-run
```

---

## Low-impact behaviour

Cleanup is designed to minimise database load: safety checks and audit writes are batched per run, and deletion runs in configurable batch sizes. Use a smaller batch size (e.g. 25) and optional per-delete delay (Admin: "Delay after each delete (ms)" or CLI `--delay-ms=25`) to spread load and avoid lock contention on large runs. Inter-batch delays (default 2000ms) pause between AJAX requests so normal site traffic is less affected.

---

## Roadmap & Hardening

- Extended heuristics (activity/IP clustering, allowlists)  
- Full runbook documentation (staging validation, backups, monitoring)  
- Alerting hooks (Slack/ELK) for long-running or failed sessions  
- Quarantine workflows (auto-role switch instead of immediate deletion)

---

Built for InterSoccer operations to keep registration databases clean while protecting real customers. Contributions welcome; please retain detailed logging and dry-run defaults in any future enhancements.

