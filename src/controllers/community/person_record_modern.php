<?php
/* file: person_record_modern.php
 *
 * purpose: Person record page (/person?id={id}, /person/{id}) on the modern
 *          design system -- the Data Hub shell plus the shared record shell
 *          (css/mgdb-record.css). Rendered server-side; the legacy page fetched
 *          three Ajax sections and ran one query per publication.
 *
 *          Included by controllers/community.php when the person controller is
 *          reached with a record id. It returns true when it renders a record
 *          and false when the id does not resolve, so an unknown id falls
 *          through to the original handler and its 404.
 *
 *          Pre-redesign originals are archived in the redesign repo under
 *          legacy/person/.
 */

  include_once('./include/db-api.php');
  include_once('./include/person_record_lib.php');

  $system = getSystemInfo('mgdb.conf');
  $DBConn = connect_to_database(false);

  $requested_identifier = trim((string) getCGIParam('id', 'G',
      (PAGE !== null && PAGE !== '') ? PAGE : ''));

  $person_id = personResolveId($DBConn, $requested_identifier);
  if ($person_id === false) {
    return false;   // let community.php fall through to the legacy handler / 404
  }

  $identity = personIdentity($DBConn, $person_id);
  if (!$identity) {
    return false;
  }

  logMessage('Starting person_record_modern.php for ' . $person_id);

  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  $esc = 'personRecEsc';

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : $system['root_dir'];

  $display = $identity['display_name'];
  $short = (strlen($display) > 70) ? rtrim(substr($display, 0, 70)) . "\xE2\x80\xA6" : $display;

  /* Build the sections that have content, and the matching tab list. */
  $tabs = array();
  $sections = '';

  $section = function ($id, $label, $countHtml, $bodyHtml) use (&$tabs, &$sections) {
    $tabs[] = '<a href="#' . $id . '">' . $label . '</a>';
    $sections .= '<section id="' . $id . '" aria-labelledby="' . $id . '-title">'
              . '<div class="mgdb-section-heading"><div><h2 id="' . $id . '-title">' . $label . $countHtml . '</h2></div></div>'
              . '<div>' . $bodyHtml . '</div>'
              . '</section>';
  };

  $badges = personBadges($DBConn, $doc_root, $person_id);
  if ($badges) {
    $section('person-rec-badges', 'Badges', ' <span class="mgdb-rec-block-count">' . count($badges) . '</span>',
             personBadgesHtml($badges));
  }

  $roles_html = personRolesHtml($DBConn, $person_id);
  if ($roles_html !== '') {
    $section('person-rec-roles', 'Roles &amp; recognitions', '', $roles_html);
  }

  $contact_html = personContactHtml($DBConn, $person_id, $identity);
  if ($contact_html !== '') {
    $section('person-rec-contact', 'Contact', '', $contact_html);
  }

  list($pub_count, $pub_html) = personPublications($DBConn, $person_id);
  if ($pub_count > 0) {
    $badge = ' <span class="mgdb-rec-block-count">' . number_format($pub_count) . '</span>';
    $section('person-rec-pubs', 'Publications', $badge, $pub_html);
  }

  list($prj_count, $prj_html) = personProjects($DBConn, $person_id);
  if ($prj_count > 0) {
    $badge = ' <span class="mgdb-rec-block-count">' . number_format($prj_count) . '</span>';
    $section('person-rec-projects', 'Projects', $badge, $prj_html);
  }

  if (!$sections) {
    $sections = '<section aria-labelledby="person-rec-empty-title">'
              . '<div class="mgdb-section-heading"><div><h2 id="person-rec-empty-title">Record</h2></div></div>'
              . '<div><p class="mgdb-muted">This record has a name and identifier but no further curated detail yet.</p></div>'
              . '</section>';
  }

  $tabs_html = '';
  if (count($tabs) > 1) {
    // Mark the first tab current, like every other record page.
    $tabs[0] = preg_replace('/^<a /', '<a class="is-current" aria-current="true" ', $tabs[0]);
    $tabs_html = '<nav class="mgdb-section-tabs mgdb-rec-tabs" aria-label="Sections on this record">'
               . implode('', $tabs) . '</nav>';
  }

  /* Modern shell. */
  $v = function ($p) use ($doc_root) { return (int) @filemtime($doc_root . $p); };

  $bauplan = new Bauplan($short . ' | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . $v('/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-record.css?v=' . $v('/css/mgdb-record.css'));
  $bauplan->includeCss('/css/mgdb-person-record.css?v=' . $v('/css/mgdb-person-record.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-person-record.js?v=' . $v('/js/mgdb-person-record.js'));
  $bauplan->head('<meta name="description" content="' . $esc($display) . ' -- person record at MaizeGDB, with affiliation, publications, and projects.">');
  $bauplan->head('<meta name="robots" content="noindex">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_person_record.bau');
  $body->get('requested_identifier')->replace($esc($requested_identifier));
  $body->get('person_id')->replace($esc($person_id));
  $body->get('display_name')->replace($esc($display));
  $syn = personSynonymsHtml($DBConn, $person_id, $identity['name'], $display);
  $body->get('synonyms')->replace($syn !== '' ? '<p class="mgdb-rec-synonyms">Also known as: ' . $syn . '</p>' : '');
  $body->get('identity_facts')->replace(personIdentityFacts($identity));
  $body->get('section_tabs')->replace($tabs_html);
  $body->get('sections')->replace($sections);

  // Fills the megamenu labels (Home/About/Community/... and the top-right items);
  // without it the header renders with empty menu links.
  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return true;
?>
