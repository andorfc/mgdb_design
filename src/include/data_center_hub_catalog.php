<?php
/* file: data_center_hub_catalog.php
 *
 * purpose: Catalog and metrics resolver for the MaizeGDB Data Hubs (/data_center/).
 *          Defines active data hubs, category groupings, guided paths, and
 *          resolves live statistics from the PostgreSQL database.
 */

/* The four metric cards, and the rows behind the two figures.
 *
 * What was here before returned ten cards, and six of those numbers had no
 * query behind them at all: 162 genome assemblies, 1.88M gene models, 97K
 * pan-genes and "1M+" predicted structures were literals, and a try/catch
 * answered a failed query with a hard-coded default. Every one of them had
 * already drifted -- there are 160 assemblies, 1,878,920 gene models, and
 * 40,995 structure models rather than a million.
 *
 * The four that did run counted whole tables. Every hub on this site counts
 * `JOIN id_num i ON i.id = x.id WHERE i.curation_lvl = 0`, so the directory
 * advertised 790,208 loci at a hub that says 781,395, and 87,397 stocks at a
 * hub that says 80,063. A directory that disagrees with what it links to is
 * worse than one that says less.
 *
 * Everything below is counted the way the hub it points at counts it, and
 * there is no fallback: a collection whose count fails is dropped rather than
 * drawn at a number nobody measured.
 */
function dataCenterHubCounts($DBConn) {
    if (!$DBConn) {
        return array();
    }

    $counts = array();

    /* One statement per collection rather than one with several counts in it.
       They are over different tables, and even over one table a
       COUNT(*) FILTER (WHERE EXISTS ...) is a correlated subquery per row --
       the EST hub's metric build took 50 seconds written that way. */
    $curated = array(
        'variations' => 'variation',
        'loci'       => 'locus',
        'stocks'     => 'stock',
        'markers'    => 'probe',
        'references' => 'reference',
        'phenotypes' => 'phenotype'
    );
    foreach ($curated as $key => $table) {
        $row = @retrieve_row(make_query($DBConn, "
            SELECT COUNT(*) AS c
            FROM mgdb.$table x
              JOIN mgdb.id_num i ON i.id = x.id
            WHERE i.curation_lvl = 0"));
        if ($row && isset($row['c'])) {
            $counts[$key] = (int) $row['c'];
        }
    }

    /* Images carry their curation level on the row rather than through id_num. */
    $row = @retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS c FROM mgdb.web_image
        WHERE (curation_lvl = 0 OR curation_lvl IS NULL)
          AND url IS NOT NULL AND url <> ''"));
    if ($row && isset($row['c'])) {
        $counts['images'] = (int) $row['c'];
    }

    /* The same query the Genome hub's assembly table is built from, so the two
       pages cannot report different numbers of assemblies. */
    $row = @retrieve_row(make_query($DBConn, "
        SELECT COUNT(*) AS c FROM (
          SELECT DISTINCT gi.assembly
          FROM chado.genome_information gi
            INNER JOIN chado.analysis a ON a.name = gi.assembly
            LEFT JOIN chado.analysisprop ap ON ap.analysis_id = a.analysis_id
               AND ap.type_id = (SELECT cvterm_id FROM chado.cvterm
                                 WHERE name = 'analysis_visibility'
                                   AND cv_id = (SELECT cv_id FROM chado.cv
                                                WHERE name = 'maizegdb'))
          WHERE gi.status = 'Completed'
            AND (ap.value IS NULL OR ap.value <> 'none')) x"));
    if ($row && isset($row['c'])) {
        $counts['genomes'] = (int) $row['c'];
    }

    /* No gene-model count. `chado.gene_model` holds only the models the database
       stores as records, and the ones distributed in flat files are not in it --
       though they are represented in the pan-genes and connected to everything
       else. A figure comparing collection sizes would have shown maize as having
       far fewer gene models than it has (Carson, 2026-09-05). Counting it
       honestly is a question for the gene hub, not a bar on a directory page. */

    $row = @retrieve_row(make_query($DBConn, "
        SELECT COUNT(DISTINCT pan_gene_name) AS c FROM chado.pan_gene_search"));
    if ($row && isset($row['c'])) {
        $counts['pan_genes'] = (int) $row['c'];
    }

    /* Protein structures come from the manifest the structure hub reads, which
       is where that hub's own header numbers come from. */
    $manifest_file = (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
        ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html')
        . '/data/protein_structure/manifest.json';
    if (is_readable($manifest_file)) {
        $manifest = json_decode((string) file_get_contents($manifest_file), true);
        if (is_array($manifest)) {
            $models = 0;
            foreach (array('monomer_models', 'homodimer_models', 'heterodimer_models') as $key) {
                if (isset($manifest[$key])) { $models += (int) $manifest[$key]; }
            }
            if ($models > 0) { $counts['structures'] = $models; }
        }
    }

    return $counts;
}

/* A compact form for a card: 1,709,866 reads as 1.71M, 781,395 as 781K. */
function dataCenterHubShort($n) {
    if ($n >= 1000000) { return number_format($n / 1000000, 2) . 'M'; }
    if ($n >= 10000)   { return number_format(round($n / 1000)) . 'K'; }
    return number_format($n);
}

/* Four cards. The first is the directory's own scale -- it is what this page
   is -- and the other three are the largest collections it points at. Every
   other number this page knows is in the figure below the cards, which is
   where a list of ten belongs. */
function getDataCenterHubMetrics($DBConn, $counts = null, $hub_count = 0) {
    if ($counts === null) { $counts = dataCenterHubCounts($DBConn); }
    $cards = array();

    if ($hub_count > 0) {
        $cards[] = array(
            'value' => number_format($hub_count), 'label' => 'Data hubs',
            'detail' => 'active entry points listed in the directory above',
            'icon' => 'Hub', 'tone' => 'green');
    }
    $wanted = array(
        array('variations', 'Variation records', 'curated alleles and polymorphisms', 'Var', 'amber'),
        array('loci',       'Locus records',     'genes, loci, and their linked evidence', 'Loc', 'blue'),
        array('markers',    'Markers and probes', 'SSRs, RFLPs, BACs, ESTs, and primers', 'Mrk', 'burgundy')
    );
    foreach ($wanted as $w) {
        if (!isset($counts[$w[0]])) { continue; }
        $cards[] = array(
            'value' => dataCenterHubShort($counts[$w[0]]), 'label' => $w[1],
            'detail' => $w[2], 'icon' => $w[3], 'tone' => $w[4]);
    }
    return $cards;
}

/* The scale figure's rows: every collection this page can count, sorted so the
   horizontal bars read top-down from largest. A collection missing from
   $counts is simply absent -- there is no placeholder bar. */
function getDataCenterHubScale($counts) {
    $names = array(
        'variations'  => 'Variation records',
        'loci'        => 'Locus records',
        'markers'     => 'Markers and probes',
        'images'      => 'Curated images',
        'pan_genes'   => 'Pan-gene groups',
        'stocks'      => 'Stock records',
        'references'  => 'Curated references',
        'structures'  => 'Protein structure models',
        'phenotypes'  => 'Phenotype records',
        'genomes'     => 'Genome assemblies'
    );
    $rows = array();
    foreach ($names as $key => $label) {
        if (isset($counts[$key]) && $counts[$key] > 0) {
            $rows[] = array('label' => $label, 'count' => (int) $counts[$key]);
        }
    }
    usort($rows, function ($a, $b) { return $b['count'] - $a['count']; });
    return $rows;
}

/* The donut's rows, counted from the catalog rather than from the rendered
   cards, so the figure is right before any script runs. */
function getDataCenterHubDomains() {
    $labels = array(
        'genomes-variation'    => 'Genomes & variation',
        'genes-function'       => 'Genes & function',
        'phenotype-germplasm'  => 'Phenotype & germplasm',
        'literature-media'     => 'Literature & media'
    );
    $tally = array();
    foreach (getDataCenterHubCenters() as $center) {
        $key = $center['category'];
        $tally[$key] = isset($tally[$key]) ? $tally[$key] + 1 : 1;
    }
    $rows = array();
    foreach ($labels as $key => $label) {
        if (!empty($tally[$key])) {
            $rows[] = array('label' => $label, 'count' => $tally[$key]);
        }
    }
    return $rows;
}

function getDataCenterHubCenters() {
    return array(
        array(
            'category' => 'genomes-variation',
            'category_label' => 'Genomes & variation',
            'icon' => 'ASM',
            'name' => 'Genomes',
            'description' => 'Discover maize and Zea assemblies, annotation releases, genome browsers, downloads, manifests, and project collections.',
            'best_for' => 'Assemblies, annotations, and browsers',
            'url' => '/genome',
            'search' => 'genomes assemblies annotations browsers B73 NAM Zea downloads'
        ),
        array(
            'category' => 'genomes-variation',
            'category_label' => 'Genomes & variation',
            'icon' => 'VAR',
            'name' => 'Alleles and polymorphisms',
            'description' => 'Find classical alleles, molecular variants, inheritance information, phenotypic effects, stocks, and supporting references.',
            'best_for' => 'Named alleles and curated variation',
            'url' => '/data_center/variation',
            'search' => 'alleles polymorphisms variants inheritance dominance viability stocks'
        ),
        array(
            'category' => 'genomes-variation',
            'category_label' => 'Genomes & variation',
            'icon' => 'SNP',
            'name' => 'SNPs and traits',
            'description' => 'Access large-scale maize SNP resources, diversity panels, trait associations, and analysis tools for genomic variation.',
            'best_for' => 'Population variation and trait-linked SNPs',
            'url' => '/genetic_variation',
            'search' => 'snps traits gwas genomic variation accessions diversity association'
        ),
        array(
            'category' => 'genomes-variation',
            'category_label' => 'Genomes & variation',
            'icon' => 'INS',
            'name' => 'Insertions',
            'description' => 'Search transposon and insertion records by gene, transcript, genomic position, collection, and supporting evidence.',
            'best_for' => 'Insertion lines and gene disruptions',
            'url' => '/insertion',
            'search' => 'insertions transposons UniformMu RescueMu gene disruptions'
        ),
        array(
            'category' => 'genomes-variation',
            'category_label' => 'Genomes & variation',
            'icon' => 'MAP',
            'name' => 'Maps',
            'description' => 'Browse genetic, physical, cytological, and other maps with mapped loci, markers, scores, and related populations.',
            'best_for' => 'Map positions and marker order',
            'url' => '/data_center/map',
            'search' => 'maps genetic physical cytological positions markers populations'
        ),
        array(
            'category' => 'genes-function',
            'category_label' => 'Genes & function',
            'icon' => 'GM',
            'name' => 'Genes and gene models',
            'description' => 'Search biological genes and annotation-version-specific gene models, then follow synonyms, coordinates, function, expression, and evidence.',
            'best_for' => 'Gene symbols and annotation identifiers',
            'url' => '/gene_center/gene',
            'search' => 'genes gene models transcripts proteins annotation identifiers B73'
        ),
        array(
            'category' => 'genes-function',
            'category_label' => 'Genes & function',
            'icon' => 'PAN',
            'name' => 'Pan-genes',
            'description' => 'Follow homologous gene groups across maize genomes and inspect membership, distributions, tandem relationships, and annotations.',
            'best_for' => 'Cross-genome gene families',
            'url' => '/pan_gene_center/pan_gene',
            'search' => 'pan genes pangenes homologs orthologs gene families genomes'
        ),
        array(
            'category' => 'genes-function',
            'category_label' => 'Genes & function',
            'icon' => 'GO',
            'name' => 'Gene products',
            'description' => 'Browse curated gene-product names, functions, ontology terms, associated loci, and literature evidence.',
            'best_for' => 'Curated molecular functions and products',
            'url' => '/data_center/gene_product',
            'search' => 'gene product function ontology GO biochemical molecular'
        ),
        array(
            'category' => 'genes-function',
            'category_label' => 'Genes & function',
            'icon' => 'EXP',
            'name' => 'Expression',
            'description' => 'Search and compare expression profiles across tissues, development, genotypes, and experimental conditions.',
            'best_for' => 'When and where genes are expressed',
            'url' => '/expression',
            'search' => 'expression RNA-seq transcriptomics tissues conditions experiments'
        ),
        array(
            'category' => 'genes-function',
            'category_label' => 'Genes & function',
            'icon' => 'PATH',
            'name' => 'Metabolic pathways',
            'description' => 'Move from genes and enzymes to curated and predicted maize metabolic reactions and pathway context.',
            'best_for' => 'Enzymes, reactions, and pathways',
            'url' => '/metabolic_pathways/',
            'search' => 'metabolic pathways enzymes reactions CornCyc metabolism'
        ),
        array(
            'category' => 'genes-function',
            'category_label' => 'Genes & function',
            'icon' => '3D',
            'name' => 'Protein structure',
            'description' => 'Search predicted protein structures and compare sequence- and structure-based ortholog evidence with Foldseek and FATCAT.',
            'best_for' => 'Predicted structures and structural homologs',
            'url' => '/data_center/protein_structure',
            'search' => 'protein structures AlphaFold ESMFold Foldseek FATCAT orthologs'
        ),
        array(
            'category' => 'genes-function',
            'category_label' => 'Genes & function',
            'icon' => 'AI',
            'name' => 'Artificial intelligence',
            'description' => 'Explore MaizeGDB AI tools, model-ready features, embeddings, predicted effects, structures, source code, and publications.',
            'best_for' => 'AI-ready genomics and model features',
            'url' => '/ai',
            'search' => 'artificial intelligence machine learning embeddings feature vectors SNPTools'
        ),
        array(
            'category' => 'phenotype-germplasm',
            'category_label' => 'Phenotype & germplasm',
            'icon' => 'LOC',
            'name' => 'Loci',
            'description' => 'Search classical loci, connecting names and synonyms to maps, traits, phenotypes, stocks, and references.',
            'best_for' => 'Named biological loci',
            'url' => '/data_center/locus',
            'search' => 'loci locus genes coordinates maps synonyms classical'
        ),
        array(
            'category' => 'phenotype-germplasm',
            'category_label' => 'Phenotype & germplasm',
            'icon' => 'QTL',
            'name' => 'QTL',
            'description' => 'Search quantitative trait loci by trait, experiment, map, and population, with the linkage evidence and publications behind each.',
            'best_for' => 'Trait mapping experiments and QTL intervals',
            'url' => '/data_center/qtl',
            'search' => 'qtl quantitative trait loci mapping experiments linkage populations lod'
        ),
        array(
            'category' => 'phenotype-germplasm',
            'category_label' => 'Phenotype & germplasm',
            'icon' => 'PH',
            'name' => 'Mutants and phenotypes',
            'description' => 'Explore phenotype descriptions, traits, body parts, variations, stocks, images, and literature relationships.',
            'best_for' => 'Visible traits and mutant effects',
            'url' => '/data_center/phenotype',
            'search' => 'mutants phenotypes traits body parts variations images stocks'
        ),
        array(
            'category' => 'phenotype-germplasm',
            'category_label' => 'Phenotype & germplasm',
            'icon' => 'STK',
            'name' => 'Stocks and germplasm',
            'description' => 'Find maize germplasm, pedigrees, genotypes, phenotypes, availability, and Stock Center ordering information.',
            'best_for' => 'Germplasm and material availability',
            'url' => '/data_center/stock',
            'search' => 'stocks germplasm pedigrees genotypes availability order stock center'
        ),
        array(
            'category' => 'phenotype-germplasm',
            'category_label' => 'Phenotype & germplasm',
            'icon' => 'MRK',
            'name' => 'Molecular markers & probes',
            'description' => 'Search probes and markers, assay details, sequences, map positions, detected loci, and linked publications.',
            'best_for' => 'Marker names, assays, and positions',
            'url' => '/data_center/marker',
            'search' => 'molecular markers probes SSR overgo assays sequences positions'
        ),
        array(
            'category' => 'literature-media',
            'category_label' => 'Literature & media',
            'icon' => 'IMG',
            'name' => 'Images',
            'description' => 'Explore over 113,000 mutant ear specimens, gel patterns, stock photos, teosinte species, and anatomical traits.',
            'best_for' => 'Visual phenotype and research media',
            'url' => '/data_center/image',
            'search' => 'images photos phenotype mutant trait pest gel historical'
        ),
        array(
            'category' => 'literature-media',
            'category_label' => 'Literature & media',
            'icon' => 'REF',
            'name' => 'References',
            'description' => 'Search curated maize literature by topic, author, year, identifier, or related biological record; visualize trends and export citations.',
            'best_for' => 'Papers, DOIs, PubMed IDs, and evidence',
            'url' => '/data_center/reference',
            'search' => 'references papers literature citations DOI PubMed authors years'
        )
    );
}
?>
