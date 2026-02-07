#!/bin/bash

# Configuration from .vscode/sftp.json
HOST="815hosting.com"
USER="kevinchamplinftp"
PASS="1Td2h4?s5"
REMOTE_DIR="/epstein.kevinchamplin.com/storage/manual_uploads"
LOCAL_DIR="./storage/manual_uploads"

# Ensure we are in the right directory
if [ ! -d "$LOCAL_DIR" ]; then
    echo "Error: Local directory $LOCAL_DIR not found. Please run this script from the project root."
    exit 1
fi

echo "Starting upload of missing manual uploads to $HOST..."

lftp -u $USER,$PASS $HOST <<EOF
set ftp:ssl-allow no
set ftp:passive-mode true
mirror -R --only-missing --verbose "$LOCAL_DIR" "$REMOTE_DIR"
quit
EOF

echo "Upload complete."
