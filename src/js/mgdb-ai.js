/* AI and machine learning resources (/ai).
   Resource finder, citation & DOI copying, and sticky tab scrollspy. */

(function () {
  'use strict';

  function byId(id) {
    return document.getElementById(id);
  }

  var activeCategory = 'all';

  function normalize(value) {
    return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
  }

  /* ── Section Tabs & Scrollspy ───────────────────────────────────────────── */

  function buildTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) return;

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href');
      if (href && href.startsWith('#')) {
        var section = document.querySelector(href);
        if (section) {
          pairs.push({ tab: tab, section: section });
        }
      }
    });

    function markCurrent(target) {
      pairs.forEach(function (pair) {
        var current = pair.section === target;
        pair.tab.classList.toggle('is-current', current);
        if (current) {
          pair.tab.setAttribute('aria-current', 'true');
        } else {
          pair.tab.removeAttribute('aria-current');
        }
      });
    }

    var initial = pairs[0];
    if (window.location.hash) {
      pairs.forEach(function (pair) {
        if ('#' + pair.section.id === window.location.hash) {
          initial = pair;
        }
      });
    }
    if (initial) {
      markCurrent(initial.section);
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () {
        markCurrent(pair.section);
      });
    });

    if (!window.IntersectionObserver) return;

    var observer = new window.IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          markCurrent(entry.target);
        }
      });
    }, { rootMargin: '-20% 0px -60% 0px' });

    pairs.forEach(function (pair) {
      observer.observe(pair.section);
    });
  }

  /* ── Resource Finder & Search ───────────────────────────────────────────── */

  function updateResources() {
    var input = byId('ai-resource-query');
    var query = normalize(input ? input.value : '');
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-ai-resource]'));
    var visible = 0;

    cards.forEach(function (card) {
      var categoryMatch = activeCategory === 'all' || card.getAttribute('data-ai-category') === activeCategory;
      var searchText = normalize(card.getAttribute('data-ai-search') + ' ' + card.textContent);
      var queryMatch = !query || query.split(' ').every(function (term) { return searchText.indexOf(term) !== -1; });
      card.hidden = !(categoryMatch && queryMatch);
      if (!card.hidden) visible += 1;
    });

    document.querySelectorAll('.ai-section').forEach(function (section) {
      if (section.id === 'ai-publications' || section.id === 'ai-related') return;
      var resourceCards = section.querySelectorAll('[data-ai-resource]');
      if (!resourceCards.length) return;
      var sectionVisible = Array.prototype.some.call(resourceCards, function (card) { return !card.hidden; });
      section.hidden = !sectionVisible;
    });

    var countEl = byId('ai-resource-count');
    if (countEl) {
      countEl.textContent = visible + (visible === 1 ? ' resource shown' : ' resources shown');
    }

    var noResults = byId('ai-no-results');
    if (noResults) {
      noResults.hidden = visible !== 0;
    }

    var clearBtn = byId('ai-resource-clear');
    if (clearBtn) {
      clearBtn.hidden = !query;
    }
  }

  function setCategory(category) {
    activeCategory = category;
    document.querySelectorAll('[data-ai-filter]').forEach(function (button) {
      var active = button.getAttribute('data-ai-filter') === category;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    updateResources();
  }

  function resetFinder() {
    var input = byId('ai-resource-query');
    if (input) input.value = '';
    setCategory('all');
    if (input) input.focus();
  }

  /* ── Copy Helpers (Citation & DOI) ───────────────────────────────────────── */

  function copyText(text, button) {
    if (!text) return;
    var original = button.textContent;
    function done(ok) {
      button.textContent = ok ? 'Copied!' : 'Press Ctrl+C';
      window.setTimeout(function () { button.textContent = original; }, 1400);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { done(true); }).catch(function () {
        selectAndCopy(text, button, done);
      });
    } else {
      selectAndCopy(text, button, done);
    }
  }

  function selectAndCopy(text, button, callback) {
    var area = document.createElement('textarea');
    area.value = text;
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try {
      var success = document.execCommand('copy');
      document.body.removeChild(area);
      callback(success);
    } catch (e) {
      document.body.removeChild(area);
      callback(false);
    }
  }

  function copyCitation(button) {
    var target = byId(button.getAttribute('data-copy-target'));
    if (!target) return;
    var citation = target.textContent.replace(/\s+/g, ' ').trim();
    copyText(citation, button);
  }

  function initialize() {
    buildTabs();

    var queryInput = byId('ai-resource-query');
    var clearBtn = byId('ai-resource-clear');
    var resetBtn = byId('ai-no-results-reset');

    if (queryInput) {
      queryInput.addEventListener('input', updateResources);
    }

    if (clearBtn && queryInput) {
      clearBtn.addEventListener('click', function () {
        queryInput.value = '';
        updateResources();
        queryInput.focus();
      });
    }

    if (resetBtn) {
      resetBtn.addEventListener('click', resetFinder);
    }

    document.querySelectorAll('[data-ai-filter]').forEach(function (button) {
      button.addEventListener('click', function () { setCategory(button.getAttribute('data-ai-filter')); });
    });

    document.querySelectorAll('.ai-copy-citation').forEach(function (button) {
      button.addEventListener('click', function () { copyCitation(button); });
    });

    document.querySelectorAll('.ai-copy-doi, [data-copy-value]').forEach(function (button) {
      button.addEventListener('click', function () {
        var val = button.getAttribute('data-copy-value');
        if (val) copyText(val, button);
      });
    });

    setCategory('all');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
}());
