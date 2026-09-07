<?php
/* file: stock_decryption.php
 *
 * purpose: main controller for /stock_decryption -- how to read the handwriting
 *          on a Maize Genetics Cooperation Stock Center seed packet.
 *
 * Why this file is at the top level
 * --------------------------------
 * controller.php checks controllers/<CONTROLLER>.php first and only falls
 * through to redirect.php when there is none. redirect.php loads
 * templates/maizegdb-main.bau -- the *legacy* main -- before it looks for a
 * page, so anything served that way carries index.css, background_static.css,
 * ie6.css and the shadowbox sheet no matter how modern its own markup is. The
 * legacy page was reached exactly that way: redirect.php ->
 * controllers/static/stock_decryption.php.
 *
 * There is no second route. controllers/static/stock_decryption.php and
 * templates/static/stock_decryption.bau and stock_decryption-content.bau are
 * untouched and archived in legacy/stock-decryption/; deleting this file hands
 * the route straight back to them. That is the whole rollback.
 *
 * What changed from the legacy page
 * ---------------------------------
 * The explanations of A, B, C and D are the ones the page has always carried.
 * What changed is everything around them:
 *
 *   - **The packet was unreadable.** images/SeedPacket.jpg is 402x661 and the
 *     page drew it at `width=100 height=165` -- a quarter-size thumbnail of the
 *     one thing the page exists to teach you to read. It is now shown at its
 *     own size, and each of its four regions is a link into the paragraph that
 *     explains it.
 *   - Only region A had anything clickable. B, C and D were prose with no way
 *     into or out of them.
 *   - The breadcrumb's middle link was `<a href=''>Data Center </a>` -- an empty
 *     href, which navigates to the current page -- and the row closed with a
 *     `</font>` that was never opened.
 *   - The symbols were described in running prose only, so a reader who came
 *     back wanting to know what `S-#` or a circled X meant had to re-read three
 *     paragraphs. They are now also a table.
 *   - css/stock_decryption.css carried a bare `img { border: 0 }`. It is not
 *     loaded here; on a modern page an unscoped element selector like that
 *     reaches the megamenu and the hero as well.
 *
 * The two record links the legacy page had were checked and are right\:
 * /data_center/stock?id=14067 is stock 222B and /data_center/variation?id=15217
 * is TB-3La-2S\(6270\), which are what the packet's own top-left lines say.
 *
 * No data. The stock record for 222B carries no pedigree text and no genotype
 * string, so there is nothing on this page that could be read from it; the
 * packet's B, C and D lines exist only on the packet. Cost: no SQL, no JSON,
 * nothing to cache.
 *
 * history
 *  09/06/26  claude  created
 */

  $system = getSystemInfo('mgdb.conf');
  logMessage('Starting modern stock_decryption.php');

  $doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
            ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';

  $bauplan = new Bauplan('Reading a Stock Center seed packet | MaizeGDB');
  $bauplan->modern();
  $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  $bauplan->includeCss('/css/static.css');
  $bauplan->includeCss('/css/mgdb-modern.css');
  $bauplan->includeCss('/css/mgdb-megamenu.css');
  /* The shared Data Hub shell, before the page sheet -- the ground, the white
     section cards, their coloured top edges, the shared table and note, and the
     green Related resources panel. */
  $bauplan->includeCss('/css/mgdb-hub.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-hub.css'));
  $bauplan->includeCss('/css/mgdb-stock-decryption.css?v=' . (int) @filemtime($doc_root . '/css/mgdb-stock-decryption.css'));
  $bauplan->includeScript('/js/mgdb-modern.js');
  $bauplan->includeScript('/js/mgdb-chrome.js');
  /* Four sections, so the tab bar needs the shared scrollspy or its active
     state never leaves the first tab. */
  $bauplan->includeScript('/js/mgdb-stock-decryption.js?v=' . (int) @filemtime($doc_root . '/js/mgdb-stock-decryption.js'));
  $bauplan->head('<meta name="description" content="How to read a Maize Genetics Cooperation Stock Center seed packet: the stock number and focus genotype, the year-row-plant pedigree, the parents\' genotypes, and the kernel count, with a reference for every symbol.">');

  $mgdb = $bauplan->template()->load('templates/maizegdb-main-modern.bau');
  $mgdb->get('megamenu')->load('templates/home/maizegdb_header_modern.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);

  $mgdb->get('body')->load('templates/static/mgdb_stock_decryption.bau');

  include_once('translation.php');
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  $bauplan->publish();
  exit;
?>
