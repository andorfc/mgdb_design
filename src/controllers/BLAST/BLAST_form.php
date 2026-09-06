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
  /* Two, on Carson's call (2026-09-05): cite MaizeGDB, not the corpora behind
     the target datasets. It listed five -- the 1990 BLAST algorithm paper, the
     NAM genomes paper, the pan-gene paper and a multi-genome search paper
     alongside these -- which read as a bibliography for maize sequence rather
     than for this page.

     Both are in data/cite_journal_articles.json, the curated bibliography, so
     neither needs a fallback: title, authors, journal, volume, pages, PubMed ID
     and abstract all come from the one record behind /cite. */
  $page->get('reference_cards')->replace(mgdb_render_references($blast_doc_root, array(
      // Tools and Resources at MaizeGDB. Cold Spring Harbor Protocols, 2025.
      array('doi' => '10.1101/pdb.over108430'),
      // MaizeGDB 2018: the maize multi-genome genetics and genomics database.
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
    /* No output_format_*_checked placeholder any more -- the result-format step
       went on 2026-09-05 and its one remaining radio is hard-coded checked in
       the template. Nary::get throws on an identifier the template does not
       declare, so setting it here would fatal the page. */
    $tmpl->get('BLAST_perc_identity')->replace('0');
    $tmpl->get('BLAST_max_hsps')->replace('');
    setDefaultTargets($tmpl, $DBConn);
  }
  
  $tmpl->get('target_species_options')->loop(getSpeciesOptions($DBConn));

  /* The current reference assembly's own datasets as "+" buttons -- the only
     way to add a dataset now, not a shortcut beside a dropdown. Picking a
     different assembly hands the same panel to js/mgdb-blast.js, which reads
     the (hidden) #BLAST_target select fillTargets() already populates and
     rebuilds this same markup for whatever assembly is chosen. This is only
     the page's starting point: B73 v5, so a sequence and a press of Run BLAST
     is a complete search without picking anything in step 2 at all. */
  $quick = getQuickTargets($DBConn, $system['cur_ref_gen']);
  if ($quick) {
      $cur_ref_escaped = htmlspecialchars($system['cur_ref_gen'], ENT_QUOTES, 'UTF-8');
      /* Two tokens for one value: the label text and the `data-cur-ref`
         attribute js/mgdb-blast.js reads to decide whether a later assembly
         pick still counts as "the current reference". A single token used
         twice in one template is not a pattern used elsewhere in this
         codebase, so this does not rely on guessing whether Bauplan would
         replace both occurrences. */
      $tmpl->get('quick_assembly')->replace($cur_ref_escaped);
      $tmpl->get('quick_assembly_attr')->replace($cur_ref_escaped);
      $tmpl->get('quick_target_rows')->loop($quick);
      $tmpl->get('quick-targets')->unmute();
  }

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



/*
 * A BLAST assembly's datasets, as the "+" buttons in the gold panel.
 *
 * Called once here, for the default reference assembly at page load.
 * js/mgdb-blast.js calls no PHP at all for later picks -- it rebuilds the same
 * chip markup client-side from the #BLAST_target select fillTargets() already
 * populates, so this function's only job is the page's starting state.
 *
 * `quick_label` is the text a chip's click handler puts in the row -- assembly
 * name, a hyphen, target type -- matching what addTarget() used to build, so a
 * chip-added row and the old picked-and-added one are identical. The ORDER BY
 * puts the whole assembly first and then the gene model datasets, which is the
 * order they are usually wanted in; ordering by target_type alone would lead
 * with "Gene model CDS".
 */
function getQuickTargets($DBConn, $assembly) {
  if (!$assembly) {
    return array();
  }

  $safe = pg_escape_string($assembly);
  $sql = "
    SELECT b.id, b.target_type
    FROM mgdb.pc_blast_ctl b
      INNER JOIN mgdb.id_num idn ON idn.id = b.id
    WHERE idn.curation_lvl = 0 AND b.assembly_name = '$safe'
    ORDER BY
      CASE WHEN b.target_type = 'Assembly' THEN 0 ELSE 1 END,
      b.target_type";
  $sth = make_query($DBConn, $sql);
  $rows = get_all_rows($sth);

  $quick = array();
  foreach ($rows as $row) {
    $quick[] = array(
      'quick_id'    => $row['id'],
      'quick_type'  => htmlspecialchars($row['target_type'], ENT_QUOTES, 'UTF-8'),
      'quick_label' => htmlspecialchars($assembly . '-' . $row['target_type'], ENT_QUOTES, 'UTF-8'),
    );
  }

  return $quick;
}//getQuickTargets

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
  
  /* Reopening a job no longer restores a result format: there is one format,
     and the template's single radio is already checked. A saved job from before
     2026-09-05 may carry output_format=BLAST_table or BLAST_text in its .parms;
     it is read and ignored, because the modern results page renders the table
     and text views from the same JSON either way. */

  $targets = explode(',', getCGIParam('targets', 'P', ''));
  $html = '';
  foreach ($targets as $blast_id) {
    /* getBLASTrecord() in BLAST_lib.php interpolates this straight into
       `WHERE id=$blast_id` with no escaping, and `targets` arrives from the
       request. A cast is all this file can do about that; the endpoints in
       BLAST_tasks.php have the same problem and are recorded as AD-049. */
    $blast_id = (int) $blast_id;
    if (!$blast_id) {
      continue;
    }
    $rec = getBLASTrecord($blast_id, $DBConn);
    if (!$rec) {
      continue;
    }
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