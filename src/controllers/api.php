<?PHP
/* file: api.php
 *
 * purpose: front controller for the MaizeGDB JSON API, mounted at /api.
 *
 *          Loaded by controller.php, which dispatches on the first path
 *          segment. controller.php only captures four path segments into
 *          CONTROLLER/PAGE/ID/EXTRA, so the full path is re-parsed here.
 *
 *          Routes:
 *            GET /api/v1/                        service description
 *            GET /api/v1/openapi                 OpenAPI 3.1 document
 *            GET /api/v1/records/{type}/{id}     one record, fully assembled
 *
 *          Resources live in include/api/v1/records/{type}.php and are given
 *          $api_identifier and $DBConn. The response contract is in
 *          include/api/v1/lib/mgdb_api.php. They sit under include/ rather
 *          than under a public /api directory for two reasons: a real
 *          directory at /api would stop .htaccess from routing /api/... here
 *          at all, and the resource files must not be executable by fetching
 *          their path directly.
 */

  include_once('./include/db-api.php');
  include_once('./include/stock_record_lib.php');
  include_once('./include/gene_record_lib.php');
  include_once('./include/map_record_lib.php');
  include_once('./include/variation_record_lib.php');
  include_once('./include/gene_product_record_lib.php');
  include_once('./include/marker_record_lib.php');
  include_once('./include/phenotype_record_lib.php');
  include_once('./include/pan_gene_record_lib.php');
  define('MGDB_API', true);
  include_once('./include/api/v1/lib/mgdb_api.php');

  $system = getSystemInfo('mgdb.conf');

  MgdbApi::begin();
  MgdbApi::requireReadMethod();
  MgdbApi::requireJsonAcceptable();

  // Re-parse the path. REQUEST_URI is the only place the whole route survives.
  $api_path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
  $api_segments = array_values(array_filter(explode('/', (string) $api_path), function ($segment) {
    return $segment !== '';
  }));
  // Drop the mount point.
  array_shift($api_segments);

  $api_version = isset($api_segments[0]) ? $api_segments[0] : '';

  if ($api_version === '') {
    MgdbApi::sendDocument(array(
      'service' => 'MaizeGDB API',
      'versions' => array(
        array('version' => 'v1', 'status' => 'current', 'href' => MgdbApi::baseUrl() . '/api/v1/')
      )
    ));
  }

  if ($api_version !== 'v1') {
    MgdbApi::problem(404, 'unknown-version', 'Unknown API version',
      'This server serves v1. Requested: ' . htmlspecialchars($api_version, ENT_QUOTES, 'UTF-8') . '.');
  }

  $api_resource = isset($api_segments[1]) ? $api_segments[1] : '';

  /////
  // GET /api/v1/ — what this API offers, so a client can start without docs
  /////

  if ($api_resource === '') {
    MgdbApi::sendDocument(array(
      'service' => 'MaizeGDB API',
      'version' => 'v1',
      'description' => 'Read-only access to MaizeGDB records as JSON. One request returns a whole record.',
      'documentation' => MgdbApi::baseUrl() . '/api/v1/openapi',
      'record_types' => api_record_types(),
      'conventions' => array(
        'errors' => 'RFC 9457 problem details, sent as application/problem+json.',
        'caching' => 'Strong ETag on every response; send If-None-Match to get a 304.',
        'sparse_fields' => 'Add ?fields=a,b to return only those sections.',
        'absent_values' => 'null for a missing scalar, [] for a missing list. Never an empty string.',
        'identifiers' => 'A record may be addressed by its numeric id or by name; the canonical id is in data.id.'
      )
    ), 86400);
  }

  /////
  // GET /api/v1/openapi.json — machine-readable description
  /////

  /* Served without a .json extension deliberately. The sitewide .htaccess
     skips its rewrite for any URI matching the unanchored pattern `(.js)`,
     which "openapi.json" satisfies — the request would never reach this
     controller. See ADMIN_DEPENDENCIES.md AD-011. */
  if ($api_resource === 'openapi' || $api_resource === 'schema') {
    include('./include/api/v1/openapi.php');
    return;
  }

  /////
  // GET /api/v1/coming_soon — MaizeGDB upcoming features roadmap
  /////

  if ($api_resource === 'coming_soon' || $api_resource === 'roadmap') {
    $cs_file = $system['root_dir'] . '/data/coming_soon.json';
    if (!is_file($cs_file)) {
      $cs_file = $_SERVER['DOCUMENT_ROOT'] . '/data/coming_soon.json';
    }
    $cs_data = is_file($cs_file) ? json_decode(file_get_contents($cs_file), true) : array('items' => array());
    MgdbApi::sendDocument($cs_data, 3600);
    return;
  }

  /////
  // GET /api/v1/records/{type}/{id}
  /////

  if ($api_resource !== 'records') {
    MgdbApi::problem(404, 'unknown-resource', 'Unknown resource',
      'This version serves /records. See ' . MgdbApi::baseUrl() . '/api/v1/ for what is available.');
  }

  $api_type = isset($api_segments[2]) ? $api_segments[2] : '';
  if ($api_type === '') {
    MgdbApi::sendDocument(array('record_types' => api_record_types()), 86400);
  }

  // The type names a file that gets included, so it is matched against a fixed
  // list rather than sanitized. Nothing derived from the URL reaches a path.
  $api_known = array();
  foreach (api_record_types() as $api_entry) {
    $api_known[] = $api_entry['type'];
  }
  if (!in_array($api_type, $api_known, true)) {
    MgdbApi::problem(404, 'unknown-record-type', 'Unknown record type',
      'No such record type in this API version.',
      array('available_types' => $api_known));
  }

  if (!isset($api_segments[3]) || $api_segments[3] === '') {
    MgdbApi::problem(400, 'missing-identifier', 'No record identifier',
      'Request a specific record: /api/v1/records/' . $api_type . '/{id}.');
  }

  /* Everything after the record type is the identifier, rejoined. A DOI
     contains slashes — /api/v1/records/reference/10.1016/j.molp.2020.03.003 —
     and an encoded %2F is decoded by Apache before PHP sees the path, so
     neither form survives as a single segment. Rejoining is the only
     representation that works for both. */
  $api_identifier = MgdbApi::identifier(implode('/', array_slice($api_segments, 3)));

  $DBConn = connect_to_database(false);
  if (!$DBConn) {
    MgdbApi::problem(503, 'database-unavailable', 'Database unavailable',
      'The record store could not be reached. Please retry.');
  }

  include('./include/api/v1/records/' . $api_type . '.php');
  return;

/////
// FUNCTIONS
/////////////////////////////////////////////////////////////////////////////////////////

/* The registry. Adding a record type means adding an entry here and a file in
   api/v1/records/. */
function api_record_types() {
  $base = MgdbApi::baseUrl();
  return array(
    array(
      'type' => 'stock',
      'description' => 'Maize genetic stocks and germplasm.',
      'href' => $base . '/api/v1/records/stock/{id}',
      'example' => $base . '/api/v1/records/stock/CML277'
    ),
    array(
      'type' => 'reference',
      'description' => 'Curated maize literature: papers, abstracts, chapters, and newsletter articles.',
      'href' => $base . '/api/v1/records/reference/{id}',
      'example' => $base . '/api/v1/records/reference/9043389'
    ),
    array(
      'type' => 'gene',
      'description' => 'Maize genes: the classical loci and the gene models that represent them across assemblies.',
      'href' => $base . '/api/v1/records/gene/{id}',
      'example' => $base . '/api/v1/records/gene/Zm00001eb067740'
    ),
    array(
      'type' => 'variation',
      'description' => 'Maize alleles, mutations, chromosome rearrangements, and other genetic variations.',
      'href' => $base . '/api/v1/records/variation/{id}',
      'example' => $base . '/api/v1/records/variation/bz1'
    ),
    array(
      'type' => 'gene_product',
      'description' => 'Maize gene products: enzymes, structural and storage proteins, transporters, and regulatory proteins, with the loci that encode them.',
      'href' => $base . '/api/v1/records/gene_product/{id}',
      'example' => $base . '/api/v1/records/gene_product/ferritin'
    ),
    array(
      'type' => 'marker',
      'description' => 'Maize markers and probes: the loci they detect, where those loci map, and the gel patterns and sequences behind them.',
      'href' => $base . '/api/v1/records/marker/{id}',
      'example' => $base . '/api/v1/records/marker/p-umc10'
    ),
    array(
      'type' => 'phenotype',
      'description' => 'Maize phenotypes: the trait and value they describe, the genes and variations that show them, and the stocks that carry them.',
      'href' => $base . '/api/v1/records/phenotype/{id}',
      'example' => $base . '/api/v1/records/phenotype/dwarf%20plant'
    ),
    array(
      'type' => 'pan_gene',
      'description' => 'Pan-genes: the gene models a pan-gene analysis grouped as the same gene across maize assemblies, and everything recorded about them.',
      'href' => $base . '/api/v1/records/pan_gene/{id}',
      'example' => $base . '/api/v1/records/pan_gene/Zm00023ab070050_T001'
    ),
    array(
      'type' => 'map',
      'description' => 'Maize genetic, cytogenetic, and physical chromosome maps.',
      'href' => $base . '/api/v1/records/map/{id}',
      'example' => $base . '/api/v1/records/map/64489'
    ),
    array(
      'type' => 'linkage_group',
      'description' => 'Linkage groups: the maize chromosomes, and the plasmids, phage, BACs and organellar genomes a locus can otherwise sit on.',
      'href' => $base . '/api/v1/records/linkage_group/{id}',
      'example' => $base . '/api/v1/records/linkage_group/1'
    ),
    array(
      'type' => 'primer',
      'description' => 'Primers and restriction enzymes: the sequence, melting temperature, and the probes each is the source DNA for.',
      'href' => $base . '/api/v1/records/primer/{id}',
      'example' => $base . '/api/v1/records/primer/51551'
    ),
    array(
      'type' => 'gel',
      'description' => 'Gel patterns: one probe and enzyme run against one stock, with the bands scored and the DNA polymorphisms called from them.',
      'href' => $base . '/api/v1/records/gel/{id}',
      'example' => $base . '/api/v1/records/gel/888243'
    ),
    array(
      'type' => 'recombination',
      'description' => 'Recombination datasets: one mapping cross, with the loci scored, the parental alleles, the observed class frequencies, and pairwise recombination frequencies in Haldane and Kosambi centimorgans.',
      'href' => $base . '/api/v1/records/recombination/{id}',
      'example' => $base . '/api/v1/records/recombination/9017518'
    ),
    array(
      'type' => 'map_scores',
      'description' => 'Map scores: one marker scored across a mapping panel, with the probe and probed site behind it and the maps that used it.',
      'href' => $base . '/api/v1/records/map_scores/{id}',
      'example' => $base . '/api/v1/records/map_scores/132439'
    ),
    array(
      'type' => 'term',
      'description' => 'Controlled-vocabulary terms across 105 types -- traits, body parts, chemicals, methods, keywords -- with their definitions, synonyms, related terms, and the phenotypes and QTL analyses that use them.',
      'href' => $base . '/api/v1/records/term/{id}',
      'example' => $base . '/api/v1/records/term/Plant_height'
    ),
    array(
      'type' => 'locus',
      'description' => 'Classical loci across 25 kinds -- points, probed sites, QTL, centromeres, transposable elements and the rest -- with their map positions, alleles, and the probes that detect them. Loci of type Gene are served by the gene resource.',
      'href' => $base . '/api/v1/records/locus/{id}',
      'example' => $base . '/api/v1/records/locus/adh1'
    )
  );
}//api_record_types
?>
