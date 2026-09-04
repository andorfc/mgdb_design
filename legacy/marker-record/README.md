# Marker record page — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/marker?id={id}`. The route calls itself marker, the legacy page
called itself Probe, and the table is `mgdb.probe`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `marker_data.php` | The Ajax endpoint. Six `?type=` modes — `top`, `overview`, `annotations`, `related_data`, `detected_loci`, `map_coordinates`, `sequence_match` — each returning a fragment of HTML. Replaced by `/api/v1/records/marker/{id}`. | Yes, no longer called from this page. |
| `marker_sections.bau` | The Bauplan template `marker_data.php` filled in. | Yes, no longer loaded. |
| `marker_functions.php` | `check_id()`, `get_nav_array()`, `get_section_array()`. | Yes, and **still needed**: `data_center.php` requires those three functions for any data centre record page. |
| `marker.php` | The record sub-controller: two `replace()` calls. | Yes, no longer reached. |

## What the six calls became

They ran a query per row for most of what they showed: two extra queries for
every locus the probe detected, and two more for every external database key.
The replacement is one request and seventeen parameterized queries, answering
in about 75 ms.

## Two things in the legacy code that could never have worked

- **`probe_contains_probe` does not exist.** `read_contains()` selects from it
  in two places to build a "contained in / contains" list. The table is not in
  the schema, so those queries returned nothing and the section was always
  empty. Not ported.
- **`show_detected_loci()` never advanced its counter.** `$count` is declared
  and incremented nowhere inside the loop, so every locus overwrote
  `$loci_results[0]` and the page showed one locus however many the probe
  detected. `p-umc10` detects four. The modern page lists all four.
