<?php
/* file: gene_model_annotations.php
 *
 * purpose: display functional annotations and scores for a gene model. 
 *          Included in gene_data.php
 *
 * history:
 *   06/16/20  eksc  created from gene_data.php
 *   04/28/26  eksc  added scores section
 */

include_once('../include/annotation_lib.php');

function showAnnotations($tmpl, $id, $arrRecord, $DBConn) {
  global $system, $username, $password, $super_curator, $author_id;
logMessage("Starting showAnnotations()");
//ogVarDump($arrRecord, "showAnnotations():\n");

  // Get information about this gene model set
  $gm_info = getGeneModelInfo($arrRecord, $DBConn);
  $tmpl->get('gene_model_id')->replace($arrRecord['gene_id']);
  
  // Not sure this makes sense anymore (eksc 04/07/26)
  // Generic gene model analyses
  // showGeneModelAnalyses($tmpl, $arrRecord['version'], $id, $arrRecord, $DBConn);
  
  // Free text annotations
  showFreeTextAnnotations($tmpl, $arrRecord['version'], $id, $arrRecord, $DBConn);
  
  // Ontology terms
  showOntoTerms($tmpl, $username, $super_curator, '', '', $id, $arrRecord['version'], $DBConn);
  
  // Gramene annotation for reference gene models
  // Gramene only supports the most recent reference genome.
  // Shouldn't be hard-coded, but no obvious solution just yet
  if ($arrRecord['assembly_version'] == 'Zm-B73-REFERENCE-NAM-5.0') {
    $tmpl->get('canonical_transcript')->replace($arrRecord['transcript_id']);
    $tmpl->get('gramene')->unmute();
  }

  showScores($tmpl, $arrRecord, $DBConn);
  
  showFunctionalAnnotations($tmpl, $arrRecord['version'], $id, $arrRecord, $DBConn);

  // Phytozome annotation
  $transcript_info = getTranscriptData($arrRecord['gene_id'], $arrRecord['version'], false, $DBConn); // false=all transcripts
  if ($transcript_info) {
    $phytozome = getOneAnalysis($transcript_info[0]['transcript_id'], 
                                'gene model function annotation', 
                                'Phytozome', 
                                $DBConn);
    if ($phytozome) {
      showPhytozome($tmpl, $phytozome, $transcript_info, $DBConn);
    }
    else {
      $tmpl->get('no_phytozome')->unmute();
    }
  }

  if ($protein_accessions = getProteinAccessions($arrRecord, $DBConn)) {
    $tmpl->get('protein_accession_list')->loop($protein_accessions);
    $tmpl->get('protein_accessions')->unmute();
  }
  
  $tmpl->get('annotations')->unmute();
}//showAnnotations



//////////////////////////////////////////////////////////////////////////////////////////
// helper functions
//////////////////////////////////////////////////////////////////////////////////////////

function getAnalysisData($analysis_id, $transcript_id, $DBConn) {
  global $system;

  $analysis_data = false;

  $sql = "
    SELECT afp.value, t.name as type
    FROM chado.analysisfeature af
      INNER JOIN chado.analysisfeatureprop afp ON afp.analysisfeature_id=af.analysisfeature_id
      INNER JOIN chado.cvterm t ON t.cvterm_id=afp.type_id
    WHERE af.feature_id=$transcript_id
          AND af.analysis_id=$analysis_id";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    if (!$analysis_data) {
      $analysis_data = array();
    }
    if (!isset($analysis_data[$row['type']])) {
      $analysis_data[$row['type']] = array();
    }
    array_push($analysis_data[$row['type']], array(
      'value' => $row['value'])
    );
  }

  $sql = "
    SELECT d.accession, db.name, db.urlprefix
    FROM chado.analysisfeature af
      INNER JOIN chado.analysisfeature_dbxref afdbx ON afdbx.analysisfeature_id=af.analysisfeature_id
      INNER JOIN chado.dbxref d ON d.dbxref_id=afdbx.dbxref_id
      INNER JOIN chado.db ON db.db_id=d.db_id
    WHERE af.feature_id=$transcript_id
          AND af.analysis_id=$analysis_id";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    if (!$analysis_data) {
      $analysis_data = array();
    }
    if (!isset($analysis_data[$row['name']])) {
      $analysis_data[$row['name']] = array();
    }
    array_push($analysis_data[$row['name']], array(
      'accession' => $row['accession'],
      'db_name'   => $row['name'],
      'urlprefix' => $row['urlprefix'])
    );
  }

  return $analysis_data;
}//getAnalysisData


function getFunctionalAnnotation($arrRecord, $transcript_info, $type, $DBConn) {
  global $system;
  
  if (!isset($arrRecord['feature_id'])) { return; } // Pre B73 v3 gene model; no annotation

  $analyses = array();

  // get analysis record for 'gene model function annotation' that are
  //   attached to this gene model via anaysisfeature
  $sql = "
    SELECT a.analysis_id, a.name, a.description, a.sourcename, a.sourceversion,
           a.program, a.programversion
    FROM chado.analysis a
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
      INNER JOIN chado.cvterm t ON t.cvterm_id=ap.type_id
      INNER JOIN chado.analysisfeature af ON af.analysis_id=a.analysis_id
    WHERE t.name = 'analysis_type' AND ap.value=" . $DBConn->quote($type) . "
          AND (af.feature_id=" . $arrRecord['feature_id'] . "
               OR af.feature_id=" . $transcript_info[0]['transcript_id'] . ")";
  $sth = make_query($DBConn, $sql);
  $analysis_rows = get_all_rows($sth);
  foreach ($analysis_rows as $analysis_row) {
    // Get information about this analysis, including whether or not it 
    //    is visible.
    $sql = "
      SELECT a.name, a.description, p.value AS pmid, d.value AS doi,
             r.value AS reference_id, v.value AS visibility
      FROM chado.analysis a
        LEFT JOIN chado.analysisprop v
          ON v.analysis_id=a.analysis_id
             AND v.type_id=(SELECT cvterm_id FROM chado.cvterm 
                            WHERE name='analysis_visibility'
                                  AND cv_id=(SELECT cv_id FROM chado.cv
                                             WHERE name='maizegdb'))
        LEFT JOIN chado.analysisprop p
          ON p.analysis_id=a.analysis_id
             AND p.type_id=(SELECT cvterm_id FROM chado.cvterm 
                            WHERE name='analysis_pmid'
                                  AND cv_id=(SELECT cv_id FROM chado.cv
                                             WHERE name='maizegdb'))
        LEFT JOIN chado.analysisprop d
          ON d.analysis_id=a.analysis_id
             AND d.type_id=(SELECT cvterm_id FROM chado.cvterm 
                            WHERE name='analysis_doi'
                                  AND cv_id=(SELECT cv_id FROM chado.cv
                                             WHERE name='maizegdb'))
        LEFT JOIN chado.analysisprop r
          ON r.analysis_id=a.analysis_id
             AND r.type_id=(SELECT cvterm_id FROM chado.cvterm 
                            WHERE name='MaizeGDB_reference'
                                  AND cv_id=(SELECT cv_id FROM chado.cv
                                             WHERE name='maizegdb'))
      WHERE a.analysis_id=" . $analysis_row['analysis_id'];
    $sth = make_query($DBConn, $sql);
    while ($row = retrieve_row($sth)) {
      if (!$row['visibility'] || $row['visibility'] == 'show') {
        array_push($analyses, array(
          'analysis_id'                => $analysis_row['analysis_id'],
          'name'                       => $analysis_row['name'],
          'sourcename'                 => $analysis_row['sourcename'],
          'sourceversion'              => $analysis_row['sourceversion'],
          'program'                    => $analysis_row['program'],
          'programversion'             => $analysis_row['programversion'],
          'description'                => mgdb_safe_html($analysis_row['description']),
          'pmid'                       => $row['pmid'],
          'doi'                        => $row['doi'],
          'reference'                  => $row['reference_id'],
        ));
      }
    }//each row in analysis
  }//each analysis

  if (count($analyses) == 0) {
// eksc- not an error
//    reportError("Unable to find any analysis records for $type");
    return false;
  }
  else {
    return $analyses;
  }
}//getFunctionalAnnotation


function getOneAnalysis($transcript_id, $analysis_name, $source, $DBConn) {
  global $system;

  $analysis = false;

  // get analysis record for most current version
  $sql = "
    SELECT a.analysis_id, MAX(programversion) AS programversion,
           MAX(sourceversion) AS sourceversion
    FROM chado.analysis a
      INNER JOIN chado.analysisfeature af ON af.analysis_id=a.analysis_id
      INNER JOIN chado.feature f ON f.feature_id=af.feature_id
    WHERE LOWER(a.name) = " . $DBConn->quote(strtolower($analysis_name)) . "
          AND LOWER(sourcename) =" . $DBConn->quote(strtolower($source)) . "
          AND f.feature_id=$transcript_id
    GROUP BY a.analysis_id ";
  $sth = make_query($DBConn, $sql);
  if (!$row=retrieve_row($sth)) {
    return false;
  }

  $analysis = array();
  $analysis['analysis_id']    = $row['analysis_id'];
  $analysis['programversion'] = $row['programversion'];
  $analysis['sourceversion']  = $row['sourceversion'];

  return $analysis;
}//getOneAnalysis


function getProteinAccessions($arrRecord, $DBConn) {
  $accessions = array();
  
  // Get canonical transcript for this gene model
  $sql = "
    SELECT transcript_id, transcript_name FROM chado.transcript 
    WHERE gene_name='" . $arrRecord['gene_id'] ."' AND canonical='yes'";
  $sth = make_query($DBConn, $sql);
  if ($row = retrieve_row($sth)) {
    $protein = str_replace("_T", "_P", $row['transcript_name']);
    $transcript_id = $row['transcript_id'];
  }
  else {
    $msg = "For some reason, the gene model '" .  $arrRecord['gene_id'] 
         . "' couldn't be found in the chado.transcript materialized view.";
    reportError($msg);
    return false;
  }

  $sql = "
    SELECT DISTINCT x.accession, x.description,
           t.name AS transcript, db.name AS db_name, db.urlprefix, a.name AS analysis_name 
    FROM chado.feature t
      INNER JOIN chado.analysisfeature af ON af.feature_id=t.feature_id
      INNER JOIN chado.analysis a ON a.analysis_id=af.analysis_id
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
      INNER JOIN chado.analysisfeature_dbxref afx ON afx.analysisfeature_id=af.analysisfeature_id
      INNER JOIN chado.dbxref x ON x.dbxref_id=afx.dbxref_id
      INNER JOIN chado.db ON db.db_id=x.db_id
    WHERE t.feature_id=$transcript_id AND ap.value='protein analysis'
    ORDER BY t.name, db.name, x.accession";

  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    $url = $row['urlprefix'] . $row['accession'];
    array_push($accessions, array(
      'gm_protein'            => $protein,
      'protein_accession_url' => $url,
      'protein_accession'     => $row['accession'],
      'protein_description'   => mgdb_safe_html($row['description']),
      'db-name'               => $row['db_name'],
      'source'                => $row['analysis_name'],
    ));
  }//each row

  if (count($accessions) > 0) {
    return $accessions;
  }
  else {
    return false;
  }
}//getProteinAccessions


function makeTranscriptAnnotation($transcript_acc, $db_url, $transcript, $annotstr) {
  $html = "
          <div style=\"margin-left:15pt\">
            <b>Transcript:</b> ";
  if ($transcript_acc && $transcript_acc != '' && $db_url && $db_url != '') {
    $url = $db_url . $transcript_acc;
    $html .= "
            <a href=\"$url\">$transcript_acc</a>, ";
  }
  
  $html .= "$transcript
            <div style=\"margin-left:15pt\">$annotstr</div>
          </div>";
  
  return $html;
}//makeTranscriptAnnotation


function showAEDscore($tmpl, $arrRecord, $found_scores, $DBConn) {
  if (!isset($arrRecord['feature_id'])) {
    return;
  }
  
  $sql = "
    SELECT t.name AS transcript, fp.value FROM chado.featureprop fp
      INNER JOIN chado.feature t ON t.feature_id=fp.feature_id
      INNER JOIN chado.feature_relationship fr ON fr.subject_id=t.feature_id
        AND fr.type_id=(SELECT cvterm_id FROM chado.cvterm 
                        WHERE name='PART_OF'
                              AND cv_id=(SELECT cv_id FROM chado.cv 
                                        WHERE name='plant_trait_ontology'))
      INNER JOIN chado.feature f ON f.feature_id=fr.object_id
    WHERE fp.type_id = (SELECT cvterm_id FROM chado.cvterm 
                     WHERE name='AED_score' 
                           AND cv_id=(SELECT cv_id FROM chado.cv WHERE name='maizegdb')
                    )
          AND f.feature_id=" . $arrRecord['feature_id'] . "
          ORDER BY t.name";
  $sth = make_query($DBConn, $sql);
  $scores = array();
  while ($row=retrieve_row($sth)) {
    if ($row['transcript'] == $arrRecord['transcript_id']) {
      $row['transcript'] = '<b>' . $row['transcript'] . '</b>';
    }
    array_push($scores, array(
      'transcript' => $row['transcript'],
      'aed_score'  => $row['value'],
    ));
  }
  
  if (count($scores) > 0) {
    $tmpl->get('aed_scores')->loop($scores);
    $tmpl->get('AED')->unmute();
    return true;
  }
  return $found_scores;
}//showAEDscore


function showFunctionalAnnotations($tmpl, $curr_gene_model_version, $id, $arrRecord, $DBConn) {
  global $system;

  $annot_records   = array();
  $transcript_info = getTranscriptData($arrRecord['gene_id'], $arrRecord['version'], false, $DBConn); // false=all transcripts
  $annotations     = getFunctionalAnnotation($arrRecord, 
                                             $transcript_info, 
                                             'gene model functional annotation', 
                                             $DBConn);

  $annotation_info = false;
  if ($annotations) {
    $annotation_info = array();
    foreach($annotations as $annotation) {
      //TODO: fix Phytozome data to look like generic annotation data
      if ($annotation['sourcename'] == 'Phytozome') {
        continue;
      }

      $transcript_annots = '';
      foreach ($transcript_info as $transcript) {
        $transcript_acc = (isset($transcript['transcript_acc'])) 
                        ? $transcript['transcript_acc'] : null;
        $transcript_url = (isset($transcript['transcript_transcript_url']))
                        ? $transcript['transcript_transcript_url'] : null;
        $transcript_id  = (isset($transcript['transcript_id']))
                        ? $transcript['transcript_id'] : null;
        $transcript     = (isset($transcript['transcript'])) 
                        ? $transcript['transcript'] : null;

        $annot_type_recs = getAnalysisData($annotation['analysis_id'], $transcript_id, $DBConn);
        if (!$annot_type_recs) {
          continue;
        }

        $annotstr = '';
        foreach ($annot_type_recs as $type => $annot_values) {
          // If type looks like an onto term, make it more friendly
          $label = (strpos($type, '_')) 
                 ? ucwords(str_replace('_', ' ', $type)) : $type;
          $annotstr .= "<b>$label:</b> ";

          foreach ($annot_values as $annot) {
            if (isset($annot['value'])) {
              $annotstr .= $annot['value'] . ' ';
            }
            else {
              // This is a linkout
              $annotstr .= "<a href=\"". $annot['urlprefix'] . $annot['accession'] . "\">";
              $annotstr .= $annot['accession'] . "</a> ";
            }
          }//each annotation of this type
          $annotstr .= "<br>";
        }//each type of annotation for this annotation set

        $transcript_annots .= makeTranscriptAnnotation($transcript_acc, 
                                                       $transcript_url, 
                                                       $transcript, 
                                                       $annotstr);
      }//each transcript infor record

      if ($transcript_annots && count($transcript_annots) > 0) {
        $publication = makePublicationLinks($row['pmid'], 
                                            $row['doi'], 
                                            $row['reference_id'], $DBConn);
        array_push($annotation_info, array(
            'annotation_source'          => $annotation['sourcename'],
            'annotation_version'         => $annotation['sourceversion'],
            'annotation_program'         => $annotation['program'],
            'annotation_program_version' => $annotation['programversion'],
            'annotation_publication'     => $publication,
            'analysis_html'              => mgdb_safe_html($annotation['description']),
            'transcript-annots'          => $transcript_annots,
          )
        );
      }
    }//each annotation set
  }//annotations where found

  if ($annotation_info && count($annotation_info) > 0) {
    $tmpl->get('annotation-sets')->loop($annotation_info);
    $tmpl->get('annotation-sets')->unmute();
  }
}//showFunctionalAnnotations


function showFreeTextAnnotations($tmpl, $curr_gene_model_version, $id, $arrRecord, $DBConn) {
  global $super_curator, $username, $author_id;

  $arrAnnotations = getAnnotations($DBConn, $id, $curr_gene_model_version,
                                   $username, $author_id, $super_curator, 'genemodel');
  
  // Handle the (*&@^#-ed + character in 5b+!
  for ($i=0; $i<count($arrAnnotations); $i++) {
    if ($arrAnnotations[$i]['gene_model_version'] == '5b+') {
      $arrAnnotations[$i]['gene_model_version'] = '5b%2B';
      // Need a human-friend version too
      $arrAnnotations[$i]['gene_model_version_modified'] = '5b+';
    }
    else {
      $arrAnnotations[$i]['gene_model_version_modified'] = $arrAnnotations[$i]['gene_model_version'];
    }
  }

  if (!$arrAnnotations || count($arrAnnotations) == 0) {
// There are almost none of these, so don't bother saying so.
//    $tmpl->get('no-user')->unmute();
// }
// else if ($super_curator) {
//   $tmpl->get('user-list-ex')->loop($arrAnnotations);
//   $tmpl->get('user-curator')->unmute();
 }
  else {
    $tmpl->get('user-list')->loop($arrAnnotations);
    $tmpl->get('user')->unmute();
  }
}//showFreeTextAnnotations


/* Not sure this makes sense anymore (eksc 04/07/26)
function showGeneModelAnalyses($tmpl, $version, $id, $arrRecord, $DBConn) {
  $sql = "
    SELECT DISTINCT a.name AS analysis_name, f.name AS feature, cmt.value AS comment, 
           x.accession, db.urlprefix 
    FROM chado.feature f
      INNER JOIN chado.analysisfeature af ON af.feature_id=f.feature_id
      INNER JOIN chado.analysis a ON a.analysis_id=af.analysis_id
      INNER JOIN chado.analysisprop ap ON ap.analysis_id=a.analysis_id
        AND ap.type_id=(SELECT cvterm_id FROM chado.cvterm 
                        WHERE name='analysis_type' 
                          AND cv_id=(SELECT cv_id FROM chado.cv 
                                     WHERE name='maizegdb'))
        AND ap.value='gene model analysis' 
      INNER JOIN chado.analysisprop cmt ON cmt.analysis_id=a.analysis_id
        AND cmt.type_id=(SELECT cvterm_id FROM chado.cvterm 
                         WHERE name='comment' 
                           AND cv_id=(SELECT cv_id FROM chado.cv 
                                      WHERE name='maizegdb'))
      LEFT JOIN chado.analysisfeature_dbxref afx ON afx.analysisfeature_id=af.analysisfeature_id
      LEFT JOIN chado.dbxref x ON x.dbxref_id=afx.dbxref_id
      LEFT JOIN chado.db ON db.db_id=x.db_id
    WHERE f.name LIKE " . $DBConn->quote($id . '%');
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);
  if (count($rows) > 0) {
    $analyses_html = array();
    foreach ($rows as $row) {
      if (!isset($analyses_html[$row['analysis_name']])) {
        $analyses_html[$row['analysis_name']] = '';
      }
      $analyses_html[$row['analysis_name']] .= '&nbsp;&nbsp;&nbsp;&nbsp;' 
                                             . $row['feature'] . ' ' . $row['comment'];
      if (isset($row['accession']) && isset($row['urlprefix'])) {
        $url = $row['urlprefix'] . $row['accession'];
        $analyses_html[$row['analysis_name']] .= " (<a href='$url'>" . $row['accession'] . "</a>)";
      }
      $analyses_html[$row['analysis_name']] .= '<br>';
    }
    
    $full_html = '';
    foreach ($analyses_html as $analysis => $html) {
      $full_html .= "&nbsp;&nbsp;<b>$analysis</b><br>$html<br>";
    }
    
    $tmpl->get('generic_analyses')->replace($full_html);
    $tmpl->get('gene_model_analyses')->unmute();
  }
}//showGeneModelAnalyses
*/


function showPhytozome($tmpl, $phytozome, $transcript_info, $DBConn) {
  $tmpl->get('phytozome_version')->replace($phytozome['sourceversion']);
  $tmpl->get('phytozome_maize_version')->replace($phytozome['programversion']);

  $phytozome_records = array();

  foreach ($transcript_info as $transcript) {
    $phytozome_data  = getAnalysisData($phytozome['analysis_id'],
                                       $transcript['transcript_id'],
                                       $DBConn);
                                       
    // HTML constructed here because we can't do nested loops in Bauplan
    if ($phytozome_data) {
      $pfam = '';
      if (isset($phytozome_data['pfam_ID'])) {
        $pfam = '<b>PFAM ID:</b> ';
        foreach ($phytozome_data['pfam_ID'] as $arr) {
          $pfam .= "<a href='http://pfam.xfam.org/family/"
                 . $arr['value'] . "'>" . $arr['value']."</a> ";
        }
        $pfam .= "<br>";
      }//pfam

      $panther = '';
      if (isset($phytozome_data['panther_ID'])) {
        $panther = '<b>Panther ID:</b> ';
        foreach ($phytozome_data['panther_ID'] as $arr) {
          $panther .= "<a href='http://www.pantherdb.org/panther/globalSearch.do?fieldValue="
                    . $arr['value'] . "'>" . $arr['value'] . "</a> ";
        }
        $panther .= "<br>";
      }//panther

      $kog = '';
      if (isset($phytozome_data['kog_ID'])) {
        $kog = '<b>KOG ID:</b> ';
        foreach ($phytozome_data['kog_ID'] as $arr) {
          $kog .= "<a href='https://www.ncbi.nlm.nih.gov/Structure/cdd/cddsrv.cgi?uid="
                . $arr['value'] . "'>" . $arr['value'] . "</a> ";
        }
        $kog .= "<br>";
      }//kog

      $kegg_ec = '';
      if (isset($phytozome_data['kegg_ec'])) {
        $panther = '<b>KEGG EC:</b> ';
        foreach ($phytozome_data['kegg_ec'] as $arr) {
          $panther .= "<a href='http://www.genome.jp/dbget-bin/www_bget?ec:"
                    . $arr['value'] . "'>" . $arr['value'] . "</a> ";
        }
        $panther .= "<br>";
      }//kegg_ec

      $kegg_orth = '';
      if (isset($phytozome_data['kegg_ortholog'])) {
        $kegg_orth = '<b>KEGG Ortholog:</b> ';
        foreach ($phytozome_data['kegg_ortholog'] as $id_arr) {
          $kegg_orth .= "<a href='http://www.genome.jp/dbget-bin/www_bget?ko:"
                      . $id_arr['value'] . "'>" . $id_arr['value'] . "</a> ";
        }
        $kegg_orth .= "<br>";
      }//kegg_ec

      $At_orth = '';
      if (isset($phytozome_data['arabidopsis_ortholog'])) {
        $At_orth = '<b>Arabidopsis best hit:</b> ';
        foreach ($phytozome_data['arabidopsis_ortholog'] as $id_arr) {
          $At_description = '';
          if (isset($phytozome_data['arabidopsis_ortholog_symbol'])) {
            $arr = array();
            foreach ($phytozome_data['arabidopsis_ortholog_symbol'] as $e) {
              array_push($arr, $e['value']);
            }
            $At_description .= '(' . implode(', ', $arr) . ') ';
          }
          if (isset($phytozome_data['arabidopsis_ortholog_name'])) {
            $arr = array();
            foreach ($phytozome_data['arabidopsis_ortholog_name'] as $e) {
              array_push($arr, $e['value']);
            }
            $At_description .= implode(' ', $arr);
          }
logMessage("Remove .1 from " . $id_arr['value'] . ":");
          $id_arr['value'] = preg_replace("/\.\d+$/", '', $id_arr['value']);
logMessage($id_arr['value']);
          $At_orth .= "<a href='https://www.arabidopsis.org/locus?name="
                    . $id_arr['value'] . "'>" . $id_arr['value'] . "</a> ";
          $At_orth .= $At_description;
        }
        $At_orth .= "<br>";
      }//At_orth

      $Os_orth = '';
      if (isset($phytozome_data['rice_ortholog'])) {
        $Os_orth = '<b>Rice best hit:</b> ';
        foreach ($phytozome_data['rice_ortholog'] as $id_arr) {
          $Os_description = '';
          if (isset($phytozome_data['rice_ortholog_symbol'])) {
            $arr = array();
            foreach ($phytozome_data['rice_ortholog_symbol'] as $e) {
              array_push($arr, $e['value']);
            }
            $Os_description .= '(' . implode(', ', $arr) . ') ';
          }
          if (isset($phytozome_data['rice_ortholog_name'])) {
            $arr = array();
            foreach ($phytozome_data['rice_ortholog_name'] as $e) {
              array_push($arr, $e['value']);
            }
            $Os_description .= implode(' ', $arr);
          }
          $Os_orth .= "<a href='http://rice.plantbiology.msu.edu/cgi-bin/ORF_infopage.cgi?db=osa1r6&orf="
                    . $id_arr['value'] . "'>" . $id_arr['value'] . "</a> ";
          $Os_orth .= $Os_description;
        }
        $Os_orth .= "<br>";
      }//At_orth

      $rec = array(
        'transcript_acc' => $transcript['transcript_acc'],
        'transcript_id'  => $transcript['transcript'],
        'pfam_id'        => $pfam,
        'panther_id'     => $panther,
        'kog_id'         => $kog,
        'kegg_ec'        => $kegg_ec,
        'kegg_orth'      => $kegg_orth,
        'tair_hit'       => $At_orth,
        'rice_hit'       => $Os_orth,
      );

      array_push($phytozome_records, $rec);
    }
  }//each transcript

  if ($phytozome_records && count($phytozome_records) > 0) {
    $tmpl->get('transcript_sec')->loop($phytozome_records);
    $tmpl->get('phytozome')->unmute();
  }
}//showPhytozome


function showProteinScores($tmpl, $arrRecord, $found_scores, $DBConn) {
logMessage("Starting showProteinScores()");
  $scores = array();
  $sql = "
    SELECT DISTINCT tr.transcript_name AS transcript, 
           ARRAY_AGG(afp.value || '|' || af.rawscore) AS score
    FROM chado.transcript tr
      INNER JOIN chado.analysisfeature af ON af.feature_id=tr.transcript_id
      INNER JOIN chado.analysisfeatureprop afp ON afp.analysisfeature_id=af.analysisfeature_id
      INNER JOIN chado.analysis a ON a.analysis_id=af.analysis_id
    WHERE a.name IN ('IUPred2A', 'AlphaFold2', 'ESMFold') 
          AND tr.gene_name = '{$arrRecord['gene_id']}'
    GROUP BY tr.transcript_name
    ORDER BY tr.transcript_name";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
logVarDump($row, "One row");
logMessage("canonical: " .$arrRecord['transcript_id']);
    if ($row['transcript'] == $arrRecord['transcript_id']) {
      $row['transcript'] = '<b>' . $row['transcript'] . '</b>';
    }
    $score = array('transcript' => $row['transcript']);

    $scorestr = str_replace('{', '', str_replace('}', '', $row['score']));
    $scorearray = explode(',', $scorestr);
    foreach ($scorearray as $s) {
      $onescore = explode('|', $s);
      $score[$onescore[0]] = number_format($onescore[1], 3);
    }//each score   
    array_push($scores, $score);
  }//each data row
logVarDump($scores);

  if (count($scores) > 0) {
    $tmpl->get('protein_structure-scores')->loop($scores);
    $tmpl->get('protein_structure')->unmute();
    return true;
  }
  
  return $found_scores;
}//showProteinScores


function showReelGeneScores($tmpl, $arrRecord, $found_scores, $DBConn) {
// app: https://reelgene.maizegdb.org/
// paper: https://doi.org/10.1111/tpj.70483
  $scores = array();
  $sql = "
    SELECT DISTINCT tr.transcript_name AS transcript, 
           ARRAY_AGG(afp.value || '|' || af.rawscore) AS score
    FROM chado.transcript tr
      INNER JOIN chado.analysisfeature af ON af.feature_id=tr.transcript_id
      INNER JOIN chado.analysisfeatureprop afp ON afp.analysisfeature_id=af.analysisfeature_id
      INNER JOIN chado.analysis a ON a.analysis_id=af.analysis_id
    WHERE a.name='reelGene' AND tr.gene_name = '{$arrRecord['gene_id']}'
    GROUP BY tr.transcript_name
    ORDER BY tr.transcript_name";
  $sth = make_query($DBConn, $sql);
  while ($row=retrieve_row($sth)) {
    if (strstr($row['score'], 'NULL')) {
      continue;  // null = no scores
    }
    
    if ($row['transcript'] == $arrRecord['transcript_id']) {
      $row['transcript'] = '<b>' . $row['transcript'] . '</b>';
    }
    
    $score = array('transcript' => $row['transcript']);

    $scorestr = str_replace('{', '', str_replace('}', '', $row['score']));
    $scorearray = explode(',', $scorestr);
    foreach ($scorearray as $s) {
      $onescore = explode('|', $s);
      $score[$onescore[0]] = number_format($onescore[1], 3);
    }
    array_push($scores, $score);
  }//each row
  
  if (count($scores) > 0) {
    $tmpl->get('reelGene-scores')->loop($scores);
    $tmpl->get('reelGene')->unmute();
    return true;
  }
  
  return $found_scores;
}//showReelGeneScores


function showpSAURONscores($tmpl, $arrRecord, $found_scores, $DBConn) {
  $scores = array();
  $sql = "
    SELECT DISTINCT tr.transcript_name AS transcript, 
           ARRAY_AGG(afp.value || '|' || af.rawscore) AS score
    FROM chado.transcript tr
      INNER JOIN chado.analysisfeature af ON af.feature_id=tr.transcript_id
      INNER JOIN chado.analysisfeatureprop afp ON afp.analysisfeature_id=af.analysisfeature_id
      INNER JOIN chado.analysis a ON a.analysis_id=af.analysis_id
    WHERE a.name='pSAURON' AND tr.gene_name = '{$arrRecord['gene_id']}'
    GROUP BY tr.transcript_name
    ORDER BY tr.transcript_name";
  $sth = make_query($DBConn, $sql);

  $scores_of_interest = array('gene_model', 'is_protein', 'in_frame_score');
  while ($row=retrieve_row($sth)) {
//logVarDump($row, "One row");
    if ($row['transcript'] == $arrRecord['transcript_id']) {
      $row['transcript'] = '<b>' . $row['transcript'] . '</b>';
    }
    $score = array('transcript' => $row['transcript']);

    $scorestr = str_replace('{', '', str_replace('}', '', $row['score']));
    $scorearray = explode(',', $scorestr);
    foreach ($scorearray as $s) {
      $onescore = explode('|', $s);
      if (in_array($onescore[0], $scores_of_interest)) {
        if ($onescore[0] == 'is_protein') {
          $score[$onescore[0]] = ($onescore[1] == '1') ? 'yes' : 'no';
        }
        else {
          $score[$onescore[0]] = number_format($onescore[1], 3);
        }
      }
    }
    array_push($scores, $score);
  }//each data row

  if (count($scores) > 0) {
    $tmpl->get('pSAURON-scores')->loop($scores);
    $tmpl->get('pSAURON')->unmute();
    return true;
  }
  
  return $found_scores;
}//showpSAURONscores


function showScores($tmpl, $arrRecord, $DBConn) {
logMessage("Starting showScores()");
  $found_scores = false;
  $found_scores = showAEDscore($tmpl, $arrRecord, $found_scores, $DBConn);
  $found_scores = showReelGeneScores($tmpl, $arrRecord, $found_scores, $DBConn);
  $found_scores = showpSAURONscores($tmpl, $arrRecord, $found_scores, $DBConn);
  $found_scores = showProteinScores($tmpl, $arrRecord, $found_scores, $DBConn);
  if ($found_scores) {
    $tmpl->get('scores')->unmute();
  }
  else {
    $tmpl->get('no-scores')->unmute();
  }
#Mikado (yes/no)
#Helixer (yes/no)
#PlantCAD
#phylostratR
#v3 gene model (yes/no)
#v4 gene model (yes/no)
#NCBI annotation (yes/no)
#pLDDT from ESMFold or Alphafold
#Average qTeller FPKM value
#TE (yes/no)
#UMRs (Unmethylated regions; yes/no)  

}//showScores


?>
