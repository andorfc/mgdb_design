<?php
/* file: linkage_group_record_lib.php
 *
 * purpose: resolution and identity helpers for the Linkage Group record page
 *          (/data_center/lg and /data_center/lg/{id}) and for
 *          /api/v1/records/linkage_group/{id}.
 *
 *          mgdb.linkage_group is a small table -- 158 curated rows -- and it
 *          holds rather more than its name suggests. 37 rows are chromosomes;
 *          the rest are the molecular entities a locus can otherwise sit on:
 *          82 plasmids, 11 mitochondrial plasmids, 4 BACs, phage, phasmids, a
 *          YAC, a cosmid, a fosmid, the plastid genome. Species runs to
 *          E. coli (30), Zea mays (23), Oryza and Sorghum (12 each). So the
 *          record page leads with the type, not with an assumption of maize.
 *
 *          The expensive fact here is the number of loci: the ten maize
 *          chromosomes carry 39,771 to 94,465 each, and counting them through
 *          the curation join costs about 350 ms against 9 ms for the raw
 *          count. The join is kept -- every hub counts
 *          `JOIN id_num i ON i.id = x.id WHERE i.curation_lvl = 0`, and
 *          dropping it here would advertise 95,325 loci on chromosome 1 at a
 *          page the Locus hub says has 94,465 -- but it is left to the API
 *          call rather than run before the first paint. lgIdentity() is the
 *          cheap half.
 */

if (!defined('LG_RECORD_LIB')) {
  define('LG_RECORD_LIB', 1);

  /* Identifier -> canonical id, or false.

     Numeric input is tried as an id first and that cannot be ambiguous: the
     lowest linkage_group id is 11064 and the only all-digit names are "1" to
     "10", the maize chromosomes. So /data_center/lg/1 resolves to chromosome
     1 by name, which is what the two requests for it in the log wanted; it
     had been answering "Lg record not found for ID: 1!". */
  function lgResolveId($DBConn, $identifier) {
    $identifier = trim((string) $identifier);
    if ($identifier === '' || strlen($identifier) > 200) {
      return false;
    }

    if (ctype_digit($identifier)) {
      $row = retrieve_row(make_query($DBConn, "
        SELECT lg.id
        FROM mgdb.linkage_group lg
        JOIN mgdb.id_num i ON i.id = lg.id AND i.curation_lvl = 0
        WHERE lg.id = :id", 1, array('id' => (int) $identifier)));
      if ($row && isset($row['id'])) {
        return (int) $row['id'];
      }
    }

    /* Exact name. Ordered by id because one name is not unique: pAD-GAL4 is
       both 148517 and 179708. */
    $row = retrieve_row(make_query($DBConn, "
      SELECT lg.id
      FROM mgdb.linkage_group lg
      JOIN mgdb.id_num i ON i.id = lg.id AND i.curation_lvl = 0
      WHERE LOWER(lg.name) = LOWER(:name)
      ORDER BY lg.id ASC
      LIMIT 1", 1, array('name' => $identifier)));
    if ($row && isset($row['id'])) {
      return (int) $row['id'];
    }

    /* Synonym. 124 of the 158 carry one, and they are the spellings a reader
       is likely to type: "chloroplast" for plastid, "chromosome one" for 1. */
    $row = retrieve_row(make_query($DBConn, "
      SELECT lg.id
      FROM mgdb.linkage_group lg
      JOIN mgdb.id_num i ON i.id = lg.id AND i.curation_lvl = 0
      JOIN mgdb.synonyms s ON s.id = lg.id
      WHERE LOWER(s.synonyms) = LOWER(:name)
      ORDER BY lg.id ASC
      LIMIT 1", 1, array('name' => $identifier)));
    if ($row && isset($row['id'])) {
      return (int) $row['id'];
    }

    $row = retrieve_row(make_query($DBConn, "
      SELECT lg.id
      FROM mgdb.linkage_group lg
      JOIN mgdb.id_num i ON i.id = lg.id AND i.curation_lvl = 0
      WHERE lg.name ILIKE :name
      ORDER BY length(lg.name) ASC, lg.id ASC
      LIMIT 1", 1, array('name' => '%' . $identifier . '%')));
    if ($row && isset($row['id'])) {
      return (int) $row['id'];
    }

    return false;
  }//lgResolveId


  /* The facts that are cheap enough to render before the first paint.

     map_count is 5 ms and reference_count 1 ms. locus_count is deliberately
     absent -- see the note at the head of this file. */
  function lgIdentity($DBConn, $id) {
    $row = retrieve_row(make_query($DBConn, "
      SELECT lg.id, lg.name, lg.chr_, lg.ttl_len_cm, lg.ttl_len_kb, lg.comments,
             t.id AS type_id, t.name AS type_name,
             mo.name AS morphology_name,
             sp.id AS species_id, sp.species AS species_name,
             (SELECT count(*) FROM mgdb.map m
                JOIN mgdb.id_num mi ON mi.id = m.id AND mi.curation_lvl = 0
               WHERE m.linkage_group = lg.id) AS map_count,
             (SELECT count(*) FROM mgdb.id_reference ir
                JOIN mgdb.id_num ri ON ri.id = ir.reference AND ri.curation_lvl = 0
               WHERE ir.id = lg.id) AS reference_count
      FROM mgdb.linkage_group lg
      JOIN mgdb.id_num i ON i.id = lg.id AND i.curation_lvl = 0
      LEFT JOIN mgdb.term t ON t.id = lg.type
      LEFT JOIN mgdb.term mo ON mo.id = lg.morphology
      LEFT JOIN mgdb.species sp ON sp.id = lg.species
      WHERE lg.id = :id", 1, array('id' => (int) $id)));

    if (!$row) {
      return null;
    }

    return array(
      'id' => (int) $row['id'],
      'name' => trim((string) $row['name']),
      'type' => trim((string) $row['type_name']),
      'morphology' => trim((string) $row['morphology_name']),
      'species' => trim((string) $row['species_name']),
      'species_id' => $row['species_id'] !== null ? (int) $row['species_id'] : null,
      'chromosome' => trim((string) $row['chr_']),
      'length_cm' => $row['ttl_len_cm'] !== null ? (float) $row['ttl_len_cm'] : null,
      'length_kb' => $row['ttl_len_kb'] !== null ? (float) $row['ttl_len_kb'] : null,
      'comments' => trim((string) $row['comments']),
      'map_count' => (int) $row['map_count'],
      'reference_count' => (int) $row['reference_count']
    );
  }//lgIdentity


  /* Synonyms, minus any that merely restate the name.

     The legacy query compared `b.name != a.synonyms` and so kept "1." beside a
     record named "1". Comparing case-insensitively on the trimmed values with
     the punctuation ignored drops the restatement and keeps "chromosome one". */
  function lgSynonyms($DBConn, $id, $name) {
    $out = array();
    $sth = make_query($DBConn, "
      SELECT DISTINCT s.synonyms
      FROM mgdb.synonyms s
      WHERE s.id = :id AND s.synonyms IS NOT NULL AND trim(s.synonyms) <> ''
      ORDER BY s.synonyms", 1, array('id' => (int) $id));
    $key = function ($value) {
      return preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
    };
    $name_key = $key($name);
    while ($row = retrieve_row($sth)) {
      $synonym = trim((string) $row['synonyms']);
      if ($synonym !== '' && $key($synonym) !== $name_key) {
        $out[] = $synonym;
      }
    }
    return $out;
  }//lgSynonyms


  /* Every curated linkage group, for the index at the bare route.

     The locus count carries the curation join, so the index agrees with the
     record page and with the Gene and Locus Data Hub. Counting one linkage
     group that way costs 350 ms, and the first version of this function
     dropped the join on the assumption that 158 records meant 158 counts. It
     does not: grouped over the whole table it is one scan, 614 ms for all 46
     linkage groups that have any loci. Without the join the index would have
     advertised 95,325 loci on chromosome 1 at a record page that says 94,465. */
  function lgIndexRows($DBConn) {
    $rows = array();
    $sth = make_query($DBConn, "
      SELECT lg.id, lg.name, t.name AS type_name, sp.species AS species_name, lg.chr_,
             coalesce(lc.n, 0) AS locus_count, coalesce(mc.n, 0) AS map_count
      FROM mgdb.linkage_group lg
      JOIN mgdb.id_num i ON i.id = lg.id AND i.curation_lvl = 0
      LEFT JOIN mgdb.term t ON t.id = lg.type
      LEFT JOIN mgdb.species sp ON sp.id = lg.species
      LEFT JOIN (SELECT l.linkage_group, count(*) AS n
                   FROM mgdb.locus l
                   JOIN mgdb.id_num li ON li.id = l.id AND li.curation_lvl = 0
                  WHERE l.linkage_group IS NOT NULL
                  GROUP BY l.linkage_group) lc
             ON lc.linkage_group = lg.id
      LEFT JOIN (SELECT m.linkage_group, count(*) AS n FROM mgdb.map m
                   JOIN mgdb.id_num mi ON mi.id = m.id AND mi.curation_lvl = 0
                  WHERE m.linkage_group IS NOT NULL GROUP BY m.linkage_group) mc
             ON mc.linkage_group = lg.id
      ORDER BY coalesce(lc.n, 0) DESC, lower(lg.name) ASC", 1);
    while ($row = retrieve_row($sth)) {
      $rows[] = array(
        'id' => (int) $row['id'],
        'name' => trim((string) $row['name']),
        'type' => trim((string) $row['type_name']),
        'species' => trim((string) $row['species_name']),
        'chromosome' => trim((string) $row['chr_']),
        'locus_count' => (int) $row['locus_count'],
        'map_count' => (int) $row['map_count']
      );
    }
    return $rows;
  }//lgIndexRows
}
?>
