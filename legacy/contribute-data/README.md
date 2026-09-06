# /contribute_data, before the redesign

The pre-redesign page as it stood on 2026-09-06, kept so the original wording
and link targets stay readable.

    contribute-data.bau          the table-layout wrapper, with the green_curve
                                 header gif and the hand-built breadcrumb
    contribute-data-top.bau      acceptance criteria, community curator note,
                                 and the jump list
    contribute-data-bottom.bau   the eleven data-type sections
    contribute-data-faqs.bau     six FAQs
    contribute_data.php          the controller that loaded them
    contribute_data.css          47 lines of page styling

Replaced by controllers/contribute_data.php (top-level route interceptor),
controllers/community/contribute_data_modern.php,
templates/static/mgdb_contribute_data.bau, css/mgdb-contribute-data.css and
js/mgdb-contribute-data.js.

`controllers/community/contribute_data.php` is left in place on the server, so
/community/contribute_data still serves this original. Rollback is deleting the
top-level controller.

Two faults in the original that the rebuild fixes rather than reproduces:

  * The jump list linked to `#faqs`, which no anchor defined -- a dead link for
    as long as the page has existed. The FAQs are now a tab.
  * The jump list spelled it "Metabalomics"; the section heading it points at
    spelled it correctly. The rebuild uses Metabolomics in both.

Every in-page anchor the original defined is preserved -- function, genomic,
annotated, nucleotide, protein, sra, geo, snps, genopheno, other, maps, and
contribute_data_faq1..6 -- because other pages link into them, and the six FAQ
anchors now open the collapsed row they point at.
