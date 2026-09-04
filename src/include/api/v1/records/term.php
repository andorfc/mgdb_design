<?PHP
/* file: api/v1/records/term.php
 *
 * purpose: assemble a complete controlled-vocabulary term record as JSON.
 *
 *          Included by controllers/api.php with $api_identifier and $DBConn
 *          already set. The response contract is in api/v1/lib/mgdb_api.php.
 *
 *          Replaces the Ajax sections of record_data/term_data.php and
 *          record_data/trait_data.php, which are two pages over one table:
 *          mgdb.term. The term page drew related terms, external entries and
 *          images; the trait page drew phenotypes, QTL analyses and trait
 *          values. This resource returns all of them, and the page shows the
 *          ones that have rows -- 6,815 curated terms across 105 types, and a
 *          Body Part differs from a Trait only in which sections fill.
 *
 *          Sections
 *            overview    definition, type, synonyms, curator comments
 *            phenotypes  phenotypes classified by this term
 *            analyses    QTL trait analyses measuring it, and their experiments
 *            values      the trait-value dataset, summarised
 *            related     related terms
 *            images      illustrations attached to the term
 *            offsite     external database entries
 *            references  the literature attached to the record
 */

// Reachable only through controllers/api.php.
if (!defined('MGDB_API')) { http_response_code(404); exit; }

include_once($_SERVER['DOCUMENT_ROOT'] . '/include/term_record_lib.php');

  $SECTIONS = array('overview', 'phenotypes', 'analyses', 'values',
                    'related', 'images', 'offsite', 'references');
  $wanted = MgdbApi::sections($SECTIONS);
  $want = array_flip($wanted);
  $max_items = MgdbApi::maxItems();

  $id = termResolveId($DBConn, $api_identifier);
  MgdbApi::countQuery(2);

  if ($id === false) {
    MgdbApi::problem(404, 'record-not-found', 'Term not found',
      'No controlled-vocabulary term matches that id, name, or synonym.',
      array('identifier' => $api_identifier));
  }

  $identity = termIdentity($DBConn, $id);
  MgdbApi::countQuery();
  if (!$identity) {
    MgdbApi::problem(404, 'record-not-found', 'Term not found',
      'No term record matches that id.', array('identifier' => $api_identifier));
  }

  $name = $identity['name'];
  $sections = array();
  $counts = array();
  $truncated = array();

  /////
  // Synonyms -- part of the record's identity, so always returned.
  /////

  $synonyms = array();
  $sth = make_query($DBConn, "
    /* mgdb.synonyms has no `type` column -- it is id, auto_num, synonyms,
       authority, del, posttext. Joining a term on s.type made this an invalid
       query, and this codebase's database layer returns an empty result for
       one rather than raising, so the synonym line was silently blank on every
       record. `del = 'x'` marks a retracted synonym (26,211 of 2,809,176). */
    SELECT s.synonyms, p.name AS authority
    FROM mgdb.synonyms s
      LEFT JOIN mgdb.person p ON p.id = s.authority
    WHERE s.id = :id AND (s.del IS NULL OR s.del <> 'x')
    ORDER BY LOWER(s.synonyms)", 1, array('id' => $id));
  MgdbApi::countQuery();
  while ($row = retrieve_row($sth)) {
    $label = MgdbApi::text($row['synonyms']);
    if ($label === null) { continue; }
    $synonyms[] = array('name' => $label, 'kind' => MgdbApi::text($row['authority']));
  }

  /////
  // Overview
  /////

  if (isset($want['overview'])) {
    $comments = array();
    $sth = make_query($DBConn, "
      SELECT m.memo, t.name AS kind,
             r.id AS ref_id, r.name AS ref_name, r.year AS ref_year
      FROM mgdb.memo m
        LEFT JOIN mgdb.term t ON t.id = m.type_term
        LEFT JOIN mgdb.reference r ON r.id = m.source
      WHERE m.id = :id
      ORDER BY t.name, m.memo", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $text = MgdbApi::text($row['memo']);
      if ($text === null) { continue; }
      $comments[] = array(
        'text' => $text,
        'kind' => MgdbApi::text($row['kind']),
        'reference' => $row['ref_id'] === null ? null
          : MgdbApi::ref('reference', $row['ref_id'], $row['ref_name'], '/data_center/reference?id='),
        'year' => MgdbApi::int($row['ref_year'])
      );
    }

    $sections['overview'] = array(
      'name' => MgdbApi::text($name),
      'definition' => MgdbApi::text($identity['definition']),
      'type' => MgdbApi::ref('term', $identity['type_id'], $identity['type'], '/data_center/term?id='),
      'is_trait' => termIsTrait($identity),
      'comments' => $comments
    );
    $counts['comments'] = count($comments);
  }

  /////
  // Phenotypes classified by this term
  /////

  if (isset($want['phenotypes'])) {
    $phenotypes = array();
    $sth = make_query($DBConn, "
      SELECT p.id, p.name
      FROM mgdb.phenotype p
        INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
      WHERE p.trait = :id
      ORDER BY LOWER(p.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $phenotypes[] = MgdbApi::ref('phenotype', $row['id'], $row['name'], '/data_center/phenotype?id=');
    }
    list($phenotypes, $cut) = MgdbApi::cap($phenotypes, $max_items);
    if ($cut) { $truncated[] = 'phenotypes'; }
    $sections['phenotypes'] = $phenotypes;
    $counts['phenotypes'] = count($phenotypes);
  }

  /////
  // QTL trait analyses that measured this trait
  /////

  if (isset($want['analyses'])) {
    $analyses = array();
    $sth = make_query($DBConn, "
      SELECT a.id AS analysis_id, a.name AS analysis_name,
             e.id AS experiment_id, e.name AS experiment_name
      FROM mgdb.trait_analysis a
        INNER JOIN mgdb.id_num ai ON ai.id = a.id AND ai.curation_lvl = 0
        LEFT JOIN mgdb.qtl_exp e ON e.id = a.qtl_exp
        LEFT JOIN mgdb.id_num ei ON ei.id = e.id AND ei.curation_lvl = 0
      WHERE a.trait = :id
      ORDER BY LOWER(a.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $analyses[] = array(
        'analysis' => MgdbApi::ref('trait_analysis', $row['analysis_id'], $row['analysis_name'],
                                   '/data_center/trait_analysis?id='),
        /* `/data_center/qtl?id=`, not `/data_center/qtl_analysis?id=`. These are
           mgdb.qtl_exp rows, and the qtl route is what renders them -- the
           qtl_analysis route answers with an all-but-empty page. The legacy
           trait page links them the same way. */
        'experiment' => $row['experiment_id'] === null ? null
          : MgdbApi::ref('qtl_experiment', $row['experiment_id'], $row['experiment_name'],
                         '/data_center/qtl?id=')
      );
    }
    list($analyses, $cut) = MgdbApi::cap($analyses, $max_items);
    if ($cut) { $truncated[] = 'analyses'; }
    $sections['analyses'] = $analyses;
    $counts['analyses'] = count($analyses);
  }

  /////
  // Trait values
  //
  // The legacy page offered a "download all values" link driven by
  // doTraitDownload(); that endpoint answers with a PHP fatal
  // (fwrite(): Argument #1 must be of type resource, bool given), so the link
  // has been broken for every one of the 121 traits that carry values. The
  // numbers are summarised here instead and the record's own TSV export serves
  // the data, which needs no second service.
  /////

  if (isset($want['values'])) {
    $summary = null;
    $row = retrieve_row(make_query($DBConn, "
      SELECT COUNT(*) AS n, COUNT(DISTINCT tmv.stock_id) AS stocks,
             MIN(tmv.value) AS min_value, MAX(tmv.value) AS max_value,
             ROUND(AVG(tmv.value), 4) AS mean_value
      FROM mgdb.trait_means_values tmv
        INNER JOIN mgdb.id_num i ON i.id = tmv.id AND i.curation_lvl = 0
      WHERE tmv.id = :id", 1, array('id' => $id)));
    MgdbApi::countQuery();
    if ($row && (int) $row['n'] > 0) {
      $units = array();
      $sth = make_query($DBConn, "
        SELECT * FROM (
          SELECT DISTINCT u.name
          FROM mgdb.trait_means_values tmv
            INNER JOIN mgdb.term u ON u.id = tmv.unit_id
          WHERE tmv.id = :id
        ) x ORDER BY LOWER(x.name)", 1, array('id' => $id));
      MgdbApi::countQuery();
      while ($u = retrieve_row($sth)) {
        $label = MgdbApi::text($u['name']);
        if ($label !== null) { $units[] = $label; }
      }
      /* trait_means_values.value is numeric(20,10), so a height of 46 arrives
         as "46.0000000000". Trailing zeros are noise in a range line; the
         stored precision is unchanged, only the presentation. */
      $trim = function ($value) {
        $text = MgdbApi::text($value);
        if ($text === null || strpos($text, '.') === false) { return $text; }
        return rtrim(rtrim($text, '0'), '.');
      };

      $summary = array(
        'values' => (int) $row['n'],
        'stocks' => (int) $row['stocks'],
        'min' => $trim($row['min_value']),
        'max' => $trim($row['max_value']),
        'mean' => $trim($row['mean_value']),
        'units' => $units,
        'bulk_download' => 'https://download.maizegdb.org/SNPs_and_Maps/Traits/'
      );
      $counts['trait_values'] = (int) $row['n'];
    }
    $sections['values'] = $summary;
  }

  /////
  // Related terms
  /////

  if (isset($want['related'])) {
    $related = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT t.id, t.name, rt.name AS relation, ty.name AS type_name
        FROM mgdb.relation r
          INNER JOIN mgdb.id_num i ON i.id = r.related_id AND i.curation_lvl = 0
          INNER JOIN mgdb.term t ON t.id = i.id
          LEFT JOIN mgdb.term rt ON rt.id = r.relation
          LEFT JOIN mgdb.term ty ON ty.id = t.type
        WHERE r.id = :id
      ) x ORDER BY LOWER(x.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $related[] = array(
        'ref' => MgdbApi::ref('term', $row['id'], $row['name'], '/data_center/term?id='),
        'type' => MgdbApi::text($row['type_name']),
        'relation' => MgdbApi::text($row['relation'])
      );
    }
    list($related, $cut) = MgdbApi::cap($related, $max_items);
    if ($cut) { $truncated[] = 'related'; }
    $sections['related'] = $related;
    $counts['related'] = count($related);
  }

  /////
  // Images
  /////

  if (isset($want['images'])) {
    $images = array();
    $sth = make_query($DBConn, "
      SELECT w.url, w.caption
      FROM mgdb.web_image w
      WHERE w.id = :id
      ORDER BY w.url", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $path = MgdbApi::text($row['url']);
      if ($path === null) { continue; }
      /* Term thumbnails are linked by the legacy page and by production, but
         the image server does not carry them -- every sampled
         db_images/Term/.../downsized/ path is a 404. The URL is still returned
         so a client can try, and the page falls back to the full image. */
      $images[] = array(
        'path' => $path,
        'url' => MgdbApi::imageUrl('Term', $path),
        'thumbnail' => MgdbApi::imageUrl('Term', $path, true),
        'caption' => MgdbApi::text($row['caption'])
      );
    }
    list($images, $cut) = MgdbApi::cap($images, $max_items);
    if ($cut) { $truncated[] = 'images'; }
    $sections['images'] = $images;
    $counts['images'] = count($images);
  }

  /////
  // Offsite resources
  /////

  if (isset($want['offsite'])) {
    $offsite = array();
    $sth = make_query($DBConn, "
      SELECT * FROM (
        SELECT DISTINCT x.key, p.id AS person_id, p.name AS database, pup.url_prefix,
               x.ext_db_comment
        FROM mgdb.ext_db_key x
          INNER JOIN mgdb.person p ON p.id = x.db_person
          INNER JOIN mgdb.person_url_prefix pup ON pup.id = p.id
          INNER JOIN mgdb.id_num i ON i.id = p.id AND i.curation_lvl = 0
        WHERE x.id = :id AND (x.obsolete IS NULL OR x.obsolete <> 'Y')
      ) x ORDER BY LOWER(x.database), x.key", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      $key = trim((string) $row['key']);
      $prefix = trim((string) $row['url_prefix']);
      $offsite[] = array(
        'key' => MgdbApi::text($key),
        'database' => MgdbApi::text($row['database']),
        'url' => $prefix !== '' ? $prefix . $key : null,
        'comment' => MgdbApi::text($row['ext_db_comment'])
      );
    }
    list($offsite, $cut) = MgdbApi::cap($offsite, $max_items);
    if ($cut) { $truncated[] = 'offsite'; }
    $sections['offsite'] = $offsite;
    $counts['offsite'] = count($offsite);
  }

  /////
  // References
  /////

  if (isset($want['references'])) {
    $references = array();
    $sth = make_query($DBConn, "
      SELECT r.id, r.name, r.title, r.year, r.doi, r.author_desc, t.name AS contents,
             t_type.name AS pub_type,
             (
               SELECT substring(regexp_replace(string_agg(
                 concat_ws(' ', rab.abstract_1, rab.abstract_2), ' '
               ), '\s+', ' ', 'g') from 1 for 700)
               FROM mgdb.reference_abstract rab WHERE rab.id = r.id
             ) AS abstract
      FROM mgdb.id_reference ir
        INNER JOIN mgdb.reference r ON r.id = ir.reference
        INNER JOIN mgdb.id_num i ON i.id = ir.reference AND i.curation_lvl = 0
        LEFT JOIN mgdb.term t ON t.id = ir.contents
        LEFT JOIN mgdb.term t_type ON t_type.id = r.type
      WHERE ir.id = :id
      ORDER BY r.year DESC NULLS LAST, LOWER(r.name)", 1, array('id' => $id));
    MgdbApi::countQuery();
    while ($row = retrieve_row($sth)) {
      /* The DOI is stored inconsistently -- bare, prefixed with "doi:", or as a
         full doi.org URL, and sometimes only inside the citation string. One
         pattern pulls the bare DOI out of any of those. */
      $doi = MgdbApi::text($row['doi']);
      if ($doi && preg_match('/(?:doi:\s*|https?:\/\/doi\.org\/)?(10\.\d{4,9}\/[-._;()\/:A-Z0-9]+)/i', $doi, $m)) {
        $doi = $m[1];
      } elseif (preg_match('/(?:doi:\s*|https?:\/\/doi\.org\/)?(10\.\d{4,9}\/[-._;()\/:A-Z0-9]+)/i', (string) $row['name'], $m)) {
        $doi = $m[1];
      } else {
        $doi = null;
      }
      $references[] = array(
        'type' => 'reference',
        'id' => MgdbApi::int($row['id']),
        'citation' => MgdbApi::text($row['name']),
        'title' => MgdbApi::text($row['title']),
        'authors' => MgdbApi::text($row['author_desc']),
        'year' => MgdbApi::int($row['year']),
        'doi' => $doi,
        'pub_type' => MgdbApi::text($row['pub_type']) ?: 'Journal article',
        'relevance' => MgdbApi::text($row['contents']),
        'abstract' => MgdbApi::text($row['abstract']),
        'html' => '/data_center/reference?id=' . (int) $row['id']
      );
    }
    list($references, $cut) = MgdbApi::cap($references, $max_items);
    if ($cut) { $truncated[] = 'references'; }
    $sections['references'] = $references;
    $counts['references'] = count($references);
  }

  MgdbApi::send('term', $id,
    array(
      'name' => MgdbApi::text($name),
      'term_type' => MgdbApi::text($identity['type']),
      'is_trait' => termIsTrait($identity),
      'curation_level' => $identity['curation_level'],
      'synonyms' => MgdbApi::synonyms($synonyms, $name)
    ),
    $sections,
    array(
      'html' => MgdbApi::baseUrl() . '/data_center/term?id=' . $id,
      'search' => MgdbApi::baseUrl() . '/data_center/term'
    ),
    array(
      'resolved_from' => $api_identifier,
      'sections_returned' => array_values($wanted),
      'sections_available' => $SECTIONS,
      'partial' => count($wanted) !== count($SECTIONS),
      'max_items' => $max_items,
      'truncated' => $truncated,
      'counts' => $counts
    )
  );
?>
