<?PHP
/* file: ai.php
 *
 * purpose: main controller for /ai — AI and machine learning resources
 */

$system = getSystemInfo('mgdb.conf');
logMessage('Starting controllers/ai.php');

// Bypass Cloudflare and browser edge cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('AI and Machine Learning Resources | MaizeGDB');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-ai.css';
$js_file = $doc_root . '/js/mgdb-ai.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js = file_exists($js_file) ? filemtime($js_file) : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-ai.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-ai.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Maize variant, protein structure, functional annotation, and machine-learning-ready datasets and tools at MaizeGDB.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');

$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$mgdb->get('body')->load('templates/static/mgdb_ai.bau');

include_once('translation.php');
if ($mgdb->has('blast_url')) {
    $mgdb->get('blast_url')->replace($system['BLAST_URL']);
}

$bauplan->publish();
?>
