/* ==========================================================================
   /fatcat — structural ortholog comparison
   --------------------------------------------------------------------------
   Three jobs:

     the search      asks search/fatcat/fatcat_api.php and renders the answer
     the matrix      four species x three methods, with the agreement computed
     the viewer      superposes the maize protein on a chosen ortholog

   The matrix is the point of the page. DIAMOND, Foldseek and FATCAT each pick
   a top hit per species; the upstream tool this replaces renders those as
   twelve separate panels and leaves the reader to diff twelve accession codes
   by eye. Here the agreement is computed server-side and is the first thing
   drawn, because "all three methods picked the same protein" is the finding.

   Three ways to colour a superposition, and why each exists
   --------------------------------------------------------
     chain      which backbone is which. The default, because the first
                question is always "how much of this overlaps at all".
     plddt      model confidence, mapped from the source AlphaFold files. The
                twist file FATCAT emits has no B-factor column at all, so this
                fetches AF-<query> and AF-<target> and maps their per-residue
                pLDDT back on by residue number, which is preserved through the
                superposition.
     deviation  how far each query residue sits from the nearest target
                backbone atom. This is the one that answers "which parts
                actually differ", and it is deliberately labelled as a
                nearest-CA distance rather than as the alignment's own residue
                correspondence -- FATCAT's block correspondence is not in the
                file it ships, and implying otherwise would be a stronger claim
                than the data supports.

   Reading deviation without confidence is the trap this page tries to close:
   two predicted structures diverging in a region that is low-pLDDT in either of
   them says nothing about the proteins. The viewer therefore reports the mean
   pLDDT across the divergent residues whenever it can.

   Nothing here touches the DOM before DOMContentLoaded. Bauplan emits every
   includeScript() into <head>, so a top-level querySelector runs while <main>
   does not yet exist and returns null.

   Depends on MGDB from /js/mgdb-modern.js and $3Dmol from /js/lib/3dmol/.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var API = '/search/fatcat/fatcat_api.php';
  var escape = MGDB.escapeHtml;

  var els = {};

  var state = {
    data: null,          /* the last compare payload */
    active: null,        /* { species, target } currently in the viewer */
    viewer: null,
    models: {},          /* 'A' target, 'B' query -> 3Dmol model */
    chains: null,        /* parsed CA coordinates per chain */
    plddt: null,         /* accession -> { resi: value } */
    remark: null,        /* rmsd, blocks, residues from the proxy headers */
    scheme: 'chain'
  };

  var METHODS = ['diamond', 'foldseek', 'fatcat'];
  var METHOD_LABEL = { diamond: 'DIAMOND', foldseek: 'Foldseek', fatcat: 'FATCAT' };
  var METHOD_KIND  = { diamond: 'sequence', foldseek: 'structure', fatcat: 'structure' };

  var VERDICT = {
    confirmed:   { label: 'confirmed',   note: 'all three methods agree' },
    supported:   { label: 'supported',   note: 'two of three agree' },
    conflicting: { label: 'conflicting', note: 'three methods, three answers' },
    single:      { label: 'single hit',  note: 'only one method returned a hit' },
    none:        { label: 'no hit',      note: 'no method returned a hit' }
  };

  /* Query and target get one colour each, chosen to stay distinguishable for
     the common forms of colour blindness -- these two are the whole comparison,
     so red/green would be the wrong pair. */
  var COLOR_QUERY  = 0x1f6fb2;   /* blue  — the maize protein */
  var COLOR_TARGET = 0xd97706;   /* amber — the ortholog */

  /* --------------------------------------------------------------------- *
   * Helpers
   * --------------------------------------------------------------------- */

  function num(value, digits) {
    if (value === null || value === undefined || value === '' || isNaN(value)) { return '—'; }
    return Number(value).toFixed(digits === undefined ? 2 : digits);
  }

  function hex(value) { return '#' + value.toString(16).padStart(6, '0'); }

  function plddtColor(value) {
    if (value > 90) { return 0x0053d6; }
    if (value >= 70) { return 0x65cbf3; }
    if (value >= 50) { return 0xffdb13; }
    return 0xff7d45;
  }

  /* Blue (superposes) through amber to red (diverges). 5 A is the point past
     which two backbones are not describing the same thing. */
  function deviationColor(distance) {
    if (distance === null || distance === undefined) { return 0x999999; }
    if (distance < 1) { return 0x0053d6; }
    if (distance < 2) { return 0x65cbf3; }
    if (distance < 3.5) { return 0xffdb13; }
    if (distance < 5) { return 0xff9d3d; }
    return 0xd32f2f;
  }

  function uniprotLink(accession) {
    return '<a href="https://www.uniprot.org/uniprotkb/' + encodeURIComponent(accession)
         + '" target="_blank" rel="noopener">' + escape(accession) + '</a>';
  }

  /* --------------------------------------------------------------------- *
   * Typeahead
   * --------------------------------------------------------------------- */

  function closeSuggestions() {
    if (!els.suggestions) { return; }
    els.suggestions.hidden = true;
    els.suggestions.innerHTML = '';
    els.input.setAttribute('aria-expanded', 'false');
  }

  function renderSuggestions(payload) {
    var items = payload.suggestions || [];
    if (!items.length) {
      els.suggestions.innerHTML = '<p class="mgdb-suggestions-message">'
        + 'No protein starts with that.</p>';
      els.suggestions.hidden = false;
      return;
    }
    els.suggestions.innerHTML = items.map(function (item) {
      var extra = [].concat(item.symbols || [], item.uniprots || [])
        .filter(Boolean).slice(0, 3).join(' · ');
      return '<button class="mgdb-suggestion" type="button" role="option"'
        + ' data-fc-term="' + escape(item.key) + '"'
        + ' data-fc-label="' + escape(item.label) + '">'
        + '<span class="mgdb-suggestion-copy"><b>' + escape(item.label) + '</b>'
        + (extra ? '<span class="mgdb-suggestion-recent">' + escape(extra) + '</span>' : '')
        + '</span></button>';
    }).join('');
    els.suggestions.hidden = false;
    els.input.setAttribute('aria-expanded', 'true');

    Array.prototype.forEach.call(els.suggestions.querySelectorAll('[data-fc-term]'), function (button) {
      button.addEventListener('click', function () {
        els.input.value = button.getAttribute('data-fc-label');
        closeSuggestions();
        runCompare(button.getAttribute('data-fc-term'));
      });
    });
  }

  function moveSuggestion(delta) {
    if (!els.suggestions || els.suggestions.hidden) { return; }
    var items = els.suggestions.querySelectorAll('.mgdb-suggestion');
    if (!items.length) { return; }
    var current = -1;
    for (var i = 0; i < items.length; i++) {
      if (items[i] === document.activeElement) { current = i; break; }
    }
    var next = current + delta;
    if (next < 0) { next = items.length - 1; }
    if (next >= items.length) { next = 0; }
    items[next].focus();
  }

  /* --------------------------------------------------------------------- *
   * The consensus matrix
   * --------------------------------------------------------------------- */

  function matrixMarkup(data) {
    var rows = data.species.map(function (species) {
      var consensus = species.consensus;
      var verdict = VERDICT[consensus.level] || VERDICT.none;

      var cells = METHODS.map(function (method) {
        var target = species.methods[method];
        if (!target) {
          return '<td class="fc-cell fc-cell-empty"><span class="mgdb-visually-hidden">'
            + 'no hit</span>—</td>';
        }
        var agrees = consensus.target && target === consensus.target;
        return '<td class="fc-cell' + (agrees ? ' is-consensus' : ' is-dissenting') + '">'
          + '<button type="button" class="fc-cell-button"'
          + ' data-fc-species="' + escape(species.key) + '"'
          + ' data-fc-target="' + escape(target) + '"'
          + ' title="Superpose ' + escape(target) + '">'
          + escape(target) + '</button></td>';
      }).join('');

      return '<tr>'
        + '<th scope="row" class="fc-species"><b>' + escape(species.label) + '</b>'
        + '<span>' + escape(species.latin) + '</span></th>'
        + cells
        + '<td class="fc-verdict-cell"><span class="fc-verdict fc-verdict-'
        + escape(consensus.level) + '">' + escape(verdict.label) + '</span>'
        + '<span class="fc-verdict-note">' + escape(verdict.note) + '</span></td>'
        + '</tr>';
    }).join('');

    return '<div class="fc-matrix-wrap"><table class="mgdb-table fc-matrix">'
      + '<caption class="mgdb-visually-hidden">Top ortholog picked by each method in each species,'
      + ' and whether the methods agree</caption>'
      + '<thead><tr><th scope="col">Species</th>'
      + METHODS.map(function (method) {
          return '<th scope="col">' + METHOD_LABEL[method]
            + '<span class="fc-method-kind">' + METHOD_KIND[method] + '</span></th>';
        }).join('')
      + '<th scope="col">Agreement</th></tr></thead>'
      + '<tbody>' + rows + '</tbody></table></div>'
      + '<p class="mgdb-small fc-matrix-note">Cells that agree with the majority are filled; a '
      + 'dissenting method is outlined. Select any accession to superpose it on the maize '
      + 'structure.</p>';
  }

  /* --------------------------------------------------------------------- *
   * Per-species detail
   * --------------------------------------------------------------------- */

  function detailMarkup(data) {
    return data.species.map(function (species) {
      if (!species.targets.length) {
        return '<article class="mgdb-card fc-species-card">'
          + '<h3>' + escape(species.label) + '</h3>'
          + '<p class="mgdb-small">No method returned a hit in this proteome. That is a statement '
          + 'about this comparison set, not about whether an ortholog exists.</p></article>';
      }
      var targets = species.targets.map(function (target) {
        var detail = target.detail || {};
        var methods = target.methods.map(function (method) {
          return '<span class="fc-method-tag fc-method-' + escape(method) + '">'
            + METHOD_LABEL[method] + '</span>';
        }).join('');
        var annotation = detail.uniprot_note || detail.pannzer_note || '';
        var annotationKind = detail.uniprot_note ? 'UniProt' : (detail.pannzer_note ? 'Pannzer' : '');

        return '<div class="fc-target">'
          + '<div class="fc-target-head">' + uniprotLink(target.accession) + methods + '</div>'
          + (annotation
              ? '<p class="fc-target-note"><span class="fc-note-kind">' + escape(annotationKind)
                + '</span> ' + escape(annotation) + '</p>'
              : '<p class="fc-target-note fc-target-note-empty">No annotation in either source.</p>')
          + '<dl class="fc-target-metrics">'
          + '<div><dt>FATCAT score</dt><dd>' + escape(detail.fatcat_score || '—') + '</dd></div>'
          + '<div><dt>p-value</dt><dd>' + escape(detail.fatcat_p || '—') + '</dd></div>'
          + (detail.gene ? '<div><dt>Gene</dt><dd>' + escape(detail.gene) + '</dd></div>' : '')
          + '</dl>'
          + '<p class="fc-target-actions">'
          + '<button class="mgdb-button mgdb-button-secondary" type="button"'
          + ' data-fc-species="' + escape(species.key) + '"'
          + ' data-fc-target="' + escape(target.accession) + '">Superpose</button>'
          + '<a class="mgdb-button mgdb-button-quiet" href="' + escape(target.model)
          + '" target="_blank" rel="noopener">Model</a>'
          + '<a class="mgdb-button mgdb-button-quiet" href="' + escape(target.alignment)
          + '" download>Superposition</a>'
          + '</p></div>';
      }).join('');

      return '<article class="mgdb-card fc-species-card">'
        + '<h3>' + escape(species.label) + ' <span class="fc-species-latin">'
        + escape(species.latin) + '</span></h3>'
        + targets + '</article>';
    }).join('');
  }

  /* --------------------------------------------------------------------- *
   * Render
   * --------------------------------------------------------------------- */

  function renderCompare(data) {
    if (!data.found) {
      els.results.innerHTML = '<div class="fc-state"><h3>Nothing to compare</h3><p>'
        + escape(data.message || 'That identifier did not resolve to a maize protein structure.')
        + '</p></div>';
      return;
    }
    state.data = data;

    var protein = data.protein;
    var rollup = data.rollup || {};
    var headline = rollup.confirmed
      ? '<b>' + rollup.confirmed + ' of ' + data.species.length
        + '</b> species have all three methods agreeing'
      : (rollup.supported
          ? '<b>' + rollup.supported + '</b> species have two of three agreeing'
          : 'No species had two methods agree');

    var identity = '<div class="fc-identity">'
      + '<div><span class="mgdb-eyebrow">Maize query</span>'
      + '<h3>' + escape(protein.symbol || protein.v5 || protein.uniprot) + '</h3>'
      + '<p class="fc-identity-ids">'
      + uniprotLink(protein.uniprot)
      + (protein.v5 ? ' · <a href="/gene_center/gene?id=' + encodeURIComponent(protein.v5) + '">'
          + escape(protein.v5) + '</a>' : '')
      + (protein.v4 ? ' · <span class="fc-id-old">' + escape(protein.v4) + ' (v4)</span>' : '')
      + '</p>'
      + (protein.uniprot_note
          ? '<p class="fc-identity-note">' + escape(protein.uniprot_note) + '</p>' : '')
      + '<p class="fc-headline">' + headline + '</p>'
      + '</div>'
      + '<div class="fc-identity-links">'
      + '<a class="mgdb-button mgdb-button-quiet" href="' + escape(data.model)
      + '" target="_blank" rel="noopener">Maize model</a>'
      + (protein.v5 ? '<a class="mgdb-button mgdb-button-quiet" href="/data_center/alphafill?gene='
          + encodeURIComponent(protein.v5) + '">Predicted ligands</a>' : '')
      + '</div></div>';

    els.results.innerHTML = identity
      + matrixMarkup(data)
      + viewerMarkup()
      + '<div class="mgdb-card-grid fc-species-grid">' + detailMarkup(data) + '</div>';

    Array.prototype.forEach.call(els.results.querySelectorAll('[data-fc-target]'), function (button) {
      button.addEventListener('click', function () {
        openSuperposition(button.getAttribute('data-fc-species'),
                          button.getAttribute('data-fc-target'));
      });
    });

    bindViewer();

    /* Open the strongest available comparison rather than making the reader
       pick one to see anything: a confirmed species first, then a supported
       one, then whatever exists. */
    var best = null;
    ['confirmed', 'supported', 'single', 'conflicting'].some(function (level) {
      data.species.some(function (species) {
        if (species.consensus.level === level && species.consensus.target) {
          best = { species: species.key, target: species.consensus.target };
          return true;
        }
        return false;
      });
      return !!best;
    });
    if (best) { openSuperposition(best.species, best.target); }
  }

  /* --------------------------------------------------------------------- *
   * Viewer
   * --------------------------------------------------------------------- */

  function viewerMarkup() {
    return '<section class="fc-viewer" data-fc-viewer aria-labelledby="fc-viewer-title">'
      + '<div class="fc-viewer-bar">'
      + '<h3 id="fc-viewer-title" data-fc-viewer-title>Superposition</h3>'
      + '<label>Colour <select data-fc-scheme>'
      + '<option value="chain">query vs ortholog</option>'
      + '<option value="plddt">model confidence</option>'
      + '<option value="deviation">backbone deviation</option>'
      + '</select></label>'
      + '<label class="fc-toggle"><input type="checkbox" data-fc-show-target checked> ortholog</label>'
      + '<label class="fc-toggle"><input type="checkbox" data-fc-show-query checked> maize</label>'
      + '<button type="button" data-fc-reset>Reset view</button>'
      + '<button type="button" data-fc-spin>Spin</button>'
      + '</div>'
      + '<div class="fc-viewport" data-fc-viewport>'
      + '<div class="fc-viewer-status" data-fc-status>Loading the superposition…</div>'
      + '<div class="fc-viewer-legend" data-fc-legend></div>'
      + '</div>'
      + '<div class="fc-metrics" data-fc-metrics></div>'
      + '</section>';
  }

  function viewerEl(selector) {
    var root = els.results.querySelector('[data-fc-viewer]');
    return root ? root.querySelector(selector) : null;
  }

  function setStatus(html) {
    var node = viewerEl('[data-fc-status]');
    if (node) { node.innerHTML = html; }
  }

  /* Parse CA atoms per chain so deviation can be computed without asking 3Dmol
     for its internal model, which differs between versions. */
  function parseChains(text) {
    var chains = { A: [], B: [] };
    text.split('\n').forEach(function (line) {
      if (line.indexOf('ATOM') !== 0) { return; }
      if (line.substr(12, 4).trim() !== 'CA') { return; }
      var chain = line.charAt(21);
      if (!chains[chain]) { return; }
      chains[chain].push({
        resi: parseInt(line.substr(22, 4), 10),
        x: parseFloat(line.substr(30, 8)),
        y: parseFloat(line.substr(38, 8)),
        z: parseFloat(line.substr(46, 8))
      });
    });
    return chains;
  }

  /* Nearest CA in the other chain, for every CA in this one. Deliberately not
     presented as the alignment's residue correspondence: FATCAT's block
     correspondence is not in the file it emits, and a nearest-neighbour
     distance is the honest thing that can be computed from what is there. */
  function nearestDistances(from, to) {
    var out = {};
    for (var i = 0; i < from.length; i++) {
      var a = from[i];
      var best = Infinity;
      for (var j = 0; j < to.length; j++) {
        var b = to[j];
        var dx = a.x - b.x, dy = a.y - b.y, dz = a.z - b.z;
        var d = dx * dx + dy * dy + dz * dz;
        if (d < best) { best = d; }
      }
      out[a.resi] = Math.sqrt(best);
    }
    return out;
  }

  /* pLDDT lives in the source AlphaFold files, never in the twist output.
     Residue numbering survives the superposition, so it maps straight back. */
  function fetchPlddt(url) {
    return window.fetch(url, { mode: 'cors' })
      .then(function (response) {
        if (!response.ok) { throw new Error(String(response.status)); }
        return response.text();
      })
      .then(function (text) {
        var map = {};
        text.split('\n').forEach(function (line) {
          if (line.indexOf('ATOM') !== 0) { return; }
          if (line.substr(12, 4).trim() !== 'CA') { return; }
          map[parseInt(line.substr(22, 4), 10)] = parseFloat(line.substr(60, 6));
        });
        return map;
      });
  }

  function applyScheme() {
    if (!state.viewer) { return; }
    var scheme = state.scheme;
    var showTarget = viewerEl('[data-fc-show-target]');
    var showQuery = viewerEl('[data-fc-show-query]');
    var wantTarget = !showTarget || showTarget.checked;
    var wantQuery = !showQuery || showQuery.checked;

    state.viewer.setStyle({}, {});

    function style(chain, want, solid, mapper) {
      if (!want) { return; }
      var spec = { cartoon: { thickness: 0.35, arrows: true } };
      if (mapper) { spec.cartoon.colorfunc = mapper; }
      else { spec.cartoon.color = hex(solid); }
      state.viewer.setStyle({ chain: chain }, spec);
    }

    if (scheme === 'plddt' && state.plddt) {
      style('A', wantTarget, COLOR_TARGET, function (atom) {
        var v = state.plddt.target ? state.plddt.target[atom.resi] : null;
        return v === undefined || v === null ? 0x999999 : plddtColor(v);
      });
      style('B', wantQuery, COLOR_QUERY, function (atom) {
        var v = state.plddt.query ? state.plddt.query[atom.resi] : null;
        return v === undefined || v === null ? 0x999999 : plddtColor(v);
      });
    } else if (scheme === 'deviation' && state.deviation) {
      /* The target is drawn plain so the coloured chain is unambiguously the
         maize protein, which is what the deviation was measured for. */
      style('A', wantTarget, 0x8a8f8b, null);
      style('B', wantQuery, COLOR_QUERY, function (atom) {
        return deviationColor(state.deviation[atom.resi]);
      });
    } else {
      style('A', wantTarget, COLOR_TARGET, null);
      style('B', wantQuery, COLOR_QUERY, null);
    }

    drawLegend();
    state.viewer.render();
  }

  function drawLegend() {
    var node = viewerEl('[data-fc-legend]');
    if (!node) { return; }
    var rows = '';
    if (state.scheme === 'plddt') {
      rows = '<b>Model pLDDT</b>'
        + legendRow(0x0053d6, 'very high &gt; 90') + legendRow(0x65cbf3, 'confident 70–90')
        + legendRow(0xffdb13, 'low 50–70') + legendRow(0xff7d45, 'very low &lt; 50');
    } else if (state.scheme === 'deviation') {
      rows = '<b>Distance to nearest ortholog backbone</b>'
        + legendRow(0x0053d6, '&lt; 1 Å') + legendRow(0x65cbf3, '1–2 Å')
        + legendRow(0xffdb13, '2–3.5 Å') + legendRow(0xff9d3d, '3.5–5 Å')
        + legendRow(0xd32f2f, '&gt; 5 Å');
    } else {
      rows = '<b>Chains</b>'
        + legendRow(COLOR_QUERY, 'maize query')
        + legendRow(COLOR_TARGET, 'ortholog');
    }
    node.innerHTML = rows;
  }

  function legendRow(color, label) {
    return '<div class="fc-legend-row"><span class="fc-legend-swatch" style="background:'
      + hex(color) + '"></span>' + label + '</div>';
  }

  function renderMetrics() {
    var node = viewerEl('[data-fc-metrics]');
    if (!node) { return; }
    var remark = state.remark || {};
    var cards = [];

    cards.push(metric('RMSD', remark.rmsd !== null && remark.rmsd !== undefined
      ? num(remark.rmsd, 2) + ' Å' : '—', 'over the aligned blocks'));
    cards.push(metric('Aligned residues', remark.residues || '—', 'in the superposition'));
    cards.push(metric('Blocks', remark.blocks || '—',
      remark.blocks === 1 ? 'a single rigid alignment' : 'hinge-separated segments'));

    if (state.deviation) {
      var values = Object.keys(state.deviation).map(function (k) { return state.deviation[k]; });
      var close = values.filter(function (v) { return v < 2; }).length;
      cards.push(metric('Backbone within 2 Å',
        Math.round(100 * close / values.length) + '%',
        close + ' of ' + values.length + ' maize residues'));

      /* The caveat that makes the deviation reading safe. */
      if (state.plddt && state.plddt.query) {
        var diverged = Object.keys(state.deviation).filter(function (k) {
          return state.deviation[k] >= 5;
        });
        if (diverged.length) {
          var scores = diverged.map(function (k) { return state.plddt.query[k]; })
            .filter(function (v) { return v !== undefined; });
          if (scores.length) {
            var mean = scores.reduce(function (a, b) { return a + b; }, 0) / scores.length;
            cards.push(metric('Divergent regions', num(mean, 1) + ' pLDDT',
              mean < 70 ? 'low confidence — treat the divergence as uncertain'
                        : 'confidently modelled — the difference looks real'));
          }
        }
      }
    }
    node.innerHTML = cards.join('');
  }

  function metric(term, value, note) {
    return '<div class="fc-metric"><dt>' + term + '</dt><dd>' + value + '</dd>'
      + '<p>' + note + '</p></div>';
  }

  function openSuperposition(speciesKey, target) {
    if (!state.data) { return; }
    var species = null;
    state.data.species.forEach(function (candidate) {
      if (candidate.key === speciesKey) { species = candidate; }
    });
    if (!species) { return; }
    var entry = null;
    species.targets.forEach(function (candidate) {
      if (candidate.accession === target) { entry = candidate; }
    });
    if (!entry) { return; }

    state.active = { species: speciesKey, target: target };
    state.plddt = null;
    state.deviation = null;
    state.remark = null;

    Array.prototype.forEach.call(els.results.querySelectorAll('.fc-cell-button'), function (button) {
      button.classList.toggle('is-active',
        button.getAttribute('data-fc-species') === speciesKey
        && button.getAttribute('data-fc-target') === target);
    });

    var title = viewerEl('[data-fc-viewer-title]');
    if (title) {
      title.innerHTML = 'Maize ' + escape(state.data.protein.uniprot) + ' superposed on '
        + escape(species.label) + ' ' + escape(target);
    }
    setStatus('Loading the superposition…');

    var host = viewerEl('[data-fc-viewport]');
    if (!host || !window.$3Dmol) { return; }
    if (!state.viewer) {
      state.viewer = window.$3Dmol.createViewer(host, {
        backgroundColor: '#0d141b', antialias: true
      });
    }
    state.viewer.clear();

    window.fetch(entry.alignment, { credentials: 'same-origin' })
      .then(function (response) {
        if (!response.ok) { throw new Error('superposition ' + response.status); }
        state.remark = {
          rmsd: parseFloat(response.headers.get('X-Fatcat-Rmsd')),
          blocks: parseInt(response.headers.get('X-Fatcat-Blocks'), 10),
          residues: parseInt(response.headers.get('X-Fatcat-Residues'), 10)
        };
        return response.text();
      })
      .then(function (text) {
        state.viewer.addModel(text, 'pdb');
        state.chains = parseChains(text);
        state.deviation = nearestDistances(state.chains.B, state.chains.A);
        applyScheme();
        state.viewer.zoomTo();
        state.viewer.render();
        renderMetrics();
        setStatus('Chain colouring. Switch to <b>backbone deviation</b> to see where the two '
          + 'structures differ, or <b>model confidence</b> to check whether a difference is real.');

        /* Confidence is fetched eagerly but not blockingly: the metrics panel
           needs it to caveat the divergent regions, and it is two files from a
           CDN that the browser will already have cached for a repeat view. */
        return Promise.all([
          fetchPlddt(state.data.model).catch(function () { return null; }),
          fetchPlddt(entry.model).catch(function () { return null; })
        ]);
      })
      .then(function (results) {
        if (!results) { return; }
        state.plddt = { query: results[0], target: results[1] };
        var scheme = viewerEl('[data-fc-scheme]');
        if (!results[0] || !results[1]) {
          /* An accession withdrawn from UniProt has no model any more. Say so
             rather than silently offering a colouring that does nothing. */
          if (scheme) {
            var option = scheme.querySelector('option[value="plddt"]');
            if (option) {
              option.disabled = true;
              option.textContent = 'model confidence (unavailable)';
            }
          }
        }
        renderMetrics();
        if (state.scheme === 'plddt') { applyScheme(); }
      })
      .catch(function (error) {
        setStatus('The superposition could not be loaded ('
          + escape(String((error && error.message) || error))
          + '). The scores below are unaffected.');
      });
  }

  function bindViewer() {
    var root = els.results.querySelector('[data-fc-viewer]');
    if (!root) { return; }

    var scheme = root.querySelector('[data-fc-scheme]');
    if (scheme) {
      scheme.addEventListener('change', function () {
        state.scheme = scheme.value;
        applyScheme();
      });
    }
    ['[data-fc-show-target]', '[data-fc-show-query]'].forEach(function (selector) {
      var box = root.querySelector(selector);
      if (box) { box.addEventListener('change', applyScheme); }
    });
    var reset = root.querySelector('[data-fc-reset]');
    if (reset) {
      reset.addEventListener('click', function () {
        if (state.viewer) { state.viewer.zoomTo(); state.viewer.render(); }
      });
    }
    var spin = root.querySelector('[data-fc-spin]');
    if (spin) {
      spin.addEventListener('click', function () {
        var on = spin.classList.toggle('is-on');
        if (state.viewer) { state.viewer.spin(on ? 'y' : false); }
      });
    }

    /* 3Dmol sizes its canvas from the container at creation, so a viewer built
       in a collapsed pane or a background tab stays blank without this. */
    var host = root.querySelector('[data-fc-viewport]');
    if (window.ResizeObserver && host) {
      var observer = new window.ResizeObserver(MGDB.debounce(function () {
        if (state.viewer && host.clientWidth) {
          state.viewer.resize();
          state.viewer.render();
        }
      }, 150));
      observer.observe(host);
    }
    window.addEventListener('resize', MGDB.debounce(function () {
      if (state.viewer) { state.viewer.resize(); state.viewer.render(); }
    }, 200));
  }

  /* --------------------------------------------------------------------- *
   * Lookup
   * --------------------------------------------------------------------- */

  function runCompare(term) {
    term = String(term || '').trim();
    if (!term) { return; }
    closeSuggestions();
    state.viewer = null;
    els.results.innerHTML = '<div class="mgdb-loading" role="status">'
      + '<span class="mgdb-spinner" aria-hidden="true"></span> '
      + 'Comparing structures across four proteomes…</div>';

    MGDB.request(API + '?action=compare&term=' + encodeURIComponent(term), { key: 'fc-compare' })
      .then(function (data) {
        renderCompare(data);
        MGDB.announce('Ortholog comparison for ' + term + ' loaded.');
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', '/fatcat?term=' + encodeURIComponent(term));
        }
      })
      .catch(function () {
        els.results.innerHTML = '<div class="mgdb-message mgdb-message-error">'
          + '<p>The comparison service could not be reached. The documentation and links on this '
          + 'page are unaffected, and every structure is also available directly from '
          + '<a href="https://alphafold.ebi.ac.uk/">AlphaFold DB</a>.</p></div>';
      });
  }

  /* --------------------------------------------------------------------- *
   * Init
   * --------------------------------------------------------------------- */

  function init() {
    els.form = document.getElementById('fc-search-form');
    if (!els.form) { return; }
    els.input = document.getElementById('fc-search-input');
    els.suggestions = document.getElementById('fc-suggestions');
    els.results = document.getElementById('fc-results');

    els.form.addEventListener('submit', function (event) {
      event.preventDefault();
      runCompare(els.input.value);
    });

    els.input.addEventListener('input', MGDB.debounce(function () {
      var term = els.input.value.trim();
      if (term.length < 2) { closeSuggestions(); return; }
      MGDB.request(API + '?action=suggest&term=' + encodeURIComponent(term), { key: 'fc-suggest' })
        .then(renderSuggestions)
        .catch(closeSuggestions);
    }, 140));

    els.input.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown') { event.preventDefault(); moveSuggestion(1); }
      else if (event.key === 'ArrowUp') { event.preventDefault(); moveSuggestion(-1); }
      else if (event.key === 'Escape') { closeSuggestions(); }
    });

    document.addEventListener('click', function (event) {
      if (els.suggestions && !els.suggestions.hidden
          && !els.suggestions.contains(event.target) && event.target !== els.input) {
        closeSuggestions();
      }
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-fc-example]'), function (button) {
      button.addEventListener('click', function () {
        els.input.value = button.getAttribute('data-fc-example');
        runCompare(button.getAttribute('data-fc-example'));
      });
    });

    /* The controller puts an identifier from /fatcat/<id>, ?uniprot= or ?term=
       into the field; all three URL forms predate this page and still work. */
    if (els.input.value.trim()) { runCompare(els.input.value); }
  }

  /* The sticky section tabs. `.mgdb-section-tabs` is styled by the shell but
     driven per page, and this page shipped without a spy: the bar highlighted
     whatever the template marked and never changed, silently. MGDB.sectionTabs
     is that behaviour, shared, so this is the only line a page needs. */
  function boot() {
    init();
    if (window.MGDB && MGDB.sectionTabs) { MGDB.sectionTabs({ watch: '#fc-find' }); }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window, document);
