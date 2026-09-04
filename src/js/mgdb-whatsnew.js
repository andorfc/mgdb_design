/* News archive (/whatsnew).

   Every announcement is rendered server-side, so the archive is complete and
   indexable before this runs and the browser's own find-in-page works across
   the whole thing. Filtering only hides and shows what is already there.

   The year is a select rather than a chip row: there are two dozen of them,
   and two dozen chips wrap to three rows of furniture above the content they
   filter. */

(function () {
  'use strict';

  function byId(id) { return document.getElementById(id); }


  /* ------------------------------------------------------------------------
     Section tabs

     The tab bar shipped with is-current hard-coded on the first tab and
     nothing to move it, so it always claimed you were in the first section.

     Deliberately a throttled scroll listener rather than an
     IntersectionObserver: some embedded and backgrounded browsers deliver no
     observer entries at all, and js/mgdb-modern.js already carries a scroll
     fallback for the same reason. setTimeout rather than
     requestAnimationFrame, which is starved in the same environments.
     ------------------------------------------------------------------------ */

  function initTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) { return; }

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href') || '';
      if (href.charAt(0) !== '#') { return; }
      var section = document.getElementById(href.slice(1));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });
    if (!pairs.length) { return; }

    function mark(target) {
      pairs.forEach(function (pair) {
        var current = pair.section === target;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); }
        else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    /* The last sections sit too close to the foot of the document to scroll
       under the bar, so at the bottom the last one is current by definition. */
    function spy() {
      var doc = document.documentElement;
      if (window.innerHeight + window.pageYOffset >= doc.scrollHeight - 2) {
        mark(pairs[pairs.length - 1].section);
        return;
      }
      var bar = document.querySelector('.mgdb-section-tabs');
      var line = (bar ? bar.getBoundingClientRect().bottom : 0) + 8;
      var best = pairs[0];
      pairs.forEach(function (pair) {
        if (pair.section.getBoundingClientRect().top <= line) { best = pair; }
      });
      mark(best.section);
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () { mark(pair.section); });
    });

    var pending = null;
    function onScroll() {
      if (pending) { return; }
      pending = window.setTimeout(function () { pending = null; spy(); }, 100);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    spy();
  }

  function init() {
    if (!window.MGDB) { return; }

    var items = document.querySelectorAll('#whatsnew-list .news-item');
    if (!items.length) { return; }

    var groups = document.querySelectorAll('#whatsnew-list .news-year');
    var yearSelect = byId('whatsnew-year');

    var list = window.MGDB.filterList({
      items: items,
      input: byId('whatsnew-query'),
      count: byId('whatsnew-count'),
      empty: byId('whatsnew-empty'),
      reset: byId('whatsnew-reset'),
      noun: 'announcements',
      urlKeys: { query: 'q' },
      /* filterList's own chip filter is unused here; the year comes off the
         select, which is read fresh on every pass. */
      filterOn: function (item) {
        var year = yearSelect ? yearSelect.value : '';
        return !year || item.getAttribute('data-year') === year;
      },
      onChange: function () {
        /* A year heading with nothing under it reads as a year with no news,
           which is wrong -- it means the filter excluded all of them. */
        Array.prototype.forEach.call(groups, function (group) {
          var visible = 0;
          Array.prototype.forEach.call(group.querySelectorAll('.news-item'), function (item) {
            if (!item.hidden) { visible += 1; }
          });
          group.hidden = visible === 0;
          var count = group.querySelector('.news-year-count');
          if (count) {
            var total = group.querySelectorAll('.news-item').length;
            count.textContent = (visible === total) ? total : visible + ' of ' + total;
          }
        });
      }
    });

    if (yearSelect) {
      yearSelect.addEventListener('change', function () {
        list.refresh();
        if (byId('whatsnew-reset')) {
          byId('whatsnew-reset').hidden = !yearSelect.value && !byId('whatsnew-query').value;
        }
      });
    }

    /* filterList's reset clears its own state; the year select is ours. */
    var reset = byId('whatsnew-reset');
    if (reset) {
      reset.addEventListener('click', function () {
        if (yearSelect) { yearSelect.value = ''; }
        list.refresh();
      });
    }

    var emptyReset = byId('whatsnew-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        if (yearSelect) { yearSelect.value = ''; }
        if (reset) { reset.click(); }
        else { list.refresh(); }
      });
    }

    initTabs();

    /* A /whatsnew#news-250 link has to survive the first filter pass: the item
       is visible, but the sticky year heading would otherwise sit over it. */
    if (window.location.hash) {
      var target = document.querySelector(window.location.hash);
      if (target && target.classList.contains('news-item')) {
        window.setTimeout(function () {
          target.scrollIntoView({ block: 'center' });
        }, 60);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
