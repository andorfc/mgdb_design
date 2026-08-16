<?php
/* file: gene_model_snps_traits.php
 *
 * purpose: display SNPs associated with a given gene model, 
 *          and associated traits, if any.
 *
 * history:
 *   06/15/23  eksc  created from gene_data.php
 */

function getSnpData($gene_id, $arrRecord, $DBConn) {
  $snp_data = array();
  
  $sql = "
    SELECT mgm.*, l.id AS snp_id, l.name AS snp_name, 
           s.id AS structure_id, s.name AS structure_name, s.term_comments AS structure_description,
           t.id AS trait_id, t.name AS trait_name, t. term_comments AS trait_description,
           r.id AS reference_id, r.name AS reference_name, stp.property
    FROM perm_tables.marker_gene_model mgm
      INNER JOIN mgdb.term s ON s.id=mgm.gene_structure_id
      INNER JOIN mgdb.locus l ON l.id=mgm.id
      INNER JOIN mgdb.id_num idn ON idn.id=l.id
      INNER JOIN mgdb.snp_trait st ON st.snp_id=mgm.id
      INNER JOIN mgdb.term t ON t.id=st.trait_id
      INNER JOIN mgdb.reference r ON r.id=st.reference_id
      LEFT OUTER JOIN mgdb.snp_trait_property stp ON stp.snp_trait_auto_num=st.auto_num
    WHERE gene_model='$gene_id' AND idn.curation_lvl=0
    ORDER BY mgm.transcript, mgm.start_coordinate";

  $sth = make_query($DBConn, $sql);
  while ($row = retrieve_row($sth)) {
    $reference_id = $row['reference_id'];
    if (!$snp_data[$reference_id]) {
      $snp_data[$reference_id] = array(
        'reference_name' => $row['reference_name'],
        'snp_traits'     => array(),
      );
    }
    $r = array(
      'gene_model'        => $row['gene_model'],
      'transcript'        => $row['transcript'],
      'snp_name'          => $row['snp_name'],
      'position'          => $row['start_coordinate'],  // start=end
      'chromosome'        => $row['chromosome'],
      'structure_name'    => $row['structure_name'],
      'structure_description' => $row['structure_description'],
      'trait_name'        => $row['trait_name'],
      'trait_description' => $row['trait_description'],
    );
    if ($row['property']) {
      $r['property'] = $row['property'];
    }
    array_push($snp_data[$reference_id]['snp_traits'], $r);
  }//each row
  
  return $snp_data;
}//getSnpData


function showSNPsTraits($tmpl, $gene_id, $arrRecord, $DBConn) {
  $browse_url = getBrowseLink($arrRecord['assembly_version'], $DBConn);
  if (strstr($browse_url, 'gbrowse')) {
    $browse_url .= "/?name=$gene_id;h_feat=$gene_id";
  }
  else {
    $browse_url .= "/?loc=$gene_id";
    $browse_url .= '&tracks=gene_models_official,Li2022_snps,gwas_snps';
  }
  $tmpl->get('browser_link')->replace($browse_url);

  $snp_data = getSnpData($gene_id, $arrRecord, $DBConn);

  if (count($snp_data) == 0) {
    $tmpl->get('no-gene_model-snps_traits')->unmute();
  }
  else {
    $tmpl->get('gene_model-snps-traits-studies')->replace(getStudyHTML($snp_data));
  }
  $tmpl->get('gene_model-snps_traits')->unmute();
}//showSNPsTraits


function getStudyHTML($snp_data) {
  if (count($snp_data) == 0) {
    return '';
  }
  
  $html = '';
  
  $references = array_keys($snp_data);
  foreach ($references as $ref_id) {
    $prop_head = '';
    if (count(array_column($snp_data[$ref_id]['snp_traits'], 'property')) > 0) {
      $prop_head = '<th>&nbsp;property</th>';
    }

    $transcript_gene = (isset($rec['transcript']) && $rec['transcript'] != '')
                       ? $rec['transcript'] : $rec['gene_model'];
                       
    $ref_html = "
      <b>SNP/traits from 
      <a href='/data_center/reference/$ref_id'>" . $snp_data[$ref_id]['reference_name'] . '</a>';
    $ref_html .= "
      <br>
      <table>
        <tr>
          <th>transcript</th>
          <th>&nbsp;SNP</th>
          <th>&nbsp;chromosome</th>
          <th>&nbsp;position</th>
          <th>&nbsp;structure</th>
          <th>&nbsp;trait</th>
          $prop_head
        </tr>";
        
    foreach ($snp_data[$ref_id]['snp_traits'] as $rec) {
      $prop_col = '';
      if ($rec['property'] && $rec['property'] != '') {
        $prop_col = '<td align=\'center\'>' . $rec['property'] . '</td>';
      }
      $ref_html .= "
        <tr>
          <td align='center'>$transcript_gene</td>
          <td align='center'>&nbsp;" . $rec['snp_name'] . "</td>
          <td align='center'>" . $rec['chromosome'] . "</td>
          <td align='center'>" . $rec['position'] . "</td>
          <td align='center'>
            &nbsp;<span class='tooltip'>
              " . $rec['structure_name'] . "
              <span class='tooltiptext tooltip-right'>" . $rec['structure_description'] . "</span>
            </span>
          </td>
          <td align='center'>
            &nbsp;<span class='tooltip'>
              " . $rec['trait_name'] . "
              <span class='tooltiptext tooltip-right'>" . $rec['trait_description'] . "</span>
            </span>
          </td>
          $prop_col
        </tr>";
    }
    $ref_html .= "
      </table>
      <br><br>";
      
    $html .= $ref_html;
  }//each reference
  
  return $html;
}//showStudySNPs


