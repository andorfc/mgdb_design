<?php
/* file: map_text.php
 *
 * purpose: download map data
 *
 * history origin - unknown
 *  02/27/19  eksc  changed to use common query in map_lib.php to get map data
 *  2026-09-09  Fixed two faults, both live for years.
 *
 *      The invalid-id branch called page_header_new() and page_footer(), which
 *      went with the pre-redesign chrome, so under PHP 8 every bad or missing
 *      id got `Call to undefined function page_header_new()` instead of the
 *      "Invalid Map!" message it was written to show. It also read $SITE_URL,
 *      $username, $password and $userid, none of which exist in this scope.
 *      This endpoint answers with a file and never with a page, so the branch
 *      now replies in plain text with a real status -- 400 for an id that is
 *      not a number, 404 for one that matches no map -- and no chrome.
 *
 *      The id was interpolated straight into the WHERE clause, and
 *      getCGIParam() does no escaping whatsoever (include/gp_lib.php:181 -- it
 *      is trim() and nothing else), so /map_text?id=<payload> was a live SQL
 *      injection. The id is validated as an integer before it reaches the
 *      query, and that integer is what the query, getMapData() and the
 *      attachment filename now carry.
 *
 *      The download headers moved from the top of the file into the success
 *      branch. Sent unconditionally, they made the error reply arrive as a
 *      Map-Data-<id>.txt attachment the reader had to open in order to find
 *      out that the map did not exist.
 */
 
  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');
  include_once('./include/map_lib.php');
logMessage("map_text start");

  $id = getCGIParam('id', 'G', false);

  // A positive integer or nothing. FILTER_VALIDATE_INT rejects "12 OR 1=1",
  // "1;--" and the false default alike, and $map_id is what every later use
  // reads -- the query, getMapData() and the attachment filename.
  $map_id = filter_var($id, FILTER_VALIDATE_INT);
  if ($map_id !== false && $map_id <= 0) {
    $map_id = false;
  }

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');
logMessage("map_text 1");
  
  $DBConn = connect_to_database();
logMessage("map_text 2");

  $arrMap = false;
  if ($map_id !== false) {
    $map_query = "
      SELECT m.id, m.name, m.linkage_group 
      FROM map m, id_num 
      WHERE m.id = id_num.id AND id_num.curation_lvl = 0 AND m.id = $map_id";
    $statement_map = make_query($DBConn, $map_query,1);
    $arrMap = retrieve_row($statement_map);
  }

  if (isset($arrMap['id'])) {
    // Sent here, not in the preamble: the branch below is not a download.
    header('Content-Description: File Transfer');
    header('Content-type: text/html');
    header('Content-Disposition: attachment; filename=Map-Data-' . $map_id . '.txt');

    $map_data = getMapData($map_id, $DBConn);
//logVarDump($map_data, "All map data:\n");
    
    print "MaizeGDB: Details of Map " . fix_map_name($arrMap['name']) . "\n";

    // Construct and print headings
    $headings = array('Locus', 'Coordinate', 'Bin');
//logVarDump($map_data['assemblies'], "All assemblies:\n");
    if (count($map_data['assemblies']) > 0) {
      foreach ($map_data['assemblies'] as $a) {
        $headings = array_merge($headings, array($a.'_gene_model', $a.'_chr', $a.'_start', $a.'_end'));
      }
    }//there are physical positions
    array_push($headings, 'Sequence');
logVarDump($headings, "Headings for output:\n");

    print implode("\t", $headings) . "\n";

    $gene_count = 0;
    $previous_id = 0;

    $map_coord_data = $map_data['locus_positions'];
    foreach ($map_coord_data as $arrLoci) {
    	$current_id = $arrLoci['id'];
			if ($current_id != $previous_id) {
				$name       = $arrLoci['name'];
				$coordinate = getCoordinate($arrLoci, $arrLoci['value']);
				$bin        =  $arrLoci['bin'];
				$sequence   = (isset($arrLoci['sequence'])) ? $arrLoci['sequence'] : '';
				
				$fields = array($name, $coordinate, $bin);

        if (count($map_data['assemblies']) > 0) {
          foreach ($map_data['assemblies'] as $a) {
            $fields = array_merge($fields, array(
              $map_data['physical_positions'][$a][$name]['gene_model_name'],
              $map_data['physical_positions'][$a][$name]['chr'],
              $map_data['physical_positions'][$a][$name]['gm_start'],
              $map_data['physical_positions'][$a][$name]['gm_end']
            ));
          }
        }//there are physical positions
        array_push($fields, $sequence);
logVarDump($fields, "Fields for output:\n");
              
				print implode("\t", $fields) . "\n";;
			}
			
			$previous_id = $current_id;
    }//foreach
  }//ID submitted
   
  else {
    // Plain text, because a client that asked for a map file should be told in
    // the format it asked for rather than handed a web page. 400 separates a
    // malformed id from 404's "no such map", which is what tells the caller
    // whether to fix the link or the id.
    $status = ($map_id === false) ? 400 : 404;
    header('Content-type: text/plain; charset=utf-8', true, $status);

    print "MaizeGDB map download\n";
    print "---------------------\n\n";
    if ($map_id === false) {
      print "This address needs a map id: /map_text?id=<number>\n";
    }
    else {
      print "No map has id " . $map_id . ", or it is not publicly curated.\n";
    }
    print "\nMaps and their ids are listed at https://www.maizegdb.org/data_center/map\n";
  }
  
  // Prevent any further processing after this script completes
  exit;

?>
