#!/usr/bin/env bash
# Exercises src/js/mgdb-uniformmu.js against real API payloads.
#
# Cloudflare's bot challenge sits in front of the development instance, so a
# browser cannot load /uniformmu for checking, and there is no node on either
# machine. JavaScriptCore ships with macOS and is what runs here, against a
# small DOM shim in uniformmu_render_test.js.
#
# The gene payload is a captured response. Recapture it with:
#   ssh development-server "/tmp/get.sh \
#     '/search/uniformmu/uniformmu_search_api.php?mode=gene&term=lg1'" \
#     > tools/tests/uniformmu_gene_payload.json
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
JSC=/System/Library/Frameworks/JavaScriptCore.framework/Versions/A/Helpers/jsc
[ -x "$JSC" ] || { echo "JavaScriptCore not found at $JSC" >&2; exit 2; }

cd "${REPO_ROOT}/tools/tests"
"$JSC" -e "
var SOURCE_PATH='${REPO_ROOT}/src/js/mgdb-uniformmu.js';
var GENE_PAYLOAD='uniformmu_gene_payload.json';
var SUMMARY_PAYLOAD='${REPO_ROOT}/src/data/uniformmu/uniformmu_summary.json';
" uniformmu_render_test.js | tee /dev/stderr | grep -q '^all checks passed$'
