/* ==========================================================================
   BLAST results — /BLAST?job_id={job}
   --------------------------------------------------------------------------
   A discovery interface over one BLAST run. The search is executed once with
   `-outfmt 15`; the table, text, enhanced and discovery views are all
   renderings of that single report, so switching view never re-runs anything.

   Loading is staged so the page is useful before it is complete:
     1  summary        no database at all — paints immediately
     2  hit rows       no database either
     3  annotation     gene / pan-gene / links, batched, filled in afterwards
   Nothing in stage 3 is allowed to block stages 1 and 2.

   The coverage and chromosome figures are hand-drawn SVG rather than Plotly.
   They are categorical row plots with one rect per hit, and at a few thousand
   rows a rect-per-row SVG stays interactive where a Plotly redraw does not.
   The identity-versus-coverage scatter IS Plotly, through MGDB.chart, because
   it is a real scatter and gets pan, zoom and hover for free.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var API = '/BLAST/blast_results_api.php';

  /* Every request carries the query index. A multi-FASTA submission is several
     independent BLAST results in one report, and omitting this silently served
     the first — a two-sequence job lost 28 of its 61 hits with nothing on
     screen to say so. */
  function api(view, extra) {
    return API + '?job=' + encodeURIComponent(state.job) +
           '&view=' + encodeURIComponent(view) +
           (state.q ? '&q=' + encodeURIComponent(state.q) : '') +
           (extra || '');
  }

  /* How many hits the coverage figure draws before it stops being a figure and
     starts being a smear. The control offers 10/25/50; 50 rows at 18px is about
     one screen. */
  var COVERAGE_STEPS = [10, 25, 50];

  /* Annotation cost is dominated by the round trip, not the row count —
     measured 0.29 s for 50 rows and 0.35 s for 500 — so ask for a full batch
     and keep asking until the visible rows are covered. 500 is the endpoint's
     own per-request ceiling.

     ENRICH_MAX bounds the total: a repetitive search can return 11,000 rows and
     annotating all of them is 22 round trips nobody is waiting for. Whatever is
     left over is reported as UNANNOTATED rather than quietly treated as absent
     — the previous 200-row cap made the pan-genome matrix assert that a gene
     was missing from assemblies where BLAST had matched it at 98–100%. */
  var ENRICH_BATCH = 500;
  var ENRICH_MAX = 2000;

  var state = {
    job: null,
    summary: null,
    unit: 'locus',
    rows: [],          /* every row, as served                        */
    view: [],          /* rows surviving the filters, in sort order   */
    annotations: {},   /* key -> annotation, filled in progressively  */
    panGenes: {},
    selected: null,
    sort: { column: 'bit_score_total', dir: 'desc' },
    filters: { text: '', identity: 0, coverage: 0, evalue: null, chr: '', target: '' },
    coverageTop: 25,
    /* A single-target job reads as a flat list. A multi-target one does not:
       six assemblies return six near-identical rows for the same gene, and the
       useful question is "which assemblies have it", not "here are 176 hits".
       Grouping is therefore chosen by job shape, and only once annotation has
       arrived — before that there are no pan-genes to group on. */
    grouping: 'none',
    multi: false,
    targets: [],
    view_mode: 'discovery',
    rowsLoaded: false,
    drawerOpener: null,
    q: 0,
    queries: [],
    domains: [],
    domainSource: null,
    matrixMetric: 'identity',
    matrixScope: 'auto',
    matrixExpanded: false,
    hasMatrix: false,
    annotating: false,
    annotatedCount: 0,
    annotationCapped: false,
    attempted: {},
    unknownByAssembly: {},
    total: 0,
    scatterWatch: null,
    hasGenomicSubjects: false,
    hasGroups: false,
    hasPanGenes: false
  };

  var els = {};

  /* ------------------------------------------------------------------------
     Small helpers
     ------------------------------------------------------------------------ */

  function esc(s) { return MGDB.escapeHtml ? MGDB.escapeHtml(String(s)) : String(s); }

  function num(n, digits) {
    if (n === null || n === undefined) { return '—'; }
    return Number(n).toFixed(digits === undefined ? 1 : digits);
  }

  /* BLAST reports an e-value of exactly 0 for a perfect match; "0.0" is how
     every BLAST report in the world prints it, so a reader expects that and
     not "0e+0". */
  function evalue(e) {
    if (e === null || e === undefined) { return '—'; }
    if (e === 0) { return '0.0'; }
    if (e >= 0.001 && e < 1000) { return String(Number(e.toPrecision(2))); }
    return e.toExponential(0).replace('e', 'e');
  }

  function commas(n) { return Number(n).toLocaleString('en-US'); }

  /* The quality ramp, five steps. Identity alone is misleading — a 100%
     identical 40 bp fragment is not a better match than a 95% identical
     full-length one — so the ramp is driven by identity AND coverage together. */
  function qualityStep(row) {
    var score = (row.pident / 100) * Math.min(1, row.q_coverage / 100);
    if (score >= 0.90) { return 4; }
    if (score >= 0.70) { return 3; }
    if (score >= 0.45) { return 2; }
    if (score >= 0.20) { return 1; }
    return 0;
  }

  var RAMP = ['#eef4f8', '#c5dcea', '#8ebdd6', '#4f96bd', '#0072b2'];

  /* Edge colors for the same five steps. Each is dark enough to read against
     the pale track behind the bars, so the extent of a match is visible even
     when its fill is nearly the color of the track. */
  var RAMP_EDGE = ['#587a8c', '#4a7d9c', '#3f7fa3', '#2c6f96', '#005c8f'];

  function rowLabel(row) {
    var ann = state.annotations[row.key];
    if (ann && ann.locus) { return ann.locus; }
    if (ann && ann.gene_model) { return ann.gene_model; }
    return row.subject;
  }

  function rowPosition(row) {
    if (row.h_start === undefined || row.h_start === null) { return null; }
    return row.subject + ':' + commas(row.h_start) + '–' + commas(row.h_end);
  }

  /* ------------------------------------------------------------------------
     Stage 1 — summary
     ------------------------------------------------------------------------ */

  /* A submission redirects here immediately, so the first request usually
     arrives while BLAST is still running. Poll rather than treating that as an
     error — the previous behavior showed "these results could not be loaded"
     for the whole duration of every search. Backs off from 1 s to 5 s so a long
     job does not hammer the endpoint. */
  var POLL_MIN = 1000, POLL_MAX = 5000;

  function pollPending(data) {
    var n = data.targets || 0;
    var done = data.finished || 0;
    if (els.reading) {
      els.reading.textContent = 'Running your search…';
      els.readingDetail.textContent = n
        ? done + ' of ' + n + ' ' + (n === 1 ? 'target' : 'targets') + ' finished.'
        : 'Waiting for BLAST to start.';
    }
    if (els.tableBody) {
      els.tableBody.innerHTML =
        '<tr><td colspan="12" class="blast-pending">Running your search&hellip;</td></tr>';
    }
    state.pollDelay = Math.min(POLL_MAX, (state.pollDelay || POLL_MIN) * 1.4);
    return new Promise(function (resolve) {
      window.setTimeout(function () { resolve(loadSummary()); }, state.pollDelay);
    });
  }

  function loadSummary() {
    return MGDB.request(api('summary') + '&_=' + Date.now())
      .then(function (data) {
        if (data.status === 'pending') { return pollPending(data); }
        state.summary = data;
        state.multi = !!data.multi;
        state.targets = data.targets || [];
        state.queries = data.queries || [];
        renderQueryBar();
        renderTargetBar();
        renderCompleteness(data);
        /* Some targets still running: show what is finished, and keep polling
           so the page fills in rather than standing at a partial answer that
           looks whole. */
        if (data.complete === false && (data.running || []).length) {
          state.pollDelay = Math.min(POLL_MAX, (state.pollDelay || POLL_MIN) * 1.4);
          window.setTimeout(function () {
            loadSummary().then(loadRows).then(loadAnnotations);
          }, state.pollDelay);
        }
        if (state.multi) { state.grouping = 'pan_gene'; }
        renderSummary(data);
        return data;
      });
  }

  function renderSummary(d) {
    var q = d.query || {};
    var c = d.counts || {};
    var interp = d.interpretation || {};

    if (els.title) {
      els.title.textContent = (d.program || 'BLAST').toUpperCase() + ' results';
    }
    if (els.queryLine) {
      els.queryLine.innerHTML = '<strong>' + esc(q.title || q.id || 'Query') + '</strong>';
    }
    if (els.queryFacts) {
      /* Query length units follow the PROGRAM, not a guess: blastp and tblastn
         take a protein query, blastn/blastx/tblastx a nucleotide one. */
      var protQuery = (d.program === 'blastp' || d.program === 'tblastn');
      var facts = [
        ['Length', commas(q.len) + (protQuery ? ' aa' : ' bp')],
        ['Program', (d.program || '').toUpperCase()],
        ['Searched', state.multi
          ? (d.targets || []).length + ' targets'
          : (d.db || '—')]
      ];
      els.queryFacts.innerHTML = facts.map(function (f) {
        return '<li>' + esc(f[0]) + ' <b>' + esc(f[1]) + '</b></li>';
      }).join('');
    }

    if (els.reading) {
      els.reading.textContent = interp.headline || '';
      els.readingDetail.textContent = interp.detail || '';
    }

    if (els.metrics) {
      var tiles = [
        metric(commas(c.subjects || 0), 'matching sequences'),
        metric(commas(c.hsps || 0), 'aligned segments')
      ];
      if (state.multi) {
        var hit = 0;
        (d.targets || []).forEach(function (t) { if (t.subjects > 0) { hit++; } });
        tiles.unshift(metric(hit + ' of ' + (d.targets || []).length, 'targets with a match'));
      } else {
        tiles.push(metric(commas(c.loci || 0), 'genomic loci'));
      }
      els.metrics.innerHTML = tiles.join('');
    }

    renderBest(d.best, d);
  }

  /* One BLAST result per submitted sequence. Switching reloads everything,
     because nothing on the page is shared between queries. */
  function renderQueryBar() {
    if (!els.queryBar) { return; }
    if (state.queries.length < 2) { els.queryBar.hidden = true; return; }
    els.queryBar.hidden = false;

    els.querySelect.innerHTML = state.queries.map(function (q) {
      var label = q.title.length > 58 ? q.title.slice(0, 57) + '…' : q.title;
      return '<option value="' + q.index + '"' + (q.index === state.q ? ' selected' : '') + '>' +
             (q.index + 1) + '. ' + esc(label) + ' (' + commas(q.len) + ' bp, ' +
             commas(q.hits) + ' hits)</option>';
    }).join('');

    els.queryNote.textContent = state.queries.length +
      ' sequences were submitted; each is a separate BLAST result.';
  }

  function switchQuery(index) {
    if (index === state.q) { return; }
    state.q = index;
    /* Everything is per-query, so nothing survives the switch. */
    state.rows = []; state.view = []; state.annotations = {}; state.panGenes = {};
    state.selected = null; state.hasGroups = false; state.hasPanGenes = false;
    state.domains = []; state.domainSource = null;
    closeDrawer();
    if (els.textview) { els.textview.dataset.loaded = '0'; }

    var q = new URLSearchParams(window.location.search);
    if (index) { q.set('q', index); } else { q.delete('q'); }
    window.history.replaceState(null, '', window.location.pathname + '?' + q.toString());

    els.tableBody.innerHTML = '<tr><td colspan="12" class="blast-pending">Loading&hellip;</td></tr>';
    loadSummary().then(loadRows).then(function () {
      setView(state.view_mode);
      loadDomains();
      return loadAnnotations();
    });
  }

  /* One search against several targets produces one merged result — that is
     what makes pan-gene grouping and the matrix possible. But a reader still
     needs to see what each target gave them, and to look at just one. This bar
     is both: a per-target summary, and a filter.

     A filter rather than a mode switch, deliberately. Everything downstream
     already reacts to state.view — coverage, chromosomes, scatter, groups,
     matrix and the table — so focusing a target needs no parallel code path,
     and combines with the identity and coverage filters instead of fighting
     them. */
  function renderTargetBar() {
    if (!els.targetBar) { return; }
    var targets = state.targets || [];
    if (targets.length < 2) { els.targetBar.hidden = true; return; }
    els.targetBar.hidden = false;

    /* Count the rows the TABLE actually holds for each target, not the summary's
       subject counts. The two differ whenever the table is in locus units — an
       assembly target has ten chromosome subjects but many more loci — and a
       chip reading "10 hits" beside a table showing more of them for the same
       target is a contradiction the reader has to resolve. Falls back to the
       summary count before rows have arrived. */
    var perTarget = {};
    state.rows.forEach(function (r) {
      if (!r.target) { return; }
      perTarget[r.target] = (perTarget[r.target] || 0) + 1;
    });
    var haveRows = state.rows.length > 0;
    var countFor = function (t) {
      return haveRows ? (perTarget[t.sub_job] || 0) : (t.subjects || 0);
    };

    var total = 0;
    targets.forEach(function (t) { total += countFor(t); });

    var chips = ['<button type="button" class="blast-chipbtn blast-chipbtn-target' +
                 (state.filters.target ? '' : ' is-on') + '" data-target="" ' +
                 'aria-pressed="' + (state.filters.target ? 'false' : 'true') + '">' +
                 '<span class="blast-chip-name">All targets</span>' +
                 '<span class="blast-chip-type">' + targets.length + ' searched</span>' +
                 '<span class="blast-chip-stats">' + commas(total) + ' ' +
                 (state.unit === 'locus' ? 'loci' : 'hits') + '</span></button>'];

    targets.forEach(function (t) {
      var on = state.filters.target === t.sub_job;
      var best = t.best;
      /* Assembly AND what was searched in it. Several targets commonly share one
         assembly — A188 offers Assembly, CDS, genomic and protein — so the
         assembly name on its own printed the same word on four chips and gave a
         reader no way to tell which was which. */
      var type = t.type_short || '';
      chips.push('<button type="button" class="blast-chipbtn blast-chipbtn-target' +
        (on ? ' is-on' : '') +
        '" data-target="' + esc(t.sub_job) + '" aria-pressed="' + (on ? 'true' : 'false') +
        '" title="' + esc(t.label_full || t.label) +
        (best ? ' — best ' + esc(best.subject) + ' at ' + num(best.pident, 2) + '% identity'
              : ' — no matches') + '">' +
        '<span class="blast-chip-name">' + esc(shortAssembly(t.label)) + '</span>' +
        (type ? '<span class="blast-chip-type">' + esc(type) + '</span>' : '') +
        '<span class="blast-chip-stats">' + commas(countFor(t)) + ' ' +
          (state.unit === 'locus' ? 'loci' : 'hits') +
        (best ? ' · ' + num(best.pident, 1) + '%' : '') + '</span></button>');
    });

    els.targetBar.innerHTML =
      '<span class="blast-targetbar-label">Targets</span>' + chips.join('');
  }

  /* Outstanding and failed targets, stated on the page. A result that is
     missing a target it was asked for must never read as complete. */
  /* How much of the visible result carries annotation, and which assemblies
     are only partly covered. The matrix needs the second of these: a cell must
     not read as "absent" for an assembly whose rows simply have not been
     looked up yet. */
  function updateAnnotationCoverage() {
    var annotated = 0;
    var unknownByAssembly = {};
    state.view.forEach(function (r) {
      if (state.annotations[r.key]) { annotated++; return; }
      var a = r.assembly || '';
      unknownByAssembly[a] = (unknownByAssembly[a] || 0) + 1;
    });
    state.annotatedCount = annotated;
    state.unknownByAssembly = unknownByAssembly;

    /* Two different reasons a row has no annotation, and they mean opposite
       things. Not looked up yet is a limit of this page. Looked up and found
       nothing is a fact about the genome — an intergenic match. Reporting the
       second as the first was the same class of false statement this whole fix
       exists to remove. */
    var notAttempted = 0, noGene = 0;
    state.view.forEach(function (r) {
      if (state.annotations[r.key]) { return; }
      if (state.attempted[r.key]) { noGene++; } else { notAttempted++; }
    });

    if (els.annotationNote) {
      var parts = [];
      if (notAttempted) {
        parts.push('Gene and pan-gene annotation covers the ' + commas(annotated) +
          ' strongest of ' + commas(state.view.length) + ' matches; groupings and the ' +
          'pan-genome matrix are computed from those.');
      }
      if (noGene) {
        parts.push(commas(noGene) + ' ' + (noGene === 1 ? 'match falls' : 'matches fall') +
          ' outside any annotated gene — common for repeat-derived and intergenic ' +
          'sequence — and ' + (noGene === 1 ? 'is' : 'are') +
          ' listed in the table without a gene.');
      }
      if (!parts.length) { els.annotationNote.hidden = true; }
      else { els.annotationNote.hidden = false; els.annotationNote.textContent = parts.join(' '); }
    }
    renderMatrix();
  }

  /* What was searched, with which settings, and what to try — rather than a
     page of empty cards. The settings matter most: a search that returns
     nothing at 1e-50 is a different problem from one that returns nothing at
     the default, and only the page knows which was used. */
  function renderEmptyState() {
    if (!els.empty) { return; }
    var d = state.summary;
    if (!d) { return; }

    /* Cleared here rather than in renderSummary, which runs before the rows
       arrive and so cannot know the result is empty. Three zeros say nothing
       the headline has not already said. */
    if (els.metrics && state.rowsLoaded && !state.rows.length) {
      els.metrics.innerHTML = '';
    }
    if (state.rowsLoaded && state.rows.length) { return; }

    var q = d.query || {};
    var p = d.params || {};
    var protQuery = (d.program === 'blastp' || d.program === 'tblastn');

    var what = '<dl class="blast-stats blast-empty-facts">' +
      '<dt>Query</dt><dd>' + esc(q.title || q.id || '—') + '</dd>' +
      '<dt>Length</dt><dd>' + commas(q.len || 0) + (protQuery ? ' aa' : ' bp') + '</dd>' +
      '<dt>Program</dt><dd>' + esc((d.program || '').toUpperCase()) + '</dd>' +
      '<dt>Searched</dt><dd>' +
        ((d.targets || []).map(function (t) { return esc(t.label_full || t.label); }).join(', ') || '—') +
      '</dd>' +
      (p.expect !== undefined ? '<dt>E-value threshold</dt><dd>' + esc(String(p.expect)) + '</dd>' : '') +
      (p.filter ? '<dt>Filtering</dt><dd>' + esc(p.filter) + '</dd>' : '') +
      '</dl>';

    /* Suggestions ordered by how likely they are to be the actual problem for
       THIS search, not a generic checklist. */
    var tips = [];
    var strict = (p.expect !== undefined && Number(p.expect) < 1e-10);
    if (strict) {
      tips.push('The e-value threshold was <strong>' + esc(String(p.expect)) +
        '</strong>, which is strict. A weaker threshold such as 1e-5, or 10 for a ' +
        'short query, will report more distant similarity.');
    } else {
      tips.push('Try a weaker e-value threshold — 1 or 10 — if you are looking for ' +
        'distant similarity rather than a close match.');
    }

    if (q.len && q.len < 50) {
      tips.push('At ' + commas(q.len) + (protQuery ? ' aa' : ' bp') +
        ' the query is short, and short queries need a smaller word size and a ' +
        'weaker e-value to match at all.');
    }

    if (d.program === 'blastn') {
      tips.push('If this is a protein sequence, BLASTN will not match it — use ' +
        'BLASTP against a protein database, or BLASTX to compare a nucleotide ' +
        'query against proteins.');
      tips.push('For similarity across species, translated search (BLASTX or ' +
        'TBLASTN) finds homology that nucleotide comparison misses.');
    } else {
      tips.push('Check that the query is the sequence type this program expects: ' +
        (protQuery ? 'a protein sequence.' : 'a nucleotide sequence.'));
    }

    tips.push('Search a different database — an assembly rather than gene models ' +
      'will match sequence that is not part of an annotated gene.');

    els.empty.innerHTML =
      '<p class="blast-reading">Nothing in ' +
        ((d.targets || []).length === 1 ? 'the searched database' : 'the searched databases') +
        ' matched this query above the threshold used.</p>' +
      '<div class="blast-empty-grid">' +
        '<div><h3 class="blast-empty-h">What was searched</h3>' + what + '</div>' +
        '<div><h3 class="blast-empty-h">What to try next</h3><ul class="blast-empty-tips"><li>' +
          tips.join('</li><li>') + '</li></ul></div>' +
      '</div>' +
      '<div class="blast-actions">' +
        '<a class="mgdb-button mgdb-button-primary" href="/BLAST">Run another search</a>' +
      '</div>';
  }

  function renderCompleteness(d) {
    if (!els.completeness) { return; }
    var bits = [];
    if ((d.running || []).length) {
      bits.push('<strong>Still running:</strong> ' +
        d.running.map(esc).join(', ') + '. This page will fill in as they finish.');
    }
    (d.failed || []).forEach(function (f) {
      bits.push('<strong>' + esc(f.label) + ' did not complete.</strong> ' +
                esc((f.error || '').split('\n').slice(-2).join(' ')));
    });
    if (!bits.length) { els.completeness.hidden = true; return; }
    els.completeness.hidden = false;
    els.completeness.innerHTML = bits.join('<br>');
  }

  function metric(value, label) {
    return '<div class="mgdb-metric"><span class="mgdb-metric-value">' + value +
           '</span><span class="mgdb-metric-label">' + esc(label) + '</span></div>';
  }

  function renderBest(best, d) {
    if (!els.best) { return; }
    if (!best) { els.best.innerHTML = ''; return; }

    var rows = [
      ['Location', best.subject + (best.h_start ? ':' + commas(best.h_start) + '–' + commas(best.h_end) : '')],
      ['Identity', num(best.pident, 2) + '%'],
      ['Query coverage', num(best.q_coverage, 1) + '%'],
      ['E-value', evalue(best.evalue)],
      ['Bit score', num(best.bit_score_total, 1)],
      ['Aligned segments', best.n_hsps]
    ];

    var html = '<dt>Best match</dt><dd class="blast-best-gene" id="blast-best-gene">' +
               esc(best.subject) + '</dd>';
    rows.forEach(function (r) {
      html += '<dt>' + esc(r[0]) + '</dt><dd>' + esc(String(r[1])) + '</dd>';
    });
    els.best.innerHTML = html;
    els.bestActions.innerHTML = '';
  }

  /* Once annotation arrives the best-match block can name a gene rather than a
     chromosome, and its actions become real links. */
  function decorateBest() {
    var best = state.summary && state.summary.best;
    if (!best || !els.bestActions) { return; }
    var key = null;
    state.bestRow = null;
    state.rows.forEach(function (r) {
      if (r.subject === best.subject && r.h_start === best.h_start) {
        key = r.key;
        /* Kept so the genome-browser link can carry this locus's own HSP
           segments rather than one block from its start to its end. */
        state.bestRow = r;
      }
    });
    var ann = key && state.annotations[key];
    if (!ann) { return; }

    var nameEl = document.getElementById('blast-best-gene');
    if (nameEl) {
      nameEl.innerHTML = '<a href="' + esc(ann.links.gene) + '">' +
        esc(ann.locus || ann.gene_model) + '</a>' +
        (ann.locus ? ' <span class="blast-drawer-sub">' + esc(ann.gene_model) + '</span>' : '');
    }

    var actions = [];
    actions.push('<a class="mgdb-button" href="' + esc(ann.links.gene) + '">View gene</a>');

    /* Same rule as the row drawer: JBrowse 1 with the match drawn as its own
       track where the assembly has a JBrowse 1 dataset, the JBrowse 2
       coordinate link only where it does not. `bestRow` is the row this
       annotation came from, so the link carries that locus's own HSPs. */
    var bestLink = browserButton(state.bestRow || null);
    if (bestLink) {
      actions.push(bestLink);
    } else if (ann.links.jbrowse) {
      actions.push('<a class="mgdb-button" href="' + esc(ann.links.jbrowse) +
                   '" target="_blank" rel="noopener">Open in JBrowse</a>');
    }
    if (ann.links.pan_gene) {
      actions.push('<a class="mgdb-button" href="' + esc(ann.links.pan_gene) + '">View pan-gene</a>');
    }
    els.bestActions.innerHTML = actions.join('');
  }

  /* ------------------------------------------------------------------------
     Stage 2 — rows
     ------------------------------------------------------------------------ */

  function loadRows() {
    return MGDB.request(api('hits', '&limit=1000'))
      .then(function (data) {
        state.unit = data.unit;
        state.rows = data.rows || [];
        /* assembly -> JBrowse 1 base, for the genome-browser links. Sent only
           for assemblies whose recorded browser IS JBrowse 1. */
        state.browsers = data.browsers || {};
        /* Loaded-and-empty is not the same as still-loading. Keying the
           progress indicators on rows.length left a no-hit search showing seven
           spinners for ever. */
        state.rowsLoaded = true;
        state.total = data.total || state.rows.length;
        state.hasGenomicSubjects = state.rows.some(function (r) {
          return r.subject_len > 1000000;
        });
        state.queryLen = data.query_len;
        applyFilters();
        renderAll();
        return data;
      });
  }

  /* ------------------------------------------------------------------------
     Stage 3 — annotation
     ------------------------------------------------------------------------ */

  /* Pfam domains of the best-matching protein, projected onto the query axis.
     Loaded alongside annotation rather than before it: the coverage figure is
     already on screen and the domain lane appears when it arrives. Silently a
     no-op for nucleotide subjects, where the endpoint reports not-applicable. */
  function loadDomains() {
    return MGDB.request(api('domains'), { key: 'blast-domains' })
      .then(function (data) {
        if (!data.applicable || !data.domains || !data.domains.length) { return; }
        state.domains = data.domains;
        state.domainSource = data.source;
        renderCoverage();
        renderDomainNote();
      })
      .catch(function () { /* the figure is complete without it */ });
  }

  function renderDomainNote() {
    if (!els.domainNote) { return; }
    if (!state.domains.length || !state.domainSource) { els.domainNote.hidden = true; return; }
    var names = state.domains.map(function (d) { return d.name || d.accession; });
    var uniq = names.filter(function (n, i) { return names.indexOf(n) === i; });
    els.domainNote.hidden = false;
    /* Name whose architecture is drawn. These are the SUBJECT's domains mapped
       through the alignment, not an annotation of the query itself, and saying
       so is the difference between a useful overlay and a misleading one. */
    els.domainNote.innerHTML =
      'Domain track: ' + uniq.map(esc).join(', ') + ' from <strong>' +
      esc(state.domainSource.gene_model) + '</strong>, positioned by its alignment to your query.';
  }

  function loadAnnotations() {
    /* Nothing to annotate is a finished state, not a waiting one. Returning
       here without clearing the flag left a no-hit search spinning for ever on
       every annotation-dependent section. */
    if (!state.rows.length) {
      state.annotating = false;
      refreshLoadingStates();
      return Promise.resolve();
    }

    /* Strongest first, up to the ceiling, skipping anything already known. */
    state.annotating = true;
    refreshLoadingStates();
    var pool = state.view.slice(0, ENRICH_MAX);
    /* ATTEMPTED, not annotated. A row whose coordinates fall outside any gene
       never comes back with an annotation, so filtering on `annotations` asked
       for the same rows again on every pass — an endless loop of identical
       POSTs. `attempted` is marked when a row is sent, so each row is asked
       for exactly once. */
    var wanted = pool.filter(function (r) { return !state.attempted[r.key]; });
    state.annotationCapped = state.view.length > ENRICH_MAX;
    if (!wanted.length) {
      state.annotating = false;
      refreshLoadingStates();
      updateAnnotationCoverage();
      return Promise.resolve();
    }
    wanted = wanted.slice(0, ENRICH_BATCH);
    wanted.forEach(function (r) { state.attempted[r.key] = true; });

    /* Each row carries its own assembly: in a multi-assembly job the rows come
       from different genomes, and sending one assembly for all of them would
       resolve every locus against the wrong coordinates. The endpoint buckets
       by assembly and spends one batched query per bucket. */
    var payload = wanted.map(function (r) {
      if (r.h_start !== undefined && r.h_start !== null && state.unit === 'locus') {
        return { key: r.key, chr: r.subject, start: r.h_start, end: r.h_end,
                 assembly: r.assembly || '' };
      }
      /* Subject rows carry their assembly as well: the same gene model name
         exists in several B73 builds at different coordinates, so resolving on
         the name alone can return the wrong build. */
      return { key: r.key, subject: r.subject,
               assembly: r.assembly || '' };
    });

    /* POST, not GET. The row list is the request: 200 rows of JSON in a query
       string exceeds the server's URI limit and comes back 414 — which a
       single-target job never hit, because twelve rows fit. MGDB.request only
       issues GETs, so this one call uses fetch directly. The endpoint reads
       `rows` with getCGIParam(...,'GP'), so it accepts either. */
    var url = api('enrich');
    var body = new URLSearchParams();
    body.set('rows', JSON.stringify(payload));

    return window.fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json',
                 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (response) {
      if (!response.ok) { throw new Error('enrich failed: ' + response.status); }
      return response.json();
    }).then(function (data) {
      Object.keys(data.annotations || {}).forEach(function (k) {
        state.annotations[k] = data.annotations[k];
      });
      Object.keys(data.pan_genes || {}).forEach(function (k) {
        state.panGenes[k] = data.pan_genes[k];
      });
      renderTable();
      renderCoverage();
      decorateBest();
      renderPanGenes();
      renderGroups();
      renderMatrix();
      updateAnnotationCoverage();
      /* The scatter is drawn before annotation arrives, so its hover labels are
         raw accessions until it is redrawn with the gene symbols. */
      if (state.view_mode === 'discovery') { renderScatter(); }
      if (state.view_mode === 'alignments') { renderAlignmentList(); }
      applySections();

      /* Keep going until the pool is covered. Each pass renders, so the page
         fills in rather than waiting on the last batch. */
      var remaining = state.view.slice(0, ENRICH_MAX).filter(function (r) {
        return !state.attempted[r.key];
      });
      if (remaining.length) { return loadAnnotations(); }
      state.annotating = false;
      refreshLoadingStates();
    }).catch(function () {
      /* Mark the batch attempted even on failure. Retrying for ever is worse
         than showing the result without annotation for those rows. */
      wanted.forEach(function (r) { state.attempted[r.key] = true; });
      state.annotating = false;
      refreshLoadingStates();
      /* Annotation is an enhancement. A failure here leaves a complete,
         usable BLAST result on screen rather than an error page. */
      if (els.annotationNote) {
        els.annotationNote.textContent =
          'Gene and pan-gene annotation could not be loaded. BLAST results below are complete.';
        els.annotationNote.hidden = false;
      }
    });
  }

  /* ------------------------------------------------------------------------
     Filtering and sorting
     ------------------------------------------------------------------------ */

  function applyFilters() {
    var f = state.filters;
    var text = f.text.toLowerCase();

    state.view = state.rows.filter(function (r) {
      if (r.pident < f.identity) { return false; }
      if (r.q_coverage < f.coverage) { return false; }
      if (f.evalue !== null && r.evalue > f.evalue) { return false; }
      if (f.chr && r.subject !== f.chr) { return false; }
      if (f.target && r.target !== f.target) { return false; }
      if (text) {
        var ann = state.annotations[r.key];
        var hay = (r.subject + ' ' + (r.title || '') + ' ' +
                   (ann ? (ann.gene_model || '') + ' ' + (ann.locus || '') + ' ' +
                          (ann.pan_gene || '') : '')).toLowerCase();
        if (hay.indexOf(text) === -1) { return false; }
      }
      return true;
    });

    var col = state.sort.column;
    var dir = state.sort.dir === 'asc' ? 1 : -1;
    state.view.sort(function (a, b) {
      var x = a[col], y = b[col];
      if (x === undefined || x === null) { x = -Infinity; }
      if (y === undefined || y === null) { y = -Infinity; }
      if (typeof x === 'string') { return x.localeCompare(y) * dir; }
      return (x < y ? -1 : x > y ? 1 : 0) * dir;
    });
  }

  function renderAll() {
    renderTable();
    renderPanGenes();
    renderGroups();
    renderMatrix();
    renderCoverage();
    renderChromosomes();
    renderScatter();
    renderFilterSummary();
    renderTargetBar();
    renderEmptyState();
    applySections();
    refreshLoadingStates();
  }

  function renderFilterSummary() {
    if (!els.filterSummary) { return; }
    var shown = state.view.length, total = state.rows.length;
    var base = (shown === total)
      ? commas(total) + ' ' + (state.unit === 'locus' ? 'loci' : 'hits')
      : commas(shown) + ' of ' + commas(total) + ' shown';
    /* If the server holds more than was fetched, say so here rather than
       letting the page imply the result is complete. */
    if (state.total > total) {
      base += ' · ' + commas(state.total) + ' found in total';
    }
    if (state.filters.target) {
      var t = (state.targets || []).filter(function (x) { return x.sub_job === state.filters.target; })[0];
      if (t) {
        base += ' · ' + shortAssembly(t.label) +
                (t.type_short ? ' ' + t.type_short : '') + ' only';
      }
    }
    els.filterSummary.textContent = base;
  }

  /* ------------------------------------------------------------------------
     Query coverage figure
     ------------------------------------------------------------------------ */

  function renderCoverage() {
    if (!els.coverage) { return; }
    /* "Top 25" has to mean the strongest 25, not the first 25 in whatever order
       the table happens to be sorted in. Sorting the table by identity
       ascending silently turned this figure into the WEAKEST 25 while the
       control still said Top. Rank here, independently of the table. */
    var rows = state.view.slice().sort(function (a, b) {
      var x = a.bit_score_total, y = b.bit_score_total;
      return x === y ? 0 : (x < y ? 1 : -1);
    }).slice(0, state.coverageTop);
    if (!rows.length) { els.coverage.innerHTML = ''; return; }

    var qlen = state.queryLen || 1;
    var labelW = 128, rowH = 18, padR = 16, padB = 26;
    /* A domain lane, when there is one, sits between the query axis and the
       hit bars: it belongs to the query coordinate system, and putting it
       directly under the ruler is what makes "this match covers only the
       kinase domain" readable at a glance. */
    var domainH = state.domains.length ? 22 : 0;
    var padT = 26 + domainH;
    var width = Math.max(520, (els.coverage.clientWidth || 760));
    var plotW = width - labelW - padR;
    var height = padT + rows.length * rowH + padB;

    var x = function (pos) { return labelW + (pos / qlen) * plotW; };

    var parts = [];
    parts.push('<svg viewBox="0 0 ' + width + ' ' + height + '" width="100%" height="' + height +
               '" role="img" aria-label="Query coverage for the top ' + rows.length + ' matches">');

    // Query axis along the top.
    parts.push('<g class="blast-axis">');
    parts.push('<line x1="' + labelW + '" y1="' + (padT - 8) + '" x2="' + (labelW + plotW) +
               '" y2="' + (padT - 8) + '"/>');
    var ticks = 5;
    for (var t = 0; t <= ticks; t++) {
      var pos = Math.round(qlen * t / ticks);
      var tx = x(pos);
      parts.push('<line x1="' + tx + '" y1="' + (padT - 12) + '" x2="' + tx + '" y2="' + (padT - 8) + '"/>');
      parts.push('<text x="' + tx + '" y="' + (padT - 16) + '" text-anchor="' +
                 (t === 0 ? 'start' : t === ticks ? 'end' : 'middle') + '">' + commas(pos) + '</text>');
    }
    parts.push('</g>');

    if (domainH) {
      var lane = padT - domainH + 2;
      parts.push('<g class="blast-domain-lane">');
      state.domains.forEach(function (d) {
        var dx = x(d.q_start), dw = Math.max(3, x(d.q_end) - x(d.q_start));
        parts.push('<rect class="blast-domain" x="' + dx + '" y="' + lane +
                   '" width="' + dw + '" height="13" rx="2"><title>' +
                   esc((d.name || d.accession) + ' — ' + (d.description || '')) +
                   '\n' + esc(d.accession) + ', subject residues ' + d.s_start + '–' + d.s_end +
                   (d.clipped ? ' (extends beyond the aligned region)' : '') +
                   '</title></rect>');
        /* Label inside the bar when it fits, above it when it does not, so a
           short domain is still named rather than reduced to a colored tick. */
        var label = d.name || d.accession;
        if (dw > label.length * 6.5) {
          parts.push('<text class="blast-domain-label" x="' + (dx + dw / 2) + '" y="' +
                     (lane + 10) + '" text-anchor="middle">' + esc(label) + '</text>');
        } else {
          parts.push('<text class="blast-domain-label blast-domain-label-out" x="' +
                     (dx + dw / 2) + '" y="' + (lane - 2) + '" text-anchor="middle">' +
                     esc(label) + '</text>');
        }
      });
      parts.push('</g>');
    }

    rows.forEach(function (row, i) {
      var y = padT + i * rowH;
      var barY = y + 3, barH = rowH - 7;
      var selected = state.selected === row.key;

      parts.push('<g class="blast-cov-row' + (selected ? ' blast-selected' : '') +
                 '" data-key="' + esc(row.key) + '">');

      // The track behind every bar, so a partial match reads as partial.
      parts.push('<rect class="blast-cov-track" x="' + labelW + '" y="' + barY +
                 '" width="' + plotW + '" height="' + barH + '" rx="2"/>');

      /* Each merged query interval is drawn separately, joined by a dashed rule.
         That is what makes an exon-split genomic match legible as one hit whose
         alignment is discontinuous, rather than as several unrelated bars. */
      var ivs = row.q_intervals || [[row.q_start, row.q_end]];
      if (ivs.length > 1) {
        parts.push('<line class="blast-cov-join" x1="' + x(ivs[0][0]) + '" y1="' + (barY + barH / 2) +
                   '" x2="' + x(ivs[ivs.length - 1][1]) + '" y2="' + (barY + barH / 2) + '"/>');
      }
      var step = qualityStep(row);
      var fill = RAMP[step];
      /* Outline every bar, not just the strong ones. The weakest fill (#eef4f8)
         sits on a track only marginally lighter (#f2f6f8), so a weak match was
         nearly invisible — a reader could see THAT it matched from the table but
         not WHERE along the query. The stroke darkens with quality so the ramp
         still reads, while the extent is legible at every step. */
      var stroke = RAMP_EDGE[step];
      ivs.forEach(function (iv) {
        var bx = x(iv[0]), bw = Math.max(1.5, x(iv[1]) - x(iv[0]));
        parts.push('<rect class="blast-cov-bar' + (selected ? ' is-selected' : '') +
                   '" x="' + bx + '" y="' + barY + '" width="' + bw + '" height="' + barH +
                   '" rx="1.5" fill="' + fill + '" stroke="' + stroke +
                   '" stroke-width="1"><title>' +
                   esc(rowLabel(row)) + ' — ' + num(row.pident, 2) + '% identity, ' +
                   num(row.q_coverage, 1) + '% coverage, E=' + evalue(row.evalue) +
                   (rowPosition(row) ? '\n' + esc(rowPosition(row)) : '') +
                   '</title></rect>');
      });

      // Orientation, where it is meaningful.
      if (row.orientation) {
        parts.push('<text class="blast-chr-label" x="' + (labelW + plotW + 4) + '" y="' +
                   (barY + barH - 2) + '">' + (row.orientation === '-' ? '←' : '→') + '</text>');
      }

      var label = rowLabel(row);
      if (label.length > 18) { label = label.slice(0, 17) + '…'; }
      parts.push('<text class="blast-cov-label" x="' + (labelW - 8) + '" y="' + (barY + barH - 2) +
                 '" text-anchor="end" data-key="' + esc(row.key) + '">' + esc(label) + '</text>');
      parts.push('</g>');
    });

    parts.push('</svg>');
    els.coverage.innerHTML = parts.join('') + rampLegend();
  }

  /* The ramp is meaningless without a key, and it appeared on neither
     hand-drawn figure. Text labels, so the scale does not depend on telling
     five blues apart. */
  function rampLegend() {
    var steps = [
      ['weakest', 0], ['', 1], ['', 2], ['', 3], ['strongest', 4]
    ];
    var swatches = steps.map(function (st) {
      return '<i style="background:' + RAMP[st[1]] +
             ';border-color:' + RAMP_EDGE[st[1]] + '"></i>';
    }).join('');
    return '<p class="blast-ramp-legend"><span>Match quality</span>' +
      '<span>weaker ' + swatches + ' stronger</span>' +
      '<span>identity and coverage combined</span></p>';
  }

  /* ------------------------------------------------------------------------
     Chromosome overview
     ------------------------------------------------------------------------ */

  function renderChromosomes() {
    if (!els.chromosomes) { return; }

    /* Only meaningful when the subjects are chromosomes. BLAST gives their real
       lengths, so the tracks are drawn to scale without needing a karyotype
       table. A gene-model search has no genomic axis and hides this panel. */
    var lengths = {};
    state.rows.forEach(function (r) {
      if (r.subject_len > 1000000) { lengths[r.subject] = r.subject_len; }
    });
    var names = Object.keys(lengths);
    if (!names.length) { els.chromosomes.innerHTML = ''; return; }

    /* Natural order: chr1..chr10 then anything else, so chr10 does not sort
       between chr1 and chr2. */
    names.sort(function (a, b) {
      var na = /^chr(\d+)$/.exec(a), nb = /^chr(\d+)$/.exec(b);
      if (na && nb) { return Number(na[1]) - Number(nb[1]); }
      if (na) { return -1; }
      if (nb) { return 1; }
      return a.localeCompare(b);
    });

    var maxLen = Math.max.apply(null, names.map(function (n) { return lengths[n]; }));
    var labelW = 62, rowH = 22, padT = 10, padR = 16, padB = 10;
    var width = Math.max(520, els.chromosomes.clientWidth || 760);
    var plotW = width - labelW - padR;
    var height = padT + names.length * rowH + padB;

    var parts = ['<svg viewBox="0 0 ' + width + ' ' + height + '" width="100%" height="' + height +
                 '" role="img" aria-label="Genomic location of matches by chromosome">'];

    names.forEach(function (name, i) {
      var y = padT + i * rowH;
      var trackW = (lengths[name] / maxLen) * plotW;
      parts.push('<text class="blast-chr-label" x="' + (labelW - 8) + '" y="' + (y + 14) +
                 '" text-anchor="end">' + esc(name) + '</text>');
      parts.push('<rect class="blast-chr-track" x="' + labelW + '" y="' + (y + 6) +
                 '" width="' + trackW + '" height="8" rx="4"/>');
    });

    /* Same ranking rule as the coverage figure: marker prominence encodes
       strength, so the set drawn must be chosen by strength too. */
    var plotted = state.view.slice().sort(function (a, b) {
      var x = a.bit_score_total, y = b.bit_score_total;
      return x === y ? 0 : (x < y ? 1 : -1);
    });
    plotted.forEach(function (row) {
      if (!lengths[row.subject] || row.h_start === undefined || row.h_start === null) { return; }
      var i = names.indexOf(row.subject);
      var y = padT + i * rowH;
      var cx = labelW + (row.h_start / maxLen) * plotW;
      var step = qualityStep(row);
      /* Marker size carries hit strength so a strong locus is findable among
         weak ones, but every marker keeps a minimum size so nothing is
         invisible. */
      var r = 2.5 + step * 0.9;
      var selected = state.selected === row.key;
      parts.push('<circle class="blast-chr-hit' + (selected ? ' is-selected' : '') +
                 '" data-key="' + esc(row.key) + '" cx="' + cx + '" cy="' + (y + 10) +
                 '" r="' + r + '" fill="' + RAMP[step] + '" stroke="' + RAMP_EDGE[step] +
                 '" stroke-width="1" fill-opacity="0.9"><title>' +
                 esc(rowLabel(row)) + '\n' + esc(rowPosition(row) || '') + '\n' +
                 num(row.pident, 2) + '% identity, ' + num(row.q_coverage, 1) + '% coverage' +
                 '</title></circle>');
    });

    parts.push('</svg>');
    els.chromosomes.innerHTML = parts.join('') + rampLegend();
  }

  /* ------------------------------------------------------------------------
     Identity versus coverage
     ------------------------------------------------------------------------ */

  function renderScatter() {
    if (!els.scatter || !MGDB.chart) { return; }
    if (state.view.length < 3) { return; }

    /* Two different truncations can bite, and both have to be disclosed or the
       plot silently misrepresents the result:
         - the page only ever FETCHES the first `limit` rows (state.total is what
           the server says exists), so a big result is already short before any
           plotting decision is made — this was never surfaced anywhere;
         - past a few thousand points the plot is a solid block and hover slows,
           so the drawing itself is capped. */
    var CAP = 1500;
    var pts = state.view.slice(0, CAP);
    if (els.scatterNote) {
      var notes = [];
      if (state.total > state.rows.length) {
        notes.push('Loaded the ' + commas(state.rows.length) + ' strongest of ' +
                   commas(state.total) + ' matches.');
      }
      if (state.view.length > CAP) {
        notes.push('Plotting ' + commas(CAP) + ' of ' + commas(state.view.length) + ' shown.');
      }
      els.scatterNote.textContent = notes.join(' ');
    }

    MGDB.chart({
      target: els.scatter,
      traces: [{
        type: 'scattergl',
        mode: 'markers',
        x: pts.map(function (r) { return r.q_coverage; }),
        y: pts.map(function (r) { return r.pident; }),
        text: pts.map(function (r) {
          return rowLabel(r) + (rowPosition(r) ? '<br>' + rowPosition(r) : '') +
                 '<br>E=' + evalue(r.evalue) + ', bits ' + num(r.bit_score_total || r.bit_score, 0);
        }),
        customdata: pts.map(function (r) { return r.key; }),
        hovertemplate: '%{text}<br>%{x:.1f}% coverage, %{y:.2f}% identity<extra></extra>',
        marker: {
          size: pts.map(function (r) { return state.selected === r.key ? 13 : 7; }),
          color: pts.map(function (r) { return state.selected === r.key ? '#d55e00' : RAMP[qualityStep(r)]; }),
          /* A gray outline, not white. The palest ramp step is #eef4f8, which
             against a white plot ground with a white outline is an invisible
             marker — the weakest matches simply were not on the chart. */
          line: {
            width: 1,
            color: pts.map(function (r) {
              return state.selected === r.key ? '#8a3800' : '#6b7a80';
            })
          }
        }
      }],
      layout: {
        xaxis: { title: 'Query coverage (%)', range: [0, 102], zeroline: false },
        yaxis: { title: 'Identity (%)', zeroline: false },
        margin: { l: 58, r: 16, t: 12, b: 46 },
        hovermode: 'closest',
        showlegend: false
      },
      fallback: 'Identity against query coverage for each match.'
    });

    bindScatterClick();
  }

  /* Binding the scatter's click handler is fiddlier than it looks, and got it
     wrong twice:

       - MGDB.chart renders LAZILY (immediately if near the viewport, otherwise
         on an IntersectionObserver), so a fixed timeout races the render and
         binds to an element that is not yet a Plotly graph. Poll for the graph
         instead of guessing a delay.
       - Plotly.newPlot REPLACES the element's event emitter. The scatter is
         redrawn whenever annotation arrives or a filter changes, and every one
         of those redraws silently discards the handler. A bind-once latch
         therefore guarantees the handler is missing for the rest of the
         session — which is exactly what happened: `on` was a function and the
         listener list was empty.

     So: rebind after every render, clearing first so repeats cannot stack. */
  /* Keeping the scatter's click handler attached is harder than it looks, and
     three attempts failed before this one:

       1. A fixed setTimeout raced MGDB.chart, which renders lazily (on an
          IntersectionObserver, with its own timeout fallback) — the handler was
          attached before the element was a Plotly graph.
       2. Polling until `el.data` existed still lost, because a truthy `data`
          can belong to a PREVIOUS plot while a newPlot is pending; that
          newPlot then replaces the element's event emitter and silently drops
          the handler.
       3. Binding once and latching guaranteed the handler stayed missing for
          the rest of the session, since the scatter is re-plotted on every
          filter change, sort and annotation arrival.

     Rather than chase Plotly's lifecycle, hold the invariant directly: check
     once a second that a handler is registered and re-attach if it is not. One
     property read per second is nothing, and it is correct no matter how many
     times the chart is redrawn or by what. Paused when the tab is hidden. */
  function ensureScatterClick() {
    var el = document.getElementById('blast-scatter');
    if (!el || typeof el.on !== 'function' || !el.data) { return; }
    var bound = el._ev && el._ev._events && el._ev._events.plotly_click;
    if (bound) { return; }
    el.on('plotly_click', function (ev) {
      if (ev.points && ev.points.length) { select(ev.points[0].customdata); }
    });
  }

  function startScatterClickWatch() {
    if (state.scatterWatch) { return; }
    /* No visibility guard. Skipping the check while the tab is hidden saves
       nothing measurable — it is one property read — and it means a tab
       restored from the background has no click handler until the next tick.
       It also makes the behavior untestable in any headless or backgrounded
       browser, where document.hidden is permanently true; that cost an hour. */
    state.scatterWatch = window.setInterval(ensureScatterClick, 1000);
    ensureScatterClick();
  }

  function bindScatterClick() { startScatterClickWatch(); }

  /* ------------------------------------------------------------------------
     Hit table
     ------------------------------------------------------------------------ */

  var COLUMNS = [
    { key: 'label',      label: 'Match',     sort: 'subject' },
    { key: 'pan_gene',   label: 'Pan-gene',  sort: null },
    { key: 'position',   label: 'Position',  sort: 'h_start' },
    { key: 'pident',     label: 'Identity',  sort: 'pident',     num: true, chip: true },
    { key: 'q_coverage', label: 'Coverage',  sort: 'q_coverage', num: true },
    { key: 'align_len',  label: 'Length',    sort: 'align_len',  num: true },
    { key: 'mismatches', label: 'Mism.',     sort: 'mismatches', num: true },
    { key: 'gaps',       label: 'Gaps',      sort: 'gaps',       num: true },
    { key: 'evalue',     label: 'E-value',   sort: 'evalue',     num: true },
    { key: 'bits',       label: 'Bit score', sort: 'bit_score_total', num: true }
  ];

  /* A clickable <th> is not operable by keyboard and is not announced as a
     control. Wrap each sortable header's text in a real button once, so it is
     tabbable, activates on Enter and Space for free, and carries the sort state
     that a screen reader reads out with it. */
  function upgradeSortHeaders() {
    if (!els.tableHead) { return; }
    Array.prototype.forEach.call(els.tableHead.querySelectorAll('th[data-sort]'), function (th) {
      if (th.querySelector('.blast-sortbtn')) { return; }
      var label = th.textContent.trim();
      th.innerHTML = '<button type="button" class="blast-sortbtn">' + esc(label) +
                     '<span class="mgdb-visually-hidden">, sort by ' + esc(label) +
                     '</span></button>';
    });
  }

  function renderTable() {
    if (!els.tableBody) { return; }

    /* The assembly column exists only for a multi-target job. It is in the
       markup and hidden rather than built in JS so the header stays sortable
       and screen readers see a stable table shape. */
    var th = document.getElementById('blast-th-assembly');
    if (th) { th.hidden = !state.multi; }
    upgradeSortHeaders();

    if (!state.view.length) {
      /* Two different empty tables. Blaming filters when none are set sends a
         reader looking for a control to clear that does not exist. */
      var filtered = !!(state.filters.text || state.filters.identity ||
                        state.filters.coverage || state.filters.evalue !== null ||
                        state.filters.target);
      var msg = state.rows.length
        ? (filtered ? 'No matches pass the current filters.'
                    : 'No matches to show.')
        : 'This search returned no matches.';
      els.tableBody.innerHTML = '<tr><td colspan="' + (COLUMNS.length + (state.multi ? 1 : 0)) +
        '" class="blast-pending">' + esc(msg) + '</td></tr>';
      renderFilterSummary();
      return;
    }

    var html = state.view.map(function (row) {
      var ann = state.annotations[row.key];
      var step = qualityStep(row);

      var name;
      if (ann && ann.links) {
        name = '<a href="' + esc(ann.links.gene) + '">' + esc(ann.locus || ann.gene_model) + '</a>';
        if (ann.locus) { name += ' <span class="blast-pending">' + esc(ann.gene_model) + '</span>'; }
      } else {
        name = esc(row.subject);
        if (state.pendingAnnotation) { name += ' <span class="blast-pending">…</span>'; }
      }

      var pan = ann && ann.pan_gene
        ? '<a href="' + esc(ann.links.pan_gene) + '">' + esc(ann.pan_gene) + '</a>'
        : '<span class="blast-pending">—</span>';

      /* tabindex + an explicit key handler: a <tr> is not focusable and a click
         handler on it is mouse-only, so there was no keyboard route to a hit's
         details at all. The label says what activating it does, since the row's
         own text does not. */
      return '<tr data-key="' + esc(row.key) + '" tabindex="0"' +
             ' aria-label="' + esc(rowLabel(row) + ', ' + num(row.pident, 1) +
               ' percent identity. Open details.') + '"' +
             (state.selected === row.key ? ' class="is-selected" aria-current="true"' : '') + '>' +
        '<td>' + name + '</td>' +
        (state.multi ? '<td>' + esc(row.assembly || row.target_label || '') +
           (row.target_type_short
             ? ' <span class="blast-pending">' + esc(row.target_type_short) + '</span>' : '') +
           '</td>' : '') +
        '<td>' + pan + '</td>' +
        '<td class="blast-num">' + esc(rowPosition(row) || '—') + '</td>' +
        '<td class="blast-num"><span class="blast-chip blast-chip-q' + step + '">' +
          num(row.pident, 1) + '</span></td>' +
        '<td class="blast-num">' + num(row.q_coverage, 1) + '%</td>' +
        '<td class="blast-num">' + commas(row.align_len) + '</td>' +
        '<td class="blast-num">' + commas(row.mismatches) + '</td>' +
        '<td class="blast-num">' + commas(row.gaps) + '</td>' +
        '<td class="blast-num">' + evalue(row.evalue) + '</td>' +
        '<td class="blast-num">' + num(row.bit_score_total || row.bit_score, 0) + '</td>' +
        '</tr>';
    }).join('');

    els.tableBody.innerHTML = html;
    renderFilterSummary();
  }

  /* ------------------------------------------------------------------------
     Pan-gene grouping
     ------------------------------------------------------------------------ */

  function renderPanGenes() {
    if (!els.panGenes) { return; }

    /* This panel annotates the hits of a SINGLE-target search with their
       pan-genes. A multi-target search is served by the grouped view instead,
       which answers the different question of breadth across the assemblies
       actually searched; showing both would put two pan-gene lists on one page
       saying different things. */
    state.hasPanGenes = false;
    if (state.multi) { return; }

    var groups = {};
    state.view.forEach(function (r) {
      var ann = state.annotations[r.key];
      if (!ann || !ann.pan_gene) { return; }
      if (!groups[ann.pan_gene]) { groups[ann.pan_gene] = { rows: [], ann: ann }; }
      groups[ann.pan_gene].rows.push(r);
    });

    var names = Object.keys(groups);
    state.hasPanGenes = names.length > 0;
    if (!names.length) { els.panGenes.innerHTML = ''; return; }

    names.sort(function (a, b) {
      return groups[b].rows[0].bit_score_total - groups[a].rows[0].bit_score_total;
    });

    /* 66 assemblies take part in the pan-gene analysis; a group present in 64
       of them is a core gene, one in 3 is line-specific. That ratio is the
       biologically interesting number, so it gets the bar. */
    var TOTAL_ASSEMBLIES = 66;

    els.panGenes.innerHTML = names.map(function (name) {
      var g = groups[name];
      var breadth = state.panGenes[name];
      var n = breadth ? breadth.assemblies.length : (g.ann.pan_gene_count || 0);
      var pct = Math.round((n / TOTAL_ASSEMBLIES) * 100);
      var best = g.rows[0];

      return '<div class="blast-pangene-group">' +
        '<button class="blast-pangene-head" data-key="' + esc(best.key) + '">' +
          '<span class="blast-pangene-name">' + esc(name) + '</span>' +
          '<span>' + esc(g.ann.locus || g.ann.gene_model) + '</span>' +
          '<span class="blast-pangene-breadth">' +
            '<span class="blast-breadth-bar"><span class="blast-breadth-fill" style="width:' +
              pct + '%"></span></span> present in ' + n + ' of ' + TOTAL_ASSEMBLIES + ' assemblies' +
          '</span>' +
        '</button>' +
      '</div>';
    }).join('');
  }

  /* ------------------------------------------------------------------------
     Grouped view — the answer to a multi-assembly search
     --------------------------------------------------------------------------
     Six assemblies searched for one gene return six near-identical rows. Listed
     flat they read as six findings; grouped by pan-gene they read as one, with
     its breadth. Two different counts matter and are shown separately:

       "in 6 of 6 searched"   how many of the assemblies YOU searched matched
       "64 of 66 assemblies"  how much of the whole pan-genome carries this gene

     The first is about this search, the second about the gene. Collapsing them
     into one number would be wrong in both directions.
     ------------------------------------------------------------------------ */

  var TOTAL_PANGENOME_ASSEMBLIES = 66;

  function renderGroups() {
    if (!els.groups) { return; }
    state.hasGroups = false;
    if (!state.multi || state.grouping !== 'pan_gene') { return; }

    var groups = {};
    var ungrouped = 0;
    state.view.forEach(function (r) {
      var ann = state.annotations[r.key];
      if (!ann || !ann.pan_gene) { ungrouped++; return; }
      if (!groups[ann.pan_gene]) {
        groups[ann.pan_gene] = { rows: [], assemblies: {}, best: r, symbol: null };
      }
      var g = groups[ann.pan_gene];
      g.rows.push(r);
      if (r.assembly) { g.assemblies[r.assembly] = true; }
      if (r.bit_score_total > g.best.bit_score_total) { g.best = r; }
      /* Prefer a row that actually carries a symbol. Across a NAM panel that is
         normally B73 alone — the other annotations are anonymous accessions,
         which is exactly why the pan-gene is doing the naming here. */
      if (!g.symbol && ann.locus) { g.symbol = ann.locus; }
    });

    var names = Object.keys(groups);
    state.hasGroups = names.length > 0;
    if (!names.length) { els.groups.innerHTML = ''; return; }

    names.sort(function (a, b) {
      return groups[b].best.bit_score_total - groups[a].best.bit_score_total;
    });

    var searched = state.targets.length || 1;

    els.groups.innerHTML = names.map(function (name) {
      var g = groups[name];
      var here = Object.keys(g.assemblies).length;
      var breadth = state.panGenes[name];
      var wide = breadth ? breadth.assemblies.length : null;
      var idents = g.rows.map(function (r) { return r.pident; }).sort(function (x, y) { return x - y; });
      var lo = idents[0], hi = idents[idents.length - 1];
      var med = idents[Math.floor(idents.length / 2)];
      var ann = state.annotations[g.best.key] || {};

      var head =
        '<button class="blast-pangene-head" data-key="' + esc(g.best.key) + '" ' +
                'aria-expanded="false" data-group="' + esc(name) + '">' +
          '<span class="blast-pangene-name">' + esc(g.symbol || ann.gene_model || name) + '</span>' +
          '<span class="blast-group-id">' + esc(name) + '</span>' +
          '<span class="blast-group-here">in <strong>' + here + ' of ' + searched +
            '</strong> searched assemblies</span>' +
          (wide !== null
            ? '<span class="blast-pangene-breadth">' +
                '<span class="blast-breadth-bar"><span class="blast-breadth-fill" style="width:' +
                  Math.round(wide / TOTAL_PANGENOME_ASSEMBLIES * 100) + '%"></span></span> ' +
                wide + ' of ' + TOTAL_PANGENOME_ASSEMBLIES + ' in the pan-genome</span>'
            : '') +
        '</button>';

      var members = '<div class="blast-group-members" hidden>' +
        '<table class="blast-table"><tbody>' +
        g.rows.map(function (r) {
          var a = state.annotations[r.key] || {};
          return '<tr data-key="' + esc(r.key) + '"' +
                 (state.selected === r.key ? ' class="is-selected"' : '') + '>' +
            '<td>' + esc(r.assembly || r.target_label || '') + '</td>' +
            '<td>' + (a.links ? '<a href="' + esc(a.links.gene) + '">' + esc(a.gene_model) + '</a>'
                              : esc(r.subject)) + '</td>' +
            '<td class="blast-num">' + num(r.pident, 2) + '%</td>' +
            '<td class="blast-num">' + num(r.q_coverage, 1) + '%</td>' +
            '<td class="blast-num">' + evalue(r.evalue) + '</td></tr>';
        }).join('') +
        '</tbody></table></div>';

      return '<div class="blast-pangene-group">' + head +
        '<p class="blast-group-stats">identity ' +
          (lo === hi ? num(lo, 2) + '%' : num(lo, 2) + '–' + num(hi, 2) + '%, median ' + num(med, 2) + '%') +
          ' · query coverage ' + num(g.best.q_coverage, 1) + '%' +
        '</p>' + members + '</div>';
    }).join('') +
    (ungrouped
      ? '<p class="blast-pending">' + commas(ungrouped) +
        ' further ' + (ungrouped === 1 ? 'match has' : 'matches have') +
        ' no pan-gene assignment and are listed in the table below.</p>'
      : '');
  }

  /* ------------------------------------------------------------------------
     Views
     --------------------------------------------------------------------------
     One search, four readings. Switching is a visibility change plus, for the
     two that need content, one fetch — never another search. Each view names
     the sections it shows; anything not named is hidden, so adding a section
     later cannot silently leak into a view it does not belong in.
     ------------------------------------------------------------------------ */

  var VIEWS = {
    discovery:  ['summary', 'empty', 'coverage', 'chromosomes', 'scatter', 'pangene', 'group', 'matrix', 'table'],
    table:      ['summary', 'empty', 'table'],
    alignments: ['summary', 'empty', 'alignments'],
    text:       ['summary', 'text']
  };

  var SECTION_IDS = {
    summary:     'blast-summary-section',
    coverage:    'blast-coverage-section',
    chromosomes: 'blast-chromosomes-section',
    scatter:     'blast-scatter-section',
    pangene:     'blast-pangene-section',
    group:       'blast-group-section',
    matrix:      'blast-matrix-section',
    empty:       'blast-empty-section',
    table:       'blast-table-section',
    alignments:  'blast-alignments-section',
    text:        'blast-text-section'
  };

  function setView(name) {
    if (!VIEWS[name]) { name = 'discovery'; }
    state.view_mode = name;
    applySections();

    /* aria-pressed, not aria-selected: these are toggle buttons in a group.
       aria-selected is only meaningful on a real tab inside a tablist with
       tabpanels, and each view here shows a different SET of sections rather
       than one panel, so claiming tab semantics would describe a structure that
       does not exist. */
    Array.prototype.forEach.call(document.querySelectorAll('.blast-view-tab'), function (b) {
      b.setAttribute('aria-pressed', b.dataset.view === name ? 'true' : 'false');
      b.removeAttribute('aria-selected');
    });
    if (els.viewAnnounce) {
      els.viewAnnounce.textContent = 'Showing the ' + name + ' view.';
    }

    if (name === 'alignments') { renderAlignmentList(); }
    if (name === 'text') { loadTextReport(); }
    if (name === 'discovery') { renderScatter(); }

    /* The URL carries the view so a result can be shared in the state it was
       read in. */
    if (window.history && window.history.replaceState) {
      var q = new URLSearchParams(window.location.search);
      if (name === 'discovery') { q.delete('view'); } else { q.set('view', name); }
      var qs = q.toString();
      window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
    }
  }

  /* Section visibility has exactly ONE owner.
     Every render function used to un-hide its own section, which meant a
     background annotation callback or a filter keystroke could pull a
     discovery-only panel onto the page while the tab and the URL still said
     Table. Renders now only fill elements; whether an element is shown is
     decided here, from the current view AND the current data, and re-applied
     after anything that can change either. */
  /* Which sections are still waiting on data, and for what. Without this the
     page looked finished the moment the table appeared, while the pan-gene
     groups and matrix were still filling in behind it — there was no way to
     tell a section that is empty from one that is not done. */
  function setLoading(key, busy, what) {
    var el = document.getElementById(SECTION_IDS[key]);
    if (!el) { return; }
    var head = el.querySelector('.mgdb-section-heading');
    if (!head) { return; }
    var bar = head.querySelector('.blast-loading');
    if (busy) {
      if (!bar) {
        bar = document.createElement('p');
        bar.className = 'blast-loading';
        bar.setAttribute('role', 'status');
        head.appendChild(bar);
      }
      bar.innerHTML = '<span class="blast-spinner" aria-hidden="true"></span>' +
                      esc(what || 'Loading…');
      el.setAttribute('aria-busy', 'true');
    } else {
      if (bar) { bar.remove(); }
      el.removeAttribute('aria-busy');
    }
  }

  function refreshLoadingStates() {
    var waitingRows = !state.rowsLoaded;
    var waitingAnn = state.annotating;
    setLoading('coverage', waitingRows, 'Loading matches…');
    setLoading('chromosomes', waitingRows, 'Loading matches…');
    setLoading('scatter', waitingRows, 'Loading matches…');
    setLoading('table', waitingRows, 'Loading matches…');
    setLoading('pangene', waitingRows || waitingAnn, 'Looking up genes and pan-genes…');
    setLoading('group', waitingRows || waitingAnn, 'Looking up genes and pan-genes…');
    setLoading('matrix', waitingRows || waitingAnn, 'Looking up genes and pan-genes…');
  }

  function applySections() {
    var wanted = VIEWS[state.view_mode] || VIEWS.discovery;
    Object.keys(SECTION_IDS).forEach(function (k) {
      var el = document.getElementById(SECTION_IDS[k]);
      if (!el) { return; }
      var show = wanted.indexOf(k) !== -1;
      /* Two sections are conditional on the data as well as the view: the
         chromosome figure needs genomic subjects, and the grouped view needs a
         multi-target job. A view may ask for them and still not get them. */
      /* With no matches at all there is nothing for any figure or table to
         show, and an empty card reads as a broken one. Only the summary and the
         empty-state panel stay. */
      if (state.rowsLoaded && !state.rows.length &&
          k !== 'summary' && k !== 'empty') { show = false; }
      if (show && k === 'empty') { show = state.rowsLoaded && !state.rows.length; }
      if (show && k === 'chromosomes') { show = state.hasGenomicSubjects; }
      if (show && k === 'group') { show = state.multi && state.hasGroups; }
      if (show && k === 'pangene') { show = !state.multi && state.hasPanGenes; }
      if (show && k === 'scatter') { show = state.view.length >= 3; }
      if (show && k === 'matrix') { show = state.hasMatrix; }
      el.hidden = !show;
    });
    /* Showing the scatter is another moment its handler can be missing, since
       a hidden element may never have been plotted. Idempotent. */
    if (!document.getElementById(SECTION_IDS.scatter).hidden) { bindScatterClick(); }
  }

  /* ------------------------------------------------------------------------
     Alignment list — where the sequence alignment actually lives
     ------------------------------------------------------------------------ */

  var ALIGNMENT_LIST_CAP = 100;

  function alignmentItemName(r) {
    var ann = state.annotations[r.key];
    return ann ? (ann.locus || ann.gene_model) : r.subject;
  }

  /* Row keys are generated (t0s1l0) so they never need escaping in practice,
     but a selector built from data should not assume that. */
  function cssEscape(v) {
    return (window.CSS && CSS.escape) ? CSS.escape(v) : String(v).replace(/["\\]/g, '\\$&');
  }

  function renderAlignmentList() {
    if (!els.alignments) { return; }
    var rows = state.view.slice(0, ALIGNMENT_LIST_CAP);
    if (!rows.length) {
      els.alignments.innerHTML = '<p class="blast-pending">No matches to align.</p>';
      return;
    }

    /* If the same matches are already listed in the same order, update their
       labels in place instead of rebuilding.

       Rebuilding detaches every open alignment: a fetch started by a click
       resolves into an element that is no longer in the document, so the panel
       sits on "Loading alignment…" for ever. Annotation now arrives in several
       batches and each one re-renders, which turned a narrow race into a
       reliable failure — the alignments view stopped opening at all. */
    var keys = rows.map(function (r) { return r.key; }).join(',');
    if (els.alignments.dataset.keys === keys) {
      rows.forEach(function (r) {
        var head = els.alignments.querySelector('.blast-aln-head[data-aln="' + cssEscape(r.key) + '"]');
        if (!head) { return; }
        var nameEl = head.querySelector('.blast-aln-name');
        if (nameEl) { nameEl.textContent = alignmentItemName(r); }
      });
      return;
    }
    els.alignments.dataset.keys = keys;

    els.alignments.innerHTML = rows.map(function (r) {
      return '<div class="blast-aln-item">' +
        '<button type="button" class="blast-aln-head" data-aln="' + esc(r.key) + '" aria-expanded="false">' +
          '<span class="blast-aln-name">' + esc(alignmentItemName(r)) + '</span>' +
          (state.multi && r.assembly
            ? '<span class="blast-group-id">' + esc(r.assembly) + '</span>' : '') +
          '<span class="blast-aln-stats">' + num(r.pident, 2) + '% identity · ' +
            num(r.q_coverage, 1) + '% coverage · E=' + evalue(r.evalue) + '</span>' +
        '</button>' +
        '<div class="blast-aln-body" hidden data-loaded="0"></div>' +
      '</div>';
    }).join('') +
    (state.view.length > ALIGNMENT_LIST_CAP
      /* "strongest" would be a lie whenever the table is sorted by anything
         else — sort by identity ascending and these are the WEAKEST hundred.
         Say what is actually true: the first hundred in the current order. */
      ? '<p class="blast-pending">Showing the first ' + ALIGNMENT_LIST_CAP +
        ' of ' + commas(state.view.length) +
        ' matches in the current sort order. Re-sort or filter to reach the rest.</p>'
      : '');
  }

  /* Fetched on open, once. This is the whole reason the list can exist: a
     result with 10,000 matches would otherwise have to render 10,000
     alignments to show one. */
  function loadAlignmentInto(key, box) {
    var row = findRow(key);
    if (!row || box.dataset.loaded === '1') { return; }
    box.dataset.loaded = '1';
    box.innerHTML = '<p class="blast-pending">Loading alignment&hellip;</p>';

    var url = api('alignment', (row.target ? '&target=' + encodeURIComponent(row.target) : '') +
              '&hit=' + encodeURIComponent(row.hit) + '&hsp=' + encodeURIComponent(row.best_hsp));

    MGDB.request(url, { key: 'aln-' + key }).then(function (data) {
      var a = data.alignment;
      box.innerHTML = diffStrip(a) + alignmentBlocks(a) + alignmentLegend(a) +
        (row.n_hsps > 1
          ? '<p class="blast-pending">Strongest of ' + row.n_hsps +
            ' aligned segments at this match.</p>' : '');
    }).catch(function () {
      box.dataset.loaded = '0';
      box.innerHTML = '<p class="blast-pending">The alignment could not be loaded.</p>';
    });
  }

  function loadTextReport() {
    if (!els.textview || els.textview.dataset.loaded === '1') { return; }
    els.textview.dataset.loaded = '1';
    /* No &q= here on purpose: the text download covers every query, so what
       is saved matches what was submitted. */
    window.fetch(API + '?job=' + encodeURIComponent(state.job) + '&view=text',
                 { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (t) { els.textview.textContent = t; })
      .catch(function () {
        els.textview.dataset.loaded = '0';
        els.textview.textContent = 'The text report could not be loaded.';
      });
  }

  /* ------------------------------------------------------------------------
     Pan-genome matrix
     --------------------------------------------------------------------------
     Rows are pan-genes the search hit; columns are assemblies. Every cell is
     one of FOUR states, and keeping them apart is the whole point of the
     figure — a blank cell that could mean either "this line does not have the
     gene" or "you did not look there" is worse than no matrix at all:

       hit      searched, and BLAST matched here      — carries real statistics
       miss     searched, the gene IS here, no match  — below the threshold
       present  not searched, but the gene is here    — from the pan-genome
       absent   the gene is not in this assembly

     Only `hit` cells are shaded by the chosen metric. `present` is drawn as an
     outline: it is knowledge about the gene, not a measurement of this search.

     Built entirely from data the page already holds — the hit rows, their
     annotations, and the pan-gene breadth fetched with them — so it costs no
     request of its own.
     ------------------------------------------------------------------------ */

  var MATRIX_ROW_CAP = 25;

  /* Assembly names are long and highly repetitive; a 60-column header of
     "Zm-CML247-REFERENCE-NAM-1.0" is unreadable. Keep the part that identifies
     the line and drop the boilerplate, but never collapse two names to the
     same label. */
  function shortAssembly(name) {
    if (!name) { return '—'; }
    var m = /^Z([a-z])-(.+?)-REFERENCE-/.exec(name);
    if (m) { return m[1] === 'm' ? m[2] : 'Z' + m[1] + '-' + m[2]; }
    m = /^(.+?)\s+RefGen_(v\d+)$/.exec(name);
    if (m) { return m[1] + ' ' + m[2]; }
    return name.length > 16 ? name.slice(0, 15) + '…' : name;
  }

  function matrixMetric(row) {
    if (state.matrixMetric === 'coverage') { return row.q_coverage; }
    if (state.matrixMetric === 'bits') { return row.bit_score_total; }
    return row.pident;
  }

  /* Each metric needs its own scale. Identity and coverage are percentages with
     a meaningful floor well above zero; bit score has no fixed ceiling, so it
     is scaled against the strongest cell in the matrix. */
  function matrixStep(value, maxBits) {
    if (state.matrixMetric === 'bits') {
      var f = maxBits > 0 ? value / maxBits : 0;
      return f >= 0.9 ? 4 : f >= 0.65 ? 3 : f >= 0.4 ? 2 : f >= 0.15 ? 1 : 0;
    }
    return value >= 98 ? 4 : value >= 90 ? 3 : value >= 80 ? 2 : value >= 60 ? 1 : 0;
  }

  function renderMatrix() {
    if (!els.matrix) { return; }
    state.hasMatrix = false;

    /* One row per pan-gene, one entry per assembly that produced a hit. */
    var groups = {};
    state.view.forEach(function (r) {
      var ann = state.annotations[r.key];
      if (!ann || !ann.pan_gene) { return; }
      var g = groups[ann.pan_gene];
      if (!g) { g = groups[ann.pan_gene] = { hits: {}, best: r, symbol: null, ann: ann }; }
      var asm = r.assembly || '';
      /* Several transcripts of one gene can each hit; keep the strongest so a
         cell means "the best match in this assembly", not an arbitrary one. */
      if (!g.hits[asm] || r.bit_score_total > g.hits[asm].bit_score_total) {
        g.hits[asm] = r;
      }
      if (r.bit_score_total > g.best.bit_score_total) { g.best = r; }
      if (!g.symbol && ann.locus) { g.symbol = ann.locus; }
    });

    var names = Object.keys(groups);
    if (!names.length) { els.matrix.innerHTML = ''; return; }
    names.sort(function (a, b) {
      return groups[b].best.bit_score_total - groups[a].best.bit_score_total;
    });

    /* Columns are assemblies, not targets. One assembly searched through several
       databases — B73 as an assembly AND as gene-model CDS is the common case —
       is still one genome, and repeating it printed the same presence column
       twice under the same heading. The count matters as much as the column:
       `searched.length` below decides whether the rest of the pan-genome is
       shown, so three databases of one genome read as three genomes and
       suppressed every other column, leaving a matrix of identical columns. */
    var searched = [];
    state.targets.forEach(function (t) {
      if (t.assembly && searched.indexOf(t.assembly) === -1) { searched.push(t.assembly); }
    });

    /* Which columns? Searched assemblies always. The rest of the pan-genome is
       optional because it is 60-odd more columns, and it is the default only
       when there is nothing else to show — a single-assembly search would
       otherwise be a one-column matrix. */
    var showAll = (state.matrixScope === 'all') ||
                  (state.matrixScope === 'auto' && searched.length < 3);

    var others = {};
    if (showAll) {
      names.forEach(function (n) {
        var b = state.panGenes[n];
        if (!b) { return; }
        b.assemblies.forEach(function (a) {
          if (searched.indexOf(a) === -1) { others[a] = true; }
        });
      });
    }
    var columns = searched.concat(Object.keys(others).sort());
    if (!columns.length) { els.matrix.innerHTML = ''; return; }

    var rows = names.slice(0, state.matrixExpanded ? names.length : MATRIX_ROW_CAP);

    var maxBits = 0;
    rows.forEach(function (n) {
      Object.keys(groups[n].hits).forEach(function (a) {
        var v = groups[n].hits[a].bit_score_total;
        if (v > maxBits) { maxBits = v; }
      });
    });

    var html = '<div class="blast-matrix-scroll" tabindex="0" role="region" ' +
               'aria-label="Pan-genome matrix, scrollable"><table class="blast-matrix">';
    html += '<caption class="mgdb-visually-hidden">Pan-genes down the side, ' +
            'assemblies across the top. Each cell is either a BLAST match with its ' +
            'identity, a gene present with no match, a gene present in an assembly ' +
            'that was not searched, or no member of that pan-gene.</caption>';
    html += '<thead><tr><th scope="col" class="blast-matrix-corner">Pan-gene</th>';
    columns.forEach(function (a) {
      var isSearched = searched.indexOf(a) !== -1;
      /* scope="col" so a screen reader pairs each cell with its assembly, and
         the full name in the header text rather than only in a title, which is
         not reliably announced. */
      html += '<th scope="col" class="blast-matrix-col' + (isSearched ? ' is-searched' : '') +
              '" title="' + esc(a) + (isSearched ? ' — searched' : ' — not searched') + '">' +
              '<span>' + esc(shortAssembly(a)) +
              '<span class="mgdb-visually-hidden">, ' + esc(a) +
              (isSearched ? ', searched' : ', not searched') + '</span></span></th>';
    });
    html += '</tr></thead><tbody>';

    rows.forEach(function (n) {
      var g = groups[n];
      var breadth = state.panGenes[n];
      var present = {};
      if (breadth) { breadth.assemblies.forEach(function (a) { present[a] = true; }); }

      html += '<tr>';
      html += '<th scope="row" class="blast-matrix-row" data-key="' + esc(g.best.key) +
                '" tabindex="0" aria-label="' + esc((g.symbol || g.ann.gene_model) + ', ' + name +
                '. Open details.') + '">' +
                '<span class="blast-matrix-symbol">' + esc(g.symbol || g.ann.gene_model) + '</span>' +
                '<span class="blast-matrix-pg">' + esc(n) + '</span>' +
              '</th>';

      columns.forEach(function (a) {
        var hit = g.hits[a];
        var isSearched = searched.indexOf(a) !== -1;
        if (hit) {
          var v = matrixMetric(hit);
          var st = matrixStep(v, maxBits);
          var label = state.matrixMetric === 'bits' ? Math.round(v) : num(v, 0);
          var cellFacts = shortAssembly(a) + ' — ' + hit.subject + ', ' +
                num(hit.pident, 2) + '% identity, ' + num(hit.q_coverage, 1) +
                '% coverage, E ' + evalue(hit.evalue) + ', bit score ' + num(hit.bit_score_total, 0);
          /* The facts go in aria-label as well as title: a title attribute is
             not announced by most screen readers and is unreachable by keyboard,
             which put every per-cell statistic out of reach. */
          html += '<td class="blast-matrix-cell is-hit q' + st + '" data-key="' + esc(hit.key) + '"' +
                  ' tabindex="0" title="' + esc(cellFacts) + '" aria-label="' + esc(cellFacts) + '">' +
                  label + '</td>';
        } else if (isSearched && (state.unknownByAssembly[a] || 0) > 0) {
          /* This assembly has matches we have not annotated yet, so we do not
             know whether one of them belongs to this pan-gene. Neither "no
             match" nor "absent" is true — say unknown. This is the state whose
             absence made the matrix assert a gene was missing from assemblies
             where BLAST had matched it at 98-100%. */
          html += '<td class="blast-matrix-cell is-unknown" title="' + esc(shortAssembly(a)) +
                  ' — ' + commas(state.unknownByAssembly[a]) +
                  ' matches in this assembly are not yet annotated, so presence here is unknown' +
                  '" aria-label="' + esc(shortAssembly(a) + ', presence unknown, not yet annotated') +
                  '"><span aria-hidden="true">?</span></td>';
        } else if (present[a]) {
          /* The gene is in this assembly. Whether that is a finding or a gap in
             the search depends entirely on whether we looked. */
          html += '<td class="blast-matrix-cell ' + (isSearched ? 'is-miss' : 'is-present') +
                  '" title="' + esc(shortAssembly(a)) + ' — gene present in the pan-genome' +
                  (isSearched ? ', but no BLAST match above the threshold' : '; this assembly was not searched') +
                  '" aria-label="' + esc(shortAssembly(a) + (isSearched
                      ? ', gene present, no match above threshold'
                      : ', gene present, assembly not searched')) +
                  '"><span aria-hidden="true">' + (isSearched ? '·' : '○') + '</span></td>';
        } else {
          html += '<td class="blast-matrix-cell is-absent" title="' + esc(shortAssembly(a)) +
                  ' — no member of this pan-gene" aria-label="' +
                  esc(shortAssembly(a) + ', no member of this pan-gene') + '"></td>';
        }
      });
      html += '</tr>';
    });

    html += '</tbody></table></div>';

    if (names.length > rows.length) {
      html += '<p class="blast-pending">Showing the ' + rows.length + ' strongest of ' +
              names.length + ' pan-genes. ' +
              '<button type="button" class="blast-linkbtn" id="blast-matrix-more">Show all</button></p>';
    }

    html += '<p class="blast-legend">' +
      '<span><i class="blast-swatch q4"></i> match, shaded by ' +
        (state.matrixMetric === 'bits' ? 'bit score' :
         state.matrixMetric === 'coverage' ? 'query coverage' : 'identity') + '</span>' +
      '<span><i class="blast-swatch miss"></i> searched, gene present, no match</span>' +
      (Object.keys(state.unknownByAssembly || {}).length
        ? '<span><i class="blast-swatch unknown"></i> not yet annotated — presence unknown</span>' : '') +
      (showAll ? '<span><i class="blast-swatch present"></i> present, not searched</span>' : '') +
      '<span><i class="blast-swatch absent"></i> not in this assembly</span></p>';

    els.matrix.innerHTML = html;
    state.hasMatrix = true;

    var more = document.getElementById('blast-matrix-more');
    if (more) {
      more.addEventListener('click', function () {
        state.matrixExpanded = true;
        renderMatrix();
      });
    }
  }

  /* ------------------------------------------------------------------------
     Selection — the state every component shares
     ------------------------------------------------------------------------ */

  function select(key) {
    if (state.selected === key) { return; }
    state.selected = key;
    /* Every render below that touches the scatter re-plots it and drops the
       click handler, so it is rebound at the end of this function. */
    renderTable();
    renderGroups();
    renderMatrix();
    renderCoverage();
    renderChromosomes();
    renderScatter();
    openDrawer(key);
  }

  function findRow(key) {
    for (var i = 0; i < state.rows.length; i++) {
      if (state.rows[i].key === key) { return state.rows[i]; }
    }
    return null;
  }

  /* ------------------------------------------------------------------------
     Detail drawer
     ------------------------------------------------------------------------ */

  function openDrawer(key) {
    var row = findRow(key);
    if (!row || !els.drawer) { return; }
    /* Remember what opened the drawer so focus can go back there on close.
       Without this, closing left focus on a control that had just become
       invisible, and the next Tab restarted from the top of the document. */
    if (document.activeElement && document.activeElement !== document.body &&
        !els.drawer.contains(document.activeElement)) {
      state.drawerOpener = document.activeElement;
    }
    var ann = state.annotations[key];

    els.drawerTitle.textContent = rowLabel(row);
    els.drawerSub.textContent = rowPosition(row) || row.subject;
    els.drawer.classList.add('is-open');
    els.drawer.setAttribute('aria-hidden', 'false');
    /* `inert` rather than a CSS visibility switch. Off-screen is not gone: with
       only a transform, every control inside the closed drawer stayed in the
       tab order, so keyboard users tabbed into an invisible panel. `visibility`
       would also work but has to be timed against the slide animation, and
       anything that depends on a transition actually running is a drawer whose
       controls become unreachable whenever animation frames are throttled.
       `inert` removes the subtree from the tab order AND the accessibility
       tree, immediately and with no timing at all. */
    els.drawer.inert = false;
    if (els.scrim) { els.scrim.hidden = false; els.scrim.classList.add('is-open'); }

    setDrawerTab('overview');
    /* Move focus into the drawer so a keyboard user is where the new content
       is, not stranded behind it. */
    els.drawerClose.focus();
  }

  function closeDrawer() {
    if (!els.drawer) { return; }
    els.drawer.classList.remove('is-open');
    els.drawer.setAttribute('aria-hidden', 'true');
    els.drawer.inert = true;
    if (els.scrim) { els.scrim.classList.remove('is-open'); els.scrim.hidden = true; }
    /* Focus returns to whatever opened it, if that is still on the page. */
    var opener = state.drawerOpener;
    state.drawerOpener = null;
    if (opener && document.contains(opener)) {
      opener.focus();
    } else {
      var firstRow = document.querySelector('#blast-table-body tr[tabindex]');
      if (firstRow) { firstRow.focus(); }
    }
  }

  function setDrawerTab(tab) {
    Array.prototype.forEach.call(els.drawerTabs, function (b) {
      b.setAttribute('aria-selected', b.dataset.tab === tab ? 'true' : 'false');
    });
    var row = findRow(state.selected);
    if (!row) { return; }
    if (tab === 'overview') { renderDrawerOverview(row); }
    else if (tab === 'alignment') { renderDrawerAlignment(row); }
    else if (tab === 'context') { renderDrawerContext(row); }
  }

  function renderDrawerOverview(row) {
    var ann = state.annotations[row.key];
    var stats = [
      ['Identity', num(row.pident, 2) + '%'],
      ['Weighted identity', num(row.pident_weighted, 2) + '%'],
      ['Query coverage', num(row.q_coverage, 1) + '%'],
      ['Aligned length', commas(row.align_len)],
      ['Mismatches', commas(row.mismatches)],
      ['Gaps', commas(row.gaps)],
      ['E-value', evalue(row.evalue)],
      ['Bit score', num(row.bit_score_total || row.bit_score, 1)],
      ['Aligned segments', row.n_hsps],
      ['Orientation', row.orientation === '-' ? 'minus strand' : 'plus strand']
    ];

    var html = '';
    if (ann) {
      html += '<dl class="blast-stats">';
      if (ann.locus) { html += '<dt>Symbol</dt><dd>' + esc(ann.locus) + '</dd>'; }
      html += '<dt>Gene model</dt><dd><a href="' + esc(ann.links.gene) + '">' +
              esc(ann.gene_model) + '</a></dd>';
      if (ann.pan_gene) {
        var b = state.panGenes[ann.pan_gene];
        html += '<dt>Pan-gene</dt><dd><a href="' + esc(ann.links.pan_gene) + '">' +
                esc(ann.pan_gene) + '</a>' +
                (b ? ' — ' + b.assemblies.length + ' assemblies' : '') + '</dd>';
      }
      if (ann.assembly) { html += '<dt>Assembly</dt><dd>' + esc(ann.assembly) + '</dd>'; }
      html += '</dl><div class="blast-actions">';
      html += '<a class="mgdb-button" href="' + esc(ann.links.gene) + '">View gene</a>';
      /* The genome browser, with this match drawn on it. The JBrowse 2
         coordinate link is the fallback for an assembly JBrowse 1 has no
         dataset for, not a second button offering the same thing twice. */
      var browserLink = browserButton(row);
      html += browserLink;
      if (!browserLink && ann.links.jbrowse) {
        html += '<a class="mgdb-button" href="' + esc(ann.links.jbrowse) +
                '" target="_blank" rel="noopener">Open in JBrowse</a>';
      }
      if (ann.links.pan_gene) {
        html += '<a class="mgdb-button" href="' + esc(ann.links.pan_gene) + '">Pan-gene</a>';
      }
      html += '</div>';
    }

    html += '<h4>BLAST statistics</h4><dl class="blast-stats">';
    stats.forEach(function (s) {
      html += '<dt>' + esc(s[0]) + '</dt><dd>' + esc(String(s[1])) + '</dd>';
    });
    html += '</dl>';

    els.drawerBody.innerHTML = html;
  }

  /* Alignments are fetched only when their tab is opened. A result with ten
     thousand HSPs must never put ten thousand alignments in the DOM. */
  function renderDrawerAlignment(row) {
    els.drawerBody.innerHTML = '<p class="blast-pending">Loading alignment…</p>';
    var url = api('alignment', (row.target ? '&target=' + encodeURIComponent(row.target) : '') +
              '&hit=' + encodeURIComponent(row.hit) + '&hsp=' + encodeURIComponent(row.best_hsp));

    MGDB.request(url, { key: 'blast-alignment' }).then(function (data) {
      var a = data.alignment;
      var html = diffStrip(a) + alignmentBlocks(a);
      html += alignmentLegend(a);
      if (row.n_hsps > 1) {
        html += '<p class="blast-pending">Showing the strongest of ' + row.n_hsps +
                ' aligned segments at this locus.</p>';
      }
      els.drawerBody.innerHTML = html;
    }).catch(function () {
      els.drawerBody.innerHTML = '<p class="blast-pending">The alignment could not be loaded.</p>';
    });
  }

  /* The difference strip: every mismatch and indel positioned along the query,
     so a reader sees where a near-identical match actually differs without
     reading the alignment. */
  function diffStrip(a) {
    var diffs = a.differences || [];
    if (!diffs.length) {
      return '<p class="blast-pending">This segment is identical to the query over its whole length.</p>';
    }
    var w = 520, h = 34, pad = 4;
    var span = Math.max(1, a.q_end - a.q_start);
    var parts = ['<div class="blast-diffstrip"><svg viewBox="0 0 ' + w + ' ' + h +
                 '" width="100%" height="' + h + '" role="img" aria-label="' +
                 diffs.length + ' differences between query and subject">'];
    parts.push('<line x1="' + pad + '" y1="' + (h - 10) + '" x2="' + (w - pad) +
               '" y2="' + (h - 10) + '" stroke="#d8e2e6" stroke-width="2"/>');
    diffs.forEach(function (d) {
      var x = pad + ((d.q_pos - a.q_start) / span) * (w - pad * 2);
      var cls = d.type === 'substitution' ? 'blast-diff-sub'
              : d.type === 'insertion' ? 'blast-diff-ins' : 'blast-diff-del';
      var title = d.type + ' at query ' + commas(d.q_pos) + ': ' +
                  d.query_allele + ' → ' + d.subject_allele +
                  (d.masked ? ' (repeat-masked)' : '');
      if (d.type === 'substitution') {
        parts.push('<rect class="blast-diff-mark ' + cls + '" x="' + (x - 1) + '" y="' +
                   (h - 22) + '" width="2.5" height="12"><title>' + esc(title) + '</title></rect>');
      } else {
        parts.push('<polygon class="blast-diff-mark ' + cls + '" points="' +
                   (x - 4) + ',' + (h - 10) + ' ' + (x + 4) + ',' + (h - 10) + ' ' +
                   x + ',' + (h - 20) + '"><title>' + esc(title) + '</title></polygon>');
      }
    });
    parts.push('</svg></div>');
    parts.push('<p class="blast-drawer-sub">' + diffs.length + ' difference' +
               (diffs.length === 1 ? '' : 's') + ' along the query.</p>');
    return parts.join('');
  }

  /* The pairwise alignment, in BLAST's own 60-column layout, with mismatches
     and gaps marked so they can be found by eye. */
  function alignmentBlocks(a) {
    var W = 60;
    var q = a.qseq, h = a.hseq, m = a.midline;
    var qFwd = a.q_strand !== 'Minus', hFwd = a.h_strand !== 'Minus';
    var qPos = qFwd ? a.q_start : a.q_end;
    var hPos = hFwd ? a.h_start : a.h_end;
    /* Each side may consume 1 or 3 bases per column — see basesPerColumn. A
       tblastn block printed Sbjct 1..60 where BLAST ends it at 180. */
    var qPer = basesPerColumn(a.q_start, a.q_end, q);
    var hPer = basesPerColumn(a.h_start, a.h_end, h);
    var qDir = qFwd ? 1 : -1, hDir = hFwd ? 1 : -1;
    var gut = String(Math.max(a.q_end, a.h_end)).length;

    var out = '';
    for (var off = 0; off < q.length; off += W) {
      var qc = q.substr(off, W), hc = h.substr(off, W), mc = m.substr(off, W);
      var qUsed = qc.length - (qc.split('-').length - 1);
      var hUsed = hc.length - (hc.split('-').length - 1);
      /* End coordinate = last base consumed, not first base of the last column;
         they differ by two on a translated side. */
      var qTo = qUsed ? qPos + qDir * (qUsed * qPer - 1) : qPos;
      var hTo = hUsed ? hPos + hDir * (hUsed * hPer - 1) : hPos;

      out += 'Query  ' + pad(qPos, gut) + '  ' + markup(qc, hc, mc) + '  ' + qTo + '\n';
      out += '       ' + pad('', gut) + '  ' + esc(mc) + '\n';
      out += 'Sbjct  ' + pad(hPos, gut) + '  ' + markup(hc, qc, mc) + '  ' + hTo + '\n\n';

      qPos = qUsed ? qTo + qDir : qPos;
      hPos = hUsed ? hTo + hDir : hPos;
    }
    return '<pre class="blast-aln">' + out + '</pre>';
  }

  /* The conservative-substitution key is only shown when the alignment actually
     contains one, so a nucleotide alignment does not carry a protein legend. */
  function alignmentLegend(a) {
    var hasSimilar = a.midline && a.midline.indexOf('+') !== -1;
    return '<p class="blast-legend">' +
      '<span><i style="background:#d55e00"></i> mismatch</span>' +
      (hasSimilar ? '<span><i style="background:#1a7f6b"></i> conservative substitution</span>' : '') +
      '<span><i style="background:#7a5195"></i> gap</span>' +
      '<span><i style="background:#98a2a8"></i> repeat-masked</span></p>';
  }

  /* Bases of one sequence per alignment column: 3 where that side was
     translated (the query for blastx, the subject for tblastn, both for
     tblastx), else 1. Taken from the HSP's own geometry rather than the
     program name, so it cannot disagree with the data it is drawing. */
  function basesPerColumn(start, end, seq) {
    var cols = seq.length - (seq.split('-').length - 1);
    if (cols <= 0) { return 1; }
    return Math.round((Math.abs(end - start) + 1) / cols) === 3 ? 3 : 1;
  }

  function pad(v, n) {
    var s = String(v);
    while (s.length < n) { s += ' '; }
    return s;
  }

  /* Mark each residue using BLAST's own midline rather than re-deriving it.
     The midline is the authority and its convention differs by program:

       nucleotide  '|' identity, ' ' mismatch
       protein     the residue letter for identity, '+' for a CONSERVATIVE
                   substitution, ' ' for a mismatch

     Comparing the two sequences directly — which this did before — throws away
     the conservative/radical distinction that is most of what a protein
     alignment is read for: every '+' was painted as a plain mismatch. It also
     needed a case-insensitive comparison to avoid flagging soft-masked
     lowercase as differences; reading the midline sidesteps that entirely.
     Case is still consulted, but only to gray masked residues. */
  function markup(seq, other, mid) {
    var out = '';
    for (var i = 0; i < seq.length; i++) {
      var c = seq[i];
      var o = other[i];
      var m = mid ? mid[i] : undefined;
      var masked = (c >= 'a' && c <= 'z') || (o >= 'a' && o <= 'z');

      if (c === '-' || o === '-') {
        out += '<span class="blast-aln-gap">' + esc(c) + '</span>';
      } else if (m === '+') {
        out += '<span class="blast-aln-sim" title="conservative substitution">' + esc(c) + '</span>';
      } else if (m === ' ' || (m === undefined && c.toUpperCase() !== String(o).toUpperCase())) {
        out += '<span class="blast-aln-mm">' + esc(c) + '</span>';
      } else if (masked) {
        out += '<span class="blast-aln-masked">' + esc(c) + '</span>';
      } else {
        out += esc(c);
      }
    }
    return out;
  }

  /* Genomic context: the match placed among its neighbours.
     Fetched when the tab is opened — one ~150 ms range scan (AD-055), which is
     affordable exactly because it is per-drawer-open and not per row. */
  function renderDrawerContext(row) {
    var ann = state.annotations[row.key];

    /* Coordinates come from the row for a genomic search, or from the annotated
       gene for a gene-model search. Without either there is nothing to draw. */
    var chr = null, start = null, end = null, assembly = null;
    if (row.h_start !== undefined && row.h_start !== null && state.unit === 'locus') {
      chr = row.subject; start = row.h_start; end = row.h_end;
      assembly = row.assembly || null;
    } else if (ann && ann.chr && ann.start) {
      chr = ann.chr; start = ann.start; end = ann.end; assembly = ann.assembly;
    }

    if (!chr || !assembly) {
      els.drawerBody.innerHTML =
        '<p class="blast-pending">No genomic coordinates are available for this match, ' +
        'so its neighborhood cannot be drawn.</p>';
      return;
    }

    els.drawerBody.innerHTML = '<p class="blast-pending">Loading genomic context&hellip;</p>';

    var url = API + '?job=' + encodeURIComponent(state.job) + '&view=neighborhood' +
              '&assembly=' + encodeURIComponent(assembly) +
              '&chr=' + encodeURIComponent(chr) +
              '&start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);

    MGDB.request(url, { key: 'blast-hood' }).then(function (data) {
      els.drawerBody.innerHTML = neighborhoodSvg(data, row, ann) + neighborhoodMeta(data, row, ann);
    }).catch(function () {
      els.drawerBody.innerHTML =
        '<p class="blast-pending">The genomic context could not be loaded.</p>';
    });
  }

  /* Deliberately not a miniature genome browser — it orients, and hands off to
     JBrowse for anything more. Genes are packed into lanes only as far as
     needed to stop overlapping models drawing on top of each other. */
  /* Estimated width of a label in the SVG's own units.
     -------------------------------------------------------------------------
     .blast-hood-label is 10px in the page's sans face. There is no way to
     measure text before it is in the document, and the lanes have to be packed
     before the markup exists, so this is an estimate: 5.6px per character is
     the measured average advance for the identifiers that actually appear here
     -- `Zm00001eb067760`, `GRMZM2G045049`, `prx16` -- which are digits, capital
     letters and lower-case letters in roughly fixed proportions. It only has to
     be close: a few px of slack is absorbed by LABEL_GAP. */
  function hoodLabelWidth(text) {
    return text.length * 5.6;
  }

  /* A JBrowse 1 URL that opens the region with this match drawn on it.
     -------------------------------------------------------------------------
     This is what the pre-redesign BLAST results linked to, and it is better
     than a plain coordinate link: the reader arrives with their own HSPs drawn
     as a track rather than having to find them among the gene models. JBrowse 1
     builds a track from URL parameters — addFeatures carries the segments,
     addTracks declares one CanvasFeatures track to draw them, and `tracks`
     opens it.

     `base` comes from the API, which reads chado.analysisprop's
     MaizeGDB_browser_URL per assembly. It already carries the dataset
     (`?data=CML247`), or carries none for B73 v5, which is JBrowse 1's default.
     The API sends a base only for assemblies whose browser IS JBrowse 1, so a
     GBrowse assembly — B73 v1 to v4 — yields no button rather than a link that
     silently drops the features. */
  function jbrowse1Url(base, chr, intervals) {
    if (!base || !chr || !intervals || !intervals.length) { return null; }

    var features = [], min = null, max = null;
    intervals.forEach(function (iv) {
      var from = Math.min(iv[0], iv[1]), to = Math.max(iv[0], iv[1]);
      features.push({
        seq_id: String(chr),
        /* Strings, which is the shape the previous BLAST emitted and what
           JBrowse 1's URL feature parser accepts. */
        start: String(from), end: String(to), type: 'match', name: 'BLASThit'
      });
      min = (min === null) ? from : Math.min(min, from);
      max = (max === null) ? to : Math.max(max, to);
    });

    var pad = Math.max(2000, Math.round((max - min) * 0.5));
    var params = {
      loc: chr + ':' + Math.max(1, min - pad) + '..' + (max + pad),
      addFeatures: JSON.stringify(features),
      addTracks: JSON.stringify([{
        label: 'BLAST', key: 'BLASThits',
        type: 'JBrowse/View/Track/CanvasFeatures',
        store: 'url', glyph: 'JBrowse/View/FeatureGlyph/Segments'
      }]),
      tracks: 'BLAST',
      highlight: ''
    };

    var qs = Object.keys(params).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }).join('&');
    return base + (base.indexOf('?') === -1 ? '?' : '&') + qs;
  }

  /* The browser base for a row's assembly, from the rows payload. */
  function browserBase(row) {
    var map = state.browsers || {};
    return (row && row.assembly && map[row.assembly]) ? map[row.assembly] : null;
  }

  /* The genome-browser button for one row, or ''. */
  function browserButton(row, label) {
    var url = jbrowse1Url(browserBase(row), row && row.subject, row && row.h_intervals);
    if (!url) { return ''; }
    return '<a class="mgdb-button" href="' + esc(url) + '" target="_blank" rel="noopener">' +
           (label || 'Genome Browser') + '</a>';
  }

  var HOOD_LABEL_GAP = 10;

  function neighborhoodSvg(data, row, ann) {
    var w = data.window, genes = data.genes || [];
    var span = Math.max(1, w.end - w.start);
    var W = 520, padL = 4, padR = 4, plotW = W - padL - padR;
    var axisH = 22, matchH = 10;

    /* A lane holds a gene bar with its label underneath, not beside it: the
       label used to be drawn at `gy + 9`, which is inside the 10px bar. */
    var barH = 8, laneH = 26, labelDrop = 18;

    var x = function (pos) {
      return padL + ((Math.min(Math.max(pos, w.start), w.end) - w.start) / span) * plotW;
    };

    /* Lane packing on the LABEL's footprint, not the bar's.
       -----------------------------------------------------------------------
       This is the bug the packing had. A gene bar can be 2px wide -- 155 kb of
       neighborhood in 512px makes most of them that -- so eight genes packed
       happily into one lane, and then eight ~80px labels were drawn at those
       eight positions and piled on top of one another. What has to not overlap
       is the label, so the footprint is the wider of the two. */
    var placed = genes.map(function (g) {
      var gx = x(g.start);
      var gw = Math.max(2, x(g.end) - gx);
      var label = g.locus || g.gene_model;
      if (label.length > 16) { label = label.slice(0, 15) + '\u2026'; }
      var lw = hoodLabelWidth(label);

      /* The label sits at the bar's left edge, pulled back inside the plot if
         that would push it past the right margin. */
      var lx = gx;
      if (lx + lw > W - padR) { lx = Math.max(padL, W - padR - lw); }

      return { g: g, label: label, gx: gx, gw: gw, lx: lx,
               from: Math.min(gx, lx), to: Math.max(gx + gw, lx + lw) };
    });

    var lanes = [];
    placed.forEach(function (p) {
      var i = 0;
      for (; i < lanes.length; i++) {
        if (p.from > lanes[i] + HOOD_LABEL_GAP) { break; }
      }
      lanes[i] = p.to;
      p.lane = i;
    });

    var laneCount = Math.max(1, lanes.length);
    var H = axisH + matchH + 8 + laneCount * laneH;

    var parts = ['<div class="blast-hood"><svg viewBox="0 0 ' + W + ' ' + H +
                 '" width="100%" height="' + H + '" role="img" aria-label="Gene neighborhood of this match on ' +
                 esc(w.chr) + '">'];

    // Coordinate ruler
    parts.push('<g class="blast-axis">');
    parts.push('<line x1="' + padL + '" y1="' + (axisH - 6) + '" x2="' + (W - padR) +
               '" y2="' + (axisH - 6) + '"/>');
    for (var t = 0; t <= 4; t++) {
      var pos = Math.round(w.start + span * t / 4);
      var tx = x(pos);
      parts.push('<line x1="' + tx + '" y1="' + (axisH - 10) + '" x2="' + tx + '" y2="' + (axisH - 6) + '"/>');
      parts.push('<text x="' + tx + '" y="' + (axisH - 13) + '" text-anchor="' +
                 (t === 0 ? 'start' : t === 4 ? 'end' : 'middle') + '">' +
                 (pos / 1e6).toFixed(2) + ' Mb</text>');
    }
    parts.push('</g>');

    // The BLAST match, on its own band directly under the ruler.
    var mx = x(data.match.start), mw = Math.max(2, x(data.match.end) - x(data.match.start));
    parts.push('<rect class="blast-hood-match" x="' + mx + '" y="' + axisH +
               '" width="' + mw + '" height="' + matchH + '" rx="2"><title>BLAST match ' +
               commas(data.match.start) + '\u2013' + commas(data.match.end) + '</title></rect>');

    // Gene models: a bar, and its name on the line below it.
    var top = axisH + matchH + 8;
    placed.forEach(function (p) {
      var g = p.g;
      var gy = top + p.lane * laneH;
      var isFocus = ann && g.gene_model === ann.gene_model;
      parts.push('<a href="' + esc(g.link) + '">');
      parts.push('<rect class="blast-hood-gene' + (isFocus ? ' is-focus' : '') +
                 '" x="' + p.gx + '" y="' + gy + '" width="' + p.gw + '" height="' + barH +
                 '" rx="2"><title>' +
                 esc((g.locus ? g.locus + ' \u2014 ' : '') + g.gene_model) + '\n' +
                 commas(g.start) + '\u2013' + commas(g.end) + '</title></rect>');
      parts.push('<text class="blast-hood-label' + (isFocus ? ' is-focus' : '') +
                 '" x="' + p.lx + '" y="' + (gy + labelDrop) + '" text-anchor="start">' +
                 esc(p.label) + '</text>');
      parts.push('</a>');
    });

    parts.push('</svg></div>');
    return parts.join('');
  }

  function neighborhoodMeta(data, row, ann) {
    var w = data.window;
    var html = '<dl class="blast-stats">' +
      '<dt>Region</dt><dd>' + esc(w.chr) + ':' + commas(w.start) + '–' + commas(w.end) +
        ' (' + num((w.end - w.start) / 1000, 0) + ' kb)</dd>' +
      '<dt>Match</dt><dd>' + commas(data.match.start) + '–' + commas(data.match.end) + '</dd>' +
      '<dt>Genes in frame</dt><dd>' + (data.genes || []).length + '</dd>' +
      '<dt>Assembly</dt><dd>' + esc(w.assembly) + '</dd>' +
      '</dl>';

    if (ann && ann.also && ann.also.length) {
      html += '<h4>The match also overlaps</h4><ul>';
      ann.also.forEach(function (g) {
        html += '<li><a href="' + esc(g.links.gene) + '">' +
                esc(g.locus || g.gene_model) + '</a></li>';
      });
      html += '</ul>';
    }

    /* The genome-browser link, with this match drawn as its own track. The
       JBrowse 2 coordinate link stays as a second option where JBrowse 1 has no
       dataset for the assembly, so a reader is never left without a browser. */
    var custom = jbrowse1Url(data.browser_base, w.chr,
                             (row && row.h_intervals) || [[data.match.start, data.match.end]]);
    html += '<div class="blast-actions">';
    if (custom) {
      html += '<a class="mgdb-button" href="' + esc(custom) +
              '" target="_blank" rel="noopener">Genome Browser</a>';
    } else if (data.jbrowse) {
      html += '<a class="mgdb-button" href="' + esc(data.jbrowse) +
              '" target="_blank" rel="noopener">Open full region in JBrowse</a>';
    }
    html += '</div>';

    /* Say what is NOT drawn. The same two facts the gene record page reports,
       in the same words: neither is in this annotation load, and a reader
       looking at a gene diagram will otherwise assume the arrows are missing
       rather than the data. */
    if (data.notes) {
      html += '<p class="blast-drawer-sub">' + esc(data.notes.strand) + ' ' +
              esc(data.notes.exons) + ' Genes are drawn as spans; open the region ' +
              'in the genome browser for strand and exon structure.</p>';
    }
    return html;
  }

  /* ------------------------------------------------------------------------
     Wiring
     ------------------------------------------------------------------------ */

  /* The table as a tab-separated file. Built from what is on screen — the
     current filters, sort and target — because a download that quietly differs
     from the table above it is worse than no download. Annotation columns are
     included where they have arrived and left empty where they have not, which
     the accompanying note on the page already explains. */
  function downloadTable() {
    var cols = ['match', 'gene_model', 'locus', 'pan_gene', 'assembly', 'subject',
                'chr', 'start', 'end', 'orientation', 'percent_identity',
                'query_coverage', 'alignment_length', 'mismatches', 'gaps',
                'evalue', 'bit_score', 'aligned_segments'];
    var lines = [cols.join('\t')];

    state.view.forEach(function (r) {
      var a = state.annotations[r.key] || {};
      lines.push([
        alignmentItemName(r),
        a.gene_model || '', a.locus || '', a.pan_gene || '',
        r.assembly || '',
        r.subject,
        r.h_start != null ? r.subject : (a.chr || ''),
        r.h_start != null ? r.h_start : (a.start || ''),
        r.h_end != null ? r.h_end : (a.end || ''),
        r.orientation || '',
        r.pident, r.q_coverage, r.align_len, r.mismatches, r.gaps,
        r.evalue, r.bit_score_total, r.n_hsps
      ].map(function (v) {
        /* Tabs and newlines would break the format; a gene description can
           contain neither today, but the export should not depend on that. */
        return String(v === null || v === undefined ? '' : v).replace(/[\t\r\n]+/g, ' ');
      }).join('\t'));
    });

    var header = [
      '# MaizeGDB BLAST results',
      '# job\t' + state.job,
      '# program\t' + ((state.summary && state.summary.program) || ''),
      '# query\t' + ((state.summary && state.summary.query && state.summary.query.title) || ''),
      '# rows\t' + state.view.length + ' of ' + state.total + ' matches found',
      '# note\tReflects the filters and sort in effect when downloaded.',
      ''
    ].join('\n');

    var blob = new Blob([header + lines.join('\n') + '\n'],
                        { type: 'text/tab-separated-values' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'blast-' + state.job + (state.q ? '-query' + (state.q + 1) : '') + '.tsv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    /* Revoked on the next tick: revoking synchronously can cancel the download
       in some browsers before it has read the blob. */
    window.setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
  }

  function bind() {
    // One delegated listener covers the table, both figures and the pan-gene list.
    document.addEventListener('click', function (ev) {
      var el = ev.target.closest('[data-key]');
      if (el && el.dataset.key) { select(el.dataset.key); }
    });

    /* The keyboard equivalent. Enter and Space activate whatever carries a
       data-key, matching what a mouse click does, and Space is prevented from
       scrolling the page as it otherwise would. */
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Enter' && ev.key !== ' ' && ev.key !== 'Spacebar') { return; }
      var el = ev.target.closest && ev.target.closest('[data-key][tabindex]');
      if (!el || !el.dataset.key) { return; }
      ev.preventDefault();
      select(el.dataset.key);
    });

    if (els.tableHead) {
      els.tableHead.addEventListener('click', function (ev) {
        var th = ev.target.closest('th[data-sort]');
        if (!th) { return; }
        var col = th.dataset.sort;
        state.sort = (state.sort.column === col)
          ? { column: col, dir: state.sort.dir === 'asc' ? 'desc' : 'asc' }
          : { column: col, dir: 'desc' };
        Array.prototype.forEach.call(els.tableHead.querySelectorAll('th'), function (h) {
          h.removeAttribute('aria-sort');
        });
        th.setAttribute('aria-sort', state.sort.dir === 'asc' ? 'ascending' : 'descending');
        applyFilters();
        renderAll();
        /* Sorting changes WHICH rows are in the annotated pool, so the newly
           visible ones have to be looked up — without this, sorting by identity
           ascending showed the weakest hits stripped of their gene identity. */
        loadAnnotations();
      });
    }

    /* Filters are debounced: re-sorting and redrawing three figures on every
       keystroke makes a text field feel broken. */
    var refilter = MGDB.debounce(function () {
      applyFilters();
      renderAll();
      loadAnnotations();
    }, 200);

    ['text', 'identity', 'coverage', 'evalue', 'chr'].forEach(function (name) {
      var el = document.getElementById('blast-filter-' + name);
      if (!el) { return; }
      el.addEventListener('input', function () {
        var v = el.value;
        state.filters[name] = (name === 'text' || name === 'chr')
          ? v
          : (v === '' ? (name === 'evalue' ? null : 0) : Number(v));
        refilter();
      });
    });

    if (els.coverageTop) {
      els.coverageTop.addEventListener('change', function () {
        state.coverageTop = Number(els.coverageTop.value);
        renderCoverage();
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('.blast-view-tab'), function (b) {
      b.addEventListener('click', function () { setView(b.dataset.view); });
    });

    if (els.querySelect) {
      els.querySelect.addEventListener('change', function () {
        switchQuery(Number(els.querySelect.value));
      });
    }

    /* Expanding an alignment must not select the hit — opening one to read it
       is not the same gesture as choosing it. */
    document.addEventListener('click', function (ev) {
      var head = ev.target.closest('.blast-aln-head');
      if (!head) { return; }
      ev.stopPropagation();
      var box = head.parentNode.querySelector('.blast-aln-body');
      var open = box.hidden;
      box.hidden = !open;
      head.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { loadAlignmentInto(head.dataset.aln, box); }
    }, true);

    if (els.downloadTable) {
      els.downloadTable.addEventListener('click', downloadTable);
    }

    if (els.targetBar) {
      els.targetBar.addEventListener('click', function (ev) {
        var b = ev.target.closest('[data-target]');
        if (!b) { return; }
        state.filters.target = b.dataset.target;
        state.attempted = {};
        /* Focusing a target changes which rows exist, so annotation for the
           newly-visible ones has to be requested. */
        applyFilters();
        renderAll();
        loadAnnotations();
      });
    }

    if (els.matrixMetric) {
      els.matrixMetric.addEventListener('change', function () {
        state.matrixMetric = els.matrixMetric.value;
        renderMatrix();
      });
    }
    if (els.matrixScope) {
      els.matrixScope.addEventListener('change', function () {
        state.matrixScope = els.matrixScope.value;
        renderMatrix();
        applySections();
      });
    }

    if (els.grouping) {
      els.grouping.addEventListener('change', function () {
        state.grouping = els.grouping.value;
        renderAll();
      });
    }

    /* Expanding a group must not also select its representative row, so this
       runs before the delegated data-key handler and stops there. */
    document.addEventListener('click', function (ev) {
      var head = ev.target.closest('.blast-pangene-head');
      if (!head) { return; }
      var members = head.parentNode.querySelector('.blast-group-members');
      if (!members) { return; }
      var open = members.hidden;
      members.hidden = !open;
      head.setAttribute('aria-expanded', open ? 'true' : 'false');
    }, true);

    if (els.drawerClose) { els.drawerClose.addEventListener('click', closeDrawer); }
    if (els.scrim) { els.scrim.addEventListener('click', closeDrawer); }
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { closeDrawer(); return; }
      /* Keep Tab inside the open drawer. It overlays the page, so tabbing out
         of it lands on controls the reader cannot see. */
      if (ev.key !== 'Tab' || !els.drawer || !els.drawer.classList.contains('is-open')) { return; }
      var focusable = els.drawer.querySelectorAll(
        'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"]), input, select');
      if (!focusable.length) { return; }
      var first = focusable[0], last = focusable[focusable.length - 1];
      if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
      else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
    });

    Array.prototype.forEach.call(els.drawerTabs || [], function (b) {
      b.addEventListener('click', function () { setDrawerTab(b.dataset.tab); });
    });
  }

  /* ------------------------------------------------------------------------
     Boot
     ------------------------------------------------------------------------ */

  /* The hand-drawn figures size themselves to the container's width at draw
     time, so a window that narrows after load leaves the labels and bars laid
     out for the old width — at 375 px the coverage labels ended up around 5 px.
     Redraw on resize, debounced, and only for the figures that are on screen. */
  function bindResize() {
    var redraw = MGDB.debounce(function () {
      if (state.view_mode !== 'discovery') { return; }
      renderCoverage();
      renderChromosomes();
      renderScatter();
    }, 200);
    window.addEventListener('resize', redraw);
    if (window.matchMedia) {
      /* Orientation change on a phone does not always fire resize. */
      var mq = window.matchMedia('(orientation: portrait)');
      if (mq.addEventListener) { mq.addEventListener('change', redraw); }
    }
  }

  function init() {
    var root = document.querySelector('[data-blast-job]');
    if (!root) { return; }

    state.job = root.dataset.blastJob;
    state.q = Number(new URLSearchParams(window.location.search).get('q')) || 0;

    els = {
      title: document.getElementById('blast-title'),
      queryLine: document.getElementById('blast-query-line'),
      reading: document.getElementById('blast-reading'),
      readingDetail: document.getElementById('blast-reading-detail'),
      metrics: document.getElementById('blast-metrics'),
      best: document.getElementById('blast-best'),
      bestActions: document.getElementById('blast-best-actions'),
      coverage: document.getElementById('blast-coverage'),
      coverageTop: document.getElementById('blast-coverage-top'),
      domainNote: document.getElementById('blast-domain-note'),
      chromosomes: document.getElementById('blast-chromosomes'),
      chromosomesSection: document.getElementById('blast-chromosomes-section'),
      scatter: document.getElementById('blast-scatter'),
      scatterSection: document.getElementById('blast-scatter-section'),
      scatterNote: document.getElementById('blast-scatter-note'),
      panGenes: document.getElementById('blast-pangenes'),
      panGeneSection: document.getElementById('blast-pangene-section'),
      groups: document.getElementById('blast-groups'),
      matrix: document.getElementById('blast-matrix'),
      matrixMetric: document.getElementById('blast-matrix-metric'),
      matrixScope: document.getElementById('blast-matrix-scope'),
      queryFacts: document.getElementById('blast-query-facts'),
      downloadTable: document.getElementById('blast-download-table'),
      queryBar: document.getElementById('blast-querybar'),
      querySelect: document.getElementById('blast-query-select'),
      queryNote: document.getElementById('blast-query-note'),
      alignments: document.getElementById('blast-alignments'),
      textview: document.getElementById('blast-textview'),
      groupSection: document.getElementById('blast-group-section'),
      grouping: document.getElementById('blast-grouping'),
      targetList: document.getElementById('blast-target-list'),
      tableHead: document.getElementById('blast-table-head'),
      tableBody: document.getElementById('blast-table-body'),
      filterSummary: document.getElementById('blast-filter-summary'),
      annotationNote: document.getElementById('blast-annotation-note'),
      viewAnnounce: document.getElementById('blast-view-announce'),
      completeness: document.getElementById('blast-completeness'),
      empty: document.getElementById('blast-empty'),
      targetBar: document.getElementById('blast-targetbar'),
      drawer: document.getElementById('blast-drawer'),
      drawerTitle: document.getElementById('blast-drawer-title'),
      drawerSub: document.getElementById('blast-drawer-sub'),
      drawerBody: document.getElementById('blast-drawer-body'),
      drawerClose: document.getElementById('blast-drawer-close'),
      drawerTabs: document.querySelectorAll('.blast-drawer-tab'),
      scrim: document.getElementById('blast-scrim')
    };

    /* The drawer starts closed, so it starts inert. */
    if (els.drawer) { els.drawer.inert = true; }

    bind();
    bindResize();

    /* Show the waiting state from the first paint. Previously nothing called
       this until rows had already arrived, so the indicator a reader needed
       while waiting only appeared once there was nothing left to wait for. */
    state.annotating = true;
    refreshLoadingStates();

    /* Started here rather than from renderScatter/applySections: the watch is
       idempotent and self-scheduling, and starting it at init means the click
       handler no longer depends on any render path reaching its last line. */
    startScatterClickWatch();

    state.pendingAnnotation = true;
    loadSummary()
      .then(loadRows)
      .then(function () {
        state.pendingAnnotation = false;
        var q = new URLSearchParams(window.location.search);
        setView(q.get('view') || 'discovery');
        loadDomains();
        return loadAnnotations();
      })
      .catch(function (err) {
        if (els.reading) {
          els.reading.textContent = 'These results could not be loaded.';
          els.readingDetail.textContent = String(err && err.message ? err.message : err);
        }
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
