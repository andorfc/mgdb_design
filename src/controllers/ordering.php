<?PHP
/* file: ordering.php
 *
 * purpose: main controller for all ordering pages
 *
 *
 * history:
 *  7/13/2011 andorf - Fixed old log-in code; throw 404 if file does not exist or is empty
 */
 
  /* The stock order basket on the design system. Everything except `submit`:
     that branch redirects to a live order form at the Maize Genetics
     Cooperation Stock Center and stays in controllers/ordering/stock.php,
     untouched.

     The guard sits above `new Bauplan` because the modern controller builds its
     own document and publishes it.

     Rollback: delete this block and templates/ordering/stock.bau serves the
     route again; nothing under templates/ordering/ was modified. */
  if (PAGE == 'stock' && ID != 'submit') {
    if (include('controllers/ordering/stock_modern.php')) {
      return;
    }
  }

  /* /ordering/coop_order -- the Maize Genetics Cooperation Stock Center request
     form, on the design system. Only *page* renders go to the modern
     controller: the confirmation screen (ID == 'completed') and the form
     itself. Everything the form posts back carries an `action` field
     (add-stock, check-stock, get-list, remove-stock, get-comment, clear-order,
     force-add-stock, submit) and is left to controllers/ordering/coop_order.php
     below, so the basket store and the order email are untouched.

     Rollback: delete this block and templates/ordering/coop_order.bau serves the
     route again; nothing under controllers/ordering/coop_order.php was modified. */
  if (PAGE == 'coop_order' && !getCGIParam('action', 'GP', false)) {
    if (include('controllers/ordering/coop_order_modern.php')) {
      return;
    }
  }

  $username = getCookie('username', false);
  $password = getCookie('password', false);
  $userid =   getCookie('userid', false);

  $bauplan = new Bauplan('Stock request');
  $bauplan->includeCss('/css/static.css');
  
  $css_filename = "/css/" . PAGE . ".css";
  if (file_exists($system['root_dir'] . $css_filename)) {
    $bauplan->includeCss($css_filename);
  } 
  
  $bauplan->includeScript('https://ajax.googleapis.com/ajax/libs/jquery/1.3.2/jquery.min.js');
  $bauplan->includeScript('https://ajax.googleapis.com/ajax/libs/jqueryui/1.5.3/jquery-ui.min.js');
  if(preg_match('/(?i)msie [1-8]/',$_SERVER['HTTP_USER_AGENT'])) {
    // if IE<=8
    $bauplan->preHTML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "https://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">');
  }
  else {
    // if IE>8
    $bauplan->preHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">');
  }
  $bauplan->head('<!--[if IE 6]><link rel="stylesheet" href="/ie/ie6.css" type="text/css" media="screen" /><![endif]--><!--[if lt IE 9]><link rel="stylesheet" type="text/css" href="/ie/ie.css" /><![endif]-->');
  
  // Load javascript functions specific to PAGE
  $javascript = "/js/" . PAGE . ".js";
  if (file_exists($system['root_dir'] . $javascript)) {
    $bauplan->includeScript($javascript);
  }

  $mgdb = $bauplan->template()->load('templates/maizegdb-main.bau');
  $header = $mgdb->get('megamenu')->load('templates/home/maizegdb_header.bau');
  
  // Toggle log in/out section based on login status
  if ($username && $password && $userid) {
    $mgdb->get('logout')->toggle();
    $mgdb->get('username')->replace($username);
  }
  // Bauplan variables in global templates
  $mgdb->get('blast_url')->replace($system['BLAST_URL']);
  $mgdb->get('server-url')->replace($system['root_url']);
  
  
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
    http_response_code(404);
    /* The modern 404 rather than error-404.bau: that template's block is
       named with its .bau suffix, so Bauplan never matched it and this
       branch rendered whatever body was already loaded, with a 200. */
    include('controllers/not_found.php');
    exit;
  }

  include_once('translation.php');
  $bauplan->publish();

?>
