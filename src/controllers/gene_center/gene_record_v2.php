<?PHP
/* file: gene_record_v2.php
 *
 * purpose: Gene record page, version 2 mockup (/gene_center/gene_v2/{id}).
 *
 *          Included by controllers/gene_center.php when PAGE is 'gene_v2' and
 *          a record identifier is present. Returns false without publishing
 *          if the identifier does not resolve, so the caller falls through to
 *          its own not-found handling.
 *
 *          The page is the same record as /gene_center/gene/{id} -- the same
 *          resolver, the same identity, the same one call to
 *          /api/v1/records/gene/{id} -- divided into four views: a visual
 *          overview, the gene model in tables, the pan-gene, and the genetic
 *          information of each classical gene on the model. What is new on
 *          the server side is two indexed queries for the chromosome context
 *          the hero draws: the length of the chromosome the model sits on and
 *          of its siblings, for the karyotype.
 *
 *          This is a mockup beside the live page, not a replacement: nothing
 *          under /gene_center/gene is touched, and the route carries a
 *          noindex so search engines keep pointing at the live record.
 */

  include_once('./include/db-api.php');
  include_once('./include/gene_record_lib.php');
  include_once('./include/gene_header_lib.php');

  $system = getSystemInfo('mgdb.conf');
  $DBConn = connect_to_database(false);
  if (!$DBConn) {
    return false;
  }

  /* controller.php splits REQUEST_URI on '/' without decoding, so an identifier
     containing an escaped character arrives still encoded. Decoding happens
     here, at the boundary, exactly once. */
  $gene_request = rawurldecode((string) getCGIParam('id', 'G', ID));
  $gene_resolved = geneResolveId($DBConn, $gene_request);
  if ($gene_resolved === false) {
    return false;
  }

  $gene_identity = geneIdentity($DBConn, $gene_resolved);
  if (!$gene_identity) {
    return false;
  }

  logMessage('Starting gene_record_v2.php for ' . $gene_identity['name']);

  $gene_withdrawn = ($gene_identity['kind'] === 'withdrawn');

  $gene_name = $gene_identity['name'];
  $gene_symbol = $gene_identity['symbol'];
  $gene_full_name = $gene_identity['full_name'];

  // What the page calls itself. A classical gene leads with its symbol, because
  // that is what a reader searched for and what the literature calls it.
  $gene_display = ($gene_symbol !== '' && strcasecmp($gene_symbol, $gene_name) !== 0)
                ? $gene_symbol : $gene_name;

  $gene_title = $gene_withdrawn
    ? ('MaizeGDB Gene: ' . $gene_name . ' (withdrawn)')
    : ('MaizeGDB Gene: ' . $gene_display .
       (($gene_display !== $gene_name && $gene_name !== '') ? ' (' . $gene_name . ')' : ''));

  $summary_parts = array();
  if ($gene_full_name !== '' && strcasecmp($gene_full_name, $gene_symbol) !== 0) {
    $summary_parts[] = $gene_display . ' (' . $gene_full_name . ')';
  } else {
    $summary_parts[] = $gene_display;
  }
  if ($gene_identity['kind'] === 'locus') {
    $summary_parts[] = 'is a classical maize gene';
  } else if ($gene_withdrawn) {
    $summary_parts[] = 'is a withdrawn maize gene model';
  } else {
    $summary_parts[] = 'is a maize gene model';
    if ($gene_identity['line'] !== '') {
      $summary_parts[] = 'in ' . $gene_identity['line'];
    }
    if ($gene_identity['assembly'] !== '') {
      $summary_parts[] = '(' . $gene_identity['assembly'] . ')';
    }
    if ($gene_identity['chromosome'] !== '' && $gene_identity['start'] !== null) {
      $summary_parts[] = 'at ' . $gene_identity['chromosome'] . ':' .
        number_format($gene_identity['start']) . '-' . number_format((int) $gene_identity['end']);
    }
  }
  $gene_summary = implode(' ', $summary_parts) . ($gene_withdrawn
    ? ('. It was removed from the annotation'
       . ($gene_identity['replacement'] !== ''
          ? ' and replaced by ' . $gene_identity['replacement'] : '') . '.')
    : '. Function, protein domains, expression, pan-gene membership, orthologs, insertions, variation and references.');

  // The identifier the client asks the API for: the canonical gene model name
  // when there is one, so a symbol URL and an accession URL share a cache entry.
  $api_id = ($gene_name !== '') ? $gene_name : $gene_request;

  /* The chromosome context for the hero's karyotype: two indexed lookups, the
     first on featureloc's feature_id, the second on chado.feature's unique
     (organism_id, uniquename, type_id). Skipped for a locus with no model. */
  $chr_context = geneChromosomeContext($DBConn, $gene_identity['feature_id']);

  $bauplan = new Bauplan($gene_title);
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
  $bauplan->includeCss('/css/mgdb-gene-structure.css?v=' . $v('/css/mgdb-gene-structure.css'));
  $bauplan->includeCss('/css/mgdb-gene-expression.css?v=' . $v('/css/mgdb-gene-expression.css'));
  $bauplan->includeCss('/css/mgdb-gene-function.css?v=' . $v('/css/mgdb-gene-function.css'));
  $bauplan->includeCss('/css/mgdb-gene-record-v2.css?v=' . $v('/css/mgdb-gene-record-v2.css'));
  $bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-record.js?v=' . $v('/js/mgdb-record.js'));
  $bauplan->includeScript('/js/mgdb-gene-structure.js?v=' . $v('/js/mgdb-gene-structure.js'));
  $bauplan->includeScript('/js/mgdb-gene-expression.js?v=' . $v('/js/mgdb-gene-expression.js'));
  $bauplan->includeScript('/js/mgdb-gene-function.js?v=' . $v('/js/mgdb-gene-function.js'));
  $bauplan->includeScript('/js/mgdb-gene-record-v2.js?v=' . $v('/js/mgdb-gene-record-v2.js'));
  $bauplan->head('<meta name="description" content="'
    . htmlspecialchars($gene_summary, ENT_QUOTES, 'UTF-8') . '">');
  // A mockup beside the live record: crawlers keep the live URL.
  $bauplan->head('<meta name="robots" content="noindex,follow">');
  $bauplan->head('<link rel="canonical" href="' . htmlspecialchars($system['root_url'], ENT_QUOTES, 'UTF-8')
    . '/gene_center/gene/' . htmlspecialchars(rawurlencode($gene_request), ENT_QUOTES, 'UTF-8') . '">');
  include_once('./include/api/v1/lib/mgdb_jsonld.php');
  $bauplan->head(MgdbJsonLd::headMarkup('gene', $api_id, array(
    'name' => $gene_display, 'description' => $gene_summary,
    'attributes' => array('name' => $gene_name, 'symbol' => $gene_symbol, 'full_name' => $gene_full_name, 'assembly' => $gene_identity['assembly'], 'annotation' => $gene_identity['annotation'], 'kind' => $gene_identity['kind']))));
  MgdbJsonLd::signpost('gene', $api_id);

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_gene_record_v2.bau');

  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $content->get('gene_api_id')->replace($esc($api_id));
  $content->get('requested_identifier')->replace($esc($gene_request));
  $content->get('requested_identifier_path')->replace($esc(rawurlencode($gene_request)));
  $content->get('gene_title')->replace($esc($gene_display));
  $content->get('gene_state')->replace($gene_withdrawn ? 'withdrawn' : 'current');
  $content->get('gene_summary')->replace($esc($gene_summary));

  $content->get('chr_name')->replace($esc($chr_context['name']));
  $content->get('chr_length')->replace($chr_context['length'] === null ? '' : (string) (int) $chr_context['length']);
  $content->get('karyotype')->replace($esc(json_encode($chr_context['karyotype'])));

  $kind_labels = array(
    'gene_model' => 'Gene model',
    'gene_model_and_locus' => 'Gene model and classical gene',
    'locus' => 'Classical gene',
    'withdrawn' => 'Withdrawn gene model'
  );

  $identity_facts = '';
  if ($gene_name !== '' && $gene_display !== $gene_name) {
    $identity_facts .= '<div><dt>Gene model</dt><dd class="mgdb-record-id">' . $esc($gene_name) . '</dd></div>';
  }
  if ($gene_identity['assembly'] !== '') {
    $identity_facts .= '<div><dt>Assembly</dt><dd>' . $esc($gene_identity['assembly'])
      . ($gene_identity['annotation'] !== ''
         ? '<small>annotation ' . $esc($gene_identity['annotation']) . '</small>' : '')
      . '</dd></div>';
  }
  $kind_pill = '<span class="mgdb-pill mgdb-pill-kind">'
    . (isset($kind_labels[$gene_identity['kind']]) ? $kind_labels[$gene_identity['kind']] : 'Gene')
    . '</span>';
  $content->get('gene_subtitle')->replace(
    ($gene_full_name !== '' && strcasecmp($gene_full_name, $gene_display) !== 0) ? $esc($gene_full_name) : '');

  $badges = array(
    'superseded' => array('mgdb-pill-warn', 'Superseded annotation'),
    'obsolete' => array('mgdb-pill-warn', 'Obsolete'),
    'withdrawn' => array('mgdb-pill-error', 'Withdrawn')
  );
  $content->get('gene_badge')->replace(' ' . $kind_pill . (isset($badges[$gene_identity['status']])
    ? ' <span class="mgdb-pill ' . $badges[$gene_identity['status']][0] . '">'
      . $badges[$gene_identity['status']][1] . '</span>'
    : ''));

  $facts = $identity_facts;
  if ($gene_identity['chromosome'] !== '' && $gene_identity['start'] !== null) {
    $facts .= '<div><dt>Location</dt><dd>'
      . $esc($gene_identity['chromosome']) . ':'
      . number_format($gene_identity['start']) . '&ndash;'
      . number_format((int) $gene_identity['end'])
      . '<small>' . number_format((int) $gene_identity['end'] - (int) $gene_identity['start'])
      . ' bp on the genome</small></dd></div>';
  }
  $content->get('gene_facts')->replace($facts);

  $withdrawn_notice = '';
  if ($gene_withdrawn) {
    $replacement = $gene_identity['replacement'];
    $message = $replacement !== ''
      ? 'This gene model was withdrawn and replaced by <a href="/gene_center/gene_v2/'
        . rawurlencode($replacement) . '">' . $esc($replacement) . '</a>.'
      : 'This gene model was withdrawn from the annotation and has no replacement.';
    $withdrawn_notice = '<div class="mgdb-message mgdb-message-warn mgdb-rec-alert" role="note">'
      . '<div><strong>Withdrawn gene model</strong><span>' . $message . '</span></div></div>';
  }
  $content->get('withdrawn_notice')->replace($withdrawn_notice);

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);

  $bauplan->publish();
  return true;


/* The chromosome context for the karyotype lives in include/gene_header_lib.php
   (geneChromosomeContext), shared with the header mockup. */


?>
