<?php
/* file: api/v1/data/domains.php
 *
 * purpose: the domains dataset of the v1 API -- InterProScan results per
 *          protein, served from the release files tools/domains_index.py
 *          writes under data/domains/.
 *
 *          Routes (all GET; $api_rest holds the segments after the dataset):
 *            /api/v1/data/domains                          genomes with a release
 *            /api/v1/data/domains/{genome}                 the release manifest
 *            /api/v1/data/domains/{genome}/{id}            one protein; a transcript
 *                                                          gives its protein, a gene
 *                                                          its canonical protein
 *            /api/v1/data/domains/{genome}/entry/{accession}   every protein
 *                                                          carrying an InterPro
 *                                                          entry or a member
 *                                                          signature
 *            /api/v1/data/domains/{genome}/region/{seq}:{start}-{end}
 *                                                          canonical-protein domains
 *                                                          projected onto the genome
 *            /api/v1/data/domains/{genome}/batch?ids=      up to 200 proteins
 *            /api/v1/data/domains/{genome}/class           the domain atlas's
 *                                                          functional classes
 *            /api/v1/data/domains/{genome}/class/{name}    every gene in one
 *                                                          (a slug or the name)
 *            /api/v1/data/domains/{genome}/immunity        the immunity calls
 *            /api/v1/data/domains/{genome}/immunity/{class}[?subclass=]
 *                                                          every gene called one
 *
 *          Five readings of the same rows: matches (raw member-database
 *          hits), entries (matches collapsed onto InterPro entries; draw
 *          these), sites (residue-level), go and pathways (the InterPro2GO
 *          and pathway columns), and genomic (the projection). Plus classes
 *          and immunity from the domain atlas where it covers the genome.
 *
 *          A protein the annotation has but InterProScan matched nothing on
 *          is a real answer, not a miss: it is synthesized from the
 *          gene-models release with empty sections and no_matches = true.
 *
 * history:
 *  09/12/26  claude  created
 *  09/17/26  claude  class and immunity member lists, from the
 *                    atlas_members/ extract tools/atlas_classes.py writes
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

  $DM_DATASET = 'domains';
  $DM_SECTIONS = array('matches', 'entries', 'sites', 'go', 'pathways', 'genomic', 'classes');
  $dm_base = MgdbApi::baseUrl();

  /////
  // GET /api/v1/data/domains
  /////

  if (count($api_rest) === 0) {
    $genomes = array();
    foreach (MgdbData::genomes($DM_DATASET) as $name => $manifest) {
      $s = MgdbData::manifestSummary($manifest);
      $s['links'] = array(
        'self' => $dm_base . '/api/v1/data/domains/' . $name,
        'example' => $dm_base . '/api/v1/data/domains/' . $name . '/' . rawurlencode($api_entry['example']['id'])
      );
      $genomes[] = $s;
    }
    MgdbApi::sendData(
      array('type' => 'dataset', 'id' => $DM_DATASET,
            'attributes' => api_data_summary($api_entry),
            'sections' => array('genomes' => $genomes)),
      array('openapi' => $dm_base . '/api/v1/openapi'),
      MgdbData::fileReadsMeta(array('dataset' => $DM_DATASET, 'genome_count' => count($genomes))),
      3600);
  }

  $dm_rest = array_slice($api_rest, 1);
  list($dm_genome, $dm_manifest) = MgdbData::resolveGenome($DM_DATASET, $api_rest[0], $dm_rest);

  /////
  // GET /api/v1/data/domains/{genome}
  /////

  if (count($dm_rest) === 0) {
    $attributes = $dm_manifest;
    unset($attributes['sequences'], $attributes['disagreements']);
    MgdbApi::sendData(
      array('type' => 'domain-release', 'id' => $dm_genome,
            'attributes' => $attributes,
            'sections' => array(
              'disagreements' => isset($dm_manifest['disagreements']) ? $dm_manifest['disagreements'] : array()
            )),
      array('dataset' => $dm_base . '/api/v1/data/domains',
            'gene_models' => $dm_base . '/api/v1/data/gene-models/' . $dm_genome,
            'example' => $dm_base . '/api/v1/data/domains/' . $dm_genome . '/' . rawurlencode($api_entry['example']['id']),
            'index' => $dm_base . '/data/domains/' . $dm_genome . '/index.json'),
      MgdbData::fileReadsMeta(MgdbData::meta($DM_DATASET, $dm_genome, $dm_manifest)),
      3600);
  }

  $dm_action = strtolower($dm_rest[0]);
  if ($dm_action === 'entry') {
    dm_entry($dm_genome, $dm_manifest, implode('/', array_slice($dm_rest, 1)));
  } elseif ($dm_action === 'region') {
    dm_region($dm_genome, $dm_manifest, implode('/', array_slice($dm_rest, 1)));
  } elseif ($dm_action === 'batch') {
    dm_batch($dm_genome, $dm_manifest, $DM_SECTIONS);
  } elseif ($dm_action === 'class') {
    dm_class($dm_genome, $dm_manifest, implode('/', array_slice($dm_rest, 1)));
  } elseif ($dm_action === 'immunity') {
    dm_immunity($dm_genome, $dm_manifest, implode('/', array_slice($dm_rest, 1)));
  } else {
    dm_one($dm_genome, $dm_manifest, implode('/', $dm_rest), $DM_SECTIONS);
  }
  return;

/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* One protein payload for an identifier: the protein itself, a transcript's
   protein, or a gene's canonical protein; anything else through the gene
   resolver. A protein with no InterProScan match is synthesized from the
   gene-models release. Returns null when nothing matches; the gene, when the
   identifier reached one with no protein, is returned in $resolved['gene']. */
function dm_lookup($genome, $manifest, $id, &$resolved) {
  $resolved = array('from' => null, 'as' => 'protein', 'queries' => 0, 'hint' => null, 'gene' => null);
  $p = MgdbData::shardEntry('domains', $genome, 'proteins', $id, MgdbData::GENE_SHARD_DEPTH);
  if ($p !== null) { return $p; }

  $gres = null;
  $g = MgdbData::gene($genome, $id, $gres, isset($manifest['assembly']) ? $manifest['assembly'] : null, true);
  $resolved['queries'] = $gres['queries'];
  $resolved['hint'] = $gres['hint'];
  if ($g === null) { return null; }
  $resolved['gene'] = $g;

  $t = null;
  if ($gres['as'] === 'transcript' || $gres['as'] === 'protein') {
    $t = MgdbData::transcriptOf($g, $id);
    $resolved['as'] = $gres['as'];
  } else {
    $t = MgdbData::canonicalTranscript($g);
    $resolved['as'] = ($gres['from'] === null) ? 'gene' : $gres['as'];
  }
  $resolved['from'] = $id;
  if ($t === null || !isset($t['protein']['id'])) { return null; }

  $protein = $t['protein']['id'];
  $p = MgdbData::shardEntry('domains', $genome, 'proteins', $protein, MgdbData::GENE_SHARD_DEPTH);
  if ($p !== null) { return $p; }
  return array(
    'id' => $protein, 'gene' => $g['id'], 'transcript' => $t['id'], 'canonical' => !empty($t['canonical']),
    'length_aa' => $t['protein']['length_aa'], 'md5' => null, 'symbol' => isset($g['symbol']) ? $g['symbol'] : null,
    'architecture' => null, 'no_matches' => true,
    'matches' => array(), 'entries' => array(), 'sites' => array(), 'go' => array(), 'pathways' => array(),
    'genomic' => array('sequence' => $g['seq'], 'strand' => $g['strand'], 'transcript' => $t['id'], 'domains' => array()),
    'classes' => array(), 'immunity' => null
  );
}//dm_lookup

function dm_filter_analysis($p, $analyses) {
  if ($analyses === null) { return $p; }
  $p['matches'] = array_values(array_filter($p['matches'], function ($m) use ($analyses) {
    return in_array(strtolower($m['analysis']), $analyses, true);
  }));
  $p['entries'] = array_values(array_filter($p['entries'], function ($e) use ($analyses) {
    foreach ($e['members'] as $m) { if (in_array(strtolower($m['analysis']), $analyses, true)) { return true; } }
    return false;
  }));
  return $p;
}//dm_filter_analysis

function dm_attributes($p) {
  return array(
    'name' => $p['id'],
    'gene' => $p['gene'],
    'transcript' => $p['transcript'],
    'canonical' => !empty($p['canonical']),
    'length_aa' => isset($p['length_aa']) ? $p['length_aa'] : null,
    'md5' => isset($p['md5']) ? $p['md5'] : null,
    'symbol' => isset($p['symbol']) ? $p['symbol'] : null,
    'architecture' => isset($p['architecture']) ? $p['architecture'] : null,
    'match_count' => count($p['matches']),
    'entry_count' => count($p['entries']),
    'site_count' => count($p['sites']),
    'no_matches' => !empty($p['no_matches'])
  );
}//dm_attributes

function dm_sections($p, $wanted) {
  $sections = array();
  foreach (array('matches', 'entries', 'sites', 'go', 'pathways', 'genomic') as $key) {
    if (in_array($key, $wanted, true)) { $sections[$key] = $p[$key]; }
  }
  if (in_array('classes', $wanted, true)) {
    $sections['classes'] = array(
      'functional' => isset($p['classes']) ? $p['classes'] : array(),
      'immunity' => isset($p['immunity']) ? $p['immunity'] : null,
      'note' => 'Functional classes are inclusive: a gene is listed under every class whose InterPro set it carries. The immunity call is exclusive, by domain-architecture precedence.'
    );
  }
  return $sections;
}//dm_sections

function dm_links($base, $genome, $p, $g) {
  $links = array(
    'self' => $base . '/api/v1/data/domains/' . $genome . '/' . rawurlencode($p['id']),
    'gene_model' => $base . '/api/v1/data/gene-models/' . $genome . '/' . rawurlencode($p['gene']),
    'record' => $base . '/api/v1/records/gene/' . rawurlencode($p['gene']),
    'html' => $base . '/gene_center/gene/' . rawurlencode($p['gene']),
    'tsv' => $base . '/api/v1/data/domains/' . $genome . '/' . rawurlencode($p['id']) . '?format=tsv',
    'isoforms' => array()
  );
  if ($g !== null) {
    foreach ((isset($g['transcripts']) ? $g['transcripts'] : array()) as $t) {
      if (isset($t['protein']['id']) && $t['protein']['id'] !== $p['id']) {
        $links['isoforms'][] = $base . '/api/v1/data/domains/' . $genome . '/' . rawurlencode($t['protein']['id']);
      }
    }
  }
  return $links;
}//dm_links

/* InterProScan's own column order, one row per match. */
function dm_tsv_rows($p) {
  $rows = array();
  foreach ($p['matches'] as $m) {
    $rows[] = array(
      'protein' => $p['id'], 'md5' => isset($p['md5']) ? $p['md5'] : '', 'length' => $p['length_aa'],
      'analysis' => $m['analysis'], 'accession' => $m['accession'], 'description' => $m['name'],
      'start' => $m['start'], 'end' => $m['end'],
      'score' => $m['evalue'] !== null ? $m['evalue'] : $m['score'], 'status' => $m['status'], 'date' => '',
      'entry' => $m['entry'], 'entry_name' => $m['entry_name'],
      'go' => implode('|', array_map(function ($g) { return $g['id']; }, $p['go'])),
      'pathways' => implode('|', $p['pathways'])
    );
  }
  return $rows;
}//dm_tsv_rows

function dm_tsv_columns() {
  return array('protein', 'md5', 'length', 'analysis', 'accession', 'description', 'start', 'end', 'score', 'status', 'date', 'entry', 'entry_name', 'go', 'pathways');
}//dm_tsv_columns

/////
// GET /api/v1/data/domains/{genome}/{id}
/////

function dm_one($genome, $manifest, $rawId, $available) {
  $base = MgdbApi::baseUrl();
  $id = MgdbApi::identifier($rawId);
  $format = MgdbData::format(array('json', 'tsv'));
  $analyses = MgdbData::listParam('analysis');
  $wanted = MgdbApi::sections($available);

  $resolved = null;
  $p = dm_lookup($genome, $manifest, $id, $resolved);
  if ($p === null) {
    if ($resolved['gene'] !== null) {
      MgdbApi::problem(404, 'protein-not-found', 'No protein for that identifier',
        'The identifier reached gene ' . $resolved['gene']['id'] . ', which has no protein in this annotation.',
        array('identifier' => $id, 'gene' => $resolved['gene']['id'],
              'biotype' => isset($resolved['gene']['biotype']) ? $resolved['gene']['biotype'] : null));
    }
    MgdbApi::problem(404, 'protein-not-found', 'Protein not found',
      'No protein, transcript or gene in this release matches that identifier.',
      array('identifier' => $id, 'genome' => $genome, 'hint' => $resolved['hint']));
  }
  $p = dm_filter_analysis($p, $analyses);

  if ($format === 'tsv') {
    MgdbApi::sendText(MgdbData::tsv(dm_tsv_columns(), dm_tsv_rows($p)), 'text/tab-separated-values; charset=utf-8', 86400, $p['id'] . '.interproscan.tsv');
  }

  $g = MgdbData::shardEntry('gene-models', $genome, 'genes', $p['gene'], MgdbData::GENE_SHARD_DEPTH);
  $meta = MgdbData::meta('domains', $genome, $manifest, array(
    'sections_returned' => $wanted, 'sections_available' => $available,
    'analysis' => $analyses,
    'counts' => array('matches' => count($p['matches']), 'entries' => count($p['entries']), 'sites' => count($p['sites']),
                      'go' => count($p['go']), 'pathways' => count($p['pathways']))
  ));
  if ($resolved['from'] !== null && strtolower($resolved['from']) !== strtolower($p['id'])) {
    $meta['resolved_from'] = $resolved['from'];
    $meta['resolved_as'] = $resolved['as'];
  }
  $sections = dm_sections($p, $wanted);
  MgdbApi::sendData(
    array('type' => 'protein_domains', 'id' => $p['id'], 'attributes' => dm_attributes($p),
          'sections' => count($sections) > 0 ? $sections : new stdClass()),
    dm_links($base, $genome, $p, $g), MgdbData::fileReadsMeta($meta), 86400);
}//dm_one

/////
// GET /api/v1/data/domains/{genome}/entry/{accession}
/////

function dm_entry($genome, $manifest, $rawAcc) {
  $base = MgdbApi::baseUrl();
  $acc = MgdbApi::identifier($rawAcc);
  $format = MgdbData::format(array('json', 'tsv'));
  $isoforms = strtolower(MgdbApi::query('isoforms', 'canonical'));
  if ($isoforms === '') { $isoforms = 'canonical'; }
  if (!in_array($isoforms, array('canonical', 'all'), true)) {
    MgdbApi::problem(400, 'invalid-isoforms', 'Invalid isoforms', 'isoforms must be canonical or all.');
  }
  $limit = MgdbData::intParam('limit', 500, 1, 500);
  $offset = MgdbData::intParam('offset', 0, 0, 100000000);

  $doc = MgdbData::namedFile('domains', $genome, 'entries', MgdbData::entryKey($acc));
  if ($doc === null) {
    MgdbApi::problem(404, 'unknown-entry', 'Unknown entry',
      'No InterPro entry or member-database signature by that accession in this release.',
      array('accession' => $acc, 'analyses' => array_map(function ($a) { return $a['name']; },
            isset($manifest['analyses']) ? $manifest['analyses'] : array())));
  }
  $rows = $doc['proteins'];
  if ($isoforms === 'canonical') {
    $rows = array_values(array_filter($rows, function ($r) { return !empty($r['canonical']); }));
  }
  list($page, $paging, $next) = MgdbData::page($rows, $limit, $offset);

  if ($format === 'tsv') {
    MgdbApi::sendText(MgdbData::tsv(array('protein', 'gene', 'symbol', 'transcript', 'canonical', 'length_aa', 'start', 'end',
      'evalue', 'score', 'sequence', 'gene_start', 'gene_end', 'strand'), $page),
      'text/tab-separated-values; charset=utf-8', 86400, MgdbData::entryKey($acc) . '.tsv');
  }
  $attributes = $doc;
  unset($attributes['proteins']);
  $meta = MgdbData::meta('domains', $genome, $manifest, array_merge(array('isoforms' => $isoforms), $paging));
  MgdbApi::sendData(
    array('type' => 'domain_entry', 'id' => $doc['accession'], 'attributes' => $attributes,
          'sections' => array('proteins' => $page)),
    array('next' => $next, 'interpro' => isset($doc['url']) ? $doc['url'] : null,
          'tsv' => $base . '/api/v1/data/domains/' . $genome . '/entry/' . rawurlencode($doc['accession']) . '?format=tsv&isoforms=' . $isoforms),
    MgdbData::fileReadsMeta($meta), 86400);
}//dm_entry

/////
// GET /api/v1/data/domains/{genome}/class[/{name}]
// GET /api/v1/data/domains/{genome}/immunity[/{class}][?subclass=]
/////

/* The member lists behind the class and immunity counts on the gene record,
   from data/domains/atlas_members/{genome}.json. Classes are non-exclusive
   (a gene can be in several); an immunity call is one class per gene. */
function dm_members($genome) {
  $dir = MgdbData::dir('domains');
  $doc = ($dir === null) ? null : MgdbData::readJson($dir . '/atlas_members/' . $genome . '.json');
  if ($doc === null) {
    MgdbApi::problem(404, 'no-class-lists', 'No class lists',
      'The domain atlas has no class or immunity lists for this genome.', array('genome' => $genome));
  }
  return $doc;
}//dm_members

/* A class name as a path segment: "Immunity: NLR (NBS-LRR)" is
   immunity-nlr-nbs-lrr. Unique across the atlas's 36 classes. */
function dm_class_slug($name) {
  return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $name)), '-');
}//dm_class_slug

function dm_class($genome, $manifest, $raw) {
  $base = MgdbApi::baseUrl();
  $doc = dm_members($genome);
  $format = MgdbData::format(array('json', 'tsv'));
  $raw = trim(rawurldecode((string) $raw));
  $meta = MgdbData::meta('domains', $genome, $manifest, array(
    'atlas_generated' => $doc['atlas_generated'], 'counting_unit' => $doc['counting_unit']));

  if ($raw === '') {
    $list = array();
    foreach ($doc['classes'] as $name => $genes) {
      $slug = dm_class_slug($name);
      $list[] = array('name' => $name, 'slug' => $slug,
                      'group' => isset($doc['groups'][$name]) ? $doc['groups'][$name] : null,
                      'genes' => count($genes),
                      'self' => $base . '/api/v1/data/domains/' . $genome . '/class/' . $slug);
    }
    if ($format === 'tsv') {
      MgdbApi::sendText(MgdbData::tsv(array('name', 'slug', 'group', 'genes'), $list),
        'text/tab-separated-values; charset=utf-8', 86400, $genome . '_classes.tsv');
    }
    MgdbApi::sendData(array('type' => 'domain_classes', 'id' => $genome, 'attributes' => array('class_count' => count($list)),
                            'sections' => array('classes' => $list)),
      array('immunity' => $base . '/api/v1/data/domains/' . $genome . '/immunity'), MgdbData::fileReadsMeta($meta), 86400);
  }

  $want = strtolower($raw);
  $name = null;
  foreach (array_keys($doc['classes']) as $n) {
    if (strtolower($n) === $want || dm_class_slug($n) === dm_class_slug($raw)) { $name = $n; break; }
  }
  if ($name === null) {
    MgdbApi::problem(404, 'unknown-class', 'Unknown class',
      'No domain atlas class by that name in this genome. The class list is at /api/v1/data/domains/' . $genome . '/class.',
      array('class' => $raw));
  }
  $slug = dm_class_slug($name);
  $genes = $doc['classes'][$name];
  if ($format === 'tsv') {
    $rows = array();
    foreach ($genes as $g) { $rows[] = array('gene_model' => $g, 'class' => $name, 'genome' => $genome); }
    MgdbApi::sendText(MgdbData::tsv(array('gene_model', 'class', 'genome'), $rows),
      'text/tab-separated-values; charset=utf-8', 86400, $genome . '_' . $slug . '.tsv');
  }
  MgdbApi::sendData(
    array('type' => 'domain_class', 'id' => $slug,
          'attributes' => array('name' => $name, 'group' => isset($doc['groups'][$name]) ? $doc['groups'][$name] : null,
                                'genome' => $genome, 'gene_count' => count($genes)),
          'sections' => array('genes' => $genes)),
    array('tsv' => $base . '/api/v1/data/domains/' . $genome . '/class/' . $slug . '?format=tsv',
          'classes' => $base . '/api/v1/data/domains/' . $genome . '/class'),
    MgdbData::fileReadsMeta($meta), 86400);
}//dm_class

function dm_immunity($genome, $manifest, $raw) {
  $base = MgdbApi::baseUrl();
  $doc = dm_members($genome);
  $format = MgdbData::format(array('json', 'tsv'));
  $labels = isset($doc['immunity_labels']) ? $doc['immunity_labels'] : array();
  $raw = trim(rawurldecode((string) $raw));
  $subRaw = trim((string) MgdbApi::query('subclass', ''));
  $meta = MgdbData::meta('domains', $genome, $manifest, array(
    'atlas_generated' => $doc['atlas_generated'], 'counting_unit' => $doc['counting_unit']));

  if ($raw === '') {
    $list = array();
    foreach ($doc['immunity'] as $cls => $calls) {
      $subs = array();
      foreach ($calls as $c) {
        $k = $c['subclass'] === null ? '' : $c['subclass'];
        $subs[$k] = isset($subs[$k]) ? $subs[$k] + 1 : 1;
      }
      ksort($subs);
      $list[] = array('class' => $cls, 'label' => isset($labels[$cls]) ? $labels[$cls] : $cls, 'genes' => count($calls),
                      'subclasses' => $subs, 'self' => $base . '/api/v1/data/domains/' . $genome . '/immunity/' . rawurlencode($cls));
    }
    MgdbApi::sendData(array('type' => 'immunity_classes', 'id' => $genome, 'attributes' => array('class_count' => count($list)),
                            'sections' => array('classes' => $list)),
      array('classes' => $base . '/api/v1/data/domains/' . $genome . '/class'), MgdbData::fileReadsMeta($meta), 86400);
  }

  $cls = null;
  foreach (array_keys($doc['immunity']) as $c) {
    if (strtolower($c) === strtolower($raw)) { $cls = $c; break; }
  }
  if ($cls === null) {
    MgdbApi::problem(404, 'unknown-immunity-class', 'Unknown immunity class',
      'No immunity call by that class in this genome. The classes are at /api/v1/data/domains/' . $genome . '/immunity.',
      array('class' => $raw, 'classes' => array_keys($doc['immunity'])));
  }
  $calls = $doc['immunity'][$cls];
  $sub = null;
  if ($subRaw !== '') {
    foreach ($calls as $c) {
      if ($c['subclass'] !== null && strtolower($c['subclass']) === strtolower($subRaw)) { $sub = $c['subclass']; break; }
    }
    if ($sub === null) {
      MgdbApi::problem(404, 'unknown-subclass', 'Unknown subclass',
        'No ' . $cls . ' call in this genome has that subclass.', array('class' => $cls, 'subclass' => $subRaw));
    }
    $calls = array_values(array_filter($calls, function ($c) use ($sub) { return $c['subclass'] === $sub; }));
  }
  $label = isset($labels[$cls]) ? $labels[$cls] : $cls;
  $query = '?format=tsv' . ($sub !== null ? '&subclass=' . rawurlencode($sub) : '');
  if ($format === 'tsv') {
    $rows = array();
    foreach ($calls as $c) {
      $rows[] = array('gene_model' => $c['gene'], 'class' => $cls, 'subclass' => $c['subclass'],
                      'evidence' => implode(',', $c['evidence']), 'genome' => $genome);
    }
    MgdbApi::sendText(MgdbData::tsv(array('gene_model', 'class', 'subclass', 'evidence', 'genome'), $rows),
      'text/tab-separated-values; charset=utf-8', 86400,
      $genome . '_immunity_' . strtolower($cls) . ($sub !== null ? '_' . dm_class_slug($sub) : '') . '.tsv');
  }
  MgdbApi::sendData(
    array('type' => 'immunity_class', 'id' => $cls . ($sub !== null ? '/' . $sub : ''),
          'attributes' => array('class' => $cls, 'label' => $label, 'subclass' => $sub, 'genome' => $genome,
                                'gene_count' => count($calls)),
          'sections' => array('genes' => $calls)),
    array('tsv' => $base . '/api/v1/data/domains/' . $genome . '/immunity/' . rawurlencode($cls) . $query,
          'classes' => $base . '/api/v1/data/domains/' . $genome . '/immunity'),
    MgdbData::fileReadsMeta($meta), 86400);
}//dm_immunity

/////
// GET /api/v1/data/domains/{genome}/region/{seq}:{start}-{end}
/////

function dm_region($genome, $manifest, $rawRegion) {
  $base = MgdbApi::baseUrl();
  $region = MgdbData::parseRegion($rawRegion, $manifest);
  $analyses = MgdbData::listParam('analysis');
  $limit = MgdbData::intParam('limit', MgdbData::LIST_LIMIT_DEFAULT, 1, MgdbData::LIST_LIMIT_MAX);
  $offset = MgdbData::intParam('offset', 0, 0, 100000000);
  $format = MgdbData::format(array('json', 'tsv', 'bed'));

  $items = MgdbData::binItems('domains', $genome, $region['sequence'], $region['start'], $region['end'],
    function ($i) { return $i['protein'] . '|' . $i['accession'] . '|' . $i['residues']['start']; });
  if ($analyses !== null) {
    $items = array_values(array_filter($items, function ($i) use ($analyses) {
      return isset($i['analysis']) && in_array(strtolower($i['analysis']), $analyses, true);
    }));
  }
  list($page, $paging, $next) = MgdbData::page($items, $limit, $offset);
  $meta = MgdbData::meta('domains', $genome, $manifest, array_merge(array('region' => $region, 'analysis' => $analyses), $paging));

  if ($format === 'tsv') {
    $rows = array();
    foreach ($page as $i) {
      $rows[] = array('protein' => $i['protein'], 'gene' => $i['gene'], 'symbol' => $i['symbol'], 'analysis' => $i['analysis'],
                      'entry' => $i['entry'], 'accession' => $i['accession'], 'name' => $i['name'],
                      'residue_start' => $i['residues']['start'], 'residue_end' => $i['residues']['end'],
                      'chromosome' => $region['sequence'], 'start' => $i['start'], 'end' => $i['end'], 'strand' => $i['strand'],
                      'blocks' => implode(';', array_map(function ($b) { return $b['start'] . '-' . $b['end']; }, $i['blocks'])));
    }
    MgdbApi::sendText(MgdbData::tsv(array('protein', 'gene', 'symbol', 'analysis', 'entry', 'accession', 'name', 'residue_start', 'residue_end',
      'chromosome', 'start', 'end', 'strand', 'blocks'), $rows), 'text/tab-separated-values; charset=utf-8', 86400,
      'domains_' . $region['sequence'] . '.tsv');
  }
  if ($format === 'bed') {
    $lines = array();
    foreach ($page as $i) {
      $blocks = $i['blocks'];
      $chromStart = (int) $i['start'] - 1;
      $sizes = array();
      $offsets = array();
      foreach ($blocks as $b) {
        $sizes[] = (int) $b['end'] - (int) $b['start'] + 1;
        $offsets[] = (int) $b['start'] - 1 - $chromStart;
      }
      $lines[] = implode("\t", array($region['sequence'], $chromStart, (int) $i['end'], $i['protein'] . ':' . $i['accession'], 0, $i['strand'],
        $chromStart, (int) $i['end'], '0,0,0', count($blocks), implode(',', $sizes) . ',', implode(',', $offsets) . ','));
    }
    MgdbApi::sendText(implode("\n", $lines) . "\n", 'text/plain; charset=utf-8', 86400, 'domains_' . $region['sequence'] . '.bed');
  }
  MgdbApi::sendData($page, array(
    'next' => $next,
    'gene_models' => $base . '/api/v1/data/gene-models/' . $genome . '/region/' . $region['sequence'] . ':' . $region['start'] . '-' . $region['end'],
    'bed' => $base . '/api/v1/data/domains/' . $genome . '/region/' . $region['sequence'] . ':' . $region['start'] . '-' . $region['end'] . '?format=bed'
  ), MgdbData::fileReadsMeta($meta), 86400);
}//dm_region

/////
// GET /api/v1/data/domains/{genome}/batch?ids=
/////

function dm_batch($genome, $manifest, $available) {
  $base = MgdbApi::baseUrl();
  $ids = MgdbData::ids();
  $format = MgdbData::format(array('json', 'tsv'));
  $analyses = MgdbData::listParam('analysis');
  $wanted = MgdbApi::sections($available);

  $found = array();
  $missing = array();
  $resolvedMap = array();
  foreach ($ids as $id) {
    $resolved = null;
    $p = dm_lookup($genome, $manifest, $id, $resolved);
    if ($p === null) { $missing[] = $id; continue; }
    if (strtolower($id) !== strtolower($p['id'])) { $resolvedMap[$id] = $p['id']; }
    $found[] = dm_filter_analysis($p, $analyses);
  }
  if ($format === 'tsv') {
    $rows = array();
    foreach ($found as $p) { foreach (dm_tsv_rows($p) as $r) { $rows[] = $r; } }
    MgdbApi::sendText(MgdbData::tsv(dm_tsv_columns(), $rows), 'text/tab-separated-values; charset=utf-8', 3600, 'domains.interproscan.tsv');
  }
  $data = array();
  foreach ($found as $p) {
    $sections = dm_sections($p, $wanted);
    $data[] = array('type' => 'protein_domains', 'id' => $p['id'], 'attributes' => dm_attributes($p),
                    'sections' => count($sections) > 0 ? $sections : new stdClass(),
                    'links' => array('self' => $base . '/api/v1/data/domains/' . $genome . '/' . rawurlencode($p['id'])));
  }
  $meta = MgdbData::meta('domains', $genome, $manifest, array(
    'requested' => count($ids), 'returned' => count($data), 'missing' => $missing,
    'resolved' => count($resolvedMap) > 0 ? $resolvedMap : new stdClass(),
    'sections_returned' => $wanted, 'sections_available' => $available, 'analysis' => $analyses,
    'max_ids' => MgdbData::MAX_IDS
  ));
  MgdbApi::sendData($data, array('tsv' => MgdbApi::selfUrl() . '&format=tsv'), MgdbData::fileReadsMeta($meta), 3600);
}//dm_batch
?>
