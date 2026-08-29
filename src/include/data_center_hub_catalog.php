<?php
/* file: data_center_hub_catalog.php
 *
 * purpose: Catalog and metrics resolver for the MaizeGDB Data Hubs (/data_center/).
 *          Defines active data hubs, category groupings, guided paths, and
 *          resolves live statistics from the PostgreSQL database.
 */

function getDataCenterHubMetrics($DBConn) {
    // Defaults based on latest curation
    $stats = array(
        'genomes' => 162,
        'gene_models' => 1878909,
        'pan_genes' => 97184,
        'stocks' => 87397,
        'variations' => 1710428,
        'loci' => 790110,
        'markers' => 780086,
        'images' => 113904,
        'references' => 55089,
        'structures' => 1000000,
        'phenotypes' => 1208,
        'pathways' => 650
    );

    if ($DBConn) {
        try {
            $q = retrieve_row(make_query($DBConn, "SELECT COUNT(*) FROM variation"));
            if ($q && isset($q['count'])) $stats['variations'] = (int) $q['count'];

            $q = retrieve_row(make_query($DBConn, "SELECT COUNT(*) FROM locus"));
            if ($q && isset($q['count'])) $stats['loci'] = (int) $q['count'];

            $q = retrieve_row(make_query($DBConn, "SELECT COUNT(*) FROM stock"));
            if ($q && isset($q['count'])) $stats['stocks'] = (int) $q['count'];

            $q = retrieve_row(make_query($DBConn, "SELECT COUNT(*) FROM probe"));
            if ($q && isset($q['count'])) $stats['markers'] = (int) $q['count'];

            $q = retrieve_row(make_query($DBConn, "SELECT COUNT(*) FROM reference"));
            if ($q && isset($q['count'])) $stats['references'] = (int) $q['count'];

            $q = retrieve_row(make_query($DBConn, "SELECT COUNT(*) FROM web_image WHERE (curation_lvl = 0 OR curation_lvl IS NULL) AND url IS NOT NULL AND url != ''"));
            if ($q && isset($q['count'])) $stats['images'] = (int) $q['count'];

            $q = retrieve_row(make_query($DBConn, "SELECT COUNT(*) FROM phenotype"));
            if ($q && isset($q['count'])) $stats['phenotypes'] = (int) $q['count'];
        } catch (Exception $e) {
            // Keep default values on error
        }
    }

    return array(
        array(
            'value' => '162',
            'label' => 'Genome assemblies',
            'detail' => 'reference, diversity, and project assemblies',
            'icon' => 'Chr',
            'tone' => 'green',
            'chart_value' => '162',
            'chart_label' => 'Genome assemblies'
        ),
        array(
            'value' => '1.88M',
            'label' => 'Gene-model annotations',
            'detail' => 'searchable across maize assemblies',
            'icon' => 'GM',
            'tone' => 'blue',
            'chart_value' => '1878909',
            'chart_label' => 'Gene models'
        ),
        array(
            'value' => '97K',
            'label' => 'Pan-gene groups',
            'detail' => 'connecting annotations across genomes',
            'icon' => 'Pan',
            'tone' => 'teal',
            'chart_value' => '97184',
            'chart_label' => 'Pan-gene groups'
        ),
        array(
            'value' => number_format(round($stats['stocks'] / 1000)) . 'K',
            'label' => 'Stock records',
            'detail' => 'germplasm, mutants, and genetic stocks',
            'icon' => 'Stk',
            'tone' => 'gold',
            'chart_value' => (string) $stats['stocks'],
            'chart_label' => 'Stock records'
        ),
        array(
            'value' => number_format($stats['variations'] / 1000000, 2) . 'M',
            'label' => 'Variation records',
            'detail' => 'curated alleles and polymorphisms',
            'icon' => 'Var',
            'tone' => 'blue',
            'chart_value' => (string) $stats['variations'],
            'chart_label' => 'Variation records'
        ),
        array(
            'value' => number_format(round($stats['loci'] / 1000)) . 'K',
            'label' => 'Locus records',
            'detail' => 'genes, loci, QTL, and linked evidence',
            'icon' => 'Loc',
            'tone' => 'green',
            'chart_value' => (string) $stats['loci'],
            'chart_label' => 'Locus records'
        ),
        array(
            'value' => number_format(round($stats['markers'] / 1000)) . 'K',
            'label' => 'Molecular markers & probes',
            'detail' => 'SSRs, RFLPs, BACs, and primers',
            'icon' => 'Mrk',
            'tone' => 'teal',
            'chart_value' => (string) $stats['markers'],
            'chart_label' => 'Molecular markers'
        ),
        array(
            'value' => number_format(round($stats['images'] / 1000)) . 'K',
            'label' => 'Curated images',
            'detail' => 'mutant ears, gel assays, and specimens',
            'icon' => 'Img',
            'tone' => 'gold',
            'chart_value' => (string) $stats['images'],
            'chart_label' => 'Curated images'
        ),
        array(
            'value' => number_format($stats['references'] / 1000, 1) . 'K',
            'label' => 'Curated references',
            'detail' => 'literature linked to biological records',
            'icon' => 'Ref',
            'tone' => 'green',
            'chart_value' => (string) $stats['references'],
            'chart_label' => 'References'
        ),
        array(
            'value' => '1M+',
            'label' => 'Predicted structures',
            'detail' => 'maize proteins across multiple genomes',
            'icon' => '3D',
            'tone' => 'blue',
            'chart_value' => '1000000',
            'chart_label' => 'Protein structures'
        )
    );
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
            'icon' => 'QTL',
            'name' => 'Loci and QTL',
            'description' => 'Search classical loci and quantitative trait loci, connecting names and synonyms to maps, traits, phenotypes, stocks, and references.',
            'best_for' => 'Biological loci and trait regions',
            'url' => '/data_center/locus',
            'search' => 'loci QTL quantitative trait loci genes coordinates maps'
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
