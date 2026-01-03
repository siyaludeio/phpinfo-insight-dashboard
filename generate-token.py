#!/usr/bin/env python3
"""
Generate a one-way hash of a token and save it to piid.txt
"""

import hashlib
import os
import sys

def generate_token_hash():
    """Generate and save token hash to piid.txt"""
    print("PHPINFO Insight Dashboard - Token Generator\n")
    print("=" * 60)
    
    # Get token from user
    token = input("Enter the token to hash: ").strip()
    
    if not token:
        print("✗ Error: Token cannot be empty")
        sys.exit(1)
    
    # Generate SHA-256 hash (more secure than MD5)
    token_hash = hashlib.sha256(token.encode('utf-8')).hexdigest()
    
    # Determine output file path (same directory as this script)
    script_dir = os.path.dirname(os.path.abspath(__file__))
    output_file = os.path.join(script_dir, 'piid.txt')
    
    # Write hash to file
    try:
        with open(output_file, 'w', encoding='utf-8') as f:
            f.write(token_hash)
        
        print(f"\n✓ Token hash generated successfully!")
        print(f"  Hash: {token_hash}")
        print(f"  Saved to: {output_file}")
        print(f"\n⚠️  Keep your token secure - it cannot be recovered from the hash!")
        
    except IOError as e:
        print(f"✗ Error: Failed to write to {output_file}: {e}")
        sys.exit(1)

if __name__ == '__main__':
    generate_token_hash()

