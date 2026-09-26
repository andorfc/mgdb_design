<?php
/* file: api/v1/data/gene_models.php
 *
 * purpose: the gene-models dataset of the v1 API, served from the release
 *          files tools/gene_models_index.py writes under data/gene_models/.
 *
 *          Routes (all GET; $api_rest holds the segments after the dataset):
 *            /api/v1/data/gene-models                      genomes with a release
 *            /api/v1/data/gene-models/{genome}             the release manifest
 *            /api/v1/data/gene-models/{genome}/{id}        one gene, every transcript
 *            /api/v1/data/gene-models/{genome}/region/{seq}:{start}-{end}
 *            /api/v1/data/gene-models/{genome}/batch?ids=  up to 200 genes
 *
 *          Coordinates are 1-based and inclusive, as in the GFF3. Blocks are
 *          listed in transcript order with their rank, so exon 1 of a minus-
 *          strand gene has the highest coordinate; a client that draws in
 *          genome order sorts by start.
 *
 *          No database query is made unless an identifier is not a gene,
 *          transcript, protein or previous id of the release; then the gene
 *          record's resolver is asked once. See MgdbData::gene().
 *
 * history:
 *  09/12/26  claude  created
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

  $GM_DATASET = 'gene-models';
  $GM_SECTIONS = array('transcripts', 'locus', 'xrefs', 'neighbors');
  $gm_base = MgdbApi::baseUrl();

  /////
  // GET /api/v1/data/gene-models
  /////

  if (count($api_rest) === 0) {
    $genomes = array();
    foreach (MgdbData::genomes($GM_DATASET) as $name => $manifest) {
      $s = MgdbData::manifestSummary($manifest);
      /* The registry's example gene belongs to its own genome; any other
         release names one of its own genes in its manifest. */
      $example = ($name === $api_entry['example']['genome']) ? $api_entry['example']['id']
               : (isset($manifest['example_gene']) ? $manifest['example_gene'] : null);
      $s['links'] = array(
        'self' => $gm_base . '/api/v1/data/gene-models/' . $name,
        'example' => $example === null ? null : $gm_base . '/api/v1/data/gene-models/' . $name . '/' . rawurlencode($example)
      );
      $genomes[] = $s;
    }
    MgdbApi::sendData(
      array('type' => 'dataset', 'id' => $GM_DATASET,
            'attributes' => api_data_summary($api_entry),
            'sections' => array('genomes' => $genomes)),
      array('openapi' => $gm_base . '/api/v1/openapi'),
      MgdbData::fileReadsMeta(array('dataset' => $GM_DATASET, 'genome_count' => count($genomes))),
      3600);
  }

  $gm_rest = array_slice($api_rest, 1);
  list($gm_genome, $gm_manifest) = MgdbData::resolveGenome($GM_DATASET, $api_rest[0], $gm_rest);

  /////
  // GET /api/v1/data/gene-models/{genome}
  /////

  if (count($gm_rest) === 0) {
    $attributes = $gm_manifest;
    unset($attributes['sequences'], $attributes['disagreements']);
    MgdbApi::sendData(
      array('type' => 'gene-model-release', 'id' => $gm_genome,
            'attributes' => $attributes,
            'sections' => array(
              'sequences' => isset($gm_manifest['sequences']) ? $gm_manifest['sequences'] : array(),
              'disagreements' => isset($gm_manifest['disagreements']) ? $gm_manifest['disagreements'] : array()
            )),
      array('dataset' => $gm_base . '/api/v1/data/gene-models',
            'example' => $gm_base . '/api/v1/data/gene-models/' . $gm_genome . '/' . rawurlencode($api_entry['example']['id']),
            'index' => $gm_base . '/data/gene_models/' . $gm_genome . '/index.json'),
      MgdbData::fileReadsMeta(MgdbData::meta($GM_DATASET, $gm_genome, $gm_manifest)),
      3600);
  }

  $gm_action = strtolower($gm_rest[0]);
  if ($gm_action === 'region') {
    gm_region($gm_genome, $gm_manifest, implode('/', array_slice($gm_rest, 1)));
  } elseif ($gm_action === 'batch') {
    gm_batch($gm_genome, $gm_manifest, $GM_SECTIONS);
  } else {
    gm_one($gm_genome, $gm_manifest, implode('/', $gm_rest), $GM_SECTIONS);
  }
  return;

/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* Which transcripts a request wants: all (the default) or the canonical one. */
function gm_transcript_selector() {
  $raw = strtolower(MgdbApi::query('transcripts', 'all'));
  if ($raw === '') { $raw = 'all'; }
  if (!in_array($raw, array('all', 'canonical'), true)) {
    MgdbApi::problem(400, 'invalid-transcripts', 'Invalid transcripts',
      'transcripts must be all or canonical.');
  }
  return $raw;
}//gm_transcript_selector

function gm_transcripts($g, $selector) {
  $out = array();
  foreach ((isset($g['transcripts']) ? $g['transcripts'] : array()) as $t) {
    if ($selector === 'canonical' && empty($t['canonical'])) { continue; }
    $out[] = $t;
  }
  return $out;
}//gm_transcripts

function gm_attributes($g) {
  return array(
    'name' => $g['id'],
    'biotype' => isset($g['biotype']) ? $g['biotype'] : null,
    'logic_name' => isset($g['logic_name']) ? $g['logic_name'] : null,
    'source' => isset($g['source']) ? $g['source'] : null,
    'chromosome' => $g['seq'],
    'start' => (int) $g['start'],
    'end' => (int) $g['end'],
    'strand' => $g['strand'],
    'length_bp' => (int) $g['end'] - (int) $g['start'] + 1,
    'canonical_transcript' => isset($g['canonical_transcript']) ? $g['canonical_transcript'] : null,
    'canonical_protein' => isset($g['canonical_protein']) ? $g['canonical_protein'] : null,
    'protein_length_aa' => isset($g['protein_length_aa']) ? $g['protein_length_aa'] : null,
    'transcript_count' => isset($g['transcript_count']) ? (int) $g['transcript_count'] : count($g['transcripts']),
    'symbol' => isset($g['symbol']) ? $g['symbol'] : null,
    'full_name' => isset($g['full_name']) ? $g['full_name'] : null,
    'description' => isset($g['description']) ? $g['description'] : null,
    'locus_name' => isset($g['locus_name']) ? $g['locus_name'] : null
  );
}//gm_attributes

function gm_sections($g, $wanted, $transcripts, $base) {
  $sections = array();
  $counts = array('transcripts' => count($transcripts), 'exons' => 0, 'cds_blocks' => 0);
  foreach ($transcripts as $t) {
    $counts['exons'] += count($t['exons']);
    $counts['cds_blocks'] += count($t['cds']);
  }
  if (in_array('transcripts', $wanted, true)) {
    $sections['transcripts'] = $transcripts;
  }
  if (in_array('locus', $wanted, true)) {
    /* The published locus file names the classical locus (lg1) and carries no
       numeric id, so the link is to the locus record by name, which the record
       API resolves. */
    $locus_name = isset($g['locus_name']) ? $g['locus_name'] : null;
    $sections['locus'] = ($locus_name === null && empty($g['symbol'])) ? null : array(
      'name' => $locus_name,
      'symbol' => isset($g['symbol']) ? $g['symbol'] : null,
      'full_name' => isset($g['full_name']) ? $g['full_name'] : null,
      'description' => isset($g['description']) ? $g['description'] : null,
      'record' => $locus_name === null ? null : $base . '/api/v1/records/locus/' . rawurlencode($locus_name)
    );
  }
  if (in_array('xrefs', $wanted, true)) {
    $sections['xrefs'] = array('previous_ids' => isset($g['previous_ids']) ? $g['previous_ids'] : array());
    $counts['previous_ids'] = count($sections['xrefs']['previous_ids']);
  }
  if (in_array('neighbors', $wanted, true)) {
    $sections['neighbors'] = isset($g['neighbors']) ? $g['neighbors'] : array('previous' => null, 'next' => null);
  }
  return array($sections, $counts);
}//gm_sections

function gm_links($base, $genome, $manifest, $g) {
  $id = $g['id'];
  $self = $base . '/api/v1/data/gene-models/' . $genome . '/' . rawurlencode($id);
  $protein = isset($g['canonical_protein']) ? $g['canonical_protein'] : null;
  $links = array(
    'self' => $self,
    'record' => $base . '/api/v1/records/gene/' . rawurlencode($id),
    'html' => $base . '/gene_center/gene/' . rawurlencode($id),
    /* Only B73 NAM-5.0 has a domains release; elsewhere the link would 404. */
    'domains' => ($protein === null || !MgdbData::hasRelease('domains', $genome)) ? null
               : $base . '/api/v1/data/domains/' . $genome . '/' . rawurlencode($protein),
    'region' => $base . '/api/v1/data/gene-models/' . $genome . '/region/' . $g['seq'] . ':'
              . max(1, (int) $g['start'] - 10000) . '-' . ((int) $g['end'] + 10000),
    'gff3' => $self . '?format=gff3',
    'bed' => $self . '?format=bed',
    'browser' => null
  );
  if (isset($manifest['assembly']) && $manifest['assembly'] === 'Zm-B73-REFERENCE-NAM-5.0') {
    $links['browser'] = 'https://jbrowse.maizegdb.org?loc=' . $g['seq'] . ':' . $g['start'] . '..' . $g['end']
                      . '&tracks=gene_models_official,gene_models_v4_json,gene_models_v3_json';
  }
  return $links;
}//gm_links

/////
// GET /api/v1/data/gene-models/{genome}/{id}
/////

function gm_one($genome, $manifest, $rawId, $available) {
  $base = MgdbApi::baseUrl();
  $id = MgdbApi::identifier($rawId);
  $format = MgdbData::format(array('json', 'gff3', 'bed'));
  $selector = gm_transcript_selector();
  $wanted = MgdbApi::sections($available);

  $resolved = null;
  $g = MgdbData::gene($genome, $id, $resolved, isset($manifest['assembly']) ? $manifest['assembly'] : null, true);
  if ($g === null) {
    MgdbApi::problem(404, 'gene-model-not-found', 'Gene model not found',
      'No gene model, transcript, protein or previous identifier in this release matches, and the resolver found nothing in this assembly.',
      array('identifier' => $id, 'genome' => $genome,
            'hint' => isset($resolved['hint']) ? $resolved['hint'] : null));
  }
  $transcripts = gm_transcripts($g, $selector);

  if ($format === 'gff3') {
    MgdbApi::sendText(gm_gff3(array($g), $manifest, $selector), 'text/plain; charset=utf-8', 86400, $g['id'] . '.gff3');
  }
  if ($format === 'bed') {
    MgdbApi::sendText(gm_bed(array($g), $selector), 'text/plain; charset=utf-8', 86400, $g['id'] . '.bed');
  }

  list($sections, $counts) = gm_sections($g, $wanted, $transcripts, $base);
  $links = gm_links($base, $genome, $manifest, $g);
  if ($resolved['as'] === 'transcript' || $resolved['as'] === 'protein') {
    $t = MgdbData::transcriptOf($g, $id);
    if ($t !== null) {
      $links['transcript'] = $links['self'] . '#' . rawurlencode($t['id']);
      if (isset($t['protein']['id'])) {
        $links['protein_domains'] = $base . '/api/v1/data/domains/' . $genome . '/' . rawurlencode($t['protein']['id']);
      }
    }
  }
  $meta = MgdbData::meta('gene-models', $genome, $manifest, array(
    'sections_returned' => $wanted,
    'sections_available' => $available,
    'transcripts' => $selector,
    'counts' => $counts
  ));
  if ($resolved['from'] !== null) {
    $meta['resolved_from'] = $resolved['from'];
    $meta['resolved_as'] = $resolved['as'];
  }
  MgdbApi::sendData(
    array('type' => 'gene_model', 'id' => $g['id'], 'attributes' => gm_attributes($g),
          'sections' => count($sections) > 0 ? $sections : new stdClass()),
    $links, MgdbData::fileReadsMeta($meta), 86400);
}//gm_one

/////
// GET /api/v1/data/gene-models/{genome}/region/{seq}:{start}-{end}
/////

function gm_region($genome, $manifest, $rawRegion) {
  $base = MgdbApi::baseUrl();
  $region = MgdbData::parseRegion($rawRegion, $manifest);
  $types = array('gene' => 'gene', 'transcript' => 'transcript', 'mrna' => 'mRNA', 'exon' => 'exon',
                 'cds' => 'CDS', 'utr' => 'UTR');
  $rawType = strtolower(MgdbApi::query('type', 'gene'));
  if ($rawType === '') { $rawType = 'gene'; }
  if (!isset($types[$rawType])) {
    MgdbApi::problem(400, 'invalid-type', 'Invalid type', 'type must be one of gene, transcript, mRNA, exon, CDS, UTR.',
      array('available_types' => array_values($types)));
  }
  $type = $types[$rawType];
  $canonicalOnly = MgdbData::flag('canonical');
  $biotypes = MgdbData::listParam('biotype');
  $limit = MgdbData::intParam('limit', MgdbData::LIST_LIMIT_DEFAULT, 1, MgdbData::LIST_LIMIT_MAX);
  $offset = MgdbData::intParam('offset', 0, 0, 100000000);
  $format = MgdbData::format(array('json', 'tsv', 'gff3', 'bed'));

  $items = MgdbData::binItems('gene-models', $genome, $region['sequence'], $region['start'], $region['end'],
                              function ($i) { return $i['id']; });
  if ($biotypes !== null) {
    $items = array_values(array_filter($items, function ($i) use ($biotypes) {
      return isset($i['biotype']) && in_array(strtolower($i['biotype']), $biotypes, true);
    }));
  }
  $selector = $canonicalOnly ? 'canonical' : 'all';
  $meta = MgdbData::meta('gene-models', $genome, $manifest, array(
    'region' => $region, 'type' => $type, 'canonical' => $canonicalOnly, 'biotype' => $biotypes
  ));

  if ($type === 'gene') {
    list($page, $paging, $next) = MgdbData::page($items, $limit, $offset);
    $meta = array_merge($meta, $paging);
    if ($format === 'tsv') {
      $rows = array();
      foreach ($page as $i) {
        $rows[] = array('gene' => $i['id'], 'chromosome' => $region['sequence'], 'start' => $i['start'], 'end' => $i['end'],
                        'strand' => $i['strand'], 'biotype' => $i['biotype'], 'symbol' => $i['symbol'],
                        'canonical_transcript' => $i['canonical_transcript'], 'canonical_protein' => $i['canonical_protein'],
                        'protein_length_aa' => $i['protein_length_aa']);
      }
      MgdbApi::sendText(MgdbData::tsv(array('gene', 'chromosome', 'start', 'end', 'strand', 'biotype', 'symbol',
        'canonical_transcript', 'canonical_protein', 'protein_length_aa'), $rows),
        'text/tab-separated-values; charset=utf-8', 86400, 'gene_models_' . $region['sequence'] . '.tsv');
    }
    if ($format === 'gff3' || $format === 'bed') {
      $genes = array();
      foreach ($page as $i) {
        $g = MgdbData::shardEntry('gene-models', $genome, 'genes', $i['id'], MgdbData::GENE_SHARD_DEPTH);
        if ($g !== null) { $genes[] = $g; }
      }
      if ($format === 'gff3') {
        MgdbApi::sendText(gm_gff3($genes, $manifest, $selector), 'text/plain; charset=utf-8', 86400, 'gene_models_' . $region['sequence'] . '.gff3');
      }
      MgdbApi::sendText(gm_bed($genes, $selector), 'text/plain; charset=utf-8', 86400, 'gene_models_' . $region['sequence'] . '.bed');
    }
    $data = array();
    foreach ($page as $i) {
      $data[] = array(
        'type' => 'gene_model', 'id' => $i['id'],
        'attributes' => array(
          'chromosome' => $region['sequence'], 'start' => $i['start'], 'end' => $i['end'], 'strand' => $i['strand'],
          'length_bp' => $i['end'] - $i['start'] + 1, 'biotype' => $i['biotype'], 'symbol' => $i['symbol'],
          'canonical_transcript' => $i['canonical_transcript'], 'canonical_protein' => $i['canonical_protein'],
          'protein_length_aa' => $i['protein_length_aa']
        ),
        'links' => array('self' => $base . '/api/v1/data/gene-models/' . $genome . '/' . rawurlencode($i['id']))
      );
    }
    $links = gm_region_links($base, $genome, $region, $next);
    MgdbApi::sendData($data, $links, MgdbData::fileReadsMeta($meta), 86400);
  }

  // Sub-gene types need the gene shards, so the window is capped.
  if ($region['span_bp'] > MgdbData::SUBGENE_SPAN_MAX) {
    MgdbApi::problem(413, 'region-too-large', 'Region too large',
      'A ' . $type . ' request may span at most ' . MgdbData::SUBGENE_SPAN_MAX . ' bp; use type=gene for a wider window, or a narrower interval.',
      array('span_bp' => $region['span_bp'], 'max_span_bp' => MgdbData::SUBGENE_SPAN_MAX, 'max_limit' => MgdbData::LIST_LIMIT_MAX));
  }
  $rows = array();
  $genesForText = array();
  foreach ($items as $i) {
    $g = MgdbData::shardEntry('gene-models', $genome, 'genes', $i['id'], MgdbData::GENE_SHARD_DEPTH);
    if ($g === null) { continue; }
    $genesForText[] = $g;
    foreach (gm_transcripts($g, $selector) as $t) {
      $common = array('gene' => $g['id'], 'transcript' => $t['id'], 'canonical' => !empty($t['canonical']),
                      'chromosome' => $g['seq'], 'strand' => $g['strand']);
      if ($type === 'transcript' || $type === 'mRNA') {
        if ($type === 'mRNA' && $t['type'] !== 'mRNA') { continue; }
        if ($t['end'] < $region['start'] || $t['start'] > $region['end']) { continue; }
        $rows[] = array_merge(array('type' => $t['type'], 'id' => $t['id']), $common, array(
          'biotype' => $t['biotype'], 'start' => $t['start'], 'end' => $t['end'],
          'protein' => isset($t['protein']['id']) ? $t['protein']['id'] : null,
          'exon_count' => $t['exon_count']));
        continue;
      }
      if ($type === 'exon') {
        foreach ($t['exons'] as $e) {
          if ($e['end'] < $region['start'] || $e['start'] > $region['end']) { continue; }
          $rows[] = array_merge(array('type' => 'exon'), $common, array('rank' => $e['rank'], 'start' => $e['start'], 'end' => $e['end']));
        }
      } elseif ($type === 'CDS') {
        foreach ($t['cds'] as $c) {
          if ($c['end'] < $region['start'] || $c['start'] > $region['end']) { continue; }
          $rows[] = array_merge(array('type' => 'CDS'), $common, array(
            'protein' => isset($t['protein']['id']) ? $t['protein']['id'] : null,
            'rank' => $c['rank'], 'start' => $c['start'], 'end' => $c['end'], 'phase' => $c['phase']));
        }
      } else {
        foreach (array('five_prime_utr' => 'five_prime_UTR', 'three_prime_utr' => 'three_prime_UTR') as $key => $label) {
          foreach ($t[$key] as $u) {
            if ($u['end'] < $region['start'] || $u['start'] > $region['end']) { continue; }
            $rows[] = array_merge(array('type' => $label), $common, array('start' => $u['start'], 'end' => $u['end']));
          }
        }
      }
    }
  }
  usort($rows, function ($a, $b) {
    if ($a['start'] !== $b['start']) { return $a['start'] < $b['start'] ? -1 : 1; }
    if ($a['end'] !== $b['end']) { return $a['end'] < $b['end'] ? -1 : 1; }
    return strcmp($a['transcript'], $b['transcript']);
  });
  list($page, $paging, $next) = MgdbData::page($rows, $limit, $offset);
  $meta = array_merge($meta, $paging);

  if ($format === 'tsv') {
    MgdbApi::sendText(MgdbData::tsv(array('type', 'gene', 'transcript', 'canonical', 'rank', 'chromosome', 'start', 'end', 'strand', 'phase', 'protein', 'biotype'), $page),
      'text/tab-separated-values; charset=utf-8', 86400, 'gene_models_' . $region['sequence'] . '_' . $type . '.tsv');
  }
  if ($format === 'gff3') {
    $lines = array('##gff-version 3', '##sequence-region ' . $region['sequence'] . ' 1 ' . $region['sequence_length']);
    foreach ($page as $r) {
      $attrs = ($r['type'] === 'CDS')
        ? 'ID=' . $r['protein'] . ';Parent=' . $r['transcript'] . ';protein_id=' . $r['protein']
        : (($r['type'] === 'exon') ? 'Parent=' . $r['transcript'] . ';exon_id=' . $r['transcript'] . '.exon.' . $r['rank'] . ';rank=' . $r['rank']
        : (isset($r['id']) ? 'ID=' . $r['id'] . ';Parent=' . $r['gene'] . ';biotype=' . $r['biotype'] . ';transcript_id=' . $r['id'] . ($r['canonical'] ? ';canonical_transcript=1' : '')
        : 'Parent=' . $r['transcript']));
      $lines[] = implode("\t", array($r['chromosome'], isset($manifest['primary_source']) ? 'MaizeGDB' : 'MaizeGDB', $r['type'],
        $r['start'], $r['end'], '.', $r['strand'], isset($r['phase']) && $r['phase'] !== null ? $r['phase'] : '.', $attrs));
    }
    MgdbApi::sendText(implode("\n", $lines) . "\n", 'text/plain; charset=utf-8', 86400, 'gene_models_' . $region['sequence'] . '_' . $type . '.gff3');
  }
  if ($format === 'bed') {
    MgdbApi::sendText(gm_bed($genesForText, $selector), 'text/plain; charset=utf-8', 86400, 'gene_models_' . $region['sequence'] . '.bed');
  }
  $links = gm_region_links($base, $genome, $region, $next);
  MgdbApi::sendData($page, $links, MgdbData::fileReadsMeta($meta), 86400);
}//gm_region

function gm_region_links($base, $genome, $region, $next) {
  $self = $base . '/api/v1/data/gene-models/' . $genome . '/region/' . $region['sequence'] . ':' . $region['start'] . '-' . $region['end'];
  return array(
    'self' => MgdbApi::selfUrl(),
    'next' => $next,
    'genes' => $self,
    'gff3' => $self . '?format=gff3',
    'bed' => $self . '?format=bed',
    'domains' => !MgdbData::hasRelease('domains', $genome) ? null
               : $base . '/api/v1/data/domains/' . $genome . '/region/' . $region['sequence'] . ':' . $region['start'] . '-' . $region['end'],
    'browser' => 'https://jbrowse.maizegdb.org?loc=' . $region['sequence'] . ':' . $region['start'] . '..' . $region['end'] . '&tracks=gene_models_official'
  );
}//gm_region_links

/////
// GET /api/v1/data/gene-models/{genome}/batch?ids=
/////

function gm_batch($genome, $manifest, $available) {
  $base = MgdbApi::baseUrl();
  $ids = MgdbData::ids();
  $format = MgdbData::format(array('json', 'tsv', 'gff3', 'bed'));
  $selector = gm_transcript_selector();
  $wanted = MgdbApi::sections($available);
  $assembly = isset($manifest['assembly']) ? $manifest['assembly'] : null;

  $found = array();
  $missing = array();
  $resolvedMap = array();
  $queries = 0;
  foreach ($ids as $id) {
    $resolved = null;
    $g = MgdbData::gene($genome, $id, $resolved, $assembly, true);
    $queries += $resolved['queries'];
    if ($g === null) { $missing[] = $id; continue; }
    if ($resolved['from'] !== null) { $resolvedMap[$id] = $g['id']; }
    $found[] = $g;
  }

  if ($format === 'gff3') {
    MgdbApi::sendText(gm_gff3($found, $manifest, $selector), 'text/plain; charset=utf-8', 3600, 'gene_models.gff3');
  }
  if ($format === 'bed') {
    MgdbApi::sendText(gm_bed($found, $selector), 'text/plain; charset=utf-8', 3600, 'gene_models.bed');
  }
  if ($format === 'tsv') {
    $rows = array();
    foreach ($found as $g) {
      $a = gm_attributes($g);
      $rows[] = array('gene' => $g['id'], 'chromosome' => $a['chromosome'], 'start' => $a['start'], 'end' => $a['end'],
                      'strand' => $a['strand'], 'biotype' => $a['biotype'], 'symbol' => $a['symbol'],
                      'full_name' => $a['full_name'], 'canonical_transcript' => $a['canonical_transcript'],
                      'canonical_protein' => $a['canonical_protein'], 'protein_length_aa' => $a['protein_length_aa'],
                      'transcript_count' => $a['transcript_count']);
    }
    MgdbApi::sendText(MgdbData::tsv(array('gene', 'chromosome', 'start', 'end', 'strand', 'biotype', 'symbol', 'full_name',
      'canonical_transcript', 'canonical_protein', 'protein_length_aa', 'transcript_count'), $rows),
      'text/tab-separated-values; charset=utf-8', 3600, 'gene_models.tsv');
  }

  $data = array();
  foreach ($found as $g) {
    list($sections, $counts) = gm_sections($g, $wanted, gm_transcripts($g, $selector), $base);
    $data[] = array('type' => 'gene_model', 'id' => $g['id'], 'attributes' => gm_attributes($g),
                    'sections' => count($sections) > 0 ? $sections : new stdClass(),
                    'links' => array('self' => $base . '/api/v1/data/gene-models/' . $genome . '/' . rawurlencode($g['id'])));
  }
  $meta = MgdbData::meta('gene-models', $genome, $manifest, array(
    'requested' => count($ids), 'returned' => count($data), 'missing' => $missing,
    'resolved' => count($resolvedMap) > 0 ? $resolvedMap : new stdClass(),
    'sections_returned' => $wanted, 'sections_available' => $available, 'transcripts' => $selector,
    'max_ids' => MgdbData::MAX_IDS
  ));
  MgdbApi::sendData($data, array('gff3' => MgdbApi::selfUrl() . '&format=gff3'), MgdbData::fileReadsMeta($meta), 3600);
}//gm_batch

/////
// Text formats
/////

/* GFF3 regenerated from the payload: the same attributes the published file
   carries, minus the Ensembl phase pair on exons, which the payload does not
   keep. Rows per transcript follow the published order: the transcript row,
   then exons and CDS in rank order, then the UTRs. */
function gm_gff3($genes, $manifest, $selector) {
  $lines = array('##gff-version 3');
  $declared = array();
  foreach ($genes as $g) {
    if (!isset($declared[$g['seq']])) {
      $seq = MgdbData::sequence($manifest, $g['seq']);
      $lines[] = '##sequence-region ' . $g['seq'] . ' 1 ' . ($seq !== null ? $seq['length'] : $g['end']);
      $declared[$g['seq']] = true;
    }
  }
  foreach ($genes as $g) {
    $src = isset($g['source']) && $g['source'] !== null ? $g['source'] : 'MaizeGDB';
    $lines[] = implode("\t", array($g['seq'], $src, 'gene', $g['start'], $g['end'], '.', $g['strand'], '.',
      'ID=' . $g['id'] . (isset($g['biotype']) ? ';biotype=' . $g['biotype'] : '') . (isset($g['logic_name']) ? ';logic_name=' . $g['logic_name'] : '')));
    foreach (gm_transcripts($g, $selector) as $t) {
      $lines[] = implode("\t", array($g['seq'], $src, $t['type'], $t['start'], $t['end'], '.', $t['strand'], '.',
        'ID=' . $t['id'] . ';Parent=' . $g['id'] . (isset($t['biotype']) ? ';biotype=' . $t['biotype'] : '')
        . ';transcript_id=' . $t['id'] . (!empty($t['canonical']) ? ';canonical_transcript=1' : '')));
      foreach ($t['five_prime_utr'] as $u) {
        $lines[] = implode("\t", array($g['seq'], $src, 'five_prime_UTR', $u['start'], $u['end'], '.', $t['strand'], '.', 'Parent=' . $t['id']));
      }
      foreach ($t['exons'] as $e) {
        $lines[] = implode("\t", array($g['seq'], $src, 'exon', $e['start'], $e['end'], '.', $t['strand'], '.',
          'Parent=' . $t['id'] . ';Name=' . $t['id'] . '.exon.' . $e['rank'] . ';exon_id=' . $t['id'] . '.exon.' . $e['rank'] . ';rank=' . $e['rank']));
      }
      $protein = isset($t['protein']['id']) ? $t['protein']['id'] : null;
      foreach ($t['cds'] as $c) {
        $lines[] = implode("\t", array($g['seq'], $src, 'CDS', $c['start'], $c['end'], '.', $t['strand'],
          ($c['phase'] === null ? '.' : $c['phase']),
          ($protein !== null ? 'ID=' . $protein . ';' : '') . 'Parent=' . $t['id'] . ($protein !== null ? ';protein_id=' . $protein : '')));
      }
      foreach ($t['three_prime_utr'] as $u) {
        $lines[] = implode("\t", array($g['seq'], $src, 'three_prime_UTR', $u['start'], $u['end'], '.', $t['strand'], '.', 'Parent=' . $t['id']));
      }
    }
  }
  return implode("\n", $lines) . "\n";
}//gm_gff3

/* BED12, one line per transcript: 0-based half-open, thick = the CDS span,
   blocks = the exons in genome order. A transcript without a CDS has
   thickStart = thickEnd = chromStart, the BED convention for non-coding. */
function gm_bed($genes, $selector) {
  $lines = array();
  foreach ($genes as $g) {
    foreach (gm_transcripts($g, $selector) as $t) {
      $exons = $t['exons'];
      usort($exons, function ($a, $b) { return $a['start'] - $b['start']; });
      $chromStart = (int) $t['start'] - 1;
      $chromEnd = (int) $t['end'];
      $thickStart = $chromStart;
      $thickEnd = $chromStart;
      if (count($t['cds']) > 0) {
        $starts = array_map(function ($c) { return (int) $c['start']; }, $t['cds']);
        $ends = array_map(function ($c) { return (int) $c['end']; }, $t['cds']);
        $thickStart = min($starts) - 1;
        $thickEnd = max($ends);
      }
      $sizes = array();
      $offsets = array();
      foreach ($exons as $e) {
        $sizes[] = (int) $e['end'] - (int) $e['start'] + 1;
        $offsets[] = (int) $e['start'] - 1 - $chromStart;
      }
      $lines[] = implode("\t", array($g['seq'], $chromStart, $chromEnd, $t['id'], 0, $t['strand'], $thickStart, $thickEnd,
        '0,0,0', count($exons), implode(',', $sizes) . ',', implode(',', $offsets) . ','));
    }
  }
  return implode("\n", $lines) . "\n";
}//gm_bed
?>
