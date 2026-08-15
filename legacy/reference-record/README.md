# Reference record page — pre-redesign originals

Archived per the standing replace-a-page policy in the repository README, for
`/data_center/reference?id={id}`.

| File | What it was | Still live on the server? |
| --- | --- | --- |
| `reference_data.php` | The Ajax endpoint. Five `?type=` modes returning HTML fragments. Replaced by `/api/v1/records/reference/{id}`. | Yes, no longer called from this page. |
| `reference_sections.bau` | The Bauplan template it filled in. | Yes, no longer loaded. |
| `reference.bau`, `reference-right.bau` | The record page body and right column. | Yes, no longer loaded. |
| `reference_functions.php` | `check_id()`, `get_nav_array()`, `get_section_array()`. | Yes, and **still needed**: `data_center.php` requires those three for any data centre record page. |
| `reference.php` | The record sub-controller. | Yes, no longer reached. |

Nothing here should be deleted from the server.

## What the five calls became

`reference_data.php` was called once each for `top`, `overview`, `annotations`,
`describes`, and `offsite_resources`. Between them:

- `read_authors()` ran one query per author to fetch each name. A twenty-author
  paper cost twenty-one round trips for the byline alone.
- `show_describes()` ran a separate query per described record type, and more
  per row for several of them.
- No author had a paper count, no locus carried its gene models, and every
  external link rendered as "Read the complete article" regardless of where it
  actually went.

The replacement is one request and nine queries.

## The annotations section

`reference_data.php?type=annotations` rendered per-viewer user annotations keyed
on the login cookie. The API is public and cacheable, so it does not carry them
and the modern page does not show them.
