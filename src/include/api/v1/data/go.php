<?php
/**
 * /api/v1/data/go -- the Gene Ontology reference index as a dataset.
 *
 * purpose: One term with its place in the ontology and what MaizeGDB hangs
 *          on it: the term itself (name, aspect, definition, depth, plant
 *          slim membership, retirement or merge), its lineage to the root,
 *          direct parents and children, the plant-slim categories it falls
 *          under, the InterPro entries InterPro2GO maps to it, and the
 *          maize gene models annotated with it. A name search, a batch, and
 *          the slim itself. No genome segment: the ontology is one thing.
 *
 *          GET /api/v1/data/go                      the index: release, counts
 *          GET /api/v1/data/go/{term}               one term; fields=, annotation=, limit, offset
 *          GET /api/v1/data/go/search?q=            terms by name or id; aspect=, limit
 *          GET /api/v1/data/go/batch?ids=           up to 200 terms, attributes only
 *          GET /api/v1/data/go/slim                 the plant GO slim
 *
 *          Everything but the genes section is read from data/go/go.sqlite
 *          (tools/go_index.py). genes is the one database contact: the
 *          annotation table by term, which is indexed, about 200 ms.
 *
 * history:
 *  09/12/26  claude  created
 */

  include_once('./include/api/v1/lib/mgdb_go.php');

  $GO_DATASET = 'go';
  $GO_SECTIONS = array('lineage', 'parents', 'children', 'slim', 'interpro', 'genes', 'annotations');
  $GO_DEFAULT_ANNOTATION = 'Zm00001eb.1';
  $go_base = MgdbApi::baseUrl();

  if (!MgdbGo::available()) {
    MgdbApi::problem(503, 'dataset-unavailable', 'GO index not on file',
      'The GO reference index has not been built on this host (tools/go_index.py).');
  }
  $go_manifest = MgdbGo::manifest();

  /////
  // GET /api/v1/data/go
  /////

  if (count($api_rest) === 0) {
    $attributes = api_data_summary($api_entry);
    $attributes['release'] = isset($go_manifest['release']) ? $go_manifest['release'] : null;
    $attributes['generated'] = isset($go_manifest['generated']) ? $go_manifest['generated'] : null;
    $attributes['counts'] = isset($go_manifest['counts']) ? $go_manifest['counts'] : null;
    $attributes['relations'] = isset($go_manifest['relations']) ? $go_manifest['relations'] : null;
    $attributes['roots'] = isset($go_manifest['roots']) ? $go_manifest['roots'] : null;
    $attributes['default_annotation'] = $GO_DEFAULT_ANNOTATION;
    MgdbApi::sendData(
      array('type' => 'dataset', 'id' => $GO_DATASET, 'attributes' => $attributes,
            'sections' => array('sources' => isset($go_manifest['sources']) ? $go_manifest['sources'] : array(),
                                'disagreements' => isset($go_manifest['disagreements']) ? $go_manifest['disagreements'] : array())),
      array('openapi' => $go_base . '/api/v1/openapi',
            'index' => $go_base . '/data/go/index.json',
            'example' => $go_base . '/api/v1/data/go/' . rawurlencode($api_entry['example']['id']),
            'search' => $go_base . '/api/v1/data/go/search?q=stomatal',
            'slim' => $go_base . '/api/v1/data/go/slim'),
      array('dataset' => $GO_DATASET, 'release' => isset($go_manifest['release']) ? $go_manifest['release'] : null),
      3600);
  }

  $go_action = strtolower($api_rest[0]);
  if ($go_action === 'search') {
    go_search($go_base, $go_manifest);
  } elseif ($go_action === 'batch') {
    go_batch($go_base, $go_manifest);
  } elseif ($go_action === 'slim') {
    go_slim($go_base, $go_manifest);
  } else {
    go_one($go_base, $go_manifest, implode('/', $api_rest), $GO_SECTIONS, $GO_DEFAULT_ANNOTATION);
  }
  return;

/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

function go_attributes($t) {
  return array(
    'id' => $t['id'],
    'name' => $t['name'],
    'namespace' => $t['namespace'],
    'aspect' => go_aspect_label($t['namespace']),
    'definition' => $t['definition'],
    'depth' => $t['depth'],
    'slim' => (bool) $t['slim'],
    'root' => in_array($t['id'], MgdbGo::ROOTS, true),
    'obsolete' => (bool) $t['obsolete'],
    'replaced_by' => $t['replaced_by'],
    'merged_from' => isset($t['merged_into']) && $t['merged_into'] !== null ? $t['requested'] : null
  );
}//go_attributes

function go_aspect_label($ns) {
  $labels = array('biological_process' => 'biological process', 'molecular_function' => 'molecular function', 'cellular_component' => 'cellular component');
  return isset($labels[$ns]) ? $labels[$ns] : $ns;
}//go_aspect_label

function go_links($base, $t) {
  $id = $t['id'];
  return array(
    'self' => $base . '/api/v1/data/go/' . rawurlencode($id),
    'amigo' => 'https://amigo.geneontology.org/amigo/term/' . rawurlencode($id),
    'quickgo' => 'https://www.ebi.ac.uk/QuickGO/term/' . rawurlencode($id),
    'ontobee' => 'http://purl.obolibrary.org/obo/' . str_replace(':', '_', $id),
    'replaced_by' => !empty($t['replaced_by']) ? $base . '/api/v1/data/go/' . rawurlencode($t['replaced_by']) : null
  );
}//go_links

/////
// GET /api/v1/data/go/{term}
/////

function go_one($base, $manifest, $rawId, $sections, $defaultAnnotation) {
  $id = MgdbGo::normaliseId(MgdbApi::identifier($rawId));
  if ($id === null) {
    MgdbApi::problem(400, 'invalid-term', 'Invalid GO identifier', 'A GO term looks like GO:0003677.', array('identifier' => $rawId));
  }
  $t = MgdbGo::term($id);
  if ($t === null) {
    MgdbApi::problem(404, 'unknown-term', 'Unknown GO term',
      'No term by that id in GO release ' . (isset($manifest['release']) ? $manifest['release'] : '(unknown)') . '.',
      array('identifier' => $id, 'search' => $base . '/api/v1/data/go/search?q=' . rawurlencode($id)));
  }
  $asked = MgdbData::listParam('fields');
  $wanted = $asked === null ? $sections : array_values(array_intersect($sections, $asked));
  if ($asked !== null && count($wanted) === 0) {
    MgdbApi::problem(400, 'invalid-fields', 'Invalid fields', 'fields names none of: ' . implode(', ', $sections) . '.');
  }
  $annotation = MgdbApi::query('annotation', $defaultAnnotation);
  if (!preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $annotation)) {
    MgdbApi::problem(400, 'invalid-annotation', 'Invalid annotation', 'annotation names an annotation version such as Zm00001eb.1.');
  }
  $limit = MgdbData::intParam('limit', 200, 1, 500);
  $offset = MgdbData::intParam('offset', 0, 0, 100000000);

  $out = array();
  $meta = array('dataset' => 'go', 'release' => isset($manifest['release']) ? $manifest['release'] : null);
  if ($t['merged_into'] !== null) { $meta['resolved_as'] = $t['id']; $meta['requested'] = $t['requested']; }
  $counts = array('ancestors' => 0, 'parents' => 0, 'children' => MgdbGo::childCount($t['id']), 'descendants' => MgdbGo::descendantCount($t['id']));

  if (in_array('lineage', $wanted, true)) {
    $out['lineage'] = MgdbGo::lineage($t['id']);
    $counts['ancestors'] = count($out['lineage']);
  }
  if (in_array('parents', $wanted, true)) {
    $out['parents'] = MgdbGo::parentsOf($t['id']);
    $counts['parents'] = count($out['parents']);
  }
  if (in_array('children', $wanted, true)) {
    $out['children'] = MgdbGo::childrenOf($t['id'], 500);
  }
  if (in_array('slim', $wanted, true)) {
    $ann = MgdbGo::annotate(array($t['id']));
    $out['slim'] = isset($ann['terms'][$t['id']]) ? $ann['terms'][$t['id']]['slim_ancestors'] : array();
  }
  if (in_array('interpro', $wanted, true)) {
    $out['interpro'] = array_map(function ($e) use ($base) {
      $e['domains'] = $base . '/api/v1/data/domains/current/entry/' . rawurlencode($e['accession']);
      return $e;
    }, MgdbGo::iprFor($t['id']));
  }

  /* The genes: the one database contact. The annotation table is indexed
     on (obo_term, validation_lvl); one count and one page. */
  $queries = 0;
  if (in_array('genes', $wanted, true) || in_array('annotations', $wanted, true)) {
    global $DBConn;
    if (!$DBConn && function_exists('connect_to_database')) { $DBConn = connect_to_database(false); }
    if (!$DBConn) {
      MgdbApi::warn('database_unavailable', 'The database could not be reached, so genes and annotations are not listed.');
    } else {
      if (in_array('annotations', $wanted, true)) {
        $sth = make_query($DBConn, "
          SELECT gene_model_version AS annotation, count(DISTINCT gene_model_id) AS genes, count(*) AS rows
          FROM perm_tables.id_ontology
          WHERE obo_term = :term AND gene_model_id IS NOT NULL
          GROUP BY gene_model_version ORDER BY genes DESC", 1, array('term' => $t['id']));
        $queries++;
        $out['annotations'] = array();
        while ($row = retrieve_row($sth)) {
          $out['annotations'][] = array('annotation' => MgdbApi::text($row['annotation']), 'genes' => (int) $row['genes'], 'rows' => (int) $row['rows'],
                                        'genes_url' => $base . '/api/v1/data/go/' . rawurlencode($t['id']) . '?fields=genes&annotation=' . rawurlencode($row['annotation']));
        }
      }
      if (in_array('genes', $wanted, true)) {
        $sth = make_query($DBConn, "
          SELECT count(DISTINCT gene_model_id) AS n FROM perm_tables.id_ontology
          WHERE obo_term = :term AND gene_model_version = :ver", 1, array('term' => $t['id'], 'ver' => $annotation));
        $queries++;
        $row = retrieve_row($sth);
        $total = $row ? (int) $row['n'] : 0;
        $sth = make_query($DBConn, "
          SELECT o.gene_model_id AS gene,
                 string_agg(DISTINCT o.evidence_code, ',') AS evidence,
                 string_agg(DISTINCT p.name, '; ') AS sources,
                 string_agg(DISTINCT o.protein_id, ',') AS proteins
          FROM perm_tables.id_ontology o
            LEFT JOIN mgdb.person p ON p.id = o.source::bigint
          WHERE o.obo_term = :term AND o.gene_model_version = :ver
          GROUP BY o.gene_model_id ORDER BY o.gene_model_id
          LIMIT :lim OFFSET :off", 1, array('term' => $t['id'], 'ver' => $annotation, 'lim' => $limit, 'off' => $offset));
        $queries++;
        $genes = array();
        while ($row = retrieve_row($sth)) {
          $gene = MgdbApi::text($row['gene']);
          $genes[] = array(
            'gene' => $gene,
            'evidence' => MgdbApi::text($row['evidence']) !== null ? explode(',', $row['evidence']) : array(),
            'sources' => MgdbApi::text($row['sources']) !== null ? explode('; ', $row['sources']) : array(),
            'proteins' => MgdbApi::text($row['proteins']) !== null ? explode(',', $row['proteins']) : array(),
            'record' => $base . '/api/v1/records/gene/' . rawurlencode($gene),
            'html' => '/gene_center/gene/' . rawurlencode($gene)
          );
        }
        $out['genes'] = $genes;
        $meta['annotation'] = $annotation;
        $meta['genes_total'] = $total;
        $meta['limit'] = $limit;
        $meta['offset'] = $offset;
        $meta['returned'] = count($genes);
        $counts['genes'] = $total;
        $next = ($offset + $limit < $total)
          ? $base . '/api/v1/data/go/' . rawurlencode($t['id']) . '?fields=genes&annotation=' . rawurlencode($annotation) . '&limit=' . $limit . '&offset=' . ($offset + $limit)
          : null;
      }
    }
  }
  MgdbApi::countQuery($queries);

  $attributes = go_attributes($t);
  $attributes['counts'] = $counts;
  $links = go_links($base, $t);
  if (isset($next)) { $links['next'] = $next; }
  $links['genes_tsv'] = null;
  $links['search_children'] = $base . '/api/v1/data/go/' . rawurlencode($t['id']) . '?fields=children';
  MgdbApi::sendData(
    array('type' => 'go_term', 'id' => $t['id'], 'attributes' => $attributes, 'sections' => $out),
    $links,
    MgdbData::fileReadsMeta($meta),
    86400);
}//go_one

/////
// GET /api/v1/data/go/search?q=
/////

function go_search($base, $manifest) {
  $q = trim(MgdbApi::query('q', ''));
  if (strlen($q) < 2) {
    MgdbApi::problem(400, 'missing-query', 'Missing query', 'q must be at least two characters: a word from a term name, or a GO id.');
  }
  $aspect = strtolower(trim(MgdbApi::query('aspect', '')));
  $nsMap = array('' => null, 'bp' => 'biological_process', 'mf' => 'molecular_function', 'cc' => 'cellular_component',
                 'biological_process' => 'biological_process', 'molecular_function' => 'molecular_function', 'cellular_component' => 'cellular_component');
  if (!array_key_exists($aspect, $nsMap)) {
    MgdbApi::problem(400, 'invalid-aspect', 'Invalid aspect', 'aspect is bp, mf or cc.');
  }
  $limit = MgdbData::intParam('limit', 25, 1, 100);
  $hits = MgdbGo::search($q, $nsMap[$aspect], $limit);
  $items = array();
  foreach ($hits as $h) {
    $h['aspect'] = go_aspect_label($h['namespace']);
    $h['links'] = array('self' => $base . '/api/v1/data/go/' . rawurlencode($h['id']));
    $items[] = $h;
  }
  MgdbApi::sendData(
    array('type' => 'go_search', 'id' => $q,
          'attributes' => array('query' => $q, 'aspect' => $aspect === '' ? null : $nsMap[$aspect], 'limit' => $limit, 'returned' => count($items),
                                'note' => 'Live terms whose name contains the query, names starting with it first, then shallower terms. Synonyms are not searched.'),
          'sections' => array('terms' => $items)),
    array('dataset' => $base . '/api/v1/data/go'),
    MgdbData::fileReadsMeta(array('dataset' => 'go', 'release' => isset($manifest['release']) ? $manifest['release'] : null)),
    3600);
}//go_search

/////
// GET /api/v1/data/go/batch?ids=
/////

function go_batch($base, $manifest) {
  $ids = MgdbData::ids();
  $items = array();
  $missing = array();
  foreach ($ids as $raw) {
    $t = MgdbGo::term($raw);
    if ($t === null) { $missing[] = $raw; continue; }
    $a = go_attributes($t);
    $items[] = array('type' => 'go_term', 'id' => $t['id'], 'attributes' => $a, 'links' => go_links($base, $t));
  }
  MgdbApi::sendData($items,
    array('dataset' => $base . '/api/v1/data/go'),
    MgdbData::fileReadsMeta(array('dataset' => 'go', 'release' => isset($manifest['release']) ? $manifest['release'] : null,
                                  'requested' => count($ids), 'returned' => count($items), 'missing' => $missing)),
    86400);
}//go_batch

/////
// GET /api/v1/data/go/slim
/////

function go_slim($base, $manifest) {
  $byNs = array();
  foreach (MgdbGo::slimTerms() as $t) {
    $byNs[$t['namespace']][] = array('id' => $t['id'], 'name' => $t['name'], 'depth' => $t['depth'],
                                     'links' => array('self' => $base . '/api/v1/data/go/' . rawurlencode($t['id'])));
  }
  MgdbApi::sendData(
    array('type' => 'go_slim', 'id' => isset($manifest['slim']) ? $manifest['slim'] : 'goslim_plant',
          'attributes' => array('name' => isset($manifest['slim']) ? $manifest['slim'] : 'goslim_plant',
                                'terms' => array_sum(array_map('count', $byNs)),
                                'note' => 'The plant GO slim as tagged in this GO release, without the three roots. The gene record folds every annotated term onto these.'),
          'sections' => $byNs),
    array('dataset' => $base . '/api/v1/data/go'),
    MgdbData::fileReadsMeta(array('dataset' => 'go', 'release' => isset($manifest['release']) ? $manifest['release'] : null)),
    86400);
}//go_slim
