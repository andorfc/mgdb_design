<?php
/* file: include/blast_form_lib.php
 *
 * purpose: Everything needed to put the BLAST search form on a page: the
 *          template's values, the species list, the starting targets, and the
 *          two ways it can arrive pre-filled.
 *
 * history:
 *  09/11/26  claude  factored out of controllers/BLAST/BLAST_form.php so the
 *                    Gene Data Hub can carry the same form rather than a
 *                    smaller lookalike of it.
 *
 * The form itself is one template, controllers/BLAST/BLAST_form.bau, and one
 * script, controllers/BLAST/BLAST.js, which is NOT repo-owned and is shared
 * with the results page. Neither is copied anywhere: a page that wants the
 * form loads that template into a slot and calls blastFormSetup() on it.
 *
 * runBLAST() in BLAST.js builds its own form element and posts it to
 * /BLAST/BLAST_form.php, so a search runs the same way and lands on the same
 * results page whatever page it was started from. Nothing here depends on the
 * form being at /BLAST.
 *
 * Two things a host page owes the form:
 *   css/mgdb-blast.css, whose every rule is scoped to .mgdb-blast-page -- a
 *     wrapper carrying that class scopes them to the form
 *   controllers/BLAST/BLAST.js and js/mgdb-blast.js
 */

include_once('./controllers/BLAST/BLAST_lib.php');


/* Fills a loaded BLAST_form.bau with its starting state.

   $tmpl is the loaded form section, not the page. Returns nothing; everything
   it does is a replace() on that section. */
function blastFormSetup($tmpl, $DBConn, $system) {
  $tmpl->get('eutil_key')->replace($system['eutil_key']);
  $tmpl->get('max_query_size')->replace(MAX_SEQUENCE_LENGTH);
  $tmpl->get('max_queries')->replace(MAX_QUERIES);

  
  // Restore settings?
  if (getCGIParam('saved_job_id', 'P', false)) {
    restoreSettings($tmpl, $DBConn);
  }
  else {
    // defaults
    if (getCGIParam('query_seq_type', 'P', '') === 'protein') {
      $tmpl->get('protein_checked')->replace('checked');
    }
    else {
      $tmpl->get('nucleotide_checked')->replace('checked');
    }
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

    $handed_over = prefillFromRequest($tmpl, $DBConn);
    if ($handed_over !== '') {
      $tmpl->get('selected_targets')->replace($handed_over);
    }
    else {
      setDefaultTargets($tmpl, $DBConn);
    }
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

}//blastFormSetup


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

/* The selected-target rows for a comma-separated list of pc_blast_ctl ids.
   Used both when a saved job is reopened and when another page hands this one
   a dataset to start from. */
function targetRows($targets, $DBConn) {
  $html = '';
  foreach (explode(',', (string) $targets) as $blast_id) {
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
    $assembly = htmlspecialchars($rec['assembly_name'], ENT_QUOTES, 'UTF-8');
    $type     = htmlspecialchars($rec['target_type'], ENT_QUOTES, 'UTF-8');
    $html .= "
      <tr id=\"{$rec['blast_id']}\" class=\"selected_BLAST_target\">
        <td class=\"BLAST\">$assembly-$type</td>
        <td><a href=\"#!\" onclick=\"removeTarget({$rec['blast_id']})\"><b>X</b></a></td>
      </tr>";
  }//each target

  return $html;
}//targetRows


/* A query handed over by another page.

   The Gene Data Hub's "BLAST a sequence" panel posts a sequence, a sequence
   type and one dataset here rather than to the retired popcorn BLAST. There is
   no saved job behind it, so everything else keeps its default and the reader
   lands on this form with their query already in it.

   Returns the selected-target markup when the caller named one, so the caller
   can skip setDefaultTargets; '' when it did not, and B73 v5 stays the start.

   The sequence is escaped on the way into the textarea. restoreSettings() does
   not escape, because what it writes is a FASTA file this site wrote itself;
   this arrives from a request. */
function prefillFromRequest($tmpl, $DBConn) {
  $sequence = (string) getCGIParam('query_sequence', 'P', '');
  if ($sequence !== '') {
    $tmpl->get('query_sequence')->replace(htmlspecialchars($sequence, ENT_QUOTES, 'UTF-8'));
  }

  $targets = (string) getCGIParam('targets', 'P', '');

  return $targets === '' ? '' : targetRows($targets, $DBConn);
}//prefillFromRequest


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

  $tmpl->get('selected_targets')->replace(targetRows(getCGIParam('targets', 'P', ''), $DBConn));

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
