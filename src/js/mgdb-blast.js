/* ==========================================================================
   BLAST front page — /BLAST
   --------------------------------------------------------------------------
   Progressive enhancement over controllers/BLAST/BLAST.js, which is the form's
   engine and is NOT touched: it is shared with the results page and is not a
   file this repository owns. Everything here works through the DOM contract
   that script already relies on -- the same ids, the same classes, the same
   change events -- so with this file removed the form still submits.

   What it adds:
     * the shared section-tab behaviour
     * a browsable, filterable listbox over the 103-entry assembly datalist
     * a live sequence count against the page's own limits, and a warning when
       the pasted sequence disagrees with the chosen type
     * one-click quick-add buttons for the reference assembly's datasets
     * a count, an empty state and a disabled Add button, so the target step
       says what it is doing
     * a running summary of what pressing Run BLAST will do

   It also repairs two things it can reach without editing BLAST.js: a GenBank
   fetch that never appeared in a textarea the user had typed in, and an Add
   button left enabled for an assembly with no datasets.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;

  /* Same character classes BLAST.js checks on submit, so a warning here and a
     rejection there never disagree. */
  var PROTEIN_ONLY = /[EFIJLOPQXZ]/;
  var NUCLEOTIDE_ONLY = /^[ABCDGHKMNRSTUVWY-]*$/;

  var PRESET_HINTS = {
    'default': 'A balanced search. Suits most queries.',
    'high': 'Near-identical matches only: e-value 1e-20, 95% identity, best HSP per hit.',
    'low': 'Diverged matches: e-value 1, no identity floor, and a scoring scheme tolerant of mismatches.',
    'short': 'For primers, probes and other short queries: e-value 10, so a short perfect match is not discarded.'
  };

  var OUTPUT_HINTS = {
    'enhanced': 'Alignment graphics, a map of where each hit falls, and links into MaizeGDB.',
    'BLAST_table': 'One row per hit, sortable and ready to copy out.',
    'BLAST_text': 'The raw BLAST report, as the command line prints it.'
  };

  function byId(id) { return document.getElementById(id); }

  function on(el, type, fn) { if (el) { el.addEventListener(type, fn); } }

  function fire(el, type) {
    if (!el) { return; }
    el.dispatchEvent(new Event(type, { bubbles: true }));
  }

  function checkedValue(selector) {
    var el = document.querySelector(selector + ':checked');
    return el ? el.value : '';
  }

  /* ---- the sequence box ------------------------------------------------- */

  /* getCleanSequence() in BLAST.js, in the same order: def lines, then
     newlines, then digits, then whitespace. */
  function cleanSequence(text) {
    return text.toUpperCase()
      .replace(/>.*/g, '')
      .replace(/\n/g, '')
      .replace(/\d+/g, '')
      .replace(/\s/g, '');
  }

  function countSequences(text) {
    if (!text.trim()) { return 0; }
    var parts = text.split('>');
    if (parts[0] === '') { parts.shift(); }
    return parts.length;
  }

  function initSequence(summary) {
    var box = byId('query_sequence');
    if (!box) { return; }

    var countEl = byId('blast-seq-count');
    var warnEl = byId('blast-seq-warning');
    var maxQueries = parseInt(box.getAttribute('data-max-queries'), 10) || 0;
    var maxBp = parseInt(box.getAttribute('data-max-bp'), 10) || 0;

    function plural(n, one, many) { return n === 1 ? one : many; }

    function update() {
      var raw = box.value;
      var seqs = countSequences(raw);
      var bp = cleanSequence(raw).length;
      var over = (maxQueries && seqs > maxQueries) || (maxBp && bp > maxBp);

      if (countEl) {
        countEl.textContent = raw.trim()
          ? seqs + ' ' + plural(seqs, 'sequence', 'sequences') + ' · '
            + bp.toLocaleString() + ' of ' + maxBp.toLocaleString() + ' bp'
          : '';
        countEl.classList.toggle('is-over', !!over);
      }

      if (warnEl) {
        var wanted = checkedValue('.query_seq_type');
        var seq = cleanSequence(raw);
        var message = '';
        if (over) {
          message = seqs > maxQueries
            ? 'That is ' + seqs + ' sequences. The limit is ' + maxQueries + '.'
            : 'That is ' + bp.toLocaleString() + ' bp. The limit is ' + maxBp.toLocaleString() + '.';
        } else if (seq.length > 20 && wanted === 'nucleotide' && PROTEIN_ONLY.test(seq)) {
          message = 'This looks like protein sequence. Switch the type above, or the search will be rejected.';
        } else if (seq.length > 40 && wanted === 'protein' && NUCLEOTIDE_ONLY.test(seq)) {
          message = 'This looks like nucleotide sequence. Switch the type above, or the search will be rejected.';
        }
        warnEl.textContent = message;
        warnEl.hidden = !message;
      }

      summary();
    }

    on(box, 'input', update);

    /* getGenbankSequence() writes the fetched FASTA with jQuery's .text(),
       which sets the textarea's content rather than its value. For a textarea
       the user has already typed in, the value no longer tracks the content,
       so the fetched sequence arrives invisibly and the old value is what gets
       submitted. Nothing but a script changes a textarea's text content, so
       any mutation here is that write, and copying it across is safe. */
    if (window.MutationObserver) {
      new window.MutationObserver(function () {
        if (box.value !== box.textContent) {
          box.value = box.textContent;
          update();
        }
      }).observe(box, { childList: true, characterData: true, subtree: true });
    }

    var clear = byId('blast-clear-sequence');
    on(clear, 'click', function () {
      box.value = '';
      fire(box, 'input');
      box.focus();
    });

    /* The example button and the type radios both change what the counter and
       the warning should say, and neither raises an input event on the box. */
    Array.prototype.forEach.call(document.querySelectorAll('.query_seq_type'), function (radio) {
      on(radio, 'change', update);
    });
    Array.prototype.forEach.call(document.querySelectorAll('[onclick*="addExample"]'), function (btn) {
      on(btn, 'click', function () { window.setTimeout(update, 0); });
    });

    update();
  }

  /* ---- the assembly combobox -------------------------------------------- */

  /* Zea mays ssp. mays alone has 103 assemblies. A datalist shows them only
     once you have typed a prefix, and gives no way to browse or to see how
     many there are, which is the whole difficulty with the names -- they are
     Zm-<accession>-<SOURCE>-<version> and nobody remembers the middle. This is
     the same list as a panel that can be opened empty, filtered anywhere in
     the string, and walked with the arrow keys.

     The datalist stays in the DOM and stays the source of truth, because
     fillAssemblies() empties and refills it. The `list` attribute comes off
     the input so the browser's own popup does not fight this one; with the
     script absent the attribute stays and the datalist behaves as before. */
  function initCombo() {
    var wrap = document.querySelector('[data-blast-combo]');
    if (!wrap) { return; }

    var input = byId('BLAST_target_assembly');
    var datalist = byId('BLAST_target_assembly_datalist');
    if (!input || !datalist) { return; }

    input.removeAttribute('list');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-autocomplete', 'list');

    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'blast-combo-toggle';
    toggle.setAttribute('aria-label', 'Browse assemblies');
    toggle.setAttribute('tabindex', '-1');
    toggle.innerHTML = '▾';
    wrap.appendChild(toggle);

    var panel = document.createElement('div');
    panel.className = 'blast-combo-panel';
    panel.id = 'blast-assembly-listbox';
    panel.setAttribute('role', 'listbox');
    wrap.appendChild(panel);
    input.setAttribute('aria-controls', panel.id);

    var names = [];
    var shown = [];
    var active = -1;

    function readDatalist() {
      names = Array.prototype.map.call(datalist.options, function (o) {
        return o.value || o.textContent;
      });
    }

    function escapeHtml(s) {
      return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function render(filter) {
      var needle = (filter || '').trim().toLowerCase();
      shown = needle
        ? names.filter(function (n) { return n.toLowerCase().indexOf(needle) !== -1; })
        : names.slice();
      active = -1;

      var head = '<div class="blast-combo-count">'
               + (needle ? shown.length + ' of ' + names.length : names.length + ' assemblies')
               + '</div>';

      if (!shown.length) {
        panel.innerHTML = head + '<p class="blast-combo-empty">No assembly matches that.</p>';
        return;
      }

      panel.innerHTML = head + shown.map(function (name) {
        var label = escapeHtml(name);
        if (needle) {
          var at = name.toLowerCase().indexOf(needle);
          label = escapeHtml(name.slice(0, at))
                + '<mark>' + escapeHtml(name.slice(at, at + needle.length)) + '</mark>'
                + escapeHtml(name.slice(at + needle.length));
        }
        return '<button type="button" class="blast-combo-option" role="option"'
             + ' aria-selected="false" data-value="' + escapeHtml(name) + '">' + label + '</button>';
      }).join('');
    }

    function open(filter) {
      readDatalist();
      if (!names.length) { return; }
      render(filter);
      wrap.classList.add('is-open');
      input.setAttribute('aria-expanded', 'true');
    }

    function close() {
      wrap.classList.remove('is-open');
      input.setAttribute('aria-expanded', 'false');
      active = -1;
    }

    function options() { return panel.querySelectorAll('.blast-combo-option'); }

    function highlight(index) {
      var opts = options();
      if (!opts.length) { return; }
      if (index < 0) { index = opts.length - 1; }
      if (index >= opts.length) { index = 0; }
      Array.prototype.forEach.call(opts, function (o, i) {
        var is = i === index;
        o.classList.toggle('is-active', is);
        o.setAttribute('aria-selected', is ? 'true' : 'false');
        if (is) { o.scrollIntoView({ block: 'nearest' }); }
      });
      active = index;
    }

    /* Setting the value is not enough: fillTargets() is wired to the input's
       change event, and assigning .value never raises one. */
    function choose(value) {
      input.value = value;
      close();
      fire(input, 'change');
      input.focus();
    }

    on(input, 'input', function () { open(input.value); });
    on(input, 'focus', function () { if (!input.value) { open(''); } });
    on(toggle, 'click', function () {
      if (wrap.classList.contains('is-open')) { close(); return; }
      open(input.value);
      input.focus();
    });

    on(input, 'keydown', function (event) {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (!wrap.classList.contains('is-open')) { open(input.value); highlight(0); return; }
        highlight(active + (event.key === 'ArrowDown' ? 1 : -1));
      } else if (event.key === 'Enter') {
        var opts = options();
        if (wrap.classList.contains('is-open') && active > -1 && opts[active]) {
          event.preventDefault();
          choose(opts[active].getAttribute('data-value'));
        }
      } else if (event.key === 'Escape') {
        if (wrap.classList.contains('is-open')) { event.stopPropagation(); close(); }
      }
    });

    on(panel, 'click', function (event) {
      var option = event.target.closest ? event.target.closest('.blast-combo-option') : null;
      if (option) { choose(option.getAttribute('data-value')); }
    });

    on(document, 'click', function (event) {
      if (!wrap.contains(event.target)) { close(); }
    });

    /* fillAssemblies() refills the datalist whenever the species changes. */
    if (window.MutationObserver) {
      new window.MutationObserver(function () {
        readDatalist();
        if (wrap.classList.contains('is-open')) { render(input.value); }
      }).observe(datalist, { childList: true });
    }

    readDatalist();
  }

  /* ---- the dataset select and the Add button ---------------------------- */

  /* fillTargets() enables the Add button before it knows whether the assembly
     has any datasets, and some do not -- B73 RefGen_v4 returns none. Pressing
     Add then posts an empty id. The observer runs after the whole callback, so
     what it sees is the finished state. */
  function initAddGuard(summary) {
    var select = byId('BLAST_target');
    var button = byId('select_target');
    if (!select || !button || !window.MutationObserver) { return; }

    var guarding = false;

    function sync() {
      if (guarding) { return; }
      var empty = select.options.length === 0;
      if (empty && !button.disabled) {
        guarding = true;
        button.disabled = true;
        window.setTimeout(function () { guarding = false; }, 0);
      }
      button.title = empty ? 'This assembly has no BLAST datasets' : '';
      summary();
    }

    new window.MutationObserver(sync).observe(select, { childList: true });
    new window.MutationObserver(sync).observe(button, { attributes: true, attributeFilter: ['disabled'] });
    sync();
  }

  /* ---- selected targets: count, empty state, quick add ------------------ */

  function initTargets(summary) {
    var table = byId('selected_targets');
    if (!table) { return; }

    var countEl = byId('blast-target-count');
    var emptyEl = byId('blast-target-empty');
    var quickChips = document.querySelectorAll('.blast-quick-chip');

    function selectedIds() {
      return Array.prototype.map.call(
        document.querySelectorAll('.selected_BLAST_target'),
        function (row) { return row.id; });
    }

    function sync() {
      var ids = selectedIds();
      if (countEl) {
        countEl.textContent = ids.length
          ? ids.length + (ids.length === 1 ? ' dataset' : ' datasets')
          : '';
      }
      if (emptyEl) { emptyEl.hidden = ids.length > 0; }
      Array.prototype.forEach.call(quickChips, function (chip) {
        var added = ids.indexOf(chip.getAttribute('data-blast-id')) !== -1;
        chip.setAttribute('aria-pressed', added ? 'true' : 'false');
      });
      summary();
    }

    /* The same row addTarget() builds, without the round trip: the id and the
       label are already known server-side, and the endpoint would only return
       what is in the button's own data attributes. */
    Array.prototype.forEach.call(quickChips, function (chip) {
      on(chip, 'click', function () {
        var id = chip.getAttribute('data-blast-id');
        var label = chip.getAttribute('data-blast-label');
        var existing = byId(id);
        if (existing && existing.classList.contains('selected_BLAST_target')) {
          existing.parentNode.removeChild(existing);
          sync();
          return;
        }
        var row = document.createElement('tr');
        row.id = id;
        row.className = 'selected_BLAST_target';
        row.innerHTML = '<td class="BLAST"></td>'
                      + '<td><a href="#!"><b>X</b></a></td>';
        row.querySelector('td').textContent = label;
        row.querySelector('a').setAttribute('onclick', 'removeTarget(' + id + ')');
        table.appendChild(row);
        sync();
      });
    });

    if (window.MutationObserver) {
      new window.MutationObserver(sync).observe(table, { childList: true, subtree: true });
    }
    sync();
  }

  /* ---- the running summary ---------------------------------------------- */

  function initSummary() {
    var summaryEl = byId('blast-run-summary');
    var presetHint = byId('blast-preset-hint');
    var outputHint = byId('blast-output-hint');

    function update() {
      var type = checkedValue('.query_seq_type');
      var preset = checkedValue('.param_set');
      var format = checkedValue('.output_format');
      var targets = document.querySelectorAll('.selected_BLAST_target').length;
      var box = byId('query_sequence');
      var hasSequence = box && box.value.trim() !== '';

      if (presetHint) { presetHint.textContent = PRESET_HINTS[preset] || ''; }
      if (outputHint) { outputHint.textContent = OUTPUT_HINTS[format] || ''; }

      if (!summaryEl) { return; }

      var missing = [];
      if (!hasSequence) { missing.push('a sequence'); }
      if (!targets) { missing.push('a target dataset'); }

      if (missing.length) {
        summaryEl.innerHTML = 'Add ' + missing.join(' and ') + ' to run a search.';
        return;
      }

      var presetLabel = document.querySelector('.param_set:checked + label');
      var formatLabel = document.querySelector('.output_format:checked + label');
      summaryEl.innerHTML =
        '<strong>' + (type === 'protein' ? 'Protein' : 'Nucleotide') + '</strong> query against <strong>'
        + targets + (targets === 1 ? '</strong> dataset' : '</strong> datasets')
        + ' · ' + (presetLabel ? presetLabel.textContent : preset) + ' parameters'
        + ' · ' + (formatLabel ? formatLabel.textContent.toLowerCase() : format) + ' output';
    }

    Array.prototype.forEach.call(
      document.querySelectorAll('.query_seq_type, .param_set, .output_format'),
      function (radio) { on(radio, 'change', update); });

    return update;
  }

  function init() {
    if (MGDB && MGDB.sectionTabs) {
      /* The tab bar is markup the shared shell styles; nothing in that
         stylesheet moves the active state. `watch` is the form, because a
         disclosure opening or a target chip appearing moves every section
         below it. */
      MGDB.sectionTabs({ watch: '#blastform' });
    }

    var summary = initSummary();
    initSequence(summary);
    initCombo();
    initAddGuard(summary);
    initTargets(summary);
    summary();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}(window, document));
