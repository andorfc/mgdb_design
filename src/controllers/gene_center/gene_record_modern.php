<?PHP
/* file: gene_record_modern.php
 *
 * purpose: Gene record page (/gene_center/gene/{id}) on the modern design
 *          system.
 *
 *          Included by controllers/gene_center.php when PAGE is 'gene' and a
 *          record identifier is present. Returns false without publishing if the
 *          identifier does not resolve, so the caller falls through to the
 *          original code and its 404 handling rather than the route being
 *          answered twice.
 *
 *          The page renders its own identity -- accession, gene symbol, full
 *          name, assembly, coordinates -- because the document title, the social
 *          preview, and a crawler all need to know what the record is before any
 *          script runs. The page this replaces rendered none of it: the whole
 *          document was assembled by Ajax from templates/gene_center/gene.bau,
 *          so the most-visited page on the site had no <h1>, no server-rendered
 *          text, and could not be indexed.
 *
 *          Everything else arrives in one call to /api/v1/records/gene/{id},
 *          made by js/mgdb-gene-record.js. The page it replaces made nineteen,
 *          sharded across ajax0..6.maizegdb.org subdomains to get around the
 *          browser's per-host connection limit, and cost over 1,700 database
 *          queries between them.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/gene-record/.
 */

  include_once('./include/db-api.php');
  include_once('./include/gene_record_lib.php');

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
    geneRecordNotFound($DBConn, $system, $gene_request);
    return true;
  }

  $gene_identity = geneIdentity($DBConn, $gene_resolved);
  if (!$gene_identity) {
    geneRecordNotFound($DBConn, $system, $gene_request);
    return true;
  }

  logMessage('Starting gene_record_modern.php for ' . $gene_identity['name']);

  /* A withdrawn gene model still resolves, and the reader needs to be told what
     replaced it rather than shown a 404. The legacy page had a template for
     this; here it is the same page with a banner, so the URL keeps working. */
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

  // The description a search result and a shared link show. Assembled from the
  // identity rather than boilerplate, so two gene pages never read alike.
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
  /* A withdrawn model has none of the sections the tail advertises, so it gets
     its own sentence rather than boilerplate that promises data it cannot show. */
  $gene_summary = implode(' ', $summary_parts) . ($gene_withdrawn
    ? ('. It was removed from the annotation'
       . ($gene_identity['replacement'] !== ''
          ? ' and replaced by ' . $gene_identity['replacement'] : '') . '.')
    : '. Function, protein domains, expression, pan-gene membership, orthologs, insertions, variation and references.');

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
  /* The gene model and protein figure in the Structure section. Its 3D
     viewer library is not included here: the figure loads it on request. */
  $bauplan->includeCss('/css/mgdb-gene-structure.css?v=' . $v('/css/mgdb-gene-structure.css'));
  $bauplan->includeCss('/css/mgdb-gene-expression.css?v=' . $v('/css/mgdb-gene-expression.css'));
  $bauplan->includeCss('/css/mgdb-gene-function.css?v=' . $v('/css/mgdb-gene-function.css'));
  $bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-record.js?v=' . $v('/js/mgdb-record.js'));
  $bauplan->includeScript('/js/mgdb-gene-structure.js?v=' . $v('/js/mgdb-gene-structure.js'));
  $bauplan->includeScript('/js/mgdb-gene-expression.js?v=' . $v('/js/mgdb-gene-expression.js'));
  $bauplan->includeScript('/js/mgdb-gene-function.js?v=' . $v('/js/mgdb-gene-function.js'));
  $bauplan->includeScript('/js/mgdb-gene-record.js?v=' . $v('/js/mgdb-gene-record.js'));
  $bauplan->head('<meta name="description" content="'
    . htmlspecialchars($gene_summary, ENT_QUOTES, 'UTF-8') . '">');
  /* Machine-readable identity: a JSON-LD block in the head built from the
     facts above, link elements to the JSON and JSON-LD records, and the same
     two as an HTTP Link header (FAIR Signposting). See /api#api-linked-data. */
  include_once('./include/api/v1/lib/mgdb_jsonld.php');
  $bauplan->head(MgdbJsonLd::headMarkup('gene', $api_id, array(
    'name' => $gene_display, 'description' => $gene_summary,
    'attributes' => array('name' => $gene_name, 'symbol' => $gene_symbol, 'full_name' => $gene_full_name, 'assembly' => $gene_identity['assembly'], 'annotation' => $gene_identity['annotation'], 'kind' => $gene_identity['kind']))));
  MgdbJsonLd::signpost('gene', $api_id);

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_gene_record.bau');

  // The identifier the client asks the API for. The canonical gene model name
  // when there is one, so a symbol URL and an accession URL share a cache entry.
  $api_id = ($gene_name !== '') ? $gene_name : $gene_request;

  $content->get('gene_api_id')->replace(htmlspecialchars($api_id, ENT_QUOTES, 'UTF-8'));
  $content->get('requested_identifier')->replace(htmlspecialchars($gene_request, ENT_QUOTES, 'UTF-8'));
  $content->get('requested_identifier_path')->replace(htmlspecialchars(rawurlencode($gene_request), ENT_QUOTES, 'UTF-8'));
  $content->get('gene_title')->replace(htmlspecialchars($gene_display, ENT_QUOTES, 'UTF-8'));

  /* A withdrawn record has nothing for the API to return — the resource answers
     410 — so the page tells the script not to ask. Without this the reader would
     see "the rest of this record could not be loaded", which frames a record
     that is correctly and permanently gone as a transient failure. */
  $content->get('gene_state')->replace($gene_withdrawn ? 'withdrawn' : 'current');
  $content->get('gene_summary')->replace(htmlspecialchars($gene_summary, ENT_QUOTES, 'UTF-8'));

  /* What kind of record this is, and which annotation it comes from. This was
     an eyebrow above the title; the design system does not use eyebrows, and
     these are facts, so they are facts. */
  $kind_labels = array(
    'gene_model' => 'Gene model',
    'gene_model_and_locus' => 'Gene model and classical gene',
    'locus' => 'Classical gene',
    'withdrawn' => 'Withdrawn gene model'
  );
  /* The hero carries only what places the record: the gene model id, the
     assembly and annotation, the location. Everything else that used to sit
     here (record kind, full name, line, model type, transcripts) belongs to
     Overview, where the page was already repeating half of it. The kind is
     a pill beside the title; the full name is the subtitle. */
  $identity_facts = '';
  if ($gene_name !== '' && $gene_display !== $gene_name) {
    $identity_facts .= '<div><dt>Gene model</dt><dd class="mgdb-record-id">'
      . htmlspecialchars($gene_name, ENT_QUOTES, 'UTF-8') . '</dd></div>';
  }
  /* Assembly and annotation together, because a B73 gene has seven of them --
     RefGen_v1 through NAM-5.0 -- and which one a reader is looking at is the
     single most confusing thing about this page. */
  if ($gene_identity['assembly'] !== '') {
    $identity_facts .= '<div><dt>Assembly</dt><dd>'
      . htmlspecialchars($gene_identity['assembly'], ENT_QUOTES, 'UTF-8')
      . ($gene_identity['annotation'] !== ''
         ? '<small>annotation ' . htmlspecialchars($gene_identity['annotation'], ENT_QUOTES, 'UTF-8') . '</small>'
         : '')
      . '</dd></div>';
  }
  $kind_pill = '<span class="mgdb-pill mgdb-pill-kind">'
    . (isset($kind_labels[$gene_identity['kind']]) ? $kind_labels[$gene_identity['kind']] : 'Gene')
    . '</span>';
  $content->get('gene_subtitle')->replace(
    ($gene_full_name !== '' && strcasecmp($gene_full_name, $gene_display) !== 0)
      ? htmlspecialchars($gene_full_name, ENT_QUOTES, 'UTF-8') : '');

  /* Status is server-rendered rather than left to the API call: a superseded or
     withdrawn model must say so in the first paint, not a moment later. Built
     from a fixed table, never from a database value. */
  $badges = array(
    'superseded' => array('mgdb-pill-warn', 'Superseded annotation'),
    'obsolete' => array('mgdb-pill-warn', 'Obsolete'),
    'withdrawn' => array('mgdb-pill-error', 'Withdrawn')
  );
  $content->get('gene_badge')->replace(' ' . $kind_pill . (isset($badges[$gene_identity['status']])
    ? ' <span class="mgdb-pill ' . $badges[$gene_identity['status']][0] . '">'
      . $badges[$gene_identity['status']][1] . '</span>'
    : ''));

  /* Server-rendered facts. These are the ones already in hand from resolution,
     so they paint with the document; the rest of the fact list is filled in from
     the API. */
  $facts = $identity_facts;
  if ($gene_identity['chromosome'] !== '' && $gene_identity['start'] !== null) {
    $facts .= '<div><dt>Location</dt><dd>'
      . htmlspecialchars($gene_identity['chromosome'], ENT_QUOTES, 'UTF-8') . ':'
      . number_format($gene_identity['start']) . '&ndash;'
      . number_format((int) $gene_identity['end'])
      . '<small>' . number_format((int) $gene_identity['end'] - (int) $gene_identity['start'])
      . ' bp on the genome</small></dd></div>';
  }
  $content->get('gene_facts')->replace($facts);

  // The withdrawal banner, with its replacement, rendered server-side.
  $withdrawn_notice = '';
  if ($gene_withdrawn) {
    $replacement = $gene_identity['replacement'];
    $message = $replacement !== ''
      ? 'This gene model was withdrawn and replaced by <a href="/gene_center/gene/'
        . rawurlencode($replacement) . '">' . htmlspecialchars($replacement, ENT_QUOTES, 'UTF-8')
        . '</a>.'
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


/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* geneRecordNotFound() and geneNotFoundBlock() lived here. They moved to
   include/gene_record_lib.php when /gene_center/gene/ was switched to the v5
   controller, which needs the same 404 page; this file still calls them, so it
   keeps working as the rollback. */

?>
