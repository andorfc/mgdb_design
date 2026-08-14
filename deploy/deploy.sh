#!/usr/bin/env bash
# Deploy repo files to the MaizeGDB dev instance (dev8 / claude.maizegdb.org).
#
# Every deploy first copies the current server version of each target into
# backups/<timestamp>/ so a rollback is always possible. rsync is not installed
# on the dev server, so scp is used.
#
# Usage:
#   deploy/deploy.sh                 # deploy every mapping in deploy/manifest.txt
#   deploy/deploy.sh <local-path>    # deploy a single file present in the manifest
#
# manifest.txt format, one per line:
#   <local-path-relative-to-repo-root> <webroot-relative-destination>
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="development-server"
WEBROOT="/var/www/claude/html"
MANIFEST="${REPO_ROOT}/deploy/manifest.txt"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="${REPO_ROOT}/backups/${STAMP}"

[ -f "$MANIFEST" ] || { echo "missing manifest: $MANIFEST" >&2; exit 2; }

only="${1:-}"
deployed=0

while read -r local remote; do
  # Skip blank lines and comments.
  case "$local" in ''|\#*) continue ;; esac
  [ -n "$only" ] && [ "$local" != "$only" ] && continue

  src="${REPO_ROOT}/${local}"
  [ -f "$src" ] || { echo "missing local file: $src" >&2; exit 1; }

  # Back up the existing server copy when one exists.
  if ssh "$HOST" "test -f '${WEBROOT}/${remote}'"; then
    mkdir -p "${BACKUP_DIR}/$(dirname "$remote")"
    scp -q "${HOST}:${WEBROOT}/${remote}" "${BACKUP_DIR}/${remote}"
    echo "  backed up ${remote}"
  else
    echo "  no existing ${remote} on server (new file)"
  fi

  ssh "$HOST" "mkdir -p '${WEBROOT}/$(dirname "$remote")'"
  scp -q "$src" "${HOST}:${WEBROOT}/${remote}"
  echo "deployed ${local} -> ${WEBROOT}/${remote}"
  deployed=$((deployed + 1))
done < "$MANIFEST"

if [ "$deployed" -eq 0 ]; then
  echo "nothing deployed (no manifest entry matched '${only}')" >&2
  exit 1
fi

echo
echo "${deployed} file(s) deployed. Backups: ${BACKUP_DIR}"
