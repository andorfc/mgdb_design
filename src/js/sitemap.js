/* Site map directory: search, group filter, and section expand/collapse.

   DOM contract, produced by templates/about/sitemap-featured.bau and
   templates/about/sitemap-content.bau:

     section.sitemap-featured[data-section-kind]  the "New tools" band, which
       li.sitemap-item                            sits above the search panel
                                                  but still filters with it

     nav.sitemap-tabs a[data-tab-section]         sticky jump link into a
                                                  section, scrollspy-highlighted

     section.sitemap-section[data-section-kind]   one resource group
       button.sitemap-section-toggle[aria-controls]  opens .sitemap-panel
       span.sitemap-section-count[data-total]        live "shown of total"
       li.sitemap-item                               one resource
         a.sitemap-item-link                         its name
         p.sitemap-item-desc                         its description

   Everything keys off classes, so the templates can grow without touching JS.

   Counting note: the featured band repeats tools that also appear in the
   directory below, so it is filtered like everything else but deliberately
   left out of every count. Totals come from unique hrefs in #sitemap_content. */

(function () {
  'use strict';

  var state = { term: '', section: 'all' };
  var entries = [];      // directory items only
  var featured = [];     // new-tools band
  var sections = [];
  var total = 0;         // unique directory destinations

  function byId(id) { return document.getElementById(id); }

  function normalize(value) {
    return (value || '').toLowerCase().replace(/\s+/g, ' ').trim();
  }

  function haystackFor(element) {
    var link = element.querySelector('.sitemap-item-link');
    if (!link) return null;
    var desc = element.querySelector('.sitemap-item-desc');
    return normalize(link.textContent + ' ' + (desc ? desc.textContent : '') + ' ' +
                     (link.getAttribute('href') || ''));
  }

  function collect() {
    var urls = {};

    sections = Array.prototype.map.call(
      document.querySelectorAll('.sitemap-section'),
      function (section) {
        return {
          element: section,
          kind: section.getAttribute('data-section-kind') || 'community',
          toggle: section.querySelector('.sitemap-section-toggle'),
          panel: section.querySelector('.sitemap-panel'),
          counter: section.querySelector('.sitemap-section-count'),
          items: []
        };
      }
    );

    sections.forEach(function (section) {
      Array.prototype.forEach.call(
        section.panel.querySelectorAll('.sitemap-item'),
        function (element) {
          var haystack = haystackFor(element);
          if (haystack === null) return;
          var entry = { element: element, section: section, haystack: haystack };
          entries.push(entry);
          section.items.push(entry);
          urls[element.querySelector('.sitemap-item-link').getAttribute('href')] = true;
        }
      );
    });

    total = Object.keys(urls).length;

    Array.prototype.forEach.call(
      document.querySelectorAll('.sitemap-featured .sitemap-item'),
      function (element) {
        var haystack = haystackFor(element);
        if (haystack !== null) featured.push({ element: element, haystack: haystack });
      }
    );
  }

  function setExpanded(section, expanded) {
    section.toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    section.panel.classList.toggle('open', expanded);
    section.panel.classList.toggle('closed', !expanded);
  }

  function matches(entry, kind) {
    return (state.section === 'all' || kind === state.section) &&
           (state.term === '' || entry.haystack.indexOf(state.term) !== -1);
  }

  function apply() {
    var matched = 0;

    entries.forEach(function (entry) {
      var hit = matches(entry, entry.section.kind);
      entry.element.classList.toggle('sitemap-hidden', !hit);
      if (hit) matched++;
    });

    sections.forEach(function (section) {
      var shown = section.items.filter(function (entry) {
        return !entry.element.classList.contains('sitemap-hidden');
      }).length;

      section.element.classList.toggle('sitemap-hidden', shown === 0);
      if (section.counter) {
        var sectionTotal = section.counter.getAttribute('data-total');
        section.counter.textContent =
          (shown === Number(sectionTotal)) ? sectionTotal : shown + ' of ' + sectionTotal;
      }
      // A search should reveal what it found rather than leave it folded away.
      if (state.term !== '' && shown > 0) setExpanded(section, true);
    });

    // The band is tools-only, so a non-tools group filter hides all of it.
    var featuredShown = 0;
    featured.forEach(function (entry) {
      var hit = matches(entry, 'tools');
      entry.element.classList.toggle('sitemap-hidden', !hit);
      if (hit) featuredShown++;
    });
    var band = document.querySelector('.sitemap-featured');
    if (band) band.classList.toggle('sitemap-hidden', featuredShown === 0);

    var empty = byId('sitemap-empty');
    if (empty) empty.hidden = matched !== 0;

    syncTabAvailability();
    spy();

    var status = byId('sitemap-results-status');
    if (status) {
      if (state.term === '' && state.section === 'all') {
        status.textContent = total + ' resources across ' + sections.length + ' groups.';
      } else {
        status.textContent = matched + ' of ' + entries.length + ' listings match'
          + (state.term ? ' “' + state.term + '”' : '')
          + (state.section === 'all' ? '' : ' in this group') + '.';
      }
    }
  }

  /* ── Section tabs and scrollspy ─────────────────────────────────────────
     Same component and the same observer margins as the data hub pages. Two
     behaviours are specific to this page: a tab whose section the group filter
     has hidden goes inert rather than scrolling nowhere, and clicking a tab
     opens the section it lands on so the reader does not arrive at a heading
     with nothing under it. */

  var tabPairs = [];

  function markCurrentTab(target) {
    tabPairs.forEach(function (pair) {
      var current = pair.target === target;
      pair.tab.classList.toggle('is-current', current);
      if (current) {
        pair.tab.setAttribute('aria-current', 'true');
      } else {
        pair.tab.removeAttribute('aria-current');
      }
    });
  }

  function syncTabAvailability() {
    tabPairs.forEach(function (pair) {
      if (!pair.section) return;
      var hidden = pair.section.element.classList.contains('sitemap-hidden');
      pair.tab.classList.toggle('is-muted', hidden);
      if (hidden) {
        pair.tab.setAttribute('aria-disabled', 'true');
      } else {
        pair.tab.removeAttribute('aria-disabled');
      }
    });
  }

  function buildTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) return;

    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href') || '';
      if (href.charAt(0) !== '#') return;
      var target = document.getElementById(href.slice(1));
      if (!target) return;
      var sectionId = tab.getAttribute('data-tab-section');
      var section = null;
      sections.forEach(function (candidate) {
        if (candidate.element.id === sectionId) section = candidate;
      });
      tabPairs.push({ tab: tab, target: target, section: section });
    });

    tabPairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function (event) {
        if (pair.section && pair.section.element.classList.contains('sitemap-hidden')) {
          event.preventDefault();
          return;
        }
        if (pair.section) setExpanded(pair.section, true);
        markCurrentTab(pair.target);
      });
    });

    var initial = tabPairs[0];
    tabPairs.forEach(function (pair) {
      if ('#' + pair.target.id === window.location.hash) initial = pair;
    });
    if (initial) markCurrentTab(initial.target);

    // Throttled with setTimeout rather than requestAnimationFrame: rAF is
    // starved in backgrounded and embedded browsers, and a tab bar that stops
    // tracking whenever the page is not compositing is a bug the reader sees
    // the moment they come back to the tab. 100ms is imperceptible here.
    var pending = null;
    function onScroll() {
      if (pending) return;
      pending = window.setTimeout(function () { pending = null; spy(); }, 100);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    spy();
  }

  /* The last target whose top has passed under the sticky bar is the one the
     reader is in. tabPairs is in document order, so the last match wins.

     Deliberately not an IntersectionObserver, even though that is what the
     data hub pages use: some headless and embedded browsers never deliver
     entries, and js/mgdb-modern.js already carries a scroll fallback for the
     same reason. A tab bar that silently never highlights is worse than one
     that costs thirteen getBoundingClientRect calls per animation frame. */
  function spy() {
    if (!tabPairs.length) return;

    var live = tabPairs.filter(function (pair) {
      return !(pair.section && pair.section.element.classList.contains('sitemap-hidden'));
    });
    if (!live.length) return;

    // The trailing sections sit too close to the foot of the document to ever
    // scroll under the bar, so at the bottom the last one is the answer by
    // definition. Without this the final tab can never light up.
    var doc = document.documentElement;
    if (window.innerHeight + window.pageYOffset >= doc.scrollHeight - 2) {
      markCurrentTab(live[live.length - 1].target);
      return;
    }

    var bar = document.querySelector('.mgdb-section-tabs');
    var line = (bar ? bar.getBoundingClientRect().bottom : 0) + 8;
    var best = live[0];
    live.forEach(function (pair) {
      if (pair.target.getBoundingClientRect().top <= line) best = pair;
    });
    markCurrentTab(best.target);
  }

  function setSectionFilter(value) {
    state.section = value;
    Array.prototype.forEach.call(document.querySelectorAll('[data-section-filter]'), function (button) {
      var active = button.getAttribute('data-section-filter') === value;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    apply();
  }

  function fillMetrics() {
    var hubs = byId('sitemap-hub-count');
    var hubSection = byId('sm-data_center');
    if (hubs && hubSection) {
      hubs.textContent = hubSection.querySelectorAll('.sitemap-item').length;
    }
    var tools = byId('sitemap-tool-count');
    var toolSection = byId('sm-tools');
    if (tools && toolSection) {
      tools.textContent = toolSection.querySelectorAll('.sitemap-item').length;
    }
    var listed = byId('sitemap-resource-count');
    if (listed) listed.textContent = total;
  }

  function initialize() {
    if (!byId('sitemap_content')) return;
    collect();
    fillMetrics();
    buildTabs();

    sections.forEach(function (section) {
      section.toggle.addEventListener('click', function () {
        setExpanded(section, section.toggle.getAttribute('aria-expanded') !== 'true');
      });
    });

    var search = byId('sitemap-search');
    var clear = byId('sitemap-search-clear');
    if (search) {
      search.addEventListener('input', function () {
        state.term = normalize(search.value);
        if (clear) clear.hidden = search.value === '';
        apply();
      });
    }
    if (clear) {
      clear.addEventListener('click', function () {
        search.value = '';
        clear.hidden = true;
        state.term = '';
        apply();
        search.focus();
      });
    }
    var form = byId('sitemap-search-form');
    if (form) form.addEventListener('submit', function (event) { event.preventDefault(); });

    Array.prototype.forEach.call(document.querySelectorAll('[data-section-filter]'), function (button) {
      button.addEventListener('click', function () {
        setSectionFilter(button.getAttribute('data-section-filter'));
      });
    });

    var expandAll = byId('sitemap-expand-all');
    if (expandAll) {
      // Sections ship open, so on load the button's job is to collapse them.
      var anyClosedNow = function () {
        return sections.some(function (section) {
          return section.toggle.getAttribute('aria-expanded') !== 'true';
        });
      };
      expandAll.textContent = anyClosedNow() ? 'Expand all sections' : 'Collapse all sections';
      expandAll.addEventListener('click', function () {
        var open = anyClosedNow();
        sections.forEach(function (section) { setExpanded(section, open); });
        expandAll.textContent = open ? 'Collapse all sections' : 'Expand all sections';
      });
    }

    apply();
  }

  /* Bauplan::includeScript() emits into <head>, so this runs while the document
     is still parsing and every query above would return nothing. */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
})();
