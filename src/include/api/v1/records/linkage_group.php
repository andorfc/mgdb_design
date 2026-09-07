<?php
/* file: api/v1/records/linkage_group.php
 *
 * purpose: assemble a complete linkage group record as JSON in one request.
 *          Included by controllers/api.php with $api_identifier and $DBConn set.
 *
 *          Replaces record_data/lg_data.php, which answered five separate Ajax
 *          calls and had no cap on the loci one: /record_data/lg_data.php?id=13579&type=loci
 *          returned 8,996 KB in 5.8 s, a grid of 94,465 locus links. Here the
 *          list is capped like every other collection in this API and the true
 *          count travels beside it in meta.counts, so a client can say
 *          "94,465, showing the first 500" instead of shipping all of them.
 */

if (!defined('MGDB_API')) { http_response_code(404); exit; }

include_once(__DIR__ . '/../../../linkage_group_record_lib.php');

$SECTIONS = array('overview', 'maps', 'loci', 'references');
$wanted = MgdbApi::sections($SECTIONS);
$want = array_flip($wanted);
$max_items = MgdbApi::maxItems();

$found_id = lgResolveId($DBConn, $api_identifier);
MgdbApi::countQuery(2);

if ($found_id === false) {
  MgdbApi::problem(404, 'record-not-found', 'Linkage group not found',
    'No linkage group record matches that id, name, or synonym.',
    array('identifier' => $api_identifier));
}

$identity = lgIdentity($DBConn, $found_id);
MgdbApi::countQuery();

if (!$identity) {
  MgdbApi::problem(404, 'record-not-found', 'Linkage group not found',
    'A record with that id was once known but is no longer current.',
    array('id' => $found_id));
}

$id = (int) $identity['id'];
$name = MgdbApi::text($identity['name']);
$sections = array();
$counts = array();
$truncated = array();

/* The curated locus count. 350 ms on a maize chromosome, and the reason this
   is one request rather than a fact rendered before the first paint. It is
   needed by both the loci section and the metrics, so it is read once. */
$locus_count_row = retrieve_row(make_query($DBConn, "
  SELECT count(*) AS n
  FROM mgdb.locus l
  JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
  WHERE l.linkage_group = :id", 1, array('id' => $id)));
MgdbApi::countQuery();
$locus_count = $locus_count_row ? (int) $locus_count_row['n'] : 0;

/////
// Overview
/////

if (isset($want['overview'])) {
  $synonyms = lgSynonyms($DBConn, $id, $identity['name']);
  MgdbApi::countQuery();

  /* Curator notes. linkage_group.comments and the memo rows overlap -- on
     every maize chromosome they carry the same "Arm ratio and total length
     determined in Seneca 60" sentence -- so the memo is dropped when it
     restates the comment rather than printing the sentence twice. */
  $notes = array();
  $seen = array();
  if ($identity['comments'] !== '') {
    $notes[] = array('text' => MgdbApi::text($identity['comments']), 'source' => 'Record comment');
    $seen[preg_replace('/\s+/', ' ', strtolower($identity['comments']))] = true;
  }
  $memo_sth = make_query($DBConn, "
    SELECT m.memo, t.name AS memo_type
    FROM mgdb.memo m
    LEFT JOIN mgdb.term t ON t.id = m.type_term
    WHERE m.id = :id AND m.memo IS NOT NULL AND trim(m.memo) <> ''
    ORDER BY m.memo", 1, array('id' => $id));
  MgdbApi::countQuery();
  while ($m_row = retrieve_row($memo_sth)) {
    $text = trim((string) $m_row['memo']);
    $key = preg_replace('/\s+/', ' ', strtolower($text));
    if ($text === '' || isset($seen[$key])) { continue; }
    $seen[$key] = true;
    $label = trim((string) $m_row['memo_type']);
    $notes[] = array(
      'text' => MgdbApi::text($text),
      'source' => ($label === '' || strcasecmp($label, 'Not specified') === 0) ? 'Curator note' : $label
    );
  }

  /* Offsite records. The legacy page ran one query for the keys and then two
     more per key -- the person and its url_prefix -- which is 19 queries for
     the plastid's nine accessions. One join answers all of them. A prefix that
     is absent leaves the accession as plain text rather than a broken link. */
  $external = array();
  $ext_sth = make_query($DBConn, "
    SELECT e.key, p.name AS db_name, u.url_prefix
    FROM mgdb.ext_db_key e
    JOIN mgdb.id_num i ON i.id = e.db_person AND i.curation_lvl = 0
    LEFT JOIN mgdb.person p ON p.id = e.db_person
    LEFT JOIN mgdb.person_url_prefix u ON u.id = e.db_person
    WHERE e.id = :id
    ORDER BY p.name, e.key", 1, array('id' => $id));
  MgdbApi::countQuery();
  while ($e_row = retrieve_row($ext_sth)) {
    $key = trim((string) $e_row['key']);
    if ($key === '') { continue; }
    $prefix = trim((string) $e_row['url_prefix']);
    $external[] = array(
      'database' => MgdbApi::text($e_row['db_name']),
      'accession' => $key,
      'html' => $prefix !== '' ? $prefix . $key : null
    );
  }

  $counts['notes'] = count($notes);
  $counts['synonyms'] = count($synonyms);
  $counts['external'] = count($external);

  $sections['overview'] = array(
    'id' => $id,
    'name' => $name,
    'type' => MgdbApi::text($identity['type']),
    'species' => MgdbApi::text($identity['species']),
    'chromosome' => MgdbApi::text($identity['chromosome']),
    'morphology' => MgdbApi::text($identity['morphology']),
    'length_cm' => $identity['length_cm'],
    'length_kb' => $identity['length_kb'],
    'synonyms' => $synonyms,
    'notes' => $notes,
    'external' => $external
  );
}

/////
// Maps assigned to this linkage group
/////

if (isset($want['maps'])) {
  $maps = array();
  $map_sth = make_query($DBConn, "
    SELECT m.id, m.name, t.name AS coordinate_type,
           (SELECT count(*) FROM mgdb.locus_coordinates lc WHERE lc.map = m.id) AS locus_count
    FROM mgdb.map m
    JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
    LEFT JOIN mgdb.term t ON t.id = m.coordinates
    WHERE m.linkage_group = :id
    ORDER BY m.name
    LIMIT :limit", 1, array('id' => $id, 'limit' => $max_items));
  MgdbApi::countQuery();
  while ($m_row = retrieve_row($map_sth)) {
    $maps[] = array(
      'id' => (int) $m_row['id'],
      'name' => MgdbApi::text($m_row['name']),
      'coordinate_type' => MgdbApi::text($m_row['coordinate_type']) ?: 'cM',
      'locus_count' => (int) $m_row['locus_count'],
      'html' => '/data_center/map/' . (int) $m_row['id']
    );
  }
  $counts['maps'] = (int) $identity['map_count'];
  if (count($maps) < $counts['maps']) {
    $truncated[] = 'maps';
  }
  $sections['maps'] = $maps;
}

/////
// Loci placed on this linkage group
/////

if (isset($want['loci'])) {
  $loci = array();
  $counts['loci'] = $locus_count;

  /* ORDER BY l.name, not lower(l.name). The index on locus.name answers the
     first arrangement in 10 ms and has to sort all 95,325 rows for the second:
     164 ms, seventeen times the work, for a case fold nobody asked for. */
  $locus_sth = make_query($DBConn, "
    SELECT l.id, l.name, l.full_name, t.name AS locus_type
    FROM mgdb.locus l
    JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
    LEFT JOIN mgdb.term t ON t.id = l.type
    WHERE l.linkage_group = :id
    ORDER BY l.name
    LIMIT :limit", 1, array('id' => $id, 'limit' => $max_items));
  MgdbApi::countQuery();
  while ($l_row = retrieve_row($locus_sth)) {
    $loci[] = array(
      'id' => (int) $l_row['id'],
      'name' => MgdbApi::text($l_row['name']),
      'full_name' => MgdbApi::text($l_row['full_name']),
      'locus_type' => MgdbApi::text($l_row['locus_type']) ?: 'Locus',
      'html' => '/data_center/locus?id=' . (int) $l_row['id']
    );
  }
  if (count($loci) < $counts['loci']) {
    $truncated[] = 'loci';
  }
  $sections['loci'] = $loci;
}

/////
// References
/////

if (isset($want['references'])) {
  $references = array();
  $ref_sth = make_query($DBConn, "
    SELECT r.id, r.name, r.title, r.year, r.doi, r.author_desc,
           t.name AS contents, t_type.name AS pub_type
    FROM mgdb.id_reference ir
    JOIN mgdb.reference r ON r.id = ir.reference
    JOIN mgdb.id_num i ON i.id = ir.reference AND i.curation_lvl = 0
    LEFT JOIN mgdb.term t ON t.id = ir.contents
    LEFT JOIN mgdb.term t_type ON t_type.id = r.type
    WHERE ir.id = :id
    ORDER BY r.year DESC NULLS LAST, LOWER(r.name)", 1, array('id' => $id));
  MgdbApi::countQuery();
  while ($r_row = retrieve_row($ref_sth)) {
    $doi = MgdbApi::text($r_row['doi']);
    if ($doi && preg_match('/(?:doi:\s*|https?:\/\/doi\.org\/)?(10\.\d{4,9}\/[-._;()\/:A-Z0-9]+)/i', $doi, $m)) {
      $doi = $m[1];
    } elseif (preg_match('/(?:doi:\s*|https?:\/\/doi\.org\/)?(10\.\d{4,9}\/[-._;()\/:A-Z0-9]+)/i', (string) $r_row['name'], $m)) {
      $doi = $m[1];
    } else {
      $doi = null;
    }
    $references[] = array(
      'type' => 'reference',
      'id' => MgdbApi::int($r_row['id']),
      'citation' => MgdbApi::text($r_row['name']),
      'title' => MgdbApi::text($r_row['title']),
      'authors' => MgdbApi::text($r_row['author_desc']),
      'year' => MgdbApi::int($r_row['year']),
      'doi' => $doi,
      'pub_type' => MgdbApi::text($r_row['pub_type']) ?: 'Journal article',
      'relevance' => MgdbApi::text($r_row['contents']),
      'html' => '/data_center/reference?id=' . (int) $r_row['id']
    );
  }
  $counts['references'] = count($references);
  $sections['references'] = $references;
}

MgdbApi::send('linkage_group', $id,
  array(
    'name' => $name,
    'type' => MgdbApi::text($identity['type']),
    'species' => MgdbApi::text($identity['species']),
    'chromosome' => MgdbApi::text($identity['chromosome']),
    'locus_count' => $locus_count,
    'map_count' => (int) $identity['map_count']
  ),
  $sections,
  array(
    'html' => MgdbApi::baseUrl() . '/data_center/lg/' . $id,
    'search' => MgdbApi::baseUrl() . '/data_center/lg'
  ),
  array(
    'resolved_from' => $api_identifier,
    'sections_returned' => array_values($wanted),
    'sections_available' => $SECTIONS,
    'partial' => count($wanted) !== count($SECTIONS),
    'max_items' => $max_items,
    'truncated' => $truncated,
    'counts' => $counts
  ),
  300
);
?>
