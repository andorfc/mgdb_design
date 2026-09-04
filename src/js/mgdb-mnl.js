/* Maize Genetics Cooperation Newsletter archive (/mnl).

   Ninety-four volumes, rendered server-side twice — once as grid chips, once
   as table rows — and filtered together. Both views are in the DOM and
   toggled, so switching never refetches and the browser's own find-in-page
   works whichever view is showing.

   The mockup's third view was a timeline. It is not built: the newsletter ran
   one volume a year for ninety years, so the timeline drew a straight line
   and told the reader nothing the year beside each volume did not. */

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

    var cards = document.querySelectorAll('#mnl-grid .mnl-card');
    var rows = document.querySelectorAll('#mnl-table tbody tr');
    if (!cards.length) { return; }

    var decade = byId('mnl-decade');

    /* One filter drives both views. The cards and the rows carry identical
       data attributes, so the two can never disagree about what matched. */
    var items = Array.prototype.slice.call(cards).concat(Array.prototype.slice.call(rows));

    var list = window.MGDB.filterList({
      items: items,
      input: byId('mnl-query'),
      count: byId('mnl-count'),
      empty: byId('mnl-empty'),
      reset: byId('mnl-reset'),
      noun: 'volumes',
      urlKeys: { query: 'q' },
      filterOn: function (item) {
        var want = decade ? decade.value : '';
        return !want || item.getAttribute('data-decade') === want;
      },
      onChange: function (visible) {
        /* Both views hold every volume, so the raw count double-counts. */
        var real = Math.round(visible / 2);
        var total = cards.length;
        var count = byId('mnl-count');
        if (count) {
          count.textContent = (real === total)
            ? total + ' volumes shown'
            : real + ' of ' + total + ' volumes shown';
        }
      }
    });

    if (decade) {
      decade.addEventListener('change', function () { list.refresh(); });
    }

    var reset = byId('mnl-reset');
    if (reset) {
      reset.addEventListener('click', function () {
        if (decade) { decade.value = ''; }
        list.refresh();
      });
    }

    var emptyReset = byId('mnl-empty-reset');
    if (emptyReset) {
      emptyReset.addEventListener('click', function () {
        if (decade) { decade.value = ''; }
        if (reset) { reset.click(); }
        else { list.refresh(); }
      });
    }

    initTabs();

    /* View switch */
    var buttons = document.querySelectorAll('.mnl-view-btn');
    Array.prototype.forEach.call(buttons, function (button) {
      button.addEventListener('click', function () {
        var want = button.getAttribute('data-view');
        Array.prototype.forEach.call(buttons, function (other) {
          other.setAttribute('aria-pressed', other.getAttribute('data-view') === want ? 'true' : 'false');
        });
        Array.prototype.forEach.call(document.querySelectorAll('.mnl-view'), function (view) {
          view.hidden = view.getAttribute('data-view-panel') !== want;
        });
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
