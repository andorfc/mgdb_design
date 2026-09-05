<?PHP
/* file: cite.php
 *
 * purpose: main controller for /cite — How to Cite MaizeGDB
 *
 * This replaces the modernized page over the canonical /cite route.
 *
 * Previously /cite had no top-level controller, so controller.php fell through
 * to redirect.php, which located controllers/about/cite.php and rendered
 * templates/about/cite.bau inside the legacy shell. controller.php checks
 * controllers/<CONTROLLER>.php first, so this file now takes the route before
 * that fallback runs. Nothing under controllers/about/ or templates/about/ was
 * modified, and /about/cite still serves the original page.
 *
 * The pre-redesign files are archived in the redesign repository under
 * legacy/cite/. Rollback: delete this file and /cite returns to the old page.
 */

  // Explicit headers to bypass Cloudflare / browser edge cache for this page
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/cite.php');

  $bauplan = new Bauplan('How to Cite MaizeGDB | MaizeGDB');
  $bauplan->modern();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-cite.css';
  $js_file = $doc_root . '/js/mgdb-cite.js';
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();
  $v_js = file_exists($js_file) ? filemtime($js_file) : time();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-cite.css?v=' . $v_css);
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-cite.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="How to cite MaizeGDB, including the current reference for the resource and the full list of MaizeGDB publications.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');

  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_cite.bau');

/* -------------------------------------------------------------------------- *
 * Refereed journal articles
 *
 * The list is the curated bibliography itself -- data/cite_journal_articles.json,
 * the file every hub's References section already reads -- rendered through
 * include/references_lib.php, so a paper looks the same here as it does on the
 * page that cites it.
 *
 * The template used to carry all sixty citations as hand-written markup. That is
 * the second copy the pattern library warns about, and it had already drifted:
 * one record's DOI had been pasted in as a whole URL, so the card linked
 * https://doi.org/https\://doi.org/10.1155/... and its Copy DOI button handed
 * over a string with a backslash in it. The bibliography has the bare DOI.
 *
 * The cards carry the filter's hooks as data attributes rather than being
 * wrapped in a second element, and data-search names the fields the search hint
 * promises -- author, title, journal, year -- so a hit is always something the
 * reader can see on the card rather than a word buried in a clamped abstract.
 * -------------------------------------------------------------------------- */

  include_once('./include/references_lib.php');

  $journal_rows = json_decode(
      (string) @file_get_contents($doc_root . '/data/cite_journal_articles.json'), true);
  if (!is_array($journal_rows)) {
      $journal_rows = array();
      reportError('cite.php: cannot read data/cite_journal_articles.json');
  }

  // Newest first, and stable, so records of one year keep the order the
  // bibliography lists them in.
  $ordered = array();
  foreach ($journal_rows as $position => $row) {
      $ordered[] = array((int) $row['year'], $position, $row);
  }
  usort($ordered, function ($a, $b) {
      return $a[0] === $b[0] ? $a[1] - $b[1] : $b[0] - $a[0];
  });

  $journal_cards = '';
  $heading_year  = null;
  foreach ($ordered as $entry) {
      $row  = $entry[2];
      $year = (string) $row['year'];
      $doi  = isset($row['doi']) ? trim($row['doi']) : '';

      if ($year !== $heading_year) {
          $journal_cards .= '<h4 class="mgdb-cite-year" data-year-heading="'
                          . htmlspecialchars($year, ENT_QUOTES, 'UTF-8') . '">'
                          . htmlspecialchars($year, ENT_QUOTES, 'UTF-8') . '</h4>';
          $heading_year = $year;
      }

      $searchable = trim($row['authors'] . ' ' . $row['title'] . ' '
                       . $row['journal'] . ' ' . $year . ' ' . $doi);

      $journal_cards .= mgdb_render_references($doc_root, array(array(
          'doi'            => $doi,
          // A preview, the length the page showed before; the card's Full text
          // link is the way to the rest, and sixty whole abstracts would have
          // added about 90 KB to a page this host does not compress.
          'abstract_limit' => 700,
          'attrs'          => array(
              'class'       => 'mgdb-cite-entry',
              'data-filter' => 'journal',
              'data-year'   => $year,
              'data-doi'    => $doi,
              'data-search' => $searchable,
          ),
      )));
  }

  $body->get('journal_cards')->replace($journal_cards);

/* The counts the page prints. The journal figure is the bibliography's own, so
   adding a record updates the group heading and both metric cards at once; the
   other three groups are still written into the template, and 180 is what they
   hold. Before this the page advertised 242 against 240 entries, because two
   "back to top" links had been marked up as publications. */
  $journal_count = count($journal_rows);
  $other_count   = 24 + 136 + 20;   // conference, extended abstracts, coordination
  $body->get('journal_count')->replace(number_format($journal_count));
  $body->get('publication_count')->replace(number_format($journal_count + $other_count));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
