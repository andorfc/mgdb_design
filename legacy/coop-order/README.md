# Legacy /ordering/coop_order

The pre-redesign Maize Genetics Cooperation Stock Center request form, archived
2026-09-06 when `/ordering/coop_order` moved onto the design system.

- `controllers/ordering/coop_order.php` — the controller. It is **not** shadowed
  or overwritten: it stays on the server exactly as archived here and still
  answers every AJAX `action` the form posts back (`add-stock`, `check-stock`,
  `check-country`, `clear-order`, `get-list`, `remove-stock`, `get-comment`,
  `force-add-stock`, and `submit`). The modern page reuses these endpoints
  verbatim, so the basket store (`temp_dir/<stock_order_id>.order`) and the
  order email to `maize@uiuc.edu` are untouched.
- `templates/ordering/coop_order.bau`, `coop_order_completed.bau` — the legacy
  page and its completed screen. The modern controller loads its own templates
  instead; these are no longer reached for a page render but are left on the
  server for rollback.
- `js/coop_order.js`, `css/coop_order.css` — legacy assets. Still on the server;
  the modern page does not load them.

## Routing

A guard in `controllers/ordering.php` sends page renders (no `action` param),
including the `completed` confirmation, to `controllers/ordering/coop_order_modern.php`.
Requests that carry an `action` fall through to the legacy controller unchanged.

Rollback: delete the guard block in `controllers/ordering.php`. The legacy
`coop_order.bau` template then serves the route again. Nothing under
`controllers/ordering/coop_order.php` was modified.

## The country check was already broken

`findCountry()` runs `'X' = ANY(variations)` against `mgdb.country.variations`,
which is a `character varying` column holding a Postgres array *literal*
(`{Germany,Ger,DE,DEU}`), not a real array. Postgres rejects `ANY` over a
scalar, so the query errors and `make_query()` returns zero rows for every
country. The legacy `check-country` action therefore always answered empty, and
the page's "Unable to find country '…' — press OK" confirm fired for *every*
country entered. The modern page drops that live check (the endpoint is left in
place for the legacy controller) and keeps the client-side rule that a phone
number is required outside the USA. The fix, if the check is ever wanted, is
`'X' = ANY(variations::text[])`.
