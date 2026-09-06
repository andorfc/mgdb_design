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
     * a gold panel of "+" buttons for whichever assembly is chosen, rebuilt
       from the assembly's own datasets instead of a dropdown and an Add
       button -- see initDatasetChips() below
     * a running summary of what pressing Run BLAST will do

   It also repairs one thing it can reach without editing BLAST.js: a GenBank
   fetch that never appeared in a textarea the user had typed in.
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

  /* ---- the dataset chips -------------------------------------------------

     There is no dataset dropdown or Add button any more. #BLAST_target is
     still a real <select> -- fillTargets() in BLAST.js still empties and
     refills it exactly as before -- it is just hidden, and this module reads
     its <option>s back out as the assembly's available datasets, rendering
     them as "+" buttons in the gold panel instead of asking the reader to
     pick from a dropdown and press a separate button. Clicking a chip adds or
     removes the row addTarget() would have built, without the round trip:
     the id and target type are already known from the option itself.

     fillTargets() calls $('#BLAST_target').empty() synchronously and refills
     it only after its POST resolves, so the select mutates twice per assembly
     change -- once empty, once populated. Re-rendering on every mutation keeps
     the panel honest without extra bookkeeping; the only thing worth
     debouncing is the "no datasets for this assembly" message, so it does not
     flash during that round trip for the common case where the assembly does
     have datasets. -------------------------------------------------------- */
  function initDatasetChips(summary) {
    var assemblyInput = byId('BLAST_target_assembly');
    var select = byId('BLAST_target');
    var quick = document.querySelector('.blast-quick');
    var table = byId('selected_targets');
    if (!assemblyInput || !select || !quick || !table) { return; }

    var label = byId('blast-quick-label');
    var row = quick.querySelector('.blast-quick-row');
    var emptyEl = byId('blast-quick-empty');
    var countEl = byId('blast-target-count');
    var targetsEmptyEl = byId('blast-target-empty');
    /* The reference assembly's name, so a later pick can tell whether it is
       still "the current reference" or just some other assembly. Read from a
       data attribute rather than assumed, since the page already knows it. */
    var curRef = quick.getAttribute('data-cur-ref') || '';
    var emptyTimer = null;

    function escapeAttr(s) { return s.replace(/&/g, '&amp;').replace(/"/g, '&quot;'); }
    function escapeHtml(s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    function selectedIds() {
      return Array.prototype.map.call(
        document.querySelectorAll('.selected_BLAST_target'),
        function (r) { return r.id; });
    }

    function markPressed() {
      var ids = selectedIds();
      Array.prototype.forEach.call(row.querySelectorAll('.blast-quick-chip'), function (chip) {
        var pressed = ids.indexOf(chip.getAttribute('data-blast-id')) !== -1;
        chip.setAttribute('aria-pressed', pressed ? 'true' : 'false');
      });
    }

    function targetsSync() {
      var ids = selectedIds();
      if (countEl) {
        countEl.textContent = ids.length
          ? ids.length + (ids.length === 1 ? ' dataset' : ' datasets')
          : '';
      }
      if (targetsEmptyEl) { targetsEmptyEl.hidden = ids.length > 0; }
      markPressed();
      summary();
    }

    /* Rebuilds the label and the chip row from whatever #BLAST_target holds
       right now. Never touches the panel while the assembly field is blank,
       so the server-rendered default (the reference assembly) is what a
       reader sees before they have picked anything at all. */
    function renderChips() {
      var assemblyName = assemblyInput.value.trim();
      if (!assemblyName) { return; }

      label.textContent = assemblyName + (assemblyName === curRef ? ' — the current reference' : '');

      var options = Array.prototype.slice.call(select.options);
      if (options.length) {
        row.innerHTML = options.map(function (option) {
          var id = option.value;
          var type = option.text;
          var chipLabel = assemblyName + '-' + type;
          return '<button type="button" class="blast-quick-chip" aria-pressed="false"'
               + ' data-blast-id="' + escapeAttr(id) + '" data-blast-label="' + escapeAttr(chipLabel) + '">'
               + escapeHtml(type) + '</button>';
        }).join('');
        markPressed();
        if (emptyEl) { emptyEl.hidden = true; }
      } else {
        row.innerHTML = '';
      }

      window.clearTimeout(emptyTimer);
      if (!options.length) {
        emptyTimer = window.setTimeout(function () {
          if (!select.options.length && assemblyInput.value.trim() === assemblyName && emptyEl) {
            emptyEl.hidden = false;
          }
        }, 350);
      }

      summary();
    }

    /* Delegated, because the row's own buttons are replaced wholesale on
       every assembly change -- listeners attached to them would not survive. */
    on(row, 'click', function (event) {
      var chip = event.target.closest ? event.target.closest('.blast-quick-chip') : null;
      if (!chip) { return; }
      var id = chip.getAttribute('data-blast-id');
      var chipLabel = chip.getAttribute('data-blast-label');
      var existing = byId(id);
      if (existing && existing.classList.contains('selected_BLAST_target')) {
        existing.parentNode.removeChild(existing);
        targetsSync();
        return;
      }
      var tr = document.createElement('tr');
      tr.id = id;
      tr.className = 'selected_BLAST_target';
      tr.innerHTML = '<td class="BLAST"></td><td><a href="#!"><b>X</b></a></td>';
      tr.querySelector('td').textContent = chipLabel;
      tr.querySelector('a').setAttribute('onclick', 'removeTarget(' + id + ')');
      table.appendChild(tr);
      targetsSync();
    });

    if (window.MutationObserver) {
      new window.MutationObserver(renderChips).observe(select, { childList: true });
      new window.MutationObserver(targetsSync).observe(table, { childList: true, subtree: true });
    }

    targetsSync();
  }

  /* ---- the running summary ---------------------------------------------- */

  function initSummary() {
    var summaryEl = byId('blast-run-summary');
    var presetHint = byId('blast-preset-hint');

    function update() {
      var type = checkedValue('.query_seq_type');
      var preset = checkedValue('.param_set');
      var targets = document.querySelectorAll('.selected_BLAST_target').length;
      var box = byId('query_sequence');
      var hasSequence = box && box.value.trim() !== '';

      if (presetHint) { presetHint.textContent = PRESET_HINTS[preset] || ''; }

      if (!summaryEl) { return; }

      var missing = [];
      if (!hasSequence) { missing.push('a sequence'); }
      if (!targets) { missing.push('a target dataset'); }

      if (missing.length) {
        summaryEl.innerHTML = 'Add ' + missing.join(' and ') + ' to run a search.';
        return;
      }

      /* No output format in the line any more: the step that chose one went on
         2026-09-05, because the results page renders every view from one
         `-outfmt 15` search. The hidden radio that survives is for
         controllers/BLAST/BLAST.js, not for the reader. */
      var presetLabel = document.querySelector('.param_set:checked + label');
      summaryEl.innerHTML =
        '<strong>' + (type === 'protein' ? 'Protein' : 'Nucleotide') + '</strong> query against <strong>'
        + targets + (targets === 1 ? '</strong> dataset' : '</strong> datasets')
        + ' · ' + (presetLabel ? presetLabel.textContent : preset) + ' parameters';
    }

    Array.prototype.forEach.call(
      document.querySelectorAll('.query_seq_type, .param_set'),
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
    initDatasetChips(summary);
    summary();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}(window, document));
