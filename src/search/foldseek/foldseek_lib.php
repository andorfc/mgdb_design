<?php
/* file: foldseek_lib.php
 *
 * purpose: Reads for /foldseek and /fusarium/foldseek. This is an adapter, not
 *          a data store: the Foldseek results live inside the applications at
 *          foldseek.maizegdb.org and fusarium.maizegdb.org and nowhere else
 *          MaizeGDB can reach -- not in the database, not on this host, not in
 *          any export. So this fetches that application's page once per
 *          protein, parses it, caches the result, and everything downstream
 *          reads structured JSON.
 *
 * Two analyses, one parser
 * ------------------------
 * The Fusarium Protein Toolkit's search page is the same PHP wrapper around
 * the same Foldseek report, so everything below serves both. What differs --
 * the host, the path, the species strings, the identifier shapes, the model
 * files and the cache names -- is in $FS_SETS, and the API picks one per
 * request with fsUseSet(). The maize entry keeps every value this file had
 * before the second was added, including its unprefixed cache names, so a
 * warm maize cache stayed valid across the change.
 *
 * The Fusarium page differs from the maize one in four ways that matter here,
 * measured 2026-09-25 on FVEG_13850, FGSG_09786, FGSG_00001 and FGRRES_15678_M:
 *   - its overview names the species ("Species name: F. graminearum") and
 *     gives gene ids ("Gene annotation: FGRRES_09786, FGSG_09786") where the
 *     maize one gives a symbol and a name;
 *   - no Fusarium protein has Pfam rows -- every page says "There are no PFAM
 *     domains for this protein" -- so fsParseDomains() returns nothing there;
 *   - its species strings are single lower-case words, and there are nine:
 *     five Fusarium species and four outgroups. F. verticillioides is never a
 *     match, and a F. graminearum protein never matches its own proteome;
 *   - the model it searched is AlphaFold DB version 4, and the toolkit keeps
 *     the file, so the page draws exactly what was searched.
 *
 * What the upstream page is
 * -------------------------
 * A PHP wrapper around Foldseek's own HTML report (`--format-mode 3`). Every
 * page carries the whole 5.5 MB report viewer inline and then calls
 *
 *     render([{ "query": {...}, "alignments": [ ...165 hits... ] }]);
 *
 * with one object per match: the target accession, species, UniProt
 * annotation, identity, score, E-value, both ranges, both aligned strings,
 * the target's full sequence, and its Calpha coordinates as a flat
 * comma-separated string. The query's Calpha coordinates ride on the query
 * object. That call is the load-bearing parse here; it is what the upstream
 * viewer itself draws from, so reading it cannot drift from what upstream
 * shows. Above it sit two hand-written blocks -- the protein overview and a
 * Pfam table -- which are parsed more softly: a miss there costs a blank field,
 * never the answer.
 *
 * The render([...]) JSON is not valid JSON. The last alignment is followed by
 * a trailing comma. json_decode() rejects the whole 2 MB for that one byte, so
 * it is removed by a pattern that steps over string literals first -- a
 * trailing-comma strip that did not skip strings could delete a comma inside an
 * annotation. Possessive quantifiers keep it linear; 5 ms on 2 MB with the PCRE
 * JIT off, which is how this host runs.
 *
 * Identifier contract, measured 2026-09-25
 * ----------------------------------------
 * The upstream lookup is exact and case-sensitive. It answers Zm00001eb374230,
 * Zm00001d045055, P16165, bz1 and wx1 -- and an empty HTTP 500 for
 * zm00001eb374230, Zm00001eb374230_T001, Zm00001eb374230_P001, p16165,
 * AF-P16165-F1, BZ1, GRMZM2G165390 and "bz1 " with a trailing space. fsCandidates()
 * turns every one of those shapes into one the upstream accepts before asking.
 *
 * Query cost
 * ----------
 * One upstream request per protein, ~7.6 MB and ~0.3 s from dev8, on a cache
 * miss. Nothing on a hit: a summary read is one small JSON file, and a single
 * match's coordinates are one gzip file. No SQL anywhere.
 */

include_once(dirname(__DIR__) . '/protein_structure/protein_structure_lib.php');
include_once(dirname(dirname(__DIR__)) . '/include/fusarium_lib.php');

const FS_UPSTREAM = 'https://foldseek.maizegdb.org';

/* The AlphaFold databank version whose files exist. The search itself used
   model_v3 (July 2022); EMBL-EBI has since retired v1-v5, so every model link
   and every structure the viewer fetches uses this version instead. Bump it
   here when v6 goes the way of v3. */
const FS_AF_VERSION = 'v6';

/* Upstream is a fixed 2022 analysis. The TTL exists only so a rebuild there
   eventually reaches here; a "not in the analysis" answer is kept a day, in
   case the miss was an upstream hiccup that happened to return the same 500. */
const FS_CACHE_TTL = 2592000;   /* 30 days */
const FS_MISS_TTL  = 86400;     /* 1 day */

/* Bumped whenever the shape of a cached payload changes, so a warm cache can
   never hand the page an entry it does not know how to read. */
const FS_CACHE_VERSION = 2;

const FS_TERM_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';
const FS_ACCESSION_PATTERN = '/^[A-Z0-9]{6,10}$/';

/* The eight proteomes the analysis searched, keyed by the species string the
   upstream report writes on every match. Ordered by relatedness to maize --
   the order a maize biologist reads them in, and the order /fatcat uses for
   the four it shares. */
$FS_SPECIES = array(
    'maize'       => array('label' => 'Maize',         'latin' => 'Zea mays',                  'upstream' => 'Z. mays - Maize',               'group' => 'grass'),
    'sorghum'     => array('label' => 'Sorghum',       'latin' => 'Sorghum bicolor',           'upstream' => 'S. bicolor - Sorghum',          'group' => 'grass'),
    'rice'        => array('label' => 'Rice',          'latin' => 'Oryza sativa',              'upstream' => 'O. sativa - Rice',              'group' => 'grass'),
    'soybean'     => array('label' => 'Soybean',       'latin' => 'Glycine max',               'upstream' => 'G. max - Soybean',              'group' => 'dicot'),
    'arabidopsis' => array('label' => 'Arabidopsis',   'latin' => 'Arabidopsis thaliana',      'upstream' => 'A. thaliana - Arabidopsis',     'group' => 'dicot'),
    'human'       => array('label' => 'Human',         'latin' => 'Homo sapiens',              'upstream' => 'H. sapiens - Human',            'group' => 'outgroup'),
    'yeast'       => array('label' => 'Budding yeast', 'latin' => 'Saccharomyces cerevisiae',  'upstream' => 'S. cerevisiae - Budding yeast', 'group' => 'outgroup'),
    'pombe'       => array('label' => 'Fission yeast', 'latin' => 'Schizosaccharomyces pombe', 'upstream' => 'S. pombe - Fission yeast',      'group' => 'outgroup'),
);

/* The nine proteomes the Fusarium analysis searched, in the order the
   toolkit's own pages name them. The upstream strings are its species values
   verbatim. */
$FS_FUSARIUM_SPECIES = array(
    'graminearum'  => array('label' => 'F. graminearum',  'latin' => 'Fusarium graminearum',       'upstream' => 'graminearum',  'group' => 'fusarium'),
    'fujikuroi'    => array('label' => 'F. fujikuroi',    'latin' => 'Fusarium fujikuroi',         'upstream' => 'fujikuroi',    'group' => 'fusarium'),
    'oxysporum'    => array('label' => 'F. oxysporum',    'latin' => 'Fusarium oxysporum',         'upstream' => 'oxysporum',    'group' => 'fusarium'),
    'proliferatum' => array('label' => 'F. proliferatum', 'latin' => 'Fusarium proliferatum',      'upstream' => 'proliferatum', 'group' => 'fusarium'),
    'solani'       => array('label' => 'F. solani',       'latin' => 'Fusarium vanettenii',        'upstream' => 'solani',       'group' => 'fusarium'),
    'arabidopsis'  => array('label' => 'Arabidopsis',     'latin' => 'Arabidopsis thaliana',       'upstream' => 'arabidopsis',  'group' => 'outgroup'),
    'human'        => array('label' => 'Human',           'latin' => 'Homo sapiens',               'upstream' => 'human',        'group' => 'outgroup'),
    'cerevisiae'   => array('label' => 'Budding yeast',   'latin' => 'Saccharomyces cerevisiae',   'upstream' => 'cerevisiae',   'group' => 'outgroup'),
    'pombe'        => array('label' => 'Fission yeast',   'latin' => 'Schizosaccharomyces pombe',  'upstream' => 'pombe',        'group' => 'outgroup'),
);

/* Everything that differs between the two analyses. `lookup` is the page that
   answers one identifier; `cache` prefixes every cache file name. */
$FS_SETS = array(
    'maize' => array(
        'host'       => 'foldseek.maizegdb.org',
        'lookup'     => FS_UPSTREAM . '/?uniprot=',
        'page'       => FS_UPSTREAM . '/?uniprot=',
        'af_version' => FS_AF_VERSION,
        'cache'      => '',
        'species'    => 'FS_SPECIES',
    ),
    'fusarium' => array(
        'host'       => 'fusarium.maizegdb.org',
        /* search.php is the report itself; index.php is the page that frames
           it, which is where a reader should be sent. */
        'lookup'     => FPT_HOST . '/protein_structure/search.php?uniprot=',
        'page'       => FPT_HOST . '/protein_structure/index.php?uniprot=',
        'af_version' => 'v4',
        'cache'      => 'fusarium-',
        'species'    => 'FS_FUSARIUM_SPECIES',
    ),
);
$GLOBALS['fs_set'] = 'maize';

function fsUseSet($key) {
    global $FS_SETS;
    if (!isset($FS_SETS[$key])) { return false; }
    $GLOBALS['fs_set'] = $key;
    return true;
}

function fsSetKey() { return $GLOBALS['fs_set']; }

function fsSet($field) {
    global $FS_SETS;
    return $FS_SETS[$GLOBALS['fs_set']][$field];
}

function fsSpeciesTable() {
    return $GLOBALS[fsSet('species')];
}

/* The upstream page for an identifier -- the one a reader should open. */
function fsUpstreamPage($term) {
    return fsSet('page') . rawurlencode($term);
}

function fsValidTerm($term) {
    return preg_match(FS_TERM_PATTERN, (string) $term) === 1;
}

function fsValidAccession($value) {
    return preg_match(FS_ACCESSION_PATTERN, (string) $value) === 1;
}

/* UniProt's own accession format, isoform suffix allowed. Stricter than
   FS_ACCESSION_PATTERN, which only has to keep a value safe to put in a URL
   and a file name: a seven-character gene symbol passes that one and must not
   pass this. */
function fsLooksLikeUniprot($value) {
    return preg_match('/^(?:[OPQ][0-9][A-Z0-9]{3}[0-9]|[A-NR-Z][0-9](?:[A-Z][A-Z0-9]{2}[0-9]){1,2})(?:-\d+)?$/i',
                      (string) $value) === 1;
}

/* The model the page draws. Maize: AlphaFold DB's current file. Fusarium:
   the toolkit's copy of the very file the search used, which is also the only
   copy there is for the F. oxysporum entries UniProt has deleted -- through
   this site, because fusarium.maizegdb.org turns a browser's fetch away off
   campus (fptModelLink). */
function fsModelUrl($accession, $species = null) {
    if (fsSetKey() === 'fusarium' && $species) {
        return fptModelLink($accession, 'alphafold');
    }
    return 'https://alphafold.ebi.ac.uk/files/AF-' . rawurlencode($accession)
         . '-F1-model_' . FS_AF_VERSION . '.pdb';
}

function fsSpeciesKey($upstream) {
    foreach (fsSpeciesTable() as $key => $meta) {
        if ($meta['upstream'] === $upstream) { return $key; }
    }
    return null;
}

/* The species list the page draws from, in order, with nothing a client could
   not have derived from the table above. */
function fsSpeciesList() {
    $out = array();
    foreach (fsSpeciesTable() as $key => $meta) {
        $out[] = array('key' => $key, 'label' => $meta['label'],
                       'latin' => $meta['latin'], 'group' => $meta['group']);
    }
    return $out;
}

/* -------------------------------------------------------------------------- *
 * Identifier ladder
 *
 * The first candidate is the reader's own text put into the one shape the
 * upstream accepts; the rest come from the protein structure index, which
 * already maps symbols, v5 gene models and UniProt accessions onto each other.
 * That index does not know every symbol the upstream does (wx1 is absent from
 * it) and the upstream does not know every identifier the index does, so each
 * is a fallback for the other rather than a replacement.
 * -------------------------------------------------------------------------- */
function fsCandidates($term) {
    if (fsSetKey() === 'fusarium') { return fsFusariumCandidates($term); }
    $term = trim((string) $term);
    $out = array();
    $add = function ($value) use (&$out) {
        if ($value !== '' && $value !== null && fsValidTerm($value) && !in_array($value, $out, true)) {
            $out[] = $value;
        }
    };

    if (preg_match('/^zm(\d{5})([a-z]{1,2})(\d{6})(?:_[tp]\d+)?$/i', $term, $m)) {
        /* Gene models: Zm + digits + lower-case annotation letters. A
           transcript or protein suffix names the same gene here. */
        $add('Zm' . $m[1] . strtolower($m[2]) . $m[3]);
    } elseif (preg_match('/^AF-([A-Z0-9]{6,10})-F\d+(?:-model_v\d+)?(?:\.(?:pdb|cif))?$/i', $term, $m)) {
        $add(strtoupper($m[1]));
    } elseif (preg_match('/^\d{6}$/', $term)) {
        /* The numeric tail of a B73 v5 gene model, as the protein structure
           hub and /fatcat already accept it. */
        $add('Zm00001eb' . $term);
    } elseif (fsLooksLikeUniprot($term)) {
        /* UniProt accession, isoform suffix dropped: the analysis used the
           canonical model of each entry. */
        $add(strtoupper(preg_replace('/-\d+$/', '', $term)));
    } else {
        /* Gene symbols are lower case at MaizeGDB, and upstream matches them
           exactly: bz1 resolves, BZ1 does not. */
        $add(strtolower($term));
    }
    $add($term);

    $alias = psAlias($term);
    if (is_array($alias)) {
        foreach (array_slice(isset($alias['gene_ids']) ? $alias['gene_ids'] : array(), 0, 2) as $gene) {
            $add($gene);
        }
        foreach (array_slice(isset($alias['uniprots']) ? $alias['uniprots'] : array(), 0, 2) as $acc) {
            $add($acc);
        }
    }
    return array_slice($out, 0, 4);
}

/* The Fusarium ladder. The upstream resolves FGSG_, FGRRES_ and FVEG_ ids
   and UniProt accessions itself, case-sensitively; the toolkit's protein index
   adds the rest of the ids a protein goes by. Only proteins of the two
   searched species are worth asking about -- the index knows which species an
   id belongs to, so an F. fujikuroi id costs no upstream request at all (the
   API explains that case instead). */
function fsFusariumCandidates($term) {
    $term = trim((string) $term);
    $out = array();
    $add = function ($value) use (&$out) {
        if ($value !== '' && $value !== null && fsValidTerm($value) && !in_array($value, $out, true)) {
            $out[] = $value;
        }
    };
    $key = fptNormalize($term);
    $proteins = fptLookup($term);
    $searched = array_values(array_filter($proteins, function ($p) { return $p['foldseek']; }));
    if ($proteins && !$searched) { return array(); }

    /* An accession the index resolves goes first: it is the one spelling the
       upstream always answers. Then the reader's own id, upper-cased. */
    foreach (array_slice($searched, 0, 2) as $p) { $add($p['accession']); }
    if (!$proteins) {
        if (preg_match('/^(FGSG|FGRRES|FVEG)_\d+(?:_[A-Z0-9]+)*$/i', $key) || fsLooksLikeUniprot($key)) {
            $add($key);
        }
    }
    return array_slice($out, 0, 3);
}

/* -------------------------------------------------------------------------- *
 * Cache
 *
 * Deliberately not dashboardCache(), for the reason fatcat_lib.php gives: this
 * holds one entry per protein looked up, with a TTL, because what is behind it
 * is somebody else's HTTP service. Three files per protein at most:
 *
 *   a-<ACC>.json           the summary the results page draws from, ~40 KB
 *   a-<ACC>.detail.json.gz alignments, sequences and Calpha coordinates, the
 *                          ~2 MB that only a superposition needs, ~0.6 MB
 *   t-<sha1>.json          which accession a typed identifier resolved to, or
 *                          that it resolved to nothing
 *
 * The directory needs the httpd_sys_rw_content_t label. Created outside httpd
 * it is user_home_t, every write is denied silently and the page works on and
 * never caches; the API reports summary.cache_error so that is visible.
 * -------------------------------------------------------------------------- */
$GLOBALS['fs_cache_error'] = null;

function fsCacheDir($system) {
    $base = '';
    if (!empty($system['foldseek_cache_path'])) {
        $base = $system['foldseek_cache_path'];
    } elseif (!empty($system['search_cache_path'])) {
        $base = rtrim($system['search_cache_path'], '/') . '/foldseek';
    }
    if ($base === '') { return null; }
    /* 0777 on purpose, as for the fatcat cache: apache and whoever runs a CLI
       probe both write here, and a 0775 directory owned by whichever came first
       locks the other out without a word. */
    if (!is_dir($base)) {
        if (!@mkdir($base, 0777, true) && !is_dir($base)) { return null; }
        @chmod($base, 0777);
    }
    return is_writable($base) ? $base : null;
}

function fsCacheFile($system, $name) {
    $dir = fsCacheDir($system);
    return $dir === null ? null : $dir . '/' . fsSet('cache') . $name;
}

function fsCacheRead($system, $name, $ttl) {
    $file = fsCacheFile($system, $name);
    if ($file === null || !is_file($file)) { return null; }
    if ($ttl > 0 && (time() - filemtime($file)) > $ttl) { return null; }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') { return null; }
    if (substr($name, -3) === '.gz') {
        $raw = @gzdecode($raw);
        if ($raw === false) { return null; }
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['v']) || $data['v'] !== FS_CACHE_VERSION) { return null; }
    return $data;
}

function fsCacheWrite($system, $name, array $payload) {
    $file = fsCacheFile($system, $name);
    if ($file === null) {
        $GLOBALS['fs_cache_error'] = 'cache directory is missing or not writable';
        return false;
    }
    $payload['v'] = FS_CACHE_VERSION;
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) { return false; }
    if (substr($name, -3) === '.gz') { $json = gzencode($json, 6); }
    $temp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($temp, $json) === false) {
        $GLOBALS['fs_cache_error'] = 'cache write denied (check the SELinux label on '
                                   . dirname($file) . ')';
        return false;
    }
    if (!@rename($temp, $file)) {
        @unlink($temp);
        $GLOBALS['fs_cache_error'] = 'cache rename failed';
        return false;
    }
    @chmod($file, 0666);
    /* One write in fifty sweeps out anything past its TTL, so the directory
       holds about a month of distinct lookups and never grows without bound. */
    if (mt_rand(1, 50) === 1) { fsCachePrune(dirname($file)); }
    return true;
}

function fsCachePrune($dir) {
    $cutoff = time() - FS_CACHE_TTL;
    foreach ((array) @glob($dir . '/*') as $path) {
        if (is_file($path) && @filemtime($path) < $cutoff) { @unlink($path); }
    }
}

/* -------------------------------------------------------------------------- *
 * Upstream fetch
 *
 * Returns array(status, body). Status 0 is a transport failure. The upstream
 * answers an identifier it does not know with an EMPTY 500, which is an answer,
 * not an outage; a 500 with a body, a 502 or a timeout is an outage.
 * -------------------------------------------------------------------------- */
function fsHttpGet($url, $timeout = 30) {
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'MaizeGDB/1.0 (+https://www.maizegdb.org/)',
        ));
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return array($body === false ? 0 : $status, $body === false ? '' : (string) $body);
    }
    $context = stream_context_create(array('http' => array(
        'header'        => "User-Agent: MaizeGDB/1.0 (+https://www.maizegdb.org/)\r\n",
        'timeout'       => $timeout,
        'ignore_errors' => true,
    )));
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0] . ' ', $m)) {
        $status = (int) $m[1];
    }
    return array($body === false ? 0 : $status, $body === false ? '' : $body);
}

/* -------------------------------------------------------------------------- *
 * Parsing
 * -------------------------------------------------------------------------- */

function fsText($html) {
    $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text));
}

/* The protein overview block: a run of "Label: value <br>" pairs with no
   classes to hang anything off. Each field is read on its own anchor and is
   null when absent, rather than guessed. */
function fsParseOverview($html) {
    $out = array('uniprot' => null, 'description' => null, 'truncated' => false,
                 'v5' => null, 'v4' => null, 'symbol' => null, 'name' => null);
    $start = strpos($html, '<h2>Protein overview</h2>');
    if ($start === false) { return $out; }
    $stop = strpos($html, '<h2>Project summary</h2>', $start);
    $block = substr($html, $start, ($stop === false ? 6000 : $stop - $start));

    if (preg_match('#uniprot\.org/uniprotkb/([A-Z0-9]{6,10})#', $block, $m)) {
        $out['uniprot'] = $m[1];
    }
    if (preg_match('#Uniprot Description:\s*(.*?)\s*<br#s', $block, $m)) {
        $text = fsText($m[1]);
        /* Upstream cuts long names at about 100 characters and appends
           "...". Kept as a flag so the page can say it is an excerpt. */
        if (substr($text, -3) === '...') {
            $out['truncated'] = true;
            $text = rtrim(substr($text, 0, -3));
        }
        $out['description'] = $text !== '' ? $text : null;
    }
    if (preg_match('#B73 version 5 ID:\s*<a[^>]*>\s*(Zm\d{5}[a-z]{1,2}\d{6})\s*</a>#', $block, $m)) {
        $out['v5'] = $m[1];
    }
    if (preg_match('#B73 version 4 ID:\s*<a[^>]*>\s*(Zm\d{5}[a-z]{1,2}\d{6})\s*</a>#', $block, $m)) {
        $out['v4'] = $m[1];
    }
    if (preg_match('#Gene annotation:\s*(.*?)\s*<br#s', $block, $m)) {
        $text = fsText($m[1]);
        if ($text !== '' && strtoupper($text) !== 'NA') {
            if (fsSetKey() === 'fusarium') {
                /* A list of gene ids here, not "symbol - name". */
                $out['genes'] = array_values(array_filter(preg_split('/[\s,]+/', $text), function ($g) {
                    return preg_match('/^[A-Z][A-Z0-9]*_[A-Za-z0-9_]+$/', $g) === 1;
                }));
            } else {
                $parts = explode(' - ', $text, 2);
                $out['symbol'] = trim($parts[0]) !== '' ? trim($parts[0]) : null;
                $out['name'] = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null;
            }
        }
    }
    if (preg_match('#Species name:\s*(.*?)\s*<br#s', $block, $m)) {
        $out['species_name'] = fsText($m[1]);
    }
    return $out;
}

/* The Pfam table. Its markup is irregular -- the second and later rows open
   with <td> and no <tr>, and the PFAM and clan links are never closed -- so it
   is read as a flat run of cells, nine to a domain, and each row is checked
   field by field before it is kept. */
function fsParseDomains($html) {
    $start = strpos($html, '<h2>PFAM domains</h2>');
    if ($start === false) { return array(); }
    $stop = strpos($html, '<h2>Foldseek Structure alignments</h2>', $start);
    $block = substr($html, $start, ($stop === false ? 20000 : $stop - $start));
    if (!preg_match_all('#<td>(.*?)</td>#s', $block, $m)) { return array(); }
    $cells = array_map('fsText', $m[1]);

    $domains = array();
    for ($i = 0; $i + 8 < count($cells); $i += 9) {
        list($protein, $from, $to, $pfam, $name, $type, $bits, $evalue, $clan) = array_slice($cells, $i, 9);
        if (!preg_match('/^\d+$/', $from) || !preg_match('/^\d+$/', $to)) { continue; }
        if (!preg_match('/^PF\d{5}$/', $pfam)) { continue; }
        $domains[] = array(
            'start'  => (int) $from,
            'end'    => (int) $to,
            'pfam'   => $pfam,
            'name'   => $name,
            'type'   => $type !== '' ? $type : null,
            'bits'   => is_numeric($bits) ? (float) $bits : null,
            'evalue' => is_numeric($evalue) ? (float) $evalue : null,
            'clan'   => preg_match('/^CL\d{4}$/', $clan) ? $clan : null,
        );
    }
    usort($domains, function ($a, $b) { return $a['start'] - $b['start']; });
    return $domains;
}

/* The render([...]) payload. Returns the decoded first query object or null. */
function fsParseRender($html) {
    $start = strrpos($html, 'render([');
    $end = strrpos($html, ']);');
    if ($start === false || $end === false || $end < $start) { return null; }
    $raw = substr($html, $start + 7, $end - ($start + 7) + 1);
    $clean = preg_replace('/"(?:[^"\\\\]++|\\\\.)*+"(*SKIP)(*FAIL)|,(?=\s*+[\]}])/s', '', $raw);
    if ($clean === null) {
        /* preg_replace answers null, not the input, when PCRE gives up. */
        if (function_exists('reportError')) {
            reportError('foldseek_lib: trailing-comma strip failed: ' . preg_last_error_msg());
        }
        return null;
    }
    $data = json_decode($clean, true);
    if (!is_array($data) || !isset($data[0]['query'], $data[0]['alignments'])) { return null; }
    return $data[0];
}

/* Coordinates travel as the upstream's own comma-separated string. Checked
   for shape here so nothing but numbers can ever reach the page. */
function fsCleanCoordinates($value) {
    $value = (string) $value;
    return preg_match('/^[-0-9.,eE ]*$/', $value) ? $value : '';
}

function fsCleanSequence($value) {
    return preg_replace('/[^A-Za-z-]/', '', (string) $value);
}

/* v5 gene models for maize accessions, from the protein structure index.
   One shard file per distinct shard rather than one read per accession. Most
   of the maize matches are 2016-era TrEMBL entries built on the B73 v4
   annotation, which UniProt has since deleted and the index never held, so a
   miss here is expected and the page says so rather than guessing. */
function fsMaizeGenes(array $accessions) {
    $byShard = array();
    foreach (array_unique($accessions) as $acc) {
        $key = psNormalize($acc);
        if ($key === '' || !psValidTerm($key)) { continue; }
        $byShard[psShard($key)][] = $key;
    }
    $genes = array();
    foreach ($byShard as $shard => $keys) {
        $data = psReadJson(psDataRoot() . '/aliases/' . $shard . '.json');
        if (!is_array($data)) { continue; }
        foreach ($keys as $key) {
            if (!empty($data[$key]['gene_ids'][0])) {
                $genes[strtoupper($key)] = $data[$key]['gene_ids'][0];
            }
        }
    }
    return $genes;
}

/* Split one parsed upstream page into the summary the page draws and the
   detail only a superposition needs. */
function fsBuildPayloads($html) {
    $render = fsParseRender($html);
    if ($render === null) { return null; }

    $query = $render['query'];
    $model = array('accession' => null, 'fragment' => null, 'version' => null);
    if (isset($query['accession'])
        && preg_match('/^AF-([A-Z0-9]{6,10})-F(\d+)-model_(v\d+)/', (string) $query['accession'], $m)) {
        $model = array('accession' => $m[1], 'fragment' => (int) $m[2], 'version' => $m[3]);
    }

    $overview = fsParseOverview($html);
    $accession = $overview['uniprot'] ? $overview['uniprot'] : $model['accession'];
    if (!$accession || !fsValidAccession($accession)) { return null; }

    $qSeq = fsCleanSequence(isset($query['sequence']) ? $query['sequence'] : '');
    $qCa = fsCleanCoordinates(isset($query['qca']) ? $query['qca'] : '');

    $fusarium = fsSetKey() === 'fusarium';
    $hits = array();
    $detail = array();
    $maize = array();
    $own = array();
    foreach ((array) $render['alignments'] as $n => $a) {
        if (!is_array($a) || !isset($a['target'])) { continue; }
        $target = strtoupper(trim((string) $a['target']));
        if (!fsValidAccession($target)) { continue; }
        $species = fsSpeciesKey(isset($a['species']) ? (string) $a['species'] : '');
        if ($species === 'maize') { $maize[] = $target; }
        if ($fusarium && fptSpeciesMeta($species)) { $own[] = $target; }
        $annotation = fsText(isset($a['annotation']) ? $a['annotation'] : '');
        /* F. graminearum's UniProt names are mostly the EMBL record's title,
           "Chromosome 1, complete genome" -- a sequence record, not a
           protein. tools/fusarium_index.py drops the same string. */
        if ($fusarium && preg_match('/^chromosome \w+, complete genome\b/i', $annotation)) { $annotation = ''; }
        $hits[] = array(
            'n'          => (int) $n,
            'target'     => $target,
            'species'    => $species,
            'annotation' => $annotation,
            'identity'   => isset($a['seqId']) ? (float) $a['seqId'] : null,
            'aln_len'    => isset($a['alnLen']) ? (int) $a['alnLen'] : null,
            'mismatch'   => isset($a['mismatch']) ? (int) $a['mismatch'] : null,
            'gap_open'   => isset($a['gapopen']) ? (int) $a['gapopen'] : null,
            'q_start'    => (int) $a['qStartPos'],
            'q_end'      => (int) $a['qEndPos'],
            'q_len'      => (int) $a['qLen'],
            't_start'    => (int) $a['dbStartPos'],
            't_end'      => (int) $a['dbEndPos'],
            't_len'      => (int) $a['dbLen'],
            'evalue'     => isset($a['eval']) ? (float) $a['eval'] : null,
            'score'      => isset($a['score']) ? (int) $a['score'] : null,
        );
        $detail[(string) (int) $n] = array(
            'q_aln' => fsCleanSequence(isset($a['qAln']) ? $a['qAln'] : ''),
            't_aln' => fsCleanSequence(isset($a['dbAln']) ? $a['dbAln'] : ''),
            't_seq' => fsCleanSequence(isset($a['tseq']) ? $a['tseq'] : ''),
            't_ca'  => fsCleanCoordinates(isset($a['tca']) ? $a['tca'] : ''),
        );
    }

    if ($fusarium) {
        /* The gene id each Fusarium match goes by, from the toolkit's index. */
        $known = fptGenesFor($own);
        foreach ($hits as &$hit) {
            $hit['gene'] = isset($known[$hit['target']]['gene']) ? $known[$hit['target']]['gene'] : null;
        }
    } else {
        $genes = fsMaizeGenes($maize);
        foreach ($hits as &$hit) {
            $hit['gene'] = ($hit['species'] === 'maize' && isset($genes[$hit['target']])) ? $genes[$hit['target']] : null;
        }
    }
    unset($hit);

    $protein = array(
        'uniprot'     => $accession,
        'description' => $overview['description'],
        'truncated'   => $overview['truncated'],
        'symbol'      => $overview['symbol'],
        'name'        => $overview['name'],
        'v5'          => $overview['v5'],
        'v4'          => $overview['v4'],
        'length'      => strlen($qSeq),
        /* The searched model's sequence. The page compares it with the
           AlphaFold DB file it draws, because that file is today's v6
           and the search used v3: where the two differ, residue
           numbers -- and so the Pfam positions -- may not line up. */
        'sequence'    => $qSeq,
        'searched_model' => $model['accession']
            ? 'AF-' . $model['accession'] . '-F' . $model['fragment'] . '-model_' . $model['version'] : null,
    );
    if ($fusarium) {
        $protein = fsFusariumProtein($protein, $overview);
    }

    return array(
        'accession' => $accession,
        'summary' => array(
            'accession' => $accession,
            'protein'   => $protein,
            'domains' => fsParseDomains($html),
            'hits'    => $hits,
            'fetched' => gmdate('c'),
        ),
        'detail' => array(
            'accession' => $accession,
            'q_seq' => $qSeq,
            'q_ca'  => $qCa,
            'hits'  => $detail,
        ),
    );
}

/* The searched Fusarium protein as the page shows it: its species from the
   overview, its gene ids from the overview and the toolkit's index together,
   and its UniProt symbol, if it has one, where a maize protein's name goes. */
function fsFusariumProtein(array $protein, array $overview) {
    $species = null;
    if (!empty($overview['species_name'])
        && preg_match('/^F\.\s*([a-z]+)$/', trim($overview['species_name']), $m)
        && fptSpeciesMeta($m[1])) {
        $species = $m[1];
    }
    $indexed = fptProtein($protein['uniprot']);
    if ($species === null && $indexed) { $species = $indexed['species']; }
    $meta = $species ? fptSpeciesMeta($species) : null;

    $genes = isset($overview['genes']) ? $overview['genes'] : array();
    if ($indexed) { $genes = array_merge($genes, $indexed['genes']); }
    /* FGSG_ first, as the toolkit writes them; then FGRRES_, then the rest. */
    $rank = function ($g) {
        foreach (array('FGSG_', 'FVEG_', 'FGRRES_') as $i => $prefix) {
            if (strpos($g, $prefix) === 0) { return $i; }
        }
        return 9;
    };
    $genes = array_values(array_unique($genes));
    usort($genes, function ($a, $b) use ($rank) {
        return ($rank($a) - $rank($b)) ?: strcmp($a, $b);
    });

    $description = $protein['description'];
    if ($description !== null && preg_match('/^chromosome \w+, complete genome\b/i', $description)) {
        $description = null;
    }
    $protein['description'] = $description;
    $protein['symbol'] = null;
    $protein['name'] = ($indexed && $indexed['symbols']) ? $indexed['symbols'][0] : null;
    $protein['v5'] = $protein['v4'] = null;
    $protein['genes'] = $genes;
    $protein['species'] = $species;
    $protein['species_label'] = $meta ? $meta['label'] : null;
    $protein['species_latin'] = $meta ? $meta['latin'] : null;
    $protein['strain'] = $meta ? $meta['strain'] : null;
    $protein['esmfold'] = $indexed ? $indexed['esmfold'] : false;
    $protein['in_uniprotkb'] = $indexed ? $indexed['in_uniprotkb'] : null;
    $protein['effector'] = $indexed ? $indexed['effector'] : false;
    return $protein;
}

/* -------------------------------------------------------------------------- *
 * The two reads the API serves
 * -------------------------------------------------------------------------- */

/* Resolve a typed identifier to a summary. Returns
     array('status' => 'found', 'summary' => ..., 'resolved' => <candidate>)
     array('status' => 'missing', 'tried' => [...])
     array('status' => 'unavailable', 'tried' => [...])
   $meta['upstream'] counts the requests that went out. */
function fsLookup($system, $term, &$meta) {
    return fsLookupCandidates($system, fsCandidates($term), $meta);
}

/* The same, over an explicit list -- the API hands it the v5 names the gene
   database finds for an identifier nothing else recognized. */
function fsLookupCandidates($system, array $candidates, &$meta) {
    if (!is_array($meta) || !isset($meta['upstream'])) {
        $meta = array('upstream' => 0, 'cache' => false);
    }
    $tried = array();
    $unavailable = false;

    foreach ($candidates as $candidate) {
        if (!fsValidTerm($candidate)) { continue; }
        $tried[] = $candidate;
        $termFile = 't-' . sha1($candidate) . '.json';

        $known = fsCacheRead($system, $termFile, FS_CACHE_TTL);
        if ($known !== null) {
            if (!empty($known['missing'])) {
                if ((time() - (int) $known['at']) < FS_MISS_TTL) { continue; }
            } elseif (!empty($known['accession'])) {
                $summary = fsCacheRead($system, 'a-' . $known['accession'] . '.json', FS_CACHE_TTL);
                if ($summary !== null) {
                    $meta['cache'] = true;
                    return array('status' => 'found', 'summary' => $summary, 'resolved' => $candidate);
                }
            }
        }

        $meta['upstream']++;
        list($status, $body) = fsHttpGet(fsSet('lookup') . rawurlencode($candidate));
        if ($status === 500 && trim($body) === '') {
            fsCacheWrite($system, $termFile, array('missing' => true, 'at' => time()));
            continue;
        }
        if ($status !== 200 || strlen($body) < 1000) {
            $unavailable = true;
            break;
        }
        $built = fsBuildPayloads($body);
        if ($built === null) {
            /* A 200 with no render payload is the bare search form -- the page
               upstream serves when it resolved nothing -- so treat it as a
               miss, not as an outage. */
            if (strpos($body, 'render([') !== false && strpos($body, '"alignments"') === false) {
                fsCacheWrite($system, $termFile, array('missing' => true, 'at' => time()));
                continue;
            }
            $unavailable = true;
            break;
        }
        fsCacheWrite($system, 'a-' . $built['accession'] . '.json', $built['summary']);
        fsCacheWrite($system, 'a-' . $built['accession'] . '.detail.json.gz', $built['detail']);
        fsCacheWrite($system, $termFile, array('accession' => $built['accession']));
        $summary = $built['summary'];
        $summary['v'] = FS_CACHE_VERSION;
        return array('status' => 'found', 'summary' => $summary, 'resolved' => $candidate);
    }
    return array('status' => $unavailable ? 'unavailable' : 'missing', 'tried' => $tried);
}

/* One match's alignment, sequences and coordinates, plus the query's. The
   detail file can be absent while the summary is not -- pruned separately, or
   a write that failed -- so a miss here refetches by accession, which the
   upstream always accepts. */
function fsHitDetail($system, $accession, $n, &$meta) {
    $meta = array('upstream' => 0, 'cache' => false);
    $detail = fsCacheRead($system, 'a-' . $accession . '.detail.json.gz', FS_CACHE_TTL);
    if ($detail !== null) {
        $meta['cache'] = true;
    } else {
        $meta['upstream']++;
        list($status, $body) = fsHttpGet(fsSet('lookup') . rawurlencode($accession));
        if ($status !== 200) { return $status === 500 ? false : null; }
        $built = fsBuildPayloads($body);
        if ($built === null || $built['accession'] !== $accession) { return null; }
        fsCacheWrite($system, 'a-' . $accession . '.json', $built['summary']);
        fsCacheWrite($system, 'a-' . $accession . '.detail.json.gz', $built['detail']);
        $detail = $built['detail'];
    }
    $key = (string) (int) $n;
    if (!isset($detail['hits'][$key])) { return false; }
    return array(
        'q_seq' => $detail['q_seq'],
        'q_ca'  => $detail['q_ca'],
        'hit'   => $detail['hits'][$key],
    );
}
?>
