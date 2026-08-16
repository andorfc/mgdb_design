(function () {
  'use strict';

  function cleanSequence(value) {
    return value.replace(/\s+/g, '').toUpperCase();
  }

  function statusFor(form) {
    return document.getElementById(form.id.replace('-form', '-status'));
  }

  function updateAddress(source, query) {
    if (!window.history || !window.history.replaceState) return;
    var url = new URL(window.location.href);
    url.searchParams.set('mode', source === 'overgo_seq' ? 'sequence' : 'name');
    url.searchParams.set('q', query);
    window.history.replaceState({}, '', url.toString());
  }

  function executeEmbeddedScript(responseText, query) {
    var match = responseText.match(/[\s\S]*<script[^>]*>([\s\S]*)<\/script>[\s\S]*/i);
    if (match && match[1]) {
      var script = match[1].replace(
        /document\.getElementById\(['"]ovterm['"]\)\.value/g,
        JSON.stringify(query)
      );
      window.eval(script);
    }
  }

  function runSearch(form) {
    var source = form.getAttribute('data-overgo-source');
    var input = form.querySelector('input[name="q"]');
    var results = document.getElementById(form.getAttribute('data-overgo-results'));
    var status = statusFor(form);
    var query = input.value.trim();
    var error = form.querySelector('.mgdb-field-error');

    if (source === 'overgo_seq') {
      query = cleanSequence(query);
      input.value = query;
      var valid = /^[ACGT]{1,25}$/.test(query);
      input.setAttribute('aria-invalid', valid ? 'false' : 'true');
      if (error) error.hidden = valid;
      if (!valid) {
        status.textContent = 'Sequence search needs 1 to 25 bases using A, C, G, or T.';
        input.focus();
        return;
      }
    } else if (!query) {
      input.focus();
      return;
    }

    status.textContent = 'Searching the archived collection.';
    results.setAttribute('aria-busy', 'true');
    results.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span><span>Searching archived Overgo records&hellip;</span></div>';
    updateAddress(source, query);

    window.main_div_name = results.id;
    window.jQuery.post('/search/' + source + '/' + source + '_results.php', {
      term: encodeURI(query),
      search_limit: document.getElementById('limit_val').value,
      div_name: results.id
    }).done(function (data, textStatus, xhr) {
      var response = xhr.responseText || data || '';
      var redirect = response.match(/^\s*javascript:(?:parent\.)?document\.location\s*=\s*['"]([^'"]+)['"]/i);
      if (redirect) {
        window.location.assign(redirect[1]);
        return;
      }

      results.innerHTML = response;
      results.setAttribute('aria-busy', 'false');
      status.textContent = /no records|no overgo sequences/i.test(response)
        ? 'No matching archived records were found.'
        : 'Search complete. Matching archived records are shown below.';
      executeEmbeddedScript(response, query);
      results.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
    }).fail(function () {
      results.setAttribute('aria-busy', 'false');
      results.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert"><div><strong>Search unavailable.</strong> The archived collection could not be queried. Please try again.</div></div>';
      status.textContent = 'The search could not be completed.';
    });
  }

  function initializeForm(form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      runSearch(form);
    });

    form.addEventListener('reset', function () {
      window.setTimeout(function () {
        var results = document.getElementById(form.getAttribute('data-overgo-results'));
        var error = form.querySelector('.mgdb-field-error');
        var input = form.querySelector('input[name="q"]');
        results.innerHTML = '';
        results.removeAttribute('aria-busy');
        statusFor(form).textContent = '';
        input.removeAttribute('aria-invalid');
        if (error) error.hidden = true;
      }, 0);
    });

    form.querySelectorAll('[data-overgo-example]').forEach(function (button) {
      button.addEventListener('click', function () {
        form.querySelector('input[name="q"]').value = button.getAttribute('data-overgo-example');
        runSearch(form);
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var forms = Array.prototype.slice.call(document.querySelectorAll('[data-overgo-source]'));
    forms.forEach(initializeForm);

    var params = new URLSearchParams(window.location.search);
    var query = params.get('q');
    if (!query) return;

    var source = params.get('mode') === 'sequence' ? 'overgo_seq' : 'overgo';
    var form = document.querySelector('[data-overgo-source="' + source + '"]');
    if (form) {
      form.querySelector('input[name="q"]').value = query;
      runSearch(form);
    }
  });
})();
