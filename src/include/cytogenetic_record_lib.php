<?php
/* file: include/cytogenetic_record_lib.php
 *
 * purpose: resolve a cytogenetic identifier to the record page that holds it.
 *
 *          Cytogenetics is not a record type. Unlike the SSR, overgo, EST and
 *          BAC data centres -- each of which is a set of mgdb.probe rows with
 *          its own record page -- this data centre is a topic: it gathers
 *          three kinds of record that live elsewhere.
 *
 *            cytological maps        mgdb.map      -> /data_center/map/{id}
 *            cytological landmarks   mgdb.locus    -> /data_center/locus?id=
 *            structural variants     mgdb.stock    -> /data_center/stock?id=
 *
 *          There has never been a cytogenetic.php record controller, and
 *          /data_center/cytogenetic?id=X answered HTTP 200 with the
 *          pre-redesign search page and ignored the id. This file is what
 *          turns such a URL into the record the reader actually wanted.
 *
 *          The three membership tests are the ones the hub itself uses, in
 *          controllers/data_center/cytogenetic_search_modern.php. They are
 *          repeated rather than shared because the hub's are counts over the
 *          whole collection and these are single-row probes; keeping them
 *          textually alike is the point.
 */

/* 121 Centromere, 122 Telomere, 24978 Cytological Structure,
   111 Chromosomal Segment -- the four locus classes the hub retrieves. */
function cytogeneticLandmarkTypes() { return array(121, 122, 24978, 111); }

function cytogeneticMapFilter($alias = 'm') {
  return "(LOWER($alias.name) LIKE 'cytological %'
        OR LOWER($alias.name) LIKE 'fsu cytogenetic fish%'
        OR LOWER($alias.name) LIKE 'b chromosome %'
        OR LOWER($alias.name) LIKE 'b rapds%')";
}

function cytogeneticStockFilter($alias = 't') {
  return "(LOWER($alias.name) LIKE '%translocation%'
        OR LOWER($alias.name) LIKE '%inversion%'
        OR LOWER($alias.name) LIKE '%chromosome%'
        OR LOWER($alias.name) LIKE '%ploid%'
        OR LOWER($alias.name) LIKE '%addition%'
        OR LOWER($alias.name) LIKE '%b-a%')";
}

/* Accepts a numeric MaizeGDB id or a name, and answers which of the three
   collections holds it. A map wins over a locus and a locus over a stock,
   which is the order the hub lists them in.

   Returns array('kind', 'id', 'name', 'html'), or false. Costs about 9 ms. */
function cytogeneticResolve($DBConn, $identifier) {
  $identifier = trim((string) $identifier);
  if ($identifier === '' || strlen($identifier) > 200) {
    return false;
  }

  $numeric = ctype_digit($identifier) ? (int) $identifier : 0;
  $landmarks = implode(',', cytogeneticLandmarkTypes());
  $map_filter = cytogeneticMapFilter();
  $stock_filter = cytogeneticStockFilter();

  $row = retrieve_row(make_query($DBConn, "
    SELECT kind, id, name FROM (
      SELECT 'map'::text AS kind, m.id, m.name, 0 AS rank
      FROM mgdb.map m
        INNER JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
      WHERE (m.id = :n1 OR m.name = :s1) AND $map_filter
      UNION ALL
      SELECT 'locus', l.id, l.name, 1
      FROM mgdb.locus l
        INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
      WHERE l.type IN ($landmarks) AND (l.id = :n2 OR l.name = :s2)
      UNION ALL
      SELECT 'stock', s.id, s.name, 2
      FROM mgdb.stock s
        INNER JOIN mgdb.term t ON t.id = s.type
        INNER JOIN mgdb.id_num i ON i.id = s.id AND i.curation_lvl IN (0, 101, 102)
      WHERE (s.id = :n3 OR s.name = :s3) AND $stock_filter
    ) x
    ORDER BY rank
    LIMIT 1", 1, array(
      'n1' => $numeric, 's1' => $identifier,
      'n2' => $numeric, 's2' => $identifier,
      'n3' => $numeric, 's3' => $identifier)));

  if (!$row) {
    return false;
  }

  $kind = trim((string) $row['kind']);
  $id = (int) $row['id'];
  $routes = array(
    'map' => '/data_center/map/' . $id,
    'locus' => '/data_center/locus?id=' . $id,
    'stock' => '/data_center/stock?id=' . $id
  );

  return array(
    'kind' => $kind,
    'id' => $id,
    'name' => trim((string) $row['name']),
    'html' => $routes[$kind]
  );
}//cytogeneticResolve


/* What to offer when nothing resolved: members of the three collections whose
   name begins with the term.

   Anchored, and each arm bounded, because mgdb.stock and mgdb.locus are large
   and no index serves LIKE under this collation (AD-030).

   'detail' is whatever stands for the record's own type within its collection:
   the term name for a landmark or a stock, and the chromosome for a map, which
   has no type of its own. Naming the collection there instead would just repeat
   the kind column.

   Returns a list of array('kind', 'id', 'name', 'detail', 'html'). */
function cytogeneticSuggestions($DBConn, $term, $limit = 8) {
  $out = array();
  $term = trim((string) $term);
  if ($term === '' || strlen($term) > 200) {
    return $out;
  }

  $like = addcslashes($term, '%_\\') . '%';
  $landmarks = implode(',', cytogeneticLandmarkTypes());
  $map_filter = cytogeneticMapFilter();
  $stock_filter = cytogeneticStockFilter();

  $sth = make_query($DBConn, "
    SELECT kind, id, name, detail FROM (
      (SELECT 'map'::text AS kind, m.id, m.name,
              CASE WHEN lg.name IS NULL OR lg.name = '' THEN ''
                   ELSE 'Chromosome ' || lg.name END AS detail, 0 AS rank
       FROM mgdb.map m
         INNER JOIN mgdb.id_num i ON i.id = m.id AND i.curation_lvl = 0
         LEFT JOIN mgdb.linkage_group lg ON lg.id = m.linkage_group
       WHERE m.name ILIKE :l1 AND $map_filter
       ORDER BY lower(m.name) LIMIT :lim1)
      UNION ALL
      (SELECT 'locus', l.id, l.name, tl.name, 1
       FROM mgdb.locus l
         INNER JOIN mgdb.id_num i ON i.id = l.id AND i.curation_lvl = 0
         LEFT JOIN mgdb.term tl ON tl.id = l.type
       WHERE l.type IN ($landmarks) AND l.name ILIKE :l2
       ORDER BY lower(l.name) LIMIT :lim2)
      UNION ALL
      (SELECT 'stock', s.id, s.name, t.name, 2
       FROM mgdb.stock s
         INNER JOIN mgdb.term t ON t.id = s.type
         INNER JOIN mgdb.id_num i ON i.id = s.id AND i.curation_lvl IN (0, 101, 102)
       WHERE s.name ILIKE :l3 AND $stock_filter
       ORDER BY lower(s.name) LIMIT :lim3)
    ) x
    ORDER BY rank, length(name), lower(name)
    LIMIT :lim", 1, array(
      'l1' => $like, 'l2' => $like, 'l3' => $like,
      'lim1' => (int) $limit, 'lim2' => (int) $limit, 'lim3' => (int) $limit,
      'lim' => (int) $limit));
  $routes = array(
    'map' => '/data_center/map/',
    'locus' => '/data_center/locus?id=',
    'stock' => '/data_center/stock?id='
  );
  while ($row = retrieve_row($sth)) {
    $kind = trim((string) $row['kind']);
    $out[] = array(
      'kind' => $kind,
      'id' => (int) $row['id'],
      'name' => trim((string) $row['name']),
      'detail' => trim((string) $row['detail']),
      'html' => $routes[$kind] . (int) $row['id']
    );
  }

  return $out;
}//cytogeneticSuggestions
?>
