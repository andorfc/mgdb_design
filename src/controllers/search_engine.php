<?PHP
/* file: search_engine.php
 *
 * purpose: main controller for search-all-data
 *
 *  All search results are cached in cache/search/
 *
 *  Flow-of-control from Search menu item:
 *    open_main_search() in search.js called when 'Search' selected from menu
 *
 *    templates/home/megamenu/search.bau
 *    search/search-box-results.php
 *       both show search form and call run_main_searchbox(), in search.js
 *       1. run_main_serchbox()
 *         1a. handle all-data and special cases
 *         OR
 *         1b. handle individual search
 *
 *    templates/search_engine/search.bau    - creates result templates and 
 *                                            starts all of the data type 
 *                                            searches
 *
 *    js/search_engine.js                   - ajax calls for data type searches
 *       1. doCount() starts counts for each data type
 *       2. doBody()  starts all data type searches
 *       3. doPage()  displays page of results for each data type
 *
 *    search/alldata/searchResultsProxy.php - farms out searches by data type
 *                                            ACTUAL SEARCH URLS ARE HERE
 *
 *    search/alldata/*.php                  - search scripts for data types
 *                                            that lack a data hub or that
 *                                            require a specialized search    
 *
 *  Flow of control from search box:
 *    1. show search-in-progress page (searchall.bau).
 *    2. search-in-progress page calls global_search_action() ...
 *    3. ... which calls search_engine again with search request
 *    4. control passed to searchall.php with partial bauplan
 *    5. request search is executed and passed back via the partion bauplan object
 *     
 * history:
 *  7/9/2013 andorf - Created
 *  6/17/14  eksc   - Cleaned up and repaired searches for various data types
 *  2/12/26  eksc   - significant revision and update
 */

  include('include/gene_center_lib.php');
  include('controllers/search_engine/searchall_lib.php');    

logVarDump($_POST, "\nPOST parameters in search_engine.php\n");

  $source = getCGIParam('global_search_source', 'GP', '');
  $term = getCGIParam('global_search_term', 'GP', '');
  $type = getCGIParam('global_search_type', 'GP', '');

  // sanitze $term
  $DBConn = connect_to_database();
  $term = sanitizeSearchTerm($term, $DBConn);
  
  if (PAGE == 'autocomplete') {
    // JSON-only suggestion endpoint for the modern header search.
    include('controllers/search_engine/autocomplete.php');
    exit;
  }

  if (PAGE == 'populate_table') {
    // populating a table; don't need bauplan
    include('search_engine/searchall_tasks.php');
    exit;
  }

  // "Static web pages" is a site: query against Google, and always was. It used
  // to get there by rendering the whole legacy in-progress page and letting
  // js/search_engine.js set document.location once that page had loaded — a
  // 39 KB round trip and a flash of pre-redesign chrome on the way out of the
  // site. The redirect is the whole behaviour, so send it directly.
  if (PAGE == 'searchall' && $type == 'goog' && $term !== '') {
    header('Location: https://www.google.com/search?q=site%3A*.maizegdb.org+' . rawurlencode($term), true, 302);
    exit;
  }

  // All-data, per-type and MaizeGDB ID results on the modern design system.
  // Renders a shell and lets search/searchall/searchall_api.php return the
  // records, rather than running the whole search server-side behind a loading
  // GIF. 'goog' still goes to the site-wide Google search below.
  //
  // The 'id' category used to be answered before this by searchall_id.php,
  // which interpolated the term into `WHERE idn.id=$term` and rendered nothing
  // at all when the lookup failed — a non-numeric term returned a 200 with an
  // empty body. searchall_modern.php does the lookup itself and falls through
  // to the search when the term is not a live id.
  //
  // Reached both by POST from the header search and by GET, so a result page
  // can be linked to. The legacy path is archived at legacy/searchall/.
  if (PAGE == 'searchall' && $type != 'goog') {
    include('controllers/search_engine/searchall_modern.php');
    exit;
  }

  if ($type == 'id') {
    // Any other page still reaching the id search keeps the old path.
    include_once('controllers/search_engine/searchall_lib.php');
    include_once('controllers/search_engine/searchall_id.php');
    exit;
  }


  // If we get here, we will need to fire up bauplan to build page content.
  //   This page shows the busy icon and contains javascript to build the results.
  if ($source == 'search_box') {
    // Build a full webpage showing that searching is in progress
    $bauplan = new Bauplan('Search MaizeGDB');
    $bauplan->includeCss('../css/static.css');
    
    $mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
    $header = $mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');
    $mgdb->get('blast_url')->replace($system['BLAST_URL']);
    $mgdb->get('image-dir')->replace($system['image_url']);
    $mgdb->get('server-url')->replace($system['root_url']);
    $tmpl = $mgdb->get('body')->load('./templates/search_engine/searchall.bau');
    $tmpl->get('global_search_term')->replace($term);
    $tmpl->get('global_search_type')->replace($type);
    
    include_once('translation.php');  
    $bauplan->publish();
  }//full webpage
  
  else if ($source == 'javascript') {
    // Build a bauplan object for inner content
    $bauplan = new Bauplan();
    $bauplan->includeCss('../css/static.css');
    
    // Using the pan_gene.css rather than copying all styles to a new file
    //   This creates collapsable divs as used in pan-gene pages
    $bauplan->includeCss('../css/pan_gene.css');
    
    // Pass controll to searchall.php
    include('controllers/search_engine/searchall.php');
    
    $bauplan->publish();
  }//'javascript' -> internal content

?>
