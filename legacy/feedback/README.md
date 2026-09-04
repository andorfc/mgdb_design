# Pre-redesign /feedback

Archived 2026-09-01, when `controllers/feedback.php` took the `/feedback`
route. Nothing was overwritten: `controller.php` checks
`controllers/<CONTROLLER>.php` before falling through to `redirect.php`, so the
new controller *shadows* these files and deleting it gives the route straight
back. The originals are still on the server, untouched.

| File here | Server path |
| --- | --- |
| `feedback.php` | `controllers/static/feedback.php` |
| `feedback-popup.bau` | `templates/static/feedback-popup.bau` |
| `feedback.bau` | `templates/static/feedback.bau` |
| `feedback-error.bau` | `templates/static/feedback-error.bau` |
| `megamenu-feedback.bau` | `templates/home/megamenu/feedback.bau` — the *legacy* megamenu's link, loaded by `feedback-popup.bau`. Left in place; the legacy shell still renders it. |

## What it did

Two things, in one file, chosen by whether the request carried POST fields:

- **With fields** — mailed the message with `send_email()` to
  `carson.andorf@gmail.com` and `portwoodii@gmail.com` (or to a `sendto`
  address passed in the request), copied the sender, and rendered a thank-you
  page. It gated on an "anti-turing-test" whose answer was the literal string
  `18`, submitted alongside the message.
- **Without fields** — rendered a popup version of the form.

## It was already broken

The no-fields branch is what `/feedback` served, and it raised a Bauplan error
rather than a page. `feedback-popup.bau` never declares `subject`, `sendto` or
`instructions`, and `Nary::get()` throws on a missing identifier:

```
$ cd /var/www/claude/html && php -r '$_SERVER["REQUEST_METHOD"]="GET"; include "./controllers/static/feedback.php";'
Bauplan Error: No such identifier "subject" in call to get()
In file templates/static/feedback-popup.bau
```

The POST branch could not have worked either: it calls `$mgdb->get(...)` four
times and `$mgdb` is never assigned in the file, so a submission would have
mailed the message and then fatalled before rendering the confirmation.

`tools/redesign_status.py` never caught this because `/feedback` sends mail and
is therefore on the never-probe list — it was classified from source, as
"Legacy", which was true but not the whole story.

The route also had two other problems worth recording, both fixed by the
replacement rather than by choice:

- it mailed two personal Gmail addresses, hard-coded, so feedback left no trace
  in any queue the team shares;
- `sendto` came straight from the request, so the page would mail an arbitrary
  address given to it — an open relay for anyone who found the form.

## Rollback

Delete `controllers/feedback.php` from the web root. `/feedback` returns to
`controllers/static/feedback.php` and to the Bauplan error above.
