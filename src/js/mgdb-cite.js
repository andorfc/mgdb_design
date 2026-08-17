/* How to cite MaizeGDB.

   Citations are rendered server-side in full semantic card markup matching
   the Reference Data Center.
   This script handles:
     1. Primary citation clipboard copy
     2. Copy DOI / Copy PMID inline button actions
     3. List / Card view toggle with localStorage persistence
     4. Real-time client-side search and year/category filtering
*/

(function () {
  'use strict';

  var STORAGE_KEY = 'mgdb-cite-journal-view';

  function byId(id) { return document.getElementById(id); }

  /* ── Copy the primary citation ──────────────────────────────────────────── */

  function initCopy() {
    var button = byId('cite-copy');
    var status = byId('cite-copy-status');
    if (!button) { return; }

    var source = byId(button.getAttribute('data-copy-target'));
    if (!source) { return; }

    function announce(message) {
      if (status) { status.textContent = message; }
      var original = button.textContent;
      button.textContent = message;
      window.setTimeout(function () { button.textContent = original; }, 2000);
    }

    function selectSource() {
      var range = document.createRange();
      range.selectNodeContents(source);
      var selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
    }

    button.addEventListener('click', function () {
      var text = (source.textContent || '').replace(/\s+/g, ' ').trim();

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
          announce('Citation copied');
        }).catch(function () {
          selectSource();
          announce('Press Ctrl or Cmd + C to copy');
        });
        return;
      }

      selectSource();
      announce('Press Ctrl or Cmd + C to copy');
    });
  }

  /* ── Copy DOI / PMID button helper ──────────────────────────────────────── */

  function initCopyButtons() {
    Array.prototype.forEach.call(document.querySelectorAll('.reference-copy-id'), function (btn) {
      btn.addEventListener('click', function () {
        var val = btn.getAttribute('data-copy-value');
        if (!val) { return; }
        var original = btn.textContent;
        function finish(ok) {
          btn.textContent = ok ? 'Copied!' : 'Press Cmd+C';
          window.setTimeout(function () { btn.textContent = original; }, 1800);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(val).then(function () { finish(true); }).catch(function () { finish(false); });
        } else {
          finish(false);
        }
      });
    });
  }

  /* ── List / Cards View Toggle ───────────────────────────────────────────── */

  function initViewToggle() {
    var group = document.querySelector('.mgdb-cite-group[data-group="journal"]');
    if (!group) { return; }

    var buttons = group.querySelectorAll('.cite-view-btn[data-view]');
    if (!buttons.length) { return; }

    var savedView = 'list';
    try { savedView = localStorage.getItem(STORAGE_KEY) || 'list'; } catch (e) {}

    function applyView(view) {
      group.classList.remove('cite-view-list', 'cite-view-card');
      group.classList.add('cite-view-' + view);
      Array.prototype.forEach.call(buttons, function (btn) {
        btn.setAttribute('aria-pressed', btn.getAttribute('data-view') === view ? 'true' : 'false');
      });
      try { localStorage.setItem(STORAGE_KEY, view); } catch (e) {}
    }

    Array.prototype.forEach.call(buttons, function (btn) {
      btn.addEventListener('click', function () {
        applyView(btn.getAttribute('data-view'));
      });
    });

    applyView(savedView);
  }

  /* ── Filtering and search ────────────────────────────────────────────────── */

  function initFilters() {
    if (!window.MGDB) { return; }

    var entries = document.querySelectorAll('#cite-list .mgdb-cite-entry');
    if (!entries.length) { return; }

    var yearSelect = byId('cite-year');
    var groups = document.querySelectorAll('#cite-list .mgdb-cite-group');

    var list = window.MGDB.filterList({
      items: entries,
      input: byId('cite-query'),
      chips: document.querySelectorAll('.mgdb-chip[data-filter]'),
      count: byId('cite-count'),
      empty: byId('cite-empty'),
      reset: byId('cite-reset'),
      noun: 'publications',
      urlKeys: { query: 'q', filter: 'type' },
      filterOn: function (entry, value) {
        if (value !== 'all' && entry.getAttribute('data-filter') !== value) { return false; }
        var year = yearSelect ? yearSelect.value : '';
        if (year && entry.getAttribute('data-year') !== year) { return false; }
        return true;
      },
      onChange: function () {
        Array.prototype.forEach.call(groups, function (group) {
          var visibleInGroup = 0;

          // Year headings visibility
          var yearHeadings = group.querySelectorAll('.mgdb-cite-year[data-year-heading]');
          Array.prototype.forEach.call(yearHeadings, function (heading) {
            var headingYear = heading.getAttribute('data-year-heading');
            var visibleForYear = group.querySelectorAll('.mgdb-cite-entry[data-year="' + headingYear + '"]:not([hidden])').length;
            heading.hidden = visibleForYear === 0;
          });

          Array.prototype.forEach.call(group.querySelectorAll('.mgdb-cite-entry'), function (entry) {
            if (!entry.hidden) { visibleInGroup += 1; }
          });

          group.hidden = visibleInGroup === 0;
        });
      }
    });

    if (yearSelect) {
      yearSelect.addEventListener('change', function () { list.refresh(); });
    }

    var reset = byId('cite-reset');
    if (reset) {
      reset.addEventListener('click', function () {
        if (yearSelect) { yearSelect.value = ''; }
        list.refresh();
      });
    }

    var emptyReset = byId('cite-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        if (yearSelect) { yearSelect.value = ''; }
        if (reset) { reset.click(); }
        else { list.refresh(); }
      });
    }
  }

  /* ── Bootstrap ──────────────────────────────────────────────────────────── */

  function init() {
    initCopy();
    initCopyButtons();
    initViewToggle();
    initFilters();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
