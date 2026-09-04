# Overgo record page — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/overgo?id={id}`. The search page's originals are in
`legacy/overgo/`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `overgo_data.php` | The Ajax endpoint, 25 KB. Six `?type=` sections: Overview, Annotations, Related Data, Detected Loci, Map Coordinates, Sequence Match. | Yes, no longer called from this page. |
| `overgo.php` | The record sub-controller: two `replace()` calls naming the record type. | Yes, no longer reached. |
| `overgo_functions.php` | `check_id()`, `get_nav_array()`, `get_section_array()`. | Yes, and **still needed**: `data_center.php` requires those three functions for any data centre record page. |

## An overgo is a probe

`CL0_-2_ov` is `mgdb.probe` id 389357 with `type = 393660`, "Unigene-Overgo".
The collection is that type plus 747274, "Overgo" — 10,644 and 2,786 rows, the
same pair the modern overgo search page already filters on. Both are the same
table, and the same record shape, the marker record page reads, so this page
shares `/api/v1/records/marker/{id}` (17 queries, about 70 ms), the element
ids, `js/mgdb-marker-record.js` and `css/mgdb-record.css`.

What is its own lives in `include/overgo_record_lib.php` and
`controllers/data_center/overgo_record_modern.php`: which types belong to the
collection, the framing, and a 404 that knows the collection is a subset of all
markers. Everything those two share with the SSR record page is in
`include/probe_collection_lib.php`, written when the second such page proved
the first was not a special case.

## Sequence Match is dead, and not ported

`show_sequence_match()` reads:

```sql
select distinct(a.seq_id), a.genbank_acc, a.seq_title, a.seq_type
from z_sequence a join id_seq b on a.seq_id = b.seq where b.id = $id
```

**`mgdb.z_sequence` has 0 rows.** 11,384 overgos carry 12,132 `id_seq` links
and not one of them resolves, so that section has been empty for every overgo —
and for every other record whose page runs the same join. The marker record
page's own "Sequences" block reaches `z_sequence` by a different path
(`ext_db_key.key = z_sequence.genbank_acc`) and is empty for the same reason.
Recorded rather than reimplemented.

## Every other legacy section is present

| Legacy | Modern |
| --- | --- |
| Overview | Overview |
| Detected Loci | Detected loci |
| Map Coordinates | Map positions |
| Related Data | Related records — primers, gel patterns, sequences, copies |
| Annotations | Annotations |
| Sequence Match | not ported; the table it reads is empty |
| — | Offsite resources, Metrics, Related resources, API |
