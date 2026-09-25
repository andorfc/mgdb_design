<?php
/* file: fusarium.php
 *
 * purpose: /fusarium -- the Fusarium Protein Toolkit, recreated from
 *          fusarium.maizegdb.org on the MaizeGDB design system with the
 *          toolkit's own name, logo and navigation.
 *
 *          this script is loaded by controller.php
 *
 *          /fusarium              home: look up a protein, the tools, the
 *                                 species, downloads
 *          /fusarium/foldseek     structural matches (the upstream Foldseek)
 *          /fusarium/structures   AlphaFold and ESMFold models (the upstream
 *                                 home page's two viewers, as one tool)
 *          /fusarium/effectors    the predicted effector tables
 *          /fusarium/help         overview, data and methods, the genomes
 *          anything else          404, in the toolkit's shell
 *
 *          Each page is its own controller under controllers/fusarium/.
 *
 * The upstream site's own paths -- index.php, effector.php, help.php and
 * protein_structure/index.php?uniprot= -- redirect to their pages here, so a
 * link into the toolkit keeps working when only the host part is changed.
 *
 * The route is new and shadows nothing. There is deliberately no fusarium/
 * directory in the web root: a real directory at that path would stop
 * .htaccess rewriting /fusarium/... to controller.php at all. The data lives
 * under data/fusarium/ for that reason.
 *
 * Rollback: delete this file and the /fusarium route stops resolving.
 */

include_once('./include/fusarium_page.php');
include_once('./include/references_lib.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting controllers/fusarium.php: PAGE: ' . PAGE);

$fptPage = strtolower(trim((string) PAGE));
$fptId   = defined('ID') ? trim((string) ID) : '';

/* The query string, re-encoded from the parsed values rather than copied, so
   a redirect cannot carry anything but plain parameters. */
function fptQueryString(array $only = null) {
    $params = array();
    foreach ($_GET as $key => $value) {
        if (!is_string($value) || ($only !== null && !in_array($key, $only, true))) { continue; }
        $params[$key] = $value;
    }
    return $params ? '?' . http_build_query($params) : '';
}

function fptRedirect($path) {
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: ' . $path);
    exit;
}

/* fusarium.maizegdb.org's own paths. */
$fptLegacy = array('index.php' => '', 'index.html' => '', 'effector.php' => '/effectors',
                   'effector' => '/effectors', 'help.php' => '/help');
if (isset($fptLegacy[$fptPage])) {
    fptRedirect(FPT_ROUTE . $fptLegacy[$fptPage] . fptQueryString());
}
if ($fptPage === 'protein_structure') {
    /* index.php framed search.php; both took ?uniprot=. */
    fptRedirect(FPT_ROUTE . '/foldseek' . fptQueryString(array('uniprot')));
}

$fptRoutes = array(
    ''           => 'home',
    'foldseek'   => 'foldseek',
    'structures' => 'structures',
    'effectors'  => 'effectors',
    'help'       => 'help',
);
if (!isset($fptRoutes[$fptPage])) {
    include('./controllers/fusarium/notfound.php');
    return;
}
include('./controllers/fusarium/' . $fptRoutes[$fptPage] . '.php');
?>
