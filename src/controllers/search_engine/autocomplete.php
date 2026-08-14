<?php
/*
 * Fast, JSON-only suggestions for the shared MaizeGDB header search.
 *
 * The first implementation intentionally reuses the indexed all_text_search
 * table and existing record tables. This keeps the endpoint useful before a
 * dedicated autocomplete index or service is introduced.
 */

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
header('X-Content-Type-Options: nosniff');

function acJson($payload, $status=200) {
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

function acCleanText($value, $limit=180) {
  if ($value === null) return '';
  $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value)));
  if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $limit) {
    return rtrim(mb_substr($value, 0, $limit - 1, 'UTF-8')) . '…';
  }
  if (strlen($value) > $limit) return rtrim(substr($value, 0, $limit - 1)) . '…';
  return $value;
}

function acJoinText($parts) {
  $clean = array();
  foreach ($parts as $part) {
    $part = acCleanText($part, 110);
    if ($part !== '' && !in_array($part, $clean)) $clean[] = $part;
  }
  return implode(' · ', $clean);
}

function acPrefixEnd($prefix) {
  for ($index = strlen($prefix) - 1; $index >= 0; $index--) {
    $code = ord($prefix[$index]);
    if ($code < 127) return substr($prefix, 0, $index) . chr($code + 1);
  }
  return $prefix . chr(127);
}

function acFetchById($db, $sql, $ids) {
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids) return array();
  $placeholders = array();
  $params = array();
  foreach ($ids as $index => $id) {
    $key = ':id' . $index;
    $placeholders[] = $key;
    $params[$key] = $id;
  }
  $sql = str_replace('__IDS__', implode(',', $placeholders), $sql);
  $sth = $db->prepare($sql);
  $sth->execute($params);
  $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
  $byId = array();
  foreach ($rows as $row) $byId[(string)$row['id']] = $row;
  return $byId;
}

function acDetailRows($db, $group, $ids) {
  switch ($group) {
    case 'locus':
      return acFetchById($db, "SELECT id, name, full_name, plant_wide_gene_name FROM mgdb.locus WHERE id IN (__IDS__)", $ids);
    case 'stock':
      return acFetchById($db, "SELECT id, name, coop_id, pedigree, country FROM mgdb.stock WHERE id IN (__IDS__)", $ids);
    case 'probe':
      return acFetchById($db, "SELECT id, name, mnemonic, repeat FROM mgdb.probe WHERE id IN (__IDS__)", $ids);
    case 'reference':
      return acFetchById($db, "SELECT id, title, author_desc, year, doi FROM mgdb.reference WHERE id IN (__IDS__)", $ids);
    case 'qtl_exp':
      return acFetchById($db, "SELECT id, name, marker_summary FROM mgdb.qtl_exp WHERE id IN (__IDS__)", $ids);
    case 'term':
      return acFetchById($db, "SELECT t.id, t.name, ty.name AS type_name, t.term_comments FROM mgdb.term t LEFT JOIN mgdb.term ty ON ty.id=t.type WHERE t.id IN (__IDS__)", $ids);
    case 'phenotype':
      return acFetchById($db, "SELECT id, name, comments FROM mgdb.phenotype WHERE id IN (__IDS__)", $ids);
    case 'variation':
      return acFetchById($db, "SELECT id, name, alleledescriptor, function FROM mgdb.variation WHERE id IN (__IDS__)", $ids);
    case 'map':
      return acFetchById($db, "SELECT id, name FROM mgdb.map WHERE id IN (__IDS__)", $ids);
    case 'person':
      return acFetchById($db, "SELECT id, name, country FROM mgdb.person WHERE id IN (__IDS__)", $ids);
    case 'gene_product':
      return acFetchById($db, "SELECT id, name FROM mgdb.gene_product WHERE id IN (__IDS__)", $ids);
  }
  return array();
}

function acRecordItem($group, $candidate, $row, $meta) {
  $id = (string)$candidate['id'];
  $label = '';
  $secondary = '';
  $url = '';

  switch ($group) {
    case 'locus':
      $label = $row['name'];
      $secondary = acJoinText(array($row['full_name'], $row['plant_wide_gene_name']));
      $url = '/data_center/locus/' . rawurlencode($id);
      break;
    case 'stock':
      $label = $row['name'];
      $secondary = acJoinText(array($row['coop_id'] ? 'Coop ID ' . $row['coop_id'] : '', $row['pedigree'], $row['country']));
      $url = '/data_center/stock/' . rawurlencode($id);
      break;
    case 'probe':
      $label = $row['name'];
      $secondary = acJoinText(array($row['mnemonic'], $row['repeat'], 'Molecular marker'));
      $url = '/data_center/marker/' . rawurlencode($id);
      break;
    case 'reference':
      $label = $row['title'] ? $row['title'] : 'Reference ' . $id;
      $secondary = acJoinText(array($row['author_desc'], $row['year'], $row['doi'] ? 'DOI ' . $row['doi'] : ''));
      $url = '/data_center/reference/' . rawurlencode($id);
      break;
    case 'qtl_exp':
      $label = $row['name'];
      $secondary = acJoinText(array('QTL experiment', $row['marker_summary']));
      $url = '/data_center/qtl/' . rawurlencode($id);
      break;
    case 'term':
      $label = $row['name'];
      $secondary = acJoinText(array($row['type_name'], $row['term_comments']));
      $url = '/data_center/term/' . rawurlencode($id);
      break;
    case 'phenotype':
      $label = $row['name'];
      $secondary = acJoinText(array('Phenotype', $row['comments']));
      $url = '/data_center/phenotype/' . rawurlencode($id);
      break;
    case 'variation':
      $label = $row['name'];
      $secondary = acJoinText(array($row['alleledescriptor'], $row['function'], 'Allele / variation'));
      $url = '/data_center/variation/' . rawurlencode($id);
      break;
    case 'map':
      $label = $row['name'];
      $secondary = 'Genetic or physical map';
      $url = '/data_center/map/' . rawurlencode($id);
      break;
    case 'person':
      $label = $row['name'];
      $secondary = acJoinText(array('Community member', $row['country']));
      $url = '/person/' . rawurlencode($id);
      break;
    case 'gene_product':
      $label = $row['name'];
      $secondary = 'Gene product';
      $url = '/data_center/gene_product/' . rawurlencode($id);
      break;
  }

  $label = acCleanText($label, 150);
  if ($label === '' || $url === '') return false;
  return array(
    'label' => $label,
    'secondary' => acCleanText($secondary, 180),
    'url' => $url,
    'badge' => $meta['badge'],
    'exact' => ((int)$candidate['match_rank'] === 0),
  );
}

$query = trim((string)$term);
$type = strtolower(trim((string)$type));
$allowedTypes = array('anything', 'gene_product', 'gene_model', 'genome', 'locus', 'probe',
  'qtl_exp', 'stock', 'reference', 'term', 'phenotype', 'variation', 'map', 'person', 'id', 'goog');
if (!in_array($type, $allowedTypes)) $type = 'anything';
if (function_exists('mb_substr')) $query = mb_substr($query, 0, 80, 'UTF-8');
else $query = substr($query, 0, 80);

if ($query === '' || (($type !== 'id') && strlen($query) < 2) || $type === 'goog') {
  acJson(array('query' => $query, 'type' => $type, 'groups' => array(), 'minimum' => 2));
}

$groupMeta = array(
  'gene_model' => array('label' => 'Genes', 'icon' => '⌘', 'badge' => 'GENE'),
  'genome' => array('label' => 'Genomes', 'icon' => '◎', 'badge' => 'GENOME'),
  'locus' => array('label' => 'Loci', 'icon' => '●', 'badge' => 'LOCUS'),
  'probe' => array('label' => 'Markers', 'icon' => '⚑', 'badge' => 'MARKER'),
  'stock' => array('label' => 'Stocks / germplasm', 'icon' => '🌽', 'badge' => 'STOCK'),
  'reference' => array('label' => 'References', 'icon' => '▤', 'badge' => 'REF'),
  'qtl_exp' => array('label' => 'QTL experiments', 'icon' => '▥', 'badge' => 'QTL'),
  'term' => array('label' => 'Traits and terms', 'icon' => '◇', 'badge' => 'TRAIT'),
  'phenotype' => array('label' => 'Phenotypes', 'icon' => '◐', 'badge' => 'PHENO'),
  'variation' => array('label' => 'Variations / alleles', 'icon' => '△', 'badge' => 'ALLELE'),
  'gene_product' => array('label' => 'Gene products', 'icon' => 'G', 'badge' => 'PRODUCT'),
  'map' => array('label' => 'Maps', 'icon' => '⌖', 'badge' => 'MAP'),
  'person' => array('label' => 'People / organizations', 'icon' => '○', 'badge' => 'PERSON'),
  'id' => array('label' => 'MaizeGDB ID', 'icon' => '#', 'badge' => 'ID'),
);

$typeTables = array(
  'anything' => array('locus', 'stock', 'probe', 'full_reference', 'qtl_exp', 'term', 'phenotype', 'variation', 'map', 'person', 'gene_product'),
  'gene_product' => array('locus', 'gene_product'),
  'locus' => array('locus'), 'probe' => array('probe'), 'qtl_exp' => array('qtl_exp'),
  'stock' => array('stock'), 'reference' => array('full_reference'), 'term' => array('term'),
  'phenotype' => array('phenotype'), 'variation' => array('variation'), 'map' => array('map'),
  'person' => array('person'),
);

$start = microtime(true);
$groups = array();
$groupsByKey = array();
$topHit = null;

try {
  $DBConn->exec("SET statement_timeout TO 2200");

  /*
   * Exact stock names are a common navigation query (especially B73). Use the
   * existing stock name/Coop ID btree indexes before the broader text search,
   * and expose the canonical germplasm record as the promoted result.
   */
  if ($type === 'anything' || $type === 'stock') {
    $stockCases = array($query, strtoupper($query), strtolower($query), ucfirst(strtolower($query)));
    $stockCases = array_values(array_unique($stockCases));
    while (count($stockCases) < 4) $stockCases[] = $stockCases[0];
    $stockSql = "
      SELECT s.id, s.name, s.coop_id, s.pedigree, s.country, t.name AS type_name
      FROM mgdb.stock s
      INNER JOIN mgdb.id_num idn ON idn.id=s.id AND idn.curation_lvl=0
      LEFT JOIN mgdb.term t ON t.id=s.type
      WHERE s.name IN (:n1,:n2,:n3,:n4) OR s.coop_id IN (:c1,:c2,:c3,:c4)
      ORDER BY CASE WHEN s.name=:original_name OR s.coop_id=:original_coop THEN 0 ELSE 1 END, s.id
      LIMIT 1";
    $sth = $DBConn->prepare($stockSql);
    $sth->execute(array(
      ':n1'=>$stockCases[0], ':n2'=>$stockCases[1], ':n3'=>$stockCases[2], ':n4'=>$stockCases[3],
      ':c1'=>$stockCases[0], ':c2'=>$stockCases[1], ':c3'=>$stockCases[2], ':c4'=>$stockCases[3],
      ':original_name'=>$query, ':original_coop'=>$query,
    ));
    if ($stock = $sth->fetch(PDO::FETCH_ASSOC)) {
      $typeName = $stock['type_name'] ? ucfirst($stock['type_name']) : 'Germplasm stock';
      $topHit = array(
        'label' => acCleanText($stock['name'], 150),
        'secondary' => acJoinText(array($typeName, $stock['pedigree'], $stock['country'])),
        'url' => '/data_center/stock/' . rawurlencode($stock['id']),
        'icon' => $groupMeta['stock']['icon'],
        'badge' => $groupMeta['stock']['badge'],
        'action' => 'Go to germplasm record',
      );
    }
  }

  if (isset($typeTables[$type])) {
    $tableNames = $typeTables[$type];
    $quoted = array();
    foreach ($tableNames as $tableName) $quoted[] = $DBConn->quote($tableName);
    $lexemes = preg_split('/[^a-z0-9_]+/i', strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
    $tsquery = implode(' & ', array_map(function($word) { return $word . ':*'; }, $lexemes));
    if ($tsquery !== '') {
      $sql = "
        -- The curation filter is expressed as two anti/semi-joins rather than an
        -- INNER JOIN on curation_lvl=0. id_num holds 4.17M rows of which 99.3%
        -- are already curation_lvl=0, so joining to keep that 99.3% forced a
        -- hash build over the whole table (~600ms of a ~750ms query). Excluding
        -- the small curation set instead, plus an existence guard so ids with no
        -- id_num row stay excluded exactly as before, is 2.25x faster.
        -- Verified identical result sets over b73, b1, kernel, mo17, waxy,
        -- zm00001eb, dwarf, and anthocyanin. See ADMIN_DEPENDENCIES.md AD-007.
        WITH direct_matches AS (
          SELECT s.id,
            CASE WHEN s.table_name='full_reference' THEN 'reference' ELSE s.table_name END AS group_name,
            MIN(CASE WHEN lower(s.text)=:exact THEN 0
                     WHEN lower(s.text) LIKE :prefix THEN 1 ELSE 2 END) AS match_rank
          FROM mgdb.all_text_search s
          WHERE to_tsvector('english', s.text) @@ to_tsquery('english', :tsquery)
            AND s.table_name IN (" . implode(',', $quoted) . ")
            AND NOT EXISTS (SELECT 1 FROM mgdb.id_num idn WHERE idn.id=s.id AND idn.curation_lvl<>0)
            AND EXISTS     (SELECT 1 FROM mgdb.id_num idn WHERE idn.id=s.id)
          GROUP BY 1, 2
        ), ranked AS (
          SELECT id, group_name, match_rank,
                 ROW_NUMBER() OVER (PARTITION BY group_name ORDER BY match_rank, id) AS group_rank,
                 COUNT(*) OVER (PARTITION BY group_name) AS group_count
          FROM direct_matches
        )
        SELECT id, group_name, match_rank, group_count
        FROM ranked WHERE group_rank <= 4
        ORDER BY group_name, group_rank";
      $sth = $DBConn->prepare($sql);
      $lower = strtolower($query);
      $sth->execute(array(':exact' => $lower, ':prefix' => $lower . '%', ':tsquery' => $tsquery));
      $candidates = $sth->fetchAll(PDO::FETCH_ASSOC);

      $byGroup = array();
      foreach ($candidates as $candidate) {
        $group = $candidate['group_name'];
        if (!isset($groupMeta[$group])) continue;
        if (!isset($byGroup[$group])) $byGroup[$group] = array();
        $byGroup[$group][] = $candidate;
      }
      foreach ($byGroup as $group => $groupCandidates) {
        $details = acDetailRows($DBConn, $group, array_column($groupCandidates, 'id'));
        $items = array();
        foreach ($groupCandidates as $candidate) {
          $id = (string)$candidate['id'];
          if (!isset($details[$id])) continue;
          $item = acRecordItem($group, $candidate, $details[$id], $groupMeta[$group]);
          if ($item) $items[] = $item;
        }
        if ($items) {
          $groupsByKey[$group] = array(
            'key' => $group,
            'label' => $groupMeta[$group]['label'],
            'icon' => $groupMeta[$group]['icon'],
            'total' => (int)$groupCandidates[0]['group_count'],
            'items' => $items,
          );
        }
      }
    }
  }

  if ($type === 'anything' || $type === 'gene_product' || $type === 'gene_model') {
    $lower = strtolower($query);
    $geneIdentifierQuery = preg_match('/^(zm|grm|ac|zeammb73)/i', $query);
    if ($geneIdentifierQuery) {
      // Use the indexed lower(gene_name) range directly. Avoiding DISTINCT here
      // lets PostgreSQL stop as soon as it has enough identifier suggestions.
      $geneSql = "
        SELECT gene_name, locus_name, locus_id, version, assembly_version,
          CASE WHEN lower(gene_name)=:exact OR lower(locus_name)=:exact THEN 0
               WHEN lower(gene_name) LIKE :prefix THEN 1 ELSE 2 END AS match_rank,
          CASE WHEN assembly_version ILIKE '%NAM-5.0%' THEN 0
               WHEN assembly_version ILIKE '%RefGen_v4%' THEN 1 ELSE 2 END AS assembly_rank
        FROM chado.gene_model
        WHERE is_obsolete IS NOT TRUE
          AND lower(gene_name) >= :range_start AND lower(gene_name) < :range_end
        ORDER BY lower(gene_name), assembly_rank, version DESC LIMIT 24";
    }
    else {
      $geneSql = "
        WITH matching_loci AS (
          SELECT id
          FROM mgdb.locus
          WHERE (name >= :locus_raw AND name < :locus_raw_end)
             OR (name >= :locus_upper AND name < :locus_upper_end)
             OR (name >= :locus_lower AND name < :locus_lower_end)
          ORDER BY CASE WHEN lower(name)=:locus_order_exact THEN 0 ELSE 1 END, id LIMIT 40
        ), candidates AS (
          SELECT gene_name, locus_name, locus_id, version, assembly_version,
            CASE WHEN lower(gene_name)=:exact OR lower(locus_name)=:exact THEN 0
                 WHEN lower(gene_name) LIKE :prefix THEN 1 ELSE 2 END AS match_rank,
            CASE WHEN assembly_version ILIKE '%NAM-5.0%' THEN 0
                 WHEN assembly_version ILIKE '%RefGen_v4%' THEN 1 ELSE 2 END AS assembly_rank
          FROM chado.gene_model
          WHERE is_obsolete IS NOT TRUE
            AND lower(gene_name) >= :range_start AND lower(gene_name) < :range_end
          UNION ALL
          SELECT gm.gene_name, gm.locus_name, gm.locus_id, gm.version, gm.assembly_version,
            CASE WHEN lower(gm.gene_name)=:exact_locus OR lower(gm.locus_name)=:exact_locus THEN 0 ELSE 2 END,
            CASE WHEN gm.assembly_version ILIKE '%NAM-5.0%' THEN 0
                 WHEN gm.assembly_version ILIKE '%RefGen_v4%' THEN 1 ELSE 2 END
          FROM matching_loci ml
          INNER JOIN chado.gene_model gm ON gm.locus_id=ml.id
          WHERE gm.is_obsolete IS NOT TRUE
        ), dedup AS (
        SELECT DISTINCT ON (gene_name) gene_name, locus_name, locus_id, version, assembly_version, match_rank, assembly_rank
        FROM candidates ORDER BY gene_name, match_rank, assembly_rank, version DESC
        )
        SELECT * FROM dedup ORDER BY match_rank, assembly_rank, gene_name LIMIT 5";
    }
    $geneParams = array(
      ':exact' => $lower,
      ':prefix' => $lower . '%',
      ':range_start' => $lower,
      ':range_end' => acPrefixEnd($lower),
    );
    if (!$geneIdentifierQuery) {
      $geneParams += array(
        ':locus_raw' => $query,
        ':locus_raw_end' => acPrefixEnd($query),
        ':locus_upper' => strtoupper($query),
        ':locus_upper_end' => acPrefixEnd(strtoupper($query)),
        ':locus_lower' => strtolower($query),
        ':locus_lower_end' => acPrefixEnd(strtolower($query)),
        ':locus_order_exact' => $lower,
        ':exact_locus' => $lower,
      );
    }
    $sth = $DBConn->prepare($geneSql);
    $sth->execute($geneParams);
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    $uniqueGeneRows = array();
    $seenGenes = array();
    foreach ($rows as $row) {
      $geneKey = strtolower($row['gene_name']);
      if (isset($seenGenes[$geneKey])) continue;
      $seenGenes[$geneKey] = true;
      $uniqueGeneRows[] = $row;
      if (count($uniqueGeneRows) >= 5) break;
    }
    $hasMoreGenes = count($uniqueGeneRows) > 4;
    $items = array();
    foreach (array_slice($uniqueGeneRows, 0, 4) as $row) {
      $locusLabel = $row['locus_name'] ? 'Locus ' . $row['locus_name'] : 'Associated locus';
      $locusIdLabel = $row['locus_id'] ? 'Locus ID ' . $row['locus_id'] : '';
      $secondary = acJoinText(array($locusLabel, $locusIdLabel, $row['assembly_version'], $row['version']));
      $item = array(
        'label' => acCleanText($row['gene_name'], 150),
        'secondary' => $secondary,
        'url' => '/gene_center/gene/' . rawurlencode($row['gene_name']),
        'badge' => $groupMeta['gene_model']['badge'],
        'exact' => ((int)$row['match_rank'] === 0),
      );
      $items[] = $item;
      if ($topHit === null && strtolower($row['gene_name']) === $lower) {
        $topHit = $item + array(
          'icon' => $groupMeta['gene_model']['icon'],
          'action' => 'Go to gene model',
        );
      }
    }
    if ($items) {
      $groupsByKey['gene_model'] = array(
        'key' => 'gene_model', 'label' => $groupMeta['gene_model']['label'],
        'icon' => $groupMeta['gene_model']['icon'], 'total' => count($items),
        'has_more' => $hasMoreGenes, 'items' => $items,
      );
    }
  }

  if ($type === 'anything' || $type === 'genome') {
    $genomeSql = "SELECT project, assembly_name, annotation
                  FROM chado.genome_metadata
                  WHERE assembly_name ILIKE :contains OR project ILIKE :contains OR annotation ILIKE :contains
                  ORDER BY CASE WHEN lower(assembly_name)=:exact THEN 0
                                WHEN lower(assembly_name) LIKE :prefix THEN 1 ELSE 2 END,
                           assembly_name LIMIT 4";
    $sth = $DBConn->prepare($genomeSql);
    $lower = strtolower($query);
    $sth->execute(array(':contains' => '%' . $query . '%', ':exact' => $lower, ':prefix' => $lower . '%'));
    $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
    $items = array();
    foreach ($rows as $row) {
      $items[] = array(
        'label' => acCleanText($row['assembly_name'], 150),
        'secondary' => acJoinText(array($row['project'], $row['annotation'])),
        'url' => '/genome/assembly/' . rawurlencode($row['assembly_name']),
        'badge' => $groupMeta['genome']['badge'],
        'exact' => (strtolower($row['assembly_name']) === $lower),
      );
    }
    if ($items) {
      $groupsByKey['genome'] = array(
        'key' => 'genome', 'label' => $groupMeta['genome']['label'], 'icon' => $groupMeta['genome']['icon'],
        'total' => count($items), 'items' => $items,
      );
    }
  }

  if (($type === 'id' || $type === 'anything') && ctype_digit($query)) {
    $sth = $DBConn->prepare("SELECT idn.id, t.name AS record_type FROM mgdb.id_num idn JOIN mgdb.term t ON t.id=idn.type_term WHERE idn.id=:id AND idn.curation_lvl=0 LIMIT 1");
    $sth->execute(array(':id' => (int)$query));
    if ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
      $recordTypeToUrl = array('Locus'=>'/data_center/locus/', 'Stock'=>'/data_center/stock/', 'Probe'=>'/data_center/marker/',
        'Reference'=>'/data_center/reference/', 'QTL Experiment'=>'/data_center/qtl/', 'Term'=>'/data_center/term/',
        'Phenotype'=>'/data_center/phenotype/', 'Variation'=>'/data_center/variation/', 'Map'=>'/data_center/map/',
        'Person'=>'/person/', 'Gene Product'=>'/data_center/gene_product/');
      if (isset($recordTypeToUrl[$row['record_type']])) {
        $groupsByKey['id'] = array(
          'key'=>'id', 'label'=>$groupMeta['id']['label'], 'icon'=>$groupMeta['id']['icon'], 'total'=>1,
          'items'=>array(array('label'=>'MaizeGDB ID ' . $row['id'], 'secondary'=>$row['record_type'],
            'url'=>$recordTypeToUrl[$row['record_type']] . rawurlencode($row['id']), 'badge'=>'ID', 'exact'=>true)),
        );
      }
    }
  }

  $priority = array('id', 'gene_model', 'locus', 'stock', 'probe', 'reference', 'genome', 'qtl_exp',
                    'term', 'phenotype', 'variation', 'gene_product', 'map', 'person');
  foreach ($priority as $key) if (isset($groupsByKey[$key])) $groups[] = $groupsByKey[$key];

  if ($topHit !== null) {
    foreach ($groups as &$group) {
      $group['items'] = array_values(array_filter($group['items'], function($item) use ($topHit) {
        return $item['url'] !== $topHit['url'];
      }));
    }
    unset($group);
    $groups = array_values(array_filter($groups, function($group) { return count($group['items']) > 0; }));
  }

  acJson(array(
    'query' => $query,
    'type' => $type,
    'top_hit' => $topHit,
    'groups' => $groups,
    'duration_ms' => (int)round((microtime(true) - $start) * 1000),
  ));
}
catch (Throwable $error) {
  logMessage('Autocomplete error: ' . $error->getMessage());
  acJson(array('query' => $query, 'type' => $type, 'groups' => array(), 'error' => 'Suggestions are temporarily unavailable.'), 503);
}
?>
