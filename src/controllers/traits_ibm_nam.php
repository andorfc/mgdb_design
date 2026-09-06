<?php
/* file: controllers/traits_ibm_nam.php
 *
 * purpose: Top-level route interceptor for /traits_ibm_nam.
 *
 *          controller.php checks ./controllers/<CONTROLLER>.php before falling
 *          through to redirect.php, which builds the legacy main template
 *          before it looks in controllers/tools/. A modern controller reached
 *          that way renders on top of two chromes.
 *
 *          Rollback is TWO steps now, not one. The legacy search endpoint
 *          search/traits_ibm_nam/traits_ibm_nam_adv_results.php was **retired
 *          from the webroot on 2026-09-05** because it concatenates the request
 *          into SQL -- `stock=NAM'` put SQLSTATE[42601] in logs/mgdb.log -- and
 *          nothing on this instance still called it. So restoring the old page
 *          means:
 *
 *            1. restore that endpoint from legacy/traits-ibm-nam/ in the repo
 *               (a byte-identical copy is also preserved on the server at
 *               /var/www/claude/removed-from-webroot-20260905-traits/), and
 *            2. delete this file.
 *
 *          Step 1 alone changes nothing; step 2 alone gives back a page whose
 *          search is a 404. controllers/tools/traits_ibm_nam.php and its four
 *          templates are untouched.
 */

if (!include('./controllers/tools/traits_ibm_nam_modern.php')) {
    include('./redirect.php');
}
