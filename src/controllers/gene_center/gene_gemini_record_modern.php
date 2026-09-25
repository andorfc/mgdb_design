<?PHP
/* file: gene_gemini_record_modern.php
 *
 * purpose: Gene record page mockup (/gene_center/gene_gemini/{id}) with multi-view
 *          tab layout (Visual overview, Gene model, Pan-gene, Genetic information)
 *          on the modern design system.
 */

  include_once('./include/db-api.php');
  include_once('./include/gene_record_lib.php');

  $system = getSystemInfo('mgdb.conf');
  $DBConn = connect_to_database(false);
  if (!$DBConn) {
    return false;
  }

  $gene_request = rawurldecode((string) getCGIParam('id', 'G', ID));
  $gene_resolved = geneResolveId($DBConn, $gene_request);
  if ($gene_resolved === false) {
    geneGeminiRecordNotFound($DBConn, $system, $gene_request);
    return true;
  }

  $gene_identity = geneIdentity($DBConn, $gene_resolved);
  if (!$gene_identity) {
    geneGeminiRecordNotFound($DBConn, $system, $gene_request);
    return true;
  }

  logMessage('Starting gene_gemini_record_modern.php for ' . $gene_identity['name']);

  $gene_withdrawn = ($gene_identity['kind'] === 'withdrawn');

  $gene_name = $gene_identity['name'];
  $gene_symbol = $gene_identity['symbol'];
  $gene_full_name = $gene_identity['full_name'];

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

  $api_id = ($gene_name !== '') ? $gene_name : $gene_request;

  $chr_context = geneGeminiChromosomeContext($DBConn, $gene_identity['feature_id']);

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
  $bauplan->includeCss('/css/mgdb-gene-gemini-record.css?v=' . $v('/css/mgdb-gene-gemini-record.css'));

  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-record.js?v=' . $v('/js/mgdb-record.js'));
  $bauplan->includeScript('/js/mgdb-gene-structure.js?v=' . $v('/js/mgdb-gene-structure.js'));
  $bauplan->includeScript('/js/mgdb-gene-expression.js?v=' . $v('/js/mgdb-gene-expression.js'));
  $bauplan->includeScript('/js/mgdb-gene-function.js?v=' . $v('/js/mgdb-gene-function.js'));
  $bauplan->includeScript('/js/mgdb-gene-gemini-record.js?v=' . $v('/js/mgdb-gene-gemini-record.js'));

  $bauplan->head('<meta name="description" content="'
    . htmlspecialchars($gene_summary, ENT_QUOTES, 'UTF-8') . '">');
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

  $content = $mgdb->get('body')->load('templates/static/mgdb_gene_gemini_record.bau');

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
      ? 'This gene model was withdrawn and replaced by <a href="/gene_center/gene_gemini/'
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


function geneGeminiChromosomeContext($DBConn, $feature_id) {
  $none = array('name' => '', 'length' => null, 'karyotype' => array());
  if (!$feature_id) {
    return $none;
  }
  $src = retrieve_row(make_query($DBConn, "
    SELECT s.uniquename, s.seqlen, s.organism_id, s.type_id
    FROM chado.featureloc fl
      JOIN chado.feature s ON s.feature_id = fl.srcfeature_id
    WHERE fl.feature_id = :fid
    LIMIT 1", 1, array('fid' => (int) $feature_id)));
  if (!$src || $src['seqlen'] === null || $src['seqlen'] === '') {
    return $none;
  }
  if (!preg_match('/^(.*?)([Cc]hr)(\d+)$/', (string) $src['uniquename'], $m)) {
    return $none;
  }
  $display = 'chr' . $m[3];
  $result = array('name' => $display, 'length' => (int) $src['seqlen'], 'karyotype' => array());

  $params = array('org' => (int) $src['organism_id'], 'type' => (int) $src['type_id']);
  $holders = array();
  for ($i = 1; $i <= 10; $i++) {
    $params['n' . $i] = $m[1] . $m[2] . $i;
    $holders[] = ':n' . $i;
  }
  $rows = get_all_rows(make_query($DBConn, "
    SELECT uniquename, seqlen
    FROM chado.feature
    WHERE organism_id = :org AND type_id = :type
      AND uniquename IN (" . implode(', ', $holders) . ")", 1, $params));
  $by_number = array();
  foreach ((array) $rows as $row) {
    if (!preg_match('/[Cc]hr(\d+)$/', (string) $row['uniquename'], $mm)) { continue; }
    if ($row['seqlen'] === null || $row['seqlen'] === '') { continue; }
    $by_number[(int) $mm[1]] = (int) $row['seqlen'];
  }
  ksort($by_number);
  foreach ($by_number as $number => $length) {
    $result['karyotype'][] = array('chr' . $number, $length);
  }
  return $result;
}

function geneGeminiRecordNotFound($DBConn, $system, $requested) {
  http_response_code(404);
  header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');

  logMessage('gene_gemini_record_modern.php: no record for ' . $requested);

  $display = $requested;
  if (function_exists('mb_strlen') ? mb_strlen($display, 'UTF-8') > 80 : strlen($display) > 80) {
    $display = (function_exists('mb_substr') ? mb_substr($display, 0, 79, 'UTF-8') : substr($display, 0, 79)) . "\xE2\x80\xA6";
  }
  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $suggestions = geneSuggestions($DBConn, $requested);

  $blocks = '';

  if (count($suggestions['loci']) > 0) {
    $rows = '';
    foreach ($suggestions['loci'] as $item) {
      $models = '';
      foreach ($item['models'] as $model) {
        $models .= ($models === '' ? '' : ', ')
                 . '<a href="/gene_center/gene_gemini/' . rawurlencode($model['name']) . '">'
                 . $esc($model['name']) . '</a>';
      }
      $rows .= '<tr><th scope="row"><a href="/gene_center/gene_gemini/' . rawurlencode($item['name']) . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['full_name'] !== '' ? $esc($item['full_name']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($models !== '' ? $models : '<span class="mgdb-muted">None</span>') . '</td>'
             . '<td class="mgdb-sequence">' . (int) $item['id'] . '</td></tr>';
    }
    $blocks .= geneNotFoundBlock($esc($display) . ' as a classical gene symbol',
      count($suggestions['loci']),
      array('Symbol', 'Full name', 'Gene models', 'MaizeGDB ID'), $rows,
      '<p class="mgdb-rec-block-status">A classical gene is curated across assemblies; its gene '
      . 'models are the per-assembly annotations of it.</p>');
  }

  if (count($suggestions['models']) > 0) {
    $rows = '';
    foreach ($suggestions['models'] as $item) {
      $rows .= '<tr><th scope="row"><a href="/gene_center/gene_gemini/' . rawurlencode($item['name']) . '">'
             . $esc($item['name']) . '</a></th>'
             . '<td>' . ($item['assembly'] !== '' ? $esc($item['assembly']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['annotation'] !== '' ? $esc($item['annotation']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['chr'] !== '' ? $esc($item['chr']) : '<span class="mgdb-muted">Not recorded</span>') . '</td>'
             . '<td>' . ($item['locus'] !== '' ? $esc($item['locus']) : '<span class="mgdb-muted">None</span>') . '</td></tr>';
    }
    $blocks .= geneNotFoundBlock($esc($display) . ' in other annotations',
      count($suggestions['models']),
      array('Gene model', 'Assembly', 'Annotation', 'Chromosome', 'Classical gene'), $rows,
      '<p class="mgdb-rec-block-status">The same gene is annotated separately in each assembly, '
      . 'and B73 alone has seven of them.</p>');
  }

  $suggestion_sections = '';
  if ($blocks !== '') {
    $suggestion_sections =
        '<section id="gene-notfound-suggestions" aria-labelledby="gene-notfound-suggestions-title">'
      . '<div class="mgdb-section-heading"><div><h2 id="gene-notfound-suggestions-title">Suggestions</h2></div></div>'
      . $blocks . '</section>';
  }

  $bauplan = new Bauplan('MaizeGDB Gene: not found');
  $bauplan->modern();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
    ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $hub_file = $doc_root . '/css/mgdb-hub.css';
  $rec_css = $doc_root . '/css/mgdb-record.css';

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (file_exists($hub_file) ? filemtime($hub_file) : time()));
  $bauplan->includeCss('/css/mgdb-record.css?v=' . (file_exists($rec_css) ? filemtime($rec_css) : time()));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_gene_notfound.bau');
  $content->get('requested_identifier')->replace($esc($display));
  $content->get('gene_suggestions')->replace($suggestion_sections);

  $bauplan->publish();
  return true;
}
?>
