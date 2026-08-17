<?php
/* Protein structure search result fragment. */

include_once('../lib/Bauplan.php');
include_once('../include/db-api.php');
include_once('../include/api_tools.php');
include_once('../include/gp_lib.php');
include_once('../include/data_center_functions.php');

function psEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function psGeneLinks($csv) {
    $links = array();
    foreach (explode(',', (string)$csv) as $id) {
        $id = trim($id);
        if ($id === '') continue;
        $safe = psEscape($id);
        $links[] = '<a href="/gene_center/gene/' . rawurlencode($id) . '">' . $safe . '</a>';
    }
    return $links ? implode(', ', $links) : 'NA';
}

function psOverviewItem($label, $value) {
    echo '<div class="ps-overview-item"><b>' . psEscape($label) . '</b>' . $value . '</div>';
}

$term = trim((string)getCGIParam('term', 'G', false));
$tool = strtolower(trim((string)getCGIParam('tool', 'G', false)));
if (!in_array($tool, array('alphafold', 'esmfold'), true)) $tool = 'alphafold';

if ($term === '' || !preg_match('/^[A-Za-z0-9_.:-]+$/', $term)) {
    echo '<div class="ps-empty">Enter a valid maize gene, protein, or UniProt identifier.</div>';
    exit;
}

$uniprot = '';
$v5Id = '';
$v4Id = '';
$geneId = '';
$description = '';
$protein = '';
$DBConn = connect_to_database();

// UniProt accessions can be used directly for AlphaFold. For genes and loci,
// resolve the current B73 model and linked UniProt accession from MaizeGDB.
if ($tool === 'alphafold' && (preg_match('/^[OPQ][0-9][A-Z0-9]{3}[0-9]$/i', $term)
    || preg_match('/^[A-NR-Z][0-9][A-Z][A-Z0-9]{2}[0-9]$/i', $term)
    || preg_match('/^[A-NR-Z0-9]{10}$/i', $term))) {
    $uniprot = strtoupper($term);
} else {
    $geneSql = "
      SELECT gene_name, protein, locus_name, locus_id, version
      FROM chado.gene_model
      WHERE lower(gene_name)=lower(:term) OR lower(protein)=lower(:term)
            OR lower(locus_name)=lower(:term) OR lower(locus_full_name)=lower(:term)
      ORDER BY (gene_name LIKE 'Zm00001eb%') DESC, version DESC
      LIMIT 1";
    $geneStmt = $DBConn->prepare($geneSql);
    $geneStmt->execute(array(':term'=>$term));
    $geneRow = $geneStmt->fetch(PDO::FETCH_ASSOC);

    if ($geneRow) {
        $geneId = trim((string)$geneRow['locus_name']);
        $protein = trim((string)$geneRow['protein']);
        if (strpos($geneRow['gene_name'], 'Zm00001eb') === 0) $v5Id = trim($geneRow['gene_name']);
        if (strpos($geneRow['gene_name'], 'Zm00001d') === 0) $v4Id = trim($geneRow['gene_name']);
        if (!empty($geneRow['locus_id'])) {
            $uniprotSql = "
              SELECT x.key
              FROM mgdb.ext_db_key x JOIN mgdb.person p ON p.id=x.db_person
              WHERE x.id=:locus AND p.name='UniProt'
              ORDER BY x.key LIMIT 1";
            $uniprotStmt = $DBConn->prepare($uniprotSql);
            $uniprotStmt->execute(array(':locus'=>$geneRow['locus_id']));
            $uniprotRow = $uniprotStmt->fetch(PDO::FETCH_ASSOC);
            if ($uniprotRow) $uniprot = trim($uniprotRow['key']);
        }
    }
}

$complexAlias = null;
if ($uniprot === '' || $v5Id === '' || $description === '') {
    $aliasFile = dirname(__DIR__) . '/data/protein_complex/aliases/' . substr(sha1(strtolower($term)), 0, 2) . '.json';
    if (is_file($aliasFile)) {
        $aliasShard = json_decode(file_get_contents($aliasFile), true);
        if (isset($aliasShard[strtolower($term)])) $complexAlias = $aliasShard[strtolower($term)];
    }
    if ($complexAlias) {
        if ($uniprot === '' && !empty($complexAlias['uniprots'][0])) $uniprot = $complexAlias['uniprots'][0];
        if ($v5Id === '' && !empty($complexAlias['gene_ids'])) $v5Id = implode(',', $complexAlias['gene_ids']);
        if ($geneId === '' && !empty($complexAlias['symbols'][0])) $geneId = $complexAlias['symbols'][0];
    }
}

$firstV5 = trim(strtok($v5Id, ','));

if ($tool === 'alphafold') {
    if ($uniprot === '') {
        echo '<div class="ps-empty">No AlphaFold model is associated with <b>' . psEscape($term) . '</b>.</div>';
        exit;
    }
    $modelUrl = 'https://alphafold.ebi.ac.uk/files/AF-' . rawurlencode($uniprot) . '-F1-model_v6.pdb';
    $modelName = 'AF-' . $uniprot . '-F1-model_v6';
} else {
    if ($protein === '' && $firstV5 !== '') {
        $safeV5 = str_replace("'", "''", $firstV5);
        $stmt = make_query($DBConn, "select protein from chado.gene_model where gene_name = '" . $safeV5 . "'");
        $row = retrieve_row($stmt);
        if ($row && isset($row['protein'])) $protein = trim($row['protein']);
    }
    if ($protein === '') {
        echo '<div class="ps-empty">No ESMFold model is associated with <b>' . psEscape($term) . '</b>.</div>';
        exit;
    }
    $modelUrl = 'https://images.maizegdb.org/esm/b73/' . rawurlencode($protein) . '.pdb';
    $modelName = $protein;
}

$viewportId = 'viewport_' . $tool;
?>
<div class="ps-viewer-shell">
  <div class="ps-viewer-toolbar" aria-label="Protein viewer controls">
    <select class="ps-viewer-select" data-viewer-style aria-label="Molecular style"><option value="cartoon">Cartoon</option><option value="surface">Surface</option><option value="ball+stick">Ball and stick</option><option value="backbone">Backbone</option></select>
    <select class="ps-viewer-select" data-viewer-color aria-label="Color scheme"><option value="confidence">Color: confidence</option><option value="chainname">Color: chain</option><option value="element">Color: element</option></select>
    <button class="ps-viewer-button" type="button" data-viewer-spin>Auto-rotate</button>
    <button class="ps-viewer-button" type="button" data-viewer-reset>Reset view</button>
    <span class="ps-viewer-spacer"></span>
    <button class="ps-viewer-button" type="button" data-viewer-image>Save PNG</button>
    <a class="ps-viewer-button" data-viewer-download href="<?php echo psEscape($modelUrl); ?>" download>Download PDB</a>
    <button class="ps-viewer-button" type="button" data-viewer-fullscreen>Fullscreen</button>
  </div>
  <div id="<?php echo $viewportId; ?>" class="protein-viewer" data-structure-url="<?php echo psEscape($modelUrl); ?>" data-structure-name="<?php echo psEscape($modelName); ?>"></div>
  <div class="ps-viewer-status" data-viewer-status>Loading structure…</div>
</div>

<div class="ps-overview">
<?php
if ($uniprot !== '') {
    psOverviewItem('UniProt ID', '<a target="_blank" rel="noopener" href="https://www.uniprot.org/uniprotkb/' . rawurlencode($uniprot) . '">' . psEscape($uniprot) . '</a>');
} else {
    psOverviewItem('UniProt ID', 'NA');
}
psOverviewItem('Description', $description !== '' ? psEscape($description) : 'NA');
if ($tool === 'alphafold') {
    psOverviewItem('AlphaFold entry', '<a target="_blank" rel="noopener" href="https://alphafold.ebi.ac.uk/entry/' . rawurlencode($uniprot) . '">' . psEscape($uniprot) . '</a>');
} else {
    psOverviewItem('Protein', '<a href="/gene_center/gene/' . rawurlencode($protein) . '">' . psEscape($protein) . '</a>');
}
psOverviewItem('Gene annotation', $geneId !== '' ? psEscape($geneId) : 'NA');
psOverviewItem('B73 version 5', psGeneLinks($v5Id));
psOverviewItem('B73 version 4', psGeneLinks($v4Id));
?>
</div>

<?php if ($firstV5 !== ''): ?>
<div class="ps-genome">
  <h4>Genome context</h4>
  <iframe title="Genome context for <?php echo psEscape($firstV5); ?>" loading="lazy" src="https://jbrowse.maizegdb.org/?loc=<?php echo rawurlencode($firstV5); ?>&amp;tracks=gene_models_official%2C<?php echo $tool; ?>&amp;tracklist=0&amp;nav=0&amp;overview=0"></iframe>
</div>
<?php endif; ?>
