<?php
/* file: uniformmu.php
 *
 * purpose: /uniformmu — the UniformMu transposon insertion resource.
 *
 *          Loaded by controller.php, which checks controllers/<CONTROLLER>.php
 *          before falling through to redirect.php. That is what takes this
 *          route from controllers/documentation/uniformmu.php without touching
 *          it; the original controller, its template, its stylesheet and its
 *          script are archived in the redesign repository under
 *          legacy/uniformmu/.
 *
 *          Rollback is deleting this file: the original is still on disk and
 *          redirect.php finds it again immediately.
 *
 * What changed, and why
 * ---------------------
 * The page it replaces was a 557-line static document written in 2012 and last
 * revised for the March 2011 data release. It described a collection of 26,211
 * insertions in 5,127 seed stocks. The collection now holds 77,990 insertion
 * records and 10,525 seed stocks, every one of which is a record page on this
 * site — and the page said none of that, because its numbers were typed into
 * the prose by hand and there was nothing to update them.
 *
 * So this page separates the two kinds of content it carries:
 *
 *   measured   Every count comes from data/uniformmu/uniformmu_summary.json,
 *              written by tools/uniformmu_summary.php against the production
 *              database. Re-running that tool updates the page. The file's
 *              modification time is what the page reports as its data date, so
 *              it cannot claim to be fresher than its data.
 *
 *   documented The methods, the genetics, the seed handling and the PCR
 *              protocol are the authors' text and do not change with a data
 *              release. They are carried over intact, restructured but not
 *              rewritten, along with the six figures and three tables the
 *              original linked.
 *
 * And it adds the thing the document only described in prose: a lookup that
 * goes from a gene, an insertion, a stock, or a genomic window to the actual
 * MaizeGDB records, in one step. That is live, and it is the only part of the
 * page that queries the database. See search/uniformmu/uniformmu_search_api.php.
 *
 * Query cost
 * ----------
 * Rendering this page runs zero SQL. The lookups cost between one and ten
 * indexed queries and answer in 20-40 ms; the region lookup is the exception at
 * about 130 ms, and the reason is recorded in ADMIN_DEPENDENCIES.
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting controllers/uniformmu.php');

/* -------------------------------------------------------------------------- *
 * The measured payload
 * -------------------------------------------------------------------------- */

  $um_payload_rel  = '/data/uniformmu/uniformmu_summary.json';
  $um_payload_file = $system['root_dir'] . $um_payload_rel;
  if (!is_file($um_payload_file) && isset($_SERVER['DOCUMENT_ROOT'])) {
      $um_payload_file = $_SERVER['DOCUMENT_ROOT'] . $um_payload_rel;
  }

  $um_data = null;
  if (is_file($um_payload_file)) {
      $um_data = json_decode(file_get_contents($um_payload_file), true);
  }
  /* Absent or malformed, the page still renders: the documentation, the
     downloads, the browsers and the lookup are all independent of it. Only the
     counts go, and they go visibly rather than as zeros. A zero here would be a
     claim about the collection, and it would be false. */
  $um_have_data = is_array($um_data) && isset($um_data['totals'], $um_data['assemblies']);
  if (!$um_have_data) {
      reportError('uniformmu.php: missing or unreadable payload ' . $um_payload_file);
      $um_data = array('totals' => array(), 'assemblies' => array(),
                       'per_gene' => array('buckets' => array()), 'per_stock' => array());
  }

  $um_totals     = isset($um_data['totals']) ? $um_data['totals'] : array();
  $um_assemblies = isset($um_data['assemblies']) ? $um_data['assemblies'] : array();
  $um_per_stock  = isset($um_data['per_stock']) ? $um_data['per_stock'] : array();
  $um_buckets    = isset($um_data['per_gene']['buckets']) ? $um_data['per_gene']['buckets'] : array();

/* -------------------------------------------------------------------------- *
 * Helpers
 * -------------------------------------------------------------------------- */

  function um_esc($value) {
      return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
  }

  function um_num($value) {
      return ($value === null || $value === '') ? '&mdash;' : number_format((float) $value);
  }

  function um_total($key, $default = null) {
      global $um_totals;
      return isset($um_totals[$key]) ? $um_totals[$key] : $default;
  }

  /* A percentage is printed to one decimal place and never rounded to a whole
     number: 48.0% and 48% read differently when the next release moves it. */
  function um_pct($fraction) {
      return $fraction === null ? '&mdash;' : number_format($fraction * 100, 1) . '%';
  }

  /* The assembly a reader should be shown first, and the one every headline
     number is quoted against. Falls back to the first assembly present rather
     than to nothing, so a future rename does not empty the page. */
  function um_reference_assembly($assemblies) {
      foreach ($assemblies as $assembly) {
          if ($assembly['name'] === 'Zm-B73-REFERENCE-NAM-5.0') { return $assembly; }
      }
      return count($assemblies) ? $assemblies[0] : null;
  }

  $um_reference = um_reference_assembly($um_assemblies);

/* -------------------------------------------------------------------------- *
 * Static content: the things that are not measurements
 *
 * Each list is here rather than in the template because the template should not
 * carry data, and because a broken link is easier to find in one array than in
 * 400 lines of markup.
 * -------------------------------------------------------------------------- */

  /* Downloads, carried over from the page this replaces.

     One entry per actual file, each stating its release and the assembly its
     coordinates are against. The old page grouped them under headings that did
     not always say which was which and hid the release inside the filename, so
     a reader who took the first spreadsheet in a group got v2 coordinates. The
     assembly is the thing that most often goes wrong, so it is a column rather
     than a parenthesis. */
  $um_downloads = array(
      array(
          'group' => 'Current release',
          'note'  => 'Release 9. The v5 and W22 alignments this page reports are in the database but are not yet published as a file.',
          'files' => array(
              array('label' => 'Insertion coordinates',
                    'detail' => 'Every insertion with its genomic position.',
                    'release' => 'Release 9', 'assembly' => 'B73 RefGen_v4', 'format' => 'XLSX',
                    'url' => 'https://download.maizegdb.org/Insertions/UniformMu/UniformMu_Release-9_B73v4.xlsx'),
              array('label' => 'Insertion coordinates with gene structure',
                    'detail' => 'The same insertions, annotated with the part of the gene each one falls in.',
                    'release' => 'Release 9', 'assembly' => 'B73 RefGen_v4', 'format' => 'XLSX',
                    'url' => 'https://download.maizegdb.org/Insertions/UniformMu/UniformMu_Release-9_B73v4_exons.xlsx'),
              array('label' => 'W22 to B73 cross-reference',
                    'detail' => 'Maps insertion positions between the W22 background and the B73 reference.',
                    'release' => 'Release 9', 'assembly' => 'W22 to B73 v4', 'format' => 'XLSX',
                    'url' => 'https://download.maizegdb.org/Insertions/UniformMu/UniformMu_W22_V4_lookup.xlsx'),
          )
      ),
      array(
          'group' => 'Earlier releases',
          'note'  => 'Kept because published work cites them. Coordinates in these files are against older assemblies and are not comparable with the current release or with each other.',
          'files' => array(
              array('label' => 'Gene models carrying an insertion',
                    'detail' => 'The filtered gene set, one row per gene model hit.',
                    'release' => 'Release 8', 'assembly' => 'B73 RefGen_v3', 'format' => 'XLSX',
                    'url' => 'https://download.maizegdb.org/Insertions/UniformMu/UniformMu_Release-8_GeneModel_B73_RefGen_v3.xlsx'),
              array('label' => 'Insertions in the filtered gene set, including 100 bp flanks',
                    'detail' => 'Insertions in or within 100 bp of a filtered-gene-set model.',
                    'release' => 'Release 8', 'assembly' => 'B73 RefGen_v2', 'format' => 'XLSX',
                    'url' => 'https://download.maizegdb.org/Insertions/UniformMu/UniformMu_Release-8_Insertions_in_RefGenv2_FGS.xlsx'),
              array('label' => 'Insertions in the filtered gene set, including 100 bp flanks',
                    'detail' => 'The Release 7 version of the same file.',
                    'release' => 'Release 7', 'assembly' => 'B73 RefGen_v2', 'format' => 'XLSX',
                    'url' => 'https://download.maizegdb.org/Insertions/UniformMu/UniformMu_Release-7_Insertions_in_RefGenv2_FGS.xlsx'),
              array('label' => 'Insertions in exons',
                    'detail' => 'Restricted to insertions inside a coding exon.',
                    'release' => 'Release 8', 'assembly' => 'B73 RefGen_v2', 'format' => 'XLSX',
                    'url' => 'https://download.maizegdb.org/Insertions/UniformMu/UniformMu_Release-8_Insertions_in_RefGenv2_FGS_exons.xlsx'),
              array('label' => 'Insertions in exons',
                    'detail' => 'The Release 7 version of the same file.',
                    'release' => 'Release 7', 'assembly' => 'B73 RefGen_v2', 'format' => 'XLSX',
                    'url' => 'https://download.maizegdb.org/Insertions/UniformMu/UniformMu_Release-7_Insertions_in_RefGenv2_FGS_exons.xlsx'),
          )
      ),
      array(
          'group' => 'Sequences and documentation',
          'note'  => 'The flanking sequences and the methods document behind this page.',
          'files' => array(
              array('label' => 'All UniformMu downloads',
                    'detail' => 'The full directory, including flanking sequence for B73 and W22.',
                    'release' => 'All', 'assembly' => null, 'format' => 'Directory',
                    'url' => 'https://download.maizegdb.org/Insertions/UniformMu/'),
              array('label' => 'UniformMu methods',
                    'detail' => 'The 2011 methods document, as published. The Methods section below is built from it.',
                    'release' => '2011', 'assembly' => null, 'format' => 'PDF',
                    'url' => 'https://download.maizegdb.org/Insertions/UniformMu/UniformMu_Methods2011.pdf'),
          )
      ),
  );

  /* The resource papers, and the works the methods text cites.

     Every one that MaizeGDB holds a reference record for links to it, because
     that record is maintained and a hand-typed citation is not. The ids were
     resolved against mgdb.reference once; they are constants here rather than
     a query, since this page runs no SQL.

     The 2025 resource paper had href="" on the old page — an empty link that
     reloaded the page. Its DOI was in the database the whole time, recorded in
     the pages column as "doi: 10.1101/pdb.top108483". */
  $um_papers = array(
      array('authors' => 'Koch KE, McCarty DR',
            'year' => 2025,
            'title' => 'The UniformMu National Public Resource: Transposon-Induced Mutant Seeds for Functional Genomics Studies in Maize',
            'citation' => 'Cold Spring Harbor Protocols',
            'doi' => '10.1101/pdb.top108483',
            'mgdb' => 10747434,
            'note' => 'Cite this if you use UniformMu seed or the mapped insertion data.'),
      array('authors' => 'McCarty DR, Koch KE',
            'year' => 2025,
            'title' => 'Functional Genomic Analysis of Transposon Insertion Mutant Maize Plants from the UniformMu National Public Resource',
            'citation' => 'Cold Spring Harbor Protocols',
            'doi' => '10.1101/pdb.prot108688',
            'mgdb' => 10747435,
            'note' => 'The companion protocol: what to do with the seed once it arrives.'),
      array('authors' => 'McCarty DR, Latshaw S, Wu S, Suzuki M, Hunter CT, Avigne WT, Koch KE',
            'year' => 2013,
            'title' => 'Mu-seq: Sequence-based mapping and identification of transposon induced mutations',
            'citation' => 'PLoS ONE 8(11): e77172',
            'doi' => '10.1371/journal.pone.0077172',
            'mgdb' => 9024386,
            'note' => 'The sequencing and mapping method behind the insertion coordinates.'),
      array('authors' => 'Marcon C, Brox A, et al.',
            'year' => 2024,
            'title' => 'Identification of Transposon Insertion Sites in Maize Mu-Tagged Mutants Using Mu-Seq',
            'citation' => 'Cold Spring Harbor Protocols',
            'doi' => '10.1101/pdb.prot108586',
            'mgdb' => 10691883,
            'note' => null),
  );

  $um_references = array(
      array('authors' => "Settles AM, Holding DR, Tan BC, Latshaw SP, Liu J, Suzuki M, Li L, O'Brien BA, Fajardo DS, Wroclawska E, Tseung CW, Lai J, Hunter CT 3rd, Avigne WT, Baier J, Messing J, Hannah LC, Koch KE, Becraft PW, Larkins BA, McCarty DR",
            'year' => 2007,
            'title' => 'Sequence-indexed mutations in maize using the UniformMu transposon-tagging population',
            'citation' => 'BMC Genomics 8: 116',
            'doi' => null, 'mgdb' => 9018229),
      array('authors' => 'McCarty DR, Settles AM, Suzuki M, Tan BC, Latshaw S, Porch T, Robin K, Baier J, Avigne W, Lai J, Messing J, Koch KE, Hannah LC',
            'year' => 2005,
            'title' => 'Steady-state transposon mutagenesis in inbred maize',
            'citation' => 'The Plant Journal 44: 52-61',
            'doi' => null, 'mgdb' => 9020441),
      array('authors' => 'Schnable PS, Ware D, et al.',
            'year' => 2009,
            'title' => 'The B73 maize genome: complexity, diversity, and dynamics',
            'citation' => 'Science 326: 1112-1115',
            'doi' => null, 'mgdb' => 1233776),
      array('authors' => 'McCarty DR, Meeley RB',
            'year' => 2009,
            'title' => 'Transposon resources for forward and reverse genetics in maize',
            'citation' => 'In: Handbook of Maize: Genetics and Genomics (Ed. JL Bennetzen, S Hake). Springer, Berlin. pp 561-584',
            'doi' => null, 'mgdb' => null),
      array('authors' => 'Myers EW, Sutton GG, et al.',
            'year' => 2000,
            'title' => 'A whole-genome assembly of <em>Drosophila</em>',
            'citation' => 'Science 287: 2196-2204',
            'doi' => null, 'mgdb' => null),
  );

/* -------------------------------------------------------------------------- *
 * Rendered fragments
 * -------------------------------------------------------------------------- */

  /* Per-assembly coverage.

     Two gene counts per row, never one. "Genes with an insertion" counts a gene
     whose 10 kb flank was hit; "genes hit inside the transcript" counts only
     UTR, exon and intron. A reader planning a knockout wants the second, and
     the old page quoted a figure of the first kind under the word "genes". */
  $um_assembly_rows = '';
  foreach ($um_assemblies as $assembly) {
      if ($assembly['name'] === null) {
          continue;   // rendered separately as a data gap, not as an assembly
      }
      $coverage = $assembly['gene_fraction'] === null
                ? '<span class="mgdb-muted">not comparable</span>'
                : um_pct($assembly['gene_fraction']);

      $um_assembly_rows .= '<tr>'
          . '<th scope="row">' . um_esc($assembly['label'])
          . '<span class="um-assembly-name">' . um_esc($assembly['name']) . '</span></th>'
          . '<td class="mgdb-numeric" data-value="' . (int) $assembly['insertions'] . '">'
          . um_num($assembly['insertions']) . '</td>'
          . '<td class="mgdb-numeric" data-value="' . (int) $assembly['genes'] . '">'
          . um_num($assembly['genes']) . '</td>'
          . '<td class="mgdb-numeric" data-value="' . (int) $assembly['genes_genic'] . '">'
          . ($assembly['genes_genic'] > 0 ? um_num($assembly['genes_genic'])
             : '<span class="mgdb-muted">none recorded</span>') . '</td>'
          . '<td class="mgdb-numeric" data-value="'
          . ($assembly['gene_fraction'] === null ? '' : $assembly['gene_fraction']) . '">'
          . $coverage . '</td>'
          . '<td>' . um_esc($assembly['note']) . '</td>'
          . '</tr>';
  }

  /* Where insertions land, per assembly. Structure counts are per alignment,
     and one insertion can be counted under two structures on the same assembly
     because different transcripts of the same gene disagree about where the
     boundary is. The column header says "alignments" for that reason. */
  $um_structure_rows = '';
  $um_structure_series = array();
  foreach ($um_assemblies as $assembly) {
      if ($assembly['name'] === null || !$assembly['structures']) { continue; }
      $total = 0;
      foreach ($assembly['structures'] as $structure) { $total += $structure['alignments']; }
      foreach ($assembly['structures'] as $structure) {
          $share = $total > 0 ? $structure['alignments'] / $total : 0;
          $um_structure_rows .= '<tr>'
              . '<th scope="row">' . um_esc($assembly['label']) . '</th>'
              . '<td>' . um_esc($structure['structure']) . '</td>'
              . '<td class="mgdb-numeric" data-value="' . (int) $structure['insertions'] . '">'
              . um_num($structure['insertions']) . '</td>'
              . '<td class="mgdb-numeric" data-value="' . (int) $structure['alignments'] . '">'
              . um_num($structure['alignments']) . '</td>'
              . '<td class="mgdb-numeric" data-value="' . $share . '">'
              . number_format($share * 100, 1) . '%</td>'
              . '</tr>';
      }
      $um_structure_series[] = $assembly['label'];
  }

  /* Insertions per gene on the current reference. */
  $um_bucket_rows = '';
  $um_genes_multi = 0;
  $um_genes_total = 0;
  foreach ($um_buckets as $bucket) {
      $um_genes_total += $bucket['genes'];
      if ($bucket['insertions'] >= 2) { $um_genes_multi += $bucket['genes']; }
  }
  foreach ($um_buckets as $bucket) {
      $label = $bucket['insertions'] >= 10 ? '10 or more' : (string) $bucket['insertions'];
      $share = $um_genes_total > 0 ? $bucket['genes'] / $um_genes_total : 0;
      /* Deliberately not .mgdb-numeric. These are bucket labels — "1", "2",
         "10 or more" — under a header six times their width, and right-aligning
         them pushes every value against the next column so the rows read as
         though the table has a deep indent. */
      $um_bucket_rows .= '<tr>'
          . '<th scope="row" class="um-bucket-col">' . um_esc($label) . '</th>'
          . '<td class="mgdb-numeric" data-value="' . (int) $bucket['genes'] . '">'
          . um_num($bucket['genes']) . '</td>'
          . '<td class="mgdb-numeric" data-value="' . $share . '">'
          . number_format($share * 100, 1) . '%</td>'
          . '</tr>';
  }

  /* Insertions per chromosome, current reference only. */
  $um_chromosome_rows = '';
  if ($um_reference) {
      foreach ($um_reference['chromosomes'] as $chromosome) {
          $um_chromosome_rows .= '<tr>'
              . '<th scope="row">' . um_esc($chromosome['name']) . '</th>'
              . '<td class="mgdb-numeric" data-value="' . (int) $chromosome['insertions'] . '">'
              . um_num($chromosome['insertions']) . '</td>'
              . '<td class="mgdb-numeric" data-value="' . (int) $chromosome['first'] . '">'
              . um_num($chromosome['first']) . '</td>'
              . '<td class="mgdb-numeric" data-value="' . (int) $chromosome['last'] . '">'
              . um_num($chromosome['last']) . '</td>'
              . '</tr>';
      }
  }

  /* Genome browsers. Built from the same assembly list as the tables, so a
     browser link can never point at an assembly the page says has no data. */
  $um_browser_cards = '';
  foreach ($um_assemblies as $assembly) {
      if ($assembly['name'] === null || !$assembly['browser_url']) { continue; }
      $um_browser_cards .= '<a class="mgdb-card um-browser-card" href="' . um_esc($assembly['browser_url'])
          . '" target="_blank" rel="noopener">'
          . '<h3>' . um_esc($assembly['label'])
          . '<span class="mgdb-visually-hidden"> (opens the genome browser in a new tab)</span></h3>'
          . '<p class="mgdb-small mgdb-muted">' . um_esc($assembly['name']) . '</p>'
          . '<p>' . um_num($assembly['insertions']) . ' insertions on the UniformMu track.</p>'
          . '</a>';
  }

  /* Downloads, as a table.

     A list of links cannot carry the release and the assembly without turning
     each entry into a sentence, and the previous version of this page proved
     that a light two-line link list is where a reader's eye slides off. In a
     table the assembly column can be read straight down, which is the actual
     question — "which of these is against v4?" */
  $um_download_html = '';
  foreach ($um_downloads as $group) {
      $rows = '';
      foreach ($group['files'] as $file) {
          $rows .= '<tr>'
              . '<th scope="row"><a class="um-download-link" href="' . um_esc($file['url']) . '">'
              . um_esc($file['label']) . '</a>'
              . '<span class="um-download-detail">' . um_esc($file['detail']) . '</span></th>'
              . '<td><span class="mgdb-pill mgdb-pill-info">' . um_esc($file['release']) . '</span></td>'
              . '<td>' . ($file['assembly'] === null
                          ? '<span class="mgdb-muted">&mdash;</span>'
                          : '<span class="um-download-assembly">' . um_esc($file['assembly']) . '</span>') . '</td>'
              . '<td><span class="um-download-format">' . um_esc($file['format']) . '</span></td>'
              . '</tr>';
      }
      $um_download_html .= '<section class="um-download-group">'
          . '<h3>' . um_esc($group['group']) . '</h3>'
          . '<p class="um-download-note">' . um_esc($group['note']) . '</p>'
          . '<div class="mgdb-table-scroll"><table class="mgdb-table um-download-table">'
          . '<thead><tr>'
          . '<th scope="col">File</th>'
          . '<th scope="col">Release</th>'
          . '<th scope="col">Coordinates against</th>'
          . '<th scope="col">Format</th>'
          . '</tr></thead><tbody>' . $rows . '</tbody></table></div>'
          . '</section>';
  }

  /* Papers and references, in the same card the reference data hub and the
     cite page use, so a citation looks the same everywhere on the site.

     Every entry that MaizeGDB holds a record for links to it. A DOI is printed
     only where the database has one; none is inferred from a journal's usual
     pattern, because a DOI that resolves to the wrong paper is worse than no
     DOI at all. */
  function um_reference_card($entry, $lead = false) {
      $meta = array((string) $entry['year']);
      if (!empty($entry['doi']))  { $meta[] = 'DOI'; }
      if (!empty($entry['mgdb'])) { $meta[] = 'MaizeGDB record'; }

      /* The title links to whichever is the better destination: the DOI where
         one exists, the MaizeGDB record otherwise, and nothing at all when the
         work is neither held here nor identified by a DOI. */
      $href = !empty($entry['doi']) ? 'https://doi.org/' . $entry['doi']
            : (!empty($entry['mgdb']) ? '/data_center/reference?id=' . (int) $entry['mgdb'] : null);

      /* Titles are the one field carrying markup — <em> for a genus name — and
         they come from the fixed list above, never from a request. */
      $title = $entry['title'];
      $heading = $href === null ? $title
               : '<a href="' . um_esc($href) . '">' . $title . '</a>';

      $links = array();
      if (!empty($entry['doi'])) {
          $links[] = '<a href="https://doi.org/' . um_esc($entry['doi']) . '">doi:'
                   . um_esc($entry['doi']) . '</a>';
      }
      if (!empty($entry['mgdb'])) {
          $links[] = '<a href="/data_center/reference?id=' . (int) $entry['mgdb']
                   . '">MaizeGDB reference record</a>';
      }

      return '<article class="reference-result-card' . ($lead ? ' is-lead' : '') . '">'
          . '<div class="reference-result-meta"><span>'
          . implode('</span><span>', array_map('um_esc', $meta)) . '</span></div>'
          . '<h3>' . $heading . '</h3>'
          . '<p class="reference-result-authors">' . um_esc($entry['authors']) . '</p>'
          . '<p class="reference-result-citation">' . um_esc($entry['citation']) . '</p>'
          . (empty($entry['note']) ? ''
             : '<p class="reference-result-note">' . um_esc($entry['note']) . '</p>')
          . (count($links)
             ? '<p class="reference-result-links">' . implode('', $links) . '</p>' : '')
          . '</article>';
  }

  $um_paper_html = '';
  foreach ($um_papers as $index => $paper) {
      // The first is the paper to cite for the resource itself; it gets the spine.
      $um_paper_html .= um_reference_card($paper, $index === 0);
  }

  $um_reference_html = '';
  foreach ($um_references as $reference) {
      $um_reference_html .= um_reference_card($reference);
  }

/* -------------------------------------------------------------------------- *
 * Headline numbers
 * -------------------------------------------------------------------------- */

  $um_generated = isset($um_data['generated']) ? $um_data['generated'] : null;
  $um_mtime = @filemtime($um_payload_file);
  $um_generated_long = $um_generated && strtotime($um_generated)
                     ? date('j F Y', strtotime($um_generated))
                     : ($um_mtime ? date('j F Y', $um_mtime) : 'unknown');

  $um_ref_genes    = $um_reference ? $um_reference['genes'] : null;
  $um_ref_genic    = $um_reference ? $um_reference['genes_genic'] : null;
  $um_ref_universe = $um_reference ? $um_reference['genes_in_assembly'] : null;
  $um_ref_fraction = $um_reference ? $um_reference['gene_fraction'] : null;
  $um_ref_label    = $um_reference ? $um_reference['label'] : 'the current reference';

  /* The unaligned insertions. Stating them is the difference between "77,990
     insertions" and "77,990 insertions, 68,834 of which have coordinates". */
  $um_unaligned = um_total('unaligned_loci', 0);

/* -------------------------------------------------------------------------- *
 * Publish
 * -------------------------------------------------------------------------- */

  $bauplan = new Bauplan('UniformMu transposon insertion resource | MaizeGDB');
  $bauplan->modern();

  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* Asset paths are versioned on mtime so a shell or page-sheet edit is not
     served from cache. Resolved the same way as the payload above: root_dir
     first, DOCUMENT_ROOT as the fallback. A missing file yields 0, which is a
     stable key rather than a cache-buster that changes every request. */
  $um_doc_root = rtrim($system['root_dir'], '/');
  if (!is_dir($um_doc_root . '/css') && isset($_SERVER['DOCUMENT_ROOT'])) {
      $um_doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
  }

  /* The shared Data Hub shell, loaded BEFORE the page sheet so the page can
     override it. It supplies the ground, the white section cards and their
     coloured top edges, the sticky tab bar, the table zebra, the green Related
     resources wash and the scroll offset -- none of which mgdb-uniformmu.css
     restates any more. Converted 2026-09-05. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($um_doc_root . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-uniformmu.css?v=' . (int) @filemtime($um_doc_root . '/css/mgdb-uniformmu.css'));
  $bauplan->includeScript('/js/lib/plotly/plotly-2.25.2.min.js');
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  $bauplan->includeScript('/js/mgdb-uniformmu.js?v=' . (int) @filemtime($um_doc_root . '/js/mgdb-uniformmu.js'));
  $bauplan->head('<meta name="description" content="UniformMu is a sequence-indexed Mu transposon insertion population in a uniform W22 background. '
      . 'Find insertions by gene, insertion identifier, seed stock or genomic region, and order the seed free of charge from the Maize Genetics Cooperation Stock Center.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $body = $mgdb->get('body')->load('templates/static/mgdb_uniformmu.bau');

  $body->get('payload-url')->replace(um_esc($um_payload_rel));
  $body->get('data-date')->replace(um_esc($um_generated_long));

  /* Headline metrics */
  $body->get('insertion-count')->replace(um_num(um_total('insertion_loci')));
  $body->get('aligned-count')->replace(um_num(um_total('aligned_loci')));
  $body->get('unaligned-count')->replace(um_num($um_unaligned));
  $body->get('stock-count')->replace(um_num(um_total('named_stocks')));
  $body->get('mapped-stock-count')->replace(um_num(um_total('stocks')));
  $body->get('variation-count')->replace(um_num(um_total('variations')));
  $body->get('alignment-count')->replace(um_num(um_total('alignments')));
  $body->get('assembly-count')->replace(um_num(um_total('assemblies')));

  $body->get('reference-label')->replace(um_esc($um_ref_label));
  $body->get('reference-genes')->replace(um_num($um_ref_genes));
  $body->get('reference-genic')->replace(um_num($um_ref_genic));
  $body->get('reference-universe')->replace(um_num($um_ref_universe));
  $body->get('reference-fraction')->replace(um_pct($um_ref_fraction));
  $body->get('reference-genic-fraction')->replace(
      ($um_ref_genic && $um_ref_universe) ? um_pct($um_ref_genic / $um_ref_universe) : '&mdash;');

  $body->get('stock-insertions-median')->replace(
      isset($um_per_stock['median']) ? um_num($um_per_stock['median']) : '&mdash;');
  $body->get('stock-insertions-mean')->replace(
      isset($um_per_stock['mean']) ? number_format((float) $um_per_stock['mean'], 1) : '&mdash;');

  $body->get('genes-multi')->replace(um_num($um_genes_multi));
  $body->get('genes-with-insertions')->replace(um_num($um_genes_total));

  /* Tables and lists */
  $body->get('assembly-rows')->replace($um_assembly_rows);
  $body->get('structure-rows')->replace($um_structure_rows);
  $body->get('bucket-rows')->replace($um_bucket_rows);
  $body->get('chromosome-rows')->replace($um_chromosome_rows);
  $body->get('browser-cards')->replace($um_browser_cards);
  $body->get('downloads')->replace($um_download_html);
  $body->get('papers')->replace($um_paper_html);
  $body->get('references')->replace($um_reference_html);

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
?>
