<?php
/* file: include/fusarium_page.php
 *
 * purpose: One page of /fusarium in the toolkit's own shell. Every
 *          controllers/fusarium/*.php starts here, so the header, navigation,
 *          footer, stylesheets and scripts are set in one place.
 *
 * The shell is templates/fusarium/fpt_shell.bau, not the MaizeGDB modern
 * shell: the toolkit keeps its own name, logo and navigation. Everything
 * inside it is the MaizeGDB design system -- mgdb-modern.css, the Data Hub
 * shell in mgdb-hub.css, the same components and scripts -- and
 * css/mgdb-fusarium.css, loaded last, carries only the toolkit's chrome and
 * the parts of its pages no MaizeGDB page has.
 */

include_once('./include/fusarium_lib.php');
include_once('./include/references_lib.php');

function fptAssetStamp($path) {
    $root = (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '')
          ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : '/var/www/claude/html';
    $file = $root . $path;
    return file_exists($file) ? filemtime($file) : time();
}

/* $opts:
     title        the <title>
     nav          which navigation item is current
     template     the page body, under templates/fusarium/
     description  the meta description
     css, js      page assets, in order, after the shared ones
     noindex      for the 404
   Returns array($bauplan, $content). */
function fptBeginPage(array $opts) {
    $bauplan = new Bauplan($opts['title']);
    $bauplan->modern();
    $bauplan->bodyClass('fpt-site');
    $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');

    $bauplan->includeCss('/css/mgdb-modern.css');
    $bauplan->includeCss('/css/mgdb-hub.css?v=' . fptAssetStamp('/css/mgdb-hub.css'));
    foreach (isset($opts['css']) ? $opts['css'] : array() as $css) {
        $bauplan->includeCss($css . '?v=' . fptAssetStamp($css));
    }
    $bauplan->includeCss('/css/mgdb-fusarium.css?v=' . fptAssetStamp('/css/mgdb-fusarium.css'));

    $bauplan->includeScript('/js/mgdb-modern.js');
    foreach (isset($opts['js']) ? $opts['js'] : array() as $js) {
        $bauplan->includeScript(strpos($js, '//') !== false ? $js : $js . '?v=' . fptAssetStamp($js));
    }

    if (!empty($opts['description'])) {
        $bauplan->head('<meta name="description" content="' . fptEsc($opts['description']) . '">');
    }
    if (!empty($opts['noindex'])) {
        $bauplan->head('<meta name="robots" content="noindex">');
    }

    $shell = $bauplan->template()->load('templates/fusarium/fpt_shell.bau');
    $shell->get('nav_items')->replace(fptNavMarkup(isset($opts['nav']) ? $opts['nav'] : ''));
    $content = $shell->get('body')->load($opts['template']);
    return array($bauplan, $content);
}

/* Numbers the pages print, from summary.json. Blank -- never a remembered
   number -- when the file is missing. */
function fptCount($value) {
    return is_numeric($value) ? number_format((float) $value) : '';
}

/* The papers the toolkit's pages cite, by key. The first two are in the
   curated bibliography (data/cite_journal_articles.json); every other record
   is supplied here, each field checked at Crossref and PubMed on 2026-09-25. */
function fptReference($key) {
    $refs = array(
        'fpt'       => array('doi' => '10.1101/2024.04.30.591916'),
        'paneffect' => array('doi' => '10.1093/bioinformatics/btae073'),
        'foldseek'  => array('doi' => '10.1038/s41587-023-01773-0', 'fallback' => array(
            'title' => 'Fast and accurate protein structure search with Foldseek',
            'authors' => 'van Kempen M, Kim SS, Tumescheit C, Mirdita M, Lee J, Gilchrist CLM, Söding J, Steinegger M',
            'journal' => 'Nature Biotechnology', 'year' => 2024, 'volume' => '42', 'pages' => '243-246',
            'pubmed' => '37156916')),
        'alphafold' => array('doi' => '10.1038/s41586-021-03819-2', 'fallback' => array(
            'title' => 'Highly accurate protein structure prediction with AlphaFold',
            'authors' => 'Jumper J, Evans R, Pritzel A, Green T, Figurnov M, Ronneberger O, Tunyasuvunakool K, Bates R, Žídek A, Potapenko A, Bridgland A, Meyer C, Kohl SAA, Ballard AJ, Cowie A, Romera-Paredes B, Nikolov S, Jain R, Adler J, Back T, Petersen S, Reiman D, Clancy E, Zielinski M, Steinegger M, Pacholska M, Berghammer T, Bodenstein S, Silver D, Vinyals O, Senior AW, Kavukcuoglu K, Kohli P, Hassabis D',
            'journal' => 'Nature', 'year' => 2021, 'volume' => '596', 'pages' => '583-589',
            'pubmed' => '34265844')),
        'afdb'      => array('doi' => '10.1093/nar/gkab1061', 'fallback' => array(
            'title' => 'AlphaFold Protein Structure Database: massively expanding the structural coverage of protein-sequence space with high-accuracy models',
            'authors' => 'Varadi M, Anyango S, Deshpande M, Nair S, Natassia C, Yordanova G, Yuan D, Stroe O, Wood G, Laydon A, Žídek A, Green T, Tunyasuvunakool K, Petersen S, Jumper J, Clancy E, Green R, Vora A, Lutfi M, Figurnov M, Cowie A, Hobbs N, Kohli P, Kleywegt G, Birney E, Hassabis D, Velankar S',
            'journal' => 'Nucleic Acids Research', 'year' => 2022, 'volume' => '50', 'pages' => 'D439-D444',
            'pubmed' => '34791371')),
        'esmfold'   => array('doi' => '10.1126/science.ade2574', 'fallback' => array(
            'title' => 'Evolutionary-scale prediction of atomic-level protein structure with a language model',
            'authors' => 'Lin Z, Akin H, Rao R, Hie B, Zhu Z, Lu W, Smetanin N, Verkuil R, Kabeli O, Shmueli Y, dos Santos Costa A, Fazel-Zarandi M, Sercu T, Candido S, Rives A',
            'journal' => 'Science', 'year' => 2023, 'volume' => '379', 'pages' => '1123-1130',
            'pubmed' => '36927031')),
        'tmalign'   => array('doi' => '10.1093/nar/gki524', 'fallback' => array(
            'title' => 'TM-align: a protein structure alignment algorithm based on the TM-score',
            'authors' => 'Zhang Y, Skolnick J',
            'journal' => 'Nucleic Acids Research', 'year' => 2005, 'volume' => '33', 'pages' => '2302-2309',
            'pubmed' => '15849316')),
        'effectorp' => array('doi' => '10.1094/MPMI-08-21-0201-R', 'fallback' => array(
            'title' => 'EffectorP 3.0: Prediction of Apoplastic and Cytoplasmic Effectors in Fungi and Oomycetes',
            'authors' => 'Sperschneider J, Dodds PN',
            'journal' => 'Molecular Plant-Microbe Interactions', 'year' => 2022, 'volume' => '35', 'pages' => '146-156',
            'pubmed' => '34698534')),
        'secretsanta' => array('doi' => '10.1093/bioinformatics/bty088', 'fallback' => array(
            'title' => 'SecretSanta: flexible pipelines for functional secretome prediction',
            'authors' => 'Gogleva A, Drost HG, Schornack S',
            'journal' => 'Bioinformatics', 'year' => 2018, 'volume' => '34', 'pages' => '2295-2296',
            'pubmed' => '29462238')),
        'localizer' => array('doi' => '10.1038/srep44598', 'fallback' => array(
            'title' => 'LOCALIZER: subcellular localization prediction of both plant and effector proteins in the plant cell',
            'authors' => 'Sperschneider J, Catanzariti AM, DeBoer K, Petre B, Gardiner DM, Singh KB, Dodds PN, Taylor JM',
            'journal' => 'Scientific Reports', 'year' => 2017, 'volume' => '7', 'pages' => '44598',
            'pubmed' => '28300209')),
        'orthofinder' => array('doi' => '10.1186/s13059-019-1832-y', 'fallback' => array(
            'title' => 'OrthoFinder: phylogenetic orthology inference for comparative genomics',
            'authors' => 'Emms DM, Kelly S',
            'journal' => 'Genome Biology', 'year' => 2019, 'volume' => '20', 'pages' => '238',
            'pubmed' => '31727128')),
        'esm1b'     => array('doi' => '10.1038/s41588-023-01465-0', 'fallback' => array(
            'title' => 'Genome-wide prediction of disease variant effects with a deep protein language model',
            'authors' => 'Brandes N, Goldman G, Wang CH, Ye CJ, Ntranos V',
            'journal' => 'Nature Genetics', 'year' => 2023, 'volume' => '55', 'pages' => '1512-1522',
            'pubmed' => '37563329')),
    );
    return $refs[$key];
}

function fptReferenceCards(array $keys) {
    $root = (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '')
          ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
    return mgdb_render_references($root, array_map('fptReference', $keys));
}

/* The replacements every page makes. */
function fptCommon($content) {
    foreach (array('snptools_url' => FPT_SNPTOOLS, 'paneffect_url' => FPT_PANEFFECT) as $name => $value) {
        if ($content->has($name)) { $content->get($name)->replace(fptEsc($value)); }
    }
}
?>
