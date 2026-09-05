/**
 * file: js/mgdb-history.js
 *
 * purpose: Scrollspy and category/search filtering for Maize History & Timelines
 */

(function () {
  'use strict';

  function init() {
    initScrollSpy();
    initTimelineFilter();
  }

  /* The tab bar. This page carried an IntersectionObserver-only spy, which is
     the shape that ships looking fine and never moves off the first tab -- one
     trigger does not fire in every case and there is no bottom-of-page case.
     MGDB.sectionTabs() is that behaviour written once, driven by scroll,
     IntersectionObserver and resize together. `watch` is the timeline, whose
     height changes when the category filter narrows it. */
  function initScrollSpy() {
    if (window.MGDB && window.MGDB.sectionTabs) {
      window.MGDB.sectionTabs({ watch: '.timeline-container' });
    }
  }

  function initTimelineFilter() {
    var filterBtns = document.querySelectorAll('.history-filter-btn');
    var searchInput = document.getElementById('history-search');
    var timelineItems = document.querySelectorAll('.timeline-item');

    if (!timelineItems.length) return;

    var activeCategory = 'all';
    var searchQuery = '';

    function applyFilters() {
      var q = searchQuery.toLowerCase().trim();

      timelineItems.forEach(function (item) {
        var itemType = item.getAttribute('data-type') || '';
        var itemYear = item.getAttribute('data-year') || '';
        var textContent = item.textContent.toLowerCase();

        var matchesCategory = (activeCategory === 'all' || itemType === activeCategory);
        var matchesSearch = (q === '' || textContent.indexOf(q) !== -1 || itemYear.indexOf(q) !== -1);

        if (matchesCategory && matchesSearch) {
          item.classList.remove('is-hidden');
        } else {
          item.classList.add('is-hidden');
        }
      });
    }

    filterBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        filterBtns.forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        activeCategory = btn.getAttribute('data-filter') || 'all';
        applyFilters();
      });
    });

    if (searchInput) {
      searchInput.addEventListener('input', function () {
        searchQuery = searchInput.value;
        applyFilters();
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
