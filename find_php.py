import shutil
import os
import sys

# Try getting from PATH
php_path = shutil.which('php')

if php_path:
    print(php_path)
    sys.exit(0)

# Check common locations
common_paths = [
    '/usr/bin/php',
    '/usr/local/bin/php',
    '/opt/homebrew/bin/php',
    '/Applications/MAMP/bin/php/php8.2.0/bin/php',
    '/Applications/MAMP/bin/php/php8.1.13/bin/php',
    '/Applications/MAMP/bin/php/php7.4.33/bin/php'
]

# Walk MAMP directory if possible
mamp_dir = '/Applications/MAMP/bin/php'
if os.path.exists(mamp_dir):
    for root, dirs, files in os.walk(mamp_dir):
        if 'php' in files:
            path = os.path.join(root, 'php')
            # Check if likely the binary (in a bin folder)
            if '/bin/' in path:
                print(path)
                sys.exit(0)

for path in common_paths:
    if os.path.exists(path):
        print(path)
        sys.exit(0)

print("PHP_NOT_FOUND")
sys.exit(1)
