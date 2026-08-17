# Protein structure data center — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/protein_structure`.

These files were taken from the **codex** instance (`/var/www/codex/html`), not
from `claude`. The protein structure page had been modernised there and never
carried across; the claude instance was still serving an older revision. The
copies here are the codex versions, because those are what this redesign
actually replaces.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `protein_structure_search.php` | Search sub-controller. 25 lines, most of it dead: it computed a `$source` and a `$params` string that nothing read, and its one meaningful line loading the body template was commented out. Its header comment still described "Marilyn Warburton's PAST shiny app", which the page had not been for years. | Yes on codex, but no longer reached once the guard in `controllers/data_center.php` is deployed. |
| `protein_structure_search.bau`, `protein_structure-content.bau` | The page body: a hero with three hand-typed counts, the complex search, two NGL viewer cards, and a Foldseek/FATCAT panel. | Yes, but no longer loaded. |
| `protein_structure.css`, `protein_structure.js` | Stylesheet and script. The script bound the NGL viewers and the complex search. | Yes, but no longer loaded. |
| `protein_structure_data.php` | `record_data` fragment. Returned an HTML blob for one structure, fetched by a jQuery `.ajax()` call and injected into the page. | Yes, but no longer called. |
| `protein_complex_api.php` | The JSON API behind the complex search. Replaced by `search/protein_structure/protein_structure_api.php`. | Yes, but no longer called. |

Nothing in this directory should be deleted from the server.

## What was wrong with it, for the record

Three things are worth keeping written down, because two of them are not
visible by reading the code and one of them looks like a feature.

**The typeahead re-read 13 MB per keystroke.** `protein_complex_api.php`'s
`suggest` action loaded `data/protein_complex/suggestions.json` — 73,408
entries — `json_decode`d it in full, and walked it linearly, on every request.
Measured on codex: 178–200 ms of server CPU per keystroke, repeated for every
user typing simultaneously. The replacement answers the same queries from a
prefix-sharded index in 0–1 ms and reads about 10 KB. See
`tools/protein_structure_index.php`, which also records why the obvious n-gram
index does *not* work on this corpus.

**The example buttons pointed at proteins that are not in the collection.**
The page offered `wx1` as a suggested search in two places. `wx1` has no entry
in the complex export — no symbol in the corpus even begins with `wx`. The old
substring matcher answered it with `A0A804MWX1`, an unrelated magnesium
transporter that merely contains the letters `wx1`, and presented it as the
match. Prefix matching in the replacement returns nothing for that string, and
the lookup path resolves `wx1` through the gene database to
`Zm00001eb378140` — which does have five monomer models — so the page now
answers the question that was being asked.

**The three headline counts were hand-typed into the template.** `39,299`,
`71,725` and `8 proteomes` were literals in `protein_structure-content.bau`,
maintained by editing HTML. The complex summary strip below them carried a
different set of numbers from a different date. The replacement takes every
count from `data/protein_structure/manifest.json`, written by the same tool
that builds the index the search reads, so the header and the search cannot
disagree.

## Not carried over

The `record_data/protein_structure_data.php` round trip is gone entirely. It
returned rendered HTML — viewer shell, overview table and a JBrowse iframe — for
the page to inject, which meant every structure change was a full page-fragment
request and the markup lived in a `record_data` script rather than a template.
The replacement fetches JSON once per protein and renders every model in that
result client-side.

The JBrowse genome-context iframe is also not carried over. It was one iframe
per viewer card, loading on every page view whether or not anyone looked at it,
and it showed the gene's coordinates — which is what the gene record page it
now links to shows, in more detail and without the frame.
