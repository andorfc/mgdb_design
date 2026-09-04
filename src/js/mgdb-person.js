/* ==========================================================================
 * file: mgdb-person.js
 * purpose: Interactive Person and Organization Directory search (/person)
 * ========================================================================== */

(function () {
  'use strict';

  var searchTimer = null;
  var suggestionTimer = null;
  var searchController = null;
  var suggestionController = null;
  var activeSuggestion = -1;
  var currentRequestId = 0;

  function byId(id) {
    return document.getElementById(id);
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[c];
    });
  }

  function getQuery() {
    var input = byId('bacterm');
    return input ? input.value.trim() : '';
  }

  function setStatus(message) {
    var status = byId('person-search-status');
    if (status) {
      status.textContent = message || '';
    }
  }

  function updateClearButton() {
    var clearBtn = byId('person-search-clear');
    var val = getQuery();
    if (clearBtn) {
      clearBtn.hidden = !val.length;
    }
  }

  function closeSuggestions() {
    var panel = byId('person-suggestions');
    var input = byId('bacterm');
    if (panel) {
      panel.hidden = true;
      panel.innerHTML = '';
    }
    if (input) {
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }
    activeSuggestion = -1;
  }

  function renderSuggestions(items) {
    var panel = byId('person-suggestions');
    var input = byId('bacterm');
    if (!panel || !input || !items || !items.length) {
      closeSuggestions();
      return;
    }

    panel.innerHTML = items.map(function (item, index) {
      var secondary = [item.institution, item.location].filter(Boolean).join(' · ');
      var subName = item.full_name ? ' (' + escapeHtml(item.full_name) + ')' : '';
      return '<a id="person-suggestion-' + index + '" class="person-suggestion" role="option" href="/person?id=' +
        encodeURIComponent(item.id) + '" data-index="' + index + '">' +
        '<span class="person-suggestion-avatar" aria-hidden="true">' + escapeHtml(item.initial || 'P') + '</span>' +
        '<div class="person-suggestion-content">' +
        '  <span class="person-suggestion-name">' + escapeHtml(item.name) + subName + '</span>' +
        (secondary ? '  <span class="person-suggestion-meta">' + escapeHtml(secondary) + '</span>' : '') +
        '</div>' +
        '<span class="person-suggestion-arrow" aria-hidden="true">&rarr;</span>' +
        '</a>';
    }).join('');

    panel.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function fetchSuggestions() {
    var term = getQuery();
    if (term.length < 2) {
      closeSuggestions();
      return;
    }

    if (suggestionController) {
      suggestionController.abort();
    }
    suggestionController = new AbortController();

    fetch('/tools/ajax/person_search/person_suggest_api.php?term=' + encodeURIComponent(term), {
      signal: suggestionController.signal,
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      if (!response.ok) throw new Error('Suggestion request failed');
      return response.json();
    }).then(function (data) {
      if (getQuery() === term) {
        renderSuggestions(data.results || []);
      }
    }).catch(function (error) {
      if (error.name !== 'AbortError') {
        closeSuggestions();
      }
    });
  }

  function executeSearch(customQuery, isLetter) {
    var term = typeof customQuery === 'string' ? customQuery.trim() : getQuery();
    var results = byId('person-results-container');
    var loading = document.querySelector('.person-loading');
    if (!results) return false;

    closeSuggestions();

    // Clear active letter button styling unless doing a letter search
    if (!isLetter) {
      document.querySelectorAll('.person-az-btn').forEach(function (btn) {
        btn.classList.remove('is-active');
      });
    }

    if (!term.length) {
      results.innerHTML = '<div class="person-empty-state"><h3>Enter a researcher or organization name</h3><p>Search by surname, full name, aliases, or institution above, or browse using the A–Z directory below.</p></div>';
      setStatus('');
      return false;
    }

    if (!isLetter && term.length < 2) {
      results.innerHTML = '<div class="person-empty-state"><h3>Enter at least 2 characters</h3><p>Please enter 2 or more characters to search (e.g. <em>Li</em>, <em>Wu</em>, <em>Yu</em>, <em>Buckler</em>, <em>Walbot</em>).</p></div>';
      setStatus('Enter at least 2 characters.');
      return false;
    }

    if (searchController) {
      searchController.abort();
    }
    searchController = new AbortController();

    currentRequestId++;
    var reqId = currentRequestId;

    if (loading) loading.hidden = false;
    setStatus('Searching community records…');

    var url = isLetter
      ? '/tools/ajax/person_search/persondisplayresults.php?letter=' + encodeURIComponent(term)
      : '/tools/ajax/person_search/persondisplayresults.php?term=' + encodeURIComponent(term);

    // Sync browser URL history
    if (window.history && window.history.replaceState) {
      var newUrl = isLetter
        ? '/person?letter=' + encodeURIComponent(term)
        : '/person?term=' + encodeURIComponent(term);
      window.history.replaceState({}, '', newUrl);
    }

    fetch(url, { signal: searchController.signal })
      .then(function (response) {
        if (!response.ok) throw new Error('Search failed');
        return response.text();
      })
      .then(function (html) {
        if (reqId !== currentRequestId) return;
        if (loading) loading.hidden = true;
        results.innerHTML = html;
        setStatus('Results updated for ' + (isLetter ? 'letter ' + term : '"' + term + '"') + '.');
      })
      .catch(function (error) {
        if (error.name === 'AbortError') return;
        if (loading) loading.hidden = true;
        results.innerHTML = '<div class="person-empty-state"><h3>Search temporarily unavailable</h3><p>Please check your connection or try again in a moment.</p></div>';
        setStatus('Search failed.');
      });

    return false;
  }

  function selectSuggestion(direction) {
    var panel = byId('person-suggestions');
    var input = byId('bacterm');
    if (!panel || panel.hidden) return;

    var options = panel.querySelectorAll('[role="option"]');
    if (!options.length) return;

    activeSuggestion = (activeSuggestion + direction + options.length) % options.length;
    options.forEach(function (option, index) {
      option.classList.toggle('is-active', index === activeSuggestion);
    });

    input.setAttribute('aria-activedescendant', options[activeSuggestion].id);
    options[activeSuggestion].scrollIntoView({ block: 'nearest' });
  }

  function initPersonSearch() {
    var form = byId('person-search-form');
    var input = byId('bacterm');
    var clearBtn = byId('person-search-clear');

    if (!form || !input) return;

    updateClearButton();

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      executeSearch();
    });

    input.addEventListener('input', function () {
      updateClearButton();
      clearTimeout(searchTimer);
      clearTimeout(suggestionTimer);

      var val = getQuery();
      if (val.length >= 2) {
        suggestionTimer = setTimeout(fetchSuggestions, 120);
        searchTimer = setTimeout(function () {
          executeSearch();
        }, 380);
      } else {
        closeSuggestions();
        if (!val.length) {
          executeSearch();
        }
      }
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectSuggestion(1);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectSuggestion(-1);
      } else if (e.key === 'Escape') {
        closeSuggestions();
      } else if (e.key === 'Enter' && activeSuggestion >= 0) {
        var active = byId('person-suggestion-' + activeSuggestion);
        if (active) {
          e.preventDefault();
          window.location.href = active.href;
        }
      }
    });

    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        input.value = '';
        updateClearButton();
        closeSuggestions();
        input.focus();
        executeSearch();
      });
    }

    document.addEventListener('click', function (e) {
      if (!e.target.closest('.person-search-input-wrap')) {
        closeSuggestions();
      }
    });

    // Quick Search Chips
    document.querySelectorAll('.person-chip').forEach(function (chip) {
      chip.addEventListener('click', function () {
        var val = chip.getAttribute('data-person-example') || chip.textContent.trim();
        input.value = val;
        updateClearButton();
        closeSuggestions();
        executeSearch(val);
      });
    });

    // A-Z Alphabetical directory buttons
    document.querySelectorAll('.person-az-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var letter = btn.getAttribute('data-letter') || btn.textContent.trim();
        document.querySelectorAll('.person-az-btn').forEach(function (b) {
          b.classList.toggle('is-active', b === btn);
        });
        input.value = '';
        updateClearButton();
        closeSuggestions();
        executeSearch(letter, true);
      });
    });

    // Initial search on page load if query param present or default
    var urlParams = new URLSearchParams(window.location.search);
    var initTerm = urlParams.get('term');
    var initLetter = urlParams.get('letter');

    if (initTerm && initTerm.trim().length >= 2) {
      input.value = initTerm.trim();
      updateClearButton();
      executeSearch(initTerm.trim());
    } else if (initLetter && initLetter.trim().length === 1) {
      var targetBtn = document.querySelector('.person-az-btn[data-letter="' + initLetter.trim().toUpperCase() + '"]');
      if (targetBtn) targetBtn.classList.add('is-active');
      executeSearch(initLetter.trim(), true);
    } else if (input.value && input.value.trim().length >= 2) {
      executeSearch(input.value.trim());
    } else {
      // Default landing: show prominent initial directory (e.g. Walbot or letter A)
      executeSearch('Walbot');
    }
  }

  /* The sticky section tabs. `.mgdb-section-tabs` is styled by the shell but
     driven per page, and this page shipped without a spy: the bar highlighted
     whatever the template marked and never changed, silently. MGDB.sectionTabs
     is that behaviour, shared, so this is the only line a page needs. */
  function boot() {
    initPersonSearch();
    if (window.MGDB && MGDB.sectionTabs) { MGDB.sectionTabs({ watch: '#person-search-section' }); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
