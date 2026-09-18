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
 *            GET /api                            documentation page for a
 *                                                browser; the version list as
 *                                                JSON for anything else
 *            GET /api/docs                       the documentation page
 *            GET /api/v1/                        service description
 *            GET /api/v1/openapi                 OpenAPI 3.1 document
 *            GET /api/v1/records/{type}/{id}     one record, fully assembled;
 *                                                ?format=jsonld or
 *                                                Accept: application/ld+json
 *                                                for the same record as
 *                                                linked data
 *
 *          Resources live in include/api/v1/records/{type}.php and are given
 *          $api_identifier and $DBConn. The response contract is in
 *          include/api/v1/lib/mgdb_api.php; the linked-data mapping in
 *          include/api/v1/lib/mgdb_jsonld.php; the documentation page in
 *          include/api/v1/docs.php. They sit under include/ rather than
 *          under a public /api directory for two reasons: a real directory at
 *          /api would stop .htaccess from routing /api/... here at all, and
 *          the resource files must not be executable by fetching their path
 *          directly.
 *
 * history:
 *  09/11/26  claude  documentation page, JSON-LD output, and the registry
 *                    now carries each type's sections and identifier forms
 *                    so the OpenAPI document and the page read one list.
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
  /* The DOI and PubMed ID every record's references section reads. */
  include_once('./include/reference_ids_lib.php');
  define('MGDB_API', true);
  include_once('./include/api/v1/lib/mgdb_api.php');

  $system = getSystemInfo('mgdb.conf');

  // Re-parse the path. REQUEST_URI is the only place the whole route survives.
  $api_path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
  $api_segments = array_values(array_filter(explode('/', (string) $api_path), function ($segment) {
    return $segment !== '';
  }));
  // Drop the mount point.
  array_shift($api_segments);

  $api_version = isset($api_segments[0]) ? $api_segments[0] : '';

  /////
  // The documentation page.
  //
  // A browser asking for /api gets the page; a script asking for /api gets the
  // version list as JSON, as it always has. The two are told apart by the
  // Accept header, which is also why every API response carries Vary: Accept.
  // /api/docs is the page whatever the client asks for.
  /////

  if ($api_version === 'docs' || ($api_version === '' && api_wants_html())) {
    include('./include/api/v1/docs.php');
    exit;
  }

  MgdbApi::begin();
  MgdbApi::requireReadMethod();
  MgdbApi::requireJsonAcceptable();

  if ($api_version === '') {
    MgdbApi::sendDocument(array(
      'service' => 'MaizeGDB API',
      'documentation' => MgdbApi::baseUrl() . '/api/docs',
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
      'documentation' => MgdbApi::baseUrl() . '/api/docs',
      'openapi' => MgdbApi::baseUrl() . '/api/v1/openapi',
      'record_types' => api_record_types(),
      'datasets' => api_data_types(),
      'conventions' => array(
        'errors' => 'RFC 9457 problem details, sent as application/problem+json.',
        'caching' => 'Strong ETag on every response; send If-None-Match to get a 304.',
        'sparse_fields' => 'Add ?fields=a,b to return only those sections.',
        'linked_data' => 'Add ?format=jsonld, or send Accept: application/ld+json, for the record as JSON-LD (schema.org and Bioschemas types).',
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
  // GET /api/v1/data/{dataset}/... -- datasets served from prebuilt files
  //
  // Gene models and protein domains, keyed by genome, answered from the
  // release directories under data/ that tools/gene_models_index.py and
  // tools/domains_index.py write. No database connection is opened unless
  // an identifier misses the release and has to go through the gene
  // resolver. Additive: nothing under /records is touched by this branch.
  /////

  if ($api_resource === 'data') {
    include_once('./include/api/v1/lib/mgdb_data.php');
    $api_dataset = isset($api_segments[2]) ? strtolower($api_segments[2]) : '';
    if ($api_dataset === '') {
      MgdbApi::sendDocument(array(
        'service' => 'MaizeGDB API',
        'version' => 'v1',
        'description' => 'Datasets served from release files: one request answers from prebuilt shards, with no database query unless an identifier has to be resolved.',
        'documentation' => MgdbApi::baseUrl() . '/api/docs#api-datasets',
        'datasets' => api_data_types(),
        'conventions' => array(
          'genomes' => 'The genome segment is the assembly name, e.g. Zm-B73-REFERENCE-NAM-5.0. Aliases and "current" answer with a 302 to it.',
          'coordinates' => '1-based, inclusive. Regions are {sequence}:{start}-{end}.',
          'formats' => 'json by default; ?format=gff3, bed or tsv where the route offers it.',
          'paging' => 'limit and offset; links.next carries the next page.',
          'caching' => 'Releases are immutable. Strong ETag on every response; Cache-Control public for a day.',
          'errors' => 'RFC 9457 problem details, as application/problem+json.'
        )
      ), 3600);
    }
    $api_entry = api_data_entry($api_dataset);
    if ($api_entry === null) {
      MgdbApi::problem(404, 'unknown-dataset', 'Unknown dataset',
        'No such dataset in this API version.',
        array('available_datasets' => array_map(function ($e) { return $e['dataset']; }, api_data_registry())));
    }
    $api_rest = array_slice($api_segments, 3);
    $DBConn = null;   // connected only when an identifier has to be resolved
    include('./include/api/v1/data/' . $api_entry['file'] . '.php');
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

  // JSON or JSON-LD. Decided before any query runs, so an invalid format is a
  // 400 that cost nothing.
  MgdbApi::negotiateFormat();

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

/* Does the client want a page rather than a document? A browser's default
   Accept header names text/html first; curl, requests, httr2 and fetch send
   none, or ask for JSON. Anything that does not name text/html gets JSON. */
function api_wants_html() {
  if (!isset($_SERVER['HTTP_ACCEPT'])) {
    return false;
  }
  return strpos(strtolower($_SERVER['HTTP_ACCEPT']), 'text/html') !== false;
}//api_wants_html

/* The registry. Adding a record type means adding an entry here and a file in
   api/v1/records/.

   Each entry carries what the three consumers need: the service index and the
   OpenAPI document (type, description, sections, example), the documentation
   page (label, identifiers, html), and the record pages (the page route). The
   sections list must match the $SECTIONS array at the top of the resource
   file -- it is what `fields` is validated against there, and what the
   documentation promises here. */
function api_record_registry() {
  return array(
    array(
      'type' => 'gene',
      'label' => 'Gene',
      'description' => 'Maize genes: the classical loci and the gene models that represent them across assemblies.',
      'example' => 'Zm00001eb067740',
      'html' => '/gene_center/gene/{id}',
      'sections' => array('overview', 'structure', 'function', 'expression', 'variation', 'pan_gene',
                          'orthologs', 'paralogs', 'locus', 'references', 'xrefs', 'sequences'),
      'identifiers' => array(
        'gene model name (Zm00001eb067740)', 'transcript name (Zm00001eb067740_T001)',
        'protein name (Zm00001eb067740_P001)', 'classical gene symbol (lg1)',
        'full gene name (liguleless1)', 'synonym (ZmSBP15)', 'GenBank or old GenBank name',
        'numeric locus id (12386)'
      ),
      'notes' => 'A withdrawn gene model answers 410 with its replacement. For an assembly with a gene-models release (B73 v5), overview.strand, structure.gene_model, structure.domains and structure.model come from the annotation files; for other assemblies overview.strand and structure.exon_structure are null, because neither is held in the database.'
    ),
    array(
      'type' => 'pan_gene',
      'label' => 'Pan-gene',
      'description' => 'Pan-genes: the gene models a pan-gene analysis grouped as the same gene across maize assemblies, and everything recorded about them.',
      'example' => 'Zm00023ab070050_T001',
      'html' => '/pan_gene_center/pan_gene/{id}',
      'sections' => array('overview', 'members', 'analysis', 'presence', 'function', 'domains', 'expression', 'expression_matrix', 'insertions',
                          'traits', 'proteins', 'pathways', 'sequence', 'tree', 'pangenome', 'downloads', 'viewers'),
      'identifiers' => array(
        'any member gene model or transcript, from any supported annotation', 'pan-gene name (pan-zea.v4.pan02070)',
        'classical locus symbol (lg1)', 'UniProt or EC accession', 'numeric chado feature id'
      ),
      'notes' => 'The canonical id is the pan-gene name, whichever member was asked for.'
    ),
    array(
      'type' => 'locus',
      'label' => 'Locus',
      'description' => 'Classical loci across 25 kinds -- points, probed sites, QTL, centromeres, transposable elements and the rest -- with their map positions, alleles, and the probes that detect them. Loci of type Gene are served by the gene resource.',
      'example' => 'adh1',
      'html' => '/data_center/locus?id={id}',
      'sections' => array('overview', 'positions', 'nearby', 'alleles', 'stocks', 'genetic', 'detected',
                          'related', 'offsite', 'annotations', 'references', 'physical'),
      'identifiers' => array('locus name (adh1)', 'full name (alcohol dehydrogenase1)', 'synonym', 'numeric locus id'),
      'notes' => 'physical calls the GBrowse feature service and is the only section that leaves this server; meta.warnings says when it did not answer.'
    ),
    array(
      'type' => 'gene_product',
      'label' => 'Gene product',
      'description' => 'Maize gene products: enzymes, structural and storage proteins, transporters, and regulatory proteins, with the loci that encode them.',
      'example' => 'ferritin',
      'html' => '/data_center/gene_product?id={id}',
      'sections' => array('overview', 'annotations', 'related', 'offsite', 'references'),
      'identifiers' => array('product name (ferritin)', 'synonym (delta zein)', 'numeric id (58066)'),
      'notes' => null
    ),
    array(
      'type' => 'variation',
      'label' => 'Variation',
      'description' => 'Maize alleles, mutations, chromosome rearrangements, and other genetic variations.',
      'example' => 'bz1',
      'html' => '/data_center/variation?id={id}',
      'sections' => array('overview', 'phenotypes', 'stocks', 'related', 'annotations', 'images', 'references'),
      'identifiers' => array('variation name (bz1)', 'synonym (6709H)', 'numeric id (10691698)'),
      'notes' => null
    ),
    array(
      'type' => 'phenotype',
      'label' => 'Phenotype',
      'description' => 'Maize phenotypes: the trait and value they describe, the genes and variations that show them, and the stocks that carry them.',
      'example' => 'dwarf plant',
      'html' => '/data_center/phenotype?id={id}',
      'sections' => array('overview', 'genes', 'variations', 'stocks', 'images', 'offsite', 'annotations', 'references'),
      'identifiers' => array('phenotype name (dwarf plant)', 'synonym', 'numeric id (11041)'),
      'notes' => null
    ),
    array(
      'type' => 'stock',
      'label' => 'Stock',
      'description' => 'Maize genetic stocks and germplasm.',
      'example' => 'CML277',
      'html' => '/data_center/stock?id={id}',
      'sections' => array('overview', 'pedigree', 'related', 'typsim', 'references', 'offsite', 'grin'),
      'identifiers' => array('stock name (CML277)', 'alternate description', 'GRIN accession (PI 595550)', 'numeric id (105132)'),
      'notes' => 'grin calls the USDA GRIN service live and is the only section that leaves this server; ask for fields without it when speed matters.'
    ),
    array(
      'type' => 'reference',
      'label' => 'Reference',
      'description' => 'Curated maize literature: papers, abstracts, chapters, and newsletter articles.',
      'example' => '9043389',
      'html' => '/data_center/reference?id={id}',
      'sections' => array('overview', 'authors', 'abstract', 'citation', 'describes', 'links', 'editorial'),
      'identifiers' => array('numeric id (9043389)', 'DOI, unencoded (10.1016/j.molp.2020.03.003)', 'PubMed id'),
      'notes' => 'A DOI contains slashes and is given as the rest of the path, exactly as written.'
    ),
    array(
      'type' => 'term',
      'label' => 'Term',
      'description' => 'Controlled-vocabulary terms across 105 types -- traits, body parts, chemicals, methods, keywords -- with their definitions, synonyms, related terms, and the phenotypes and QTL analyses that use them.',
      'example' => 'Plant_height',
      'html' => '/data_center/term?id={id}',
      'sections' => array('overview', 'phenotypes', 'analyses', 'values', 'related', 'images', 'offsite', 'references'),
      'identifiers' => array('term name (Plant_height)', 'synonym', 'numeric id'),
      'notes' => null
    ),
    array(
      'type' => 'marker',
      'label' => 'Marker',
      'description' => 'Maize markers and probes: the loci they detect, where those loci map, and the gel patterns and sequences behind them.',
      'example' => 'p-umc10',
      'html' => '/data_center/marker?id={id}',
      'sections' => array('overview', 'loci', 'positions', 'related', 'offsite', 'annotations', 'references'),
      'identifiers' => array('marker name, with or without the p- prefix (p-umc10, umc10)', 'synonym', 'numeric id (44544)'),
      'notes' => null
    ),
    array(
      'type' => 'primer',
      'label' => 'Primer',
      'description' => 'Primers and restriction enzymes: the sequence, melting temperature, and the probes each is the source DNA for.',
      'example' => '51551',
      'html' => '/data_center/primer?id={id}',
      'sections' => array('overview', 'probes', 'isoschizomers', 'references'),
      'identifiers' => array('primer or enzyme name', 'numeric id (51551)'),
      'notes' => null
    ),
    array(
      'type' => 'map',
      'label' => 'Map',
      'description' => 'Maize genetic, cytogenetic, and physical chromosome maps.',
      'example' => '64489',
      'html' => '/data_center/map/{id}',
      'sections' => array('overview', 'coordinates', 'related_maps', 'references', 'qtl_experiments'),
      'identifiers' => array('map name', 'numeric id (64489)'),
      'notes' => 'coordinates is capped by max_items; meta.counts.coordinates is the true total.'
    ),
    array(
      'type' => 'linkage_group',
      'label' => 'Linkage group',
      'description' => 'Linkage groups: the maize chromosomes, and the plasmids, phage, BACs and organellar genomes a locus can otherwise sit on.',
      'example' => '1',
      'html' => '/data_center/lg/{id}',
      'sections' => array('overview', 'maps', 'loci', 'references'),
      'identifiers' => array('chromosome number (1)', 'linkage group name', 'numeric id'),
      'notes' => null
    ),
    array(
      'type' => 'qtl',
      'label' => 'QTL experiment',
      'description' => 'QTL experiments: the mapping panel and marker set behind a study, one entry per trait evaluated with the method and environment it was scored under, and the QTL detected with the statistics reported for each. A trait analysis id resolves to the experiment that owns it.',
      'example' => '86159',
      'html' => '/data_center/qtl?id={id}',
      'sections' => array('overview', 'evaluations', 'loci', 'maps', 'references'),
      'identifiers' => array('experiment name', 'numeric experiment id (86159)', 'trait analysis id'),
      'notes' => null
    ),
    array(
      'type' => 'gel',
      'label' => 'Gel pattern',
      'description' => 'Gel patterns: one probe and enzyme run against one stock, with the bands scored and the DNA polymorphisms called from them.',
      'example' => '888243',
      'html' => '/data_center/gel?id={id}',
      'sections' => array('overview', 'bands', 'polymorphisms', 'images', 'references'),
      'identifiers' => array('gel pattern name', 'numeric id (888243)'),
      'notes' => null
    ),
    array(
      'type' => 'recombination',
      'label' => 'Recombination',
      'description' => 'Recombination datasets: one mapping cross, with the loci scored, the parental alleles, the observed class frequencies, and pairwise recombination frequencies in Haldane and Kosambi centimorgans.',
      'example' => '9017518',
      'html' => '/data_center/recombination?id={id}',
      'sections' => array('overview', 'loci', 'alleles', 'classes', 'frequencies', 'overlaps', 'references'),
      'identifiers' => array('dataset name', 'numeric id (9017518)'),
      'notes' => null
    ),
    array(
      'type' => 'map_scores',
      'label' => 'Map scores',
      'description' => 'Map scores: one marker scored across a mapping panel, with the probe and probed site behind it and the maps that used it.',
      'example' => '132439',
      'html' => '/data_center/map_scores?id={id}',
      'sections' => array('overview', 'maps'),
      'identifiers' => array('map score name', 'numeric id (132439)'),
      'notes' => null
    )
  );
}//api_record_registry

/* The registry as the service index publishes it: absolute URLs for this
   instance, and no notes. */
function api_record_types() {
  $base = MgdbApi::baseUrl();
  $out = array();
  foreach (api_record_registry() as $entry) {
    $out[] = array(
      'type' => $entry['type'],
      'label' => $entry['label'],
      'description' => $entry['description'],
      'href' => $base . '/api/v1/records/' . $entry['type'] . '/{id}',
      'example' => $base . '/api/v1/records/' . $entry['type'] . '/' . rawurlencode($entry['example']),
      'example_id' => $entry['example'],
      'html' => $base . $entry['html'],
      'sections' => $entry['sections'],
      'identifiers' => $entry['identifiers']
    );
  }
  return $out;
}//api_record_types

/* The dataset registry: the datasets under /api/v1/data. Adding one means an
   entry here, a resource file in include/api/v1/data/, and a builder in
   tools/ that writes data/<dir>/<genome>/. The service index, the OpenAPI
   document and the /api page all read this list. */
function api_data_registry() {
  return array(
    array(
      'dataset' => 'gene-models',
      'file' => 'gene_models',
      'label' => 'Gene models',
      'description' => 'Gene, transcript, exon, CDS and UTR coordinates for an annotated genome, with strand, the canonical transcript, protein length, the classical locus and previous identifiers. Built from the published GFF3.',
      'example' => array('genome' => 'Zm-B73-REFERENCE-NAM-5.0', 'id' => 'Zm00001eb067740'),
      'routes' => array(
        '{genome}/{id}' => 'One gene with every transcript. A transcript or protein id gives its gene.',
        '{genome}/region/{sequence}:{start}-{end}' => 'Features overlapping an interval. type=gene (default), transcript, mRNA, exon, CDS or UTR; canonical=1; limit and offset.',
        '{genome}/batch?ids=' => 'Up to 200 genes in one answer; meta.missing lists the identifiers that did not resolve.'
      ),
      'sections' => array('transcripts', 'locus', 'xrefs', 'neighbors'),
      'identifiers' => array('gene model (Zm00001eb067740)', 'transcript (Zm00001eb067740_T001)', 'protein (Zm00001eb067740_P001)',
                             'a previous identifier of the same assembly', 'anything the gene record resolves, such as lg1'),
      'formats' => array('json', 'gff3', 'bed', 'tsv'),
      'caps' => array('batch_ids' => 200, 'region_limit' => 2000, 'region_span_subgene_bp' => 10000000),
      'html' => '/gene_center/gene/{id}',
      'notes' => 'Coordinates are 1-based and inclusive. Blocks are listed in transcript order with their rank. There is no v4 to v5 correspondence; a v4 identifier answers 404 with a hint.'
    ),
    array(
      'dataset' => 'domains',
      'file' => 'domains',
      'label' => 'Protein domains',
      'description' => 'InterProScan results per protein: member-database matches, InterPro entries, residue-level sites, GO and pathway terms, the domain atlas classes, and every canonical domain projected onto the genome.',
      'example' => array('genome' => 'Zm-B73-REFERENCE-NAM-5.0', 'id' => 'Zm00001eb067740_P001'),
      'routes' => array(
        '{genome}/{id}' => 'One protein. A transcript gives its protein; a gene gives its canonical protein.',
        '{genome}/entry/{accession}' => 'Every protein carrying an InterPro entry or a member signature (PF, PTHR, cd, ...). isoforms=canonical (default) or all; limit up to 500 and offset.',
        '{genome}/region/{sequence}:{start}-{end}' => 'Canonical-protein domains projected onto the genome, split at introns.',
        '{genome}/batch?ids=' => 'Up to 200 proteins, transcripts or genes.',
        '{genome}/class/{name}' => 'Every gene in one of the domain atlas functional classes, by slug (immunity-nlr-nbs-lrr) or name; without a name, the classes and their gene counts. A gene can be in several classes. format=tsv for a download.',
        '{genome}/immunity/{class}' => 'Every gene given one immunity call (NLR, RLK, RLP, PR, ...); subclass= narrows it (CNL). Without a class, the calls and their subclass counts. One call per gene. format=tsv for a download.'
      ),
      'sections' => array('matches', 'entries', 'sites', 'go', 'pathways', 'genomic', 'classes'),
      'identifiers' => array('protein (Zm00001eb067740_P001)', 'transcript: its protein', 'gene: its canonical protein',
                             'an InterPro or member-database accession on the entry route'),
      'formats' => array('json', 'tsv', 'bed'),
      'caps' => array('batch_ids' => 200, 'entry_limit' => 500, 'region_limit' => 2000),
      'html' => '/gene_center/gene/{id}',
      'notes' => 'Entries collapse member matches onto InterPro entries; draw entries, not matches. Which analyses a release carries is in its manifest; the published B73 v5 file is Pfam only.'
    ),
    array(
      'dataset' => 'expression',
      'file' => 'expression',
      'label' => 'Expression',
      'description' => 'RNA and protein abundance profiles from qTeller: every sample of every study for a gene, with the mean, median, maximum, detection count, tissue specificity, the top samples, and the same figures per tissue and per study.',
      'example' => array('genome' => 'Zm-B73-REFERENCE-NAM-5.0', 'id' => 'Zm00001eb067740'),
      'routes' => array(
        '{genome}/{id}' => 'One gene\'s profile. assay=rna, protein or all; source= keeps studies whose name contains a term.',
        '{genome}/samples' => 'The sample catalogue of a release: studies, samples, tissue and stress-condition readings.',
        '{genome}/batch?ids=' => 'Up to 200 genes; summaries by default, fields=samples for the values, format=tsv for a table.'
      ),
      'sections' => array('summary', 'samples', 'sources'),
      'identifiers' => array('gene model (Zm00001eb067740)', 'for a genome with a gene-models release: a transcript, a protein, or anything the gene record resolves'),
      'formats' => array('json', 'tsv'),
      'caps' => array('batch_ids' => 200),
      'html' => '/gene_center/gene/{id}',
      'notes' => 'Values are as each study published them (FPKM or TPM for RNA), replicates averaged by qTeller and rounded to four significant digits: compare within a study, not across. tissue is a keyword reading of the sample label; condition (abiotic stress, biotic stress, control) is the same kind of reading inside stress studies.'
    ),
    array(
      'dataset' => 'go',
      'file' => 'go',
      'genomes' => false,   /* the ontology is one thing: no genome segment */
      'label' => 'Gene Ontology',
      'description' => 'The Gene Ontology as a reference index: one term with its aspect, definition, lineage to the root, parents and children, the plant GO-slim categories it falls under, the InterPro entries InterPro2GO maps to it, and the maize gene models annotated with it. Built from go-basic.obo and InterPro2GO; the same index places the terms on the gene record.',
      'example' => array('genome' => null, 'id' => 'GO:0010119'),
      'routes' => array(
        '{term}' => 'One term. fields= picks sections; annotation= (default Zm00001eb.1), limit (up to 500) and offset page the genes.',
        'search?q=' => 'Terms whose name contains a phrase, or one id. aspect=bp, mf or cc; limit up to 100.',
        'batch?ids=' => 'Up to 200 terms, attributes only; meta.missing lists the ids the release does not know.',
        'slim' => 'The plant GO slim by aspect, without the three roots.'
      ),
      'sections' => array('lineage', 'parents', 'children', 'slim', 'interpro', 'genes', 'annotations'),
      'identifiers' => array('a GO id (GO:0003677); GO_0003677 and a bare number are accepted', 'a merged id answers with its survivor and meta.resolved_as'),
      'formats' => array('json'),
      'caps' => array('batch_ids' => 200, 'genes_limit' => 500, 'search_limit' => 100),
      'html' => null,
      'notes' => 'Only is_a and part_of relations propagate. A retired term is returned with obsolete=true and its replacement; a merged id with its survivor. genes and annotations are the two sections that query the database; the rest is read from the index.'
    )
  );
}//api_data_registry

function api_data_entry($slug) {
  foreach (api_data_registry() as $entry) {
    if ($entry['dataset'] === strtolower($slug)) { return $entry; }
  }
  return null;
}//api_data_entry

/* A registry entry as a response attribute block: no file name, absolute
   route templates. */
function api_data_summary($entry) {
  $base = MgdbApi::baseUrl();
  $routes = array();
  foreach ($entry['routes'] as $pattern => $description) {
    $routes[] = array('href' => $base . '/api/v1/data/' . $entry['dataset'] . '/' . $pattern, 'description' => $description);
  }
  return array(
    'label' => $entry['label'],
    'description' => $entry['description'],
    'routes' => $routes,
    'sections' => $entry['sections'],
    'identifiers' => $entry['identifiers'],
    'formats' => $entry['formats'],
    'caps' => $entry['caps'],
    'notes' => $entry['notes']
  );
}//api_data_summary

/* The datasets as the service index publishes them. */
function api_data_types() {
  $base = MgdbApi::baseUrl();
  $out = array();
  foreach (api_data_registry() as $entry) {
    $out[] = array(
      'dataset' => $entry['dataset'],
      'label' => $entry['label'],
      'description' => $entry['description'],
      'href' => $base . '/api/v1/data/' . $entry['dataset'],
      'example' => $base . '/api/v1/data/' . $entry['dataset'] . '/'
                   . ($entry['example']['genome'] !== null ? $entry['example']['genome'] . '/' : '') . rawurlencode($entry['example']['id']),
      'genomes' => !(isset($entry['genomes']) && $entry['genomes'] === false),
      'sections' => $entry['sections'],
      'formats' => $entry['formats']
    );
  }
  return $out;
}//api_data_types
?>
