#!/usr/bin/env bash
# Fetch a file from the MaizeGDB dev instance into the local repo.
# Usage: deploy/fetch.sh <path-relative-to-webroot> <local-destination>
# Example: deploy/fetch.sh css/maize_meeting_modern.css reference/
set -euo pipefail

HOST="development-server"
WEBROOT="/var/www/claude/html"

if [ "$#" -ne 2 ]; then
  echo "usage: $0 <webroot-relative-path> <local-destination>" >&2
  exit 2
fi

scp -q "${HOST}:${WEBROOT}/$1" "$2"
echo "fetched ${WEBROOT}/$1 -> $2"
