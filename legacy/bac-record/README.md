# BAC record page — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/bac?id={id}`. The search page's originals are in `legacy/bac/`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `bac_data.php` | The Ajax endpoint, 55 KB and the largest of the collection endpoints. Eight `?type=` sections. | Yes, no longer called from this page. |
| `bac.php` | The record sub-controller. | Yes, no longer reached. |
| `bac_functions.php` | `check_id()`, `get_nav_array()`, `get_section_array()`. | Yes, and **still needed**: `data_center.php` requires those three functions for any data centre record page. |

## A BAC is a probe

`b0002A02` is `mgdb.probe` id 320812 with `type = 171715`, "BAC clone" —
430,550 of them, the largest of the four probe collections. It shares
`/api/v1/records/marker/{id}`, the element ids, `js/mgdb-marker-record.js` and
`css/mgdb-record.css` with the marker, SSR, overgo and EST record pages.

Two things were added rather than assumed:

- **Related probes** is on the marker resource now. It was the one section of
  the legacy BAC page that nothing else covered — "This BAC is detected by
  overgo X" — and the relation is not particular to BACs, so every marker
  record gets it. Each row routes to whichever collection owns the related
  probe, which the legacy page did by hand in a chain of type-id comparisons.
- **Resolution by GenBank accession.** 303,536 of the 430,550 BACs carry one,
  and it is an `ext_db_key` row that the name and synonym arms do not reach.
  The legacy page's own documented test URL was `/data_center/bac/AC205396`,
  and it threw a PHP fatal there — `check_id()` returned false and the caller
  ran `array_key_exists()` on it. That URL now resolves, to `c0040M18`.

## Four of the eight sections cannot produce content

Checked before deciding what to port, not after:

| Section | Why it is empty |
| --- | --- |
| Sequence | `getBACrecs()` reads `mgdb.z_sequence` (**0 rows**) and falls back to `mgdb.zb_chr_v2_clone` (**0 rows**). Every BAC gets "No sequence found", despite 89,385 `id_seq` links from BACs. |
| Genome Browser | Depends on the same `getBACrecs()`, and is keyed to B73 RefGen v1, v2 and v3. Every BAC gets "Unable to place this BAC on a genome browser." |
| Alignment | A `file_exists()` check against `images/BAC_alignments`, **which does not exist on the server**. Every BAC gets the "not part of the minimum tiling path" message. |
| Issues | A live Jira call to `collect.maizegdb.org/rest/api/2`, which **returns HTTP 502**. Related to AD-031. |

None is ported. The first two are the same empty `z_sequence` that makes the
overgo and EST pages' Sequence Match sections dead, and that makes
`counts.sequences` zero on every marker record.

## The other four are present

| Legacy | Modern |
| --- | --- |
| Description | Overview |
| Related Information | Related records — Related probes |
| Curated Links to Other Databases | Offsite resources |
| Annotations | Annotations |
| — | Detected loci, Map positions, Metrics, Related resources, API |
