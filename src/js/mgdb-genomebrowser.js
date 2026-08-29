/**
 * file: js/mgdb-genomebrowser.js
 *
 * purpose: Scrollspy, launcher submit handlers, and live search for Genome Browser Data Hub
 */

(function () {
  'use strict';

  function init() {
    initScrollSpy();
    initDirectoryFilter();
  }

  function initScrollSpy() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length || !('IntersectionObserver' in window)) return;

    var sectionIds = [];
    tabs.forEach(function (tab) {
      var href = tab.getAttribute('href');
      if (href && href.charAt(0) === '#') {
        sectionIds.push(href.substring(1));
      }
    });

    var sections = sectionIds.map(function (id) {
      return document.getElementById(id);
    }).filter(Boolean);

    if (!sections.length) return;

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          var id = entry.target.id;
          tabs.forEach(function (tab) {
            if (tab.getAttribute('href') === '#' + id) {
              tab.classList.add('is-current');
              tab.setAttribute('aria-current', 'true');
            } else {
              tab.classList.remove('is-current');
              tab.removeAttribute('aria-current');
            }
          });
        }
      });
    }, {
      rootMargin: '-10% 0px -75% 0px',
      threshold: 0
    });

    sections.forEach(function (sec) {
      observer.observe(sec);
    });
  }

  function initDirectoryFilter() {
    var searchInput = document.getElementById('browser-dir-search');
    var filterChips = document.querySelectorAll('.dir-filter-chip');
    var rows = document.querySelectorAll('#browser-dir-table tbody tr');

    if (!rows.length) return;

    var activePlatform = 'all';
    var searchQuery = '';

    function applyFilter() {
      var q = searchQuery.toLowerCase().trim();

      rows.forEach(function (row) {
        var rowType = (row.getAttribute('data-type') || '').toLowerCase();
        var text = row.textContent.toLowerCase();

        var matchesPlatform = (activePlatform === 'all' || rowType.indexOf(activePlatform) !== -1);
        var matchesSearch = (q === '' || text.indexOf(q) !== -1);

        if (matchesPlatform && matchesSearch) {
          row.style.display = '';
        } else {
          row.style.display = 'none';
        }
      });
    }

    filterChips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        filterChips.forEach(function (c) { c.classList.remove('is-active'); });
        chip.classList.add('is-active');
        activePlatform = (chip.getAttribute('data-filter') || 'all').toLowerCase();
        applyFilter();
      });
    });

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        searchQuery = searchInput.value;
        applyFilter();
      });
    }
  }

  // Global launcher helpers
  window.launchJBrowse2Linear = function () {
    var form = document.getElementById('jbrowse2_linear_form');
    if (!form) return;

    var asm1 = document.getElementById('linear_primary_assembly');
    var chr = document.getElementById('linear_chr');
    var start = document.getElementById('linear_start');
    var end = document.getElementById('linear_end');

    var asm1Val = asm1 ? asm1.value : 'Zm-B73-REFERENCE-NAM-5.0';
    var chrVal = chr ? chr.value : 'chr1';
    var startVal = start && start.value ? start.value.trim() : '';
    var endVal = end && end.value ? end.value.trim() : '';

    var targetUrl = 'https://jbrowse2.maizegdb.org';
    if (startVal !== '' && endVal !== '') {
      targetUrl += '/?loc=' + encodeURIComponent(chrVal + ':' + startVal + '..' + endVal) + '&assembly=' + encodeURIComponent(asm1Val);
      window.open(targetUrl, '_blank', 'noopener');
    } else {
      form.submit();
    }
  };

  window.launchJBrowse2Synteny = function () {
    var form = document.getElementById('jbrowse2_synteny_form');
    if (form) {
      form.submit();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
