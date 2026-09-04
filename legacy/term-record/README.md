# Term and trait record pages — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/term?id={id}` and `/data_center/trait?id={id}`.

| File | What it was |
| --- | --- |
| `term.php`, `term_functions.php`, `term_data.php`, `term_sections.bau` | The term record page: overview and annotations. |
| `trait.php`, `trait_functions.php`, `trait_data.php`, `trait_sections.bau` | The trait record page: overview, annotations, and QTL trait analyses. |
| `termdoclist.php`, `termdoclist_data.php` | A separate term-document list route, untouched by this work. |

Nothing here should be deleted from the server.

## Two pages, one table

Both read `mgdb.term` — 6,815 curated rows across **105 types**. They differ
only in which sections they draw: `/trait` shows phenotypes, QTL analyses and
trait values; `/term` shows related terms, external entries and images. The
modern page draws all of them and lets the data decide, so both routes now
render the same record and the route only chooses the noun in the title.

## The GWAS trait map was kept deliberately

`check_gwas_trait()` in `term_functions.php` is a 41-case switch mapping JBrowse
GWAS display names to term ids, with a comment explaining that adding real ids
to 52 GFF3 files was not worth it.

**It looks like a code smell and it is not.** These names are curated aliases,
not the term names. Replacing the switch with "turn underscores into spaces and
match the name" was tried and checked pair by pair against the database: it
reproduces only **6 of the 41**, and for several it resolves to the *wrong
record* — `Plant_height` is term 3097755, "plant height, PANZEA", while the name
rule lands on 64851; `Stalk_strength` is "rind puncture resistance PANZEA";
`Nodes_above_ear` is "node number, tassel to ear". The map is ported verbatim
into `include/term_record_lib.php`.

## The trait-values download was broken

The legacy trait page offers "Download all values for '<trait>' to a text file",
driven by `doTraitDownload()` against
`/search/traits_ibm_nam/traits_ibm_nam_adv_results.php`. That endpoint answers
with a PHP fatal:

```
Uncaught TypeError: fwrite(): Argument #1 ($stream) must be of type resource,
bool given in /var/www/claude/html/include/data_center_functions.php:247
```

It cannot open its output file, so the link has been dead for every one of the
**121 traits** that carry values. The modern page summarises the values instead
— count, stocks, range, mean, units — and links the IBM/NAM trait viewer and the
bulk download directory, both of which work. **The broken endpoint is not fixed
here; it is a separate route with its own callers.**

## Also not ported

- **User annotations.** A logged-in curator feature; the anonymous reader saw
  only an empty block.
- **`read_term_synonyms()`** in `trait_data.php` is commented out, replaced by
  the generic `getSynonyms()`.

## A route correction

The legacy trait page links a QTL experiment as `/data_center/qtl?id={id}`, and
that is right: these are `mgdb.qtl_exp` rows, and `/data_center/qtl_analysis?id=`
renders an all-but-empty page for them. The first draft of the modern resource
used the wrong one; the completeness comparison caught it.
