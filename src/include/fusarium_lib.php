<?php
/* file: include/fusarium_lib.php
 *
 * purpose: Shared reads for /fusarium, the Fusarium Protein Toolkit on
 *          MaizeGDB: which species it covers, where their structure models
 *          are, how an identifier becomes a protein, and the small data files
 *          its pages quote.
 *
 * Where everything lives
 * ----------------------
 *   data/fusarium/proteins.sqlite   every protein with a structure model --
 *                                   108,965 of them over six species -- with
 *                                   its gene ids, UniProt name and length.
 *                                   Out of band, like data/alphafill/; written
 *                                   by tools/fusarium_index.py. Nothing here
 *                                   fails without it: a lookup finds nothing
 *                                   and the pages say the index is missing.
 *   data/fusarium/effectors.json    the predicted effector table
 *   data/fusarium/genomes.json      the 22 pan-genome species
 *   data/fusarium/summary.json      the counts the pages print
 *
 * The models themselves stay where the toolkit published them, on
 * fusarium.maizegdb.org: one AlphaFold directory and one ESMFold directory per
 * species. That host sends Access-Control-Allow-Origin for claude.maizegdb.org
 * and www.maizegdb.org -- and for no other origin, measured 2026-09-25 -- so
 * the browser fetches them directly, the same way the Protein Structure Hub
 * reads AlphaFold DB. A move to any other host needs that list extended.
 *
 * Query cost
 * ----------
 * No SQL against the MaizeGDB database anywhere. A lookup is one or two
 * primary-key reads of a local SQLite file, well under a millisecond; a
 * suggestion is one range scan of the alias table's primary key.
 */

const FPT_HOST      = 'https://fusarium.maizegdb.org';
const FPT_SNPTOOLS  = 'https://fusarium-snptools.maizegdb.org/';
const FPT_PANEFFECT = 'https://www.maizegdb.org/effect/fusarium/index.php';
const FPT_ROUTE     = '/fusarium';

/* Identifier-shaped input only. Wider than any real identifier on purpose:
   it decides what is safe to look up, not what exists. */
const FPT_TERM_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

/* The six species with structures, in the order the toolkit lists them. The
   same table as SPECIES in tools/fusarium_index.py; the builder writes these
   keys into every row it stores. `query` marks the two species whose proteins
   were searched with Foldseek -- the other four appear only as matches --
   and `paneffect` the two with per-gene PanEffect data. */
function fptSpecies() {
    static $species = null;
    if ($species !== null) { return $species; }
    $species = array(
        'graminearum'     => array('label' => 'F. graminearum',     'latin' => 'Fusarium graminearum',
                                   'strain' => 'PH-1',      'query' => true,  'paneffect' => true),
        'verticillioides' => array('label' => 'F. verticillioides', 'latin' => 'Fusarium verticillioides',
                                   'strain' => '7600',      'query' => true,  'paneffect' => true),
        'fujikuroi'       => array('label' => 'F. fujikuroi',       'latin' => 'Fusarium fujikuroi',
                                   'strain' => 'IMI 58289', 'query' => false, 'paneffect' => false),
        'oxysporum'       => array('label' => 'F. oxysporum',       'latin' => 'Fusarium oxysporum f. sp. lycopersici',
                                   'strain' => '4287',      'query' => false, 'paneffect' => false),
        'proliferatum'    => array('label' => 'F. proliferatum',    'latin' => 'Fusarium proliferatum',
                                   'strain' => 'ET1',       'query' => false, 'paneffect' => false),
        'solani'          => array('label' => 'F. solani',          'latin' => 'Fusarium vanettenii',
                                   'strain' => '77-13-4',   'query' => false, 'paneffect' => false),
    );
    foreach ($species as $key => &$meta) { $meta['key'] = $key; }
    unset($meta);
    return $species;
}

function fptSpeciesMeta($key) {
    $all = fptSpecies();
    return isset($all[$key]) ? $all[$key] : null;
}

function fptEsc($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function fptValidTerm($term) {
    return preg_match(FPT_TERM_PATTERN, (string) $term) === 1;
}

/* -------------------------------------------------------------------------- *
 * Files
 * -------------------------------------------------------------------------- */

function fptDataDir() {
    $root = (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '')
          ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : realpath(__DIR__ . '/..');
    return $root . '/data/fusarium';
}

/* One decode per file per request. */
function fptReadJson($name) {
    static $cache = array();
    if (array_key_exists($name, $cache)) { return $cache[$name]; }
    $file = fptDataDir() . '/' . $name;
    $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
    return $cache[$name] = is_array($data) ? $data : null;
}

function fptSummary()   { return fptReadJson('summary.json'); }
function fptEffectors() { return fptReadJson('effectors.json'); }
function fptGenomes()   { return fptReadJson('genomes.json'); }

/* The mtime of every data file a page renders from, for cache keys and
   cache-busting query strings. */
function fptDataStamp() {
    $stamp = 0;
    foreach (array('summary.json', 'effectors.json', 'genomes.json', 'proteins.sqlite') as $name) {
        $stamp = max($stamp, (int) @filemtime(fptDataDir() . '/' . $name));
    }
    return $stamp;
}

/* -------------------------------------------------------------------------- *
 * The protein index
 * -------------------------------------------------------------------------- */

function fptOpen() {
    static $db = false;
    if ($db !== false) { return $db; }
    $db = null;
    $path = fptDataDir() . '/proteins.sqlite';
    if (!class_exists('SQLite3') || !is_file($path)) { return null; }
    try {
        $handle = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $handle->enableExceptions(true);
        $handle->busyTimeout(1000);
        $handle->exec('PRAGMA query_only = 1');
        if ($handle->querySingle("SELECT v FROM meta WHERE k = 'built'") === null) { return null; }
        $db = $handle;
    } catch (Exception $e) {
        if (function_exists('logMessage')) { logMessage('Fusarium protein index unavailable: ' . $e->getMessage()); }
    }
    return $db;
}

function fptIndexMeta() {
    static $meta = null;
    if ($meta !== null) { return $meta; }
    $meta = array();
    $db = fptOpen();
    if (!$db) { return $meta; }
    $res = $db->query('SELECT k, v FROM meta');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $meta[$row['k']] = $row['v']; }
    return $meta;
}

/* The key a typed identifier is stored under: upper case, with an AlphaFold
   file or entry name reduced to its accession and a UniProt isoform suffix
   dropped. */
function fptNormalize($term) {
    $term = strtoupper(trim((string) $term));
    if (preg_match('/^AF-([A-Z0-9]{6,10})-F\d+(?:-MODEL_V\d+)?(?:\.(?:PDB|CIF))?$/', $term, $m)) {
        return $m[1];
    }
    if (preg_match('/^([A-Z0-9]{6,10})-\d{1,2}$/', $term, $m)) { return $m[1]; }
    return $term;
}

/* Whether fusarium.maizegdb.org serves a species' model files one at a time.
   Listed is not served: on 2026-09-25 four of the six ESMFold directories
   answered 403 for every file, though the same models are in the toolkit's
   download archive. tools/fusarium_index.py probes each directory and records
   the answer in summary.json; the index's af/esm flags already account for
   it, and this is what lets a page say why a model is missing. */
function fptServed($species, $tool) {
    $summary = fptSummary();
    foreach ($summary ? $summary['species'] : array() as $sp) {
        if ($sp['key'] === $species) {
            return !isset($sp['served'][$tool]) || (bool) $sp['served'][$tool];
        }
    }
    return true;
}

function fptRow(array $row) {
    $species = fptSpeciesMeta($row['sp']);
    $genes = $row['genes'] !== '' ? explode(' ', $row['genes']) : array();
    $symbols = $row['symbols'] !== '' ? explode(' ', $row['symbols']) : array();
    return array(
        'accession' => $row['acc'],
        'species'   => $row['sp'],
        'species_label' => $species ? $species['label'] : $row['sp'],
        'genes'     => $genes,
        'symbols'   => $symbols,
        'name'      => $row['name'] !== '' ? $row['name'] : null,
        'length'    => $row['len'] !== null ? (int) $row['len'] : null,
        'alphafold' => (bool) $row['af'],
        'esmfold'   => (bool) $row['esm'],
        /* The model exists but only in the download archive. */
        'esmfold_archive_only' => !$row['esm'] && !fptServed($row['sp'], 'esmfold'),
        'in_uniprotkb' => (bool) $row['active'],
        'effector'  => (bool) $row['effector'],
        'foldseek'  => (bool) ($row['af'] && $species && $species['query']),
        'paneffect' => (bool) ($species && $species['paneffect']),
    );
}

/* One protein by accession, or null. */
function fptProtein($accession) {
    $db = fptOpen();
    if (!$db) { return null; }
    $stmt = $db->prepare('SELECT * FROM protein WHERE acc = :acc');
    $stmt->bindValue(':acc', strtoupper((string) $accession), SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ? fptRow($row) : null;
}

/* Every protein an identifier names, best first. A gene id can name two:
   F. oxysporum's FOXG genes were entered in UniProt twice (A0A0D2 and
   A0A0J9), and 12,534 ids across the six species name more than one model.
   Order: an entry UniProtKB still has, then one with a functional name, then
   the longer -- so the protein a reader most likely means comes first and the
   others are offered beside it. */
function fptLookup($term) {
    $db = fptOpen();
    $key = fptNormalize($term);
    if (!$db || $key === '' || !fptValidTerm($key)) { return array(); }
    $stmt = $db->prepare('SELECT p.*, a.kind FROM alias a JOIN protein p ON p.acc = a.acc
                          WHERE a.term = :term
                          ORDER BY a.kind, p.active DESC, (p.name = \'\' OR p.name LIKE \'Uncharacterized%\'),
                                   p.len DESC, p.acc
                          LIMIT 12');
    $stmt->bindValue(':term', $key, SQLITE3_TEXT);
    $res = $stmt->execute();
    $out = array();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $record = fptRow($row);
        $record['matched_as'] = array('accession', 'gene', 'symbol')[(int) $row['kind']] ?? 'gene';
        $out[] = $record;
    }
    return $out;
}

/* Typeahead: identifiers that start with what was typed. One range scan of
   the alias primary key; the upper bound is the prefix with its last
   character stepped up, so no LIKE and no collation surprises. */
function fptSuggest($term, $limit = 10) {
    $db = fptOpen();
    $prefix = fptNormalize($term);
    if (!$db || strlen($prefix) < 2 || !fptValidTerm($prefix)) { return array(); }
    $upper = substr($prefix, 0, -1) . chr(ord(substr($prefix, -1)) + 1);
    $stmt = $db->prepare('SELECT a.term, a.kind, p.* FROM alias a JOIN protein p ON p.acc = a.acc
                          WHERE a.term >= :lo AND a.term < :hi
                          ORDER BY (a.term = :lo) DESC, a.kind, length(a.term), a.term, p.active DESC, p.acc
                          LIMIT :limit');
    $stmt->bindValue(':lo', $prefix, SQLITE3_TEXT);
    $stmt->bindValue(':hi', $upper, SQLITE3_TEXT);
    $stmt->bindValue(':limit', max(1, min(25, (int) $limit)), SQLITE3_INTEGER);
    $res = $stmt->execute();
    $out = array();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $record = fptRow($row);
        /* The spelling the reader typed toward, not the upper-cased key. */
        $shown = $row['term'];
        foreach (array_merge($record['genes'], $record['symbols'], array($record['accession'])) as $name) {
            if (strtoupper($name) === $row['term']) { $shown = $name; break; }
        }
        $record['term'] = $shown;
        $out[] = $record;
    }
    return $out;
}

/* Gene ids for a list of accessions -- the Foldseek matches' Fusarium rows.
   One query for the lot. */
function fptGenesFor(array $accessions) {
    $db = fptOpen();
    $accessions = array_values(array_unique(array_filter(array_map('strtoupper', $accessions),
        function ($a) { return preg_match('/^[A-Z0-9]{6,10}$/', $a) === 1; })));
    if (!$db || !$accessions) { return array(); }
    $out = array();
    foreach (array_chunk($accessions, 400) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $stmt = $db->prepare('SELECT acc, sp, genes, symbols, name FROM protein WHERE acc IN (' . $marks . ')');
        foreach ($chunk as $i => $acc) { $stmt->bindValue($i + 1, $acc, SQLITE3_TEXT); }
        $res = $stmt->execute();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $genes = $row['genes'] !== '' ? explode(' ', $row['genes']) : array();
            $out[$row['acc']] = array(
                'species' => $row['sp'],
                'gene'    => $genes ? $genes[0] : null,
                'symbol'  => $row['symbols'] !== '' ? explode(' ', $row['symbols'])[0] : null,
                'name'    => $row['name'] !== '' ? $row['name'] : null,
            );
        }
    }
    return $out;
}

/* -------------------------------------------------------------------------- *
 * URLs
 * -------------------------------------------------------------------------- */

/* The model files as the toolkit published them. Both directories name their
   files AF-<accession>-F1-model_v4.pdb -- the ESMFold ones too. Read by the
   server only (fptModelText); a page is given fptModelLink(). */
function fptModelUrl($accession, $species, $tool) {
    $dir = ($tool === 'esmfold' ? 'esm_' : '') . $species;
    return FPT_HOST . '/protein_structure/structures/' . rawurlencode($dir)
         . '/AF-' . rawurlencode($accession) . '-F1-model_v4.pdb';
}

/* A model file as a page fetches it: from this site, not from
   fusarium.maizegdb.org. That host sits behind a Cloudflare bot check for
   visitors off the Iowa State network -- fetched from off campus on
   2026-09-25 it answered "Performing security verification", from campus the
   file -- and a browser's cross-origin fetch can neither pass the check nor
   carry a pass the visitor earned, so off campus the structures and Foldseek
   pages drew no model while on campus they did. The server is on campus; it
   fetches each file once and keeps it. */
function fptModelLink($accession, $tool) {
    return '/search/fusarium/fusarium_api.php?action=model&model=' . rawurlencode($tool)
         . '&term=' . rawurlencode($accession);
}

/* Fetched model files are kept this long after they were last fetched. They
   do not change -- a new release is a new file name -- so this only bounds the
   directory. */
const FPT_MODEL_TTL = 7776000;   // 90 days

/* Where fetched model files are kept: fusarium_cache_path in conf/mgdb.conf,
   else <search_cache_path>/fusarium. httpd can write there only if the
   directory carries httpd_sys_rw_content_t (AD-023 explains why a new
   directory under /home/cache does not); without it every request fetches
   the file again and nothing else changes. */
function fptModelCacheDir() {
    $system = function_exists('getSystemInfo') ? getSystemInfo('mgdb.conf') : array();
    $base = !empty($system['fusarium_cache_path']) ? $system['fusarium_cache_path']
          : (!empty($system['search_cache_path']) ? rtrim($system['search_cache_path'], '/') . '/fusarium' : '');
    if ($base === '') { return null; }
    if (!is_dir($base)) {
        if (!@mkdir($base, 0777, true) && !is_dir($base)) { return null; }
        @chmod($base, 0777);
    }
    return is_writable($base) ? $base : null;
}

/* One model file's text: array(status, text). 200 with the text; 404 when the
   index has no such model; 502 when fusarium.maizegdb.org did not answer
   with one. Only a model the index lists can be asked for, so this never
   fetches a URL a caller chose. */
function fptModelText($accession, $tool) {
    $p = fptProtein($accession);
    if (!$p || !in_array($tool, array('alphafold', 'esmfold'), true) || !$p[$tool]) {
        return array(404, null);
    }
    $dir = fptModelCacheDir();
    $file = $dir === null ? null : $dir . '/' . $tool . '-' . $p['accession'] . '.pdb';
    if ($file !== null && is_file($file) && (time() - filemtime($file)) < FPT_MODEL_TTL) {
        $text = @file_get_contents($file);
        if (is_string($text) && fptLooksLikePdb($text)) { return array(200, $text); }
    }
    list($status, $text) = fptFetch(fptModelUrl($p['accession'], $p['species'], $tool));
    if ($status !== 200 || !fptLooksLikePdb($text)) { return array(502, null); }
    if ($file !== null) {
        $temp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($temp, $text) !== false && @rename($temp, $file)) {
            @chmod($file, 0666);
            /* One write in a hundred sweeps out files past the TTL. */
            if (mt_rand(1, 100) === 1) {
                foreach ((array) @glob($dir . '/*.pdb') as $old) {
                    if (is_file($old) && (time() - @filemtime($old)) > FPT_MODEL_TTL) { @unlink($old); }
                }
            }
        } else {
            @unlink($temp);
        }
    }
    return array(200, $text);
}

/* A PDB file, not the HTML of a challenge or an error page. */
function fptLooksLikePdb($text) {
    return is_string($text) && preg_match('/^ATOM  /m', $text) === 1
        && stripos(substr($text, 0, 2048), '<html') === false;
}

/* GET a URL: array(HTTP status, body); status 0 when nothing came back. */
function fptFetch($url) {
    if (!function_exists('curl_init')) { return array(0, null); }
    $handle = curl_init($url);
    curl_setopt_array($handle, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 2,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'MaizeGDB/1.0 (+https://www.maizegdb.org/)',
    ));
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    return array($body === false ? 0 : $status, $body === false ? null : $body);
}

function fptLinks(array $p) {
    $gene = $p['genes'] ? $p['genes'][0] : null;
    $links = array(
        'uniprot'   => 'https://www.uniprot.org/uniprotkb/' . rawurlencode($p['accession']) . '/entry',
        /* AlphaFold DB keeps models only for entries UniProtKB still has; the
           F. oxysporum 4287 entries were deleted, and their pages with them. */
        'afdb'      => $p['in_uniprotkb'] ? 'https://alphafold.ebi.ac.uk/entry/' . rawurlencode($p['accession']) : null,
        'fungidb'   => $gene ? 'https://fungidb.org/fungidb/app/record/gene/' . rawurlencode($gene) : null,
        'structures'=> FPT_ROUTE . '/structures?id=' . rawurlencode($p['accession']),
        'foldseek'  => $p['foldseek'] ? FPT_ROUTE . '/foldseek?uniprot=' . rawurlencode($p['accession']) : null,
        /* PanEffect reads its own ids: the UniProt accession is the one every
           one of its gene pages is filed under. */
        'paneffect' => $p['paneffect'] ? FPT_PANEFFECT . '?id=' . rawurlencode($p['accession']) : null,
        'alphafold_pdb' => $p['alphafold'] ? fptModelLink($p['accession'], 'alphafold') : null,
        'esmfold_pdb'   => $p['esmfold'] ? fptModelLink($p['accession'], 'esmfold') : null,
    );
    return $links;
}

/* -------------------------------------------------------------------------- *
 * The shell
 * -------------------------------------------------------------------------- */

/* The toolkit's own navigation. SNPTools and PanEffect are separate
   applications, but both are maizegdb.org hosts, so -- as for every MaizeGDB
   subdomain on the site -- no outbound arrow. */
function fptNavItems() {
    return array(
        array('key' => 'home',       'label' => 'Home',       'href' => FPT_ROUTE),
        array('key' => 'foldseek',   'label' => 'Foldseek',   'href' => FPT_ROUTE . '/foldseek'),
        array('key' => 'structures', 'label' => 'Structures', 'href' => FPT_ROUTE . '/structures'),
        array('key' => 'effectors',  'label' => 'Effectors',  'href' => FPT_ROUTE . '/effectors'),
        array('key' => 'snptools',   'label' => 'SNPTools',   'href' => FPT_SNPTOOLS),
        array('key' => 'paneffect',  'label' => 'PanEffect',  'href' => FPT_PANEFFECT),
        array('key' => 'help',       'label' => 'Help',       'href' => FPT_ROUTE . '/help'),
    );
}

function fptNavMarkup($current) {
    $html = '';
    foreach (fptNavItems() as $item) {
        $attrs = ' href="' . fptEsc($item['href']) . '"';
        if ($item['key'] === $current) { $attrs .= ' aria-current="page"'; }
        $html .= '<li><a' . $attrs . '>' . fptEsc($item['label']) . '</a></li>';
    }
    return $html;
}
?>
