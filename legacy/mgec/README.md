# /mgec, before the consolidation

The twenty-two files that served the MGEC record on the development instance on
2026-09-05, taken from the server before `controllers/mgec.php` and the
`PAGE == 'mgec'` hook in `controllers/community.php` intercepted the routes.

    controllers/community/mgec.php     the dispatcher, four lines of switch
    templates/community/mgec.bau       the frame
    templates/community/mgec-content.bau      mission statement, document archive
    templates/community/mgec-origins.bau      the 1929-2000 timeline
    templates/community/mgec-procedures.bau   March 2004 procedures, 2018/2019 motions
    templates/community/mgec-committees.bau   21 terms of rosters
    templates/community/mgec-activities<year>.bau   16 activity reports

`mgec.php` switched on a path segment and loaded `mgec-<subpage>.bau`, so each
of the twenty sub-pages was its own URL. Nothing linked the activity years to
each other: reading the committee's history end to end meant twenty
navigations, and several of those pages held a single sentence and a link.

Their content is transcribed into `src/data/mgec.json`, which `/mgec` now
renders as one page, and every old route 301s to the section that replaced it.
The transcription was checked against these files; what was verified, and the
six links that no longer resolve, are recorded in that file's header comment.

None of these files were modified. Rollback is deleting
`controllers/mgec.php` and the `PAGE == 'mgec'` block in
`controllers/community.php`, at which point they answer every route again.
