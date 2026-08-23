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
