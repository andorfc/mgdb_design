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
  include_once('./include/blast_form_lib.php');
  
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
  $DBConn = connect_to_database();

  /* Everything the form needs is in include/blast_form_lib.php, because this
     page is no longer the only one that carries it -- the Gene Data Hub's
     "BLAST a sequence" section loads the same template and calls the same
     function. */
  blastFormSetup($tmpl, $DBConn, $system);

  include_once('translation.php');
?>
