#!/bin/bash

###############################################################################
# InterSoccer Fake User Cleanup - Deployment Script
###############################################################################
#
# This script deploys the plugin to the dev server and can optionally run
# additional test suites (PHPUnit always runs).
#
# Usage:
#   ./deploy.sh                 # Deploy to dev server (runs PHPUnit first)
#   ./deploy.sh --test          # Deploy and run optional Cypress E2E tests
#   ./deploy.sh --no-cache      # Deploy and clear server caches
#   ./deploy.sh --dry-run       # Show what would be uploaded
#
###############################################################################

# Exit on error
set -e

# Color output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
# IMPORTANT: Copy this file to deploy.local.sh and set your credentials there
# deploy.local.sh is in .gitignore and won't be committed

# Default configuration (override in deploy.local.sh)
SERVER_USER="your-username"
SERVER_HOST="intersoccer.legit.ninja"
SERVER_PATH="/path/to/wordpress/wp-content/plugins/fake-user-cleanup"
SSH_PORT="22"
SSH_KEY="~/.ssh/id_rsa"

# Load local configuration if it exists
if [ -f "deploy.local.sh" ]; then
    source deploy.local.sh
    echo -e "${GREEN}✓ Loaded local configuration${NC}"
fi

# Parse command line arguments
DRY_RUN=false
RUN_CYPRESS_TESTS=false
CLEAR_CACHE=false

while [[ $# -gt 0 ]]; do
    case $1 in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --test)
            RUN_CYPRESS_TESTS=true
            shift
            ;;
        --no-cache|--clear-cache)
            CLEAR_CACHE=true
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --dry-run        Show what would be uploaded without uploading"
            echo "  --test           Run Cypress E2E tests AFTER deployment"
            echo "  --clear-cache    Clear server caches after deployment"
            echo "  --help           Show this help message"
            echo ""
            echo "Note: PHPUnit tests ALWAYS run before deployment (cannot be skipped)"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            exit 1
            ;;
    esac
done

# Check if configuration is set
if [ "$SERVER_USER" = "your-username" ]; then
    echo -e "${RED}✗ Configuration not set!${NC}"
    echo ""
    echo "Please create a deploy.local.sh file with your server credentials:"
    echo ""
    echo "cat > deploy.local.sh << 'EOF'"
    echo "SERVER_USER=\"your-ssh-username\""
    echo "SERVER_HOST=\"intersoccer.legit.ninja\""
    echo "SERVER_PATH=\"/var/www/html/wp-content/plugins/fake-user-cleanup\""
    echo "SSH_PORT=\"22\""
    echo "SSH_KEY=\"~/.ssh/id_rsa\""
    echo "EOF"
    echo ""
    exit 1
fi

###############################################################################
# Functions
###############################################################################

print_header() {
    echo ""
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo ""
}

run_phpunit_tests() {
    print_header "Running PHPUnit Tests"

    if [ ! -f "vendor/bin/phpunit" ]; then
        if command -v composer >/dev/null 2>&1; then
            echo -e "${YELLOW}⚠ PHPUnit not installed. Installing dev dependencies...${NC}"
            composer install --no-interaction --prefer-dist
        else
            echo -e "${RED}✗ PHPUnit not installed and composer not found. Install composer first.${NC}"
            return 1
        fi
    fi

    vendor/bin/phpunit -c phpunit.xml

    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ All PHPUnit tests passed${NC}"
        return 0
    else
        echo -e "${RED}✗ PHPUnit tests failed${NC}"
        return 1
    fi
}

run_cypress_tests() {
    print_header "Running Cypress E2E Tests"

    # Path to intersoccer-ui-tests repository (sibling directory)
    CYPRESS_REPO="../intersoccer-ui-tests"

    if [ ! -d "$CYPRESS_REPO" ]; then
        echo -e "${RED}✗ Cypress tests repository not found at: $CYPRESS_REPO${NC}"
        echo ""
        echo "Expected location: /home/jeremy-lee/projects/underdog/intersoccer/intersoccer-ui-tests"
        echo ""
        echo "Please ensure the intersoccer-ui-tests repository is cloned at the correct location."
        return 1
    fi

    echo "Running tests from: $CYPRESS_REPO"
    echo "Target server: https://${SERVER_HOST}"
    echo ""

    # Change to Cypress repo directory
    cd "$CYPRESS_REPO"

    # Check if Cypress is installed
    if [ ! -f "node_modules/.bin/cypress" ]; then
        echo -e "${YELLOW}⚠ Cypress not installed in ui-tests repo. Installing...${NC}"
        npm install
        if [ $? -ne 0 ]; then
            echo -e "${RED}✗ Failed to install Cypress dependencies${NC}"
            return 1
        fi
    fi

    echo "Running: npx cypress run --spec cypress/e2e/fake_user_cleanup.spec.js --env environment=dev"
    echo "Adjust the spec above to match the tests you want to execute."
    echo ""

    npx cypress run --spec cypress/e2e/fake_user_cleanup.spec.js --env environment=dev

    CYPRESS_EXIT_CODE=$?

    # Return to original directory
    cd - > /dev/null

    if [ $CYPRESS_EXIT_CODE -eq 0 ]; then
        echo ""
        echo -e "${GREEN}✓ All Cypress E2E tests passed${NC}"
        return 0
    else
        echo ""
        echo -e "${RED}✗ Cypress E2E tests failed${NC}"
        echo ""
        echo "Test failures found. Please review the Cypress output above."
        echo "Note: Code has already been deployed. You may need to fix and redeploy."
        return 1
    fi
}

deploy_to_server() {
    print_header "Deploying to Server"

    # Validate SERVER_PATH
    if [ -z "$SERVER_PATH" ]; then
        echo -e "${RED}✗ ERROR: SERVER_PATH is not set!${NC}"
        echo ""
        echo "Please set SERVER_PATH in deploy.local.sh to the FULL PATH of this specific plugin:"
        echo "  SERVER_PATH=\"/var/www/html/wp-content/plugins/fake-user-cleanup\""
        echo ""
        echo "⚠️  DO NOT use the generic plugins directory path - this would affect other plugins!"
        exit 1
    fi

    # Safety check: Ensure path ends with plugin name
    if [[ ! "$SERVER_PATH" =~ fake-user-cleanup/?$ ]]; then
        echo -e "${YELLOW}⚠️  WARNING: SERVER_PATH should end with 'fake-user-cleanup'${NC}"
        echo "Current path: $SERVER_PATH"
        echo ""
        echo "Expected format: /path/to/wp-content/plugins/fake-user-cleanup"
        echo ""
        read -p "Continue anyway? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            echo "Deployment cancelled."
            exit 1
        fi
    fi

    echo -e "Target: ${GREEN}${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}${NC}"
    echo ""

    # Compile translation files before deployment
    if [ -f "scripts/compile-translations.sh" ]; then
        echo -e "${BLUE}Compiling translation files...${NC}"
        bash scripts/compile-translations.sh
        if [ $? -ne 0 ]; then
            echo -e "${RED}❌ Translation compilation failed!${NC}"
            exit 1
        fi
        echo ""
    fi

    # Build rsync command WITHOUT --delete flag
    # Using --delete is dangerous - could delete other plugins if path is wrong!
    RSYNC_CMD="rsync -avz"

    # Add dry-run flag if requested
    if [ "$DRY_RUN" = true ]; then
        RSYNC_CMD="$RSYNC_CMD --dry-run"
        echo -e "${YELLOW}DRY RUN MODE - No files will be uploaded${NC}"
        echo ""
    fi

    # Add SSH options
    RSYNC_CMD="$RSYNC_CMD -e 'ssh -p ${SSH_PORT} -i ${SSH_KEY}'"

    # Important: Include rules must come BEFORE exclude rules in rsync
    # Include README.md before excluding other *.md files
    RSYNC_CMD="$RSYNC_CMD --include='README.md'"

    # Exclude files/directories
    RSYNC_CMD="$RSYNC_CMD \
        --exclude='.git' \
        --exclude='.gitignore' \
        --exclude='node_modules' \
        --exclude='vendor' \
        --exclude='tests' \
        --exclude='cypress' \
        --exclude='docs' \
        --exclude='.phpunit.result.cache' \
        --exclude='composer.json' \
        --exclude='composer.lock' \
        --exclude='package.json' \
        --exclude='package-lock.json' \
        --exclude='phpunit.xml' \
        --exclude='*.log' \
        --exclude='debug.log' \
        --exclude='*.sh' \
        --exclude='*.md' \
        --exclude='*.list' \
        --exclude='run-*.php' \
        --exclude='.DS_Store' \
        --exclude='*.swp' \
        --exclude='*~'"

    # Add source and destination
    RSYNC_CMD="$RSYNC_CMD ./ ${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}/"

    # Execute rsync
    echo "Uploading files..."
    eval $RSYNC_CMD

    if [ $? -eq 0 ]; then
        if [ "$DRY_RUN" = false ]; then
            echo ""
            echo -e "${GREEN}✓ Files uploaded successfully${NC}"
        fi
    else
        echo -e "${RED}✗ Upload failed${NC}"
        exit 1
    fi
}

clear_server_caches() {
    print_header "Clearing Server Caches"

    # Create a temporary PHP script to clear caches
    CLEAR_SCRIPT='<?php
// Clear PHP Opcache
if (function_exists("opcache_reset")) {
    opcache_reset();
    echo "✓ PHP Opcache cleared\n";
} else {
    echo "⚠ PHP Opcache not available\n";
}

// Clear WooCommerce transients
if (function_exists("wc_delete_product_transients")) {
    wc_delete_product_transients(0);
    echo "✓ WooCommerce transients cleared\n";
}

// Clear WordPress object cache
if (function_exists("wp_cache_flush")) {
    wp_cache_flush();
    echo "✓ WordPress object cache cleared\n";
}

echo "\nCaches cleared successfully!\n";
unlink(__FILE__);
?>'

    # Upload and execute the script
    echo "$CLEAR_SCRIPT" | ssh -p ${SSH_PORT} -i ${SSH_KEY} ${SERVER_USER}@${SERVER_HOST} "cat > ${SERVER_PATH}/clear-cache-temp.php"

    echo ""
    echo "Executing cache clear script on server..."
    ssh -p ${SSH_PORT} -i ${SSH_KEY} ${SERVER_USER}@${SERVER_HOST} "cd ${SERVER_PATH} && php clear-cache-temp.php"

    echo ""
    echo -e "${GREEN}✓ Server caches cleared${NC}"
}

###############################################################################
# Main Script
###############################################################################

print_header "InterSoccer Fake User Cleanup Deployment"

echo "Configuration:"
echo "  Server: ${SERVER_USER}@${SERVER_HOST}"
echo "  Path: ${SERVER_PATH}"
echo "  SSH Port: ${SSH_PORT}"
if [ "$RUN_CYPRESS_TESTS" = true ]; then
    echo "  Cypress Tests: Will run AFTER deployment"
fi
echo ""

# ALWAYS run PHPUnit tests (even in dry-run mode)
run_phpunit_tests
if [ $? -ne 0 ]; then
    echo ""
    echo -e "${RED}✗ PHPUnit tests failed. Deployment BLOCKED.${NC}"
    echo ""
    echo "Fix the failing tests before deploying:"
    echo "  ./vendor/bin/phpunit -c phpunit.xml --testdox"
    echo ""
    exit 1
fi
echo ""

# Deploy to server (skip if dry-run)
if [ "$DRY_RUN" = false ]; then
    deploy_to_server
else
    echo -e "${YELLOW}DRY RUN MODE - Skipping deployment${NC}"
    echo -e "${GREEN}✓ PHPUnit tests passed. Ready to deploy.${NC}"
    echo ""
    exit 0
fi

# Clear caches if requested or if running Cypress tests
if [ "$DRY_RUN" = false ]; then
    if [ "$CLEAR_CACHE" = true ] || [ "$RUN_CYPRESS_TESTS" = true ]; then
        clear_server_caches
    fi
fi

# Run Cypress E2E tests if requested (AFTER deployment and cache clearing)
if [ "$RUN_CYPRESS_TESTS" = true ] && [ "$DRY_RUN" = false ]; then
    echo ""
    echo -e "${BLUE}Waiting 3 seconds for server to stabilize...${NC}"
    sleep 3
    echo ""

    run_cypress_tests
    CYPRESS_RESULT=$?

    if [ $CYPRESS_RESULT -ne 0 ]; then
        echo ""
        echo -e "${YELLOW}⚠ WARNING: Cypress tests failed but code is already deployed.${NC}"
        echo "You may need to fix issues and redeploy."
        echo ""
    fi
fi

# Success message
if [ "$DRY_RUN" = false ]; then
    print_header "Deployment Complete"
    echo -e "${GREEN}✓ Plugin successfully deployed to ${SERVER_HOST}${NC}"

    if [ "$RUN_CYPRESS_TESTS" = true ]; then
        if [ $CYPRESS_RESULT -eq 0 ]; then
            echo -e "${GREEN}✓ All Cypress E2E tests passed${NC}"
        else
            echo -e "${YELLOW}⚠ Deployment succeeded but some Cypress tests failed${NC}"
            echo "  Check test output above for details"
        fi
    fi

    echo ""
    echo "Next steps:"
    echo "  1. Clear browser cache and hard refresh (Ctrl+Shift+R)"
    echo "  2. Test the changes on: https://${SERVER_HOST}/wp-admin/"
    echo "  3. Check browser console for any errors"

    if [ "$RUN_CYPRESS_TESTS" = false ]; then
        echo ""
        echo "Tip: Run with --test flag to run Cypress E2E tests:"
        echo "  ./deploy.sh --test"
    fi
    echo ""
else
    echo ""
    echo -e "${YELLOW}DRY RUN completed. No files were uploaded.${NC}"
    echo "Run without --dry-run to actually deploy."
    echo ""
fi

