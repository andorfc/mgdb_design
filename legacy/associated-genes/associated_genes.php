<?php
/* file: associated_genes.php
 *
 * purpose: list gene models with associated genes (loci).
 *
 * history:
 *  07/17/14  eksc  created from displaygmassociatedresults.cgi
 */
 
  include_once('./include/gp_lib.php');
  
  $system = getSystemInfo('mgdb.conf');
  
  $type    = getCGIParam('type', 'G', 'all');
  $style   = getCGIParam('style', 'G', 'tsv');
  
  $DBConn = connect_to_database();
  $num = 1;
  
  // NOTE! Looping thousands of rows into the template via bauplan is slow.
  // This can be made MUCH faster by putting the html into a variable
  // and then outputing that variable in the template
  if ($type == 'all') {
    // Extra column for all
    $rows_str = '
    <table cellpadding=5 cellspacing=0 width=100%>
      <tr bgcolor="E8E8E8">
      <th align="left" width="4%">Row</th>
        <th align="left" width="11%">V5 Gene Model</th>
        <th align="left" width="11%">V4 Gene Model</th>
        <th align="left" width="11%">V3 Gene Model</th>
        <th align="left" width="10%">Gene Symbol</th>
        <th align="left" width="10%">Full Name</th>
        <th align="left" width="10%">Source</th>
      </tr>';
    $rows_dl = "v5 Gene Model ID\tv4 Gene Model ID\tv3 Gene Model ID\tGene Symbol\tFull Name\tSource\n";
  }
  else {
    $rows_str = '
    <table cellpadding=5 cellspacing=0 width=100%>
      <tr bgcolor="E8E8E8">
      <th align="left" width="4%">Row</th>
        <th align="left" width="11%">V5 Gene Model</th>
        <th align="left" width="11%">V4 Gene Model</th>
        <th align="left" width="11%">V3 Gene Model</th>
        <th align="left" width="10%">Gene Symbol</th>
        <th align="left" width="10%">Full Name</th>
      </tr>';
    $rows_dl = "v5 Gene Model ID\tv4 Gene Model ID\tv3 Gene Model ID\tGene Symbol\tFull Name\n";
  }
  
  // Get Classical Gene list
  if ($type == 'classical') {
    $sql = "
      SELECT distinct xref5.key AS v5_gene_model, xref4.key AS v4_gene_model, 
             xref.key AS v3_gene_model, l.name AS gene, l.full_name, 
             xref4.ext_db_comment AS v4_source, 
             xref.ext_db_comment AS v3_source
      FROM ext_db_key xref
        INNER JOIN locus l ON l.id=xref.id
        INNER JOIN id_num ON id_num.id=l.id
        LEFT JOIN ext_db_key xref4 ON xref4.id=l.id
          AND xref4.ext_db_comment='Gene model association inferred from similarity with B73 RefGen_v3 gene models'
        LEFT JOIN ext_db_key xref5 ON xref5.id=l.id
          AND xref5.key LIKE 'Zm00001eb%'
      WHERE xref.ext_db_comment='Classical Gene' 
            AND l.type=(SELECT id FROM term WHERE name='Gene')
            AND id_num.curation_lvl=0
      ORDER BY l.name";
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
    $rows_str .= makeRows($rows, $rows_dl, $type);
  }//classical gene list
  
  // Get MaizeGDB-curated list
  else if ($type == 'maizegdb') {
    $sql = "
      SELECT distinct xref5.key AS v5_gene_model, xref4.key AS v4_gene_model, 
             xref.key AS v3_gene_model, l.name AS gene, l.full_name
      FROM ext_db_key xref
        INNER JOIN locus l ON l.id=xref.id
        INNER JOIN chado.gene_model gm ON gm.gene_name=xref.key
          AND gm.assembly_version='B73 RefGen_v3'
        INNER JOIN id_num ON id_num.id=l.id
        LEFT OUTER JOIN ext_db_key xref4 ON xref4.id=l.id
          AND xref4.ext_db_comment='Gene model association inferred from similarity with B73 RefGen_v3 gene models'
        LEFT OUTER JOIN ext_db_key xref5 ON xref5.id=l.id
          AND xref5.key LIKE 'Zm00001eb%'
      WHERE xref.db_person=(SELECT id FROM person 
                           WHERE name='Gene Model - MaizeGDB')
            AND l.type=(SELECT id FROM term WHERE name='Gene')
            AND id_num.curation_lvl=0
      ORDER BY l.name";
    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);
    
    // A bit of a hack: remove rows that have a v4 gene model but no v3 gene model.
    //   these are classical gene associations and shouldn't be on this list.
    for ($i=0; $i<count($rows); $i++) {
      $r = $rows[$i];
      if ($r['v4_gene_model'] && $r['v4_gene_model'] != '') {
        if (!$r['v3_gene_model'] || $r['v3_gene_model'] == '') {
          if ($r['v4_source'] == 'Gene model association inferred from similarity with B73 RefGen_v3 gene models') {
            unset($r[$i]);
          }
        }
      }
    }//each row
    
    $rows_str .= makeRows($rows, $rows_dl, $type);
  }//MaizeGDB-curated list
  
  else if ($type == 'all') {
    $sql = "
      SELECT distinct xref5.key AS v5_gene_model, xref4.key AS v4_gene_model, 
             xref3.key AS v3_gene_model, l.name AS gene, l.full_name, p3.name AS source
      FROM locus l
        INNER JOIN id_num ON id_num.id=l.id
        LEFT OUTER JOIN ext_db_key xref3 ON xref3.id=l.id
          AND xref3.key ~ '^[GAE].+'
        LEFT OUTER JOIN person p3 ON p3.id=xref3.db_person
        LEFT OUTER JOIN ext_db_key xref4 ON xref4.id=l.id
          AND xref4.key LIKE 'Zm00001d%'
        LEFT OUTER JOIN person p4 ON p4.id=xref4.db_person
        LEFT OUTER JOIN ext_db_key xref5 ON xref5.id=l.id
          AND xref5.key LIKE 'Zm00001eb%'
        LEFT OUTER JOIN person p5 ON p5.id=xref5.db_person
      WHERE l.type=(SELECT id FROM term WHERE name='Gene')
            AND id_num.curation_lvl=0
      ORDER BY xref5.key
";

    $sth = make_query($DBConn, $sql);
    $rows = get_all_rows($sth);

    $rows_str .= makeRows($rows, $rows_dl, $type);
  }//all associations
  
  $rows_str.="</table>";
  if ($style == 'table') {
    $bauplan->title('Gene Models Associated with Genes');
    $tmpl = $mgdb->get('body')->load('templates/tools/associated_genes.bau');
    $tmpl->get('ag-table')->replace($rows_str);
  }
  else {
    // Force download
    header('Content-Description: File Transfer');
    header('Content-type: text/html');
    header('Content-Disposition: attachment; filename=genes_' . $type . '.txt');
    echo $rows_dl;
    
    // Avoid displaying template:
    exit;
  }
  
  
///////////////////////////////////////////////////////////////////////////////////////

function getRowsDL($r, $type) {
  $str = $r['v5_gene_model'] . "\t" .
         $r['v4_gene_model'] . "\t" .
         $r['v3_gene_model'] . "\t" .
         $r['gene'] . "\t" .
         $r['full_name'];
  if ($type == 'all') {
    $str .= "\t" . $r['source'];
  }
  
  return "$str\n";
}//getRowsDL


function makeRows($rows, &$rows_dl, $type) {
  $str = '';
  $row_num = 1;
  
  foreach ($rows as $r) {
    $r['v5_gene_model'] = (isset($r['v5_gene_model'])) ? $r['v5_gene_model'] : '';
    $r['v4_gene_model'] = (isset($r['v4_gene_model'])) ? $r['v4_gene_model'] : '';
    $r['v3_gene_model'] = (isset($r['v3_gene_model'])) ? $r['v3_gene_model'] : '';
    $r['gene']          = (isset($r['gene'] )) ? $r['gene']  : '';
    $r['full_name']     = (isset($r['full_name'])) ? $r['full_name'] : '';
    
    if ($type == 'all') {
      $r['source']     = (isset($r['source'])) ? $r['source'] : '<i>unknown</i>';
    }
    
    $rows_dl .= getRowsDL($r, $type);  // if download requested
    if ($row_num % 2 == 0) {
      $str .= "<tr bgcolor='E8E8E8'>";
    }
    else {
      $str .= "
        <tr>";
    }
    
    $str .= '
          <td align="left">' . $row_num . '</td>
          <td align="left"><a href="/gene_center/gene/' . $r['v5_gene_model'] . '">' . $r['v5_gene_model'] . '</a>&nbsp;</td>
          <td align="left"><a href="/gene_center/gene/' . $r['v4_gene_model'] . '">' . $r['v4_gene_model'] . '</a>&nbsp;</td>
          <td align="left"><a href="/gene_center/gene/' . $r['v3_gene_model'] . '">' . $r['v3_gene_model'] . '</a>&nbsp;</td>
          <td align="left"><a href="/gene_center/gene/'. $r['gene'] .'">'. $r['gene'] . '</a></td>
          <td align="left"><a href="/gene_center/gene/'. $r['gene'] .'">'. $r['full_name'] . '</a></td>';
    if ($type == 'all') {
      $str .= '
          <td align="left">' . $r['source'] . '</td>';
    }
    $str .= "
        </tr>";
      
    $row_num++;
  }
  
  return $str;
}//makeRows


?>
