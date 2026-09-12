#!/usr/bin/env bash
# Deploy repo files to the MaizeGDB development instance.
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
#
# Every .bau in the deploy set is checked for balanced Bauplan blocks BEFORE
# anything is copied, and nothing is deployed if any of them fails. See
# deploy/check_bau.py. Set SKIP_BAU_CHECK=1 to deploy anyway.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Host and web root live in an untracked local file so they stay out of the
# repository. See deploy/config.example.sh.
CONFIG="${REPO_ROOT}/deploy/config.local.sh"
if [ ! -f "$CONFIG" ]; then
  echo "missing $CONFIG — copy deploy/config.example.sh and fill it in" >&2
  exit 2
fi
# shellcheck source=/dev/null
. "$CONFIG"
: "${HOST:?HOST not set in deploy/config.local.sh}"
: "${WEBROOT:?WEBROOT not set in deploy/config.local.sh}"

MANIFEST="${REPO_ROOT}/deploy/manifest.txt"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="${REPO_ROOT}/backups/${STAMP}"

[ -f "$MANIFEST" ] || { echo "missing manifest: $MANIFEST" >&2; exit 2; }

only="${1:-}"
deployed=0

# ---------------------------------------------------------------------------
# Pre-flight: every .bau in this deploy set has to parse.
#
# A single stray ")" in a template -- including one inside an HTML comment,
# which Bauplan's tokenizer does not recognise as a comment -- closes the
# outermost block and the page then serves a one-line parse error with status
# **200**. That took /gene_center/gene down for a day on 2026-09-10 from a
# comment-only edit, and status-code monitoring cannot see it.
#
# Run over the whole set first rather than per file inside the loop, so a bad
# template in a full manifest run stops the deploy instead of leaving half of
# it applied.
# ---------------------------------------------------------------------------
if [ "${SKIP_BAU_CHECK:-0}" != "1" ]; then
  if command -v python3 >/dev/null 2>&1; then
    bau_targets=()
    while read -r local remote; do
      case "$local" in ''|\#*) continue ;; esac
      [ -n "$only" ] && [ "$local" != "$only" ] && continue
      case "$local" in *.bau) ;; *) continue ;; esac
      [ -f "${REPO_ROOT}/${local}" ] && bau_targets+=("${REPO_ROOT}/${local}")
    done < "$MANIFEST"

    if [ ${#bau_targets[@]} -gt 0 ]; then
      if ! python3 "${REPO_ROOT}/deploy/check_bau.py" "${bau_targets[@]}"; then
        echo >&2
        echo "refusing to deploy: the template(s) above will not parse." >&2
        echo "Bauplan blames the LAST ')' in the file, which is why its own" >&2
        echo "error names a line that looks innocent. Fix the line named here." >&2
        echo "To deploy anyway: SKIP_BAU_CHECK=1 deploy/deploy.sh ${only}" >&2
        exit 3
      fi
      echo "bau check: ${#bau_targets[@]} template(s) parse"
    fi
  else
    echo "bau check skipped: python3 not found" >&2
  fi
fi

while read -r local remote; do
  # Skip blank lines and comments.
  case "$local" in ''|\#*) continue ;; esac
  [ -n "$only" ] && [ "$local" != "$only" ] && continue

  src="${REPO_ROOT}/${local}"
  [ -f "$src" ] || { echo "missing local file: $src" >&2; exit 1; }

  # Back up the existing server copy when one exists.
  # -n is required: without it ssh consumes the manifest from stdin and the
  # loop silently stops after the first entry.
  if ssh -n "$HOST" "test -f '${WEBROOT}/${remote}'"; then
    mkdir -p "${BACKUP_DIR}/$(dirname "$remote")"
    scp -q "${HOST}:${WEBROOT}/${remote}" "${BACKUP_DIR}/${remote}"
    echo "  backed up ${remote}"
  else
    echo "  no existing ${remote} on server (new file)"
  fi

  ssh -n "$HOST" "mkdir -p '${WEBROOT}/$(dirname "$remote")'"
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
