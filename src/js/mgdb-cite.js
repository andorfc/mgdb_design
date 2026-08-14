/* How to cite MaizeGDB.

   Every citation is rendered server-side, so the full list is present and
   indexable before this runs. Filtering only hides and shows what is already
   there, and copying the primary citation is a convenience on top of text the
   reader could always select by hand. */

(function () {
  'use strict';

  function byId(id) { return document.getElementById(id); }

  /* Copy the primary citation, falling back to selecting it when the clipboard
     API is unavailable or refused (it needs a secure context and permission). */
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
        // Hide a year heading with nothing left under it, and a whole category
        // group when every entry in it is filtered out, so the page does not
        // leave empty headings behind.
        Array.prototype.forEach.call(groups, function (group) {
          var visibleInGroup = 0;

          Array.prototype.forEach.call(group.children, function (node) {
            if (!node.classList.contains('mgdb-cite-year')) { return; }
            var visibleUnderHeading = 0;
            var sibling = node.nextElementSibling;
            while (sibling && !sibling.classList.contains('mgdb-cite-year')) {
              if (sibling.classList.contains('mgdb-cite-entry') && !sibling.hidden) {
                visibleUnderHeading += 1;
              }
              sibling = sibling.nextElementSibling;
            }
            node.hidden = visibleUnderHeading === 0;
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
        // Delegate to the main reset so filterList's own state (query, active
        // chip, URL) is cleared rather than only the visible attributes.
        if (yearSelect) { yearSelect.value = ''; }
        if (reset) { reset.click(); }
        else { list.refresh(); }
      });
    }
  }

  function init() {
    initCopy();
    initFilters();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
