/**
 * file: js/mgdb-api.js
 *
 * purpose: behaviour for the API documentation page (/api, /api/docs).
 *
 * Three things, none of which the page needs to be readable without:
 *
 *   - the section tab bar's scrollspy, opted into the same way every hub does;
 *   - the language tabs on the examples, one choice applied to every example
 *     and remembered per browser;
 *   - the Try it form, which sends a real request to this instance with
 *     fetch() and prints the status, the headers a client cares about, and
 *     the body.
 *
 * Copy buttons are the shell's: mgdb-modern.js binds any .mgdb-ref-copy with
 * a data-copy-target at load. The Try it result's copy button is bound here
 * as well, because its text is written after load.
 *
 * This script is emitted into <head>, so nothing touches the DOM before
 * DOMContentLoaded.
 */

(function () {
  'use strict';

  var LANG_KEY = 'mgdb-api-lang';
  var LANGS = ['curl', 'python', 'r', 'javascript'];
  var BODY_CAP = 400000; // characters shown; a full record can be larger

  function byId(id) { return document.getElementById(id); }

  /* ------------------------------------------------------------------------
     Language tabs
     ------------------------------------------------------------------------ */

  function readLang() {
    try {
      var stored = window.localStorage.getItem(LANG_KEY);
      return LANGS.indexOf(stored) === -1 ? null : stored;
    } catch (error) {
      return null;
    }
  }

  function writeLang(lang) {
    try { window.localStorage.setItem(LANG_KEY, lang); } catch (error) { /* private mode */ }
  }

  function applyLang(lang) {
    var examples = document.querySelectorAll('[data-api-example]');
    Array.prototype.forEach.call(examples, function (example) {
      var tabs = example.querySelectorAll('[role="tab"]');
      var panels = example.querySelectorAll('[role="tabpanel"]');
      Array.prototype.forEach.call(tabs, function (tab) {
        var on = tab.getAttribute('data-lang') === lang;
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
        tab.setAttribute('tabindex', on ? '0' : '-1');
      });
      Array.prototype.forEach.call(panels, function (panel) {
        panel.hidden = panel.getAttribute('data-lang') !== lang;
      });
    });
  }

  function initExamples() {
    var lang = readLang() || 'curl';
    applyLang(lang);

    document.addEventListener('click', function (event) {
      var tab = event.target.closest ? event.target.closest('[data-api-example] [role="tab"]') : null;
      if (!tab) { return; }
      var chosen = tab.getAttribute('data-lang');
      if (LANGS.indexOf(chosen) === -1) { return; }
      applyLang(chosen);
      writeLang(chosen);
    });

    // Left and right arrows move between the tabs of one example, as a
    // tablist is expected to.
    document.addEventListener('keydown', function (event) {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') { return; }
      var tab = event.target.closest ? event.target.closest('[data-api-example] [role="tab"]') : null;
      if (!tab) { return; }
      var tabs = Array.prototype.slice.call(tab.parentNode.querySelectorAll('[role="tab"]'));
      var index = tabs.indexOf(tab);
      var next = tabs[(index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
      if (next) {
        event.preventDefault();
        next.focus();
        next.click();
      }
    });
  }

  /* ------------------------------------------------------------------------
     Try it
     ------------------------------------------------------------------------ */

  function initTryIt() {
    var form = byId('api-try-form');
    if (!form) { return; }

    var typeEl = byId('api-try-type');
    var idEl = byId('api-try-id');
    var fieldsEl = byId('api-try-fields');
    var formatEl = byId('api-try-format');
    var sectionsEl = byId('api-try-sections');
    var urlEl = byId('api-try-url');
    var submitEl = byId('api-try-submit');
    var resultEl = byId('api-try-result');
    var statusEl = byId('api-try-status');
    var headersEl = byId('api-try-headers');
    var linksEl = byId('api-try-links');
    var bodyEl = byId('api-try-body');
    var base = (byId('api-base-url') ? byId('api-base-url').textContent : '').replace(/\/api\/v1\s*$/, '').trim()
             || window.location.origin;

    function selectedOption() {
      return typeEl.options[typeEl.selectedIndex];
    }

    function buildUrl() {
      var type = typeEl.value;
      var id = idEl.value.trim();
      var url = base + '/api/v1/records/' + encodeURIComponent(type) + '/' + encodeURIComponent(id);
      var params = [];
      var fields = fieldsEl.value.replace(/\s+/g, '');
      if (fields) { params.push('fields=' + encodeURIComponent(fields)); }
      if (formatEl.value === 'jsonld') { params.push('format=jsonld'); }
      if (params.length) { url += '?' + params.join('&'); }
      return url;
    }

    function showSections() {
      var option = selectedOption();
      var sections = option ? (option.getAttribute('data-sections') || '').split(',') : [];
      var parts = sections.filter(Boolean).map(function (s) {
        return '<code data-section="' + s + '" title="Add to fields">' + s + '</code>';
      });
      sectionsEl.innerHTML = parts.length ? 'Sections for this type: ' + parts.join(' ') + ' (click one to add it to fields)' : '';
    }

    function refresh() {
      urlEl.textContent = buildUrl();
    }

    typeEl.addEventListener('change', function () {
      var option = selectedOption();
      if (option) { idEl.value = option.getAttribute('data-example') || ''; }
      fieldsEl.value = '';
      showSections();
      refresh();
    });
    idEl.addEventListener('input', refresh);
    fieldsEl.addEventListener('input', refresh);
    formatEl.addEventListener('change', refresh);

    sectionsEl.addEventListener('click', function (event) {
      var code = event.target.closest ? event.target.closest('code[data-section]') : null;
      if (!code) { return; }
      var name = code.getAttribute('data-section');
      var current = fieldsEl.value.replace(/\s+/g, '').split(',').filter(Boolean);
      if (current.indexOf(name) === -1) { current.push(name); }
      fieldsEl.value = current.join(',');
      refresh();
    });

    /* Deep link: /api?type=stock&id=CML277 pre-fills and runs. */
    if (window.URLSearchParams) {
      var params = new window.URLSearchParams(window.location.search);
      var wantType = params.get('type');
      if (wantType) {
        Array.prototype.forEach.call(typeEl.options, function (option, index) {
          if (option.value === wantType) { typeEl.selectedIndex = index; }
        });
        var option = selectedOption();
        idEl.value = params.get('id') || (option ? option.getAttribute('data-example') || '' : '');
        if (params.get('fields')) { fieldsEl.value = params.get('fields'); }
        if (params.get('format') === 'jsonld') { formatEl.value = 'jsonld'; }
      }
    }
    showSections();
    refresh();

    function headerRow(name, value) {
      if (value === null || value === undefined || value === '') { return ''; }
      return '<div><dt>' + escapeHtml(name) + '</dt><dd>' + escapeHtml(value) + '</dd></div>';
    }

    function escapeHtml(text) {
      return String(text).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    function link(href, label) {
      return '<a class="mgdb-button mgdb-button-quiet mgdb-button-sm" href="' + escapeHtml(href) + '" target="_blank" rel="noopener">' + escapeHtml(label) + ' <span aria-hidden="true">&nearr;</span></a>';
    }

    function run(event) {
      event.preventDefault();
      var id = idEl.value.trim();
      if (!id) {
        idEl.focus();
        return;
      }
      var url = buildUrl();
      var accept = formatEl.value === 'jsonld' ? 'application/ld+json' : 'application/json';
      var started = (window.performance && window.performance.now) ? window.performance.now() : Date.now();

      submitEl.disabled = true;
      resultEl.hidden = false;
      statusEl.innerHTML = '<span class="api-status-note">Requesting…</span>';
      headersEl.innerHTML = '';
      linksEl.innerHTML = '';
      bodyEl.textContent = '';

      window.fetch(url, { headers: { 'Accept': accept }, credentials: 'omit' }).then(function (response) {
        var elapsed = ((window.performance && window.performance.now) ? window.performance.now() : Date.now()) - started;
        return response.text().then(function (text) {
          var pretty = text;
          var parsed = null;
          try {
            parsed = JSON.parse(text);
            pretty = JSON.stringify(parsed, null, 2);
          } catch (error) { /* not JSON; show as received */ }

          var klass = response.ok ? '' : (response.status >= 500 || response.status === 404 || response.status === 400 ? ' is-error' : ' is-info');
          var note = ' · ' + Math.round(elapsed) + ' ms round trip';
          if (parsed && parsed.meta && typeof parsed.meta.elapsed_ms === 'number') {
            note += ' · ' + parsed.meta.elapsed_ms + ' ms on the server';
          }
          if (parsed && parsed.meta && typeof parsed.meta.query_count === 'number') {
            note += ' · ' + parsed.meta.query_count + ' database ' + (parsed.meta.query_count === 1 ? 'query' : 'queries');
          }
          note += ' · ' + text.length.toLocaleString() + ' characters';
          statusEl.innerHTML = '<span class="api-status-code' + klass + '">' + response.status + ' ' + escapeHtml(response.statusText || '') + '</span>'
                             + '<span class="api-status-note">' + escapeHtml(note) + '</span>';

          headersEl.innerHTML = headerRow('Content-Type', response.headers.get('content-type'))
                              + headerRow('ETag', response.headers.get('etag'))
                              + headerRow('Cache-Control', response.headers.get('cache-control'))
                              + headerRow('X-Request-Id', response.headers.get('x-request-id'));

          var links = '';
          links += link(url, formatEl.value === 'jsonld' ? 'Open JSON-LD' : 'Open JSON');
          if (parsed && parsed.links) {
            if (parsed.links.html) { links += link(parsed.links.html, 'Record page'); }
            if (parsed.links.json_ld && formatEl.value !== 'jsonld') { links += link(parsed.links.json_ld, 'JSON-LD'); }
          } else if (parsed && parsed.url && formatEl.value === 'jsonld') {
            links += link(parsed.url, 'Record page');
          }
          linksEl.innerHTML = links;

          if (pretty.length > BODY_CAP) {
            bodyEl.textContent = pretty.slice(0, BODY_CAP) + '\n\n… ' + (pretty.length - BODY_CAP).toLocaleString()
                               + ' more characters. Open the response in a new tab for all of it.';
          } else {
            bodyEl.textContent = pretty;
          }
          submitEl.disabled = false;
        });
      }).catch(function (error) {
        statusEl.innerHTML = '<span class="api-status-code is-error">Request failed</span>'
                           + '<span class="api-status-note">' + escapeHtml(error && error.message ? error.message : 'The request did not complete.') + '</span>';
        submitEl.disabled = false;
      });
    }

    form.addEventListener('submit', run);

    if (window.URLSearchParams && new window.URLSearchParams(window.location.search).get('type')) {
      run({ preventDefault: function () {} });
    }
  }

  /* ------------------------------------------------------------------------
     Copy buttons the shell did not see at load
     ------------------------------------------------------------------------ */

  function initLateCopy() {
    var buttons = document.querySelectorAll('.api-copy:not([data-copy-bound])');
    Array.prototype.forEach.call(buttons, function (button) {
      button.setAttribute('data-copy-bound', '');
      button.addEventListener('click', function () {
        var source = byId(button.getAttribute('data-copy-target') || '');
        var text = source ? source.textContent : '';
        if (!text) { return; }
        var original = button.textContent;
        function done() {
          button.textContent = 'Copied';
          window.setTimeout(function () { button.textContent = original; }, 1600);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done).catch(function () { fallback(text, done); });
        } else {
          fallback(text, done);
        }
      });
    });
  }

  function fallback(text, done) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try { document.execCommand('copy'); done(); } catch (error) { /* nothing to do */ }
    document.body.removeChild(area);
  }

  function init() {
    if (window.MGDB && typeof window.MGDB.sectionTabs === 'function') {
      window.MGDB.sectionTabs();
    }
    initExamples();
    initTryIt();
    initLateCopy();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
