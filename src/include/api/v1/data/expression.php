<?php
/* file: api/v1/data/expression.php
 *
 * purpose: the expression dataset of the v1 API -- qTeller's RNA and
 *          protein abundance profiles, served from the release files
 *          tools/expression_index.py writes under data/expression/.
 *
 *          Routes (all GET; $api_rest holds the segments after the dataset):
 *            /api/v1/data/expression                       genomes with a release
 *            /api/v1/data/expression/{genome}              the release manifest
 *            /api/v1/data/expression/{genome}/samples      the sample catalogue
 *            /api/v1/data/expression/{genome}/{id}         one gene's profile
 *            /api/v1/data/expression/{genome}/batch?ids=   up to 200 genes
 *
 *          A profile carries three sections: summary (per assay: detection,
 *          mean, median, maximum, tau, the top samples, and the same figures
 *          per tissue reading and per study), samples (every value with its
 *          sample, study and tissue), and sources (the studies with links).
 *
 *          The identifier is the genome's own gene id. For a genome with a
 *          gene-models release a transcript or protein id, or anything the
 *          gene record resolves, reaches the gene the same way the other
 *          datasets do.
 *
 * history:
 *  09/12/26  claude  created
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

  include_once('./include/api/v1/lib/mgdb_expression.php');

  $EX_DATASET = 'expression';
  $EX_SECTIONS = array('summary', 'samples', 'sources');
  $ex_base = MgdbApi::baseUrl();

  /////
  // GET /api/v1/data/expression
  /////

  if (count($api_rest) === 0) {
    $genomes = array();
    foreach (MgdbData::genomes($EX_DATASET) as $name => $manifest) {
      $s = MgdbData::manifestSummary($manifest);
      $s['assays'] = isset($manifest['assays']) ? $manifest['assays'] : array();
      $s['studies'] = isset($manifest['studies']) ? count($manifest['studies']) : null;
      $s['links'] = array(
        'self' => $ex_base . '/api/v1/data/expression/' . $name,
        'samples' => $ex_base . '/api/v1/data/expression/' . $name . '/samples'
      );
      $genomes[] = $s;
    }
    MgdbApi::sendData(
      array('type' => 'dataset', 'id' => $EX_DATASET,
            'attributes' => api_data_summary($api_entry),
            'sections' => array('genomes' => $genomes)),
      array('openapi' => $ex_base . '/api/v1/openapi'),
      MgdbData::fileReadsMeta(array('dataset' => $EX_DATASET, 'genome_count' => count($genomes))),
      3600);
  }

  $ex_rest = array_slice($api_rest, 1);
  list($ex_genome, $ex_manifest) = MgdbData::resolveGenome($EX_DATASET, $api_rest[0], $ex_rest);

  /////
  // GET /api/v1/data/expression/{genome}
  /////

  if (count($ex_rest) === 0) {
    $attributes = $ex_manifest;
    unset($attributes['disagreements']);
    MgdbApi::sendData(
      array('type' => 'expression-release', 'id' => $ex_genome,
            'attributes' => $attributes,
            'sections' => array('disagreements' => isset($ex_manifest['disagreements']) ? $ex_manifest['disagreements'] : array())),
      array('dataset' => $ex_base . '/api/v1/data/expression',
            'samples' => $ex_base . '/api/v1/data/expression/' . $ex_genome . '/samples',
            'example' => $ex_base . '/api/v1/data/expression/' . $ex_genome . '/' . rawurlencode($api_entry['example']['id']),
            'index' => $ex_base . '/data/expression/' . $ex_genome . '/index.json'),
      MgdbData::fileReadsMeta(MgdbData::meta($EX_DATASET, $ex_genome, $ex_manifest)),
      3600);
  }

  $ex_action = strtolower($ex_rest[0]);
  if ($ex_action === 'samples') {
    ex_samples($ex_genome, $ex_manifest);
  } elseif ($ex_action === 'batch') {
    ex_batch($ex_genome, $ex_manifest, $EX_SECTIONS);
  } else {
    ex_one($ex_genome, $ex_manifest, implode('/', $ex_rest), $EX_SECTIONS);
  }
  return;

/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function ex_assay_param($genome) {
  $raw = strtolower(MgdbApi::query('assay', 'all'));
  if ($raw === '') { $raw = 'all'; }
  $known = array_merge(array('all'), MgdbExpression::assays($genome));
  if (!in_array($raw, $known, true)) {
    MgdbApi::problem(400, 'invalid-assay', 'Invalid assay', 'assay must be one of ' . implode(', ', $known) . '.',
      array('available_assays' => $known));
  }
  return $raw;
}//ex_assay_param

/* The gene id as this release spells it: exact, case-insensitive, or through
   the gene-models release and the resolver for a symbol, transcript or
   protein. */
function ex_resolve($genome, $manifest, $id, &$resolved) {
  $resolved = array('from' => null, 'as' => 'gene', 'queries' => 0, 'hint' => null);
  if (MgdbExpression::exists($genome, $id)) { return $id; }
  $exact = MgdbExpression::resolveCase($genome, $id);
  if ($exact !== null) { $resolved['from'] = $id; $resolved['as'] = 'gene'; return $exact; }
  if (MgdbData::manifest('gene-models', $genome) !== null) {
    $gres = null;
    $g = MgdbData::gene($genome, $id, $gres, isset($manifest['assembly']) ? $manifest['assembly'] : null, true);
    $resolved['queries'] = $gres['queries'];
    $resolved['hint'] = $gres['hint'];
    if ($g !== null) {
      $resolved['from'] = $id;
      $resolved['as'] = $gres['from'] === null ? 'gene' : $gres['as'];
      if (MgdbExpression::exists($genome, $g['id'])) { return $g['id']; }
      $resolved['hint'] = 'The identifier resolves to ' . $g['id'] . ', which has no expression profile in this release.';
    }
  }
  return null;
}//ex_resolve

/////
// GET /api/v1/data/expression/{genome}/{id}
/////

function ex_one($genome, $manifest, $rawId, $available) {
  $id = MgdbApi::identifier($rawId);
  $format = MgdbData::format(array('json', 'tsv'));
  $assay = ex_assay_param($genome);
  $wanted = MgdbApi::sections($available);
  $sourceFilter = MgdbData::listParam('source');

  $resolved = null;
  $gene = ex_resolve($genome, $manifest, $id, $resolved);
  if ($gene === null) {
    MgdbApi::problem(404, 'expression-not-found', 'No expression profile',
      'No gene in this expression release matches that identifier.',
      array('identifier' => $id, 'genome' => $genome, 'hint' => $resolved['hint']));
  }
  $profile = MgdbExpression::profile($genome, $gene, $assay, array('summary', 'samples', 'sources'));
  if ($profile === null) {
    MgdbApi::problem(404, 'expression-not-found', 'No expression profile', 'The gene has no profile for that assay.',
      array('identifier' => $id, 'assay' => $assay));
  }
  if ($sourceFilter !== null) {
    $profile['sections']['samples'] = array_values(array_filter($profile['sections']['samples'], function ($s) use ($sourceFilter) {
      $name = strtolower((string) $s['source']);
      foreach ($sourceFilter as $f) { if (strpos($name, $f) !== false) { return true; } }
      return false;
    }));
  }
  if ($format === 'tsv') {
    MgdbApi::sendText(MgdbData::tsv(MgdbExpression::tsvColumns(), MgdbExpression::tsvRows($profile)),
      'text/tab-separated-values; charset=utf-8', 86400, $gene . '.expression.tsv');
  }
  $sections = array();
  foreach ($wanted as $key) {
    if (isset($profile['sections'][$key])) { $sections[$key] = $profile['sections'][$key]; }
  }
  $meta = MgdbData::meta('expression', $genome, $manifest, array(
    'sections_returned' => $wanted, 'sections_available' => $available, 'assay' => $assay,
    'source_filter' => $sourceFilter,
    'counts' => array('samples' => count($profile['sections']['samples']), 'sources' => count($profile['sections']['sources']))
  ));
  if ($resolved['from'] !== null) {
    $meta['resolved_from'] = $resolved['from'];
    $meta['resolved_as'] = $resolved['as'];
  }
  $links = $profile['links'];
  $links['gene_model'] = MgdbData::manifest('gene-models', $genome) !== null
    ? MgdbApi::baseUrl() . '/api/v1/data/gene-models/' . $genome . '/' . rawurlencode($gene) : null;
  $links['record'] = MgdbApi::baseUrl() . '/api/v1/records/gene/' . rawurlencode($gene);
  $links['html'] = MgdbApi::baseUrl() . '/gene_center/gene/' . rawurlencode($gene);
  $links['samples'] = MgdbApi::baseUrl() . '/api/v1/data/expression/' . $genome . '/samples';
  MgdbApi::sendData(
    array('type' => 'expression_profile', 'id' => $gene, 'attributes' => $profile['attributes'],
          'sections' => count($sections) > 0 ? $sections : new stdClass()),
    $links, MgdbData::fileReadsMeta($meta), 86400);
}//ex_one

/////
// GET /api/v1/data/expression/{genome}/samples
/////

function ex_samples($genome, $manifest) {
  $format = MgdbData::format(array('json', 'tsv'));
  $assay = ex_assay_param($genome);
  $catalog = MgdbExpression::catalog($genome);
  $samples = array();
  foreach ($catalog['samples'] as $s) {
    if ($assay !== 'all' && $s['assay'] !== $assay) { continue; }
    $samples[] = array('id' => $s['id'], 'assay' => $s['assay'], 'label' => $s['label'], 'stub' => $s['stub'],
                       'source' => $s['source'], 'source_id' => $s['source_id'], 'tissue' => $s['tissue'],
                       'condition' => $s['condition']);
  }
  $sources = array();
  foreach ($catalog['sources'] as $src) {
    if ($assay !== 'all' && $src['assay'] !== $assay) { continue; }
    $sources[] = $src;
  }
  if ($format === 'tsv') {
    MgdbApi::sendText(MgdbData::tsv(array('id', 'assay', 'label', 'stub', 'source', 'tissue', 'condition'), $samples),
      'text/tab-separated-values; charset=utf-8', 86400, $genome . '.samples.tsv');
  }
  MgdbApi::sendData(
    array('type' => 'expression_samples', 'id' => $genome,
          'attributes' => array('genome' => $genome, 'assay' => $assay, 'sample_count' => count($samples), 'source_count' => count($sources),
                                'units_note' => isset($manifest['units_note']) ? $manifest['units_note'] : null,
                                'tissue_note' => isset($manifest['tissue_note']) ? $manifest['tissue_note'] : null,
                                'condition_note' => isset($manifest['condition_note']) ? $manifest['condition_note'] : null),
          'sections' => array('samples' => $samples, 'sources' => $sources)),
    array('release' => MgdbApi::baseUrl() . '/api/v1/data/expression/' . $genome),
    MgdbData::fileReadsMeta(MgdbData::meta('expression', $genome, $manifest, array('assay' => $assay))), 86400);
}//ex_samples

/////
// GET /api/v1/data/expression/{genome}/batch?ids=
/////

function ex_batch($genome, $manifest, $available) {
  $ids = MgdbData::ids();
  $format = MgdbData::format(array('json', 'tsv'));
  $assay = ex_assay_param($genome);
  /* A batch defaults to the summaries: values for 200 genes across 300
     samples is a table, and the TSV form is there for that. */
  $raw = MgdbApi::query('fields', '');
  $wanted = $raw === '' ? array('summary') : MgdbApi::sections($available);

  $found = array();
  $missing = array();
  $resolvedMap = array();
  foreach ($ids as $id) {
    $resolved = null;
    $gene = ex_resolve($genome, $manifest, $id, $resolved);
    if ($gene === null) { $missing[] = $id; continue; }
    if (strtolower($id) !== strtolower($gene)) { $resolvedMap[$id] = $gene; }
    $p = MgdbExpression::profile($genome, $gene, $assay, $format === 'tsv' ? array('samples') : $wanted);
    if ($p === null) { $missing[] = $id; continue; }
    $found[] = $p;
  }
  if ($format === 'tsv') {
    $rows = array();
    foreach ($found as $p) { foreach (MgdbExpression::tsvRows($p) as $r) { $rows[] = $r; } }
    MgdbApi::sendText(MgdbData::tsv(MgdbExpression::tsvColumns(), $rows), 'text/tab-separated-values; charset=utf-8', 3600, 'expression.tsv');
  }
  $data = array();
  foreach ($found as $p) {
    $data[] = array('type' => 'expression_profile', 'id' => $p['id'], 'attributes' => $p['attributes'],
                    'sections' => count($p['sections']) > 0 ? $p['sections'] : new stdClass(),
                    'links' => array('self' => $p['links']['api']));
  }
  $meta = MgdbData::meta('expression', $genome, $manifest, array(
    'requested' => count($ids), 'returned' => count($data), 'missing' => $missing,
    'resolved' => count($resolvedMap) > 0 ? $resolvedMap : new stdClass(),
    'sections_returned' => $wanted, 'sections_available' => $available, 'assay' => $assay,
    'max_ids' => MgdbData::MAX_IDS
  ));
  MgdbApi::sendData($data, array('tsv' => MgdbApi::selfUrl() . '&format=tsv'), MgdbData::fileReadsMeta($meta), 3600);
}//ex_batch
?>
