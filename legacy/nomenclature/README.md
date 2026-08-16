# Maize genetics nomenclature — what could and could not be archived

For `/nomenclature` and `/community/nomenclature`.

This directory is **empty of code on purpose**, and that is the point worth
recording.

## The original controller is gone

`controllers/community/nomenclature.php` was modernized by **overwriting it on
the server**, not by shadowing it with a new file. It was never a
`deploy/manifest.txt` target, so `deploy/deploy.sh` had never taken a
pre-deploy snapshot of it, and no copy survives in `backups/`, in `reference/`,
or anywhere on the development instance. The pre-redesign controller for this
route cannot be recovered from here.

That is the same failure mode as the five data-center searches — work done
directly on the server rather than in this repository — with the one difference
that made it unrecoverable: those five were *shadowed* by new `*_modern.php`
files, so their originals were still on disk to archive. This one was replaced
in place.

## The content itself was never at risk

The standard is not in the controller. `templates/community/nomenclature.bau`
holds it — every guideline, update, committee member and appendix — and the
modern page nests that template unchanged:

```php
$body = $mgdb->get('body')->load('templates/community/mgdb_nomenclature.bau');
$body->get('full-standard')->load('templates/community/nomenclature.bau');
```

So the redesign is presentation only, and nothing in the standard was rewritten
or re-keyed.

## nomenclature.bau is deliberately not in the manifest

It is the curator-maintained standard itself, owned by `mgdbadmin` on the
server and updated by curators rather than by this redesign. Listing it in
`deploy/manifest.txt` would make this repository the source of truth for a
document it does not maintain, and the next deploy would quietly revert a
curator's edit. It stays server-owned.

## What is in the repository

| File | Where |
| --- | --- |
| The modern controller | `src/controllers/community/nomenclature.php` |
| Its body template | `src/templates/community/mgdb_nomenclature.bau` |
| Its stylesheet | `src/css/mgdb-nomenclature.css` |

The stylesheet deviates from the convention in the repository README: its rules
are scoped `.mgdb-modern .nomenclature-*` rather than under the page class
`.mgdb-nomenclature-page`. Nothing can leak onto a legacy page, since
`.mgdb-modern` only exists on modernized ones, but a second modern page using a
`nomenclature-` class would collide. Left as built rather than re-scoped, since
the page is live and working.
