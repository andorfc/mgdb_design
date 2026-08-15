<?PHP
/* file: api/v1/records/reference.php
 *
 * purpose: assemble a complete reference record as JSON.
 *
 *          Included by controllers/api.php with $api_identifier and $DBConn
 *          already set. The response contract is in api/v1/lib/mgdb_api.php.
 *
 *          Replaces five Ajax calls to record_data/reference_data.php. That
 *          file ran a query per author to fetch each name, and another per
 *          described record type; a paper with twenty authors cost twenty-one
 *          round trips for the author list alone. Everything here is
 *          set-based.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/reference-record/.
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

  $SECTIONS = array('overview', 'authors', 'abstract', 'citation',
                    'describes', 'links', 'editorial');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  /////
  // Resolve
  //
  // A numeric MaizeGDB id, a DOI, or a PubMed ID. Scientists arrive holding
  // one of those three and should not have to know which one this database
  // keys on. Each arm is an indexed equality lookup.
  /////

  $numeric = ctype_digit($api_identifier) ? (int) $api_identifier : 0;

  $found = retrieve_row(make_query($DBConn, "
    SELECT r.id, 0 AS rank
    FROM mgdb.reference r
      INNER JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
    WHERE r.id = :nid
    UNION ALL
    SELECT r.id, 1
    FROM mgdb.reference r
      INNER JOIN mgdb.id_num i ON i.id = r.id AND i.curation_lvl = 0
    WHERE r.doi = :doi
    UNION ALL
    SELECT x.id, 2
    FROM mgdb.ext_db_key x
      INNER JOIN mgdb.id_num i ON i.id = x.id AND i.curation_lvl = 0
    WHERE x.key = :key
      AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
      AND x.db_person IN (SELECT id FROM mgdb.person
                          WHERE name IN ('Medline -- PubMed', 'Digital Object Identifier (DOI), -'))
    ORDER BY rank, id
    LIMIT 1", 1, array(
      'nid' => $numeric, 'doi' => $api_identifier, 'key' => $api_identifier
    )));
  MgdbApi::countQuery();

  if (!$found) {
    MgdbApi::problem(404, 'record-not-found', 'Reference not found',
      'No reference matches that id, DOI, or PubMed ID.',
      array('identifier' => $api_identifier));
  }

  $id = (int) $found['id'];

  /////
  // The record, with its journal and type joined in
  /////

  $record = retrieve_row(make_query($DBConn, "
    SELECT r.id, r.name, r.title, r.year, r.volume, r.pages, r.doi,
           r.ref_number, r.author_desc, r.issn,
           t.id AS type_id, t.name AS type_name,
           j.id AS journal_id, j.name AS journal_name,
           pub.id AS publisher_id, pub.name AS publisher_name,
           inst.id AS institution_id, inst.name AS institution_name
    FROM mgdb.reference r
      LEFT JOIN mgdb.term t ON t.id = r.type
      LEFT JOIN mgdb.journal j ON j.id = r.in1
      LEFT JOIN mgdb.person pub ON pub.id = r.publisher
      LEFT JOIN mgdb.person inst ON inst.id = r.institution
    WHERE r.id = :id", 1, array('id' => $id)));
  MgdbApi::countQuery();

  if (!$record) {
    MgdbApi::problem(404, 'record-not-found', 'Reference not found',
      'No reference matches that id, DOI, or PubMed ID.',
      array('identifier' => $api_identifier));
  }

  $title = MgdbApi::text($record['title']);
  $citation_line = MgdbApi::text($record['name']);
  $year = MgdbApi::int($record['year']);
  $journal = MgdbApi::text($record['journal_name']);
  $volume = MgdbApi::text($record['volume']);
  $pages = MgdbApi::text($record['pages']);

  /////
  // External identifiers
  //
  // Needed by three sections — the citation, the link list, and the header —
  // so they are fetched once and classified once. reference.doi is often
  // empty even when a DOI exists as an external key, and a DOI sometimes
  // arrives in the pages field of older records; both are picked up.
  /////

  $external = array();
  $doi = MgdbApi::text($record['doi']);
  $pubmed = null;

  $sth = make_query($DBConn, "
    SELECT p.id AS db_id, p.name AS db_name, x.key, pup.url_prefix
    FROM mgdb.ext_db_key x
      INNER JOIN mgdb.person p ON p.id = x.db_person
      INNER JOIN mgdb.id_num i ON i.id = x.db_person AND i.curation_lvl = 0
      LEFT JOIN mgdb.person_url_prefix pup ON pup.id = p.id
    WHERE x.id = :id AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
    ORDER BY LOWER(p.name)", 1, array('id' => $id));
  MgdbApi::countQuery();

  while ($row = retrieve_row($sth)) {
    $key = MgdbApi::text($row['key']);
    if ($key === null) {
      continue;
    }
    $database = MgdbApi::text($row['db_name']);
    $kind = referenceLinkKind($database);

    if ($kind === 'doi' && $doi === null) {
      $doi = $key;
    }
    if ($kind === 'pubmed') {
      $pubmed = $key;
    }

    $external[] = array(
      'kind' => $kind,
      'database' => referenceLinkLabel($database, $kind),
      'database_id' => MgdbApi::int($row['db_id']),
      'accession' => $key,
      'url' => referenceLinkUrl($kind, $key, MgdbApi::text($row['url_prefix'])),
      'destination' => referenceLinkDestination($kind, $database),
      'is_external' => ($kind !== 'mnl' && $kind !== 'ancillary')
    );
  }

  // Some older records carry the DOI in the pages field: "doi: 10.1016/...".
  if ($doi === null && $pages !== null && preg_match('~\bdoi:\s*(\S+)~i', $pages, $matches)) {
    $doi = rtrim($matches[1], '.');
  }

  /////
  // Authors
  //
  // One query for the names and, in the same pass, how many other papers each
  // author has in the database. A reader deciding whether to trust a paper
  // looks at who wrote it, and a name with 110 papers here means something
  // different from a name with one. The legacy page fetched each author's name
  // in its own query and offered no counts at all.
  /////

  $authors = array();
  if (isset($want['authors']) || isset($want['citation'])) {
    $sth = make_query($DBConn, "
      SELECT ra.order1, p.id, p.name, p.name_first, p.name_last,
             (SELECT COUNT(DISTINCT other.id)
              FROM mgdb.reference_authors other
                INNER JOIN mgdb.id_num oi ON oi.id = other.id AND oi.curation_lvl = 0
              WHERE other.author = p.id) AS paper_count
      FROM mgdb.reference_authors ra
        INNER JOIN mgdb.person p ON p.id = ra.author
        INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
      WHERE ra.id = :id
      ORDER BY ra.order1", 1, array('id' => $id));
    MgdbApi::countQuery();

    $rows = array();
    while ($row = retrieve_row($sth)) {
      $rows[] = $row;
    }
    $last = count($rows) - 1;

    foreach ($rows as $index => $row) {
      $full = trim(MgdbApi::text($row['name_first']) . ' ' . MgdbApi::text($row['name_last']));
      $papers = MgdbApi::int($row['paper_count']);
      $authors[] = array(
        'type' => 'person',
        'id' => MgdbApi::int($row['id']),
        'name' => MgdbApi::text($row['name']),
        'full_name' => $full !== '' ? $full : null,
        'position' => $index + 1,
        // First and last authorship carry meaning in this literature, so the
        // API states them rather than leaving every client to re-derive it.
        'is_first' => ($index === 0),
        'is_last' => ($index === $last && $last > 0),
        'paper_count' => $papers,
        'other_papers' => ($papers !== null && $papers > 0) ? $papers - 1 : 0,
        'html' => '/person?id=' . (int) $row['id'],
        'papers_html' => '/data_center/reference?scope=author&q='
                       . rawurlencode((string) MgdbApi::text($row['name']))
      );
    }
  }

  /////
  // Section counts
  /////

  $counts_row = retrieve_row(make_query($DBConn, "
    SELECT
      (SELECT COUNT(*) FROM mgdb.reference_authors WHERE id = :c1) AS authors,
      (SELECT COUNT(*) FROM mgdb.id_reference ir
         INNER JOIN mgdb.id_num i ON i.id = ir.id AND i.curation_lvl = 0
       WHERE ir.reference = :c2) AS describes,
      (SELECT COUNT(*) FROM mgdb.ext_db_key x
         INNER JOIN mgdb.id_num i ON i.id = x.db_person AND i.curation_lvl = 0
       WHERE x.id = :c3 AND (x.obsolete IS NULL OR x.obsolete <> 'Y')) AS links,
      (SELECT COUNT(*) FROM mgdb.ed_board_papers WHERE reference_id = :c4) AS editorial",
    1, array('c1' => $id, 'c2' => $id, 'c3' => $id, 'c4' => $id)));
  MgdbApi::countQuery();

  $counts = array(
    'authors' => MgdbApi::int($counts_row['authors']),
    'describes' => MgdbApi::int($counts_row['describes']),
    'links' => MgdbApi::int($counts_row['links']),
    'editorial' => MgdbApi::int($counts_row['editorial'])
  );

  $sections = array();

  /////
  // Overview
  /////

  if (isset($want['overview'])) {
    // Maize Newsletter articles carry their issue in the citation string.
    $mnl_issue = null;
    if ($citation_line !== null && preg_match('/ MNL\.* (\d+):/', $citation_line, $matches)) {
      $mnl_issue = (int) $matches[1];
    }

    $sections['overview'] = array(
      'publication_type' => MgdbApi::ref('term', $record['type_id'], $record['type_name']),
      'journal' => MgdbApi::ref('journal', $record['journal_id'], $record['journal_name']),
      'year' => $year,
      'volume' => $volume,
      'issue' => MgdbApi::text($record['ref_number']),
      'pages' => $pages,
      'issn' => MgdbApi::text($record['issn']),
      'doi' => $doi,
      'pubmed_id' => $pubmed,
      'publisher' => MgdbApi::ref('person', $record['publisher_id'], $record['publisher_name'], '/person?id='),
      'institution' => MgdbApi::ref('person', $record['institution_id'], $record['institution_name'], '/person?id='),
      'maize_newsletter_issue' => $mnl_issue
    );
  }

  if (isset($want['authors'])) {
    $sections['authors'] = $authors;
  }

  /////
  // Abstract
  //
  // Stored split across two columns because of a historical length limit.
  /////

  if (isset($want['abstract'])) {
    $abstract = '';
    $sth = make_query($DBConn, "
      SELECT ra.abstract_1, ra.abstract_2
      FROM mgdb.reference_abstract ra
        INNER JOIN mgdb.id_num i ON i.id = ra.id AND i.curation_lvl = 0
      WHERE ra.id = :id", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $abstract .= (string) $row['abstract_1'] . (string) $row['abstract_2'];
    }
    $sections['abstract'] = MgdbApi::text($abstract);
  }

  /////
  // Citation
  //
  // Built server-side in every format a reader is likely to want, so that
  // getting a paper into a manuscript or a reference manager is a copy rather
  // than a retyping exercise.
  /////

  if (isset($want['citation'])) {
    $names = array();
    foreach ($authors as $author) {
      $names[] = $author['name'];
    }
    if (count($names) === 0 && MgdbApi::text($record['author_desc']) !== null) {
      $names[] = MgdbApi::text($record['author_desc']);
    }

    $sections['citation'] = array(
      'maizegdb' => $citation_line,
      'formatted' => referenceFormatted($names, $year, $title, $journal, $volume,
                                        MgdbApi::text($record['ref_number']), $pages, $doi),
      'bibtex' => referenceBibtex($id, $names, $year, $title, $journal, $volume,
                                  MgdbApi::text($record['ref_number']), $pages, $doi,
                                  MgdbApi::text($record['type_name'])),
      'ris' => referenceRis($names, $year, $title, $journal, $volume,
                            MgdbApi::text($record['ref_number']), $pages, $doi, $pubmed,
                            MgdbApi::text($record['type_name'])),
      'doi_url' => $doi === null ? null : 'https://doi.org/' . $doi,
      'pubmed_url' => $pubmed === null ? null
                    : 'https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode($pubmed) . '/'
    );
  }

  /////
  // What the paper describes
  //
  // One query for every curated record of every type, with the curation term
  // that says *why* it is linked. The legacy page ran a separate query per
  // record type and then another per row for several of them.
  //
  // Loci then get their gene models in a second query — one for all of them,
  // not one per locus — so a reader can go straight from "this paper describes
  // lg2" to the gene model page for lg2 in the assembly they work in.
  /////

  if (isset($want['describes'])) {
    $sth = make_query($DBConn, "
      SELECT i.id, t.name AS record_type, ct.name AS relevance,
             COALESCE(l.name, s.name, v.name, ph.name, pr.name, per.name,
                      term.name, r2.name, m.name) AS name,
             l.full_name AS locus_full_name
      FROM mgdb.id_reference ir
        INNER JOIN mgdb.id_num i ON i.id = ir.id AND i.curation_lvl = 0
        INNER JOIN mgdb.term t ON t.id = i.type_term
        LEFT JOIN mgdb.term ct ON ct.id = ir.contents
        LEFT JOIN mgdb.locus l ON l.id = i.id
        LEFT JOIN mgdb.stock s ON s.id = i.id
        LEFT JOIN mgdb.variation v ON v.id = i.id
        LEFT JOIN mgdb.phenotype ph ON ph.id = i.id
        LEFT JOIN mgdb.probe pr ON pr.id = i.id
        LEFT JOIN mgdb.person per ON per.id = i.id
        LEFT JOIN mgdb.term term ON term.id = i.id
        LEFT JOIN mgdb.reference r2 ON r2.id = i.id
        LEFT JOIN mgdb.map m ON m.id = i.id
      WHERE ir.reference = :id
      ORDER BY t.name, LOWER(COALESCE(l.name, s.name, v.name, ph.name, pr.name,
                                      per.name, term.name, r2.name, m.name))",
      1, array('id' => $id));
    MgdbApi::countQuery();

    $groups = array();
    $locus_ids = array();
    while ($row = retrieve_row($sth)) {
      $type = MgdbApi::text($row['record_type']);
      $name = MgdbApi::text($row['name']);
      if ($type === null || $name === null) {
        continue;
      }
      if (!isset($groups[$type])) {
        $groups[$type] = array();
      }
      $entry = array(
        'type' => strtolower(str_replace(' ', '_', $type)),
        'id' => MgdbApi::int($row['id']),
        'name' => $name,
        'full_name' => MgdbApi::text($row['locus_full_name']),
        'relevance' => MgdbApi::text($row['relevance']),
        'html' => referenceRecordUrl($type, (int) $row['id'])
      );
      if ($type === 'Locus') {
        $entry['gene_models'] = array();
        $locus_ids[] = (int) $row['id'];
      }
      $groups[$type][] = $entry;
    }

    // Gene models for every described locus, in one query. The reference
    // model for the current assembly is flagged: that is the one a reader
    // working in B73 v5 actually wants.
    if (count($locus_ids) > 0) {
      $placeholders = array();
      $params = array();
      foreach ($locus_ids as $index => $locus_id) {
        $placeholders[] = ':l' . $index;
        $params['l' . $index] = $locus_id;
      }

      $models = array();
      $sth = make_query($DBConn, "
        SELECT DISTINCT gm.locus_id, gm.gene_name, gm.canonical_transcript_name,
               gm.assembly_version, gm.version,
               COALESCE(gm.is_reference_gene_model, '') = 'yes' AS is_reference
        FROM chado.gene_model gm
        WHERE gm.locus_id IN (" . implode(',', $placeholders) . ")
          AND gm.gene_name IS NOT NULL
        ORDER BY is_reference DESC, gm.assembly_version DESC, gm.gene_name",
        1, $params);
      MgdbApi::countQuery();

      while ($row = retrieve_row($sth)) {
        $locus_id = (int) $row['locus_id'];
        $gene = MgdbApi::text($row['gene_name']);
        if ($gene === null) {
          continue;
        }
        if (!isset($models[$locus_id])) {
          $models[$locus_id] = array();
        }
        // One entry per gene model, not per annotation version.
        if (isset($models[$locus_id][$gene])) {
          continue;
        }
        $models[$locus_id][$gene] = array(
          'type' => 'gene_model',
          'name' => $gene,
          'transcript' => MgdbApi::text($row['canonical_transcript_name']),
          'assembly' => MgdbApi::text($row['assembly_version']),
          'annotation' => MgdbApi::text($row['version']),
          'is_reference' => ($row['is_reference'] === true || $row['is_reference'] === 't'),
          'html' => '/gene_center/gene/' . rawurlencode($gene)
        );
      }

      if (isset($groups['Locus'])) {
        foreach ($groups['Locus'] as $index => $entry) {
          $locus_id = $entry['id'];
          if (isset($models[$locus_id])) {
            $groups['Locus'][$index]['gene_models'] =
              array_slice(array_values($models[$locus_id]), 0, $max_items);
          }
        }
      }
    }

    // A stable order: the record types a reader is most likely to want first.
    $order = array('Locus', 'Variation', 'Gene Product', 'Phenotype', 'Stock',
                   'Probe', 'Map', 'Metabolic Pathway', 'Term', 'Person', 'Reference');
    $describes = array();
    foreach ($order as $type) {
      if (isset($groups[$type])) {
        $describes[] = referenceGroup($type, $groups[$type], $max_items);
        unset($groups[$type]);
      }
    }
    foreach ($groups as $type => $items) {
      $describes[] = referenceGroup($type, $items, $max_items);
    }

    $sections['describes'] = $describes;
  }

  if (isset($want['links'])) {
    $sections['links'] = $external;
  }

  /////
  // Editorial Board
  //
  // A recommendation from the MaizeGDB Editorial Board is a signal worth
  // surfacing: a working maize geneticist read this paper and put it forward.
  /////

  if (isset($want['editorial'])) {
    $recommendations = array();
    $sth = make_query($DBConn, "
      SELECT ebp.rec_year, p.id, p.name, p.name_first, p.name_last
      FROM mgdb.ed_board_papers ebp
        LEFT JOIN mgdb.person p ON p.id = ebp.person_id
      WHERE ebp.reference_id = :id
      ORDER BY ebp.rec_year DESC", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $full = trim(MgdbApi::text($row['name_first']) . ' ' . MgdbApi::text($row['name_last']));
      $recommendations[] = array(
        'year' => MgdbApi::int($row['rec_year']),
        'recommended_by' => MgdbApi::ref('person', $row['id'], $row['name'], '/person?id='),
        'recommended_by_full_name' => $full !== '' ? $full : null
      );
    }

    $sections['editorial'] = array(
      'is_editorial_pick' => count($recommendations) > 0,
      'recommendations' => $recommendations,
      'about_html' => '/hot_new_papers'
    );
  }

  /////
  // Send
  /////

  if (isset($sections['authors']) && count($sections['authors']) !== $counts['authors']) {
    MgdbApi::warn('count_mismatch',
      'authors returned ' . count($sections['authors']) . ' rows but the record has '
      . $counts['authors'] . '. Some rows were withheld or a query failed.');
  }

  MgdbApi::send('reference', $id,
    array(
      'title' => $title,
      'citation' => $citation_line,
      'year' => $year,
      'journal' => $journal,
      'publication_type' => MgdbApi::text($record['type_name']),
      'doi' => $doi,
      'pubmed_id' => $pubmed,
      'is_editorial_pick' => $counts['editorial'] > 0
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/data_center/reference?id=' . $id,
      'search' => MgdbApi::baseUrl() . '/data_center/reference'
    ),
    array(
      'resolved_from' => $api_identifier,
      'sections_returned' => array_values($wanted),
      'sections_available' => $SECTIONS,
      'partial' => count($wanted) !== count($SECTIONS),
      'max_items' => $max_items,
      'counts' => $counts
    )
  );

/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* Which external database a key belongs to. The database names are the ones
   recorded in mgdb.person and are matched rather than their ids, because an id
   here would be a magic number nobody could read. */
function referenceLinkKind($database) {
  if ($database === null) {
    return 'other';
  }
  if (stripos($database, 'PubMed') !== false || stripos($database, 'Medline') !== false) {
    return 'pubmed';
  }
  if (stripos($database, 'Digital Object Identifier') !== false || strcasecmp($database, 'DOI') === 0) {
    return 'doi';
  }
  if (strcasecmp($database, 'MNL') === 0) {
    return 'mnl';
  }
  if (stripos($database, 'Ancillary Files') !== false) {
    return 'ancillary';
  }
  if (stripos($database, 'gene review') !== false) {
    return 'gene_review';
  }
  return 'other';
}//referenceLinkKind

/* A name a reader recognizes, in place of the database's internal label —
   "Digital Object Identifier (DOI), -" is not something to show anyone. */
function referenceLinkLabel($database, $kind) {
  switch ($kind) {
    case 'pubmed':      return 'PubMed';
    case 'doi':         return 'DOI';
    case 'mnl':         return 'Maize Newsletter';
    case 'ancillary':   return 'MaizeGDB ancillary files';
    case 'gene_review': return 'Maize Gene Review';
    default:            return $database;
  }
}//referenceLinkLabel

/* Where the link actually goes, said plainly. The legacy page rendered every
   one of these as "Read the complete article" regardless of destination. */
function referenceLinkDestination($kind, $database) {
  switch ($kind) {
    case 'pubmed':      return 'The PubMed record at the NIH National Library of Medicine';
    case 'doi':         return 'The published article at the publisher';
    case 'mnl':         return 'The full text in the MaizeGDB Maize Newsletter archive';
    case 'ancillary':   return 'Supplementary files held at MaizeGDB';
    case 'gene_review': return 'The full text at Maize Gene Review';
    default:            return 'The record at ' . $database;
  }
}//referenceLinkDestination

function referenceLinkUrl($kind, $key, $prefix) {
  switch ($kind) {
    case 'doi':         return 'https://doi.org/' . $key;
    case 'pubmed':      return 'https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode($key) . '/';
    case 'mnl':         return 'https://mnl.maizegdb.org/mnl/' . rawurlencode($key);
    case 'gene_review': return 'http://www.maizegenereview.org/' . rawurlencode($key);
    default:
      return $prefix === null ? null : $prefix . rawurlencode($key);
  }
}//referenceLinkUrl

/* The page a described record lives on. */
function referenceRecordUrl($type, $id) {
  $routes = array(
    'Locus' => '/gene_center/gene?id=',
    'Variation' => '/data_center/variation?id=',
    'Stock' => '/data_center/stock?id=',
    'Phenotype' => '/data_center/phenotype?id=',
    'Probe' => '/data_center/probe?id=',
    'Map' => '/data_center/map?id=',
    'Person' => '/person?id=',
    'Reference' => '/data_center/reference?id=',
    'Gene Product' => '/data_center/gene_product?id=',
    'Metabolic Pathway' => '/data_center/metabolic_pathway?id='
  );
  return isset($routes[$type]) ? $routes[$type] . $id : null;
}//referenceRecordUrl

function referenceGroup($type, $items, $max_items) {
  return array(
    'record_type' => $type,
    'count' => count($items),
    'items' => array_slice($items, 0, $max_items),
    'truncated' => count($items) > $max_items
  );
}//referenceGroup

/* A citation in the style MaizeGDB's own literature uses. */
function referenceFormatted($names, $year, $title, $journal, $volume, $issue, $pages, $doi) {
  $parts = array();
  if (count($names) > 0) {
    $parts[] = implode(', ', $names) . '.';
  }
  if ($year !== null) {
    $parts[] = $year . '.';
  }
  if ($title !== null) {
    $parts[] = rtrim($title, '.') . '.';
  }
  if ($journal !== null) {
    $locator = $journal;
    if ($volume !== null && $volume !== '0') {
      $locator .= ' ' . $volume;
      if ($issue !== null && $issue !== '0') {
        $locator .= '(' . $issue . ')';
      }
    }
    // Older records park the DOI in the pages field; it is already reported
    // separately and repeating it in the citation reads as a page range.
    if ($pages !== null && stripos($pages, 'doi:') === false) {
      $locator .= ':' . $pages;
    }
    $parts[] = $locator . '.';
  }
  if ($doi !== null) {
    $parts[] = 'https://doi.org/' . $doi;
  }
  return count($parts) > 0 ? implode(' ', $parts) : null;
}//referenceFormatted

function referenceBibtexKey($names, $year, $id) {
  $surname = 'maizegdb';
  if (count($names) > 0) {
    $first = preg_split('/[,\s]+/', trim($names[0]));
    if (isset($first[0]) && $first[0] !== '') {
      $surname = strtolower(preg_replace('/[^A-Za-z]/', '', $first[0]));
    }
  }
  return $surname . ($year !== null ? $year : '') . '_' . $id;
}//referenceBibtexKey

function referenceBibtex($id, $names, $year, $title, $journal, $volume, $issue, $pages, $doi, $type) {
  $entry = ($type === 'Article') ? 'article' : (($type === 'Book') ? 'book' : 'misc');
  $lines = array('@' . $entry . '{' . referenceBibtexKey($names, $year, $id) . ',');

  if (count($names) > 0) {
    $lines[] = '  author = {' . implode(' and ', $names) . '},';
  }
  if ($title !== null) {
    $lines[] = '  title = {' . $title . '},';
  }
  if ($journal !== null) {
    $lines[] = '  journal = {' . $journal . '},';
  }
  if ($year !== null) {
    $lines[] = '  year = {' . $year . '},';
  }
  if ($volume !== null && $volume !== '0') {
    $lines[] = '  volume = {' . $volume . '},';
  }
  if ($issue !== null && $issue !== '0') {
    $lines[] = '  number = {' . $issue . '},';
  }
  if ($pages !== null && stripos($pages, 'doi:') === false) {
    $lines[] = '  pages = {' . $pages . '},';
  }
  if ($doi !== null) {
    $lines[] = '  doi = {' . $doi . '},';
  }
  $lines[] = '  note = {MaizeGDB reference ' . $id . '}';
  $lines[] = '}';

  return implode("\n", $lines);
}//referenceBibtex

function referenceRis($names, $year, $title, $journal, $volume, $issue, $pages, $doi, $pubmed, $type) {
  $lines = array('TY  - ' . ($type === 'Article' ? 'JOUR' : ($type === 'Book' ? 'BOOK' : 'GEN')));
  foreach ($names as $name) {
    $lines[] = 'AU  - ' . $name;
  }
  if ($title !== null)  { $lines[] = 'TI  - ' . $title; }
  if ($journal !== null) { $lines[] = 'JO  - ' . $journal; }
  if ($year !== null)   { $lines[] = 'PY  - ' . $year; }
  if ($volume !== null && $volume !== '0') { $lines[] = 'VL  - ' . $volume; }
  if ($issue !== null && $issue !== '0')   { $lines[] = 'IS  - ' . $issue; }
  if ($pages !== null && stripos($pages, 'doi:') === false) { $lines[] = 'SP  - ' . $pages; }
  if ($doi !== null)    { $lines[] = 'DO  - ' . $doi; }
  if ($pubmed !== null) { $lines[] = 'AN  - ' . $pubmed; }
  $lines[] = 'ER  - ';

  return implode("\n", $lines);
}//referenceRis
?>
