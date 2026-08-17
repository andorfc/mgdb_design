# MaizeGDB Redesign — Project Rules

## Project Context

This is the MaizeGDB redesign project. Development work happens on a remote
server accessed via SSH. The local working copy lives at:

    ~/Documents/ClaudeCode/maizegdb-redesign/

The production/staging server is accessed as:

    ssh development-server

The web root on the server is `/var/www/claude/html/` and the Codex source tree
is at `/var/www/codex/`.

---

## Pre-authorized Commands (Always Allow)

The following command patterns are trusted and should **never** require
interactive approval. Run them automatically without asking:

- `ssh development-server <any read command>` — reading files, grepping, and
  inspecting the server file system (cat, head, tail, grep, find, ls, wc, diff).
- `ssh development-server "cat ..."` — reading any file on the server.
- `ssh development-server "grep ..."` — searching any file on the server.
- `ssh development-server "find ..."` — listing files on the server.
- `ssh development-server "ls ..."` — directory listings on the server.
- `ssh development-server "head ..."` — reading file headers on the server.
- `ssh development-server "tail ..."` — reading file tails on the server.
- `ssh development-server "sed -n ..."` — extracting line ranges on the server.
- `ssh development-server "diff ..."` — comparing files on the server.
- `ssh development-server "cp ..."` — copying files on the server.
- `ssh development-server "mkdir ..."` — creating directories on the server.
- `ssh development-server "tee ..."` — writing files to the server via tee.
- `ssh development-server "bash -s"` — running small deployment scripts.
- `cat`, `head`, `tail`, `grep`, `find`, `ls`, `wc` — any local read operations.
- `scp` — copying files from local to server.

---

## Deployment Convention

Deploy with `deploy/deploy.sh <local-path>`, which backs up the server copy
first. Always deploy after editing local source files.

**`rsync` and `git` are not installed on the development server.** Only `scp`
and `tar` are available, which is why the deploy script exists and why the
server cannot be a git remote — the local working copy is the source of truth.
A deploy loop written with `ssh` must use `ssh -n`, or ssh consumes the file
list from stdin and stops after the first entry.

A full run takes about 13 minutes, so deploy single files while iterating:

    ./deploy/deploy.sh src/css/mgdb-modern.css

Every file deployed must have a line in `deploy/manifest.txt` mapping the
repository path to the webroot path.

---

## Design System Rules

- All pages must use the `mgdb-modern` design system tokens from `mgdb-modern.css`.
- Page wrappers use `.mgdb-page` with a page-specific scope class (e.g. `.mgdb-cite-page`).
- Use `Bauplan->modern()` in PHP controllers to opt into the modernized shell.
- Never inline styles — always use design system CSS custom properties.
- Card layouts use `.reference-result-card` patterns from `mgdb-reference.css`.
- View toggles (card / list) follow the pattern established in the reference data center.

---

## Code Style

- PHP: follow existing controller patterns in `src/controllers/`.
- JS: vanilla ES5-compatible IIFE pattern, no bundlers.
- CSS: scoped under a page class (e.g. `.mgdb-cite-page`), variables from the
  design system only.
- Templates: `.bau` Bauplan templates in `src/templates/static/`.

---

## Traps that cost the most time

- **`.bau` templates escape literal parentheses as `\(` and `\)`.** An
  unescaped `)` ends the template block. The parser reports the *final* `)` as
  the error, so the line number points at the end of the file and is useless.
  This applies inside HTML comments too.
- **`Bauplan::includeScript()` emits into `<head>`,** so a page script runs
  while the document is still parsing. Guard every one with
  `document.readyState === 'loading'` or it silently does nothing.
- **Never copy `conf/mgdb.conf` or `conf/db.conf` into this repository.** They
  hold live database credentials and are in `.gitignore`.
- **The dev site sits behind Cloudflare,** so `curl` from the workstation gets a
  403 challenge. Verify over HTTP from the server instead, resolving to the
  host's own LAN address rather than 127.0.0.1.

`README.md` is the full reference for all of the above; read it first.

---

## Working alongside other agents

Claude Code, Codex, and Gemini agents share this one working copy and one
`main` branch, and their work interleaves in the same files.

- **`git pull` before starting and commit when a page is finished.** Work left
  uncommitted is invisible to the other agents and easy to overwrite.
- **Shared files to edit additively, never rewrite wholesale:**
  `deploy/manifest.txt` (append), `src/css/mgdb-modern.css` (the design
  system), `src/controllers/data_center.php` (the router guards), and
  `README.md`.
- **Do not commit another agent's half-finished page** to get a clean tree.
  Leave it and say so.
- `tools/redesign_status.py` measures the site by fetching every URL, so it is
  the honest check on whether everyone's pages are actually live.
