<?php
/* file: searchall_tasks.php
 *
 * purpose: handled requests from the search-all-data utility.
 *
 * history
 *  02/04/26  eksc  created
 */

  include_once('./include/db-api.php');
  include_once('./include/gp_lib.php');
  include_once('./include/gene_center_lib.php');
  include_once('./controllers/search_engine/searchall_lib.php');

logVarDump($_POST, "\nPOST parameters searchall_tasks.php\n");

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $DBConn = connect_to_database();

  $ids = getCGIParam('ids', 'GP', false);
  $term = getCGIParam('global_search_term', 'GP', false);
  $term = sanitizeSearchTerm($term, $DBConn);
  $download = getCGIParam('download_results', 'GP', false);
  
  if (getCGIParam('type', 'PG', false)) {
    $table = getTable(getCGIParam('type', 'PG', false));
  }
  else {
    $table = getCGIParam('table', 'GP', false);
  }
logMessage("Search [$table] for ids [$ids] that match [$term]");
 
  if (!$table || !$term) {
    reportError("ERROR: no information given to searchall_tasks.php");
  }
  
  if (!$ids && $table != 'gene_model' && $table != 'genome') {
    // Probably searching only a specific table, so get ids matching the term
    // Gene model and genome searches are special cases.
    $ids = searchByTerm($term, $table, $DBConn);
    if ($ids == '') {
      echo "<br><br><b>No records found</b>";
      exit;
    }
  }
 
  $html = "
    <table>
      <tr>
        <td colspan=2>
          <form id='download_results$table' action='/search_engine/populate_table/'
                method='POST' target='_blank'>
            <input type='hidden' id='gtable' name='table' value='$table'>
            <input type='hidden' id='ids' name='ids' value='$ids'>
            <input type='hidden' id='global_search_term' name='global_search_term' value='$term'>
            <input type='hidden' id='download_results' name='download_results' value='true'>
            <a href='#!' onclick=\"$('#download_results$table').submit()\">Download all</a>
          <form>
          <br><br>
        </td>
      </tr>";
  
  if ($table == 'clone_library')  {
    $results = searchCloneLibrary($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/clone/' . $row['id'] . '">' . $row['name'] . '</a>';
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= "<b> synonyms:</b> $synonyms";
      }
      $html .= "</td></tr>";
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
    }
  }//clone_library

  else if ($table == 'environment')  {
    $results = searchEnvironment($term, $ids, $DBConn);
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/environment/' . $row['id'] . '">' . $row['name'] . '</a>';
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= "<b> synonyms:</b> $synonyms";
      }
      $html .= "</td></tr>";
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
    }
  }//environment
  
  else if ($table == 'gene_model') {
    $results = searchGeneModel($term, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    if (!$results || count($results) == 0) {
      echo "<br><br><b>No records found</b>";
      exit;
    }
    $html .= "<tr><td><b>There are " . count($results) . " matches.</b><br><br></td></tr>\n";
    foreach ($results as $row) {
      if ($row['pan_gene_name']) {
        // This gene model only exists in a pan-gene
        $link = '<a href="/pan_gene_center/pan_gene/' . $row['gene_name'] . '">' . $row['gene_name'] . "</a>\n";
        $inner_html = "<tr><td>$link is in a pan-gene</td></tr>";
      }
      else {
        $link = '<a href="/gene_center/gene/' . $row['gene_name'] . '">' . $row['gene_name'] . '</a>';
        $assembly = '<a href="/genome/assembly/' . $row['assembly'] . '">' . $row['assembly'] . '</a>';
        $annotation = $row['annotation'];
        $inner_html = "<tr><td>$link <b>assembly:</b> $assembly, <b>annotation:</b> $annotation</td></tr>\n";
      }
      $html .= $inner_html;
    }//foreach
  }//gene_model
  
  else if ($table == 'gene_product') {
    $results = searchGeneProduct($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/gene_product/' . $row['id'] . '">' . $row['name'] . '</a>';
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= "</td></tr>";
      if ($comments) {
        $html .= "<tr><td colspan=2><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
    }
  }//gene_product
  
  else if ($table == 'genome') {
    $results = searchGenome($term, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/genome/assembly/' . $row['assembly'] . '">' . $row['assembly'] . '</a>';
      $project = $row['project'];
      $annotation = $row['annotation'];
      $html .= "<tr><td>$link <b>annotation:</b> $annotation, <b>project:</b> $project</td></tr>";
    }
  }//genome
  
  else if ($table == 'journal') {
    $results = searchJournal($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/journal/' . $row['id'] . '">' . $row['name'] . '</a>';
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= "</td></tr>";
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
    }
  }//journal
  
  else if ($table == 'linkage_group')  {
    $results = searchLinkageGroup($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/lg/' . $row['id'] . '">' . $row['name'] . '</a>';
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= "</td></tr>";
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
    }
  }//linkage_goup
  
  else if ($table == 'locus') {
    $results = searchLocus($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/locus/' . $row['id'] . '">' . $row['name'] . '</a>';
      $full_name = $row['full_name'];
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link $full_name";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }//foreach
  }//locus
    
  else if ($table == 'map') {
    $results = searchMap($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/map/' . $row['id'] . '">' . $row['name'] . '</a>';
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }//foreach
  }//map
    
  else if ($table == 'map_scores') {
    $results = searchMapScores($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/map/' . $row['id'] . '">' . $row['name'] . '</a>';
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }//foreach
  }//map_scores
    
  else if ($table == 'panel_of_stocks') {
    $results = searchPanelOfStocks($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/pos/' . $row['id'] . '">' . $row['name'] . '</a>';
      $comment = $row['comment'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link</td><td>$comment</td></tr>";
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
    }
  }//panel_of_stocks
  
  else if ($table == 'person') {
    $results = searchPerson($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/person/' . $row['id'] . '">' . $row['name'] . '</a>';
      $interests = $row['interests'];
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>aka:</b> $synonyms";
      }
      if ($interests) {
        $html .= "; <b>interests:</b> $interests";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
    }
  }//person
  
  else if ($table == 'phenotype') {
    $results = searchPhenotype($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/phenotype/' . $row['id'] . '">' . $row['name'] . '</a>';
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }
  }//phenotype
  
  else if ($table == 'primer') {
    $results = searchPrimer($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/primer/' . $row['id'] . '">' . $row['name'] . '</a>';
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }
  }//primer
  
  else if ($table == 'probe') {
    $results = searchProbe($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/probe/' . $row['id'] . '">' . $row['name'] . '</a>';
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }
  }//probe
  
  else if ($table == 'qtl_exp') {
    $results = searchQTLExperiment($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/qtl/' . $row['id'] . '">' . $row['name'] . '</a>';
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }
  }//qtl_exp
  
  else if ($table == 'qtl_link_analysis') {
    $results = searchQTLLinkAnalysis($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/qtl_analysis/' . $row['id'] . '">' . $row['name'] . '</a>';
      $method = $row['method'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link</td><td>$method</td></tr>";
      if ($comments) {
        $html .= "<tr><td colspan=2><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
    }
  }//qtl_link_analysis
  
  else if ($table == 'reference') {
    $results = searchReference($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
//logMessage("There are " . count($results) . " reference matches.");
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/reference/' . $row['id'] . '">' . $row['title'] . '</a>';
      $html .= "<tr><td>$link</td></tr>";
      $html .= '<tr height="5px"><td></td></tr>';
    }
  }//reference
  
  else if ($table == 'recomb') {
    $results = searchRecombination($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/recombination/' . $row['id'] . '">' . $row['name'] . '</a>';
      $type = $row['type'];
      $synonyms = $row['synonyms'];
      $comments = $row['term_comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'>$comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }
  }//recomb
  
  else if ($table == 'species') {
    $results = searchSpecies($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/species/' . $row['id'] . '">' . $row['species'] . '</a>';
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }
  }//species
  
  else if ($table == 'stock') {
    $results = searchStock($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/stock/' . $row['id'] . '">' . $row['name'] . '</a>';
      $pedigree = $row['pedigree'];
      $synonyms = $row['synonyms'];
      $comments = $row['comments'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      if ($pedigree) {
        $html .= " <b>pedigree:</b> $pedigree";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'><b>comments:</b> $comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }
  }//stock
  
  else if ($table == 'term') {
    $results = searchTerm($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/term/' . $row['id'] . '">' . $row['name'] . '</a>';
      $type = $row['type'];
      $synonyms = $row['synonyms'];
      $comments = $row['term_comments'];
      $html .= "<tr><td>$link ($type)";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'>$comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }
  }//term
  
  else if ($table == 'trait_analysis') {
    $results = searchTraitAnalysis($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/trait_analysis/' . $row['id'] . '">' . $row['name'] . '</a>';
      $comments = $row['term_comments'];
      $html .= "<tr><td>$link</td></tr>";
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'>$comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }
  }//trait_analysis
  
  else if ($table == 'variation') {
    $results = searchVariation($term, $ids, $DBConn);
    if ($download == 'true') {
      downloadSearchResults($results, $table);
      exit;
    }
    $html .= "<b>There are " . count($results) . " matches.</b><br><br>";
    foreach ($results as $row) {
      $link = '<a href="/data_center/variation/' . $row['id'] . '">' . $row['name'] . '</a>';
      $comments = $row['comments'];
      $synonyms = $row['synonyms'];
      $html .= "<tr><td>$link";
      if ($synonyms) {
        $html .= " <b>synonyms:</b> $synonyms";
      }
      $html .= '</td></tr>';
      if ($comments) {
        $html .= "<tr><td><div style='margin-left:30px'>$comments</div></td></tr>";
      }
      $html .= '<tr height="5px"><td></td></tr>';
    }
  }//variation
  
  else {
    reportError("Un-handled table [$table] in search engine.");
  }
  
  $html .= '</table>';
  
  echo $html;

?>