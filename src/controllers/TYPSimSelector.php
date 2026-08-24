<?php
/* file: TYPSimSelector.php
 *
 * purpose: /TYPSimSelector — rank the maize collection against one accession
 *          by identity by state.
 *
 *          Loaded by controller.php, which checks controllers/<CONTROLLER>.php
 *          before falling through to redirect.php. That is what takes this
 *          route from controllers/tools/TYPSimSelector.php without touching it.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/TYPSimSelector.php');

  // Bypass edge and browser cache
  header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");

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

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $css_file = $doc_root . '/css/mgdb-typsimselector.css';
  $js_file  = $doc_root . '/js/mgdb-typsimselector.js';
  $v_css = file_exists($css_file) ? filemtime($css_file) : time();
  $v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-typsimselector.css?v=' . $v_css);
  /* Plotly is deliberately fetched on first use in mgdb-typsimselector.js */
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-typsimselector.js?v=' . $v_js);
  $bauplan->head('<meta name="description" content="Rank the USDA Ames maize inbred collection against any one accession by '
                . 'identity by state. Similarity scores were computed with PLINK from the genotyping-by-sequencing SNP data '
                . 'behind the Ames Diversity Panel, and can be downloaded as TSV or CSV.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_typsimselector.bau');

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
  $body->get('data_date')->replace(date('F j, Y'));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return;
?>
