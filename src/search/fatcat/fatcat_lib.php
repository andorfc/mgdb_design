<?php
/* file: fatcat_lib.php
 *
 * purpose: Reads for /fatcat. This is an adapter, not a data store: the
 *          ortholog table lives inside the FATCAT application at
 *          fatcat.maizegdb.org and exists nowhere else -- not in the MaizeGDB
 *          database, not on the codex instance, not in any export. So this
 *          fetches that page once per protein, parses it into JSON, caches the
 *          result, and everything downstream reads structured data instead of
 *          an iframe.
 *
 * Why an adapter and not an iframe
 * --------------------------------
 * The page this replaces was a 1,050px iframe plus twenty lines of JavaScript
 * that widened the legacy shell to 1700px by setting inline styles on #wrapper,
 * #logo, #content and #footer. The dependency on the upstream app is therefore
 * not new -- it is already total. What changes is its failure mode: an iframe
 * that cannot load leaves a blank rectangle inside a page with no title, while
 * a cached JSON payload leaves a page that still has its documentation, its
 * search, its links and its last good answer.
 *
 * What is parsed, and how brittle each part is
 * -------------------------------------------
 * The upstream page renders one NGL viewer per species x method. Each block
 * also carries a `var str = '<acc>'`, and that looks like the obvious anchor --
 * but it is not usable: some blocks put it before their NGL.Stage call and
 * some after, so pairing stage to accession by proximity silently shifts every
 * hit by one from the third viewer onward. It produced a page where sorghum's
 * Foldseek hit was a rice accession, and it looked entirely plausible.
 *
 * The load-bearing parse is the `loadFile('.//alignments/<dir>/AF-<query>...
 * AF-<target>...opt.twist.pdb')` inside each block. That is stable because it
 * is definitionally correct: it is the file the viewer actually loads, and it
 * names the species directory, the query, the target and the model version all
 * at once, so nothing has to be mapped or assumed.
 * Scores and annotations are scraped out of the surrounding markup and are
 * softer -- so a failure there degrades to a hit with no scores rather than to
 * no answer. The viewer, the consensus and every link work without them.
 *
 * Two things upstream that are broken, and are repaired here
 * ---------------------------------------------------------
 * 1. **Every AlphaFold link on the live page is dead.** It points at
 *    `AF-<acc>-F1-model_v3.pdb`; the databank is on v6 and v1 through v5 all
 *    404. That means the query viewer on the upstream page loads a 404 and the
 *    "Download Structure" links go nowhere. This rewrites them to v6 and
 *    reports which accessions no longer resolve at all -- some, like Q6ZF65,
 *    have since been withdrawn from UniProt.
 * 2. **The alignment files send no CORS headers**, so a browser on
 *    maizegdb.org cannot fetch them. fatcatAlignment() proxies them, which is
 *    also where the RMSD gets read out of the REMARK -- a number the upstream
 *    page computes and then never shows.
 */

/* Identifiers this page accepts: gene models, UniProt accessions, gene
   symbols. Anything outside this alphabet cannot match, and refusing it here
   is also what keeps a term out of a URL and off the filesystem. */
const FC_TERM_PATTERN = '/^[A-Za-z0-9_.:-]{1,100}$/';
const FC_ACCESSION_PATTERN = '/^[A-Z0-9]{4,15}$/';

const FC_UPSTREAM = 'https://fatcat.maizegdb.org';

/* The AlphaFold databank version whose files still exist. Bump it here when
   EMBL-EBI retires v6 the way they retired v3; everything else follows. */
const FC_AF_VERSION = 'v6';

/* Upstream is a static 2022 analysis, so a long TTL is honest. It is not
   infinite only because a rebuild there should eventually reach here. */
const FC_CACHE_TTL = 604800;   /* 7 days */

/* The species the upstream app computes hits for, in the order the page shows
   them: closest relative first, because that is the order a maize biologist
   reads them in. The app also hosts alignments for human, cerevisiae and
   pombe, but computes no hit table for them, so they cannot be shown. */
$FC_SPECIES = array(
    'sorghum'     => array('label' => 'Sorghum',     'latin' => 'Sorghum bicolor',    'group' => 'grass'),
    'rice'        => array('label' => 'Rice',        'latin' => 'Oryza sativa',       'group' => 'grass'),
    'soybean'     => array('label' => 'Soybean',     'latin' => 'Glycine max',        'group' => 'dicot'),
    /* The upstream directory really is spelled this way. Keeping the key
       verbatim is what makes the alignment URLs resolve. */
    'arabadopsis' => array('label' => 'Arabidopsis', 'latin' => 'Arabidopsis thaliana', 'group' => 'dicot'),
);

$FC_METHODS = array(
    'diamond'  => array('label' => 'DIAMOND',  'kind' => 'sequence',
                        'note'  => 'sequence similarity'),
    'foldseek' => array('label' => 'Foldseek', 'kind' => 'structure',
                        'note'  => 'fast structure search'),
    'fatcat'   => array('label' => 'FATCAT',   'kind' => 'structure',
                        'note'  => 'flexible structural alignment'),
);

/* Upstream element ids are <species>_<dm|fc|fs>. */
$FC_METHOD_IDS = array('dm' => 'diamond', 'fc' => 'fatcat', 'fs' => 'foldseek');

function fcValidTerm($term) {
    return preg_match(FC_TERM_PATTERN, (string) $term) === 1;
}

function fcValidAccession($value) {
    return preg_match(FC_ACCESSION_PATTERN, (string) $value) === 1;
}

function fcValidSpecies($value) {
    global $FC_SPECIES;
    return isset($FC_SPECIES[(string) $value]);
}

function fcModelUrl($accession) {
    return 'https://alphafold.ebi.ac.uk/files/AF-' . rawurlencode($accession)
         . '-F1-model_' . FC_AF_VERSION . '.pdb';
}

/* -------------------------------------------------------------------------- *
 * Cache
 *
 * Deliberately not dashboardCache(): that caches one collection-wide aggregate
 * per data centre and never expires, which is right for figures that change on
 * a monthly database reload. This caches one entry per protein queried, with a
 * TTL, because what is behind it is somebody else's HTTP service. The safety
 * properties are the same -- atomic write, and every failure path falls back to
 * doing the work live rather than breaking the page.
 * -------------------------------------------------------------------------- */

function fcCacheDir($system) {
    $base = '';
    if (!empty($system['fatcat_cache_path'])) {
        $base = $system['fatcat_cache_path'];
    } elseif (!empty($system['search_cache_path'])) {
        $base = rtrim($system['search_cache_path'], '/') . '/fatcat';
    }
    if ($base === '') { return null; }
    /* Created 0777 rather than 0775 on purpose. This directory is written by
       two different users -- apache serving the page, and whoever runs the
       tools or a CLI probe -- and a 0775 directory owned by whichever got
       there first locks the other out silently: the cache simply never hits
       and every request goes upstream. The sibling dashboard cache is 0777 for
       the same reason. mkdir's mode is masked by the umask, so it is set
       again explicitly. */
    if (!is_dir($base)) {
        if (!@mkdir($base, 0777, true) && !is_dir($base)) { return null; }
        @chmod($base, 0777);
    }
    return is_writable($base) ? $base : null;
}

function fcCacheRead($system, $key, $ttl = FC_CACHE_TTL) {
    $dir = fcCacheDir($system);
    if ($dir === null) { return null; }
    $file = $dir . '/' . sha1($key) . '.json';
    if (!is_file($file)) { return null; }
    if ($ttl > 0 && (time() - filemtime($file)) > $ttl) { return null; }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') { return null; }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/* Set by fcCacheWrite when a write fails. The API puts it in the response so a
   dead cache is visible from the network tab instead of just being slow.
   That matters here: every failure path in this file is deliberately silent and
   falls back to doing the work live, so without this the only symptom of a
   broken cache is that every request goes upstream forever. It happened during
   this page's build -- SELinux labelled a new directory user_home_t and denied
   httpd every write, while the page carried on working perfectly. */
$GLOBALS['fc_cache_error'] = null;

function fcCacheWrite($system, $key, array $payload) {
    $dir = fcCacheDir($system);
    if ($dir === null) {
        $GLOBALS['fc_cache_error'] = 'cache directory is missing or not writable';
        return false;
    }
    $file = $dir . '/' . sha1($key) . '.json';
    $temp = $file . '.' . getmypid() . '.tmp';
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    if (@file_put_contents($temp, $json) === false) {
        /* Almost always SELinux rather than permissions: a directory created
           outside httpd's context is labelled user_home_t and every write is
           denied even at mode 0777. The fix is
           chcon -t httpd_sys_rw_content_t on the directory. */
        $GLOBALS['fc_cache_error'] = 'cache write denied (check the SELinux label on '
                                   . $dir . ')';
        return false;
    }
    if (!@rename($temp, $file)) {
        @unlink($temp);
        $GLOBALS['fc_cache_error'] = 'cache rename failed';
        return false;
    }
    @chmod($file, 0666);
    return true;
}

/* -------------------------------------------------------------------------- *
 * Upstream fetch
 * -------------------------------------------------------------------------- */

/* A plain urllib-style request gets a 403 from Cloudflare; a normal
   User-Agent does not. Sending one is not evasion -- it is the same
   organisation's own service, reached the way a browser reaches it, which is
   exactly what the iframe this replaces was already doing. */
function fcHttpGet($url, $timeout = 20) {
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'MaizeGDB/1.0 (+https://www.maizegdb.org/)',
        ));
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return ($body === false || $status < 200 || $status >= 300) ? null : $body;
    }
    $context = stream_context_create(array('http' => array(
        'header'  => "User-Agent: MaizeGDB/1.0 (+https://www.maizegdb.org/)\r\n",
        'timeout' => $timeout,
    )));
    $body = @file_get_contents($url, false, $context);
    return $body === false ? null : $body;
}

/* -------------------------------------------------------------------------- *
 * Parsing
 * -------------------------------------------------------------------------- */

function fcText($html) {
    $html = preg_replace('#<script\b.*?</script>#is', ' ', $html);
    $html = preg_replace('#<style\b.*?</style>#is', ' ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text));
}

/* Pull one labelled value out of the flattened page text. The upstream markup
   is a run of "Label: value" pairs with no classes to hang off, so the label
   is the only anchor there is. Returns null rather than guessing. */
function fcField($text, $label, $stop = null) {
    $pattern = '/' . preg_quote($label, '/') . '\s*:?\s*(.*?)\s*(?:' .
        ($stop !== null ? preg_quote($stop, '/') :
         'Maize protein|Top \w+ Hit|Diamond score|FoldSeek score|Fatcat score|Fatcat p-value|'
         . 'UniProt annotation|Pannzer annotation|EBI alphafold|NCBI alphafold|Download|'
         . '\w+ gene:|Species:') . '|$)/su';
    if (preg_match($pattern, $text, $match)) {
        $value = trim($match[1], " \t\n\r\0\x0B:-");
        return $value === '' ? null : $value;
    }
    return null;
}

/* The load-bearing parse. Split the document on stage constructions so each
   segment provably belongs to the viewer that opened it, then read the file
   that viewer loads. A block with no loadFile is either the query viewer or a
   genuine no-hit, and the two are told apart by the placeholder image upstream
   substitutes.

   Element ids use their own species abbreviations -- Arabidopsis is `arab`
   while its directory is `arabadopsis` -- so the directory is taken from the
   path rather than derived from the id. That removes a mapping table and the
   chance of it going stale. */
function fcParseHits($html) {
    global $FC_METHOD_IDS;
    $hits = array();
    $parts = preg_split("/new\s+NGL\.Stage\(\s*'([A-Za-z0-9_]+)'\s*\)/",
                        $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    for ($i = 1; $i < count($parts); $i += 2) {
        $id = $parts[$i];
        $body = isset($parts[$i + 1]) ? $parts[$i + 1] : '';
        if (!preg_match('/^[a-z]+_(dm|fc|fs)$/', $id, $idMatch)) { continue; }
        $method = isset($FC_METHOD_IDS[$idMatch[1]]) ? $FC_METHOD_IDS[$idMatch[1]] : null;
        if ($method === null) { continue; }

        $pattern = '#alignments/([a-z]+)/(AF-([A-Z0-9]+)-F1-model_(v\d+)\.'
                 . 'AF-([A-Z0-9]+)-F1-model_v\d+\.opt\.twist\.pdb)#';
        if (preg_match($pattern, $body, $m)) {
            $hits[$m[1]][$method] = array(
                'target'  => $m[5],
                'query'   => $m[3],
                'version' => $m[4],
                'file'    => $m[2],
            );
        }
    }
    return $hits;
}

/* Scores and annotations. Softer than the hit parse by design: the upstream
   markup has no classes, so this keys off visible labels and returns nulls
   where it cannot be sure. A miss costs a blank metric, never an answer. */
function fcParseDetails($html) {
    global $FC_SPECIES;
    $details = array();
    foreach ($FC_SPECIES as $key => $meta) {
        $heading = $meta['label'] . ' Structural Orthologs';
        $start = stripos($html, $heading);
        if ($start === false) { continue; }
        $end = strlen($html);
        foreach ($FC_SPECIES as $other) {
            $next = stripos($html, $other['label'] . ' Structural Orthologs', $start + 1);
            if ($next !== false && $next < $end) { $end = $next; }
        }
        $section = substr($html, $start, $end - $start);
        /* Split on the per-method sub-blocks the app labels "Diamond (ACC)". */
        $parts = preg_split('/(?=(?:Diamond|Fatcat|FoldSeek)\s*\()/i', fcText($section));
        foreach ($parts as $part) {
            if (!preg_match('/^(Diamond|Fatcat|FoldSeek)\s*\(([A-Z0-9]*)\)/i', $part, $head)) {
                continue;
            }
            $method = strtolower($head[1]);
            if ($method === 'foldseek') { $method = 'foldseek'; }
            $details[$key][$method] = array(
                'target'      => $head[2] !== '' ? $head[2] : null,
                'method_score' => fcField($part, ucfirst($head[1]) . ' score'),
                'fatcat_score' => fcField($part, 'Fatcat score \( ? \)'),
                'fatcat_p'     => fcField($part, 'Fatcat p-value \( ? \)'),
                'uniprot_note' => fcField($part, 'UniProt annotation'),
                'pannzer_note' => fcField($part, 'Pannzer annotation\( ? \)'),
                'gene'         => fcField($part, $meta['label'] . ' gene'),
            );
        }
    }
    return $details;
}

function fcParseQuery($html) {
    $text = fcText($html);
    $query = array(
        'uniprot'     => null,
        'uniprot_note' => fcField($text, 'UniProt annotation'),
        'pannzer_note' => fcField($text, 'Pannzer annotation\( ? \)'),
        'v4'          => null,
        'v5'          => null,
        'symbol'      => null,
    );
    if (preg_match('/alphafold\.ebi\.ac\.uk\/files\/AF-([A-Z0-9]+)-F1-model_v\d+\.pdb/', $html, $m)) {
        $query['uniprot'] = $m[1];
    }
    if (preg_match('/B73 Refgen_v4\s*:?\s*(Zm\d+[a-z]+\d+)/i', $text, $m)) { $query['v4'] = $m[1]; }
    if (preg_match('/B73 Refgen_v5\s*:?\s*(Zm\d+[a-z]+\d+)/i', $text, $m)) { $query['v5'] = $m[1]; }
    if (preg_match('/MaizeGDB gene\s*:?\s*([A-Za-z0-9_-]+)/', $text, $m))  { $query['symbol'] = $m[1]; }
    return $query;
}

/* -------------------------------------------------------------------------- *
 * Consensus
 *
 * The whole point of the page. Three methods vote; where they land on the same
 * target the ortholog assignment is corroborated by a sequence method and two
 * independent structural ones, which is as strong as this evidence gets without
 * an experiment. The upstream page has all of this and shows none of it -- a
 * reader has to eyeball twelve accession codes and diff them by hand.
 * -------------------------------------------------------------------------- */
function fcConsensus(array $methods) {
    $votes = array();
    foreach ($methods as $method => $target) {
        if ($target) { $votes[$target][] = $method; }
    }
    if (!count($votes)) {
        return array('target' => null, 'agree' => 0, 'voted' => 0,
                     'level' => 'none', 'by_target' => array());
    }
    uasort($votes, function ($first, $second) { return count($second) - count($first); });
    $top = key($votes);
    $agree = count($votes[$top]);
    $voted = count(array_filter($methods));
    /* Three-way agreement is "confirmed"; two of three "supported"; a
       three-way split is "conflicting" and is worth saying out loud rather
       than quietly showing the first one. */
    $level = 'single';
    if ($voted >= 3 && $agree === 3)      { $level = 'confirmed'; }
    elseif ($agree >= 2)                  { $level = 'supported'; }
    elseif ($voted >= 2 && $agree === 1)  { $level = 'conflicting'; }
    return array(
        'target'    => $top,
        'agree'     => $agree,
        'voted'     => $voted,
        'level'     => $level,
        'by_target' => array_map('count', $votes),
        'methods'   => $votes[$top],
    );
}

/* -------------------------------------------------------------------------- *
 * The assembled answer
 * -------------------------------------------------------------------------- */

function fcCompare($system, $term, &$fromCache = null) {
    $key = 'compare:' . strtolower($term);
    $cached = fcCacheRead($system, $key);
    if ($cached !== null) { $fromCache = true; return $cached; }
    $fromCache = false;

    $html = fcHttpGet(FC_UPSTREAM . '/?uniprot=' . rawurlencode($term), 25);
    if ($html === null || strlen($html) < 500) { return null; }

    $query = fcParseQuery($html);
    if (empty($query['uniprot'])) {
        /* The app answers an unknown identifier with a page that has no
           structure on it at all. That is a real answer -- "no AlphaFold
           protein behind this identifier" -- and not a fetch failure. */
        $payload = array('found' => false, 'query' => $query, 'species' => array());
        fcCacheWrite($system, $key, $payload);
        return $payload;
    }

    $hits    = fcParseHits($html);
    $details = fcParseDetails($html);

    global $FC_SPECIES, $FC_METHODS;
    $species = array();
    foreach ($FC_SPECIES as $skey => $meta) {
        $raw = isset($hits[$skey]) ? $hits[$skey] : array();
        $methods = array();
        foreach ($FC_METHODS as $mkey => $ignored) {
            $methods[$mkey] = isset($raw[$mkey]) ? $raw[$mkey]['target'] : null;
        }
        $consensus = fcConsensus($methods);

        $targets = array();
        foreach ($raw as $mkey => $hit) {
            $accession = $hit['target'];
            if (isset($targets[$accession])) {
                $targets[$accession]['methods'][] = $mkey;
                continue;
            }
            $detail = null;
            foreach (isset($details[$skey]) ? $details[$skey] : array() as $row) {
                if (isset($row['target']) && $row['target'] === $accession) { $detail = $row; break; }
            }
            $targets[$accession] = array(
                'accession' => $accession,
                'methods'   => array($mkey),
                'version'   => $hit['version'],
                /* Proxied rather than linked: the upstream host sends no CORS
                   header, so a browser here cannot fetch it directly. */
                'alignment' => '/search/fatcat/fatcat_api.php?action=alignment'
                             . '&species=' . rawurlencode($skey)
                             . '&query=' . rawurlencode($hit['query'])
                             . '&target=' . rawurlencode($accession)
                             . '&v=' . rawurlencode($hit['version']),
                'model'     => fcModelUrl($accession),
                'detail'    => $detail,
            );
        }
        $species[$skey] = array(
            'key'       => $skey,
            'label'     => $meta['label'],
            'latin'     => $meta['latin'],
            'group'     => $meta['group'],
            'methods'   => $methods,
            'consensus' => $consensus,
            'targets'   => array_values($targets),
        );
    }

    $payload = array(
        'found'   => true,
        'query'   => $query,
        'model'   => fcModelUrl($query['uniprot']),
        'species' => array_values($species),
    );
    fcCacheWrite($system, $key, $payload);
    return $payload;
}

/* -------------------------------------------------------------------------- *
 * Alignment proxy
 *
 * Two jobs. The browser cannot fetch these directly -- the upstream host sends
 * no Access-Control-Allow-Origin, and serves them as application/vnd.palm --
 * and the REMARK header carries the alignment's RMSD, block count and aligned
 * residue count, which the upstream page computes and never displays.
 * -------------------------------------------------------------------------- */

function fcAlignmentUrl($species, $file) {
    return FC_UPSTREAM . '/alignments/' . rawurlencode($species) . '/' . rawurlencode($file);
}

function fcParseRemark($pdb) {
    $out = array('rmsd' => null, 'blocks' => null, 'residues' => null,
                 'chain_a' => null, 'chain_b' => null);
    if (preg_match('/optimizing\s+(\d+)\s+blocks\s+(\d+)\s+residues\s+([\d.]+)\s+rmsd/i', $pdb, $m)) {
        $out['blocks']   = (int) $m[1];
        $out['residues'] = (int) $m[2];
        $out['rmsd']     = (float) $m[3];
    }
    if (preg_match('/protein\s+AF-([A-Z0-9]+)-F1[^\s]*\s+chain\s+A\s+with\s+twisted\s+protein\s+AF-([A-Z0-9]+)-F1/i', $pdb, $m)) {
        $out['chain_a'] = $m[1];
        $out['chain_b'] = $m[2];
    }
    return $out;
}
