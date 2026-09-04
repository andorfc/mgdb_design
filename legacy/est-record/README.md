# EST record page — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/est?id={id}`. The search page's originals are in `legacy/est/`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `est_data.php` | The Ajax endpoint, 26 KB. Six `?type=` sections: Overview, Annotations, Related Data, Detected Loci, Map Coordinates, Sequence Match. | Yes, no longer called from this page. |
| `est.php` | The record sub-controller. | Yes, no longer reached. |
| `est_functions.php` | `check_id()`, `get_nav_array()`, `get_section_array()`. | Yes, and **still needed**: `data_center.php` requires those three functions for any data centre record page. |

## An EST is a probe

`p-bcd98` is `mgdb.probe` id 110916 with `type = 34`, "cDNA - EST" — 59,308 of
them, the same type the modern EST search page filters on. It is the third
probe collection on the record shell, after SSR and overgo, and the third to
share `/api/v1/records/marker/{id}` (17 queries, about 70 ms), the element ids,
`js/mgdb-marker-record.js` and `css/mgdb-record.css`.

`include/est_record_lib.php` is twenty lines: the type id, and four wrappers
over `include/probe_collection_lib.php`.

## Sequence Match is dead, and not ported

Same as the overgo page: `show_sequence_match()` joins `z_sequence` to
`id_seq`, and **`mgdb.z_sequence` has 0 rows**. The section has been empty for
every EST, as it has for every overgo.

## Every other legacy section is present

| Legacy | Modern |
| --- | --- |
| Overview | Overview |
| Detected Loci | Detected loci |
| Map Coordinates | Map positions |
| Related Data | Related records |
| Annotations | Annotations |
| Sequence Match | not ported; the table it reads is empty |
| — | Offsite resources, Metrics, Related resources, API |
