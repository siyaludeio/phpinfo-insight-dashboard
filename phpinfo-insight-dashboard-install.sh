#!/bin/bash
#
# PHPINFO Insight Dashboard Installation Script
#
# This script can be installed via:
#   curl -fsSL http://siyalude.io/php/phpinfo-insight-dashboard-install.sh | sh
#
# Or downloaded and run directly:
#   curl -fsSL http://siyalude.io/php/phpinfo-insight-dashboard-install.sh -o install.sh
#   sh install.sh
#
# IMPORTANT: Before hosting, update GITHUB_REPO below with your actual repository!
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# GitHub repository (UPDATE THIS with your actual GitHub username/repo)
# Example: GITHUB_REPO="siyaludeio/phpinfo-insight-dashboard"
GITHUB_REPO="siyaludeio/phpinfo-insight-dashboard"
GITHUB_API="https://api.github.com/repos/${GITHUB_REPO}"

# Print colored messages
print_info() {
    printf "${BLUE}ℹ${NC} %s\n" "$1"
}

print_success() {
    printf "${GREEN}✓${NC} %s\n" "$1"
}

print_error() {
    printf "${RED}✗${NC} %s\n" "$1"
}

print_warning() {
    printf "${YELLOW}⚠${NC} %s\n" "$1"
}

print_header() {
    printf "\n"
    printf "==========================================\n"
    printf "  PHPINFO Insight Dashboard Installer\n"
    printf "==========================================\n"
    printf "\n"
}

# Check if required commands exist
check_requirements() {
    local missing=0
    
    if ! command -v curl &> /dev/null; then
        print_error "curl is required but not installed"
        missing=1
    fi
    
    # Check for SHA-256 command (shasum on macOS, sha256sum on Linux)
    if ! command -v shasum &> /dev/null && ! command -v sha256sum &> /dev/null; then
        if ! command -v openssl &> /dev/null; then
            print_error "sha256sum, shasum, or openssl is required but not installed"
            missing=1
        fi
    fi
    
    if [ $missing -eq 1 ]; then
        exit 1
    fi
}

# Generate SHA-256 hash of token
hash_token() {
    local token="$1"
    
    # Trim whitespace from token (like Python's .strip())
    token=$(printf "%s" "$token" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
    
    # Use printf instead of echo -n for better portability
    if command -v shasum &> /dev/null; then
        printf "%s" "$token" | shasum -a 256 | cut -d' ' -f1 | tr -d '\r\n'
    elif command -v sha256sum &> /dev/null; then
        printf "%s" "$token" | sha256sum | cut -d' ' -f1 | tr -d '\r\n'
    elif command -v openssl &> /dev/null; then
        printf "%s" "$token" | openssl dgst -sha256 -hex | cut -d' ' -f2 | tr -d '\r\n'
    else
        print_error "No SHA-256 hashing tool found"
        exit 1
    fi
}

# Get latest release from GitHub
get_latest_release() {
    # Check if repository is configured
    if [[ "$GITHUB_REPO" == *"your-username"* ]] || [[ "$GITHUB_REPO" == *"username"* ]]; then
        print_error "GitHub repository not configured!" >&2
        print_info "Please update GITHUB_REPO in the script with your actual repository" >&2
        print_info "Example: GITHUB_REPO=\"siyaludeio/phpinfo-insight-dashboard\"" >&2
        exit 1
    fi
    
    print_info "Fetching latest release information from ${GITHUB_REPO}..." >&2
    
    local release_url="${GITHUB_API}/releases/latest"
    local release_info
    local temp_file=$(mktemp)
    
    # Download release info to temp file for better error handling
    if ! curl -fsSL -H "Accept: application/vnd.github.v3+json" "$release_url" -o "$temp_file" 2>/dev/null; then
        print_error "Failed to fetch release information" >&2
        print_info "Make sure the repository exists and has releases" >&2
        rm -f "$temp_file"
        exit 1
    fi
    
    release_info=$(cat "$temp_file")
    rm -f "$temp_file"
    
    if [ -z "$release_info" ]; then
        print_error "Empty response from GitHub API" >&2
        exit 1
    fi
    
    # Extract tag name
    local tag_name=$(echo "$release_info" | grep -o '"tag_name": "[^"]*' | head -1 | cut -d'"' -f4 | tr -d '\r\n')
    
    if [ -z "$tag_name" ]; then
        print_error "Could not determine latest release tag" >&2
        exit 1
    fi
    
    # Extract download URL - look for the asset with phpinfo-insight-dashboard.php
    # The assets array contains objects with "browser_download_url" and "name" fields
    local download_url=""
    
    # Try to find the asset by name first (more reliable)
    # Look for the asset name, then get the browser_download_url from the same JSON object
    download_url=$(echo "$release_info" | grep -B 2 -A 10 '"name": "phpinfo-insight-dashboard\.php"' | grep '"browser_download_url"' | head -1 | grep -o 'https://[^"]*' | head -1)
    
    # Fallback: search for any browser_download_url containing the filename
    if [ -z "$download_url" ]; then
        download_url=$(echo "$release_info" | grep -o '"browser_download_url": "https://[^"]*phpinfo-insight-dashboard\.php[^"]*' | head -1 | cut -d'"' -f4)
    fi
    
    # Clean up the URL (remove any trailing whitespace or control characters)
    download_url=$(echo "$download_url" | tr -d '\r\n\t' | sed 's/[[:space:]]*$//' | sed 's/^[[:space:]]*//')
    
    if [ -z "$download_url" ]; then
        print_error "Could not find phpinfo-insight-dashboard.php in release assets" >&2
        print_info "Release tag: $tag_name" >&2
        print_info "Available assets in release:" >&2
        echo "$release_info" | grep -o '"name": "[^"]*' | cut -d'"' -f4 | head -5 | while read name; do
            printf "  - %s\n" "$name" >&2
        done
        exit 1
    fi
    
    # Output only the data (no print statements) to stdout
    echo "$tag_name|$download_url"
}

# Main installation function
main() {
    print_header
    
    # Check requirements
    print_info "Checking requirements..."
    check_requirements
    print_success "All requirements met"
    printf "\n"
    
    # Get token
    local token=""
    local token_confirm=""
    
    # Check for token in environment variable first
    if [ -n "$PHPINFO_TOKEN" ]; then
        token="$PHPINFO_TOKEN"
        # Trim whitespace from environment variable token
        token=$(printf "%s" "$token" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
        print_info "Using token from PHPINFO_TOKEN environment variable"
        printf "\n"
    else
        # Prompt for token (try to use /dev/tty for interactive input even when piped)
        print_info "Token Configuration"
        printf "Enter a secure token to protect your phpinfo dashboard.\n"
        printf "This token will be hashed and stored in piid.txt\n"
        printf "\n"
        
        # Read token (hidden input) - use /dev/tty if available
        while [ -z "$token" ]; do
            if [ -c /dev/tty ]; then
                printf "Enter token: "
                read -s token < /dev/tty
                printf "\n"
                # Trim whitespace (like Python's .strip())
                token=$(printf "%s" "$token" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
            else
                # Fallback: read from stdin (non-hidden)
                printf "Enter token (will be visible): "
                read token
                # Trim whitespace
                token=$(printf "%s" "$token" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
            fi
            
            if [ -z "$token" ]; then
                print_error "Token cannot be empty. Please try again."
            fi
        done
        
        # Confirm token
        if [ -c /dev/tty ]; then
            printf "Confirm token: "
            read -s token_confirm < /dev/tty
            printf "\n"
            # Trim whitespace
            token_confirm=$(printf "%s" "$token_confirm" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
        else
            printf "Confirm token (will be visible): "
            read token_confirm
            # Trim whitespace
            token_confirm=$(printf "%s" "$token_confirm" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
        fi
        
        if [ "$token" != "$token_confirm" ]; then
            print_error "Tokens do not match. Installation cancelled."
            exit 1
        fi
        
        printf "\n"
        print_success "Token confirmed"
    fi
    
    # Generate hash
    print_info "Generating token hash..."
    local token_hash=$(hash_token "$token")
    print_success "Token hash generated"
    printf "\n"
    
    # Get latest release (stderr goes to terminal, stdout is captured)
    local release_data=$(get_latest_release)
    local tag_name=$(echo "$release_data" | cut -d'|' -f1 | tr -d '\r\n\t ')
    local download_url=$(echo "$release_data" | cut -d'|' -f2 | tr -d '\r\n\t ')
    
    # Validate we got both values
    if [ -z "$tag_name" ] || [ -z "$download_url" ]; then
        print_error "Failed to parse release information"
        print_info "Release data: ${release_data:0:100}..."
        exit 1
    fi
    
    # Validate URL format
    if [[ ! "$download_url" =~ ^https:// ]]; then
        print_error "Invalid download URL format: $download_url"
        exit 1
    fi
    
    print_success "Found latest release: $tag_name"
    printf "\n"
    
    # Check if files already exist
    local overwrite="y"
    if [ -f "phpinfo-insight-dashboard.php" ]; then
        print_warning "phpinfo-insight-dashboard.php already exists"
        if [ -c /dev/tty ]; then
            printf "Overwrite? (y/N): "
            read overwrite < /dev/tty
        else
            overwrite="y"  # Auto-overwrite in non-interactive mode
        fi
    fi
    
    if [[ "$overwrite" =~ ^[Yy]$ ]] || [ ! -f "phpinfo-insight-dashboard.php" ]; then
        # Download the PHP file
        print_info "Downloading phpinfo-insight-dashboard.php..."
        
        # Use curl with proper flags and error handling
        # Remove -f flag temporarily to see the actual error
        if curl -SL --max-time 60 --fail -o "phpinfo-insight-dashboard.php" "$download_url" 2>/tmp/curl_error.log; then
            if [ -f "phpinfo-insight-dashboard.php" ]; then
                local file_size=$(stat -f%z "phpinfo-insight-dashboard.php" 2>/dev/null || stat -c%s "phpinfo-insight-dashboard.php" 2>/dev/null || echo "unknown")
                print_success "Downloaded phpinfo-insight-dashboard.php ($file_size bytes)"
                rm -f /tmp/curl_error.log
            else
                print_error "Download appeared to succeed but file was not created"
                exit 1
            fi
        else
            print_error "Failed to download phpinfo-insight-dashboard.php"
            if [ -f /tmp/curl_error.log ]; then
                local curl_error=$(cat /tmp/curl_error.log)
                print_info "Error details: $curl_error"
                rm -f /tmp/curl_error.log
            fi
            print_info "URL attempted: $download_url"
            exit 1
        fi
    else
        print_info "Skipping download of phpinfo-insight-dashboard.php"
    fi
    printf "\n"
    
    # Create or update piid.txt with hash
    local overwrite_hash="y"
    if [ -f "piid.txt" ]; then
        print_warning "piid.txt already exists"
        if [ -c /dev/tty ]; then
            printf "Overwrite with new token hash? (y/N): "
            read overwrite_hash < /dev/tty
        else
            overwrite_hash="y"  # Auto-overwrite in non-interactive mode
        fi
    fi
    
    if [[ "$overwrite_hash" =~ ^[Yy]$ ]] || [ ! -f "piid.txt" ]; then
        print_info "Creating/updating piid.txt..."
        # Write hash without trailing newline (like Python does)
        printf "%s" "$token_hash" > piid.txt
        chmod 600 piid.txt  # Restrict permissions
        print_success "Created/updated piid.txt with token hash"
    else
        print_info "Keeping existing piid.txt"
    fi
    printf "\n"
    
    # Final instructions
    print_success "Installation completed successfully!"
    printf "\n"
    printf "Files created:\n"
    if [ -f "phpinfo-insight-dashboard.php" ]; then
        printf "  ✓ phpinfo-insight-dashboard.php (main dashboard file)\n"
    fi
    if [ -f "piid.txt" ]; then
        printf "  ✓ piid.txt (token hash file)\n"
    fi
    printf "\n"
    print_warning "Keep your token secure - it cannot be recovered from the hash!"
    printf "\n"
    printf "To use the dashboard, access it via:\n"
    printf "  http://your-domain.com/phpinfo-insight-dashboard.php?token=YOUR_TOKEN\n"
    printf "\n"
    printf "Make sure to:\n"
    printf "  1. Place the file in your web-accessible directory\n"
    printf "  2. Keep piid.txt in the same directory as the PHP file\n"
    printf "  3. Use the exact token you entered during installation\n"
    printf "\n"
}

# Run main function
main

