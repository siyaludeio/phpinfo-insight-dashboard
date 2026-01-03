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

def delete_git_tag(tag_name, remote=False):
    """Delete a git tag (local and optionally remote)"""
    try:
        # Delete local tag
        result = subprocess.run(
            ['git', 'rev-parse', '--verify', f'refs/tags/{tag_name}'],
            capture_output=True,
            check=False
        )
        if result.returncode == 0:
            subprocess.run(['git', 'tag', '-d', tag_name], check=True)
            print(f"✓ Deleted local tag: {tag_name}")
        
        # Delete remote tag if requested
        if remote:
            try:
                subprocess.run(['git', 'push', 'origin', '--delete', tag_name], check=True)
                print(f"✓ Deleted remote tag: {tag_name}")
            except subprocess.CalledProcessError:
                print(f"⚠ Could not delete remote tag (may not exist): {tag_name}")
        
        return True
    except subprocess.CalledProcessError as e:
        print(f"✗ Failed to delete tag: {e}")
        return False

def create_git_tag(version, force=False):
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
            if force:
                # Delete existing tag
                delete_git_tag(tag_name, remote=False)
            else:
                print(f"⚠ Tag {tag_name} already exists")
                override = input(f"Delete and recreate tag {tag_name}? (y/N): ").strip().lower()
                if override in ('y', 'yes'):
                    delete_git_tag(tag_name, remote=False)
                else:
                    print(f"✗ Tag creation cancelled")
                    return False

        # Create tag
        subprocess.run(['git', 'tag', '-a', tag_name, '-m', f'Release {tag_name}'], check=True)
        print(f"✓ Created git tag: {tag_name}")
        return True
    except subprocess.CalledProcessError as e:
        print(f"✗ Failed to create git tag: {e}")
        return False

def delete_git_release(tag_name):
    """Delete a GitHub release"""
    try:
        # Check if gh CLI is available
        subprocess.run(['gh', '--version'], capture_output=True, check=True)
        
        # Delete release
        subprocess.run(['gh', 'release', 'delete', tag_name, '--yes'], check=True)
        print(f"✓ Deleted GitHub release: {tag_name}")
        return True
    except subprocess.CalledProcessError as e:
        # Release might not exist, which is fine
        print(f"⚠ Could not delete release (may not exist): {tag_name}")
        return False
    except FileNotFoundError:
        print("✗ GitHub CLI ('gh') not found")
        return False

def create_git_release(version, file_path, force=False):
    """Create a git release (GitHub)"""
    tag_name = f"v{version}"
    try:
        # Check if gh CLI is available
        subprocess.run(['gh', '--version'], capture_output=True, check=True)

        # Check if release already exists
        try:
            result = subprocess.run(
                ['gh', 'release', 'view', tag_name],
                capture_output=True,
                check=False
            )
            if result.returncode == 0:
                if force:
                    delete_git_release(tag_name)
                else:
                    print(f"⚠ GitHub release {tag_name} already exists")
                    override = input(f"Delete and recreate release {tag_name}? (y/N): ").strip().lower()
                    if override in ('y', 'yes'):
                        delete_git_release(tag_name)
                    else:
                        print(f"✗ Release creation cancelled")
                        return False
        except:
            pass  # Release doesn't exist, continue

        # Create release with file
        cmd = [
            'gh', 'release', 'create', tag_name,
            file_path,
            '--title', f'Release {tag_name}',
            '--notes', f'Standalone PHPINFO Insight Dashboard - {tag_name}'
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

def run_build_script():
    """Run build-standalone.py to create the build file"""
    build_script = os.path.join(os.path.dirname(__file__), 'build-standalone.py')
    if not os.path.exists(build_script):
        print(f"✗ Error: Build script not found: {build_script}")
        return False
    
    print("Running build-standalone.py...\n")
    try:
        result = subprocess.run([sys.executable, build_script], check=True)
        print()  # Add blank line after build output
        return True
    except subprocess.CalledProcessError as e:
        print(f"✗ Error: Build script failed: {e}")
        return False

def main():
    print("Creating release...\n")

    # Check if build file exists
    build_file = os.path.join(os.path.dirname(__file__), 'build', 'phpinfo-insight-dashboard.php')
    
    if os.path.exists(build_file):
        # Get file modification time
        mtime = os.path.getmtime(build_file)
        mod_time = datetime.fromtimestamp(mtime).strftime('%Y-%m-%d %H:%M:%S')
        file_size = os.path.getsize(build_file)
        
        print(f"✓ Found existing build file: {build_file}")
        print(f"  Modified: {mod_time}")
        print(f"  Size: {file_size:,} bytes")
        print()
        
        use_existing = input("Use existing build file or rebuild? (u=use existing, r=rebuild) [u]: ").strip().lower()
        
        if use_existing in ('r', 'rebuild'):
            if not run_build_script():
                print("✗ Failed to build. Exiting.")
                sys.exit(1)
        else:
            print("Using existing build file.\n")
    else:
        print(f"⚠ Build file not found: {build_file}")
        print("Running build-standalone.py to create it...\n")
        
        if not run_build_script():
            print("✗ Failed to build. Exiting.")
            sys.exit(1)
        
        # Verify the file was created
        if not os.path.exists(build_file):
            print(f"✗ Error: Build file was not created: {build_file}")
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
                # Check if remote tag exists and ask to delete it
                result = subprocess.run(
                    ['git', 'ls-remote', '--tags', 'origin', f'v{version}'],
                    capture_output=True,
                    text=True,
                    check=False
                )
                if result.returncode == 0 and result.stdout.strip():
                    print(f"⚠ Remote tag v{version} already exists")
                    delete_remote = input(f"Delete remote tag v{version} before pushing? (y/N): ").strip().lower()
                    if delete_remote in ('y', 'yes'):
                        try:
                            subprocess.run(['git', 'push', 'origin', '--delete', f'v{version}'], check=True)
                            print(f"✓ Deleted remote tag v{version}")
                        except subprocess.CalledProcessError as e:
                            print(f"⚠ Could not delete remote tag: {e}")
                
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

