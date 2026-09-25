<?php
/* file: tools/tests/foldseek_adapter_check.php
 *
 * purpose: prove that search/foldseek/foldseek_lib.php still reads the
 *          upstream Foldseek page correctly.
 *
 * The adapter parses another application's HTML. If that page changes, the
 * failure is silent: /foldseek would show fewer matches, or a match with the
 * wrong coordinates, and nothing would error. So each identifier is fetched
 * fresh from foldseek.maizegdb.org -- never from the cache -- and checked:
 *
 *   FOUND     it resolves, to the accession expected
 *   COUNT     the parsed matches equal the objects in the page's render([...])
 *   SPECIES   every match names one of the eight proteomes; none has over 25
 *   RANGES    each alignment's residues are exactly its stated ranges of both
 *             sequences -- what the superposition's residue pairing relies on
 *   COORDS    each Calpha string holds three numbers per residue of its
 *             sequence, for the query and every match
 *   DOMAINS   as many Pfam rows parsed as PF accessions in the table
 *   HEADER    UniProt accession and B73 v5 gene read from the overview --
 *             or, for the Fusarium analysis, the species and a gene id
 *   SELF      Fusarium only: no match from the searched protein's own
 *             species, and none from F. verticillioides, which is not one of
 *             the nine proteomes -- two facts the page's wording relies on
 *
 * and one identifier the upstream does not know must come back as an empty
 * HTTP 500, which is how the adapter tells "not in the analysis" from an
 * outage.
 *
 * Running it -- from the web root on the development server:
 *   php tools/tests/foldseek_adapter_check.php              # the built-in list
 *   php tools/tests/foldseek_adapter_check.php wx1 P04707   # any identifiers
 *   php tools/tests/foldseek_adapter_check.php --set=fusarium [ids...]
 *                                                   # fusarium.maizegdb.org
 *
 * About a second per identifier. Exit status is the number of failures.
 */

$root = getcwd();
if (!is_file($root . '/search/foldseek/foldseek_lib.php')) {
    fwrite(STDERR, "run this from the web root\n");
    exit(2);
}
include_once($root . '/search/foldseek/foldseek_lib.php');

$args = array_slice($argv, 1);
$set = 'maize';
foreach ($args as $i => $arg) {
    if (strpos($arg, '--set=') === 0) { $set = substr($arg, 6); unset($args[$i]); }
}
if (!fsUseSet($set)) { fwrite(STDERR, "unknown set $set\n"); exit(2); }
$fusarium = $set === 'fusarium';

/* identifier => the accession it must resolve to, and its B73 v5 gene --
   or, for Fusarium, a gene id it must carry and its species */
$cases = $fusarium ? array(
    'FVEG_13850'     => array('A0A139YB70', 'FVEG_13850', 'verticillioides'),
    'FGSG_09786'     => array('I1RZE7', 'FGSG_09786', 'graminearum'),
    'FGRRES_15678_M' => array('A0A098CYZ1', 'FGRRES_15678_M', 'graminearum'),
    'Q00909'         => array('Q00909', 'FGSG_03537', 'graminearum'),
    'FGSG_00001'     => array('A0A098D053', 'FGSG_00001', 'graminearum'),
) : array(
    'bz1'             => array('P16165', 'Zm00001eb374230'),
    'wx1'             => array('Q5NKP6', 'Zm00001eb378140'),
    'Zm00001eb168550' => array('P04707', 'Zm00001eb168550'),
    'Zm00001d045055'  => array('P16165', 'Zm00001eb374230'),
    'Zm00001eb000010' => array('A0A1D6JJ64', 'Zm00001eb000010'),
    'dek1'            => array('Q8RVL1', 'Zm00001eb014030'),
);
if ($args) {
    $cases = array();
    foreach ($args as $id) { $cases[$id] = array(null, null, null); }
}

$failures = 0;
function fail($id, $check, $message) {
    global $failures;
    $failures++;
    printf("FAIL %-16s %-8s %s\n", $id, $check, $message);
}

foreach ($cases as $id => $expect) {
    $started = microtime(true);
    list($status, $body) = fsHttpGet(fsSet('lookup') . rawurlencode($id));
    if ($status !== 200) { fail($id, 'FOUND', 'upstream answered HTTP ' . $status); continue; }

    $built = fsBuildPayloads($body);
    if ($built === null) { fail($id, 'FOUND', 'no render payload parsed'); continue; }
    $summary = $built['summary'];
    $detail = $built['detail'];
    $render = fsParseRender($body);
    $problems = 0;

    if ($expect[0] !== null && $built['accession'] !== $expect[0]) {
        fail($id, 'FOUND', 'resolved to ' . $built['accession'] . ', expected ' . $expect[0]); $problems++;
    }
    if (!$fusarium && $expect[1] !== null && $summary['protein']['v5'] !== $expect[1]) {
        fail($id, 'HEADER', 'v5 gene ' . var_export($summary['protein']['v5'], true) . ', expected ' . $expect[1]); $problems++;
    }
    if ($fusarium && $expect[1] !== null && !in_array($expect[1], (array) $summary['protein']['genes'], true)) {
        fail($id, 'HEADER', 'gene ids ' . json_encode($summary['protein']['genes']) . ' lack ' . $expect[1]); $problems++;
    }
    if ($fusarium && $expect[2] !== null && $summary['protein']['species'] !== $expect[2]) {
        fail($id, 'HEADER', 'species ' . var_export($summary['protein']['species'], true) . ', expected ' . $expect[2]); $problems++;
    }
    if ($summary['protein']['uniprot'] !== $built['accession']) {
        fail($id, 'HEADER', 'overview accession disagrees with the render payload'); $problems++;
    }

    $objects = count($render['alignments']);
    if (count($summary['hits']) !== $objects) {
        fail($id, 'COUNT', count($summary['hits']) . ' parsed of ' . $objects . ' in render()'); $problems++;
    }

    $perSpecies = array();
    foreach ($summary['hits'] as $hit) {
        if ($hit['species'] === null) { fail($id, 'SPECIES', $hit['target'] . ' has an unknown species'); $problems++; continue; }
        $perSpecies[$hit['species']] = (isset($perSpecies[$hit['species']]) ? $perSpecies[$hit['species']] : 0) + 1;
    }
    foreach ($perSpecies as $species => $count) {
        if ($count > 25) { fail($id, 'SPECIES', $species . ' has ' . $count . ' matches, over the 25 kept'); $problems++; }
    }
    if ($fusarium) {
        $own = $summary['protein']['species'];
        if ($own !== null && isset($perSpecies[$own])) {
            fail($id, 'SELF', $perSpecies[$own] . ' matches from its own species, ' . $own); $problems++;
        }
        if (isset($perSpecies['verticillioides'])) {
            fail($id, 'SELF', 'a match from F. verticillioides, which is not a searched proteome'); $problems++;
        }
    }

    $qSeq = $detail['q_seq'];
    $qNumbers = $detail['q_ca'] === '' ? 0 : count(explode(',', $detail['q_ca']));
    if ($qNumbers !== 3 * strlen($qSeq)) {
        fail($id, 'COORDS', 'query has ' . $qNumbers . ' numbers for ' . strlen($qSeq) . ' residues'); $problems++;
    }

    foreach ($summary['hits'] as $hit) {
        $d = $detail['hits'][(string) $hit['n']];
        $qRes = str_replace('-', '', $d['q_aln']);
        $tRes = str_replace('-', '', $d['t_aln']);
        if ($qRes !== substr($qSeq, $hit['q_start'] - 1, $hit['q_end'] - $hit['q_start'] + 1)
            || $tRes !== substr($d['t_seq'], $hit['t_start'] - 1, $hit['t_end'] - $hit['t_start'] + 1)) {
            fail($id, 'RANGES', $hit['target'] . ' alignment does not match its stated ranges'); $problems++;
        }
        $tNumbers = $d['t_ca'] === '' ? 0 : count(explode(',', $d['t_ca']));
        if ($tNumbers !== 3 * strlen($d['t_seq'])) {
            fail($id, 'COORDS', $hit['target'] . ' has ' . $tNumbers . ' numbers for ' . strlen($d['t_seq']) . ' residues'); $problems++;
        }
    }

    $section = '';
    $start = strpos($body, '<h2>PFAM domains</h2>');
    if ($start !== false) {
        $stop = strpos($body, '<h2>Foldseek Structure alignments</h2>', $start);
        $section = substr($body, $start, $stop === false ? 20000 : $stop - $start);
    }
    $expected = preg_match_all('#pfam/(PF\d{5})#', $section);
    if ($expected !== count($summary['domains'])) {
        fail($id, 'DOMAINS', count($summary['domains']) . ' parsed of ' . $expected . ' in the table'); $problems++;
    }

    printf("%s %-16s %-10s %3d matches in %d proteomes, %d domains, %.2f s\n",
        $problems ? 'FAIL' : 'ok  ', $id, $built['accession'], count($summary['hits']),
        count($perSpecies), count($summary['domains']), microtime(true) - $started);
}

/* The one answer that is not a page. */
list($status, $body) = fsHttpGet(fsSet('lookup') . 'notagene99');
if ($status !== 500 || trim($body) !== '') {
    fail('notagene99', 'MISS', 'expected an empty HTTP 500, got ' . $status . ' with ' . strlen($body) . ' bytes');
} else {
    echo "ok   notagene99       an unknown identifier is an empty HTTP 500\n";
}

echo $failures ? "$failures failure(s)\n" : "all checks passed\n";
exit($failures);
?>
