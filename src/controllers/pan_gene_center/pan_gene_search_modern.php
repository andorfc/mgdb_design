<?PHP
/* file: pan_gene_search_modern.php
 *
 * purpose: Pan-Gene Center search page (/pan_gene_center/pan_gene) on the
 *          modern design system.
 *
 *          Included by controllers/pan_gene_center.php when PAGE is 'pan_gene'
 *          and no record id is supplied. Pan-gene *record* pages continue
 *          through the original controller untouched.
 *
 *          Every figure on the page is read live from the database. Results
 *          themselves are fetched by js/mgdb-pan-gene.js from
 *          search/pan_gene/pan_gene_search_api.php.
 *
 *          Pre-redesign files are archived in the redesign repository under
 *          legacy/pan_gene/.
 */

  include_once('./include/db-api.php');
  include_once('./include/pan_gene_lib.php');

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting pan_gene_search_modern.php');

  $DBConn = connect_to_database(false);

  $bauplan = new Bauplan('MaizeGDB Pan-Gene Search | Pan-Zea Pan-Genes and Gene Families');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-pan-gene.css');
  $bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-pan-gene.js');
  $bauplan->head('<meta name="description" content="Search maize pan-genes by locus, gene model, transcript, or protein. Filter by analysis membership, assembly coverage, traits, and size, and download exemplar sequence.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $content = $mgdb->get('body')->loadRemote($system['root_url_private'] . '/templates/static/mgdb_pan_gene.bau');

  /////
  // The analysis this page describes
  /////

  $metadata = getPanGeneAnalysisMetadata('', $DBConn);
  $annotations = isset($metadata['annotation_metadata']) ? $metadata['annotation_metadata'] : array();
  $analysis_meta = isset($metadata['analysis_metadata']) ? $metadata['analysis_metadata'] : array();
  $analysis_name = isset($analysis_meta['name']) ? $analysis_meta['name'] : '';

  usort($annotations, function($a, $b) {
    return strcasecmp($a['assembly'], $b['assembly']);
  });

  $content->get('analysis_name')->replace(htmlspecialchars($analysis_name, ENT_QUOTES, 'UTF-8'));
  $content->get('analysis_pipeline')->replace(htmlspecialchars(
    trim(($analysis_meta['program'] ?? '') . ' ' . ($analysis_meta['programversion'] ?? '')), ENT_QUOTES, 'UTF-8'));
  $content->get('analysis_date')->replace(htmlspecialchars(
    substr((string) ($analysis_meta['timeexecuted'] ?? ''), 0, 10), ENT_QUOTES, 'UTF-8'));

  /////
  // Summary figures
  /////

  $distribution = getPanGeneDistribution($analysis_name, 1000000, $DBConn);
  $pan_gene_count = 0;
  $placed_models = 0;
  $largest = 0;
  foreach ($distribution as $bin) {
    $pan_gene_count += (int) $bin['member_count'];
    $placed_models += ((int) $bin['pan_gene_size']) * ((int) $bin['member_count']);
    if ((int) $bin['pan_gene_size'] > $largest) {
      $largest = (int) $bin['pan_gene_size'];
    }
  }

  $annotation_count = (int) getPanGeneAnnotationCount($analysis_name, $DBConn);
  $assembly_count = count(array_unique(array_column($annotations, 'assembly')));

  $content->get('pan_gene_count')->replace(number_format($pan_gene_count));
  $content->get('placed_models')->replace(number_format($placed_models));
  $content->get('annotation_count')->replace(number_format($annotation_count));
  $content->get('assembly_count')->replace(number_format($assembly_count));

  // Currently one annotation per assembly; the analysis could include two
  // annotations of the same assembly, so both readings are worded.
  $content->get('annotation_coverage')->replace($annotation_count === $assembly_count
    ? number_format($annotation_count) . ' annotation sets, one from each assembly in the analysis'
    : number_format($annotation_count) . ' annotation sets from '
      . number_format($assembly_count) . ' assemblies');
  $content->get('largest_pan_gene')->replace(number_format($largest));

  /////
  // Search form option lists
  /////

  $analysis_options = '';
  $sth = make_query($DBConn, "
    SELECT DISTINCT pan_gene_analysis FROM chado.pan_gene_search ORDER BY pan_gene_analysis");
  while ($row = retrieve_row($sth)) {
    $value = htmlspecialchars($row['pan_gene_analysis'], ENT_QUOTES, 'UTF-8');
    $analysis_options .= '<option value="' . $value . '">' . $value . "</option>\n";
  }
  $content->get('analysis_options')->replace($analysis_options);

  // The value is the annotation; the label is the assembly it belongs to,
  // because that is what a reader recognizes.
  $annotation_options = '';
  foreach ($annotations as $row) {
    $annotation_options .= '<option value="'
      . htmlspecialchars($row['annotation'], ENT_QUOTES, 'UTF-8') . '">'
      . htmlspecialchars($row['assembly'], ENT_QUOTES, 'UTF-8')
      . ' &middot; ' . htmlspecialchars($row['annotation'], ENT_QUOTES, 'UTF-8')
      . "</option>\n";
  }
  $content->get('annotation_options')->replace($annotation_options);

  /////
  // Size distribution figure
  //
  // Passed as two plain comma-separated lists in data attributes: the .bau
  // parser treats '(', ')' and '\' specially, and digits and commas avoid the
  // question entirely.
  /////

  $chart_cutoff = 200;
  $sizes = array();
  $counts = array();
  $charted_pan_genes = 0;
  foreach ($distribution as $bin) {
    if ((int) $bin['pan_gene_size'] > $chart_cutoff) {
      continue;
    }
    $sizes[] = (int) $bin['pan_gene_size'];
    $counts[] = (int) $bin['member_count'];
    $charted_pan_genes += (int) $bin['member_count'];
  }

  $content->get('distribution_sizes')->replace(implode(',', $sizes));
  $content->get('distribution_counts')->replace(implode(',', $counts));
  $content->get('distribution_cutoff')->replace(number_format($chart_cutoff));

  // Saying "100.0% is shown" reads as though nothing was cut off. The count of
  // pan-genes above the cutoff is the honest figure.
  $above_cutoff = $pan_gene_count - $charted_pan_genes;
  $content->get('distribution_tail')->replace($above_cutoff === 0
    ? 'no pan-gene in the analysis is larger than that'
    : ($above_cutoff === 1
       ? 'one pan-gene is larger than that'
       : number_format($above_cutoff) . ' pan-genes are larger than that'));

  // The modal (most common) pan-gene size, for the figure's text interpretation.
  $mode_size = 0;
  $mode_count = 0;
  foreach ($distribution as $bin) {
    if ((int) $bin['member_count'] > $mode_count) {
      $mode_count = (int) $bin['member_count'];
      $mode_size = (int) $bin['pan_gene_size'];
    }
  }
  $content->get('distribution_mode_size')->replace(number_format($mode_size));
  $content->get('distribution_mode_count')->replace(number_format($mode_count));

  /////
  // Annotations included in the analysis
  /////

  $annotation_rows = '';
  foreach ($annotations as $row) {
    $assembly = htmlspecialchars($row['assembly'], ENT_QUOTES, 'UTF-8');
    $placed = (float) str_replace('%', '', (string) $row['perc_gene_models_placed']);
    $annotation_rows .= '<tr>'
      . '<th scope="row"><a href="/genome/assembly/' . rawurlencode($row['assembly']) . '">' . $assembly . '</a></th>'
      . '<td>' . htmlspecialchars($row['annotation'], ENT_QUOTES, 'UTF-8') . '</td>'
      . '<td class="mgdb-numeric" data-value="' . (int) $row['gene_model_count'] . '">'
        . number_format((int) $row['gene_model_count']) . '</td>'
      . '<td class="mgdb-numeric" data-value="' . (int) $row['min_gene_model_length'] . '">'
        . number_format((int) $row['min_gene_model_length']) . '</td>'
      . '<td class="mgdb-numeric" data-value="' . (int) $row['max_gene_model_length'] . '">'
        . number_format((int) $row['max_gene_model_length']) . '</td>'
      . '<td class="mgdb-numeric" data-value="' . (int) $row['ave_gene_model_length'] . '">'
        . number_format((int) $row['ave_gene_model_length']) . '</td>'
      . '<td class="mgdb-numeric" data-value="' . $placed . '">' . number_format($placed, 1) . '%</td>'
      . "</tr>\n";
  }
  $content->get('annotation_rows')->replace($annotation_rows);

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  return;
?>
