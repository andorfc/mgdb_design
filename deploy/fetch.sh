#!/usr/bin/env bash
# Fetch a file from the MaizeGDB dev instance into the local repo.
# Usage: deploy/fetch.sh <path-relative-to-webroot> <local-destination>
# Example: deploy/fetch.sh css/maize_meeting_modern.css reference/
set -euo pipefail

CONFIG="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/config.local.sh"
if [ ! -f "$CONFIG" ]; then
  echo "missing $CONFIG — copy deploy/config.example.sh and fill it in" >&2
  exit 2
fi
# shellcheck source=/dev/null
. "$CONFIG"
: "${HOST:?HOST not set in deploy/config.local.sh}"
: "${WEBROOT:?WEBROOT not set in deploy/config.local.sh}"

if [ "$#" -ne 2 ]; then
  echo "usage: $0 <webroot-relative-path> <local-destination>" >&2
  exit 2
fi

scp -q "${HOST}:${WEBROOT}/$1" "$2"
echo "fetched ${WEBROOT}/$1 -> $2"
