-- AD-052 — repair the botched string edit on the six Td-KS_B6_1-REFERENCE-PanAnd-2.0
--          gene-model rows in mgdb.pc_blast_ctl
--
-- REQUIRES A DBA. The application role `mgdb` in conf/db.conf holds SELECT only on
-- this table (relacl: mgdb=r/postgres, owner postgres); it has UPDATE on exactly one
-- of the 226 tables in the schema, pc_job_ctl. Running this as `mgdb` returns
--     ERROR:  permission denied for table pc_blast_ctl
--
-- APPLY TO ALL THREE DATABASE HOSTS — each carries a byte-identical copy of the
-- defect, and the dev vhosts do not share one database:
--   maizegdb-core3.usda.iastate.edu     (claude)
--   maizegdb-core2.usda.iastate.edu     (codex, john, redesign)
--   curation-tools-dev.usda.iastate.edu (chi, gamma)  <-- the curation source;
--        fixing this one is what stops the damage returning at the next reload.
-- All are database `planter`.
--
-- Note this bypasses the audit trail the curation UI writes for PC_BLAST_CTL
-- (lib_curation_db.php:3093, captureParentChanges). If a curator can reach these
-- rows through the curation interface, that is the better route.
--
-- ---------------------------------------------------------------------------
-- WHAT IS WRONG — one bad find/replace damaged TWO columns, differently
-- ---------------------------------------------------------------------------
-- db_name (6 rows): a hyphen was DOUBLED — "Td-KS_B6_1--REFERENCE-…".
--   BLAST_run.php builds its -db argument as <BLAST_dbs>/<db_path>/<db_name>, so
--   these six jobs exit 3 and leave a zero-byte .bla, which the results poller
--   reads as "still running" — the job hangs in the browser forever.
--   The correction is exactly replace(db_name,'--','-'). Verified byte-for-byte:
--   md5(replace(db_name,'--','-')) equals md5 of the on-disk basename for all six.
--   db_path is already correct and needs no change.
--
-- display_info (4 of the same 6 rows): characters were DELETED instead —
--   "Td-KS_B6_1REFERENCE-…" (hyphen gone) on the two CDS rows, and
--   "Td-KS_B6_1-ERENCE-…"  ("REF" gone) on the two genomic rows. The two protein
--   rows escaped and show the correct "Td-KS_B6_1-REFERENCE-…", which is what the
--   repaired text is modelled on. This column is user-visible in the legacy
--   sequence-search interface (popcorn/search/sequence_search/showallblast.php:33,
--   showmore.php:43, blastsearch.js:281) as well as in the curation UI. Exactly
--   these 4 rows are affected table-wide.
--
-- target_type (1 row): 10741991 reads "Gene model protein -haplotype a", missing
--   the space its five siblings have. Cosmetic, but it is the label shown on the
--   BLAST form's dataset chips.
--
-- NOT AFFECTED, checked: db_path, name, short_name, type, assembly_name are all
-- correct. The two sibling assembly rows (10741988 haplotype a, 10741992
-- haplotype b) point at chrs/ and are undamaged.

\set ON_ERROR_STOP on
BEGIN;

-- Guard 1: refuse to run unless exactly the six expected rows are in the bad state.
DO $$
DECLARE n int;
BEGIN
  SELECT count(*) INTO n FROM mgdb.pc_blast_ctl
   WHERE id IN (10741989,10741990,10741991,10741993,10741994,10741995)
     AND db_name LIKE '%--%';
  IF n <> 6 THEN
    RAISE EXCEPTION 'expected 6 rows needing the db_name fix, found % — stopping', n;
  END IF;
END $$;

-- Guard 2: refuse to create a duplicate db_name. getBLASTinfoFromDBname does a
-- first-row-wins reverse lookup on this column, so a collision would silently
-- mislabel results. (Table-wide there are currently zero duplicate db_name values.)
DO $$
DECLARE n int;
BEGIN
  SELECT count(*) INTO n FROM mgdb.pc_blast_ctl
   WHERE db_name IN (SELECT replace(db_name,'--','-') FROM mgdb.pc_blast_ctl
                      WHERE id IN (10741989,10741990,10741991,10741993,10741994,10741995));
  IF n <> 0 THEN
    RAISE EXCEPTION 'corrected names would collide with % existing row(s) — stopping', n;
  END IF;
END $$;

-- 1. db_name — the fix that makes BLAST work.
UPDATE mgdb.pc_blast_ctl
   SET db_name = replace(db_name, '--', '-')
 WHERE id IN (10741989,10741990,10741991,10741993,10741994,10741995);
-- expect: UPDATE 6

-- 2. display_info — the user-visible text damaged by the same edit.
UPDATE mgdb.pc_blast_ctl SET display_info =
  'CDS sequences for the Td-KS_B6_1-REFERENCE-PanAnd-2.0a assembly annotation Td00002bc.1'
 WHERE id = 10741989;
UPDATE mgdb.pc_blast_ctl SET display_info =
  'Genomic sequences for the Td-KS_B6_1-REFERENCE-PanAnd-2.0a assembly annotation Td00002bc.1'
 WHERE id = 10741990;
UPDATE mgdb.pc_blast_ctl SET display_info =
  'CDS sequences for the Td-KS_B6_1-REFERENCE-PanAnd-2.0b assembly annotation Td00002bc.1'
 WHERE id = 10741993;
UPDATE mgdb.pc_blast_ctl SET display_info =
  'Genomic sequences for the Td-KS_B6_1-REFERENCE-PanAnd-2.0b assembly annotation Td00002bc.1'
 WHERE id = 10741994;
-- expect: UPDATE 1 each

-- 3. target_type — restore the missing space on the one odd row.
UPDATE mgdb.pc_blast_ctl SET target_type = 'Gene model protein - haplotype a'
 WHERE id = 10741991 AND target_type = 'Gene model protein -haplotype a';
-- expect: UPDATE 1

-- Verify before committing.
SELECT id, db_name, length(db_name) AS len, target_type, display_info
  FROM mgdb.pc_blast_ctl
 WHERE id IN (10741989,10741990,10741991,10741993,10741994,10741995)
 ORDER BY id;
-- expect db_name, in id order:
--   10741989 Td-KS_B6_1-REFERENCE-PanAnd-2.0a_Td00002bc.1.cds      48
--   10741990 Td-KS_B6_1-REFERENCE-PanAnd-2.0a_Td00002bc.1.gene     49
--   10741991 Td-KS_B6_1-REFERENCE-PanAnd-2.0a_Td00002bc.1.protein  52
--   10741993 Td-KS_B6_1-REFERENCE-PanAnd-2.0b_Td00002bc.1.cds      48
--   10741994 Td-KS_B6_1-REFERENCE-PanAnd-2.0b_Td00002bc.1.gene     49
--   10741995 Td-KS_B6_1-REFERENCE-PanAnd-2.0b_Td00002bc.1.protein  52
-- and every display_info reading "…for the Td-KS_B6_1-REFERENCE-PanAnd-2.0{a,b} assembly…"

COMMIT;

-- After committing, confirm from the web tier that all six now resolve on disk and
-- that a real job returns results — an HTTP 200 on the form proves nothing here.

-- ---------------------------------------------------------------------------
-- ROLLBACK — restores the exact values captured 2026-09-03
-- ---------------------------------------------------------------------------
-- BEGIN;
-- UPDATE mgdb.pc_blast_ctl SET db_name='Td-KS_B6_1--REFERENCE-PanAnd-2.0a_Td00002bc.1.cds',
--   display_info='CDS sequences for the Td-KS_B6_1REFERENCE-PanAnd-2.0a assembly annotation Td00002bc.1'      WHERE id=10741989;
-- UPDATE mgdb.pc_blast_ctl SET db_name='Td-KS_B6_1--REFERENCE-PanAnd-2.0a_Td00002bc.1.gene',
--   display_info='Genomic sequences for the Td-KS_B6_1-ERENCE-PanAnd-2.0a assembly annotation Td00002bc.1'    WHERE id=10741990;
-- UPDATE mgdb.pc_blast_ctl SET db_name='Td-KS_B6_1--REFERENCE-PanAnd-2.0a_Td00002bc.1.protein',
--   target_type='Gene model protein -haplotype a'                                                            WHERE id=10741991;
-- UPDATE mgdb.pc_blast_ctl SET db_name='Td-KS_B6_1--REFERENCE-PanAnd-2.0b_Td00002bc.1.cds',
--   display_info='CDS sequences for the Td-KS_B6_1REFERENCE-PanAnd-2.0b assembly annotation Td00002bc.1'      WHERE id=10741993;
-- UPDATE mgdb.pc_blast_ctl SET db_name='Td-KS_B6_1--REFERENCE-PanAnd-2.0b_Td00002bc.1.gene',
--   display_info='Genomic sequences for the Td-KS_B6_1-ERENCE-PanAnd-2.0b assembly annotation Td00002bc.1'    WHERE id=10741994;
-- UPDATE mgdb.pc_blast_ctl SET db_name='Td-KS_B6_1--REFERENCE-PanAnd-2.0b_Td00002bc.1.protein'               WHERE id=10741995;
-- COMMIT;
