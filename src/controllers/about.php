<?PHP
/* file: about.php
 *
 * purpose: main controller for all about pages
 *
 *
 * history:
 *  7/13/2011 andorf - Fixed old log-in code; throw 404 if file does not exist or is empty
 */
 
  /* /about is not a page, and never was.
   *
   * This controller serves the whole /about/<page> namespace, but the bare
   * route carries no PAGE, so the $page_filename built at the foot of this file
   * resolves to "controllers/about/.php". That file does not exist, so the
   * request fell through to reportError() and published the shell with an empty
   * body: HTTP 200 and ~39 KB of chrome carrying no content, byte-identical to
   * a deliberately bogus /about/<nonsense>. A link checker reading the status
   * code saw a healthy page, and the breadcrumbs on /contact and /cite both
   * pointed here.
   *
   * Identical to the /community bug fixed on 2026-09-04, and fixed the same way
   * (Carson, 2026-09-05). There is no About landing page: the About megamenu is
   * the index, and its own heading action is "Explore site map", so the bare
   * route goes to the site map's About section -- a real, expanded, four-entry
   * section of a modern page.
   *
   * PAGE is compared against null and '' rather than tested with !PAGE so that a
   * path segment of "0" is not swept up with them. controller.php routes
   * nothing but CONTROLLER == 'about' into this file, but the check is kept
   * explicit to match controllers/community.php, where /person and /annotator
   * make it load-bearing.
   *
   * Rollback: delete this block and /about serves the empty shell again.
   */
  if (CONTROLLER == 'about' && (PAGE === null || PAGE === '')) {
    header('Location: /sitemap#sm-about', true, 301);
    exit;
  }

  /* /about/api retired 2026-09-06 (Carson).
   *
   * A 2012 page titled "MaizeGDB API" that is not an API: it is instructions for
   * linking to MaizeGDB from your own site, and its instructions are now wrong.
   * It tells readers to append a GenBank accession to
   * http://claude.maizegdb.org/data_center/sequence/ -- a route retired on
   * 2026-09-01 that now 301s to /genome. Retired to /contact, the same
   * destination /faq took, so anyone who arrives wanting linking help can ask.
   *
   * The real API is controllers/api.php at /api, which is unrelated to this page
   * and is not retired. The redesign status scan listed it in the page migration
   * queue because it is JSON rather than HTML; tools/redesign_status.py now
   * excludes it.
   *
   * Rollback: delete this block and /about/api serves the legacy page again.
   */
  if (PAGE == 'api') {
    header('Location: /contact', true, 301);
    exit;
  }

  // The site map opts in to the shared responsive design-system shell.
  // All other About routes continue through the legacy controller unchanged.
  if (PAGE == 'sitemap') {
    include('controllers/about/sitemap.php');
    return;
  }

  /* The NCGA podcast series on the modern design system.
   *
   * Hooked here because the modern controller creates its own Bauplan and
   * publishes it, so it has to run before the legacy shell below is built. The
   * bare /podcast alias -- what the About megamenu and the site map link -- does
   * not come through this file at all: it reaches redirect.php, and is taken by
   * controllers/podcast.php. Same arrangement as /videos in
   * controllers/community.php.
   *
   * The modern controller returns false without publishing if data/
   * ncga_podcasts.json is missing, in which case this falls through to the
   * legacy page below rather than serving an empty shell.
   *
   * Rollback: delete this block and controllers/podcast.php.
   * controllers/about/podcast.php and its three templates are untouched.
   */
  if (PAGE == 'podcast') {
    if (include('controllers/about/podcast_modern.php')) {
      return;
    }
  }

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid =   getCookie('userid', false);

  $bauplan = new Bauplan('About MaizeGDB');
  $bauplan->includeCss('../css/static.css');
  
  $css_filename = "../css/" . PAGE . ".css";
  if (file_exists($css_filename)) {
    $bauplan->includeCss($css_filename);
  } 
  
  if (preg_match('/(?i)msie [1-8]/',$_SERVER['HTTP_USER_AGENT'])) {
    // if IE<=8
    $bauplan->preHTML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">');
  }
  else {
    // if IE>8
    $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  }
  $bauplan->head('<!--[if IE 6]><link rel="stylesheet" href="/ie/ie6.css" type="text/css" media="screen" /><![endif]--><!--[if lt IE 9]><link rel="stylesheet" type="text/css" href="/ie/ie.css" /><![endif]-->');
  
  $mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
  $header = $mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');
  $mgdb->get('image-dir')->replace($system['image_url']);
  $mgdb->get('server-url')->replace($system['root_url']);
  
  // Toggle log in/out section based on login status
  if ($username && $password && $userid) {
    $mgdb->get('logout')->toggle();
    $mgdb->get('username')->replace($username);
  }
  
  $page_filename = "controllers/" . CONTROLLER . "/" . PAGE . ".php";
  $page_filename_id = "controllers/" . CONTROLLER . "/" . PAGE . ID . ".php";
 
  if (file_exists($page_filename_id)) {
    include ($page_filename_id);
  } 
  else if (file_exists($page_filename)) {
    include ($page_filename);
  } 
  else{
    reportError("Unable to find page $page_filename");
    $mgdb->get('body')->load('templates/error/error-404.bau');
  }

  // Bauplan variables in global templates
//  $mgdb->get('gbrowse_url')->replace($system['GBROWSE_URL']);
//  $mgdb->get('blast_url')->replace($system['BLAST_URL']);

  include_once('translation.php');
  $bauplan->publish();
?>
