#!/usr/bin/env python3
"""
Build script to create a standalone phpinfo-insight-viewer.php file
Downloads Tailwind CSS and Alpine.js from CDN and embeds them inline
"""

import os
import sys
import re
from datetime import datetime
from urllib.request import urlopen, Request
from urllib.error import URLError

def download_file(url, description):
    """Download a file from URL"""
    print(f"Downloading {description}...")
    try:
        req = Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urlopen(req, timeout=30) as response:
            content = response.read()
            print(f"✓ Downloaded {description} ({len(content):,} bytes)")
            # Always decode as UTF-8 for JavaScript files
            if description.endswith('.js') or 'tailwind' in description.lower() or 'alpine' in description.lower():
                return content.decode('utf-8')
            return content
    except URLError as e:
        print(f"✗ Failed to download {description}: {e}")
        return None

def main():
    print("Building standalone phpinfo-insight-viewer.php...\n")

    # URLs
    tailwind_js_url = 'https://cdn.tailwindcss.com'
    alpine_js_url = 'https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js'

    # Read source file
    source_file = os.path.join(os.path.dirname(__file__), 'src', 'phpinfo-insight-dashboard.php')
    if not os.path.exists(source_file):
        print(f"✗ Error: Source file not found: {source_file}")
        sys.exit(1)

    with open(source_file, 'r', encoding='utf-8') as f:
        content = f.read()

    # Download Tailwind CSS (v4 is JavaScript-based)
    tailwind_js = download_file(tailwind_js_url, 'Tailwind CSS')
    if tailwind_js is None:
        print("✗ Error: Failed to download Tailwind CSS")
        sys.exit(1)

    # Download Alpine.js
    alpine_js = download_file(alpine_js_url, 'Alpine.js')
    if alpine_js is None:
        print("✗ Error: Failed to download Alpine.js")
        sys.exit(1)

    # Replace Alpine.js CDN script - move it to end of body (after phpinfoViewer function)
    alpine_pattern = r'<script[^>]*src=["\']https://unpkg\.com/alpinejs@[^"\']*["\'][^>]*defer[^>]*></script>'
    # Remove Alpine.js from head first (and any associated comments)
    content = re.sub(alpine_pattern, '', content, flags=re.IGNORECASE)
    # Remove leftover Alpine comment
    content = re.sub(r'<!--\s*Alpine\s*-->', '', content, flags=re.IGNORECASE)
    # Add Alpine.js at the end of body, before </body>
    alpine_replacement = f'\n<script>\n{alpine_js}\n</script>\n'
    content = re.sub(r'</body>', lambda m: alpine_replacement + '</body>', content, flags=re.IGNORECASE)

    # Replace Tailwind CDN script with inline version (Tailwind v4 is JavaScript)
    tailwind_pattern = r'<script[^>]*src=["\']https://cdn\.tailwindcss\.com[^"\']*["\'][^>]*></script>'
    tailwind_js_str = tailwind_js.decode("utf-8") if isinstance(tailwind_js, bytes) else tailwind_js
    tailwind_replacement = f'<script>\n{tailwind_js_str}\n</script>'
    content = re.sub(tailwind_pattern, lambda m: tailwind_replacement, content, flags=re.IGNORECASE)

    # Keep Tailwind config script (it's needed for dark mode)
    # The config script is already in the source file, so we don't need to remove it

    # Add build info comment
    build_info = f"""<!--
 * Built: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}
 * Source: phpinfo-insight-dashboard.php
 * Alpine.js: Embedded inline
 * Tailwind CSS: Embedded inline (v4 JavaScript)
 -->
"""

    # Insert build info before DOCTYPE or html tag
    match = re.search(r'^(.*?)(<!DOCTYPE|<html)', content, re.IGNORECASE | re.DOTALL)
    if match:
        content = match.group(1) + build_info + match.group(2) + content[len(match.group(0)):]

    # Write output file
    output_dir = os.path.join(os.path.dirname(__file__), 'build')
    os.makedirs(output_dir, exist_ok=True)
    output_file = os.path.join(output_dir, 'phpinfo-insight-dashboard.php')
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(content)

    file_size = os.path.getsize(output_file)
    print(f"\n✓ Built successfully: {output_file}")
    print(f"  File size: {file_size:,} bytes")
    print("\n✓ All dependencies embedded inline - file is fully standalone!")
    print("\nTo create a release, run: python3 create-release.py")

if __name__ == '__main__':
    main()

