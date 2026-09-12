<?php
/* file: person.php
 *
 * purpose: top-level route for /person, so the modern person pages are not
 *          served inside the legacy chrome.
 *
 * Why this file exists
 * --------------------
 * The person search page and the person record page are both modern and both
 * build their own Bauplan. They were still arriving wrapped in the old site
 * chrome, because controller.php has no controllers/person.php and therefore
 * hands /person to controllers/community.php, which loads
 * templates/maizegdb-main.bau -- the LEGACY main -- at its line 127, before it
 * dispatches anything. That template registers index.css, background_static.css,
 * ie6.css and the shadowbox sheet, and those registrations survive into the new
 * Bauplan the modern controller makes. Modern markup, old chrome.
 *
 * /person is the busiest page this was happening to: 701 requests from 639
 * distinct clients in six days of production traffic, more than every other
 * unmigrated page put together.
 *
 * Same fix as /nomenclature, /handyref and /contribute_data. controller.php
 * checks controllers/<CONTROLLER>.php first, so this file takes the route
 * before community.php can build the old shell.
 *
 * Routing:
 *   no id                     -> modern person search
 *   an id that resolves       -> modern person record
 *   anything else             -> the site 404, on the modern shell.
 *
 * The unresolvable-id case, corrected 2026-09-07
 * ----------------------------------------------
 * This file used to hand an id it could not resolve back to community.php,
 * which built the LEGACY record page for it. That page renders nothing. Asked
 * for /person/123 it answered:
 *
 *   HTTP 200, legacy chrome, and a body reading "Home > Community > Person
 *   record" -- 39 KB of shell around no content.
 *
 * The three ids carved out as special -- cooperators, breeders and maizegdb --
 * were being preserved for the same handler, on the assumption that it still
 * built those lists. It does not: all three return the same 15 tables and 28
 * rows of chrome as a nonsense id, with zero person links. Measured against
 * ?id=zzzznotanid, which is byte-for-byte the same page. So the carve-out is
 * gone with the rest; the modern hub's browse-by-initial does that job.
 *
 * The title was worse than the body. community.php line 219 builds it as
 *
 *   $bauplan->title('MaizeGDB ' . ucfirst(PAGE) . ' Record Page: ' . $id);
 *
 * and Bauplan::title() writes its argument into <title> with no escaping
 * (lib/Bauplan.php line 181), so the id was reflected raw. Verified against
 * the origin, past Cloudflare&#58;
 *
 *   /person?id=</title><script>alert(1)</script>
 *   -> <title>MaizeGDB  Record Page: </title><script>alert(1)</script></title>
 *
 * A reflected XSS, live, masked from the public hostname by the WAF. The four
 * title calls that build a title this way are escaped as part of this change;
 * this route is the one that could reach them.
 *
 * Rollback: delete this file and /person goes back through community.php.
 *
 * history
 *  09/07/26  claude  created
 *  09/07/26  claude  unresolvable id now gets the site 404 rather than an
 *                    empty legacy record page at HTTP 200
 */

  $person_id_req = getCGIParam('id', 'G', (PAGE !== null && PAGE !== '') ? PAGE : '');

  if ($person_id_req === '') {
    include('controllers/community/person_search_modern.php');
    return;
  }

  /* Returns false for an id that is not a person. */
  if (include('controllers/community/person_record_modern.php')) {
    return;
  }

  logMessage('person.php: no person for ' . $person_id_req . ' -- serving 404');
  include('controllers/not_found.php');
  exit;
?>
