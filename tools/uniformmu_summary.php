<?php
/* file: tools/uniformmu_summary.php
 *
 * purpose: measure the UniformMu collection once, offline, and write the result
 *          as JSON for /uniformmu to render server-side.
 *
 * Why this is not done at render time
 * -----------------------------------
 * Every collection-wide number on that page is an aggregate over
 * perm_tables.marker_gene_model, which has 1,305,425 rows and no index on
 * source_id or assembly_version. The single per-assembly rollup below costs
 * 1.6 s and 19,551 buffers as a sequential scan; the whole run is about six
 * seconds. That is fine offline and unacceptable in a page load, so the page
 * reads this file instead and issues no query at all for its summary. The
 * interactive lookups on the page are a different matter -- those are indexed,
 * bounded, and live; see search/uniformmu/uniformmu_search_lib.php.
 *
 * The file's own modification time is what the page reports as its data date,
 * so the page cannot claim to be fresher than its data.
 *
 * Running it
 * ----------
 *   scp tools/uniformmu_summary.php development-server:/tmp/
 *   ssh development-server 'cd <webroot> && php /tmp/uniformmu_summary.php'
 *   # writes JSON on stdout; capture it into src/data/uniformmu/
 *
 * It has to run on the server: the MaizeGDB codebase and the database
 * credentials are both there, and neither belongs in this repository.
 * getSystemInfoFile() walks up from getcwd() to find conf/, so the working
 * directory must be the web root. Warnings about a missing gp_lib.php and
 * about HTTP_HOST are harmless under the CLI.
 *
 * What counts as UniformMu
 * ------------------------
 * perm_tables.marker_gene_model.source_id = 1226435 ("UniformMu"), restricted
 * to loci whose name matches ^mu[0-9]+$. That restriction is not cosmetic:
 * nine loci filed under this source are Ac insertions carrying names like
 * bti00194::Ac and mon03077::Ac. They are somebody else's collection sitting in
 * this one's source field, and including them would make every count on the
 * page slightly wrong in a way no reader could see. See ADMIN_DEPENDENCIES.
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit("This script is a command-line tool.\n");
}

/* The output of this script is the file, so nothing else may reach stdout.
   Under the CLI SAPI display_errors defaults to writing there, and the codebase
   emits a handful of unavoidable notices about HTTP_HOST and include paths. */
ini_set('display_errors', 'stderr');

/* db-api.php includes gp_lib.php through $_SERVER['DOCUMENT_ROOT'], which the
   CLI does not set, so getSystemInfo() would be undefined. Including it by
   relative path first satisfies the include_once and the warning is harmless. */
include_once('./include/gp_lib.php');
include_once('./include/db-api.php');

define('UM_SOURCE_ID', 1226435);

/* Assembly display order and labels. An assembly that turns up in the data
   without an entry here still appears -- at the end, under its raw name --
   rather than being dropped, because a silently missing assembly is
   indistinguishable from one with no insertions. */
$UM_ASSEMBLIES = array(
    'Zm-B73-REFERENCE-NAM-5.0'      => array('label' => 'B73 v5',  'line' => 'B73',
        'note' => 'Current reference assembly. Release 9.',
        'browser' => 'https://jbrowse.maizegdb.org/?loc=chr1%3A56590639..56904419&tracks=uniformmu%2Cgene_models_official'),
    'Zm-B73-REFERENCE-GRAMENE-4.0'  => array('label' => 'B73 v4',  'line' => 'B73',
        'note' => 'Release 9.',
        'browser' => 'https://www.maizegdb.org/gbrowse/maize_v4/?start=34400;stop=530000;ref=Chr1;width=1000;version=100;flip=0;grid=1;id=f3e56d34082ca3b8c03410da1eec743a;l=UniformMu%1EGene_Models'),
    'B73 RefGen_v3'                 => array('label' => 'B73 v3',  'line' => 'B73',
        'note' => 'Release 8.',
        'browser' => null),
    'Zm-W22-REFERENCE-NRGENE-2.0'   => array('label' => 'W22 v2',  'line' => 'W22',
        'note' => 'The background the population was built in.',
        'browser' => 'https://jbrowse.maizegdb.org/?data=W22&loc=chr1%3A127472..316971&tracks=gene_models%2Cuniformmu'),
);

$DBConn = connect_to_database(false);
if (!$DBConn) {
    fwrite(STDERR, "Could not connect to the database.\n");
    exit(1);
}

$started = microtime(true);
$queries = 0;

function umRows($sql, $params = array()) {
    global $DBConn, $queries;
    $queries++;
    $rows = get_all_rows(make_query($DBConn, $sql, 1, $params));
    return is_array($rows) ? $rows : array();
}

function umRow($sql, $params = array()) {
    $rows = umRows($sql, $params);
    return count($rows) ? $rows[0] : array();
}

function umInt($value) {
    return ($value === null || $value === '') ? null : (int) $value;
}

/* The subquery every aggregate below starts from: one row per alignment of a
   UniformMu insertion, with the gene it was aligned against resolved.

   gene_model is empty for every W22 alignment and the transcript carries the
   name instead, so the gene key has to fall back to the transcript with its
   _T### suffix stripped. Counting distinct gene_model on W22 returns 10, which
   is not a fact about the genome. */
$UM_ALIGNMENTS = "
    SELECT mgm.id,
           COALESCE(NULLIF(btrim(mgm.gene_model), ''),
                    regexp_replace(btrim(COALESCE(mgm.transcript, '')), '_T[0-9]+$', '')) AS gene_key,
           NULLIF(btrim(COALESCE(mgm.assembly_version, '')), '') AS assembly,
           mgm.chromosome, mgm.start_coordinate, mgm.end_coordinate,
           mgm.gene_structure_id
    FROM perm_tables.marker_gene_model mgm
      JOIN mgdb.locus l ON l.id = mgm.id
    WHERE mgm.source_id = " . UM_SOURCE_ID . "
          AND l.name ~ '^mu[0-9]+$'";

/* ---------------------------------------------------------------- Totals --- */

/* Insertion loci that exist as MaizeGDB records, whether or not they were ever
   aligned. The difference between this and the aligned count is a real gap and
   the page states it rather than reporting only the larger number. */
$locus_totals = umRow("
    SELECT count(*) AS loci,
           count(*) FILTER (WHERE idn.curation_lvl = 0) AS current_loci
    FROM mgdb.locus l
      JOIN mgdb.id_num idn ON idn.id = l.id
    WHERE l.name ~ '^mu[0-9]+$'");

$align_totals = umRow("
    SELECT count(*) AS alignments,
           count(DISTINCT a.id) AS insertions,
           count(DISTINCT a.assembly) AS assemblies
    FROM ($UM_ALIGNMENTS) a");

/* A variation is the allele the insertion creates; a stock is the seed that
   carries it. Both are record pages, and the count of each is what tells a
   reader whether following the chain from an insertion will reach seed. */
$variation_totals = umRow("
    SELECT count(DISTINCT v.id) AS variations,
           count(DISTINCT l.id) AS insertions_with_variation
    FROM mgdb.locus l
      JOIN mgdb.variation v ON v.variationof = l.id
    WHERE l.name ~ '^mu[0-9]+$'");

$stock_totals = umRow("
    SELECT count(DISTINCT s.id) AS stocks,
           count(DISTINCT s.id) FILTER (WHERE idn.curation_lvl = 0) AS current_stocks,
           count(DISTINCT l.id) AS insertions_with_stock
    FROM mgdb.locus l
      JOIN mgdb.variation v ON v.variationof = l.id
      JOIN mgdb.stock_genotypic_var sgv ON sgv.variation = v.id
      JOIN mgdb.stock s ON s.id = sgv.id
      JOIN mgdb.id_num idn ON idn.id = s.id
    WHERE l.name ~ '^mu[0-9]+$'");

/* Two different counts of "the UFMu stocks", and they differ. Every stock that
   carries a mapped Mu insertion is UFMu-named -- there are none outside the
   naming convention -- but several hundred UFMu-named stocks have no insertion
   linked to them. Reporting only the larger number would overstate how much of
   the collection is sequence-indexed; reporting only the smaller would
   understate what the Stock Center will send you. */
$named_stocks = umRow("
    SELECT count(*) AS stocks,
           count(*) FILTER (WHERE idn.curation_lvl = 0) AS current_stocks
    FROM mgdb.stock s
      JOIN mgdb.id_num idn ON idn.id = s.id
    WHERE s.name LIKE 'UFMu-%'");

/* Insertions per stock and stocks per insertion. The methods text promises
   "typically 5-10 insertions" per line; this measures it rather than repeating
   it. */
$per_stock = umRow("
    SELECT round(avg(n)::numeric, 1) AS mean, percentile_cont(0.5) WITHIN GROUP (ORDER BY n) AS median,
           min(n) AS min, max(n) AS max
    FROM (
      SELECT s.id, count(DISTINCT l.id) AS n
      FROM mgdb.stock s
        JOIN mgdb.stock_genotypic_var sgv ON sgv.id = s.id
        JOIN mgdb.variation v ON v.id = sgv.variation
        JOIN mgdb.locus l ON l.id = v.variationof
      WHERE l.name ~ '^mu[0-9]+$'
      GROUP BY s.id
    ) t");

/* -------------------------------------------------------------- Assembly --- */

$per_assembly = umRows("
    SELECT a.assembly,
           count(*) AS alignments,
           count(DISTINCT a.id) AS insertions,
           count(DISTINCT a.gene_key) FILTER (WHERE a.gene_key <> '') AS genes,
           count(DISTINCT a.chromosome) AS sequences
    FROM ($UM_ALIGNMENTS) a
    GROUP BY a.assembly
    ORDER BY count(DISTINCT a.id) DESC");

/* Genes carrying an insertion inside the transcribed region, as opposed to one
   in the 10 kb flank. The two are different claims about a gene and the page
   never prints one under the other's label. */
$genic = umRows("
    SELECT a.assembly, count(DISTINCT a.gene_key) AS genes
    FROM ($UM_ALIGNMENTS) a
      JOIN mgdb.term gs ON gs.id = a.gene_structure_id
    WHERE a.gene_key <> '' AND gs.name <> 'Flanking region'
    GROUP BY a.assembly");

$structures = umRows("
    SELECT a.assembly, COALESCE(gs.name, 'not recorded') AS structure,
           count(*) AS alignments, count(DISTINCT a.id) AS insertions
    FROM ($UM_ALIGNMENTS) a
      LEFT JOIN mgdb.term gs ON gs.id = a.gene_structure_id
    GROUP BY a.assembly, COALESCE(gs.name, 'not recorded')
    ORDER BY a.assembly, count(*) DESC");

$chromosomes = umRows("
    SELECT a.assembly, a.chromosome, count(DISTINCT a.id) AS insertions,
           min(a.start_coordinate) AS first_position, max(a.start_coordinate) AS last_position
    FROM ($UM_ALIGNMENTS) a
    WHERE a.chromosome IS NOT NULL AND btrim(a.chromosome) <> ''
    GROUP BY a.assembly, a.chromosome
    ORDER BY a.assembly, count(DISTINCT a.id) DESC");

/* Insertions per gene, capped into a 10+ bucket. Read on the current reference
   only: the same insertion aligns to three B73 assemblies whose gene sets are
   different sizes, so one histogram per assembly would invite a comparison that
   is about annotation, not about the collection. */
$per_gene = umRows("
    SELECT least(n, 10) AS bucket, count(*) AS genes
    FROM (
      SELECT a.gene_key, count(DISTINCT a.id) AS n
      FROM ($UM_ALIGNMENTS) a
      WHERE a.assembly = 'Zm-B73-REFERENCE-NAM-5.0' AND a.gene_key <> ''
      GROUP BY a.gene_key
    ) g
    GROUP BY 1 ORDER BY 1");

/* The denominator for coverage: current, non-obsolete gene models per assembly.
   is_reference_gene_model is only populated for B73 v5, so filtering on it
   would leave every other assembly without a denominator and quietly drop its
   coverage figure -- the filter is analysis_is_current instead, which is set
   throughout. Note that the v3 denominator is the whole working gene set,
   110,467 models, not a filtered set, so v3 coverage is not comparable with
   v4 and v5 coverage; the page says so where it prints them. */
$gene_universe = umRows("
    SELECT assembly_version AS assembly, count(DISTINCT gene_name) AS genes
    FROM chado.gene_model
    WHERE is_obsolete = false AND analysis_is_current = 'yes'
    GROUP BY 1");

/* ---------------------------------------------------------------- Shape ---- */

$genic_by_assembly = array();
foreach ($genic as $row) {
    $genic_by_assembly[(string) $row['assembly']] = (int) $row['genes'];
}

$universe_by_assembly = array();
foreach ($gene_universe as $row) {
    $universe_by_assembly[(string) $row['assembly']] = (int) $row['genes'];
}

$structures_by_assembly = array();
foreach ($structures as $row) {
    $key = $row['assembly'] === null ? '' : (string) $row['assembly'];
    $structures_by_assembly[$key][] = array(
        'structure'  => (string) $row['structure'],
        'alignments' => (int) $row['alignments'],
        'insertions' => (int) $row['insertions']
    );
}

$chromosomes_by_assembly = array();
foreach ($chromosomes as $row) {
    $key = $row['assembly'] === null ? '' : (string) $row['assembly'];
    $chromosomes_by_assembly[$key][] = array(
        'name'       => (string) $row['chromosome'],
        'insertions' => (int) $row['insertions'],
        'first'      => umInt($row['first_position']),
        'last'       => umInt($row['last_position'])
    );
}

$assemblies = array();
foreach ($per_assembly as $row) {
    $name = $row['assembly'] === null ? '' : (string) $row['assembly'];
    $meta = isset($UM_ASSEMBLIES[$name]) ? $UM_ASSEMBLIES[$name] : null;
    $universe = isset($universe_by_assembly[$name]) ? $universe_by_assembly[$name] : null;
    $genes = (int) $row['genes'];

    $chrs = isset($chromosomes_by_assembly[$name]) ? $chromosomes_by_assembly[$name] : array();
    /* A B73 assembly has ten chromosomes and, in v5, thirty-six scaffolds
       carrying eighteen insertions between them. Splitting them keeps a
       chromosome chart from growing a tail of near-zero bars. */
    $named = array();
    $scaffold_insertions = 0;
    $scaffold_count = 0;
    foreach ($chrs as $chr) {
        if (preg_match('/^(chr|Chr)?([0-9]{1,2})$/', $chr['name'])) {
            $named[] = $chr;
        } else {
            $scaffold_count++;
            $scaffold_insertions += $chr['insertions'];
        }
    }

    $assemblies[] = array(
        'name'         => $name === '' ? null : $name,
        'label'        => $meta ? $meta['label'] : ($name === '' ? 'No assembly recorded' : $name),
        'line'         => $meta ? $meta['line'] : null,
        'note'         => $meta ? $meta['note'] : null,
        'browser_url'  => $meta ? $meta['browser'] : null,
        'alignments'   => (int) $row['alignments'],
        'insertions'   => (int) $row['insertions'],
        'genes'        => $genes,
        'genes_genic'  => isset($genic_by_assembly[$name]) ? $genic_by_assembly[$name] : 0,
        'genes_in_assembly' => $universe,
        'gene_fraction' => ($universe && $universe > 0) ? round($genes / $universe, 4) : null,
        'sequences'    => (int) $row['sequences'],
        'chromosomes'  => $named,
        'scaffold_count' => $scaffold_count,
        'scaffold_insertions' => $scaffold_insertions,
        'structures'   => isset($structures_by_assembly[$name]) ? $structures_by_assembly[$name] : array()
    );
}

/* Ordered as $UM_ASSEMBLIES lists them, unknown assemblies last, so the current
   reference leads every table rather than whichever happens to have most rows. */
$rank = array_flip(array_keys($UM_ASSEMBLIES));
usort($assemblies, function ($a, $b) use ($rank) {
    $ar = isset($rank[$a['name']]) ? $rank[$a['name']] : 90;
    $br = isset($rank[$b['name']]) ? $rank[$b['name']] : 90;
    if ($ar !== $br) { return $ar - $br; }
    return $b['insertions'] - $a['insertions'];
});

$buckets = array();
foreach ($per_gene as $row) {
    $buckets[] = array('insertions' => (int) $row['bucket'], 'genes' => (int) $row['genes']);
}

$loci        = (int) $locus_totals['loci'];
$aligned     = (int) $align_totals['insertions'];

$payload = array(
    'generated' => gmdate('c'),
    'source'    => array('id' => UM_SOURCE_ID, 'name' => 'UniformMu'),
    'totals'    => array(
        'insertion_loci'    => $loci,
        'current_loci'      => (int) $locus_totals['current_loci'],
        'aligned_loci'      => $aligned,
        'unaligned_loci'    => $loci - $aligned,
        'alignments'        => (int) $align_totals['alignments'],
        'assemblies'        => (int) $align_totals['assemblies'],
        'variations'        => (int) $variation_totals['variations'],
        'insertions_with_variation' => (int) $variation_totals['insertions_with_variation'],
        'stocks'            => (int) $stock_totals['stocks'],
        'current_stocks'    => (int) $stock_totals['current_stocks'],
        'named_stocks'      => (int) $named_stocks['stocks'],
        'current_named_stocks' => (int) $named_stocks['current_stocks'],
        'insertions_with_stock' => (int) $stock_totals['insertions_with_stock']
    ),
    'per_stock' => array(
        'mean'   => $per_stock ? (float) $per_stock['mean'] : null,
        'median' => $per_stock ? (float) $per_stock['median'] : null,
        'min'    => $per_stock ? (int) $per_stock['min'] : null,
        'max'    => $per_stock ? (int) $per_stock['max'] : null
    ),
    'assemblies' => $assemblies,
    'per_gene'   => array(
        'assembly' => 'Zm-B73-REFERENCE-NAM-5.0',
        'buckets'  => $buckets
    ),
    'measured'   => array(
        'queries'    => $queries,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)
    )
);

/* An independent check of the same quantity from a different direction. The
   database layer in this codebase returns an empty result rather than raising,
   so a query that silently fails is otherwise indistinguishable from a
   collection that is genuinely empty. */
$warnings = array();
$sum_insertions = 0;
foreach ($assemblies as $assembly) {
    $sum_insertions = max($sum_insertions, $assembly['insertions']);
}
if ($aligned < $sum_insertions) {
    $warnings[] = 'aligned_loci (' . $aligned . ') is below the largest per-assembly count ('
                . $sum_insertions . '); the rollup and the per-assembly query disagree.';
}
if ($aligned === 0 || $loci === 0 || (int) $stock_totals['stocks'] === 0) {
    $warnings[] = 'A headline total came back as zero. Do not deploy this file.';
}
if ($warnings) {
    $payload['warnings'] = $warnings;
    foreach ($warnings as $warning) {
        fwrite(STDERR, "WARNING: $warning\n");
    }
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
?>
