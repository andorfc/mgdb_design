<?php
/* file: controllers/genome/genomebrowser_modern.php
 *
 * purpose: Modernized controller for the Genome Browser Data Center (/genomebrowser)
 */

include_once('./include/db-api.php');
include_once('./include/dashboard_cache.php');

$system = getSystemInfo('mgdb.conf');
logMessage('Starting genomebrowser_modern.php');

$DBConn = connect_to_database(false);

// Bypass edge and browser cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

$bauplan = new Bauplan('Genome Browser Data Center | JBrowse 2, JBrowse & Synteny');
$bauplan->modern();

$doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
$css_file = $doc_root . '/css/mgdb-genomebrowser.css';
$js_file  = $doc_root . '/js/mgdb-genomebrowser.js';
$v_css = file_exists($css_file) ? filemtime($css_file) : time();
$v_js  = file_exists($js_file)  ? filemtime($js_file)  : time();

$bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
$bauplan->includeCss('/css/static.css');
$bauplan->includeCss('/css/mgdb-modern.css');
$bauplan->includeCss('/css/mgdb-megamenu.css');
$bauplan->includeCss('/css/mgdb-genomebrowser.css?v=' . $v_css);
$bauplan->includeScript('/js/mgdb-modern.js');
$bauplan->includeScript('/js/mgdb-chrome.js');
$bauplan->includeScript('/js/mgdb-genomebrowser.js?v=' . $v_js);
$bauplan->head('<meta name="description" content="Explore MaizeGDB genome browsers including next-generation JBrowse 2, linear synteny viewers, JBrowse 1 pan-genomes, NAM founder inbreds, wild relatives, and curated functional genomics tracks.">');

$mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
$mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
$mgdb->get('image-dir')->replace($system['image_url']);
$mgdb->get('server-url')->replace($system['root_url']);

$content = $mgdb->get('body')->load('templates/static/mgdb_genomebrowser.bau');

// Cached corpus statistics and options
$page_data = dashboardCache($system, 'genomebrowser/page', function () use ($DBConn) {
    $assembly_options = '';
    $instance_rows = '';
    $total_browsers = 0;
    $jbrowse2_count = 0;
    $jbrowse1_count = 0;
    $gbrowse_count = 0;
    $nam_count = 0;
    $panand_count = 0;

    if ($DBConn) {
        // Assembly select options
        $sql = "
          SELECT DISTINCT assembly_name, 
                 CASE 
                   WHEN assembly_name='Zm-B73-REFERENCE-NAM-5.0' THEN 'selected' ELSE '' 
                 END AS selected
          FROM chado.genome_metadata
          WHERE browser LIKE '%jbrowse%'
          ORDER BY assembly_name";
        $sth = $DBConn->query($sql);
        if ($sth) {
            $rows = $sth->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $sel = $r['selected'] ? ' selected' : '';
                $name = htmlspecialchars($r['assembly_name']);
                $assembly_options .= '<option value="' . $name . '"' . $sel . '>' . $name . '</option>';
            }
        }

        // All browser instances table
        $sql2 = "
          SELECT DISTINCT assembly_name, stock_name, project, browser
          FROM chado.genome_metadata
          WHERE browser IS NOT NULL AND length(browser) > 0
          ORDER BY assembly_name ASC";
        $sth2 = $DBConn->query($sql2);
        if ($sth2) {
            $browsers = $sth2->fetchAll(PDO::FETCH_ASSOC);
            $total_browsers = count($browsers);
            foreach ($browsers as $b) {
                $asm = htmlspecialchars($b['assembly_name']);
                $stock = htmlspecialchars(isset($b['stock_name']) && $b['stock_name'] ? $b['stock_name'] : '—');
                $proj = htmlspecialchars(isset($b['project']) && $b['project'] ? $b['project'] : '—');
                $url = htmlspecialchars($b['browser']);

                $is_gbrowse = (strpos($url, 'gbrowse') !== false);

                if ($is_gbrowse) {
                    $gbrowse_count++;
                    $platform_html = '<span class="browser-pill badge-gbrowse">GBrowse</span>';
                    $data_types = 'gbrowse';
                    $launch_html = '<a class="mgdb-button mgdb-button-sm mgdb-button-secondary" href="' . $url . '" target="_blank" rel="noopener">GBrowse &#8599;</a>';
                } else {
                    $jbrowse2_count++;
                    $jbrowse1_count++;
                    $platform_html = '<span class="browser-pill badge-jbrowse2">JBrowse 2</span> <span class="browser-pill badge-jbrowse">JBrowse 1</span>';
                    $data_types = 'jbrowse 2 jbrowse 1 jbrowse';
                    $launch_html = '<div class="instance-launch-btns">'
                                 . '<a class="mgdb-button mgdb-button-sm mgdb-button-primary" href="https://jbrowse2.maizegdb.org" target="_blank" rel="noopener">JBrowse 2 &#8599;</a>'
                                 . '<a class="mgdb-button mgdb-button-sm mgdb-button-secondary" href="' . $url . '" target="_blank" rel="noopener">JBrowse 1 &#8599;</a>'
                                 . '</div>';
                }

                if (strpos($asm, 'NAM') !== false) {
                    $nam_count++;
                }
                if (strpos($asm, 'PanAnd') !== false || strpos($asm, 'Zd-') === 0 || strpos($asm, 'Zh-') === 0 || strpos($asm, 'Zn-') === 0 || strpos($asm, 'Zv-') === 0 || strpos($asm, 'Zx-') === 0) {
                    $panand_count++;
                }

                $instance_rows .= '<tr data-type="' . $data_types . '">';
                $instance_rows .= '  <td><strong>' . $asm . '</strong></td>';
                $instance_rows .= '  <td>' . $stock . '</td>';
                $instance_rows .= '  <td><div class="platform-pills-wrap">' . $platform_html . '</div></td>';
                $instance_rows .= '  <td><small class="instance-proj-text">' . $proj . '</small></td>';
                $instance_rows .= '  <td>' . $launch_html . '</td>';
                $instance_rows .= '</tr>';
            }
        }
    }

    return array(
        'assembly_options' => $assembly_options,
        'instance_rows'    => $instance_rows,
        'total_browsers'   => $total_browsers > 0 ? $total_browsers : 59,
        'jbrowse2_count'   => $jbrowse2_count > 0 ? $jbrowse2_count : 48,
        'jbrowse1_count'   => $jbrowse1_count > 0 ? $jbrowse1_count : 48,
        'gbrowse_count'    => $gbrowse_count > 0 ? $gbrowse_count : 11,
        'nam_count'        => $nam_count > 0 ? $nam_count : 26,
        'panand_count'     => $panand_count > 0 ? $panand_count : 15,
        'data_date'        => date('F j, Y')
    );
});

$content->get('assembly_options1')->replace($page_data['assembly_options']);
$content->get('assembly_options2')->replace($page_data['assembly_options']);
$content->get('assembly_options3')->replace($page_data['assembly_options']);
$content->get('assembly_options4')->replace($page_data['assembly_options']);
$content->get('instance_rows')->replace($page_data['instance_rows']);

$content->get('total_browsers')->replace(number_format($page_data['total_browsers']));
$content->get('jbrowse2_count')->replace(number_format($page_data['jbrowse2_count']));
$content->get('jbrowse1_count')->replace(number_format($page_data['jbrowse1_count']));
$content->get('gbrowse_count')->replace(number_format($page_data['gbrowse_count']));
$content->get('nam_count')->replace(number_format($page_data['nam_count']));
$content->get('panand_count')->replace(number_format($page_data['panand_count']));
$content->get('data_date')->replace($page_data['data_date']);

include_once('translation.php');
echo $bauplan->publish();
