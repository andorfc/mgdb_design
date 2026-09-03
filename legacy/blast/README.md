# /BLAST, before the redesign

The pre-redesign BLAST front page, archived per the standing policy in the
repository README. Only the **front page** was modernized: the job submission,
execution and results path — `BLAST_run.php`, `BLAST_tasks.php`,
`BLAST_visual_alignment.php`, `BLAST.js`, and every `BLAST_results*.bau` — was
deliberately left alone and is not archived here because it was not touched.

| File | What changed |
| --- | --- |
| `BLAST.php` | The controller loaded `templates/maizegdb-main.bau`, the legacy main, for both branches. It now loads the modern main and the page wrapper **for the form branch only**; a request carrying `job_id` or `submit-form` still renders through the legacy template exactly as before. |
| `BLAST_form.php` | One line: it loaded `BLAST_form.bau` straight into `body`, and now loads `templates/static/mgdb_blast.bau` there and nests the form inside it. Every token assignment, the species query, `restoreSettings()` and `setDefaultTargets()` are unchanged. |
| `BLAST_form.bau` | Its own page furniture removed — the `green_curve_background` header bar and the fixed `width=980px` wrapper — because the page around it now supplies both. **No form control was touched**: every `id`, `name`, `value`, `onclick` and `$(token)` is byte-identical, which is what keeps `BLAST.js` working. |
| `BLAST.css` | Unchanged and still loaded; `css/mgdb-blast.css` sits on top of it. |

Rollback is copying these four files back over the deployed ones.
