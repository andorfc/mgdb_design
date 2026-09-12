# Legacy /ssr_protocols

The pre-redesign SSR PCR protocol page (Maize Mapping Project), archived
2026-09-06 when `/ssr_protocols` moved onto the design system.

- `controllers/static/ssr_protocols.php` — legacy controller, reached only via
  `redirect.php`. Untouched on the server; not shadowed or overwritten.
- `templates/static/ssr_protocols.bau` — legacy body (green_curve_background
  header, centercolumn_record wrapper) that nested the content partial.
- `templates/static/ssr_protocols-content.bau` — the protocol content in legacy
  markup (`<p id='bold_title'>` pseudo-headings, a layout table). Its prose is
  the source the modern `mgdb_ssr_protocols.bau` was transformed from, with the
  parentheses already Bauplan-escaped.
- `css/ssr_protocols.css` — legacy stylesheet.

## Routing

A top-level `controllers/ssr_protocols.php` shadows the route (controller.php
finds it before redirect.php, so no legacy chrome loads). Rollback: delete that
file and the legacy controller serves the route again through redirect.php.

## The broken trail links

The legacy trail read "Project Documentation & Protocols: Maize Mapping Project:
SSR Protocols" with links to `/doc` (retired) and `archive.maizegdb.org` (does
not resolve). Neither works, and the Maize Mapping Project has no live page, so
the modern page drops the trail, names the project in the hero without a link,
and points its breadcrumb at `/projects`.
