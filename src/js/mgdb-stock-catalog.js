/* mgdb-stock-catalog.js -- /stock_catalog
 *
 * The whole catalog is in the document, so filtering is a local pass rather
 * than a request. The previous page fetched each of its 25 categories over its
 * own Ajax call and had no filter at all.
 *
 * The largest catalog holds 7,469 entries, which is enough that the obvious
 * implementation is too slow to use. Three things keep a keystroke cheap:
 *
 *   - every DOM handle and every entry's search text is read once, at startup,
 *     and kept. Re-running `querySelectorAll` per group per keystroke, and
 *     reading `textContent` per entry, is most of what makes a filter like
 *     this feel broken on a long list.
 *   - **the filter never touches a non-match.** Hiding each non-matching entry
 *     meant writing to all 7,469 on every keystroke and again on clear, which
 *     measured around 900 ms a pass. The container carries an `is-filtering`
 *     class and only matches carry `is-match`, so a filter costs one class per
 *     match -- usually tens -- and clearing costs one class on the container.
 *   - the input is debounced, so a burst of typing is one pass.
 */
(function () {
  'use strict';

  /* Included from <head>, so nothing here can query the document until it has
     been parsed. */
  function init() {
    var input = document.getElementById('sc-filter');
    var root = document.getElementById('sc-groups');
    if (!input || !root) {
      return;
    }

    var status = document.getElementById('sc-status');
    var none = document.getElementById('sc-none');

    var jumpFor = {};
    Array.prototype.forEach.call(document.querySelectorAll('.sc-jump'), function (a) {
      var id = (a.getAttribute('href') || '').replace(/^#/, '');
      if (id) {
        jumpFor[id] = { link: a, badge: a.querySelector('span') };
      }
    });

    /* One walk of the document at startup: the groups, their entries, each
       entry's lower-cased name, and the furniture each group owns. */
    var total = 0;
    var groups = Array.prototype.map.call(root.querySelectorAll('.sc-group'), function (group) {
      var entries = Array.prototype.slice.call(group.querySelectorAll('.sc-entry'));
      var text = entries.map(function (entry) {
        var name = entry.querySelector('.sc-name');
        return (name ? name.textContent : entry.textContent).toLowerCase();
      });
      total += entries.length;
      var count = group.querySelector('.sc-count');
      var jump = jumpFor[group.id];
      return {
        el: group,
        entries: entries,
        text: text,
        full: entries.length,
        count: count,
        jump: jump ? jump.link : null,
        badge: jump ? jump.badge : null
      };
    });

    function plural(n) {
      return n.toLocaleString() + ' ' + (n === 1 ? 'stock' : 'stocks');
    }

    /* Only the matches are touched. `matched` remembers what carries the
       class so the next pass can take it off again without walking the
       catalog, and clearing the field is one class on the container. */
    var matched = [];
    var markedGroups = [];

    function apply(term) {
      var q = term.trim().toLowerCase();
      var i, j, group;

      for (i = 0; i < matched.length; i++) {
        matched[i].classList.remove('is-match');
      }
      for (i = 0; i < markedGroups.length; i++) {
        markedGroups[i].classList.remove('has-match');
      }
      matched = [];
      markedGroups = [];

      if (q === '') {
        root.classList.remove('is-filtering');
        for (i = 0; i < groups.length; i++) {
          group = groups[i];
          if (group.count) {
            group.count.textContent = group.full.toLocaleString();
          }
          if (group.jump) {
            group.jump.hidden = false;
            if (group.badge) {
              group.badge.textContent = group.full.toLocaleString();
            }
          }
        }
        if (none) {
          none.hidden = true;
        }
        if (status) {
          status.textContent = 'Showing all ' + plural(total);
        }
        return;
      }

      var shown = 0;
      for (i = 0; i < groups.length; i++) {
        group = groups[i];
        var kept = 0;
        for (j = 0; j < group.entries.length; j++) {
          if (group.text[j].indexOf(q) !== -1) {
            group.entries[j].classList.add('is-match');
            matched.push(group.entries[j]);
            kept++;
          }
        }
        shown += kept;
        if (kept) {
          group.el.classList.add('has-match');
          markedGroups.push(group.el);
        }
        if (group.count) {
          group.count.textContent = kept.toLocaleString();
        }
        if (group.jump) {
          group.jump.hidden = kept === 0;
          if (group.badge) {
            group.badge.textContent = kept.toLocaleString();
          }
        }
      }

      root.classList.add('is-filtering');
      if (none) {
        none.hidden = shown !== 0;
      }
      if (status) {
        status.textContent = 'Showing ' + shown.toLocaleString() + ' of ' + plural(total);
      }
    }

    var timer = null;
    function schedule() {
      if (timer) {
        window.clearTimeout(timer);
      }
      timer = window.setTimeout(function () {
        apply(input.value);
      }, 120);
    }

    input.addEventListener('input', schedule);
    /* A search input's clear button fires `search`, not `input`, in Safari. */
    input.addEventListener('search', function () {
      apply(input.value);
    });

    if (window.MGDB && typeof window.MGDB.sectionTabs === 'function') {
      window.MGDB.sectionTabs({ watch: '#sc-groups' });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
