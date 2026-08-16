(function () {
  'use strict';

  function updateAddress(query) {
    if (!window.history || !window.history.replaceState) return;
    var url = new URL(window.location.href);
    if (query) url.searchParams.set('q', query);
    else url.searchParams.delete('q');
    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
  }

  function executeEmbeddedScript(responseText) {
    var scripts = responseText.match(/<script[^>]*>[\s\S]*?<\/script>/gi) || [];
    scripts.forEach(function (script) {
      var code = script.replace(/^<script[^>]*>/i, '').replace(/<\/script>$/i, '');
      if (code.trim()) window.eval(code);
    });
  }

  function runSearch(form) {
    var input = form.querySelector('input[name="q"]');
    var results = document.getElementById('est-search-results');
    var status = document.getElementById('est-search-status');
    var limitInput = document.getElementById('limit_val');
    var query = input.value.trim();
    var maximum = parseInt(limitInput.max, 10);
    var limit = parseInt(limitInput.value, 10);

    if (!query) {
      status.textContent = 'Enter an EST identifier or search pattern before searching.';
      input.focus();
      return;
    }

    if (!Number.isFinite(limit) || limit < 1) limit = 1;
    if (Number.isFinite(maximum) && limit > maximum) limit = maximum;
    limitInput.value = limit;

    status.textContent = 'Searching MaizeGDB expressed sequence tag records.';
    results.setAttribute('aria-busy', 'true');
    results.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span><span>Searching EST records&hellip;</span></div>';
    updateAddress(query);

    window.main_div_name = results.id;
    window.jQuery.post('/search/est/est_results.php', {
      term: encodeURI(query),
      search_limit: limit,
      div_name: results.id
    }).done(function (data, textStatus, xhr) {
      var response = xhr.responseText || data || '';
      var redirect = response.match(/^\s*javascript:document\.location\s*=\s*['"]([^'"]+)['"]/i);

      if (redirect) {
        window.location.assign(redirect[1]);
        return;
      }

      results.innerHTML = response;
      results.setAttribute('aria-busy', 'false');
      status.textContent = /no records matching|no matches/i.test(response)
        ? 'No matching EST records were found.'
        : 'Search complete. Matching EST records are shown below.';
      executeEmbeddedScript(response);
      results.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'nearest'
      });
    }).fail(function () {
      results.setAttribute('aria-busy', 'false');
      results.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert"><div><strong>Search unavailable.</strong> The EST collection could not be queried. Please try again.</div></div>';
      status.textContent = 'The EST search could not be completed.';
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('est-search-form');
    if (!form) return;

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      runSearch(form);
    });

    form.addEventListener('reset', function () {
      window.setTimeout(function () {
        var results = document.getElementById('est-search-results');
        results.innerHTML = '';
        results.removeAttribute('aria-busy');
        document.getElementById('est-search-status').textContent = '';
        updateAddress('');
      }, 0);
    });

    form.querySelectorAll('[data-est-example]').forEach(function (button) {
      button.addEventListener('click', function () {
        form.querySelector('input[name="q"]').value = button.getAttribute('data-est-example');
        runSearch(form);
      });
    });

    var query = new URLSearchParams(window.location.search).get('q');
    if (query) {
      form.querySelector('input[name="q"]').value = query;
      runSearch(form);
    }
  });
})();
