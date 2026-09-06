<?php
/*
 * file: startsearch.php
 *
 * purpose: Read input information and start the requested search(es).
 *
 * history:
 *   10/20/09  eksc  created
 */

include_once($pc_system['root_dir'] . '/inc/lib.php');

include_once($pc_system['search_dir'] . '/inc/search_constants.php');
include_once($pc_system['search_dir'] . '/inc/search_helpers.php');
include_once($pc_system['search_dir'] . '/inc/seq_search_helpers.php');
include_once($pc_system['search_dir'] . '/inc/BLAST_helpers.php');

$pc_system = (!$pc_system) ? getSystemInfoPC('project_search') : $pc_system; 

include_once($pc_system['MaizeGDB_home'] . '/include/mail.php');


function startSearch() {
   global $pc_system;

   // reset expiration on UID cookie
   resetUID();
   $uid = getUID();
   
   // Use this connection from here on
   $conn = connectToDatabase();
   
   // Check for a specific job id
   $job_id = validate_input($conn, getCGIParamPC('job_id', 'PG', false));
//logMessagePC("job_id: $job_id");
   if ($job_id) {
      restoreSearchResults($job_id);
      return;
   }

   // See if this is a cached job set
   $job_set_id 
   		= trim(validate_input($conn, 
   		                      getCGIParamPC('job_set_id', 'PG',
                                          getSessionParam('job_set_id', false))));
//logMessagePC("job_set_id: $job_set_id");
   if ($job_set_id != false) {
      restoreSearchResults($job_set_id);
      return;
   }

   $email = validate_input($conn, getCGIParamPC('notify_email', 'PG', ''));
   $email_results = ($email != '');

   // Create a unique job set id and job set name
   $job_set_id = getUniqueID(12);
   $default_job_set_name = date("d-M-Y G:i");
   $job_set_name = validate_input($conn, 
                                  getCGIParamPC('job_set_name', 'PG',
                                                $default_job_set_name));
   if ($job_set_name == '') {
      $job_set_name = $default_job_set_name;
   }
   $job_set_name = "search: $job_set_name";
   $search_stats = getSearchStats();
   
   // Get CGI parms
   $selected_searches = validate_input($conn, getCGIParamPC('selected_search', 'P', ''));
   $max_evalue        = validate_input($conn, getCGIParamPC('max_evalue', 'P', 0));
   $max_hits          = validate_input($conn, getCGIParamPC('max_hits', 'P', 0));
   $seqtype           = validate_input($conn, getCGIParamPC('seq_type', 'P', ''));

   $num_searches = count($selected_searches);
logMessagePC("startsearch.php: there are $num_searches searches in job set $job_set_id.");
   
   // Get sequence (from textarea, file, or genbank ids)
   $sequence = getSequence();

   // ...and make sure it's valid
   if (!isValidSequence($sequence, $seqtype, $msg)) { // sets $msg if error
      reportErrorPC($msg);
      $contents = prepTmpl($pc_system['search_dir'] . '/tmpls/sequence_error.htpl',
                           array('icon'                => $pc_system['icon'],
                                 'error'               => $msg,
                                 'max_queries'         => MAX_QUERIES,
                                 'max_sequence_length' => MAX_SEQUENCE_LENGTH,
                           ));
                           
logMessagePC("startsearch.php: startSearch(): error results: determine button bar for $job_set_id with num_searches = $num_searches");
      // Show scrollable search list if necessary
      if ($num_searches < 4) {
         showSearchPage('Sequence-indexed Data Search results',
                        prepTmpl($pc_system['search_dir'] . '/tmpls/results_btns_div_noscroll.htpl',
                                 array('results_btns' => '')),
                        $contents);
      }
      else {
         showSearchPage('Sequence-indexed Data Search results',
                        prepTmpl($pc_system['search_dir'] . '/tmpls/results_btns_div.htpl',
                                 array('results_btns' => '')),
                        $contents);
      }
      exit();
   }//not valid sequence

   // extract def lines
   preg_match_all("/^>(.*)/m", $sequence, $matches);
   function truncate50($s) {
      return truncate($s, 50);
   }
   $def_lines = implode('<br>', array_map('truncate50', $matches[1]));

   // Alphabetize searches and remove duplicates
   $selected_searches = array_unique($selected_searches, SORT_STRING);
logVarDumpPC($selected_searches, 'selected searches for $job_set_id');

   // These <div>s will hold the search results
   $search_divs = '';

   // These buttons are tabs between the BLAST dvs
   $btns = '<table>';
   $btns .= '  <tr>';
   
   // Cache this job set
   $_SESSION['job_set_id'] = $job_set_id;

   // Start a job record for this job set
   $job_type = ($email_results) ? 'email search set' : 'search set';
   createJobRecord($uid, $job_set_id, $job_set_name, '', '', '', '', $job_type, 
                   $email, $conn);

   // Each search job will have its own id
   $job_ids = array();

   foreach ($selected_searches as $selected_search) {
      if (isCategory(preg_replace("/_/", ' ', $selected_search))) {
         continue;  // a category, not a search: skip the rest of this iteration
      }
         
      // Get search record
      $search_info = getSearchRecord($selected_search, $conn);
      if (!$search_info) {
         $msg = "Failed to find the search record for $selected_search";
         reportErrorPC($msg);
         showSearchPage('Search Failed',
                        '',   // no extra leftside buttons/menu items
                        "<br>$msg");
         exit();
      }
logVarDumpPC($search_info, "Search record\n");

      // Create a unique ID for this search
      $job_id = $job_set_id . '_' . getUniqueID(10);
      array_push($job_ids, $job_id);
         
      // Add to cached job ids
      if (isset($_SESSION['job_ids']) && trim($_SESSION['job_ids']) != '')
         $jobs = explode(',', $_SESSION['job_ids']);
      else
         $jobs = array();
      array_push($jobs, $job_id);
      
      $_SESSION['job_ids'] = implode(',', $jobs);
         
      // Write sequence out to a file
      writeSequence($job_id, $sequence);
         
      // Start a job record
      $job_name = 'search ' . $search_info['name'];
      $input_parameters = "query: $def_lines";
      createJobRecord($uid, $job_id, $job_name, '', '', $search_info['type'],
                      $input_parameters, 'search job', '', $conn);
         
      if ($search_info['blast_source'] == '') {
         // Don't run BLAST for the search (but some parameters may still be
         //   needed by the search scripts)
         $BLASTparms  = "PROGRAM=";
         $BLASTparms .= ",SEQTYPE=$seqtype";
         $BLASTparms .= ",EXPECT=$max_evalue";
         $BLASTparms .= ",HITLIST_SIZE=$max_hits";
         $BLASTparms .= ",ENTREZ=" . $search_info['entrez'];
         $BLASTparms .= ",SEQTYPE=$seqtype";
         $BLASTparms .= ",OTHER_ADVANCED=-W3-r-1-q-1";
      }//no BLAST required
      
      else {
         // Set up BLAST job
         
         // What kind of blast program do we want?
         $program = getBLASTProgram($seqtype, $search_info['blast_source'],
                                    $search_info['blast_database']);

         // Make sure the e-value is valid (values < 1e-307 are seen as
         //    negative by NCBI BLAST).
         if (floatval($max_evalue) < 1.0e-295) {
            $max_evalue = '1e-295';
         }

         // Build up the string of BLAST parameters
         $BLASTparms  = "PROGRAM=$program";
         $BLASTparms .= ",EXPECT=$max_evalue";
         $BLASTparms .= ",HITLIST_SIZE=$max_hits";
         $BLASTparms .= ",ENTREZ=" . $search_info['entrez'];
         $BLASTparms .= ",DATABASE=";
         $BLASTparms .= getBLASTdbPath($search_info['blast_source'],
                                       $search_info['blast_database']);
         if ($program == 'blastn') {
            $BLASTparms .= ",OTHER_ADVANCED=-W16-r-1-q-1";
         }
         else {
            $BLASTparms .= ",OTHER_ADVANCED=-W3-r-1-q-1";
         }
      }//BLAST required
         
      if (!$email_results) {
         // No e-mail; user will wait for results to appear

         // display the 'waiting' HTML
         $waiting = prepTmpl($pc_system['search_dir'] . '/tmpls/waiting.htpl',
                              array('results_js_lib' => 'blastresults.js', //js lib
                                    'status'         => 'searching...',
                                    'image_url'      => $pc_system['image_url'],
//                                    'median_time'    => $search_stats['median_search_time'],
                                    'check_func'     => 'checkBLAST()',   //js function
                              )
         );

         // Set up the div holding results of this search job
         $results_url = $pc_system['root_url']
         . "/search/sequence_search/showresults.php?job_id="
         . $job_id;
         $div = prepTmpl($pc_system['search_dir'] . '/tmpls/results_div.htpl',
                         array('job_id'       => $job_id,
                               'results_url'  => $results_url,
                               'result_id'    => $job_id,
                               'name'         => $search_info['name'],
                               'blastsummary' => '',
                               'query_divs'   => $waiting,
                         )
         );
         $search_divs .= $div;

         // Create a button/tab for this set of results
         $btn = prepTmpl($pc_system['search_dir'] . '/tmpls/results_btn.htpl',
                         array('result_id' => $job_id,
                               'name'      => $search_info['short_name'],
                               'status'    => 'searching...'
                        )
         );
         $btns .= '<td>' . $btn . '</td>';
         
      }//construct results div for this job
         
      // Construct call to master search script:
      //   perl MasterSearcher.pl search-dir search-type search-id source BLASTinfo
      // where BLASTinfo looks like:
      //   PROGRAM=blastn,EXPECT=1e-10,ENTREZ='',HITLIST_SIZE=5
      if (!file_exists($pc_system['perl_dir'] . '/MasterSearcher.pl')) {
         echo "Can't find MasterSearcher.pl!<br>";
         echo "I am looking in " . getcwd() . "<br>";
         exit();
      }
      $cmd  = "/usr/share/current_perl MasterSearcher.pl ";
      $cmd .= $pc_system['SYSTEM_INFO'] . " ";
      $cmd .= " $selected_search";
      $cmd .= " $job_id";
      $cmd .= " '$BLASTparms'";
      # this causes exec() to return immediately with pid
      $cmd .= ' > /dev/null 2>&1 & echo $!';
      logMessagePC("startsearch.php: [$job_set_id] $cmd\n");

      // Change directories briefly to search script home
      $cwd = getcwd();
      chdir($pc_system['perl_dir']);
         
      // Call the search script
      $output = null;
      $retn = -1;
      $pid = exec($cmd, $output, $retn);
      
      setPID($job_id, $pid);

      // Go back where we belong
      chdir($cwd);
         
      // Pause between starting each search
      sleep(3);
   }//foreach selected search

   if ($email_results) { # An email address was provided.
      $link = $pc_system['root_url'] . "/search/sequence_search/show_set_status.php?job_id=" . $job_set_id;
      $email_msg = "Your job is being processed.\nHere is a link " . $link;
///      mail($email, "Search status from POPcorn", "Your job is being processed.\nHere is a link " . $link, 'From: popcornwebmaster@iastate.edu');
      send_email($email, 'popcornwebmaster@iastate.edu', 
                 'Search status from POPcorn', $email_msg);
      
      $email_message = "Your job has been submitted to POPcorn.  An email has been sent to $email.";

      $contents = prepTmpl($pc_system['search_dir'] . '/tmpls/email_sent.htpl',
                           array('message' => $email_message,
                                 'status_link' =>$link)
      );
      
      #TODO, and the status template to $contents
      
     //connect to the Database.
      // makeQuery() binds when handed a values array; $conn is the PDO here, not $DBConn.
      $sql = "SELECT * FROM PC_JOB_CTL WHERE JOB_ID = ?";
      $res = makeQuery($conn, $sql, array($job_id));
      $row=retrieveRow($res);

      $status_link = $pc_system['root_url'] . "/search/sequence_search/" . $row['LINK'];
      $status = $row['status'];
     
      $contents .= "<hr />\n";
      $contents .= prepTmpl($pc_system['search_dir'] . '/tmpls/set_status.htpl',
                            array('status' => $status,'result_link' =>$link));

      showSearchPage('POPcorn sequence-indexed data search results',
                     '',
                     $contents);
   }//e-mail requested
   
   else {
      // Show search results page
      $results_url = $pc_system['root_url'] 
                        . "/search/sequence_search/showsetresults.php?"
                        . "job_set_id=$job_set_id";
      $download_url = $pc_system['root_url'] 
                     . "/search/sequence_search/downloadsearch.php?"
                     . "job_set_id=$job_set_id";
      $btns .= '</tr></table>';
        
logMessagePC("startsearch.php: startSearch(): determine button bar for $job_set_id with num_searches = $num_searches");
      $results_btns = '';
      // Show scrollable search list if necessary
      if ($num_searches < 4) {
         $results_btns = prepTmpl($pc_system['search_dir'] . '/tmpls/results_btns_div_noscroll.htpl',
                                 array('results_btns' => $btns));
      }
      else {
         $results_btns = prepTmpl($pc_system['search_dir'] . '/tmpls/results_btns_div.htpl',
                                 array('results_btns' => $btns,
                                       'left_arrow'   
                                          => $pc_system['image_url'] 
                                             . "/left_button_inset.png",
                                       'right_arrow'  
                                          => $pc_system['image_url'] 
                                             . "/right_button_inset.png"));
      }
      $results = prepTmpl($pc_system['search_dir'] . '/tmpls/results.htpl',
                          array('root_url'       => $pc_system['root_url'],
                                'image_url'      => $pc_system['image_url'],
                                'num_jobs'       => count($job_ids),
                                'job_set_name'   => $job_set_name,
                                'max_hits'       => $max_hits,
                                'job_set_id'     => $job_set_id,
                                'results_url'    => $results_url,
                                'download_url'   => $download_url,
                                'sequence'       => $sequence,
                                'seq_type'       => $seqtype,
                                'repeat_action'  => 'search again',
                                'results_btns'   => $results_btns,
                                'results_divs'   => $search_divs,
                                'results_js_lib' => 'searchresults.js', //js lib
                                'check_func'     => 'checkSearch()',    //js function
                           )
      );
logMessagePC("startsearch.php: job_set_id: $job_set_id, num_jobs: " . count($job_ids));

      showSearchPage('POPcorn search results','',$results);
   }
}//startSearch()


function restoreSearchResults($job_set_id) {
   global $pc_system;
logMessagePC("startsearch.php: restoreSearchresults(): job_set_id: $job_set_id");

   $conn = connectToDatabase();
   
   $uid          = getUID();
   $ids          = getJobIdsForSet($uid, $job_set_id, $conn);
   $job_set_name = getJobSetName($job_set_id, $conn);
   $search_stats = getSearchStats($conn);
   $sequence     = '';
   $seqtype      = '';
   
   $divs = '';
   $btns = '<table>';
   $btns .= '  <tr>';

   $num_jobs = count($ids);
logMessagePC("startsearch.php: there are $num_jobs jobs in job set $job_set_id.");
   
   foreach ($ids as $job_id) {
logMessagePC("startsearch.php: restoreSearchresults(): handle job $job_id");
      if ($job_id == '')
         continue;
         
      // Get input sequence
      $sequence_part = file_get_contents($pc_system['results_dir'] . "/$job_id.fas") . "\n";
      if(!preg_match("/$sequence_part/", $sequence)){
         $sequence .= $sequence_part;
      }
      
      // Get sequence type
      $blast_results_file = $pc_system['results_dir'] . "/$job_id.bla";
      $cmd  = "grep '<BlastOutput_program>.*</BlastOutput_program>' ";
      $cmd .= $blast_results_file;
      $match = `$cmd`;
      $program = preg_replace("/<BlastOutput_program>(.*)<\/BlastOutput_program>/",
                              "$1", $match);
      $seq_type = '';
      if ($program == 'blastn' || $program == 'blastx') {
         $seq_type = 'nucleotide';
      }
      else if ($program == 'tblastn' || $program == 'blastp') {
         $seq_type = 'aminoacid';
      }
logMessagePC("startsearch.php: restoreSearchResults(): seq type = $seq_type");

      list($short_name, $search_name) = getSearchNamesForJob($job_id);
      
      if (!$search_name) {
         $msg = "Found a problem with job $job_id. Results may be missing or ";
         $msg .= "contain an error. ";
         reportErrorPC($msg);
      }
         
      else {
         $btn = prepTmpl($pc_system['search_dir'] . '/tmpls/results_btn.htpl',
                         array('result_id' => $job_id,
                               'name'      => $short_name,
                               'status'    => 'loading...'
                          )
         );
         $btns .= '<td>' . $btn . '</td>';

         $waiting = prepTmpl($pc_system['search_dir'] . '/tmpls/waiting.htpl',
                             array('results_js_lib' => 'blastresults.js', //js lib
                                   'status'         => 'loading...',
                                   'image_url'      => $pc_system['image_url'],
//                                   'mean_time'      => $search_stats['median_search_time'],
                                   'check_func'     => 'checkSearch()',   //js function
                             )
         );

         $results_url = $pc_system['root_url']
                      . "/search/sequence_search/showresults.php?job_id="
                      . $job_id;
         $div = prepTmpl($pc_system['search_dir'] . '/tmpls/results_div.htpl',
                         array('job_id'       => $job_id,
                               'results_url'  => $results_url,
                               'result_id'    => $job_id,
                               'name'         => $search_name,
                               'blastsummary' => '',
                               'query_divs'   => $waiting,
                         )
          );
          $divs .= $div;
      }
   }//each id

   // Show results page, which will wait for the job(s) to complete
   $results_url = $pc_system['root_url'] 
                     . "/search/sequence_search/showsetresults.php?"
                     . "job_set_id=$job_set_id";
   $download_url = $pc_system['root_url'] 
                  . "/search/sequence_search/downloadsearch.php?"
                  . "job_set_id=$job_set_id";
                  
   $btns .= '</tr></table>';
   
logMessagePC("startsearch.php: restoreSearchResults(): determine button bar for $job_set_id with num_jobs = $num_jobs");
   $results_btns = '';
   if ($num_jobs < 5) {
      $results_btns = prepTmpl($pc_system['search_dir'] . '/tmpls/results_btns_div_noscroll.htpl',
                                 array('results_btns' => $btns));
   }
   else {
      $results_btns = prepTmpl($pc_system['search_dir'] . '/tmpls/results_btns_div.htpl',
                                 array('left_arrow'   => $pc_system['image_url'] . "/left_button_inset.png",
                                       'right_arrow'  => $pc_system['image_url'] . "/right_button_inset.png",
                                       'results_btns' => $btns));
   }
             
   $results = prepTmpl($pc_system['search_dir'] . '/tmpls/results.htpl',
                       array('image_url'      => $pc_system['image_url'],
                             'num_jobs'       => count($ids),
                             'job_set_name'   => $job_set_name,
                             'job_set_id'     => $job_set_id,
                             'results_url'    => $results_url,
                             'download_url'   => $download_url,
                             'max_hits'       => 0,
                             'sequence'       => $sequence,
                             'seq_type'       => $seq_type,
                             'repeat_action'  => 'search again',
                             'results_btns'   => $results_btns,
                             'results_divs'   => $divs,
                             'results_js_lib' => 'searchresults.js', //js lib
                             'check_func'     => 'checkSearch()',    //js function
                       )
   );

      showSearchPage('Sequence-indexed Data Search results', '', $results);
}//restoreSearchResults


?>