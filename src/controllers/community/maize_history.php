<?php
/* file: maize_history.php
 * 
 * purpose: build the maize history page.
 *
 * history:
 *  04/03/20  eksc  created
 */
 
  include_once('./lib/Bauplan.php');
  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
  
  $DBConn = connect_to_database();
  if (!$DBConn) {
    echo "Unable to connect to database.";
    exit;
  }
  
  $bauplan->title("Maize History");  
  $bauplan->includeCss('/css/maize_history.css');
  $bauplan->includeScript('/js/reveal.js');
  
  $tmpl = $mgdb->get('body')->load('templates/community/maize_history.bau');
  
  if (ID && ID == 'classic_reads') {
    $tmpl->get('display-classic-reads')->unmute();
  }
  if (ID && ID == 'image_galleries') {
    $tmpl->get('display-image-galleries')->unmute();
  }
  
  $events = getEvents($DBConn);
  showEvents($tmpl, $events);


//////////////////////////////////////////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////

function getEvents($DBConn) {
  $sql = "SELECT * FROM mgdb.maize_history ORDER by year";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//getEvents


function showEvents($tmpl, $events) {
  $left = true;
  
  $html = '';
  $count = 0;
  foreach ($events as $e) {
    $count++;
    
    // Start a new template to hold the event
    $bauplan = new Bauplan('');
    
    if ($left) {
      $sub_tmpl = $bauplan->template()->load('templates/community/maize_history-event-left.bau');
    }
    else {
      $sub_tmpl = $bauplan->template()->load('templates/community/maize_history-event-right.bau');
    }
    
    // Event details
    $sub_tmpl->get('event-id')->replace("event$count");
    $sub_tmpl->get('event-type')->replace($e['event_type']);
    $sub_tmpl->get('event-date')->replace($e['year']);
    $sub_tmpl->get('event-title')->replace($e['title']);
    if (isset($e['description'])) {
      $sub_tmpl->get('event-description')->replace(mgdb_safe_html($e['description']));
    }
    
    // Publication, if any
    if (isset($e['publication']) && trim($e['publication']) != '') {
      $pub = 'Publication: ';
      if (isset($e['pub_link']) && trim($e['pub_link']) != '') {
        $pub .= "<a href='" . $e['pub_link'] . "'>" . $e['publication'] . "</a>";
      }
      else {
        $pub .= $e['publication'];
      }
      $sub_tmpl->get('event-publication')->replace($pub);
    }
    
    // Image, if any
    if (!isset($e['image_name']) || $e['image_name'] == '') {
      $sub_tmpl->get('no-event-image')->unmute();
    }
    else {
      $sub_tmpl->get('event-image')->replace($e['image_name']);
      if (isset($e['image_caption'])) {
        $sub_tmpl->get('event-image-caption')->replace($e['image_caption']);
      }
      if (isset($e['image_credit'])) {
        $sub_tmpl->get('event-image-credit')->replace($e['image_credit']);
      }
      
      $sub_tmpl->get('event-image-block')->unmute();
    }
    
    $html .= $sub_tmpl->getHTML();

    $left = !$left;
  }//each event
  
  $tmpl->get('events')->replace($html);
}//showEvents
?>
