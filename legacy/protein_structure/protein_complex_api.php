<?php
/* JSON API for indexed AlphaFold maize protein-complex models. */

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=1800');
header('X-Content-Type-Options: nosniff');

function pcJson($payload, $status=200) {
  http_response_code($status);
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  $etag = '"' . sha1($json) . '"';
  header('ETag: ' . $etag);
  if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
  }
  echo $json;
  exit;
}

function pcClean($value, $max=120) {
  $value = trim(preg_replace('/\s+/u', ' ', (string)$value));
  if (function_exists('mb_substr')) return mb_substr($value, 0, $max, 'UTF-8');
  return substr($value, 0, $max);
}

function pcShard($value) {
  return substr(sha1(strtolower((string)$value)), 0, 2);
}

function pcReadJson($file) {
  if (!is_file($file)) return array();
  $data = json_decode(file_get_contents($file), true);
  return is_array($data) ? $data : array();
}

function pcDataRoot() {
  return dirname(__DIR__) . '/data/protein_complex';
}

function pcAlias($term) {
  $key = strtolower($term);
  $data = pcReadJson(pcDataRoot() . '/aliases/' . pcShard($key) . '.json');
  return isset($data[$key]) ? $data[$key] : null;
}

function pcResolveGeneRecord($term) {
  if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $term)) return null;
  $documentRoot = dirname(__DIR__);
  if (!isset($_SERVER['DOCUMENT_ROOT']) || $_SERVER['DOCUMENT_ROOT'] === '') {
    $_SERVER['DOCUMENT_ROOT'] = $documentRoot;
  }
  include_once($documentRoot . '/include/db-api.php');
  include_once($documentRoot . '/controllers/gene_center/gene_functions.php');
  $connection = connect_to_database();
  if (!$connection) return null;
  $record = check_id($term, $connection);
  return is_array($record) ? $record : null;
}

function pcRecord($id) {
  $data = pcReadJson(pcDataRoot() . '/records/' . pcShard(strtolower($id)) . '.json');
  return isset($data[$id]) ? $data[$id] : null;
}

function pcIsGeneModel($value) {
  return preg_match('/^Zm[0-9A-Za-z._-]+$/i', (string)$value) === 1;
}

function pcFindMonomer($uniprot, $genes) {
  if ($uniprot !== '') {
    return array(
      'available'=>true,
      'source'=>'AlphaFold DB',
      'uniprot'=>$uniprot,
      'pdb'=>'https://alphafold.ebi.ac.uk/files/AF-' . rawurlencode($uniprot) . '-F1-model_v6.pdb',
      'entry'=>'https://alphafold.ebi.ac.uk/entry/' . rawurlencode($uniprot),
    );
  }
  return array('available'=>false);
}

$action = strtolower(pcClean(isset($_GET['action']) ? $_GET['action'] : 'suggest', 20));
$term = pcClean(isset($_GET['term']) ? $_GET['term'] : '', 100);

if ($action === 'manifest') {
  pcJson(pcReadJson(pcDataRoot() . '/manifest.json'));
}

if ($action === 'suggest') {
  if ($term === '' || strlen($term) < 2) pcJson(array('query'=>$term, 'suggestions'=>array(), 'minimum'=>2));
  $needle = strtolower($term);
  $all = pcReadJson(pcDataRoot() . '/suggestions.json');
  $matches = array();
  foreach ($all as $item) {
    $label = isset($item['label']) ? $item['label'] : '';
    $haystack = strtolower(implode(' ', array_merge(array($label), $item['symbols'], $item['uniprots'], $item['gene_ids'])));
    $position = strpos($haystack, $needle);
    if ($position === false) continue;
    $labelLower = strtolower($label);
    $rank = $labelLower === $needle ? 0 : (strpos($labelLower, $needle) === 0 ? 1 : 2);
    $matches[] = array(
      'key'=>$item['key'], 'label'=>$label, 'symbols'=>$item['symbols'],
      'uniprots'=>$item['uniprots'], 'gene_ids'=>$item['gene_ids'],
      'monomer_count'=>isset($item['monomer_count']) ? $item['monomer_count'] : 0,
      'homo_count'=>$item['homo_count'], 'hetero_count'=>$item['hetero_count'],
      '_rank'=>$rank,
    );
  }
  usort($matches, function ($first, $second) {
    if ($first['_rank'] !== $second['_rank']) return $first['_rank'] - $second['_rank'];
    $firstCount = $first['monomer_count'] + $first['homo_count'] + $first['hetero_count'];
    $secondCount = $second['monomer_count'] + $second['homo_count'] + $second['hetero_count'];
    if ($firstCount !== $secondCount) return $secondCount - $firstCount;
    return strcasecmp($first['label'], $second['label']);
  });
  $matches = array_slice($matches, 0, 10);
  foreach ($matches as &$match) unset($match['_rank']);
  pcJson(array('query'=>$term, 'suggestions'=>$matches));
}

if ($action === 'lookup') {
  if ($term === '') pcJson(array('error'=>'Enter a maize gene model, locus, gene symbol, or UniProt accession.'), 400);
  $alias = pcAlias($term);
  $resolved = null;
  if (!$alias) {
    $resolved = pcResolveGeneRecord($term);
    if ($resolved && !empty($resolved['GM_NAME'])) $alias = pcAlias($resolved['GM_NAME']);
  }
  if (!$alias && !$resolved) pcJson(array('query'=>$term, 'found'=>false, 'monomer'=>array('available'=>false), 'monomers'=>array(), 'homodimers'=>array(), 'heterodimers'=>array()));
  if (!$alias && $resolved) {
    pcJson(array(
      'query'=>$term,
      'found'=>true,
      'gene_exists'=>true,
      'resolved_from'=>'MaizeGDB gene database',
      'identity'=>array(
        'label'=>!empty($resolved['LOCUS_NAME']) ? $resolved['LOCUS_NAME'] : $resolved['GM_NAME'],
        'symbols'=>!empty($resolved['LOCUS_NAME']) ? array($resolved['LOCUS_NAME']) : array(),
        'uniprots'=>array(),
        'gene_ids'=>!empty($resolved['GM_NAME']) ? array($resolved['GM_NAME']) : array(),
      ),
      'monomer'=>array('available'=>false),
      'monomers'=>array(),
      'homodimers'=>array(),
      'heterodimers'=>array(),
      'truncated'=>array('homodimers'=>false, 'heterodimers'=>false),
    ));
  }

  $homodimers = array();
  $heterodimers = array();
  $monomers = array();
  foreach (array_slice(isset($alias['monomer']) ? $alias['monomer'] : array(), 0, 100) as $id) {
    $record = pcRecord($id);
    if ($record) $monomers[] = $record;
  }
  foreach (array_slice($alias['homo'], 0, 50) as $id) {
    $record = pcRecord($id);
    if ($record) $homodimers[] = $record;
  }
  foreach (array_slice($alias['hetero'], 0, 100) as $id) {
    $record = pcRecord($id);
    if ($record) $heterodimers[] = $record;
  }
  usort($homodimers, function ($a, $b) {
    if ($a['display'] !== $b['display']) return $a['display'] ? -1 : 1;
    return ($b['metrics']['ipsae'] ?: 0) <=> ($a['metrics']['ipsae'] ?: 0);
  });
  usort($heterodimers, function ($a, $b) {
    if ($a['display'] !== $b['display']) return $a['display'] ? -1 : 1;
    return ($b['metrics']['ipsae'] ?: 0) <=> ($a['metrics']['ipsae'] ?: 0);
  });
  usort($monomers, function ($a, $b) {
    if ($a['reviewed'] !== $b['reviewed']) return $a['reviewed'] ? -1 : 1;
    return ($b['metrics']['plddt'] ?: 0) <=> ($a['metrics']['plddt'] ?: 0);
  });
  $uniprot = count($alias['uniprots']) ? $alias['uniprots'][0] : '';
  pcJson(array(
    'query'=>$term,
    'found'=>true,
    'gene_exists'=>true,
    'resolved_from'=>$resolved ? 'MaizeGDB gene database' : 'protein-complex index',
    'identity'=>$alias,
    'monomer'=>count($monomers) ? array('available'=>true, 'source'=>'AlphaFold DB', 'uniprot'=>$monomers[0]['partners'][0]['uniprot'], 'pdb'=>$monomers[0]['pdb'], 'entry'=>$monomers[0]['entry']) : pcFindMonomer($uniprot, $alias['gene_ids']),
    'monomers'=>$monomers,
    'homodimers'=>$homodimers,
    'heterodimers'=>$heterodimers,
    'truncated'=>array('monomers'=>count(isset($alias['monomer']) ? $alias['monomer'] : array()) > 100, 'homodimers'=>count($alias['homo']) > 50, 'heterodimers'=>count($alias['hetero']) > 100),
  ));
}

pcJson(array('error'=>'Unknown protein-complex API action.'), 400);
?>
