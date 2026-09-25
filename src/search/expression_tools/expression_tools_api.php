<?php
/* file: search/expression_tools/expression_tools_api.php
 *
 * purpose: the JSON endpoint behind Expression Tools (/expression/tools).
 *
 *            GET  ?action=genomes                       the genomes of the release
 *            GET  ?action=catalog&genome=               samples, studies, per-sample stats
 *            GET  ?action=search&genome=&q=             typeahead
 *            POST {action:resolve, genome, ids:[]}      identifiers -> genes
 *            POST {action:values, genome, assay, ids:[]}  values, aligned with the columns
 *            GET  ?action=gene&genome=&id=              one gene, for the gene report
 *            GET  ?action=interval&genome=&chr=&start=&end=&within=
 *            GET  ?action=coexpression&genome=&id=&s=&method=&n=&min=&center=
 *            POST {action:specific, genome, target, s, metric, direction, min, n}
 *            POST {action:contrast, genome, a, b}
 *            GET  ?action=variable&genome=&s=&n=&min=&center=
 *            GET  ?action=pangene&id=
 *            GET  ?action=landscape&... (format=tsv for the whole filtered list)
 *            GET  ?action=pairs&a=&b=&samples=&stat=
 *            GET  ?action=homeologs&s=
 *            GET  ?action=protein_global&genome=&sample=
 *            POST {action:enrich, genome, ids:[], background, aspects}
 *
 *          Sample selections (s, target, a, b) are sample ids of the assay,
 *          as ranges: "1-40,52". An empty selection is every usable sample.
 *
 *          The envelope is {ok: true, data, meta} or {ok: false, error}; the
 *          HTTP status carries the kind of failure. GET responses carry a
 *          strong ETag built from the release stamp, this code and the query,
 *          so a repeat request is a 304. Nothing here queries the database.
 *
 * history:
 *  09/24/26  claude  created
 */

$et_t0 = microtime(true);
include_once(__DIR__ . '/expression_tools_lib.php');

ini_set('display_errors', '0');
set_error_handler(function ($no, $msg, $file, $line) {
  if (!(error_reporting() & $no)) { return false; }
  throw new ErrorException($msg, 0, $no, $file, $line);
});

function etSend($status, $payload, $cacheable) {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  if ($cacheable && $status === 200) {
    header('Cache-Control: public, max-age=600');
  } else {
    header('Cache-Control: no-store');
  }
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
  exit;
}

/* GET parameters, overlaid by a JSON body on POST. */
function etRequest() {
  $p = $_GET;
  if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input', false, null, 0, 4 * 1024 * 1024);
    $body = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (is_array($body)) { $p = array_merge($p, $body); }
  }
  return $p;
}

function etStr($p, $k, $default = '', $max = 400) {
  if (!isset($p[$k])) { return $default; }
  if (is_array($p[$k])) { throw new EtError('Parameter ' . $k . ' must be a single value.'); }
  $v = trim((string) $p[$k]);
  if (strlen($v) > $max) { throw new EtError('Parameter ' . $k . ' is too long.'); }
  return $v;
}

function etInt($p, $k, $default, $min, $max) {
  $v = etStr($p, $k, '');
  if ($v === '') { return $default; }
  if (!preg_match('/^-?\d+$/', str_replace(',', '', $v))) { throw new EtError('Parameter ' . $k . ' must be a whole number.'); }
  return max($min, min($max, (int) str_replace(',', '', $v)));
}

function etNum($p, $k, $default) {
  $v = etStr($p, $k, '');
  if ($v === '') { return $default; }
  if (!is_numeric($v)) { throw new EtError('Parameter ' . $k . ' must be a number.'); }
  return (float) $v;
}

function etBool($p, $k) {
  return in_array(strtolower(etStr($p, $k, '')), array('1', 'true', 'yes', 'on'), true);
}

/* A list: a JSON array, or a string split on whitespace, commas and semicolons. */
function etList($p, $k, $max) {
  $v = isset($p[$k]) ? $p[$k] : array();
  if (is_string($v)) { $v = preg_split('/[\s,;]+/', $v); }
  if (!is_array($v)) { throw new EtError('Parameter ' . $k . ' must be a list.'); }
  $out = array();
  foreach ($v as $x) {
    if (!is_scalar($x)) { continue; }
    $x = trim((string) $x);
    if ($x !== '') { $out[$x] = true; }
  }
  if (count($out) > $max) { throw new EtError('Too many values in ' . $k . ' (at most ' . number_format($max) . ').'); }
  return array_keys($out);
}

function etAssay($p, $G) {
  $a = strtolower(etStr($p, 'assay', 'rna'));
  if (!in_array($a, array('rna', 'protein'), true) || !$G->hasAssay($a)) {
    throw new EtError('This genome has no ' . $a . ' data.', 404);
  }
  return $a;
}

/* The genome-wide passes are the expensive ones, so a result for the
   default selection is kept in the site's dashboard cache: the same gene
   report opened twice costs one pass. Keyed on everything that shapes it,
   the release stamp and this code among them. Any failure falls through to
   computing it live. */
function etCached($key, $builder) {
  static $system = null;
  if ($system === null) {
    $system = false;
    $gp = EtData::root() . '/include/gp_lib.php';
    $dc = EtData::root() . '/include/dashboard_cache.php';
    if (is_file($gp) && is_file($dc)) {
      try {
        include_once($gp);
        include_once($dc);
        if (function_exists('getSystemInfo') && function_exists('dashboardCache')) {
          $system = getSystemInfo('mgdb.conf');
        }
      } catch (Throwable $e) {
        $system = false;
      }
    }
  }
  if (!$system) { return $builder(); }
  $stamp = EtData::stamp() . '_' . (int) @filemtime(__DIR__ . '/expression_tools_lib.php');
  return dashboardCache($system, 'expression_tools/' . $stamp . '_' . md5($key), $builder);
}

/* Where a gene can be opened elsewhere. The qTeller, eFP and JBrowse URLs
   come from the Expression Data Hub's own builders, whose contracts were
   checked against each service (search/expression/expression_search_lib.php),
   so the hub and this page cannot disagree about which links have data. */
function etGeneLinks($G, $report) {
  $gene = $report['gene']['gene'];
  $links = array(
    'record' => '/gene_center/gene/' . rawurlencode($gene),
    'expression_api' => '/api/v1/data/expression/' . $G->name . '/' . rawurlencode($gene),
    'gene_model_api' => $G->name === ET_NAM_REFERENCE ? '/api/v1/data/gene-models/' . $G->name . '/' . rawurlencode($gene) : null,
    'pan_gene_record' => null, 'qteller' => null, 'efp' => null, 'jbrowse' => null
  );
  if (!empty($report['pangene']['exemplar_gene'])) {
    $links['pan_gene_record'] = '/pan_gene_center/pan_gene/' . rawurlencode($report['pangene']['exemplar_gene']);
  }
  $lib = EtData::root() . '/search/expression/expression_search_lib.php';
  if (is_file($lib)) {
    include_once($lib);
    if (function_exists('expressionQtellerUrl')) { $links['qteller'] = expressionQtellerUrl($G->name, $gene); }
    if (function_exists('expressionEfpUrl')) { $links['efp'] = expressionEfpUrl($G->name, $gene); }
    if (function_exists('expressionJbrowseUrl')) { $links['jbrowse'] = expressionJbrowseUrl($G->name, $gene); }
  }
  return $links;
}

try {
  $p = etRequest();
  $action = etStr($p, 'action', 'genomes', 40);
  $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
  /* A JSON GET is validated and cached; a download is neither. */
  $cacheable = $method === 'GET' && strtolower(etStr($p, 'format', '')) !== 'tsv';
  /* Sample ids a selection named that this release does not have: reported
     in meta, never silently dropped. */
  $unknown = array();

  if (EtData::manifest() === null) {
    throw new EtError('The Expression Tools release is not installed on this server.', 503);
  }

  /* A strong validator for every GET: the release, this code, the query. */
  if ($cacheable) {
    $etag = '"et-' . md5(EtData::stamp() . '|' . @filemtime(__DIR__ . '/expression_tools_lib.php') . '|' .
                         @filemtime(__FILE__) . '|' . (isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '')) . '"';
    header('ETag: ' . $etag);
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
      http_response_code(304);
      header('Cache-Control: public, max-age=600');
      exit;
    }
  }

  $meta = array();
  switch ($action) {

    case 'genomes':
      $data = etGenomesPayload();
      break;

    case 'catalog':
      $data = etCatalog(EtData::requireGenome(etStr($p, 'genome')));
      break;

    case 'search':
      $G = EtData::requireGenome(etStr($p, 'genome'));
      $data = array('results' => etSearch($G, etStr($p, 'q', '', 100), etInt($p, 'limit', 12, 1, 50)));
      break;

    case 'resolve':
      $G = EtData::requireGenome(etStr($p, 'genome'));
      $r = etResolve($G, etList($p, 'ids', 5000));
      $info = etGeneInfo($G, $r['rows']);
      $data = array('map' => (object) $r['map'], 'via' => (object) $r['via'], 'missing' => $r['missing'],
                    'ambiguous' => $r['ambiguous'], 'genes' => array_values($info));
      break;

    case 'values':
      $G = EtData::requireGenome(etStr($p, 'genome'));
      $a = etAssay($p, $G);
      $r = etResolve($G, etList($p, 'ids', 5000));
      if (count($r['rows']) > 5000) { throw new EtError('Too many genes: at most 5,000 once symbols are resolved.'); }
      $vals = etValues($G, $a, $r['rows']);
      $info = etGeneInfo($G, $r['rows']);
      $genes = array();
      foreach ($r['rows'] as $row) {
        if (!isset($info[$row])) { continue; }
        $genes[] = $info[$row] + array('values' => isset($vals[$row]) ? $vals[$row] : null);
      }
      $data = array('assay' => $a, 'genes' => $genes, 'map' => (object) $r['map'], 'missing' => $r['missing'],
                    'ambiguous' => $r['ambiguous']);
      break;

    case 'gene':
      $G = EtData::requireGenome(etStr($p, 'genome'));
      $id = etStr($p, 'id', '', 100);
      $row = etResolveOne($G, $id);
      $data = etGeneReport($G, $row);
      $data['links'] = etGeneLinks($G, $data);
      /* A symbol carried by several genes: the report is the first, and it
         says which others there are. */
      $all = etResolve($G, array($id));
      if (isset($all['map'][$id]) && count($all['map'][$id]) > 1) {
        $others = array_values(array_filter($all['map'][$id], function ($x) use ($row) { return $x !== $row; }));
        $data['ambiguous'] = array_values(etGeneInfo($G, $others));
      }
      break;

    case 'interval':
      $G = EtData::requireGenome(etStr($p, 'genome'));
      $data = etInterval($G, etStr($p, 'chr', '', 60), etInt($p, 'start', 1, 0, PHP_INT_MAX),
                         etInt($p, 'end', PHP_INT_MAX, 0, PHP_INT_MAX), etBool($p, 'within'), etInt($p, 'limit', 2000, 1, 5000));
      break;

    case 'coexpression':
      $G = EtData::requireGenome(etStr($p, 'genome'));
      $a = etAssay($p, $G);
      $row = etResolveOne($G, etStr($p, 'id', '', 100));
      $sel = etColumns($G, $a, etStr($p, 's', '', 20000));
      $unknown = array_merge($unknown, $sel['unknown']);
      $opts = array('method' => strtolower(etStr($p, 'method', 'pearson')), 'n' => etInt($p, 'n', 50, 1, 500),
                    'min' => etNum($p, 'min', 1.0), 'center' => etBool($p, 'center'));
      $build = function () use ($G, $a, $row, $sel, $opts) { return etCoexpression($G, $a, $row, $sel['cols'], $opts); };
      $data = $sel['default']
        ? etCached('coexp|' . $G->name . '|' . $a . '|' . $row . '|' . json_encode($opts), $build)
        : $build();
      $meta['samples'] = $sel['default'] ? 'default' : count($sel['cols']);
      break;

    case 'specific':
      $G = EtData::requireGenome(etStr($p, 'genome'));
      $a = etAssay($p, $G);
      $target = etColumns($G, $a, etStr($p, 'target', '', 20000));
      if ($target['default']) { throw new EtError('Choose the target samples.'); }
      $all = etColumns($G, $a, etStr($p, 's', '', 20000));
      $unknown = array_merge($unknown, $target['unknown'], $all['unknown']);
      $data = etSpecific($G, $a, $target['cols'], $all['cols'], array(
        'metric' => etStr($p, 'metric', 'specificity'), 'direction' => etStr($p, 'direction', 'up'),
        'min' => etNum($p, 'min', 5.0), 'n' => etInt($p, 'n', 200, 1, 2000)));
      break;

    case 'contrast':
      $G = EtData::requireGenome(etStr($p, 'genome'));
      $a = etAssay($p, $G);
      $A = etColumns($G, $a, etStr($p, 'a', '', 20000));
      $B = etColumns($G, $a, etStr($p, 'b', '', 20000));
      $unknown = array_merge($unknown, $A['unknown'], $B['unknown']);
      if ($A['default'] || $B['default']) { throw new EtError('Choose the samples of both groups.'); }
      $data = etContrast($G, $a, $A['cols'], $B['cols']);
      break;

    case 'variable':
      $G = EtData::requireGenome(etStr($p, 'genome'));
      $a = etAssay($p, $G);
      $sel = etColumns($G, $a, etStr($p, 's', '', 20000));
      $unknown = array_merge($unknown, $sel['unknown']);
      $opts = array('n' => etInt($p, 'n', 1000, 10, 5000), 'min' => etNum($p, 'min', 1.0), 'center' => etBool($p, 'center'));
      $build = function () use ($G, $a, $sel, $opts) { return etVariable($G, $a, $sel['cols'], $opts); };
      $data = $sel['default'] ? etCached('variable|' . $G->name . '|' . $a . '|' . json_encode($opts), $build) : $build();
      break;

    case 'pangene':
      $data = etPanGene(etStr($p, 'id', '', 100));
      break;

    case 'landscape':
      $f = array();
      foreach (array('class', 'chr', 'silent_in', 'low_in', 'expressed_in', 'present_in', 'absent_in', 'min_silent', 'min_expressed',
                     'min_present', 'min_conservation', 'max_conservation', 'q', 'present', 'expressed') as $k) {
        $f[$k] = etStr($p, $k, '', 400);
      }
      $format = strtolower(etStr($p, 'format', 'json'));
      if ($format === 'tsv') {
        /* Streamed row by row: the whole list is 83,000 records, which is
           too much to hold as PHP arrays before the first byte goes out. */
        header('Content-Type: text/tab-separated-values; charset=utf-8');
        header('Content-Disposition: attachment; filename="maizegdb_pangene_expression.tsv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "# MaizeGDB Expression Tools: pan-gene expression across the 26 NAM genomes, 23 shared samples\n");
        fputcsv($out, array('pan_gene', 'b73_gene', 'b73_symbol', 'chr', 'class', 'annotations', 'members', 'nam_present',
                            'nam_measured', 'nam_expressed', 'nam_silent', 'silent_in', 'absent_in', 'multi_copy_genomes',
                            'conservation_r', 'min_r', 'most_divergent', 'level_sd_log2', 'max_fpkm', 'tau'), "\t");
        etLandscape($f, etStr($p, 'sort', 'variation'), 1, 0, true, function ($r) use ($out) {
          fputcsv($out, array($r['name'], $r['b73'], $r['b73_symbol'], $r['chr'], $r['class'], $r['annotations'], $r['members'],
                              $r['present'], $r['measured'], $r['expressed'], $r['silent'], implode(',', $r['silent_in']),
                              implode(',', $r['absent_in']), $r['multi_copy'], $r['conservation'], $r['min_r'], $r['divergent'],
                              $r['level_sd'], $r['max'], $r['tau']), "\t");
        });
        fclose($out);
        exit;
      }
      $data = etLandscape($f, etStr($p, 'sort', 'variation'), etInt($p, 'limit', 50, 1, 500), etInt($p, 'offset', 0, 0, 1000000));
      break;

    case 'pairs':
      $samples = array();
      foreach (etList($p, 'samples', 23) as $x) { if (ctype_digit($x)) { $samples[] = (int) $x; } }
      $stat = etStr($p, 'stat', 'mean');
      $ka = etStr($p, 'a', 'B73v5'); $kb = etStr($p, 'b', 'Oh7B');
      $build = function () use ($ka, $kb, $samples, $stat) { return etPairs($ka, $kb, $samples, $stat); };
      $data = etCached('pairs|' . $ka . '|' . $kb . '|' . implode(',', $samples) . '|' . $stat, $build);
      break;

    case 'homeologs':
      $G = EtData::requireGenome('B73v5');
      $sel = etColumns($G, 'rna', etStr($p, 's', '', 20000));
      $unknown = array_merge($unknown, $sel['unknown']);
      $build = function () use ($G, $sel) { return etHomeologs($G, $sel['cols']); };
      $data = $sel['default'] ? etCached('homeologs|' . $G->name, $build) : $build();
      break;

    case 'protein_global':
      $G = EtData::requireGenome(etStr($p, 'genome'));
      $data = etProteinGlobal($G, etInt($p, 'sample', 0, 0, 100000));
      break;

    case 'enrich':
      $G = EtData::requireGenome(etStr($p, 'genome'));
      $r = etResolve($G, etList($p, 'ids', 5000));
      $data = etEnrich($G, $r['rows'], array(
        'background' => etStr($p, 'background', 'annotated'), 'aspects' => etStr($p, 'aspects', 'PFC', 3),
        'min_size' => etInt($p, 'min_size', 5, 1, 1000), 'max_size' => etInt($p, 'max_size', 2000, 5, 100000),
        'limit' => etInt($p, 'limit', 200, 1, 2000)));
      $data['missing'] = $r['missing'];
      break;

    default:
      throw new EtError('Unknown action.', 404);
  }

  if ($unknown) { $meta['unknown_samples'] = array_values(array_unique($unknown)); }
  $meta['elapsed_ms'] = (int) round((microtime(true) - $et_t0) * 1000);
  $meta['release'] = EtData::manifest()['generated'];
  etSend(200, array('ok' => true, 'data' => $data, 'meta' => $meta), $cacheable);

} catch (EtError $e) {
  etSend($e->status, array('ok' => false, 'error' => $e->getMessage()), false);
} catch (Throwable $e) {
  /* Logged in full; the reader gets no path, line or trace. */
  error_log('[expression_tools] ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
  etSend(500, array('ok' => false, 'error' => 'The expression tools could not answer that request. It has been logged.'), false);
}
