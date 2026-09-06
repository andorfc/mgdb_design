<?PHP
/* file: stock_catalog_data.php
 *
 * purpose: display the various sections of a stock catalog; called via Ajax
 *
 * history:
 *  12/12/12  jportwood  created
 */

  include_once('../lib/Bauplan.php');
  include_once("../include/db-api.php");
  include_once("../include/api_tools.php");
  include_once('../include/gp_lib.php');

  // Get system configuration
  $system = getSystemInfo('mgdb.conf');

  $type = getCGIParam("type", 'G', false);
  $year = getCGIParam("year", 'G', false);
  /* $year is interpolated straight into four SQL date literals below --
     TO_DATE('$year-01-01', ...) -- so anything that is not a bare year closes
     the literal. Validated to four digits here, which is the same check
     controllers/tools/stock_catalog_modern.php already applies; this endpoint
     stays reachable as that page's rollback path, so it needs its own.
     Fixed 2026-09-05. */
  if ($year !== false && !preg_match('/^\d{4}$/', (string) $year)) {
    $year = date('Y');
  }
logMessage("stock_catalog_data.php: type: $type, 'year': $year", true);

  $bauplan = $bauplan = new Bauplan('');
  $tmpl = $bauplan->template()->load('../templates/tools/stock_catalog-sections.bau');
  
  $DBConn = connect_to_database();

  if ($year > 0) {
    $tail = " AND ((idn.add_date BETWEEN TO_DATE('$year-01-01','YYYY-MM-DD') AND TO_DATE('$year-12-31','YYYY-MM-DD')) OR (idn.curation_lvl_change BETWEEN TO_DATE('$year-01-01','YYYY-MM-DD') AND TO_DATE('$year-12-31','YYYY-MM-DD')))";
    $tail1 = "AND (idn.add_date BETWEEN TO_DATE('$year-01-01','YYYY-MM-DD') AND TO_DATE('$year-12-31','YYYY-MM-DD') OR idn.curation_lvl_changeE BETWEEN TO_DATE('$year-01-01','YYYY-MM-DD') AND TO_DATE('$year-12-31','YYYY-MM-DD'))";
    $tail2 = "AND (idn.add_date BETWEEN TO_DATE('$year-01-01','YYYY-MM-DD') AND TO_DATE('$year-12-31','YYYY-MM-DD') OR idn.curation_lvl_change BETWEEN TO_DATE('$year-01-01','YYYY-MM-DD') AND TO_DATE('$year-12-31','YYYY-MM-DD'))";
    $tail3 = "AND (idn.add_date BETWEEN TO_DATE('$year-01-01','YYYY-MM-DD') AND TO_DATE('$year-12-31','YYYY-MM-DD') OR idn.curation_lvl_change BETWEEN TO_DATE('$year-01-01','YYYY-MM-DD') AND TO_DATE('$year-12-31','YYYY-MM-DD'))";
  }
  else {
    $tail = "";
    $tail1 = "";
    $tail2 = "";
    $tail3 = ""; 
  }

  switch ($type) {
    case 'chr1':
      show_list($tmpl, $DBConn, $tail, 13579, 32270);
      break;
    case 'chr2':
      show_list($tmpl, $DBConn, $tail, 13582, 32270);
      break;
    case 'chr3':
      show_list($tmpl, $DBConn, $tail, 13585, 32270);
      break;
    case 'chr4':
      show_list($tmpl, $DBConn, $tail, 13588, 32270);
      break;
    case 'chr5':
      show_list($tmpl, $DBConn, $tail, 13591, 32270);
      break;
    case 'chr6':
      show_list($tmpl, $DBConn, $tail, 13594, 32270);
      break;
    case 'chr7':
      show_list($tmpl, $DBConn, $tail, 13597, 32270);
      break;
    case 'chr8':
      show_list($tmpl, $DBConn, $tail, 13600, 32270);
      break;
    case 'chr9':
      show_list($tmpl, $DBConn, $tail, 13603, 32270);
      break;
    case 'chr10':
      show_list($tmpl, $DBConn, $tail, 13606, 32270);
      break;
    case 'unp':
      show_list($tmpl, $DBConn, $tail, null, 706);
      break;
    case 'mul':
      show_list($tmpl, $DBConn, $tail, null, 707);
      break;
    case 'rar':
      show_list($tmpl, $DBConn, $tail, null, 9018491);
      break;
    case 'b':
      show_list($tmpl, $DBConn, $tail, null, 96149);
      break;
    case 'alien':
      show_list($tmpl, $DBConn, $tail, null, 936054);
      break;
    case 'tri':
      show_list($tmpl, $DBConn, $tail, null, 143453);
      break;    
    case 'tet':
      show_list($tmpl, $DBConn, $tail, null, 711);
      break;
    case 'csr':
      show_list($tmpl, $DBConn, $tail, null, 15415);
      break;    
    case 'cyt':
      show_list($tmpl, $DBConn, $tail, null, 15416);
      break;
    case 'tool':
      show_list($tmpl, $DBConn, $tail, null, 72190);
      break;    
    case 'batb':
      show_list($tmpl, $DBConn, $tail, null, 85236);
      break;
    case 'bato':
      show_list($tmpl, $DBConn, $tail, null, 17227);
      break;    
    case 'inv':
      show_list($tmpl, $DBConn, $tail, null, 15417);
      break;
    case 'nil':
      // 2738048 = 'Near Isogenic Line'
      show_list($tmpl, $DBConn, $tail, null, 2738048);
      break;
    case 'wx1':
      show_wx1($tmpl, $DBConn, $tail);
      break;
    case 'rils':
      // (A.TYPE = 32659 OR A.TYPE = 701)
      show_rils($tmpl, $DBConn, $tail);
      break;
    case 'rts':
      show_rts($tmpl, $DBConn, $tail1, $tail2);
      break;
    case 'pheno':
      show_pheno($tmpl, $DBConn, $tail3);
      break;
      
    //Function calls for specialized pages  
    case 'rip_main':
      show_rip_main($tmpl, $DBConn);
      break;
    case 'rip_parent':
      show_rip_parent($tmpl, $DBConn);
      break;
    case 'rip_other':
      show_rip_other($tmpl, $DBConn);
      break;  
    case 'tran_main':
      show_tran_main($tmpl, $DBConn);
      break;
    case 'phen_main':
      show_phen_main($tmpl, $DBConn);
      break;
    case 'chrom_main':
      show_list($tmpl, $DBConn, $tail, null, 892251);
      break;
  }

  $bauplan->publish();


//////////////////////////////////////////////////////////////////////////////////////////


function show_list($tmpl, $DBConn, $tail, $linkage, $type) {
  $stock_data = '';
  $linkage_clause = ($linkage) ? "AND s.focus_linkage_group = " . (int) $linkage : '';
  $sql = "
    SELECT s.id, d.description, idn.curation_lvl
    FROM mgdb.stock s 
      INNER JOIN mgdb.id_num idn ON idn.id=s.id 
      INNER JOIN mgdb.description d ON d.id=s.id
    WHERE s.type = $type $linkage_clause
          AND idn.curation_lvl=0 
          AND s.available_from = 25725 $tail 
    ORDER BY LOWER(d.description)";
  $sth = make_query($DBConn, $sql);
  while ($arr_chrom = retrieve_row($sth)) {
    $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_chrom['id'] . "\">" 
               . trim($arr_chrom['description']) ."</a></b> ";
               
    // NOTE: for now, only curation_lvl=0 is permitted, but leaving in case that changes.
    if ($arr_chrom['curation_lvl'] == 101) {
      $stock_data .= "Currently unavailable<br>";
    }
    else if ($arr_chrom['curation_lvl'] == 102) {
      $stock_data .= "DISCONTINUED<br>";
    }
    else {
      $stock_data .= "(<a href=\"/ordering/coop_order/" 
                 . urlencode(trim($arr_chrom['description'])) 
                 . "\" target=\"new\">add stock to order</a>)<br>\n";
    }
  }//each row
      
  if (strlen(trim($stock_data)) == 0) {
    $stock_data = "&nbsp;&nbsp;&nbsp;&nbsp; There is no stock data of this type.";
  }
  
  $tmpl->get('list_stocks')->replace($stock_data); 
}//show_list


//////////////////////////////////////////////////////////////////////////////////////////
////////////////////////// Functions for specific stock lists ////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////
    
function show_wx1($tmpl, $DBConn, $tail) {
  $stock_data = '';
  // 15687 = variation 'wx1', 15349 = variation 'Wx1'
  $query_rt_wx1 = "
    SELECT s.id, d.description, idn.curation_lvl
    FROM mgdb.stock s
      INNER JOIN mgdb.stock_genotypic_var sgv ON sgv.id=s.id 
      INNER JOIN mgdb.id_num idn ON idn.id=s.id
      INNER JOIN mgdb.description d ON d.id=s.id
    WHERE s.type = 17228 AND s.available_from = 25725 
          AND (sgv.variation = 15687 OR sgv.variation = 15349) 
          AND idn.curation_lvl=0 $tail 
    ORDER BY LOWER(d.description)";
  $stmt_rt_wx1 = make_query($DBConn, $query_rt_wx1);
  while($arr_rt_wx1 = retrieve_row($stmt_rt_wx1)) {
    $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_rt_wx1['id'] . "\">" 
                 . trim($arr_rt_wx1['description']) . "</a></b> ";
    
    // NOTE: for now, only curation_lvl=0 is permitted, but leaving in case that changes.
    if ($arr_rt_wx1['curation_lvl'] == 101) {
      $stock_data .= "Currently unavailable<br>";
    }
    else if ($arr_rt_wx1['curation_lvl'] == 102) {
      $stock_data .= "DISCONTINUED<br>";
    }
    else {
      $stock_data .= "(<a href=\"/ordering/coop_order/" 
                   . urlencode(trim($arr_rt_wx1['description'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>";
    }
  }

  if (strlen(trim($stock_data)) == 0) {
    $stock_data = "&nbsp;&nbsp;&nbsp;&nbsp; There is no stock data for this type.";
  }

  $tmpl->get('list_stocks')->replace($stock_data); 
}//show_wx1


function show_rils($tmpl, $DBConn, $tail) {
  $stock_data = '';
  $query_rils = "
    SELECT A.ID, C.DESCRIPTION, LOWER(C.DESCRIPTION) 
    FROM mgdb.stock s
      INNER JOIN mgdb.id_num idn ON idn.id=s.id
      INNER JONI mgdb.description d ON d.id=.id
    WHERE (s.type = 32659 OR s.type = 701) AND s.available_from = 25725 
          idn.curation_lvl = 0 $tail 
    ORDER BY LOWER(d.description)";
  $stmt_rils = make_query($DBConn, $query_rils);
  while ($arr_rils = retrieve_row($stmt_rils)) {
    $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_rils['id'] . "\">" 
                 . mgdb_safe_html(trim($arr_rils['description'])) . "</a></b> (<a href=\"" 
                 . "/ordering/coop_order/" . urlencode(trim($arr_rils['description'])) 
                 . "\" target=\"new\">add stock to order</a>)<br>\n";
  }

  if (strlen(trim($stock_data)) == 0) {
    $stock_data = "&nbsp;&nbsp;&nbsp;&nbsp; There is no stock data for this type.";
  }

  $tmpl->get('list_stocks')->replace($stock_data); 
}//show_rils


// Reciprocal translocation ... may not be called, possibly replaced by show_tran_main()
function show_rts($tmpl, $DBConn, $tail1, $tail2) {
  $stock_data = '';
  $query_rtscomp = "
    SELECT id, name FROM (
      SELECT DISTINCT(s.id), s.name
    FROM mgdb.stock s
      INNER JOIN mgdb.stock_genotypic_var sgv ON sgv.id=s.id
      INNER JOIN mgdb.id_num idn ON idn.id=s.id 
    WHERE s.type = 17228 AND s.available_from = 25725
          AND idn.curation_lvl = 0 $tail1
    EXCEPT 
    SELECT DISTINCT(s.id), s.name 
    FROM mgdb.stock s
      INNER JOIN mgdb.stock_genotypic_var sgv ON sgv.id=s.id
      INNER JOIN mgdb.id_num idn ON idn.id=s.id
    WHERE s.type = 17228 AND s.available_from = 25725 
          AND (sgv.variation OR sgv.variation = 15349) 
          AND idn.curation_lvl = 0 $tail2) AS TT
    ORDER BY LOWER(s.name)";
  $stmt_rtscomp = make_query($DBConn, $query_rtscomp);

  while ($arr_stock = retrieve_row($stmt_rtscomp)) {
    $query_desc_name = "SELECT description FROM mgdb.description WHERE id = " . $arr_stock['id'];
    $stmt_desc_name = make_query($DBConn, $query_desc_name);
    $arr_desc_name = retrieve_row($stmt_desc_name);
    if (isset($arr_desc_name['description']))
      $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_stock['id'] . "\">" 
                   . trim($arr_desc_name['description']) 
                   . "</a></b> (<a href=\"" . "/ordering/coop_order/" 
                   . urlencode(trim($arr_desc_name['description'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>\n";
    else
      $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_stock['id'] . "\">" 
                   . trim($arr_stock['name']) . "</a></b> (<a href=\"" . "/ordering/coop_order/" 
                   . urlencode(trim($arr_stock['name'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>";
  }

  if (strlen(trim($stock_data)) == 0) {
    $stock_data = "&nbsp;&nbsp;&nbsp;&nbsp; There is no stock data for this type.";
  }

  $tmpl->get('list_stocks')->replace($stock_data); 
}//show_rts

    
function show_pheno($tmpl, $DBConn, $tail3) {
  $stock_data = '';

  $query_phenotypes = "
    SELECT A.PHENO_ID, A.PHENO_NAME, A.STOCK_ID, A.STOCK_NAME, B.DESCRIPTION 
    FROM (SELECT B.ID AS PHENO_ID, B.NAME AS PHENO_NAME, E.ID AS STOCK_ID, 
                 E.NAME AS STOCK_NAME 
          FROM STOCK_PHENOTYPES A, PHENOTYPE B, ID_NUM C, ID_NUM D, STOCK E, 
               STOCK_GENOTYPIC_VAR F, VARIATION G 
          WHERE E.ID = A.ID AND A.ID = C.ID AND C.CURATION_LVL = 0 AND A.PHENOTYPE = B.ID 
                AND B.ID = D.ID AND D.CURATION_LVL = 0 AND E.TYPE = 165656 
                AND E.AVAILABLE_FROM = 25725 AND E.ID = F.ID 
                AND F.VARIATION = G.ID " . $tail3 . " 
          ORDER BY LOWER(B.NAME), LOWER(E.NAME)
         ) A 
      LEFT OUTER JOIN DESCRIPTION B ON A.STOCK_ID = B.ID 
    ORDER BY LOWER(A.PHENO_NAME), LOWER(A.STOCK_NAME)";

  $prev_pheno_id = 0;
  $prev_stock_id = 1;
  $stmt_phenotypes = make_query($DBConn, $query_phenotypes);
  while ($arr_phenotypes = retrieve_row($stmt_phenotypes)) {
    if ($arr_phenotypes['pheno_id'] != $prev_pheno_id) {
      $stock_data .= "<br><p><b>" . trim($arr_phenotypes['pheno_name']) . "</b><br></p>\n";
      $prev_pheno_id = $arr_phenotypes['pheno_id'];
    }

    if ($arr_phenotypes['stock_id'] != $prev_stock_id) {
      if (isset($arr_phenotypes['description'])) {
        $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_phenotypes['stock_id'] 
                     . "\">" . trim($arr_phenotypes['description']) 
                     . "</a></b> (<a href=\"" . "/ordering/coop_order/" 
                     . urlencode(trim($arr_phenotypes['description'])) 
                     . "\" target=\"new\">add stock to order</a>)<br>\n";
      }
      else {
        $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_phenotypes['stock_id'] 
                     . "\">" . trim($arr_phenotypes['stock_name']) 
                     . "</a></b> (<a href=\"" .  "/ordering/coop_order/" 
                     . urlencode(trim($arr_phenotypes['stock_name'])) 
                     . "\" target=\"new\">add stock to order</a>)<br>\n";
      }
    }
    
    $prev_stock_id = $arr_phenotypes['stock_id'];
  }//each row
  
  $stock_data .= "</p><br>\n";

  $tmpl->get('list_stocks')->replace($stock_data); 
}//show_pheno


// RILs
function show_rip_main($tmpl, $DBConn) {
  $stock_data = '';

  $query_ibm_94 = "
    SELECT s.name, s.id, d.description, idn.curation_lvl 
    FROM mgdb.stock s
      INNER JOIN mgdb.stock_panel_of_stocks sps ON sps.id=s.id
      INNER JOIN mgdb.id_num idn ON idn.id=s.id
      LEFT OUTER JOIN mgdb.description d ON d.id=s.id
    WHERE s.type = 701 AND idn.curation_lvl=0
          AND sps.panel_of_stocks = 415474 
    ORDER BY s.id";
  $stmt_ibm_94 = make_query($DBConn, $query_ibm_94);
  while($arr_ibm_94 = retrieve_row($stmt_ibm_94)) {
    $stock_data .= "<p>";
    if (isset($arr_ibm_94['description']) && $arr_ibm_94['description'] != '') {
      $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_ibm_94['id'] . "\">" 
                   . mgdb_safe_html(trim($arr_ibm_94['description'])) . "</a></b> (<a href=\"" 
                   . "/ordering/coop_order/" . urlencode(trim($arr_ibm_94['description'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>\n";
    }
    else {
      $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_ibm_94['id'] . "\">" 
                   . trim($arr_ibm_94['name']) . "</a></b> (<a href=\"" .  "/ordering/coop_order/" 
                   . urlencode(trim($arr_ibm_94['name'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>\n";
    }
  }//each row
  
  $stock_data .= "</p><br>\n";

  $tmpl->get('list_stocks')->replace($stock_data); 
}//show_rip_main

    
// RILs
function show_rip_parent($tmpl, $DBConn) {
  $stock_data = '';

  $query_parents = "
    SELECT s.name, s.id, d.description, idn.curation_lvl 
    FROM mgdb.stock s
      INNER JOIN mgdb.id_num idn ON idn.id=s.id
      LEFT OUTER JOIN mgdb.description d ON d.id=s.id 
    WHERE d.description LIKE '3409-% IBM RI Parent%'
          AND idn.curation_lvl = 0 
    ORDER BY s.name";
  $stmt_parents = make_query($DBConn, $query_parents);

  while($arr_parents = retrieve_row($stmt_parents)) {
    $stock_data .= "<p>";
    if (isset($arr_parents['description']) && $arr_parents['description'] != '') {
      $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_parents['id'] . "\">" 
                   . mgdb_safe_html(trim($arr_parents['description'])) . "</a></b> (<a href=\"" 
                   .  "/ordering/coop_order/" . urlencode(trim($arr_parents['description'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>\n";
    }
    else {
      $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_parents['id'] . "\">" 
                   . trim($arr_parents['name']) . "</a></b> (<a href=\"" .  "/ordering/coop_order/" 
                   . urlencode(trim($arr_parents['name'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>\n";
    }
  }//each row

  $stock_data .= "</p><br>\n";

  $tmpl->get('list_stocks')->replace($stock_data); 
}//show_rip_parent

    
// RILs
function show_rip_other($tmpl, $DBConn) {
  $stock_data = "";

  $query_other = "
    SELECT s.name, s.id, d.description, idn.curation_lvl
    FROM mgdb.stock s
      INNER JOIN mgdb.id_num idn ON idn.id=s.id
      LEFT OUTER JOIN mgdb.description d ON d.id=s.id 
    WHERE d.description LIKE '341%IBM RI%'
          AND idn.curation_lvl = 0 
    ORDER BY s.id";
  $stmt_other = make_query($DBConn, $query_other);
  while ($arr_other = retrieve_row($stmt_other)) {
    $stock_data .= "<p>";
    if (isset($arr_other['description']) && $arr_other['description'] != '') {
      $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_other['id'] . "\">" 
                   . mgdb_safe_html(trim($arr_other['description'])) . "</a></b> (<a href=\"" 
                   .  "/ordering/coop_order/" . urlencode(trim($arr_other['description'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>\n";
    }
    else {
      $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_other['id'] . "\">" 
                   . trim($arr_other['name']) . "</a></b> (<a href=\"" .  "/ordering/coop_order/" 
                   . urlencode(trim($arr_other['name'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>";
    }
  }//each row
  
  $stock_data .= "</p><br>\n";

  $tmpl->get('list_stocks')->replace($stock_data); 
}//show_rip_other


// Translocations
function show_tran_main($tmpl, $DBConn) {
  $stock_data = '';

  $query_stock = "
    SELECT id, name, description FROM 
      (SELECT DISTINCT(s.id), s.name, d.description
       FROM mgdb.stock s
         INNER JOIN mgdb.stock_genotypic_var sgv ON sgv.id=s.id
         INNER JOIN mgdb.id_num idn ON idn.id=s.id
         LEFT OUTER JOIN mgdb.description d ON d.id=s.id
       WHERE s.type = 17228 AND s.available_from = 25725 
             AND idn.curation_lvl=0
       EXCEPT 
       SELECT DISTINCT(s.id), s.name, d.description 
       FROM mgdb.stock s
         INNER JOIN mgdb.stock_genotypic_var sgv ON sgv.id=s.id
         INNER JOIN mgdb.id_num idn ON idn.id=s.id
         LEFT OUTER JOIN mgdb.description d ON d.id=s.id
       WHERE s.type = 17228 AND s.available_from = 25725 
             AND (sgv.variation = 15687 OR sgv.variation = 15349) 
             AND idn.curation_lvl = 0
      ) AS MAIN_Q 
    ORDER BY LOWER(name)";
  $stmt_stock = make_query($DBConn, $query_stock);
  while($arr_stock = retrieve_row($stmt_stock)) {
    $stock_data .= "<p>";
    if (isset($arr_stock['description']) && $arr_stock['description'] != '') {
      $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_stock['id'] . "\">" 
                   . trim($arr_stock['description']) 
                   . "</a></b> (<a href=\"" .  "/ordering/coop_order/" 
                   . urlencode(trim($arr_stock['description'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>\n";
    }
    else {
      $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_stock['id'] . "\">" 
                   . trim($arr_stock['name']) . "</a></b> (<a href=\"" .  "/ordering/coop_order/" 
                   . urlencode(trim($arr_stock['name'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>";
    }
  }//each row

  $stock_data .= "</p><br>\n";

  $tmpl->get('list_stocks')->replace($stock_data); 
}//show_tran_main


// Characterized by phenotype
function show_phen_main($tmpl, $DBConn) {
  $stock_data = "";

  $query_phenotypes = "
    SELECT stock_id, stock_name, pheno_id, pheno_name, description
    FROM (
      SELECT s.id AS stock_id, s.name AS stock_name, ph.id AS pheno_id, ph.name AS pheno_name 
        FROM mgdb.stock s 
          LEFT OUTER JOIN mgdb.stock_phenotypes sp ON sp.id=s.id 
          LEFT OUTER JOIN mgdb.phenotype ph ON ph.id=sp.phenotype
          LEFT OUTER JOIN mgdb.id_num idn1 ON idn1.id=s.id 
          LEFT OUTER JOIN mgdb.id_num idn2 ON idn2.id=ph.id 
        WHERE s.type = 165656 AND idn1.curation_lvl = 0 AND idn2.curation_lvl = 0 
              AND s.available_from = 25725 
      ) a 
      LEFT OUTER JOIN mgdb.description d ON d.id=a.stock_id 
    ORDER BY LOWER(a.pheno_name), LOWER(a.stock_name)";
  $prev_pheno_id = 0;
  $stmt_phenotypes = make_query($DBConn, $query_phenotypes);
  while ($arr_phenotypes = retrieve_row($stmt_phenotypes)) {
    if ($prev_pheno_id == 0) {
      $prev_pheno_id = $arr_phenotypes['pheno_id'];
      $stock_data .= "<br><p><b>" . trim($arr_phenotypes['pheno_name']) . "</b><br>\n";
    }
    else if ($arr_phenotypes['pheno_id'] != $prev_pheno_id) {
      $prev_pheno_id = $arr_phenotypes['pheno_id'];
      $stock_data .= "</p>\n<br><p><b>" . trim($arr_phenotypes['pheno_name']) . "</b><br>\n";
    }
    if (isset($arr_phenotypes['description'])) {
      $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_phenotypes['stock_id'] 
                   . "\">" . mgdb_safe_html(trim($arr_phenotypes['description'])) . "</a></b> (<a href=\"" 
                   .  "/ordering/coop_order/" . urlencode(trim($arr_phenotypes['description'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>\n";
    } 
    else {
      $stock_data .= "<b><a href=\"/data_center/stock?id=" . $arr_phenotypes['stock_id'] 
                   . "\">" . trim($arr_phenotypes['stock_name']) . "</a></b> (<a href=\"" 
                   .  "/ordering/coop_order/" . urlencode(trim($arr_phenotypes['stock_name'])) 
                   . "\" target=\"new\">add stock to order</a>)<br>\n";
    }
  }//each row

  $stock_data .= "</p><br>\n";

  $tmpl->get('list_stocks')->replace($stock_data); 
}//show_phen_main

?>
