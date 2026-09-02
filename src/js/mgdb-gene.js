/* file: mgdb-gene.js
 *
 * purpose: behavior for the Gene Data Hub (/gene_center/gene).
 *
 *   - simple and advanced search against search/gene/gene_search_api.php
 *   - the three-way region form, whose action and method change with the
 *     selected radio, exactly as gene.js setRegion() did
 *   - client-side validation for the BLAST, bulk position, score and
 *     download-all forms, matching the alerts of the previous page
 *   - the three Plotly figures
 *
 * Every form still posts the same field names to the same endpoint as before,
 * so nothing downstream of this page had to change.
 *
 * Bauplan's includeScript emits into <head>, so the entry point waits for
 * DOMContentLoaded or every query below returns nothing.
 */

(function () {
  'use strict';

  var API = '/search/gene/gene_search_api.php';

  var EXAMPLES = {
    mixed: 'Zm00001eb014280\nZm00001eb165610_T002\nZm00001eb220660\nZm00001eb284010_T003\nZm00001eb411380_P001',
    models: 'Zm00001eb014280\nZm00001eb067740\nZm00001eb165610\n',
    positions: 'Chr3:1349161..1545106\nChr3:1851542..1980827\nChr3:7136721..7414401\nChr3:124200581..124666812',
    scores: 'Zm00001eb014280\nZm00001eb067740\nZm00001eb165610'
  };

  function byId(id) { return document.getElementById(id); }

  function esc(value) {
    if (value === null || value === undefined || value === '') { return ''; }
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function num(value) { return Number(value || 0).toLocaleString(); }

  function readJson(id) {
    var el = byId(id);
    if (!el) { return null; }
    try { return JSON.parse(el.textContent || 'null'); }
    catch (error) { return null; }
  }

  /* ======================================================================
     Simple search
     ====================================================================== */

  var lastQuery = '';

  function searchParams(extra) {
    var params = new URLSearchParams();
    var term = byId('gene-search-term');
    var limit = byId('gene-search-limit');

    params.set('term', term ? term.value.trim() : '');
    if (limit && limit.value) { params.set('limit', limit.value); }
    if (extra && extra.broad) { params.set('broad', '1'); }
    return params;
  }

  function setStatus(text) {
    var el = byId('gene-results-status');
    if (el) { el.textContent = text; }
  }

  function showError(target, message) {
    var el = byId(target);
    if (el) {
      el.innerHTML = '<div class="mgdb-message mgdb-message-error" role="alert">' + esc(message) + '</div>';
    }
  }

  function runSearch(options) {
    var term = byId('gene-search-term');
    if (!term || term.value.trim() === '') {
      setStatus('Enter a locus name or an identifier to search.');
      return;
    }

    var params = searchParams(options);
    var results = byId('gene-results');
    var notes = byId('gene-notes');
    var empty = byId('gene-empty');
    var exportLink = byId('gene-export-tsv');

    notes.innerHTML = '';
    empty.hidden = true;
    if (exportLink) { exportLink.hidden = true; }
    results.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span>Searching gene models&hellip;</div>';
    setStatus('Searching…');

    lastQuery = params.toString();

    fetch(API + '?' + lastQuery, { credentials: 'same-origin' })
      .then(function (response) {
        return response.json().then(function (data) { return { ok: response.ok, data: data }; });
      })
      .then(function (wrap) {
        if (!wrap.ok || !wrap.data || !wrap.data.ok) {
          var message = (wrap.data && (wrap.data.message || wrap.data.detail)) || 'The search could not be completed.';
          results.innerHTML = '';
          showError('gene-notes', message);
          setStatus('Search failed.');
          return;
        }
        renderSearch(wrap.data);
      })
      .catch(function () {
        results.innerHTML = '';
        showError('gene-notes', 'The search request failed. Please try again.');
        setStatus('Search failed.');
      });
  }

  function modelTable(rows, caption) {
    var html = '<div class="mgdb-table-scroll"><table class="mgdb-table" data-sortable>'
      + '<caption>' + esc(caption) + '</caption><thead><tr>'
      + '<th scope="col" data-sort="text"><button type="button">Gene model</button></th>'
      + '<th scope="col" data-sort="text"><button type="button">Annotation</button></th>'
      + '<th scope="col" data-sort="text"><button type="button">Line</button></th>'
      + '<th scope="col" data-sort="text"><button type="button">Locus</button></th>'
      + '<th scope="col" data-sort="text"><button type="button">Type</button></th>'
      + '<th scope="col" data-sort="text"><button type="button">Position</button></th>'
      + '<th scope="col" data-sort="number" class="mgdb-numeric"><button type="button">Transcripts</button></th>'
      + '</tr></thead><tbody>';

    rows.forEach(function (row) {
      var position = row.chromosome
        ? esc(row.chromosome) + (row.start ? '&#58;' + num(row.start) + '&ndash;' + num(row.end) : '')
        : '';

      html += '<tr>'
        + '<th scope="row"><a class="gene-model-id" href="' + esc(row.url) + '">' + esc(row.gene_model) + '</a></th>'
        + '<td>' + esc(row.annotation) + '</td>'
        + '<td>' + esc(row.line) + '</td>'
        + '<td>' + (row.locus_name
            ? '<a href="/data_center/locus?id=' + esc(row.locus_id) + '"><i>' + esc(row.locus_name) + '</i></a>'
            : '<span class="mgdb-muted">&mdash;</span>') + '</td>'
        + '<td>' + esc(row.model_type) + '</td>'
        + '<td class="gene-coords">' + position + '</td>'
        + '<td class="mgdb-numeric" data-value="' + (row.transcripts || 0) + '">'
          + (row.transcripts ? num(row.transcripts) : '') + '</td>'
        + '</tr>';
    });

    return html + '</tbody></table></div>';
  }

  function locusTable(rows) {
    var html = '<div class="mgdb-table-scroll"><table class="mgdb-table" data-sortable>'
      + '<caption>Gene loci</caption><thead><tr>'
      + '<th scope="col" data-sort="text"><button type="button">Locus</button></th>'
      + '<th scope="col" data-sort="text"><button type="button">Full name</button></th>'
      + '<th scope="col" data-sort="number" class="mgdb-numeric"><button type="button">Gene models</button></th>'
      + '<th scope="col" data-sort="number" class="mgdb-numeric"><button type="button">Annotations</button></th>'
      + '<th scope="col" data-sort="text"><button type="button">MaizeGDB ID</button></th>'
      + '</tr></thead><tbody>';

    rows.forEach(function (row) {
      html += '<tr>'
        + '<th scope="row"><a href="' + esc(row.url) + '"><i>' + esc(row.locus_name) + '</i></a></th>'
        + '<td>' + esc(row.full_name) + '</td>'
        + '<td class="mgdb-numeric" data-value="' + row.models + '">' + num(row.models) + '</td>'
        + '<td class="mgdb-numeric" data-value="' + row.annotations + '">' + num(row.annotations) + '</td>'
        + '<td class="gene-model-id">' + esc(row.locus_id) + '</td>'
        + '</tr>';
    });

    return html + '</tbody></table></div>';
  }

  function activateTables(container) {
    if (!window.MGDB || !window.MGDB.sortTable) { return; }
    Array.prototype.forEach.call(container.querySelectorAll('table[data-sortable]'), window.MGDB.sortTable);
  }

  function renderSearch(data) {
    var results = byId('gene-results');
    var notes = byId('gene-notes');
    var empty = byId('gene-empty');
    var exportLink = byId('gene-export-tsv');
    var summary = data.summary || {};

    var models = data.models || [];
    var loci = data.loci || [];

    if (!models.length && !loci.length) {
      results.innerHTML = '';
      empty.hidden = false;
      setStatus('No genes or gene models matched "' + summary.term + '".');
      return;
    }

    var html = '';
    if (loci.length) {
      html += '<div class="gene-result-group"><h3>Gene loci <span class="mgdb-muted">('
        + num(loci.length) + ')</span></h3>' + locusTable(loci) + '</div>';
    }
    if (models.length) {
      html += '<div class="gene-result-group"><h3>Gene models <span class="mgdb-muted">('
        + num(models.length) + ')</span></h3>'
        + modelTable(models, 'Gene models matching "' + summary.term + '"') + '</div>';
    }
    results.innerHTML = html;
    activateTables(results);

    setStatus(num(models.length) + ' gene model' + (models.length === 1 ? '' : 's')
      + ' and ' + num(loci.length) + ' gene loc' + (loci.length === 1 ? 'us' : 'i')
      + ' matched "' + summary.term + '" in ' + num(summary.elapsed_ms) + ' ms.');

    /* The scan is skipped when the reader typed a complete gene model
       identifier, because for those the index lookup is the whole answer and
       the scan costs about three quarters of a second to confirm it. Offer it
       rather than deciding for them. */
    var messages = '';
    if (summary.exact_only) {
      messages += '<div class="mgdb-message mgdb-message-info" role="note"><div>'
        + '<strong>Exact identifier match.</strong> Partial matches were not searched. '
        + '<button type="button" class="mgdb-button mgdb-button-quiet" id="gene-broaden">Search partial matches</button>'
        + '</div></div>';
    }
    if (summary.truncated) {
      messages += '<div class="mgdb-message mgdb-message-info" role="note"><div>'
        + 'More records matched than the limit of ' + num(summary.limit)
        + '. Raise the maximum, or narrow the term with <code>^</code> or <code>$</code>.'
        + '</div></div>';
    }
    notes.innerHTML = messages;

    var broaden = byId('gene-broaden');
    if (broaden) {
      broaden.addEventListener('click', function () { runSearch({ broad: true }); });
    }

    if (exportLink) {
      exportLink.href = API + '?' + lastQuery + '&format=tsv';
      exportLink.hidden = false;
    }
  }

  function initSearch() {
    var form = byId('gene-search-form');
    if (!form) { return; }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      runSearch();
    });

    var reset = byId('gene-search-reset');
    if (reset) {
      reset.addEventListener('click', function () {
        window.setTimeout(function () {
          byId('gene-results').innerHTML = '';
          byId('gene-notes').innerHTML = '';
          byId('gene-empty').hidden = true;
          var exportLink = byId('gene-export-tsv');
          if (exportLink) { exportLink.hidden = true; }
          setStatus('Enter a locus name or an identifier to search.');
        }, 10);
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.gene-example[data-term]'), function (button) {
      button.addEventListener('click', function () {
        var input = byId('gene-search-term');
        input.value = button.getAttribute('data-term');
        runSearch();
        input.focus();
      });
    });

    // ?term= in the address bar runs the search, as the previous page did with
    // its start-search block.
    if (window.URLSearchParams) {
      var initial = new window.URLSearchParams(window.location.search).get('term');
      if (initial) {
        byId('gene-search-term').value = initial;
        runSearch();
      }
    }
  }

  /* ======================================================================
     Advanced search
     ====================================================================== */

  /* Checkbox, then the controls that belong to it. Changing a control checks
     its box, which is what the previous page did with an inline onchange. */
  var ADVANCED = [
    { box: 'adv-annotation-box', key: 'annotation',   controls: { annotation: 'adv-annotation' } },
    { box: 'adv-type-box',       key: 'model_type',   controls: { model_type: 'adv-type' } },
    { box: 'adv-chr-box',        key: 'chromosome',   controls: { chromosome: 'adv-chr' } },
    { box: 'adv-range-box',      key: 'range',        controls: { range_start: 'adv-range-start', range_end: 'adv-range-end' } },
    { box: 'adv-locus-box',      key: 'locus_assoc',  controls: {} },
    { box: 'adv-product-box',    key: 'gene_product', controls: { gene_product: 'adv-product' } },
    { box: 'adv-pheno-box',      key: 'phenotype',    controls: { phenotype: 'adv-pheno' } },
    { box: 'adv-trait-box',      key: 'trait',        controls: { trait: 'adv-trait' } },
    { box: 'adv-tandem-box',     key: 'tandem',       controls: {} },
    { box: 'adv-protein-box',    key: 'protein',      controls: { protein: 'adv-protein' } }
  ];

  function runAdvanced() {
    var params = new URLSearchParams();
    params.set('mode', 'advanced');

    var limit = byId('gene-search-limit');
    if (limit && limit.value) { params.set('limit', limit.value); }

    var checked = 0;
    ADVANCED.forEach(function (entry) {
      var box = byId(entry.box);
      if (!box || !box.checked) { return; }
      checked += 1;
      params.set('use_' + entry.key, '1');
      Object.keys(entry.controls).forEach(function (field) {
        var control = byId(entry.controls[field]);
        if (control) { params.set(field, control.value); }
      });
    });

    var notes = byId('gene-advanced-notes');
    var results = byId('gene-advanced-results');

    if (checked === 0) {
      results.innerHTML = '';
      showError('gene-advanced-notes', 'Check at least one box to describe the gene models you are looking for.');
      return;
    }

    notes.innerHTML = '';
    results.innerHTML = '<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span>Searching gene models&hellip;</div>';

    var query = params.toString();

    fetch(API + '?' + query, { credentials: 'same-origin' })
      .then(function (response) {
        return response.json().then(function (data) { return { ok: response.ok, data: data }; });
      })
      .then(function (wrap) {
        if (!wrap.ok || !wrap.data || !wrap.data.ok) {
          var message = (wrap.data && (wrap.data.message || wrap.data.detail)) || 'The search could not be completed.';
          results.innerHTML = '';
          showError('gene-advanced-notes', message);
          return;
        }

        var data = wrap.data;
        var rows = data.models || [];
        var summary = data.summary || {};

        if (!rows.length) {
          results.innerHTML = '';
          notes.innerHTML = '<div class="mgdb-message mgdb-message-info" role="note"><div>'
            + esc(summary.criteria) + ' No gene models matched.</div></div>';
          return;
        }

        var head = '<div class="mgdb-message mgdb-message-info" role="note"><div>'
          + esc(summary.criteria) + ' ' + num(rows.length) + ' gene model'
          + (rows.length === 1 ? '' : 's') + ' found in ' + num(summary.elapsed_ms) + ' ms.';
        if (summary.truncated) {
          head += ' More matched than the limit of ' + num(summary.limit) + '.';
        }
        head += '</div></div>';
        notes.innerHTML = head;

        results.innerHTML = modelTable(rows, 'Advanced search results')
          + '<p class="mgdb-form-actions"><a class="mgdb-button mgdb-button-quiet" download href="'
          + esc(API + '?' + query + '&format=tsv') + '">Export TSV</a></p>';
        activateTables(results);
      })
      .catch(function () {
        results.innerHTML = '';
        showError('gene-advanced-notes', 'The search request failed. Please try again.');
      });
  }

  /* The gene product, phenotype and trait lists are 2,810 options between
     them. Rendering them into the page was 170 KB of markup for a form that
     starts collapsed, so they arrive on first open. Each select keeps its
     "Any ..." option server side, so the control is never empty and a failed
     request leaves the form usable with the any-value criterion. */
  var optionsLoaded = false;

  function loadAdvancedOptions() {
    if (optionsLoaded) { return; }
    optionsLoaded = true;

    var selects = document.querySelectorAll('[data-gene-options]');
    if (!selects.length) { return; }

    Array.prototype.forEach.call(selects, function (select) { select.disabled = true; });

    fetch(API + '?mode=options', { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.options) { throw new Error('no options'); }
        Array.prototype.forEach.call(selects, function (select) {
          var html = data.options[select.getAttribute('data-gene-options')];
          if (html) { select.insertAdjacentHTML('beforeend', html); }
          select.disabled = false;
        });
      })
      .catch(function () {
        optionsLoaded = false;
        Array.prototype.forEach.call(selects, function (select) { select.disabled = false; });
        showError('gene-advanced-notes', 'The filter lists could not be loaded. Reopen this section to try again.');
      });
  }

  function initAdvanced() {
    var form = byId('gene-advanced-form');
    if (!form) { return; }

    var disclosure = byId('gene-advanced');
    if (disclosure) {
      disclosure.addEventListener('toggle', function () {
        if (disclosure.open) { loadAdvancedOptions(); }
      });
      if (disclosure.open) { loadAdvancedOptions(); }
    }

    ADVANCED.forEach(function (entry) {
      Object.keys(entry.controls).forEach(function (field) {
        var control = byId(entry.controls[field]);
        if (!control) { return; }
        control.addEventListener('change', function () {
          var box = byId(entry.box);
          if (box) { box.checked = true; }
        });
      });
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      runAdvanced();
    });

    form.addEventListener('reset', function () {
      window.setTimeout(function () {
        byId('gene-advanced-results').innerHTML = '';
        byId('gene-advanced-notes').innerHTML = '';
      }, 10);
    });
  }

  /* ======================================================================
     Example fillers
     ====================================================================== */

  function initExampleFillers() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-gene-fill]'), function (button) {
      button.addEventListener('click', function () {
        var target = byId(button.getAttribute('data-gene-fill'));
        if (!target) { return; }
        var key = button.getAttribute('data-gene-example');
        target.value = key ? (EXAMPLES[key] || '') : (button.getAttribute('data-gene-value') || '');
        target.focus();
      });
    });
  }

  /* ======================================================================
     Search by region

     Three mutually exclusive ways to name a region, each posting to its own
     endpoint with its own method. The inactive branches are disabled so their
     fields stay out of the submitted query string, which is what the previous
     page's disabled_form_set class did.
     ====================================================================== */

  var REGION_TARGETS = {
    assembly: { action: '/search/gene/gene_chr_position.php', method: 'get' },
    marker:   { action: '/search/gene/gene_marker_position.php', method: 'get' },
    gm:       { action: '/search/gene/gene_gm_position.php', method: 'post' }
  };

  function selectedRegion() {
    var checked = document.querySelector('#gene-region-form input[name="region"]:checked');
    return checked ? checked.value : 'assembly';
  }

  function applyRegion() {
    var form = byId('gene-region-form');
    if (!form) { return; }

    var which = selectedRegion();
    var target = REGION_TARGETS[which] || REGION_TARGETS.assembly;
    form.setAttribute('action', target.action);
    form.setAttribute('method', target.method);

    Array.prototype.forEach.call(form.querySelectorAll('.gene-region-fields'), function (group) {
      var active = group.getAttribute('data-region') === which;
      group.classList.toggle('is-inactive', !active);
      Array.prototype.forEach.call(group.querySelectorAll('input, select, textarea'), function (field) {
        field.disabled = !active;
      });
    });
  }

  function initRegion() {
    var form = byId('gene-region-form');
    if (!form) { return; }

    Array.prototype.forEach.call(form.querySelectorAll('input[name="region"]'), function (radio) {
      radio.addEventListener('change', applyRegion);
    });

    // Clicking into a field selects the branch it belongs to.
    Array.prototype.forEach.call(form.querySelectorAll('.gene-region-fields'), function (group) {
      group.addEventListener('focusin', function () {
        var radio = form.querySelector('input[name="region"][value="' + group.getAttribute('data-region') + '"]');
        if (radio && !radio.checked) {
          radio.checked = true;
          applyRegion();
        }
      });
    });

    form.addEventListener('submit', function (event) {
      var which = selectedRegion();

      if (which === 'assembly') {
        var start = byId('region-start').value.trim();
        var end = byId('region-end').value.trim();
        if (start !== '' || end !== '') {
          if (start === '' || end === '') {
            event.preventDefault();
            window.alert('You need to set a start and end position or leave both fields blank to get genes/gene models for the entire chromosome.');
            return;
          }
          if (parseInt(start, 10) > parseInt(end, 10)) {
            event.preventDefault();
            window.alert('The ending coordinate is smaller than the starting coordinate.');
            return;
          }
        }
      } else if (which === 'marker') {
        if (byId('region-start-marker').value.trim() === '' && byId('region-end-marker').value.trim() === '') {
          event.preventDefault();
          window.alert('You need to enter at least one marker or BAC name.');
          return;
        }
      } else if (which === 'gm') {
        if (byId('region-gm-list').value.trim() === '') {
          event.preventDefault();
          window.alert('No gene models provided.');
          return;
        }
      }

      // A GET form would otherwise reload this page in place.
      form.setAttribute('target', '_blank');
    });

    applyRegion();
  }

  /* ======================================================================
     Form validation carried over from gene.js
     ====================================================================== */

  function requireValue(form, id, message) {
    form.addEventListener('submit', function (event) {
      var field = byId(id);
      if (!field || field.value.trim() === '') {
        event.preventDefault();
        window.alert(message);
      }
    });
  }

  function initToolForms() {
    var blast = byId('gene-blast-form');
    if (blast) {
      blast.addEventListener('submit', function (event) {
        if (byId('gene-blast-target').value === '') {
          event.preventDefault();
          window.alert('You have not selected a target.');
          return;
        }
        if (byId('gene-blast-sequence').value.trim() === '') {
          event.preventDefault();
          window.alert('You have not entered any sequence.');
        }
      });
    }

    var bulk = byId('gene-bulk-position-form');
    if (bulk) {
      bulk.addEventListener('submit', function (event) {
        if (byId('bulk-positions').value.trim() === '') {
          event.preventDefault();
          window.alert('No positions provided.');
          return;
        }
        if (byId('bulk-position-assembly').value === '') {
          event.preventDefault();
          window.alert('No assembly selected.');
        }
      });
    }

    var scores = byId('gene-scores-form');
    if (scores) { requireValue(scores, 'scores-list', 'No gene models provided.'); }

    var translate = byId('gene-translate-form');
    if (translate) { requireValue(translate, 'translate-list', 'No gene models provided.'); }

    var fasta = byId('gene-fasta-form');
    if (fasta) { requireValue(fasta, 'fasta-list', 'No gene models provided.'); }
  }

  /* ======================================================================
     Download all data for a gene model list

     Same two endpoints as before -- search/gene/download_all.php to start the
     job and search/download/checkQuery.php to poll it. The previous
     implementation re-entered its own poll with no delay, so a job that took
     a minute made thousands of requests; this one waits two seconds between
     checks and gives up after ten minutes.
     ====================================================================== */

  var POLL_INTERVAL = 2000;
  var POLL_LIMIT = 300;

  function jobId() {
    return Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15);
  }

  function statusText(html) {
    var el = byId('downloadall_status_div');
    if (el) { el.innerHTML = html; }
  }

  function pollDownload(id, filename, attempt) {
    if (attempt > POLL_LIMIT) {
      statusText('<b>Download did not complete.</b> The job is still running; try a shorter list of gene models.');
      return;
    }

    var body = new URLSearchParams();
    body.set('job_id', id);

    fetch('/search/download/checkQuery.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
      .then(function (response) { return response.text(); })
      .then(function (text) {
        var state;
        try { state = JSON.parse(text); }
        catch (error) { state = null; }

        if (!state) {
          statusText('<b>Download did not complete.</b> No status was returned.');
          return;
        }

        if (state.status === 'done') {
          statusText('<b>Download completed.</b>');
          var link = document.createElement('a');
          link.href = '/temp/' + id + '.txt';
          link.download = filename;
          link.target = '_blank';
          link.style.display = 'none';
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);
          return;
        }

        if (state.status === 'error') {
          statusText('<b>Download did not complete&#58;</b> ' + esc(state.msg || ''));
          return;
        }

        statusText('Preparing download file ' + esc(filename) + '… (' + attempt + ')');
        window.setTimeout(function () { pollDownload(id, filename, attempt + 1); }, POLL_INTERVAL);
      })
      .catch(function () {
        statusText('<b>Download failed.</b>');
      });
  }

  function initDownloadAll() {
    var form = byId('gene-downloadall-form');
    if (!form) { return; }

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var list = byId('downloadall_list');
      var fileInput = byId('downloadall_file');
      var files = fileInput ? fileInput.files : [];

      if (list.value.trim() === '' && (!files || files.length === 0)) {
        window.alert('No gene models entered. Paste a list of gene models into the box or upload a file.');
        return;
      }

      var filename = 'gene_list_download.txt';
      var id = jobId();

      var data = new FormData();
      data.append('job_id', id);
      if (list.value.trim() !== '') {
        data.append('downloadall_list', list.value);
      } else {
        for (var i = 0; i < files.length; i += 1) {
          data.append('files[]', files[i]);
        }
      }

      statusText('Preparing download file ' + esc(filename) + '…');

      fetch('/search/gene/download_all.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      })
        .then(function (response) { return response.text(); })
        .then(function (text) {
          if (text.indexOf('Success') !== -1) {
            pollDownload(id, filename, 1);
          } else {
            statusText('<b>File upload failed.</b>');
          }
        })
        .catch(function () {
          statusText('<span class="mgdb-message-error">Download failed. '
            + 'If you tried to upload a file larger than 50kb, you may want to download the full gene model '
            + 'information file from the <a href="https://download.maizegdb.org" target="_blank" rel="noopener">downloads directory</a>. '
            + 'Look inside the directory for your genome assembly of interest.</span>');
        });
    });

    form.addEventListener('reset', function () { statusText(''); });
  }

  /* ======================================================================
     Figures
     ====================================================================== */

  function fillTable(id, rows) {
    var table = byId(id);
    if (!table) { return; }
    var tbody = table.querySelector('tbody');
    if (!tbody) { return; }
    tbody.innerHTML = rows.join('');
    if (window.MGDB && window.MGDB.sortTable && table.hasAttribute('data-sortable')) {
      window.MGDB.sortTable(table);
    }
  }

  /* .mgdb-chart is a fixed 320px in the shared stylesheet -- 260px at narrow
     widths -- and .mgdb-chart-tall is not defined in any stylesheet, so a
     chart asking for more was drawn past the bottom of its own figure. The
     annotation chart wants 860px for its 35 bars and was getting 260. The
     container takes the same height that Plotly is given, from one variable. */
  function sizeChart(id, height) {
    var el = byId(id);
    if (el) { el.style.height = height + 'px'; }
    return height;
  }

  function annotationChart() {
    var data = readJson('gene-annotation-data');
    if (!data || !data.length) { return; }

    // Ascending, so the largest annotation sits at the top of a horizontal bar.
    var ordered = data.slice().sort(function (a, b) { return a.gene_models - b.gene_models; });

    fillTable('gene-annotation-table', data.map(function (row) {
      return '<tr><th scope="row">' + esc(row.annotation) + '</th>'
        + '<td>' + esc(row.line) + '</td>'
        + '<td class="mgdb-numeric" data-value="' + row.gene_models + '">' + num(row.gene_models) + '</td></tr>';
    }));

    if (!window.MGDB || !window.MGDB.chart) { return; }

    window.MGDB.chart({
      target: 'gene-annotation-chart',
      traces: function () {
        return [{
          type: 'bar',
          orientation: 'h',
          x: ordered.map(function (r) { return r.gene_models; }),
          y: ordered.map(function (r) { return r.line ? r.line + ' · ' + r.annotation : r.annotation; }),
          marker: { color: window.MGDB.CHART_COLORS[0] },
          hovertemplate: '%{y}<br>%{x:,} gene models<extra></extra>'
        }];
      },
      layout: {
        height: sizeChart('gene-annotation-chart', Math.max(420, ordered.length * 24 + 110)),
        margin: { l: 190, r: 24, t: 12, b: 48 },
        xaxis: { title: { text: 'Gene models' }, tickformat: ',d' },
        yaxis: { automargin: true, tickfont: { size: 11 } }
      }
    });
  }

  function chromosomeChart() {
    var data = readJson('gene-chromosome-data');
    if (!data || !data.bins || !data.bins.length) { return; }

    var types = data.types || [];
    var bins = data.bins;

    var table = byId('gene-chromosome-table');
    if (table) {
      var headRow = table.querySelector('thead tr');
      if (headRow) {
        headRow.innerHTML = '<th scope="col">Chromosome</th>'
          + types.map(function (t) { return '<th scope="col" class="mgdb-numeric">' + esc(t) + '</th>'; }).join('');
      }
      fillTable('gene-chromosome-table', bins.map(function (bin) {
        return '<tr><th scope="row">' + esc(bin.label) + '</th>'
          + types.map(function (t) {
              var v = bin.types[t] || 0;
              return '<td class="mgdb-numeric">' + num(v) + '</td>';
            }).join('')
          + '</tr>';
      }));
    }

    if (!window.MGDB || !window.MGDB.chart) { return; }

    window.MGDB.chart({
      target: 'gene-chromosome-chart',
      traces: function () {
        return types.map(function (type, index) {
          return {
            type: 'bar',
            name: type,
            x: bins.map(function (bin) { return bin.label; }),
            y: bins.map(function (bin) { return bin.types[type] || 0; }),
            marker: { color: window.MGDB.CHART_COLORS[index % window.MGDB.CHART_COLORS.length] },
            hovertemplate: '%{x}<br>' + type + '&#58; %{y:,}<extra></extra>'
          };
        });
      },
      layout: {
        barmode: 'stack',
        height: sizeChart('gene-chromosome-chart', 420),
        margin: { l: 70, r: 24, t: 12, b: 90 },
        xaxis: { tickangle: -35 },
        yaxis: { title: { text: 'Gene models' }, tickformat: ',d' },
        legend: { orientation: 'h', y: -0.32 }
      }
    });
  }

  function transcriptChart() {
    var data = readJson('gene-transcript-data');
    if (!data || !data.series || !data.series.length) { return; }

    var series = data.series;
    var cap = data.cap || 10;

    var label = function (row) {
      return row.capped ? cap + ' or more' : String(row.transcripts);
    };

    fillTable('gene-transcript-table', series.map(function (row) {
      return '<tr><th scope="row">' + esc(label(row)) + '</th>'
        + '<td class="mgdb-numeric">' + num(row.gene_models) + '</td></tr>';
    }));

    if (!window.MGDB || !window.MGDB.chart) { return; }

    window.MGDB.chart({
      target: 'gene-transcript-chart',
      traces: function () {
        return [{
          type: 'bar',
          x: series.map(label),
          y: series.map(function (r) { return r.gene_models; }),
          marker: { color: window.MGDB.CHART_COLORS[1 % window.MGDB.CHART_COLORS.length] },
          hovertemplate: '%{x} transcript(s)<br>%{y:,} gene models<extra></extra>'
        }];
      },
      layout: {
        height: sizeChart('gene-transcript-chart', 380),
        margin: { l: 70, r: 24, t: 12, b: 60 },
        xaxis: { title: { text: 'Transcripts per gene model' }, type: 'category' },
        yaxis: { title: { text: 'Gene models' }, tickformat: ',d' }
      }
    });
  }

  /* ======================================================================
     Scrollspy for the section tab bar
     ====================================================================== */

  /* The shared section-tab behaviour: a wrapping bar, aria-current, and a
     click hold released by real scrolling. The rail-scrolling version this
     replaced existed because thirteen tabs could not wrap; nine can. */
  function buildTabs() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length) { return; }

    var pairs = [];
    Array.prototype.forEach.call(tabs, function (tab) {
      var href = tab.getAttribute('href') || '';
      if (href.charAt(0) !== '#') { return; }
      var section = document.getElementById(href.slice(1));
      if (section) { pairs.push({ tab: tab, section: section }); }
    });
    if (!pairs.length) { return; }

    var heldUntilScroll = null;
    var heldAtY = 0;

    function mark(section) {
      pairs.forEach(function (pair) {
        var current = pair.section === section;
        pair.tab.classList.toggle('is-current', current);
        if (current) { pair.tab.setAttribute('aria-current', 'true'); }
        else { pair.tab.removeAttribute('aria-current'); }
      });
    }

    function triggerLine() {
      var bar = document.querySelector('.mgdb-section-tabs');
      var barHeight = bar ? bar.getBoundingClientRect().height : 0;
      var margin = parseFloat(window.getComputedStyle(pairs[0].section).scrollMarginTop) || 0;
      return Math.max(barHeight + 8, margin + 4);
    }

    function update() {
      if (heldUntilScroll) {
        if (Math.abs(window.scrollY - heldAtY) < 4) { return; }
        heldUntilScroll = null;
      }
      var line = triggerLine();
      var current = pairs[0];
      pairs.forEach(function (pair) {
        if (pair.section.hasAttribute('hidden')) { return; }
        if (pair.section.getBoundingClientRect().top <= line) { current = pair; }
      });
      if ((window.innerHeight + window.scrollY) >= (document.documentElement.scrollHeight - 2)) {
        current = pairs[pairs.length - 1];
      }
      if (current) { mark(current.section); }
    }

    pairs.forEach(function (pair) {
      pair.tab.addEventListener('click', function () {
        mark(pair.section);
        heldUntilScroll = pair.section;
        heldAtY = window.scrollY;
      });
    });

    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);

    if (window.IntersectionObserver) {
      var observer = new window.IntersectionObserver(function () { update(); },
        { rootMargin: '-20% 0px -60% 0px' });
      pairs.forEach(function (pair) { observer.observe(pair.section); });
    }

    update();
  }

  /* ====================================================================== */

  function init() {
    initSearch();
    initAdvanced();
    initExampleFillers();
    initRegion();
    initToolForms();
    initDownloadAll();
    annotationChart();
    chromosomeChart();
    transcriptChart();
    buildTabs();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
