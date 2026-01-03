#!/usr/bin/env python3
"""
Release script to create git tags and GitHub releases
"""

import os
import sys
import re
import subprocess
from datetime import datetime

def get_version():
    """Get version from git or use timestamp"""
    try:
        result = subprocess.run(
            ['git', 'describe', '--tags', '--abbrev=0'],
            capture_output=True,
            text=True,
            check=False
        )
        if result.returncode == 0 and result.stdout.strip():
            tag = result.stdout.strip()
            # Extract version number and increment patch
            match = re.search(r'v?(\d+)\.(\d+)\.(\d+)', tag)
            if match:
                major, minor, patch = map(int, match.groups())
                return f"{major}.{minor}.{patch + 1}"
            return tag
    except:
        pass

    # Fallback to date-based version
    return datetime.now().strftime("%Y.%m.%d")

def create_git_tag(version):
    """Create a git tag"""
    tag_name = f"v{version}"
    try:
        # Check if tag already exists
        result = subprocess.run(
            ['git', 'rev-parse', '--verify', f'refs/tags/{tag_name}'],
            capture_output=True,
            check=False
        )
        if result.returncode == 0:
            print(f"✗ Tag {tag_name} already exists")
            return False

        # Create tag
        subprocess.run(['git', 'tag', '-a', tag_name, '-m', f'Release {tag_name}'], check=True)
        print(f"✓ Created git tag: {tag_name}")
        return True
    except subprocess.CalledProcessError as e:
        print(f"✗ Failed to create git tag: {e}")
        return False

def create_git_release(version, file_path):
    """Create a git release (GitHub)"""
    tag_name = f"v{version}"
    try:
        # Check if gh CLI is available
        subprocess.run(['gh', '--version'], capture_output=True, check=True)

        # Create release with file
        cmd = [
            'gh', 'release', 'create', tag_name,
            file_path,
            '--title', f'Release {tag_name}',
            '--notes', f'Standalone phpinfo insight viewer - {tag_name}'
        ]
        subprocess.run(cmd, check=True)
        print(f"✓ Created GitHub release: {tag_name}")
        return True
    except subprocess.CalledProcessError as e:
        print(f"✗ Failed to create GitHub release: {e}")
        print("  Make sure 'gh' CLI is installed and authenticated")
        return False
    except FileNotFoundError:
        print("✗ GitHub CLI ('gh') not found")
        print("  Install it from: https://cli.github.com/")
        return False

def main():
    print("Creating release...\n")

    # Check if build file exists
    build_file = os.path.join(os.path.dirname(__file__), 'build', 'phpinfo-insight-viewer.php')
    if not os.path.exists(build_file):
        print(f"✗ Error: Build file not found: {build_file}")
        print("  Please run build-standalone.py first to create the build file.")
        sys.exit(1)

    # Get version
    version = get_version()
    print(f"Proposed version: {version}")
    custom_version = input("Enter version (or press Enter to use proposed): ").strip()
    if custom_version:
        version = custom_version

    # Create git tag
    if create_git_tag(version):
        # Push tag
        push_tag = input(f"\nPush tag v{version} to remote? (y/N): ").strip().lower()
        if push_tag in ('y', 'yes'):
            try:
                subprocess.run(['git', 'push', 'origin', f'v{version}'], check=True)
                print(f"✓ Pushed tag v{version} to remote")
            except subprocess.CalledProcessError as e:
                print(f"✗ Failed to push tag: {e}")

        # Create GitHub release
        create_gh_release = input("\nCreate GitHub release? (y/N): ").strip().lower()
        if create_gh_release in ('y', 'yes'):
            create_git_release(version, build_file)
    else:
        print("Skipping release creation due to tag creation failure")

if __name__ == '__main__':
    main()

