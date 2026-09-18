<?PHP
/* file: gene_record_v4.php
 *
 * purpose: Gene record header mockup (/gene_center/gene_v4/{id}) -- the
 *          header alone, nothing below it and no synonym table.
 *
 *          Included by controllers/gene_center.php when PAGE is 'gene_v4'
 *          and a record identifier is present. Returns false without
 *          publishing if the identifier does not resolve, so the caller
 *          falls through to its own not-found handling.
 *
 *          The same facts as the v3 header and the same data layer --
 *          include/gene_header_lib.php, about eight indexed lookups, no page
 *          script and no API call -- laid out the way the v2 mockup reads:
 *          one green panel rather than two white cards, inline label/value
 *          pairs rather than a tabbed definition list, and the gene name and
 *          the gene model name both set large.
 *
 *          Two halves divided by a vertical rule. Left, the gene and its
 *          genetic map; right, the gene model and its physical map. The two
 *          map glyphs are the same drawing in different units, which is the
 *          reason for the split.
 *
 *          The panel itself is built by geneHeaderPanel() in
 *          include/gene_header_panel.php, which /gene_center/gene_v5 renders
 *          too. It was this file until v5 arrived; the markup is unchanged.
 */

  include_once('./include/db-api.php');
  include_once('./include/gene_record_lib.php');
  include_once('./include/gene_header_panel.php');

  $system = getSystemInfo('mgdb.conf');
  $DBConn = connect_to_database(false);
  if (!$DBConn) {
    return false;
  }

  $gene_request = rawurldecode((string) getCGIParam('id', 'G', ID));
  $gene_resolved = geneResolveId($DBConn, $gene_request);
  if ($gene_resolved === false) {
    return false;
  }
  $gene_identity = geneIdentity($DBConn, $gene_resolved);
  if (!$gene_identity) {
    return false;
  }
  logMessage('Starting gene_record_v4.php for ' . $gene_identity['name']);

  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $panel = geneHeaderPanel($DBConn, $gene_identity, $gene_resolved, '/gene_center/gene_v4/');

  /* ---- Publish -------------------------------------------------------------- */
  $bauplan = new Bauplan($panel['gene_title']);
  $bauplan->modern();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $v = function ($path) use ($doc_root) {
    return file_exists($doc_root . $path) ? filemtime($doc_root . $path) : time();
  };

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . $v('/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-record.css?v=' . $v('/css/mgdb-record.css'));
  $bauplan->includeCss('/css/mgdb-gene-record.css?v=' . $v('/css/mgdb-gene-record.css'));
  $bauplan->includeCss('/css/mgdb-gene-record-v4.css?v=' . $v('/css/mgdb-gene-record-v4.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->head('<meta name="description" content="' . $esc($panel['gene_summary']) . '">');
  $bauplan->head('<meta name="robots" content="noindex,follow">');
  $bauplan->head('<link rel="canonical" href="' . $esc($system['root_url']) . '/gene_center/gene/' . $esc(rawurlencode($gene_request)) . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_gene_record_v4.bau');
  $content->get('gene_title')->replace($esc($panel['gene_display']));
  $content->get('gene_summary')->replace($esc($panel['gene_summary']));
  $content->get('requested_identifier_path')->replace($esc(rawurlencode($gene_request)));
  $content->get('status_notice')->replace($panel['status_notice']);
  $content->get('locus_side')->replace($panel['locus_side']);
  $content->get('model_side')->replace($panel['model_side']);

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);

  $bauplan->publish();
  return true;
?>
