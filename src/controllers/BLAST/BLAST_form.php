<?php
/* file: BLAST_form.php
 * 
 * Purpose: set up BLAST form. Some set up is done by Ajax calls in the page.
 *
 * History
 *  03/18/25  eksc  created
 */

  include_once('../../lib/Bauplan.php');
  include_once('../../include/db-api.php');
  include_once('../../include/gp_lib.php');
  include_once('BLAST_lib.php');
  
  $system = getSystemInfo('mgdb.conf');
//logVarDump($_POST, "Incoming to BLAST_form.php:\n");

  /* Create templating object ($mgdb created by BLAST.php). The form is nested
     inside the modern page wrapper rather than loaded straight into the body;
     $tmpl still points at the form template, so every assignment below -- and
     restoreSettings(), setDefaultTargets() and the species query -- is
     unchanged. */
  $page = $mgdb->get('body')->load('templates/static/mgdb_blast.bau');
  $tmpl = $page->get('blast-form')->load('controllers/BLAST/BLAST_form.bau');

  /* References: BLAST itself, and the maize sequence these searches run
     against. Rendered by include/references_lib.php so these cards match the
     rest of the site. */
  include_once('./include/references_lib.php');
  $blast_doc_root = isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT']
                  ? $_SERVER['DOCUMENT_ROOT'] : '/var/www/claude/html';
  $page->get('reference_cards')->replace(mgdb_render_references($blast_doc_root, array(
      /* The algorithm. Not a MaizeGDB paper, so it is not in the curated
         bibliography and its details are supplied inline. */
      array('doi' => '10.1016/S0022-2836(05)80360-2',
            'fallback' => array(
                'title'   => 'Basic local alignment search tool',
                'authors' => 'Altschul SF, Gish W, Miller W, Myers EW, Lipman DJ',
                'journal' => 'Journal of Molecular Biology',
                'year'    => 1990)),
      // The assemblies and annotations most of these target datasets are.
      array('doi' => '10.1126/science.abg5289'),
      // The pan-genomic resources the newer targets come from.
      array('doi' => '10.1093/genetics/iyae036'),
      // Querying these collections together rather than one search at a time.
      array('doi' => '10.3389/fpls.2020.592730'),
      // The database of record.
      array('doi' => '10.1093/nar/gky1046'),
  )));
  $tmpl->get('eutil_key')->replace($system['eutil_key']);
  $tmpl->get('max_query_size')->replace(MAX_SEQUENCE_LENGTH);
  $tmpl->get('max_queries')->replace(MAX_QUERIES);

  $DBConn = connect_to_database();
  
  // Restore settings?
  if (getCGIParam('saved_job_id', 'P', false)) {
    restoreSettings($tmpl, $DBConn);
  }
  else {
    // defaults
    $tmpl->get('nucleotide_checked')->replace('checked');
    $tmpl->get('BLAST_param_set_default_checked')->replace('checked');
    $tmpl->get('BLAST_max_hits250_selected')->replace('selected');
    $tmpl->get('BLAST_word_size11_selected')->replace('selected');
    $tmpl->get('BLAST_match_mismatch_scores1,-2_selected')->replace('selected');
    $tmpl->get('BLAST_max_evalue')->replace('1e-10');
    $tmpl->get('output_format_enhanced_checked')->replace('checked');
    $tmpl->get('BLAST_perc_identity')->replace('0');
    $tmpl->get('BLAST_max_hsps')->replace('');
    setDefaultTargets($tmpl, $DBConn);
  }
  
  $tmpl->get('target_species_options')->loop(getSpeciesOptions($DBConn));

  if ($cached_jobs = getCGIParam('BLAST_jobs', 'S', false)) {
    $cached_jobs_arr = explode(',', $cached_jobs);
    $cached_job_names = array();
    foreach ($cached_jobs_arr as $cached_job) {
      $cached_job_names[] = array('job_name' => $cached_job);
    }
    $tmpl->get('cached_job_names')->loop($cached_job_names);
    $tmpl->get('cached-jobs')->unmute();
  }

  include_once('translation.php');
  
  
/////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////

function getSpeciesOptions($DBConn) {
  $sql = "
    SELECT * FROM (
      SELECT DISTINCT organism_id, genus, species, infraspecific_name 
      FROM chado.organism  o
        INNER JOIN chado.biomaterial b ON b.taxon_id=o.organism_id
        INNER JOIN chado.sequence_metadata sm ON sm.biomaterial_id=b.biomaterial_id
        INNER JOIN chado.analysis a ON a.analysis_id=sm.analysis_id
        INNER JOIN mgdb.pc_blast_ctl pbc ON pbc.assembly_name=a.name
      WHERE organism_id IN (SELECT taxon_id FROM chado.biomaterial 
                            WHERE biomaterial_id IN (SELECT biomaterial_id 
                                                     FROM chado.sequence_metadata)) 
    ) s
    ORDER BY
      CASE
        WHEN genus='Zea' AND species='mays' AND infraspecific_name='ssp. mays' 
          THEN (1, genus, species, infraspecific_name)
        WHEN genus='Zea' AND species='mays' 
          THEN (2, genus, species, infraspecific_name)
        WHEN genus='Zea'
          THEN (3, genus, species, infraspecific_name)
        ELSE (4, genus, species, infraspecific_name)
      END";
  $sth = make_query($DBConn, $sql);
  
  return get_all_rows($sth);
}//getSpeciesOptions


function restoreSettings($tmpl, $DBConn) {
  global $system;
//logVarDump($_POST, "Incoming to restoreSettings():\n");

  $job_id = getCGIParam('saved_job_id', 'P', false);
  $fasta_file = $system['temp_dir'] . "/$job_id.fa";
//logMessage("Read FASTA from $fasta_file");
//logMessage("File contents:\n" . file_get_contents($fasta_file));
  $tmpl->get('query_sequence')->replace(file_get_contents($fasta_file));
  
  $query_seq_type = getCGIParam('query_seq_type', 'P', false);
  if ($query_seq_type == 'nucleotide') {
    $tmpl->get('nucleotide_checked')->replace('checked');
  }
  else {
    $tmpl->get('protein_checked')->replace('checked');
  }

  if ($param_set = getCGIParam('BLAST_param_set', 'P', false)) {
    $checked = "BLAST_param_set_$param_set" . "_checked";
    $tmpl->get($checked)->replace('checked');
  }
  
  if ($max_hits = getCGIParam('BLAST_max_hits', 'P', false)) {
    $selected = "BLAST_max_hits$max_hits" . "_selected";
    $tmpl->get($selected)->replace('selected');
  }
  
  if ($wordsize = getCGIParam('BLAST_word_size', 'P', false)) {
    $selected = "BLAST_word_size$wordsize" . "_selected";
    $tmpl->get($selected)->replace('selected');
  }

  if ($match_mismatch = getCGIParam('BLAST_match_mismatch_scores', 'P', false)) {
    $selected = "BLAST_match_mismatch_scores$match_mismatch" . "_selected";
    $tmpl->get($selected)->replace('selected');
  }
  
  if ($output_format = getCGIParam('output_format', 'P', false)) {
    $selected = "output_format_$output_format" . "_checked";
    $tmpl->get($selected)->replace('checked');
  }
  
  $targets = explode(',', getCGIParam('targets', 'P', ''));
  $html = '';
  foreach ($targets as $blast_id) {
    $rec = getBLASTrecord($blast_id, $DBConn);
    $html .= "
      <tr id=\"{$rec['blast_id']}\" class=\"selected_BLAST_target\">
        <td class=\"BLAST\">{$rec['assembly_name']}-{$rec['target_type']}</td>
        <td><a href=\"#!\" onclick=\"removeTarget({$rec['blast_id']})\"><b>X</b></a></td>
      </tr>";
  }//each target
  $tmpl->get('selected_targets')->replace($html);

  // The easy ones...
  $tmpl->get('query_sequence')->replace(getCGIParam('query_sequence', 'P', ''));
  $tmpl->get('BLAST_max_evalue')->replace(getCGIParam('BLAST_max_evalue', 'P', ''));
  $tmpl->get('BLAST_perc_identity')->replace(getCGIParam('BLAST_perc_identity', 'P', ''));
  $tmpl->get('BLAST_max_hsps')->replace(getCGIParam('BLAST_max_hsps', 'P', ''));
  $tmpl->get('query_sequence')->replace(getCGIParam('query_sequence', 'P', ''));

}//restoreSettings


function setDefaultTargets($tmpl, $DBConn) {
  global $system;
  
  $default_assembly = $system['cur_ref_gen'];  // NOTE: "ref_gen" is a legacy term
  $sql = "
    SELECT id FROM pc_blast_ctl
    WHERE assembly_name='$default_assembly' AND target_type='Assembly'";
  $sth = make_query($DBConn, $sql);
  $row = retrieve_row($sth);
  $html = "
    <tr id=\"{$row['id']}\" class=\"selected_BLAST_target\">
      <td class=\"BLAST\">$default_assembly-Assembly</td>
      <td><a href=\"#!\" onclick=\"removeTarget({$row['id']})\"><b>X</b></a></td>
    </tr>";
  
  $tmpl->get('selected_targets')->replace($html);
}//setDefaultTargets
?>