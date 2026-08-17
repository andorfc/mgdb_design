<?php
/* file: TYPSimSelector.php
 *
 * purpose: /TYPSimSelector — rank the maize collection against one accession
 *          by identity by state.
 *
 *          Loaded by controller.php, which checks controllers/<CONTROLLER>.php
 *          before falling through to redirect.php. That is what takes this
 *          route from controllers/tools/TYPSimSelector.php without touching it;
 *          the original controller, its three templates, its stylesheet and its
 *          two scripts are archived in the redesign repository under
 *          legacy/typsimselector/.
 *
 *          Rollback is deleting this file: the original is still on disk and
 *          redirect.php finds it again immediately.
 *
 * What changed, and why
 * ---------------------
 * The page this replaces weighed 705 KB and had no <h1>, no page title beyond
 * "Welcome to MaizeGDB", and no viewport. Almost all of that weight was four
 * <select> elements — the 3,679-accession curation list twice and the
 * 2,831-line breeding list twice, about 13,000 <option> elements — built by
 * four queries that ran on every page view whether or not anyone opened a
 * dropdown. One of those queries, DISTINCT iid1 over the 4,005,865-row
 * pidata.ames_merged, is a 320 ms sequential scan.
 *
 * Those lists are constants. The IBS matrices were computed once, in 2012,
 * from a fixed SNP export, and nothing writes to the tables. So they are built
 * offline by tools/typsimselector_index.php into data/typsimselector/ and
 * fetched as static files, once, only after a reader has chosen a dataset.
 *
 * Rendering this page therefore runs **zero SQL**. A ranking costs three or
 * four indexed queries and answers in 10-25 ms.
 *
 * Two things the old page got wrong, now fixed:
 *
 *   The breeding dropdown was built from DISTINCT iid1 alone. ames_merged
 *   holds the strict upper triangle of the matrix, so the last line in sort
 *   order never appears in iid1 and was missing from the list entirely. The
 *   picker now carries all 2,831.
 *
 *   The curation dropdown collapsed on the taxa string and kept the first id
 *   it saw, which made every replicate genotyping run after the first
 *   unreachable — while those same runs still appeared in the results. 347
 *   accessions were genotyped more than once, one of them 28 times. The picker
 *   now offers the run.
 *
 * The ranking itself lives in search/typsimselector/, which also serves the
 * TSV and CSV exports.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/TYPSimSelector.php');

/* -------------------------------------------------------------------------- *
 * The measured payload
 * -------------------------------------------------------------------------- */

  $typ_payload_rel  = '/data/typsimselector/summary.json';
  $typ_payload_file = $system['root_dir'] . $typ_payload_rel;
  if (!is_file($typ_payload_file) && isset($_SERVER['DOCUMENT_ROOT'])) {
      $typ_payload_file = $_SERVER['DOCUMENT_ROOT'] . $typ_payload_rel;
  }

  $typ_data = null;
  if (is_file($typ_payload_file)) {
      $typ_data = json_decode(file_get_contents($typ_payload_file), true);
  }

  /* Absent or malformed, the page still renders: the tool, the method notes
     and the citations are all independent of it. Only the counts go, and they
     go visibly rather than as zeros — a zero here would be a claim about the
     collection, and it would be false. */
  $typ_have_data = is_array($typ_data) && isset($typ_data['datasets']['curation'], $typ_data['datasets']['breeding']);
  if (!$typ_have_data) {
      reportError('TYPSimSelector.php: missing or unreadable payload ' . $typ_payload_file);
  }

  function typ_count($dataset, $key) {
      global $typ_data, $typ_have_data;
      if (!$typ_have_data || !isset($typ_data['datasets'][$dataset][$key])) {
          return '&mdash;';
      }
      return number_format((float) $typ_data['datasets'][$dataset][$key]);
  }

/* -------------------------------------------------------------------------- *
 * The document
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('TYPSimSelector: rank maize accessions by genetic similarity | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-typsimselector.css');
  /* Plotly is deliberately not included here. It is 3.6 MB, and the only thing
     on this page that needs it is a figure that does not exist until a reader
     has run a comparison. mgdb-typsimselector.js fetches it on first use. */
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-typsimselector.js');
  $bauplan->head('<meta name="description" content="Rank the USDA Ames maize inbred collection against any one accession by '
                . 'identity by state. Similarity scores were computed with PLINK from the genotyping-by-sequencing SNP data '
                . 'behind the Ames Diversity Panel, and can be downloaded as TSV or CSV.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_typsimselector.bau');

  /* The picker files are versioned by the build time recorded inside them, so
     a rebuilt index invalidates the browser copy without an ETag round trip.
     They are large enough to be worth caching hard and static enough that
     nothing else would ever invalidate them. */
  $typ_stamp = $typ_have_data && isset($typ_data['generated'])
      ? substr(preg_replace('/[^0-9]/', '', $typ_data['generated']), 0, 14)
      : (string) time();

  $body->get('lines-curation-url')->replace('/data/typsimselector/lines_curation.json?v=' . $typ_stamp);
  $body->get('lines-breeding-url')->replace('/data/typsimselector/lines_breeding.json?v=' . $typ_stamp);
  $body->get('api-url')->replace('/search/typsimselector/typsimselector_search_api.php');

  $body->get('curation-accessions')->replace(typ_count('curation', 'accessions'));
  $body->get('curation-entries')->replace(typ_count('curation', 'entries'));
  $body->get('curation-pairs')->replace(typ_count('curation', 'pairs'));
  $body->get('breeding-lines')->replace(typ_count('breeding', 'accessions'));
  $body->get('breeding-pairs')->replace(typ_count('breeding', 'pairs'));

  $typ_total_pairs = '&mdash;';
  if ($typ_have_data) {
      $typ_total_pairs = number_format(
          (float) $typ_data['datasets']['curation']['pairs'] + (float) $typ_data['datasets']['breeding']['pairs']
      );
  }
  $body->get('total-pairs')->replace($typ_total_pairs);

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return;
?>
