<?php
/* file: fowler_insertion_validation.php
 *
 * purpose: /projects/fowler_insertion_validation — PCR verification and
 *          transmission rates for 83 Ds-GFP insertion lines, from the Maize
 *          Gametophyte Project.
 *
 *          Included by controllers/projects.php once the slug has been matched
 *          against the registry in include/projects_lib.php.
 *
 * Ported from the legacy /documentation/fowler_insertion_validation on
 * 2026-09-05. Both old URLs reach the same controller through redirect.php, so
 * one 301 covers /fowler_insertion_validation as well.
 *
 * The 47 truncated primers
 * ------------------------
 * The legacy page carried the data as two Bauplan partials, fowler_TableA.bau
 * and fowler_TableB.bau, which were an Excel "save as HTML" export. Excel had
 * split 47 of the cells so that their last one to three characters sat inside
 * a <span style="display:none">, for example
 *
 *   <td>GCAGCTGCAGTTGTACACAGTACA<span style='display:none'>GAG</span></td>
 *
 * The browser rendered GCAGCTGCAGTTGTACACAGTACA and hid GAG, and text inside a
 * display:none element is not copied, so anyone reading a primer off that page
 * -- or copying one to order oligos -- got a sequence up to three bases short.
 * One expression class read `vegetative_cell_hig`. 46 of the 47 are in the two
 * primer columns.
 *
 * tools/fowler_lines.py parses both partials, keeps the hidden text as part of
 * the cell value, and writes data/projects/fowler_insertion_validation/
 * lines.json. Nothing on this page is hand-typed from the tables.
 *
 * The two tables became one
 * -------------------------
 * Table A was the 64 verified lines and Table B the 19 unverified, side by side
 * with identical columns. They are one table here with a Status column and a
 * chip to filter it, because the question a reader arrives with is about an
 * allele, not about which of two tables it is in. 64 + 19 = 83, which is the
 * count the prose has always claimed and which the payload asserts.
 */

  include_once('./include/references_lib.php');

  $project  = mgdb_project('fowler_insertion_validation');
  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
      ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

  $payload_rel  = 'lines.json';
  $payload_file = $doc_root . $project['data_url'] . '/' . $payload_rel;

  $data = null;
  if (is_file($payload_file)) {
      $data = json_decode(file_get_contents($payload_file), true);
  }
  if (!is_array($data) || empty($data['lines']) || empty($data['counts'])) {
      /* The table is the page. Returning here rather than publishing a shell
         with an empty table, the same call pathway_explorer.php makes. */
      reportError('fowler_insertion_validation.php: missing or unreadable payload ' . $payload_file);
      header('HTTP/1.1 503 Service Unavailable');
      echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
         . '<title>Fowler insertion verification | MaizeGDB</title></head><body>'
         . '<h1>This page is temporarily unavailable</h1>'
         . '<p>The data behind this page could not be read. '
         . '<a href="/projects">Browse all research projects</a>.</p></body></html>';
      return;
  }

  $counts = $data['counts'];

  /* The status chips, built from the payload so a chip cannot be offered that
     matches nothing, and cannot disagree with the metric card above it. */
  $status_labels = array(
      'verified'   => 'PCR verified',
      'unverified' => 'Not recovered',
  );
  $chips = '<button class="mgdb-chip" type="button" data-filter="all" aria-pressed="true">All '
         . '<span class="projects-chip-count">' . (int) $counts['total'] . '</span></button>';
  foreach ($status_labels as $key => $label) {
      $n = isset($counts[$key]) ? (int) $counts[$key] : 0;
      if ($n === 0) { continue; }
      $chips .= '<button class="mgdb-chip" type="button" data-filter="' . mgdb_project_esc($key) . '"'
              . ' aria-pressed="false">' . mgdb_project_esc($label)
              . ' <span class="projects-chip-count">' . $n . '</span></button>';
  }

  /* Rows are rendered here, server-side, so the whole table is in the HTML and
     is indexable, printable and readable with scripting off. The page script
     only hides and shows rows that are already there -- the same split every
     other filtered list on the site uses. */
  $rows_html = '';
  foreach ($data['lines'] as $line) {
      $status = isset($line['status']) ? $line['status'] : '';
      $label  = isset($status_labels[$status]) ? $status_labels[$status] : $status;
      $pill   = $status === 'verified' ? 'mgdb-pill-ok' : 'mgdb-pill-warn';

      /* A rate that differs significantly from 50% carried a bare "**" in the
         legacy table, explained only in the column heading. It is marked in
         words here, and the word is what the filter and a screen reader read. */
      $rate = '<span class="mgdb-muted fw-rate-nd">Not determined</span>';
      if (isset($line['rate']) && $line['rate'] !== null) {
          $rate = '<span class="fw-rate' . (!empty($line['significant']) ? ' fw-rate-flagged' : '') . '">'
                . mgdb_project_esc($line['rate_raw'])
                . (!empty($line['significant'])
                    ? ' <abbr title="Significantly different from 50%">&dagger;</abbr>'
                    : '')
                . '</span>';
      }

      /* The searchable text is built once here rather than read out of six
         cells per keystroke in the browser. */
      $search = trim($line['allele'] . ' ' . $line['gene_v4'] . ' ' . $line['gene_v3'] . ' '
                   . $line['expression'] . ' ' . $line['primer1'] . ' ' . $line['primer2'] . ' ' . $label);

      $rows_html .=
          '<tr data-filter="' . mgdb_project_esc($status) . '"'
        . ' data-search="' . mgdb_project_esc($search) . '">'
        . '<td class="fw-allele">' . mgdb_project_esc($line['allele']) . '</td>'
        . '<td><span class="mgdb-pill ' . $pill . '">' . mgdb_project_esc($label) . '</span></td>'
        /* MGDB.sortTable reads data-value before textContent. The cell's text
           is "48.4% <dagger>" or "Not determined", and the dagger would not
           survive a numeric parse cleanly; the bare number is what sorts. A
           line with no measurement gets an empty value, which the helper sorts
           last in both directions rather than treating as zero. */
        . '<td data-value="' . mgdb_project_esc($line['rate'] === null ? '' : $line['rate']) . '">' . $rate . '</td>'
        . '<td class="fw-gene">' . mgdb_project_esc($line['gene_v4']) . '</td>'
        . '<td class="fw-gene">' . mgdb_project_esc($line['gene_v3']) . '</td>'
        . '<td>' . mgdb_project_esc($line['expression']) . '</td>'
        . '<td class="fw-primer">' . mgdb_project_esc($line['primer1']) . '</td>'
        . '<td class="fw-primer">' . mgdb_project_esc($line['primer2']) . '</td>'
        . '</tr>';
  }

  $bauplan = new Bauplan('Maize Gametophyte Project validated Ds-GFP insertions | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  $bauplan->includeCss('/css/mgdb-hub.css');
  $bauplan->includeCss('/css/mgdb-projects.css');
  $bauplan->includeCss('/css/mgdb-project-resources.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-project-resources.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-project-resources.js');
  $bauplan->head('<meta name="description" content="' . mgdb_project_esc($project['description']) . '">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load(ltrim($project['template'], '/'));

  $body->get('total-count')->replace(number_format((int) $counts['total']));
  $body->get('verified-count')->replace(number_format((int) $counts['verified']));
  $body->get('unverified-count')->replace(number_format((int) $counts['unverified']));
  $body->get('defect-count')->replace(number_format((int) $counts['transmission_defect']));
  $body->get('status-chips')->replace($chips);
  $body->get('line-rows')->replace($rows_html);
  $body->get('data-json')->replace(mgdb_project_esc(mgdb_project_asset_url($project, $payload_rel)));
  $body->get('data-tsv')->replace(mgdb_project_esc(mgdb_project_asset_url($project, 'fowler_ds_gfp_lines.tsv')));

  /* Verified at Crossref and Europe PMC on 2026-09-05, and one of them is a
     correction: the legacy page cited the 2020 bioRxiv preprint of the ear
     phenotyping paper, which was published in The Plant Journal in 2021 as
     10.1111/tpj.15166. The published version is what is cited here. */
  $body->get('reference_cards')->replace(mgdb_render_references($doc_root, array(
      array(
          'doi'      => '10.1371/journal.pgen.1008462',
          'fallback' => array(
              'title'    => 'High expression in maize pollen correlates with genetic contributions to pollen fitness as well as with coordinated transcription from neighboring transposable elements',
              'authors'  => 'Warman C, Panda K, Vejlupkova Z, Hokin S, Unger-Wallace E, Cole RA, Chettoor AM, Jiang D, Vollbrecht E, Evans MMS, Slotkin RK, Fowler JE',
              'journal'  => 'PLOS Genetics',
              'year'     => 2020,
              'volume'   => '16',
              'pages'    => 'e1008462',
              'pubmed'   => '32236090',
              'abstract' => 'In flowering plants, gene expression in the haploid male gametophyte (pollen) is essential for sperm delivery and double fertilization. Pollen also undergoes dynamic epigenetic regulation of expression from transposable elements (TEs), but how this process interacts with gene expression is not clearly understood. To explore relationships among these processes, we quantified transcript levels in four male reproductive stages of maize (tassel primordia, microspores, mature pollen, and sperm cells) via RNA-seq. We found that, in contrast with vegetative cell-limited TE expression in Arabidopsis pollen, TE transcripts in maize accumulate as early as the microspore stage and are also present in sperm cells. Intriguingly, coordinate expression was observed between highly expressed protein-coding genes and their neighboring TEs, specifically in mature pollen and sperm cells. To investigate a potential relationship between elevated gene transcript level and pollen function, we measured the fitness cost (male-specific transmission defect) of GFP-tagged coding sequence insertion mutations in over 50 genes identified as highly expressed in the pollen vegetative cell, sperm cell, or seedling (as a sporophytic control). Insertions in seedling genes or sperm cell genes (with one exception) exhibited no difference from the expected 1:1 transmission ratio. In contrast, insertions in over 20% of vegetative cell genes were associated with significant reductions in fitness, showing a positive correlation of transcript level with non-Mendelian segregation when mutant. Insertions in maize gamete expressed2 (Zm gex2), the sole sperm cell gene with measured contributions to fitness, also triggered seed defects when crossed as a male, indicating a conserved role in double fertilization, given the similar phenotype previously demonstrated for the Arabidopsis ortholog GEX2. Overall, our study demonstrates a developmentally programmed and coordinated transcriptional activation of TEs and genes in pollen, and further identifies maize pollen as a model in which transcriptomic data have predictive value for quantitative phenotypes.',
          ),
      ),
      array(
          'doi'      => '10.1111/tpj.15166',
          'fallback' => array(
              'title'    => 'A cost-effective maize ear phenotyping platform enables rapid categorization and quantification of kernels',
              'authors'  => 'Warman C, Sullivan CM, Preece J, Buchanan ME, Vejlupkova Z, Jaiswal P, Fowler JE',
              'journal'  => 'The Plant Journal',
              'year'     => 2021,
              'volume'   => '106',
              'pages'    => '566-579',
              'pubmed'   => '33476427',
              'abstract' => 'High-throughput phenotyping systems are powerful, dramatically changing our ability to document, measure, and detect biological phenomena. Here, we describe a cost-effective combination of a custom-built imaging platform and deep-learning-based computer vision pipeline. A minimal version of the maize (Zea mays) ear scanner was built with low-cost and readily available parts. The scanner rotates a maize ear while a digital camera captures a video of the surface of the ear, which is then digitally flattened into a two-dimensional projection. Segregating GFP and anthocyanin kernel phenotypes are clearly distinguishable in ear projections and can be manually annotated and analyzed using image analysis software. Increased throughput was attained by designing and implementing an automated kernel counting system using transfer learning and a deep learning object detection model. The computer vision model was able to rapidly assess over 390 000 kernels, identifying male-specific transmission defects across a wide range of GFP-marked mutant alleles. This includes a previously undescribed defect putatively associated with mutation of Zm00001d002824, a gene predicted to encode a vacuolar processing enzyme. Thus, by using this system, the quantification of transmission data and other ear and kernel phenotypes can be accelerated and scaled to generate large datasets for robust analyses.',
          ),
      ),
      array(
          'doi'      => '10.1007/978-1-62703-568-2_6',
          'kind'     => 'Book chapter',
          'fallback' => array(
              'title'    => 'Gene tagging with engineered Ds elements in maize',
              'authors'  => 'Li Y, Segal G, Wang Q, Dooner HK',
              'journal'  => 'Methods in Molecular Biology',
              'year'     => 2013,
              'volume'   => '1057',
              'pages'    => '83-99',
              'pubmed'   => '23918422',
              'abstract' => 'We describe here protocols for isolating genes in maize using Dissociation (Ds) transposons marked with a green fluorescent protein (GFP) transgene. The introduced marker enables the phenotypic scoring of the nonautonomous element and the anchoring of unique primers on the element to facilitate the isolation of the adjacent DNA by PCR. Transposons such as Ds transpose preferentially to sites closely linked to the Ds-launching platform. Based on this transposition behavior, a genetic resource is being created to mobilize a modified Ds element from different starting sites in the genome. Enough transgenic lines are being generated to cover most of the maize genome, allowing the targeted tagging of most genes from a Ds-launching platform located nearby.',
          ),
      ),
  )));

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
