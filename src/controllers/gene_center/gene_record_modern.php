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
    return false;   // let the original controller answer
  }

  $gene_identity = geneIdentity($DBConn, $gene_resolved);
  if (!$gene_identity) {
    return false;
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

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-gene-record.css');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-gene-record.js');
  $bauplan->head('<meta name="description" content="'
    . htmlspecialchars($gene_summary, ENT_QUOTES, 'UTF-8') . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_gene_record.bau');

  // The identifier the client asks the API for. The canonical gene model name
  // when there is one, so a symbol URL and an accession URL share a cache entry.
  $api_id = ($gene_name !== '') ? $gene_name : $gene_request;

  $content->get('gene_api_id')->replace(htmlspecialchars($api_id, ENT_QUOTES, 'UTF-8'));

  /* A withdrawn record has nothing for the API to return — the resource answers
     410 — so the page tells the script not to ask. Without this the reader would
     see "the rest of this record could not be loaded", which frames a record
     that is correctly and permanently gone as a transient failure. */
  $content->get('gene_state')->replace($gene_withdrawn ? 'withdrawn' : 'current');
  $content->get('gene_name')->replace(htmlspecialchars($gene_display, ENT_QUOTES, 'UTF-8'));
  $content->get('gene_summary')->replace(htmlspecialchars($gene_summary, ENT_QUOTES, 'UTF-8'));

  /* The accession, shown beside the symbol only when the two differ, so the
     heading never repeats itself. Blocks in a .bau are declared
     {display: off} and unmuted, rather than muted away. */
  if ($gene_name !== '' && $gene_display !== $gene_name) {
    $block = $content->get('gene_accession_block');
    $block->get('gene_accession')->replace(htmlspecialchars($gene_name, ENT_QUOTES, 'UTF-8'));
    $block->unmute();
  }

  if ($gene_full_name !== '' && strcasecmp($gene_full_name, $gene_display) !== 0) {
    $block = $content->get('gene_full_name_block');
    $block->get('gene_full_name')->replace(htmlspecialchars($gene_full_name, ENT_QUOTES, 'UTF-8'));
    $block->unmute();
  }

  // Eyebrow: what kind of record this is and which annotation it comes from.
  $kind_labels = array(
    'gene_model' => 'Gene model',
    'gene_model_and_locus' => 'Gene model and classical gene',
    'locus' => 'Classical gene',
    'withdrawn' => 'Withdrawn gene model'
  );
  $eyebrow = isset($kind_labels[$gene_identity['kind']])
           ? $kind_labels[$gene_identity['kind']] : 'Gene';
  if ($gene_identity['line'] !== '') {
    $eyebrow .= ' &middot; ' . htmlspecialchars($gene_identity['line'], ENT_QUOTES, 'UTF-8');
  }
  if ($gene_identity['assembly'] !== '') {
    $eyebrow .= ' &middot; ' . htmlspecialchars($gene_identity['assembly'], ENT_QUOTES, 'UTF-8');
  }
  if ($gene_identity['annotation'] !== '') {
    $eyebrow .= ' / ' . htmlspecialchars($gene_identity['annotation'], ENT_QUOTES, 'UTF-8');
  }
  $content->get('gene_eyebrow')->replace($eyebrow);

  /* Status is server-rendered rather than left to the API call: a superseded or
     withdrawn model must say so in the first paint, not a moment later. Built
     from a fixed table, never from a database value. */
  $badges = array(
    'superseded' => array('mgdb-pill-warn', 'Superseded annotation'),
    'obsolete' => array('mgdb-pill-warn', 'Obsolete'),
    'withdrawn' => array('mgdb-pill-error', 'Withdrawn')
  );
  $content->get('status_badge')->replace(isset($badges[$gene_identity['status']])
    ? '<span class="mgdb-pill ' . $badges[$gene_identity['status']][0] . '">'
      . $badges[$gene_identity['status']][1] . '</span>'
    : '');

  /* Server-rendered facts. These are the ones already in hand from resolution,
     so they paint with the document; the rest of the fact list is filled in from
     the API. */
  $facts = '';
  if ($gene_identity['chromosome'] !== '' && $gene_identity['start'] !== null) {
    $facts .= '<div><dt>Location</dt><dd>'
      . htmlspecialchars($gene_identity['chromosome'], ENT_QUOTES, 'UTF-8') . ':'
      . number_format($gene_identity['start']) . '&ndash;'
      . number_format((int) $gene_identity['end'])
      . '<small>' . number_format((int) $gene_identity['end'] - (int) $gene_identity['start'])
      . ' bp on the genome</small></dd></div>';
  }
  if ($gene_identity['model_type'] !== '') {
    $facts .= '<div><dt>Model type</dt><dd>'
      . htmlspecialchars(str_replace('_', ' ', $gene_identity['model_type']), ENT_QUOTES, 'UTF-8')
      . '</dd></div>';
  }
  if ($gene_identity['transcript_count'] !== null) {
    $facts .= '<div><dt>Transcripts</dt><dd>' . (int) $gene_identity['transcript_count']
      . ($gene_identity['canonical_transcript'] !== ''
         ? '<small>canonical ' . htmlspecialchars($gene_identity['canonical_transcript'], ENT_QUOTES, 'UTF-8') . '</small>'
         : '')
      . '</dd></div>';
  }
  $content->get('gene_facts')->replace($facts);

  // The withdrawal banner, with its replacement, rendered server-side.
  if ($gene_withdrawn) {
    $replacement = $gene_identity['replacement'];
    $message = $replacement !== ''
      ? 'This gene model was withdrawn and replaced by <a href="/gene_center/gene/'
        . rawurlencode($replacement) . '">' . htmlspecialchars($replacement, ENT_QUOTES, 'UTF-8')
        . '</a>.'
      : 'This gene model was withdrawn from the annotation and has no replacement.';
    $notice = $content->get('withdrawn_notice');
    $notice->get('withdrawn_message')->replace($message);
    $notice->unmute();
  }

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);

  $bauplan->publish();
  return true;
?>
