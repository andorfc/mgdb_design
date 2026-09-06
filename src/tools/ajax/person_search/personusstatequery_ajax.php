 
<?PHP
  /** file: persondstatequery_ajax.php
 *
 * purpose: run the person USA location queries and display results
 *
 * history:
 *   08/30/16  jportwood - created
 */
  require("../../../include/db-api.php");
  include_once("../../../lib/Bauplan.php");
  
  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $DBConn = connect_to_database();


  $state = validate_input($DBConn, getCGIParam('state', 'GP', ''));
  $state_full = validate_input($DBConn, getCGIParam('state_full', 'GP', ''));

  $bauplan = new Bauplan('Person Location Query (by State): People Inside United States');
  $bauplan->includeCss('/css/index.css');
  $bauplan->head('<!--[if IE 6]><link rel="stylesheet" href="/ie/ie6.css" type="text/css" media="screen" /><![endif]--><!--[if lt IE 9]><link rel="stylesheet" type="text/css" href="/ie/ie.css" /><link rel="stylesheet" type="text/css" href="/css/index_ie.css" /><![endif]-->');
  $mgdb = $bauplan->template()->load('../../../templates/community/person-us-state-search.bau');

  $sql = "
    SELECT P.ID, NAME, NAME_FIRST, NAME_LAST
    FROM PERSON P JOIN ID_NUM ON P.ID = ID_NUM.ID 
    WHERE ID_NUM.CURATION_LVL = 0 
          AND LOWER(STATE) LIKE " . $DBConn->quote(strtolower($state)) . " 
    ORDER BY NAME";
  $stmt = make_query($DBConn, $sql);
  $rows = get_all_rows($stmt);
  
  $count = ($rows) ? count($rows) : 0;
  $mgdb->get('number_of_people')->replace($count);
  
  if ($count > 0) {
    $mgdb->get('state')->replace($state_full);
    
    for ($i=0; $i<$count; $i++) {
      if ($rows[$i]['name'] != '') {
        $rows[$i]['display_name'] = $rows[$i]['name'];
      }
      else {
        $rows[$i]['display_name'] = $rows[$i]['first_name'] . ', ' 
                                  . $rows[$i]['last_name'];
      }
      // So Bauplan won't choke:
      unset($rows[$i]['name']);
      unset($rows[$i]['name_first']);
      unset($rows[$i]['name_last']);
    }
    $mgdb->get('person-list')->loop($rows);
    
    $mgdb->get('success')->unmute();
  }
  else {
    $mgdb->get('failure')->unmute();
  }
  
  $bauplan->publish();
?>
