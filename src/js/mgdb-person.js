/* Person and organization search (/person).

   Suggestion and result behaviour ported unchanged from the MaizeGDB
   website repository; only the presentation layer was restyled. */

/* Person and organization search, plus record-page disclosure controls. */
(function () {
  'use strict';

  var searchTimer = null;
  var suggestionTimer = null;
  var searchController = null;
  var suggestionController = null;
  var activeSuggestion = -1;

  function byId(id) { return document.getElementById(id); }
  function escapeHtml(value) {
    return String(value || '').replace(/[&<>'"]/g, function (char) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char];
    });
  }
  function query() { return byId('bacterm') ? byId('bacterm').value.trim() : ''; }
  function setStatus(message) { if (byId('person-search-status')) byId('person-search-status').textContent = message || ''; }

  function closeSuggestions() {
    var panel = byId('person-suggestions');
    var input = byId('bacterm');
    if (panel) { panel.hidden = true; panel.innerHTML = ''; }
    if (input) { input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }
    activeSuggestion = -1;
  }

  function renderSuggestions(items) {
    var panel = byId('person-suggestions');
    var input = byId('bacterm');
    if (!panel || !input || !items.length) { closeSuggestions(); return; }
    panel.innerHTML = items.map(function (item, index) {
      var secondary = [item.institution, item.location].filter(Boolean).join(' · ');
      return '<a id="person-suggestion-' + index + '" class="person-suggestion" role="option" href="/person?id=' +
        encodeURIComponent(item.id) + '" data-index="' + index + '">' +
        '<span class="person-suggestion-avatar" aria-hidden="true">' + escapeHtml(item.initial) + '</span>' +
        '<span><strong>' + escapeHtml(item.name) + '</strong>' +
        (item.full_name ? '<small>' + escapeHtml(item.full_name) + '</small>' : '') +
        (secondary ? '<em>' + escapeHtml(secondary) + '</em>' : '') + '</span>' +
        '<b aria-hidden="true">→</b></a>';
    }).join('');
    panel.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  window.doSugg = function () {
    var term = query();
    if (term.length < 2) { closeSuggestions(); return; }
    if (suggestionController) suggestionController.abort();
    suggestionController = new AbortController();
    fetch('/tools/ajax/person_search/person_suggest_api.php?term=' + encodeURIComponent(term), {
      signal: suggestionController.signal,
      headers: {'Accept': 'application/json'}
    }).then(function (response) {
      if (!response.ok) throw new Error('Suggestion request failed');
      return response.json();
    }).then(function (data) {
      if (query() === term) renderSuggestions(data.results || []);
    }).catch(function (error) {
      if (error.name !== 'AbortError') closeSuggestions();
    });
  };

  window.doSyn = function (term, requestId) {
    var panel = byId('p4');
    if (!panel || term.length < 3) return Promise.resolve();
    return fetch('/tools/ajax/person_search/displaypersonsynlist_ajax.php?term=' + encodeURIComponent(term))
      .then(function (response) { if (!response.ok) throw new Error(); return response.text(); })
      .then(function (html) {
        if (requestId === window.personSearchRequestId) {
          panel.innerHTML = html;
          panel.hidden = !html.trim();
        }
      }).catch(function () { panel.hidden = true; });
  };

  window.doWork = function (update, keepSuggestions) {
    var term = query();
    var results = byId('p1');
    var aliases = byId('p4');
    if (!results) return false;
    if (!keepSuggestions) closeSuggestions();
    if (term.length < 3) {
      results.hidden = true;
      results.innerHTML = '';
      if (aliases) { aliases.hidden = true; aliases.innerHTML = ''; }
      setStatus(term.length ? 'Enter ' + (3 - term.length) + ' more character' + (term.length === 2 ? '' : 's') + '.' : '');
      return false;
    }

    if (searchController) searchController.abort();
    searchController = new AbortController();
    window.personSearchRequestId = (window.personSearchRequestId || 0) + 1;
    var requestId = window.personSearchRequestId;
    var updateFlag = typeof update !== 'undefined' ? '&update=Y' : '';
    results.hidden = false;
    results.innerHTML = '<div class="person-loading" aria-label="Loading results"><i></i><i></i><i></i></div>';
    if (aliases) { aliases.hidden = true; aliases.innerHTML = ''; }
    setStatus('Searching…');

    if (!update && window.history && window.history.replaceState) {
      window.history.replaceState({}, '', '/person?term=' + encodeURIComponent(term));
    }

    fetch('/tools/ajax/person_search/persondisplayresults.php?term=' + encodeURIComponent(term) + updateFlag, {
      signal: searchController.signal
    }).then(function (response) {
      if (!response.ok) throw new Error('Search request failed');
      return response.text();
    }).then(function (html) {
      if (requestId !== window.personSearchRequestId) return;
      results.innerHTML = html;
      setStatus('Results updated for ' + term + '.');
      return window.doSyn(term, requestId);
    }).catch(function (error) {
      if (error.name === 'AbortError') return;
      results.innerHTML = '<div class="person-empty-state"><h2>Search unavailable</h2><p>Please try again in a moment.</p></div>';
      setStatus('The search could not be completed.');
    });
    return false;
  };

  window.SamplePersonQuery = function () {
    var samples = ['Ed Buckler', 'Virginia Walbot', 'Barbara McClintock', 'John Portwood', 'Sarah Hake'];
    var input = byId('bacterm');
    if (input) input.value = samples[Math.floor(Math.random() * samples.length)];
  };

  function selectSuggestion(direction) {
    var panel = byId('person-suggestions');
    var input = byId('bacterm');
    if (!panel || panel.hidden) return;
    var options = panel.querySelectorAll('[role="option"]');
    if (!options.length) return;
    activeSuggestion = (activeSuggestion + direction + options.length) % options.length;
    options.forEach(function (option, index) { option.classList.toggle('is-active', index === activeSuggestion); });
    input.setAttribute('aria-activedescendant', options[activeSuggestion].id);
  }

  function initSearch() {
    var form = byId('person-search-form');
    var input = byId('bacterm');
    if (!form || !input) return;
    form.addEventListener('submit', function (event) { event.preventDefault(); window.doWork(); });
    input.addEventListener('input', function () {
      clearTimeout(searchTimer); clearTimeout(suggestionTimer);
      suggestionTimer = setTimeout(window.doSugg, 140);
      searchTimer = setTimeout(function () { window.doWork(undefined, true); }, 420);
    });
    input.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') { event.preventDefault(); selectSuggestion(1); }
      else if (event.key === 'ArrowUp') { event.preventDefault(); selectSuggestion(-1); }
      else if (event.key === 'Escape') closeSuggestions();
      else if (event.key === 'Enter' && activeSuggestion >= 0) {
        var active = byId('person-suggestion-' + activeSuggestion);
        if (active) { event.preventDefault(); window.location.href = active.href; }
      }
    });
    document.addEventListener('click', function (event) {
      if (!event.target.closest('.person-autocomplete')) closeSuggestions();
    });
    document.querySelectorAll('[data-person-example]').forEach(function (button) {
      button.addEventListener('click', function () { input.value = button.getAttribute('data-person-example'); window.doWork(); });
    });
  }

  document.addEventListener('DOMContentLoaded', initSearch);
}());

function doCity() {
  var code = $('#city').val();
  if (code.length > 2) $('#p2').show().load('/tools/ajax/person_search/personuslocquery_ajax.php?city=' + encodeURIComponent(code));
  return false;
}
function doState() {
  var state = $('#state').val();
  var stateFull = $('#state option:selected').text();
  if (state.length > 1) $('#p2').show().load('/tools/ajax/person_search/personusstatequery_ajax.php?state=' + encodeURIComponent(state) + '&state_full=' + encodeURIComponent(stateFull));
  return false;
}
function doNation() {
  var code = $('#country').val();
  if (code.length > 1) $('#p3').show().load('/tools/ajax/person_search/personintllocquery_ajax.php?country=' + encodeURIComponent(code));
  return false;
}
function toggle_references(display) {
  document.getElementById('show_ref').style.display = display === 'show' ? 'block' : 'none';
  document.getElementById('hide_ref').style.display = display === 'show' ? 'none' : 'block';
}
function toggle_prj(display) {
  document.getElementById('show_prj').style.display = display === 'show' ? 'block' : 'none';
  document.getElementById('hide_prj').style.display = display === 'show' ? 'none' : 'block';
}
