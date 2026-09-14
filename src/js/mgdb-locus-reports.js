/* ==========================================================================
   Locus reports — /data_center/locus-reports
   --------------------------------------------------------------------------
   One filter over a list that is already in the page. 89 transgenes or 28 gene
   families is small enough to render server-side and hide client-side, so the
   page works with this file absent: every record is in the document and the
   filter field is the only thing that stops doing anything.

   The shared section-tab behaviour comes from js/mgdb-modern.js.
   ========================================================================== */

(function (window, document) {
  'use strict';

  function init() {
    var field = document.getElementById('lr-filter');
    var list = document.getElementById('lr-records-list');
    var count = document.getElementById('lr-count');
    var empty = document.getElementById('lr-empty');
    if (!field || !list) { return; }

    var records = Array.prototype.slice.call(list.querySelectorAll('.lr-record'));
    var total = records.length;

    /* data-search is the name and full name, lower cased, written by the
       controller -- matching against it rather than against textContent keeps
       a note that happens to mention another locus from pulling that record
       into the results. */
    function apply() {
      var term = field.value.trim().toLowerCase();
      var shown = 0;

      records.forEach(function (record) {
        var hit = term === '' ||
                  (record.getAttribute('data-search') || '').indexOf(term) !== -1;
        record.hidden = !hit;
        if (hit) { shown += 1; }
      });

      if (count) {
        count.textContent = term === ''
          ? total + ' records'
          : shown + ' of ' + total + ' records';
      }
      if (empty) { empty.hidden = shown !== 0; }
    }

    field.addEventListener('input', apply);

    /* A search input's clear button fires `search`, not `input`, in Safari. */
    field.addEventListener('search', apply);

    if (window.MGDB && window.MGDB.sectionTabs) {
      window.MGDB.sectionTabs({ watch: '#lr-records-list' });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}(window, document));
