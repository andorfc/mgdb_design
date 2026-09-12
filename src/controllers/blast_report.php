<?php
/* file: blast_report.php
 *
 * purpose: retired 2026-09-07 (Carson). /blast_report now redirects to /BLAST.
 *
 * An operator's readout, not a reader's page. It printed a count of configured
 * BLAST targets and a count by sequence type, and that is all it printed. As
 * served it read, in full:
 *
 *   BLAST Target Count  Zm-B73-REFERENCE-NAM-5.0  10
 *   Sequence Type Count  Nucleotide 10  Amino Acid  [blank]
 *
 * -- one assembly named out of the several dozen the BLAST database list
 * actually carries, and an empty cell where the protein count belongs. It was
 * already wrong as well as internal.
 *
 * Zero requests in the log window and no inbound link anywhere on the site.
 * /BLAST names its own targets, from the same configuration, on the form the
 * reader is going to use.
 *
 * Rollback: delete this file and controllers/static/blast_report.php serves the
 * readout again.
 */

  header('Location: /BLAST', true, 301);
  exit;
?>
