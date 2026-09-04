# SSR record page — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/ssr?id={id}`. The search page's originals are in `legacy/ssr/`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `ssr_data.php` | The Ajax endpoint, 34 KB. Its sections were Overview, Annotations, Related Data, Detected Loci and Map Coordinates. | Yes, no longer called from this page. |
| `ssr.php` | The record sub-controller: two `replace()` calls naming the record type. | Yes, no longer reached. |
| `ssr_functions.php` | `check_id()`, `get_nav_array()`, `get_section_array()`. | Yes, and **still needed**: `data_center.php` requires those three functions for any data centre record page. |

## An SSR is a probe

`p-umc1246`, this page's own example, is `mgdb.probe` id 242172 with
`type = 104436`, "PCR - SSR" — the same table and the same shape the marker
record page reads. So the modern SSR page is the marker record page: it shares
`/api/v1/records/marker/{id}` (17 queries, about 70 ms), the element ids,
`js/mgdb-marker-record.js` and `css/mgdb-record.css`. Only three things are its
own, in `include/ssr_record_lib.php` and
`controllers/data_center/ssr_record_modern.php`:

- resolving inside the SSR collection rather than all 1.4M probes,
- the framing — title, breadcrumb, related resources,
- a 404 whose first arm is the useful one: an identifier that names a real
  probe of another type is not a mistake, and the marker record page has it.
  `p-umc10` is a DNA probe, and the SSR 404 says so and links to it.

Duplicating the resource or the script would only have let the two drift apart.

## Every legacy section is present

| Legacy | Modern |
| --- | --- |
| Overview | Overview |
| Detected Loci | Detected loci |
| Map Coordinates | Map positions |
| Related Data | Related records — primers, gel patterns, sequences, copies |
| Annotations | Annotations |
| — | Offsite resources, Metrics, Related resources, API |
