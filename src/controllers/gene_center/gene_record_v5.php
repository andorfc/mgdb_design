<?PHP
/* file: gene_record_v5.php
 *
 * purpose: The gene record page (/gene_center/gene/{id}). Was the
 *          /gene_center/gene_v5 mockup until 2026-09-17, when it replaced the
 *          previous record controller (gene_record_modern.php, kept on disk as
 *          the rollback). Originally: the v4
 *          header with the page's navigation under it.
 *
 *          Included by controllers/gene_center.php when PAGE is 'gene'
 *          and a record identifier is present. Returns false without
 *          publishing if the identifier does not resolve, so the caller falls
 *          through to its own not-found handling.
 *
 *          Three pieces, each taken from a page that already exists:
 *
 *            the header   geneHeaderPanel(), which is the v4 header itself
 *                         rather than a copy of it -- v5's <main> also carries
 *                         .mgdb-gene-record-v4-page, so it is styled by the
 *                         same stylesheet;
 *            the views    the four tabs of /gene_center/gene_openai -- a title,
 *                         a line of what is in it, and a glyph -- sticky at the
 *                         top of the page;
 *            the sections the bubble bar of /gene_center/gene_v2, one per view.
 *
 *          The section lists are v2's own ORDER and LABELS from
 *          js/mgdb-gene-record-v2.js, not a fresh guess at what belongs where.
 *
 *          The body is not built yet. Each section is a placeholder panel
 *          carrying its real id and heading, which is what gives the page
 *          enough height for the sticky bars, the bubble jumps and the
 *          scrollspy to be worth looking at at all.
 */

  include_once('./include/db-api.php');
  include_once('./include/gene_record_lib.php');
  include_once('./include/gene_header_panel.php');

  $system = getSystemInfo('mgdb.conf');
  $DBConn = connect_to_database(false);
  if (!$DBConn) {
    return false;
  }

  $gene_request = rawurldecode((string) getCGIParam('id', 'G', ID));
  /* An identifier that resolves to nothing gets the record 404 -- HTTP 404
     with suggestions -- and this returns true so gene_center.php does not fall
     through to the legacy handler, which answers the same miss with a soft
     200. As a mockup this returned false; as the live page it must not. */
  $gene_resolved = geneResolveId($DBConn, $gene_request);
  if ($gene_resolved === false) {
    geneRecordNotFound($DBConn, $system, $gene_request);
    return true;
  }
  $gene_identity = geneIdentity($DBConn, $gene_resolved);
  if (!$gene_identity) {
    geneRecordNotFound($DBConn, $system, $gene_request);
    return true;
  }
  logMessage('Starting gene_record_v5.php for ' . $gene_identity['name']);

  $esc = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

  $panel = geneHeaderPanel($DBConn, $gene_identity, $gene_resolved, '/gene_center/gene/');

  /* ---- The four views ------------------------------------------------------
     Titles and the line under each are the ones asked for. The glyphs are the
     four drawn for the openai mockup, which were drawn for these same four
     views.

     The section lists are the curated ones, not a mechanical copy of v2's
     ORDER. Two things follow from them and are worth saying out loud, because
     both look like omissions and are not:

       Overview appears in Gene model and in Genetic information only. The
       visual view opens on Structure; the overview facts are the header.

       Related resources, Metrics and API appear once, at the foot of the
       visual view, rather than repeated at the foot of all four.

     Section ids: the visual view keeps the version-1 gene-record-* ids; the
     other three carry gm-, pg- and gn- prefixes so that more than one view can
     have a section called "Overview" without two elements claiming one id.

     /new_genes builds about 3,300 links to #gene-record-overview, but they
     point at /gene_center/gene, which still has that section. If this layout
     ever becomes the live record page, that anchor has to go somewhere. */
  $views = array(
    'visual' => array(
      'label' => 'Visual overview',
      'hint' => 'Annotations, evidence, and interactive figures',
      'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 18h18M5 15l4-5 4 3 6-8M5 15v-3m4-2V6m4 7V8m6-3v10"/></svg>',
      'sections' => array(
        array('gene-record-structure', 'Gene and Protein Structure'),
        array('gene-record-function', 'Function'),
        array('gene-record-expression', 'Expression'),
        array('gene-record-variation', 'Variation'),
        array('gene-record-orthologs', 'Orthologs'),
        array('gene-record-paralogs', 'Homeologs and tandem arrays'),
        array('gene-record-provenance', 'Gene model scores'),
        array('gene-record-images', 'Mutant Phenotype Images'),
        array('gene-record-references', 'References'),
        array('gene-record-metrics', 'Metrics'),
        array('gene-record-resources', 'Related resources'),
        array('gene-record-api', 'API')
      )
    ),
    'gene_model' => array(
      'label' => 'Gene model',
      'hint' => 'Tables, lists, and downloads',
      'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16M4 12h16M4 19h16M7 3v4m5 3v4m5 3v4"/></svg>',
      'sections' => array(
        array('gm-overview', 'Overview'),
        array('gm-annotations', 'Annotations and scores'),
        array('gm-insertions', 'Insertions'),
        array('gm-expression', 'Expression'),
        array('gm-snps', 'SNPs and traits'),
        array('gm-proteomics', 'Proteomics'),
        array('gm-sequences', 'Sequences')
      )
    ),
    'pan_gene' => array(
      'label' => 'Pan-gene',
      'hint' => 'Presence, members, and orthologs',
      'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><circle cx="5" cy="6" r="2"/><circle cx="19" cy="6" r="2"/><circle cx="5" cy="18" r="2"/><circle cx="19" cy="18" r="2"/><path d="M7 7.5l3 2.7m7-2.7-3 2.7m-7 6.3 3-2.7m7 2.7-3-2.7"/></svg>',
      'sections' => array(
        array('pg-members', 'Related gene models'),
        array('pg-datasets', 'Member datasets'),
        array('pg-orthologs', 'Orthologs')
      )
    ),
    'genetic' => array(
      'label' => 'Genetic information',
      'hint' => 'Loci, alleles, maps, and stocks',
      'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3c7 4 3 14 10 18M17 3C10 7 14 17 7 21M8.5 7h7m-8 5h9m-8 5h7"/></svg>',
      'sections' => array(
        array('gn-overview', 'Overview'),
        array('gn-annotations', 'Annotations'),
        array('gn-references', 'References'),
        array('gn-alleles', 'Alleles'),
        array('gn-stocks', 'Stocks'),
        array('gn-map', 'Map coordinates'),
        array('gn-nearby', 'Nearby loci'),
        array('gn-genetic', 'Additional genetic information'),
        array('gn-external', 'External links')
      )
    )
  );

  /* ---- The navigation ------------------------------------------------------
     One sticky block: the four view tabs, and under them the bubble bar of
     whichever view is open. One block rather than v2's two stacked sticky
     elements, because two need their heights measured against each other and
     one does not.

     Every tab and every bar is in the markup; the script shows one pair. With
     scripting off the first view is the one that is open, and the other three
     tabs are marked disabled rather than left looking clickable. */
  /* Two sections are markup, not data: the same links and the same endpoint on
     every gene record. The server writes them so the page script has nothing to
     do for them and they are present with scripting off. */
  $api_path = $esc(rawurlencode($gene_request));
  $section_body = array(
    'gene-record-resources' =>
      '<div class="mgdb-resource-panel"><div class="mgdb-resource-list">'
      . '<a href="/gene_center/gene"><strong>Gene and Locus Data Hub</strong><span>Find gene models and classical genes by name, function, or position</span></a>'
      . '<a href="/pan_gene_center/pan_gene"><strong>Pan-Gene Data Hub</strong><span>The same gene across every maize assembly, and its orthologs</span></a>'
      . '<a href="/data_center/variation"><strong>Variation Data Hub</strong><span>Alleles, insertions, and the phenotypes they produce</span></a>'
      . '<a href="https://jbrowse.maizegdb.org/" target="_blank" rel="noopener"><strong>Genome browser</strong><span>See this region and its neighbors in JBrowse</span></a>'
      . '<a href="https://wgs.maizegdb.org/" target="_blank" rel="noopener"><strong>SNPversity 2.0</strong><span>Genotype data for this gene across the diversity panels</span></a>'
      . '</div></div>',
    'gene-record-api' =>
      '<div class="mgdb-rec-api-row">'
      . '<code id="gene-record-api-endpoint">GET /api/v1/records/gene/' . $esc($gene_request) . '</code>'
      . '<div class="mgdb-rec-api-actions">'
      . '<a class="mgdb-button mgdb-button-quiet" id="gene-record-api-link" href="/api/v1/records/gene/' . $api_path . '" target="_blank" rel="noopener">Raw JSON</a>'
      . '<a class="mgdb-button mgdb-button-quiet" href="/api/v1/records/gene/' . $api_path . '?format=jsonld" target="_blank" rel="noopener">JSON-LD</a>'
      . '<button class="mgdb-button mgdb-button-quiet" id="gene-copy-json-btn" type="button">Copy JSON</button>'
      . '<a class="mgdb-button mgdb-button-quiet" href="/api">API documentation</a>'
      . '</div></div>'
      . '<p class="mgdb-rec-api-note">Every view on this page is drawn from one structured JSON response; the Pan-gene view adds one request, made only when it is opened.</p>'
  );

  /* Two sections need more than the generic shell.

     Metrics is the one section whose body IS a layout: the cards are a grid,
     the way they are on every Data Hub, and without .mgdb-metric-grid they
     stack one per row down the left. And the connections chart is markup the
     renderer fills rather than markup it creates, so if it is not here
     R.connectionsChart() has nothing to draw into and the chart is silently
     absent. */
  $section_body_class = array(
    'gene-record-metrics' => 'mgdb-metric-grid'
  );
  $section_after = array(
    'gene-record-metrics' =>
      '<div class="mgdb-chart-grid mgdb-rec-charts" id="gene-record-charts">'
      . '<figure class="mgdb-figure" id="gene-record-connections-figure">'
      . '<h3>Record connections</h3>'
      . '<div class="mgdb-chart" id="gene-record-connections-chart" role="img"'
      . ' aria-label="Horizontal bar chart of how many transcripts, ontology terms, insertions and other records are attached to this gene">'
      . '<div class="mgdb-chart-fallback">Loading the record connections&hellip;</div></div>'
      . '<figcaption id="gene-record-connections-caption"></figcaption>'
      . '</figure></div>'
  );

  $tabs = '';
  $bars = '';
  $panels = '';
  $first = true;
  foreach ($views as $key => $view) {
    $tabs .= '<button type="button" role="tab" id="v5-tab-' . $key . '"'
           . ' aria-selected="' . ($first ? 'true' : 'false') . '"'
           . ' aria-controls="v5-panels-' . $key . '"'
           . ' data-v5-view="' . $key . '"' . ($first ? '' : ' tabindex="-1"') . '>'
           . $view['icon']
           . '<span><strong>' . $esc($view['label']) . '</strong>'
           . '<small>' . $esc($view['hint']) . '</small></span></button>';

    $links = '';
    $stubs = '';
    $section_first = true;
    foreach ($view['sections'] as $section) {
      list($id, $label) = $section;
      /* The count rides in the bubble and is filled by the page script from
         meta.counts; empty until then, and marked .is-empty when the section
         renders nothing, so the reader can see what is there before clicking. */
      $links .= '<a href="#' . $esc($id) . '"' . ($section_first ? ' class="is-current" aria-current="true"' : '') . '>'
              . $esc($label) . '<span class="mgdb-tab-count" hidden></span></a>';
      $stubs .= '<section id="' . $esc($id) . '" class="v5-section" aria-labelledby="' . $esc($id) . '-title"'
              . ' data-section-label="' . $esc($label) . '">'
              . '<div class="mgdb-section-heading"><div><h2 id="' . $esc($id) . '-title">' . $esc($label) . '</h2></div></div>'
              . '<div' . (isset($section_body_class[$id]) ? ' class="' . $esc($section_body_class[$id]) . '"' : '')
              . ' id="' . $esc($id) . '-body">'
              . (isset($section_body[$id]) ? $section_body[$id] : '') . '</div>'
              . (isset($section_after[$id]) ? $section_after[$id] : '')
              . '</section>';
      $section_first = false;
    }

    $bars .= '<nav class="mgdb-section-tabs mgdb-rec-tabs v5-tabs" id="v5-bar-' . $key . '"'
           . ' data-v5-bar="' . $key . '" aria-label="Sections of the ' . $esc(strtolower($view['label'])) . ' view"'
           . ($first ? '' : ' hidden') . '>' . $links . '</nav>';

    $panels .= '<div class="v5-panels" id="v5-panels-' . $key . '" role="tabpanel"'
             . ' aria-labelledby="v5-tab-' . $key . '" data-v5-panels="' . $key . '"'
             . ($first ? '' : ' hidden') . '>' . $stubs . '</div>';

    $first = false;
  }

  /* ---- Publish -------------------------------------------------------------- */
  $bauplan = new Bauplan($panel['gene_title']);
  $bauplan->modern();

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $v = function ($path) use ($doc_root) {
    return file_exists($doc_root . $path) ? filemtime($doc_root . $path) : time();
  };

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . $v('/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-record.css?v=' . $v('/css/mgdb-record.css'));
  $bauplan->includeCss('/css/mgdb-gene-record.css?v=' . $v('/css/mgdb-gene-record.css'));
  /* The header's own stylesheet, loaded unchanged. v5's <main> carries
     .mgdb-gene-record-v4-page as well as its own class, so the panel is not
     restyled here -- it is the same panel. */
  $bauplan->includeCss('/css/mgdb-gene-record-v4.css?v=' . $v('/css/mgdb-gene-record-v4.css'));
  $bauplan->includeCss('/css/mgdb-gene-structure.css?v=' . $v('/css/mgdb-gene-structure.css'));
  $bauplan->includeCss('/css/mgdb-gene-expression.css?v=' . $v('/css/mgdb-gene-expression.css'));
  $bauplan->includeCss('/css/mgdb-gene-function.css?v=' . $v('/css/mgdb-gene-function.css'));
  $bauplan->includeCss('/css/mgdb-gene-paralogs.css?v=' . $v('/css/mgdb-gene-paralogs.css'));
  $bauplan->includeCss('/css/mgdb-gene-record-v2.css?v=' . $v('/css/mgdb-gene-record-v2.css'));
  $bauplan->includeCss('/css/mgdb-gene-record-v5.css?v=' . $v('/css/mgdb-gene-record-v5.css'));
  $bauplan->includeScript('https://cdn.plot.ly/plotly-2.35.2.min.js');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-record.js?v=' . $v('/js/mgdb-record.js'));
  $bauplan->includeScript('/js/mgdb-gene-structure.js?v=' . $v('/js/mgdb-gene-structure.js'));
  $bauplan->includeScript('/js/mgdb-gene-expression.js?v=' . $v('/js/mgdb-gene-expression.js'));
  $bauplan->includeScript('/js/mgdb-gene-function.js?v=' . $v('/js/mgdb-gene-function.js'));
  $bauplan->includeScript('/js/mgdb-gene-paralogs.js?v=' . $v('/js/mgdb-gene-paralogs.js'));
  $bauplan->includeScript('/js/mgdb-gene-record-v5.js?v=' . $v('/js/mgdb-gene-record-v5.js'));
  $bauplan->head('<meta name="description" content="' . $esc($panel['gene_summary']) . '">');
  /* No robots meta. As a mockup this page was noindex; as the live gene record
     -- the site's second most requested URL -- it has to be found. */
  $bauplan->head('<link rel="canonical" href="' . $esc($system['root_url']) . '/gene_center/gene/' . $esc(rawurlencode($gene_request)) . '">');

  /* Machine-readable identity, carried over from the previous record
     controller: a JSON-LD block in the head, link elements to the JSON and
     JSON-LD records, and the same two as an HTTP Link header (FAIR
     Signposting). See /api#api-linked-data. */
  $jsonld_id = ($panel['gene_name'] !== '') ? $panel['gene_name'] : $gene_request;
  include_once('./include/api/v1/lib/mgdb_jsonld.php');
  $bauplan->head(MgdbJsonLd::headMarkup('gene', $jsonld_id, array(
    'name' => $panel['gene_display'], 'description' => $panel['gene_summary'],
    'attributes' => array('name' => $gene_identity['name'], 'symbol' => $gene_identity['symbol'],
                          'full_name' => $gene_identity['full_name'], 'assembly' => $gene_identity['assembly'],
                          'annotation' => $gene_identity['annotation'], 'kind' => $gene_identity['kind']))));
  MgdbJsonLd::signpost('gene', $jsonld_id);

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->load('templates/static/mgdb_gene_record_v5.bau');
  $content->get('gene_title')->replace($esc($panel['gene_display']));
  $content->get('gene_summary')->replace($esc($panel['gene_summary']));
  $content->get('requested_identifier')->replace($esc($gene_request));
  /* No requested_identifier_path: its only use was the mockup's footer note,
     which went when this became the live page, and Bauplan's get() throws on
     an identifier no loaded template declares. */
  $content->get('gene_api_id')->replace($esc($panel['gene_name'] !== '' ? $panel['gene_name'] : $gene_request));
  $content->get('gene_state')->replace($gene_identity['kind'] === 'withdrawn' ? 'withdrawn' : 'current');
  $content->get('status_notice')->replace($panel['status_notice']);
  $content->get('locus_side')->replace($panel['locus_side']);
  $content->get('model_side')->replace($panel['model_side']);
  $content->get('view_tabs')->replace($tabs);
  $content->get('view_bars')->replace($bars);
  $content->get('view_panels')->replace($panels);

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);

  $bauplan->publish();
  return true;
?>
