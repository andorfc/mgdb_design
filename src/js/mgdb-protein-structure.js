/* ==========================================================================
   /data_center/protein_structure — structure search and the 3D workspace
   --------------------------------------------------------------------------
   Three jobs, deliberately not coupled:

     the search   asks search/protein_structure/protein_structure_api.php and
                  renders what comes back — typeahead, then a full lookup
     the viewer   loads a model file from the archive that published it and
                  draws it with 3Dmol
     the panels   the metrics, partner cards, pLDDT strip and PAE map, all
                  derived from data already in hand

   The viewer is ported from the Boltz-2 complex viewer: the pLDDT palettes and
   binning, the colour-function approach to 3Dmol styling, the per-residue
   confidence strip, the surface handling and the legend/colourbar. What is not
   carried over is everything that made sense for a single inlined dataset and
   not for a database page — the scripted camera tour, the movie recorder, the
   presentation mode, the draggable pane splitters, and the ligand affinity
   scorecard, which has no counterpart in AlphaFold complex data.

   The substantive difference from the original is where per-residue confidence
   comes from. The Boltz viewer shipped a precomputed res_plddt array alongside
   each structure. AlphaFold model files carry the same information in the
   B-factor column, so here it is derived from the parsed structure — see
   residueProfile(). That means the strip works for any model the viewer can
   open, including ESMFold, with nothing precomputed.

   If this file fails to load, or WebGL is unavailable, the page keeps its
   documentation, its counts and its links, and the template says so in a
   <noscript>. The search and the viewer are the only parts that genuinely need
   scripting.

   Nothing here touches the DOM before DOMContentLoaded. Bauplan emits every
   includeScript() into <head>, so a top-level querySelector runs while <main>
   does not yet exist and returns null.

   Depends on MGDB from /js/mgdb-modern.js and $3Dmol from /js/lib/3dmol/.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var API = '/search/protein_structure/protein_structure_api.php';
  var escape = MGDB.escapeHtml;

  /* Resolved in init(), never before. */
  var form = null;
  var input = null;
  var panel = null;
  var results = null;
  var esmForm = null;
  var esmInput = null;
  var esmResults = null;

  var lookup = null;          /* the last lookup payload */
  var activeType = null;      /* 'monomer' | 'homodimer' | 'heterodimer' | 'esmfold' */
  var activeList = [];
  var suggestions = [];
  var activeSuggestion = -1;

  /* One viewer at a time. Switching models disposes the previous one rather
     than stacking WebGL contexts — browsers cap those at around 16, and a
     reader comparing partners will blow through that in a minute. */
  var viewer = null;

  function byId(id) { return document.getElementById(id); }

  function reducedMotion() {
    return MGDB.prefersReducedMotion ? MGDB.prefersReducedMotion() : false;
  }

  function num(value, digits) {
    var parsed = Number(value);
    if (!isFinite(parsed)) { return 'NA'; }
    return parsed.toFixed(digits === undefined ? 2 : digits);
  }

  /* ------------------------------------------------------------------------
     Palettes — carried over from the Boltz-2 viewer

     The pLDDT bins and their colours are AlphaFold's own. Keeping them exactly
     means a reader who knows the AlphaFold database reads this viewer without
     relearning anything, and a screenshot from either sits beside the other.
     ------------------------------------------------------------------------ */

  var PLDDT_BINS = [
    { min: 90, color: '#0053D6', label: 'Very high (≥90)' },
    { min: 70, color: '#65CBF3', label: 'Confident (70–90)' },
    { min: 50, color: '#FFDB13', label: 'Low (50–70)' },
    { min: 0,  color: '#FF7D45', label: 'Very low (<50)' }
  ];
  var CHAIN_COLORS = { A: '#8fa6c4', B: '#ff9f40', C: '#4dd0e1', D: '#c792ea' };
  var SS_COLORS = { h: '#ff5c8a', s: '#ffd54f', c: '#8d99ae' };
  /* Kyte-Doolittle hydropathy, keyed by one-letter code. */
  var KD = { I: 4.5, V: 4.2, L: 3.8, F: 2.8, C: 2.5, M: 1.9, A: 1.8, G: -0.4,
             T: -0.7, S: -0.8, W: -0.9, Y: -1.3, P: -1.6, H: -3.2, E: -3.5,
             Q: -3.5, D: -3.5, N: -3.5, K: -3.9, R: -4.5, X: 0 };
  var THREE_TO_ONE = {
    ALA: 'A', ARG: 'R', ASN: 'N', ASP: 'D', CYS: 'C', GLN: 'Q', GLU: 'E',
    GLY: 'G', HIS: 'H', ILE: 'I', LEU: 'L', LYS: 'K', MET: 'M', PHE: 'F',
    PRO: 'P', SER: 'S', THR: 'T', TRP: 'W', TYR: 'Y', VAL: 'V'
  };

  var RAMP_PLDDT = [[0, [255, 80, 50]], [0.5, [255, 219, 19]], [0.75, [101, 203, 243]], [1, [0, 60, 200]]];
  var RAMP_HYDRO = [[0, [60, 130, 220]], [0.5, [240, 240, 240]], [1, [220, 90, 40]]];

  function hexColor(r, g, b) {
    function pair(value) {
      var text = Math.max(0, Math.min(255, Math.round(value))).toString(16);
      return text.length < 2 ? '0' + text : text;
    }
    return '#' + pair(r) + pair(g) + pair(b);
  }

  function ramp(t, stops) {
    t = Math.max(0, Math.min(1, t));
    for (var i = 0; i < stops.length - 1; i++) {
      var from = stops[i], to = stops[i + 1];
      if (t >= from[0] && t <= to[0]) {
        var u = (t - from[0]) / ((to[0] - from[0]) || 1);
        return hexColor(
          from[1][0] + (to[1][0] - from[1][0]) * u,
          from[1][1] + (to[1][1] - from[1][1]) * u,
          from[1][2] + (to[1][2] - from[1][2]) * u
        );
      }
    }
    var last = stops[stops.length - 1][1];
    return hexColor(last[0], last[1], last[2]);
  }

  function plddtBinColor(value) {
    for (var i = 0; i < PLDDT_BINS.length; i++) {
      if (value >= PLDDT_BINS[i].min) { return PLDDT_BINS[i].color; }
    }
    return PLDDT_BINS[PLDDT_BINS.length - 1].color;
  }

  /* ------------------------------------------------------------------------
     Suggestions
     ------------------------------------------------------------------------ */

  function closeSuggestions() {
    if (!panel) { return; }
    panel.hidden = true;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
    activeSuggestion = -1;
  }

  function suggestionCounts(item) {
    function cell(count, label) {
      var zero = count ? '' : ' ps-zero';
      return '<span class="' + escape(zero.trim()) + '"><b>' + count + '</b> ' + label + '</span>';
    }
    return '<span class="ps-suggestion-counts">'
      + cell(item.monomer_count, 'mono')
      + cell(item.homo_count, 'homo')
      + cell(item.hetero_count, 'hetero')
      + '</span>';
  }

  function renderSuggestions(payload) {
    suggestions = (payload && payload.suggestions) || [];
    if (!suggestions.length) { closeSuggestions(); return; }

    panel.innerHTML = '<div class="mgdb-suggestions-content">' + suggestions.map(function (item, index) {
      /* Show the aliases that are not already the label, so a row explains why
         it matched rather than repeating itself. */
      var aliases = [].concat(item.gene_ids || [], item.symbols || [], item.uniprots || [])
        .filter(function (value, position, values) {
          return value && value !== item.label && values.indexOf(value) === position;
        }).slice(0, 4);
      return '<button class="mgdb-suggestion ps-suggestion" type="button" role="option"'
        + ' id="ps-option-' + index + '" aria-selected="false"'
        + ' data-ps-term="' + escape(item.key) + '"'
        + ' data-ps-label="' + escape(item.label) + '">'
        + '<span class="mgdb-suggestion-copy"><strong>' + escape(item.label) + '</strong>'
        + '<small>' + escape(aliases.join(' · ') || 'Indexed AlphaFold structure') + '</small></span>'
        + suggestionCounts(item)
        + '</button>';
    }).join('') + '</div>';

    panel.hidden = false;
    input.setAttribute('aria-expanded', 'true');

    Array.prototype.forEach.call(panel.querySelectorAll('[data-ps-term]'), function (button) {
      button.addEventListener('click', function () {
        input.value = button.getAttribute('data-ps-label');
        closeSuggestions();
        runLookup(button.getAttribute('data-ps-term'));
      });
    });
  }

  function moveSuggestion(delta) {
    var options = panel.querySelectorAll('[role="option"]');
    if (!options.length || panel.hidden) { return; }
    activeSuggestion = (activeSuggestion + delta + options.length) % options.length;
    Array.prototype.forEach.call(options, function (option, index) {
      option.setAttribute('aria-selected', index === activeSuggestion ? 'true' : 'false');
    });
    input.setAttribute('aria-activedescendant', options[activeSuggestion].id);
    options[activeSuggestion].scrollIntoView({ block: 'nearest' });
  }

  var askSuggest = MGDB.debounce(function (term) {
    MGDB.request(API + '?action=suggest&term=' + encodeURIComponent(term), { key: 'ps-suggest' })
      .then(renderSuggestions)
      .catch(function () { closeSuggestions(); });
  }, 160);

  /* ------------------------------------------------------------------------
     Lookup and results
     ------------------------------------------------------------------------ */

  function scoreClass(value, high, mid) {
    var parsed = Number(value);
    if (!isFinite(parsed)) { return ''; }
    if (parsed >= high) { return ' ps-score-high'; }
    if (parsed >= mid) { return ' ps-score-mid'; }
    return ' ps-score-low';
  }

  function partnerLabel(partner) {
    return partner.symbol || partner.gene || partner.uniprot || 'Unnamed chain';
  }

  function geneLink(id) {
    return '<a href="/gene_center/gene/' + encodeURIComponent(id) + '">' + escape(id) + '</a>';
  }

  function renderIdentity(data) {
    var identity = data.identity || {};
    var label = identity.label || data.query;
    var aliases = [].concat(identity.gene_ids || [], identity.symbols || [], identity.uniprots || [])
      .filter(function (value, position, values) {
        return value && value !== label && values.indexOf(value) === position;
      });

    var links = [];
    if ((identity.gene_ids || []).length) {
      links.push('<a class="mgdb-button mgdb-button-secondary" href="/gene_center/gene/'
        + encodeURIComponent(identity.gene_ids[0]) + '">Gene record</a>');
    }
    if ((identity.uniprots || []).length) {
      links.push('<a class="mgdb-button mgdb-button-quiet" rel="noopener" href="https://www.uniprot.org/uniprotkb/'
        + encodeURIComponent(identity.uniprots[0]) + '">UniProt ↗</a>');
    }
    if ((identity.gene_ids || []).length) {
      links.push('<a class="mgdb-button mgdb-button-quiet" href="/foldseek?uniprot='
        + encodeURIComponent(identity.gene_ids[0]) + '">Foldseek</a>');
    }
    /* AlphaFill answers the question this page cannot: not what shape the
       protein is, but what it probably binds. The link is unconditional
       because /data_center/alphafill distinguishes "no transplant" from "no
       model" from "not a gene", and each of those is worth landing on --
       gating the link here would collapse them back into silence. */
    if ((identity.gene_ids || []).length) {
      links.push('<a class="mgdb-button mgdb-button-quiet" href="/data_center/alphafill?gene='
        + encodeURIComponent(identity.gene_ids[0]) + '">Predicted ligands</a>');
    }

    /* Saying which route answered is not trivia: "structure index" means the
       identifier was matched exactly, "MaizeGDB gene database" means it was
       translated to another name first, and a reader looking at partner
       assignments deserves to know which happened. */
    var via = data.resolved_from
      ? '<p class="mgdb-small">Matched through the ' + escape(data.resolved_from) + '.</p>'
      : '';

    return '<div class="ps-identity"><div><span class="mgdb-eyebrow">Matched protein</span>'
      + '<h3>' + escape(label) + '</h3>'
      + '<p class="ps-identity-aliases">' + escape(aliases.join(' · ') || 'No other identifiers indexed') + '</p>'
      + via
      + '</div><div class="ps-identity-links">' + links.join('') + '</div></div>';
  }

  function renderTabs(data) {
    var groups = [
      { type: 'monomer', label: 'Monomer', list: data.monomers || [] },
      { type: 'homodimer', label: 'Homodimer', list: data.homodimers || [] },
      { type: 'heterodimer', label: 'Heterodimer', list: data.heterodimers || [] }
    ];
    return '<div class="ps-tabs" role="tablist" aria-label="Assembly state">'
      + groups.map(function (group) {
        var disabled = group.list.length ? '' : ' disabled';
        return '<button class="ps-tab" type="button" role="tab"'
          + ' id="ps-tab-' + group.type + '"'
          + ' aria-selected="false" aria-controls="ps-browser"'
          + ' data-ps-type="' + group.type + '"' + disabled + '>'
          + escape(group.label)
          + '<span class="ps-tab-count">' + group.list.length + '</span></button>';
      }).join('') + '</div>';
  }

  function candidateMarkup(record, type) {
    var name = (record.partners || []).map(partnerLabel).join(' + ') || record.id;
    var metrics = record.metrics || {};
    var pills = [];

    if (type === 'monomer') {
      pills.push('<span class="ps-score-pill' + scoreClass(metrics.plddt, 90, 70) + '">pLDDT '
        + num(metrics.plddt, 1) + '</span>');
      if (metrics.sequence_length) {
        pills.push('<span class="ps-score-pill">' + metrics.sequence_length + ' aa</span>');
      }
    } else {
      pills.push('<span class="ps-score-pill' + scoreClass(metrics.ipsae, 0.75, 0.5) + '">ipSAE '
        + num(metrics.ipsae) + '</span>');
      pills.push('<span class="ps-score-pill' + scoreClass(metrics.iptm, 0.8, 0.6) + '">ipTM '
        + num(metrics.iptm) + '</span>');
      pills.push('<span class="ps-score-pill">pLDDT ' + num(metrics.plddt, 1) + '</span>');
    }

    var flag = record.display === false
      ? '<span class="ps-candidate-flag">Flagged low quality by the source export</span>'
      : '';

    return '<button class="ps-candidate" type="button" data-ps-model="' + escape(record.id) + '"'
      + ' aria-current="false">'
      + '<span class="ps-candidate-name">' + escape(name) + '</span>'
      + '<span class="ps-candidate-id">' + escape(record.id) + '</span>'
      + '<span class="ps-candidate-scores">' + pills.join('') + '</span>'
      + flag + '</button>';
  }

  function renderCandidates(type) {
    var listMap = {
      monomer: lookup.monomers || [],
      homodimer: lookup.homodimers || [],
      heterodimer: lookup.heterodimers || []
    };
    activeType = type;
    activeList = listMap[type] || [];

    Array.prototype.forEach.call(results.querySelectorAll('[data-ps-type]'), function (tab) {
      tab.setAttribute('aria-selected', tab.getAttribute('data-ps-type') === type ? 'true' : 'false');
    });

    var host = byId('ps-candidates');
    if (!host) { return; }
    if (!activeList.length) {
      host.innerHTML = '<div class="mgdb-empty"><p>No ' + escape(type) + ' model is indexed for this protein.</p></div>';
      byId('ps-model').innerHTML = '';
      return;
    }

    var truncated = (lookup.truncated || {})[type + 's'];
    var total = (lookup.counts || {})[type + 's'];
    var note = truncated
      ? '<p class="ps-truncation-note">Showing the ' + activeList.length
        + ' best-scoring of ' + total + ' indexed ' + escape(type)
        + ' models. The rest are in the AlphaFold database.</p>'
      : '';

    host.innerHTML = activeList.map(function (record) {
      return candidateMarkup(record, type);
    }).join('') + note;

    Array.prototype.forEach.call(host.querySelectorAll('[data-ps-model]'), function (button) {
      button.addEventListener('click', function () {
        var id = button.getAttribute('data-ps-model');
        var record = activeList.filter(function (item) { return item.id === id; })[0];
        if (record) { showModel(record, type); }
      });
    });

    showModel(activeList[0], type);
  }

  function renderResults(data) {
    lookup = data;

    if (!data.found && !data.gene_exists) {
      results.innerHTML = '<div class="mgdb-message mgdb-message-error">'
        + '<p><strong>Nothing at MaizeGDB matches “' + escape(data.query) + '”.</strong></p>'
        + '<p>This page accepts B73 RefGen_v5 gene models, older annotations that map to one, '
        + 'locus and gene symbols, UniProt accessions, and AlphaFold model identifiers.</p></div>';
      return;
    }

    /* A real gene with no predicted structure. Distinct from an unrecognised
       identifier, and the reader's next move is different, so say which. */
    if (!data.found) {
      var identity = data.identity || {};
      var gene = (identity.gene_ids || [])[0] || data.query;
      results.innerHTML = renderIdentity(data)
        + '<div class="mgdb-message mgdb-message-info">'
        + '<p><strong>This is a maize gene, but no AlphaFold model is indexed for it.</strong></p>'
        + '<p>The AlphaFold collection is keyed on UniProt accessions for B73 RefGen_v5. A gene '
        + 'with no reviewed protein entry, or one whose model predates the current annotation, '
        + 'will not appear here even though the gene is real.</p>'
        + '<p>Worth trying next: the <a href="/gene_center/gene/' + encodeURIComponent(gene)
        + '">gene record</a> for its protein and domain annotations, '
        + '<a href="#ps-esmfold">ESMFold</a> if this is a RefGen_v5 model, or '
        + '<a href="/foldseek?uniprot=' + encodeURIComponent(gene) + '">Foldseek</a> '
        + 'to find a structural relative that does have one.</p></div>';
      return;
    }

    results.innerHTML = renderIdentity(data)
      + renderTabs(data)
      + '<div class="ps-browser" id="ps-browser">'
      + '<div class="ps-candidates" id="ps-candidates"></div>'
      + '<div class="ps-model-detail" id="ps-model"></div>'
      + '</div>';

    Array.prototype.forEach.call(results.querySelectorAll('[data-ps-type]'), function (tab) {
      tab.addEventListener('click', function () {
        if (tab.hasAttribute('disabled')) { return; }
        renderCandidates(tab.getAttribute('data-ps-type'));
      });
    });

    /* Open on the most informative group that has anything in it. A
       heterodimer says more than a monomer of the same protein. */
    var first = (data.heterodimers || []).length ? 'heterodimer'
              : ((data.homodimers || []).length ? 'homodimer' : 'monomer');
    renderCandidates(first);
  }

  function runLookup(term) {
    term = String(term || '').trim();
    if (!term) { return; }
    closeSuggestions();
    results.innerHTML = '<div class="mgdb-loading" role="status">Finding predicted structures…</div>';
    MGDB.request(API + '?action=lookup&term=' + encodeURIComponent(term), { key: 'ps-lookup' })
      .then(function (data) {
        renderResults(data);
        MGDB.announce('Structure results for ' + term + ' loaded.');
      })
      .catch(function () {
        results.innerHTML = '<div class="mgdb-message mgdb-message-error">'
          + '<p>The structure index could not be reached. Try again in a moment.</p></div>';
      });
  }

  /* ------------------------------------------------------------------------
     Per-residue confidence, derived from the model

     AlphaFold and ESMFold both write per-residue pLDDT into the B-factor
     column, so the profile is read off the parsed structure rather than
     shipped alongside it. One CA atom per residue is the sample: every
     standard residue has exactly one, which makes it a natural key and avoids
     weighting large side chains more heavily than small ones.
     ------------------------------------------------------------------------ */

  function residueProfile(model) {
    var atoms = model.selectedAtoms({});
    var residues = [];
    var byKey = {};
    var oneLetter = {};

    for (var i = 0; i < atoms.length; i++) {
      var atom = atoms[i];
      if (atom.hetflag) { continue; }
      if (atom.atom !== 'CA') { continue; }
      var key = atom.chain + ':' + atom.resi;
      if (byKey[key]) { continue; }
      byKey[key] = true;
      residues.push({
        chain: atom.chain,
        resi: atom.resi,
        name: THREE_TO_ONE[atom.resn] || 'X',
        plddt: Number(atom.b)
      });
      oneLetter[key] = THREE_TO_ONE[atom.resn] || 'X';
    }

    var chains = [];
    var seenChain = {};
    residues.forEach(function (residue) {
      if (!seenChain[residue.chain]) {
        seenChain[residue.chain] = true;
        chains.push(residue.chain);
      }
    });

    return { residues: residues, oneLetter: oneLetter, chains: chains };
  }

  /* ------------------------------------------------------------------------
     The viewer
     ------------------------------------------------------------------------ */

  function colorFunction(scheme, profile) {
    if (scheme === 'plddt5') { return function (atom) { return plddtBinColor(atom.b); }; }
    if (scheme === 'plddtc') { return function (atom) { return ramp(atom.b / 100, RAMP_PLDDT); }; }
    if (scheme === 'chain')  { return function (atom) { return CHAIN_COLORS[atom.chain] || '#9aa7b8'; }; }
    if (scheme === 'ss')     { return function (atom) { return SS_COLORS[atom.ss] || SS_COLORS.c; }; }
    if (scheme === 'hydro')  {
      return function (atom) {
        var one = profile.oneLetter[atom.chain + ':' + atom.resi] || 'X';
        var value = KD[one] === undefined ? 0 : KD[one];
        return ramp((value + 4.5) / 9, RAMP_HYDRO);
      };
    }
    return null;   /* spectrum is handled by 3Dmol's own colorscheme */
  }

  /* Every viewer-internal lookup goes through here, scoped to that viewer's
     own root element. */
  function q(state, selector) {
    return state.root ? state.root.querySelector(selector) : null;
  }

  function drawLegend(state) {
    var host = q(state, '[data-ps-legend]');
    var bar = q(state, '[data-ps-colorbar]');
    if (!host || !bar) { return; }

    var items = [], gradient = null, low = '', high = '', title = '';
    var scheme = state.scheme;

    if (scheme === 'plddt5') {
      items = PLDDT_BINS.map(function (bin) { return [bin.color, bin.label]; });
    } else if (scheme === 'plddtc') {
      gradient = 'linear-gradient(90deg,#ff5032,#ffdb13,#65cbf3,#003cc8)';
      low = '0'; high = '100'; title = 'pLDDT';
    } else if (scheme === 'chain') {
      items = state.profile.chains.map(function (chain) {
        var partner = state.record.partners && state.record.partners[state.profile.chains.indexOf(chain)];
        return [CHAIN_COLORS[chain] || '#9aa7b8',
                'Chain ' + chain + (partner ? ' — ' + partnerLabel(partner) : '')];
      });
    } else if (scheme === 'ss') {
      items = [[SS_COLORS.h, 'Helix'], [SS_COLORS.s, 'Sheet'], [SS_COLORS.c, 'Loop / coil']];
    } else if (scheme === 'hydro') {
      gradient = 'linear-gradient(90deg,#3c82dc,#f0f0f0,#dc5a28)';
      low = 'hydrophilic'; high = 'hydrophobic'; title = 'Kyte–Doolittle';
    } else if (scheme === 'spectrum') {
      gradient = 'linear-gradient(90deg,#4040ff,#40ffff,#40ff40,#ffff40,#ff4040)';
      low = 'N-terminus'; high = 'C-terminus'; title = 'Sequence position';
    }

    host.innerHTML = items.map(function (item) {
      return '<div><i style="background:' + escape(item[0]) + '"></i>' + escape(item[1]) + '</div>';
    }).join('');

    if (gradient) {
      bar.hidden = false;
      q(state, '[data-ps-colorbar-title]').textContent = title;
      q(state, '[data-ps-colorbar-grad]').style.background = gradient;
      q(state, '[data-ps-colorbar-lo]').textContent = low;
      q(state, '[data-ps-colorbar-hi]').textContent = high;
    } else {
      bar.hidden = true;
    }
  }

  function applyStyles(state) {
    if (!state.viewer || !state.model) { return; }

    var representation = q(state, '[data-ps-rep]').value;
    var scheme = q(state, '[data-ps-color]').value;
    var thickness = parseInt(q(state, '[data-ps-thickness]').value, 10) / 100;
    var paint = colorFunction(scheme, state.profile);
    var protein = { hetflag: false };

    state.scheme = scheme;
    state.viewer.setStyle({}, {});

    function colored(extra) {
      var options = extra || {};
      if (scheme === 'spectrum') { options.color = 'spectrum'; }
      else { options.colorfunc = paint; }
      return options;
    }

    if (representation !== 'none') {
      if (representation === 'cartoon' || representation === 'ribbon'
          || representation === 'tube' || representation === 'cartoon+stick') {
        var style = colored({ thickness: 0.4 * thickness, opacity: 1 });
        if (representation === 'tube')   { style.style = 'trace'; style.thickness = 0.35 * thickness; }
        if (representation === 'ribbon') { style.style = 'oval'; style.arrows = false; }
        if (representation === 'cartoon' || representation === 'cartoon+stick') {
          style.arrows = true; style.tubes = true;
        }
        state.viewer.setStyle(protein, { cartoon: style });
        if (representation === 'cartoon+stick') {
          state.viewer.addStyle(protein, { stick: colored({ radius: 0.13 * thickness }) });
        }
      } else if (representation === 'stick') {
        state.viewer.setStyle(protein, { stick: colored({ radius: 0.16 * thickness }) });
      } else if (representation === 'line') {
        state.viewer.setStyle(protein, { line: colored({ linewidth: 1.6 * thickness }) });
      } else if (representation === 'sphere') {
        state.viewer.setStyle(protein, { sphere: colored({ scale: 0.85 * thickness }) });
      }
    }

    /* Any non-protein atoms the file carries — ions, cofactors, waters kept by
       the depositor. Always drawn, always by element: they are not what the
       colour scheme is describing. */
    state.viewer.addStyle({ hetflag: true }, {
      stick: { radius: 0.17, colorscheme: 'default' },
      sphere: { scale: 0.26, colorscheme: 'default' }
    });

    state.viewer.removeAllSurfaces();
    if (q(state, '[data-ps-surface]').checked) {
      var opacity = parseInt(q(state, '[data-ps-surface-opacity]').value, 10) / 100;
      var surface = { opacity: opacity };
      if (paint) { surface.colorfunc = paint; } else { surface.color = '#8fa6c4'; }
      state.viewer.addSurface($3Dmol.SurfaceType.VDW, surface, { hetflag: false });
    }

    state.viewer.setViewStyle({ style: q(state, '[data-ps-outline]').checked ? 'outline' : '' });
    drawLegend(state);
    state.viewer.render();
  }

  function drawStrip(state) {
    var canvas = q(state, '[data-ps-strip]');
    if (!canvas || !state.profile.residues.length) { return; }

    var residues = state.profile.residues;
    var width = canvas.clientWidth || 800;
    var height = 96;
    var ratio = window.devicePixelRatio || 1;
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    var context = canvas.getContext('2d');
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, width, height);

    var padLeft = 22, plotWidth = width - padLeft - 6, plotHeight = height - 20;

    /* Confidence bands behind the bars, so a reader can see which band a run
       of residues sits in without reading the axis. */
    [[90, 100, 'rgba(0,83,214,.10)'], [70, 90, 'rgba(101,203,243,.10)'],
     [50, 70, 'rgba(255,219,19,.10)'], [0, 50, 'rgba(255,125,69,.10)']]
      .forEach(function (band) {
        var top = 4 + plotHeight * (1 - band[1] / 100);
        var bottom = 4 + plotHeight * (1 - band[0] / 100);
        context.fillStyle = band[2];
        context.fillRect(padLeft, top, plotWidth, bottom - top);
      });

    context.strokeStyle = '#2d3748';
    context.lineWidth = 1;
    context.fillStyle = '#9aa7b8';
    context.font = '9px sans-serif';
    [0, 50, 70, 90, 100].forEach(function (value) {
      var y = 4 + plotHeight * (1 - value / 100);
      context.beginPath();
      context.moveTo(padLeft, y);
      context.lineTo(width - 6, y);
      context.stroke();
      context.fillText(String(value), 2, y + 3);
    });

    var barWidth = plotWidth / residues.length;
    residues.forEach(function (residue, index) {
      var value = residue.plddt;
      var barHeight = plotHeight * (value / 100);
      context.fillStyle = plddtBinColor(value);
      context.fillRect(padLeft + index * barWidth, 4 + plotHeight - barHeight,
                       Math.max(0.7, barWidth - 0.25), barHeight);
    });

    /* Chain boundaries. On a dimer this is the single most useful mark on the
       strip: it says which half of the plot belongs to which partner. */
    context.strokeStyle = '#e6edf3';
    context.lineWidth = 1;
    for (var i = 1; i < residues.length; i++) {
      if (residues[i].chain !== residues[i - 1].chain) {
        var x = padLeft + i * barWidth;
        context.beginPath();
        context.moveTo(x, 2);
        context.lineTo(x, 4 + plotHeight);
        context.stroke();
        context.fillStyle = '#e6edf3';
        context.fillText(residues[i].chain, x + 3, 12);
      }
    }

    var mean = residues.reduce(function (total, residue) { return total + residue.plddt; }, 0) / residues.length;
    var lowFraction = residues.filter(function (residue) { return residue.plddt < 50; }).length
                    / residues.length * 100;
    q(state, '[data-ps-strip-meta]').innerHTML = 'mean <b>' + mean.toFixed(1) + '</b> · <b>'
      + lowFraction.toFixed(0) + '%</b> below 50 · <b>' + residues.length + '</b> residues';

    var tip = q(state, '[data-ps-strip-tip]');
    canvas.onmousemove = function (event) {
      var box = canvas.getBoundingClientRect();
      var x = event.clientX - box.left;
      var index = Math.floor((x - padLeft) / barWidth);
      if (index < 0 || index >= residues.length) { tip.hidden = true; return; }
      var residue = residues[index];
      tip.hidden = false;
      tip.style.left = Math.min(width - 150, Math.max(0, x + 8)) + 'px';
      tip.textContent = residue.chain + ' · ' + residue.name + residue.resi
        + ' · pLDDT ' + residue.plddt.toFixed(1);
    };
    canvas.onmouseleave = function () { tip.hidden = true; };
    canvas.onclick = function (event) {
      var box = canvas.getBoundingClientRect();
      var index = Math.floor((event.clientX - box.left - padLeft) / barWidth);
      if (index < 0 || index >= residues.length) { return; }
      focusResidue(state, residues[index]);
    };
  }

  function focusResidue(state, residue) {
    var selection = { chain: residue.chain, resi: residue.resi, hetflag: false };
    state.viewer.addStyle(selection, { stick: { radius: 0.26, color: '#ffffff' } });
    state.viewer.zoomTo(selection, reducedMotion() ? 0 : 550);
    state.viewer.render();
    setViewerStatus(state, residue.chain + ' ' + residue.name + residue.resi
      + ' — pLDDT ' + residue.plddt.toFixed(1));
    window.setTimeout(function () { applyStyles(state); }, 2600);
  }

  function setViewerStatus(state, message) {
    var status = q(state, '[data-ps-status]');
    if (status) { status.textContent = message; }
  }

  /* ------------------------------------------------------------------------
     Metrics and partner cards
     ------------------------------------------------------------------------ */

  function metricCard(term, value, note) {
    return '<div class="ps-metric"><dt>' + escape(term) + '</dt><dd>' + escape(value) + '</dd>'
      + (note ? '<p>' + escape(note) + '</p>' : '') + '</div>';
  }

  function renderMetrics(record, type) {
    var metrics = record.metrics || {};
    if (type === 'monomer') {
      var confident = (Number(metrics.confident) || 0) + (Number(metrics.very_high) || 0);
      return '<dl class="ps-metric-row">'
        + metricCard('pLDDT', num(metrics.plddt, 1), 'mean per-residue confidence')
        + metricCard('Length', String(metrics.sequence_length || 'NA'), 'amino acids')
        + metricCard('Confident', isFinite(confident) ? Math.round(confident * 100) + '%' : 'NA',
                     'residues at pLDDT 70 or above')
        + metricCard('Reviewed', record.reviewed ? 'Swiss-Prot' : 'TrEMBL',
                     record.reviewed ? 'manually annotated entry' : 'unreviewed entry')
        + '</dl>';
    }
    return '<dl class="ps-metric-row">'
      + metricCard('ipSAE', num(metrics.ipsae), 'interface confidence — read this first')
      + metricCard('ipTM', num(metrics.iptm), 'whole-complex confidence')
      + metricCard('pDockQ', num(metrics.pdockq), 'predicted interface quality')
      + metricCard('Contacts', String(metrics.interactions == null ? 'NA' : metrics.interactions),
                   'predicted residue contacts')
      + metricCard('pLDDT', num(metrics.plddt, 1), 'mean over both chains')
      + '</dl>';
  }

  function renderPartners(record) {
    var partners = record.partners || [];
    return '<div class="ps-partners">' + partners.map(function (partner, index) {
      var chain = String.fromCharCode(65 + index);
      var links = [];
      (partner.gene_ids || []).slice(0, 2).forEach(function (id) { links.push(geneLink(id)); });
      if (partner.uniprot) {
        links.push('<a rel="noopener" href="https://www.uniprot.org/uniprotkb/'
          + encodeURIComponent(partner.uniprot) + '">' + escape(partner.uniprot) + ' ↗</a>');
      }
      /* The export records why a chain was matched to a gene. When that reason
         is anything other than a clean cross-reference, the mapping is the
         weakest link in the result and should be visible. */
      var mapping = partner.mapping && partner.mapping !== 'uniprot_xref'
        ? '<p class="mgdb-small">Mapping: ' + escape(String(partner.mapping).replace(/_/g, ' ')) + '</p>'
        : '';
      return '<article class="ps-partner">'
        + '<span class="ps-chain ps-chain-' + chain.toLowerCase() + '">Chain ' + chain + '</span>'
        + '<h4>' + escape(partnerLabel(partner)) + '</h4>'
        + '<p>' + escape(partner.description || 'No description recorded') + '</p>'
        + (partner.plddt ? '<p class="mgdb-small">Chain pLDDT ' + num(partner.plddt, 1) + '</p>' : '')
        + mapping
        + (links.length ? '<p class="ps-partner-links">' + links.join('') + '</p>' : '')
        + '</article>';
    }).join('') + '</div>';
  }

  /* ------------------------------------------------------------------------
     Predicted aligned error
     ------------------------------------------------------------------------ */

  function renderPae(record) {
    var url = record.pae_json || (record.pae || '').replace(/\.png(\?.*)?$/i, '.json');
    if (!url) { return ''; }
    return '<div class="ps-pae"><h3>Predicted aligned error</h3>'
      + '<p class="mgdb-small">Expected error in the position of residue <i>x</i> when the model is '
      + 'aligned on residue <i>y</i>. Dark blocks are rigid relative to each other; a dark '
      + 'off-diagonal block between two chains is the strongest visual evidence that a predicted '
      + 'interface is real.</p>'
      + '<canvas data-ps-pae="' + escape(url) + '" role="img" aria-label="Predicted aligned error matrix for '
      + escape(record.id) + '"></canvas>'
      + '<div class="ps-pae-scale"><small>0 Å</small><i></i><small>31 Å+</small></div>'
      + '<p class="mgdb-small" data-ps-pae-status>Loading aligned-error matrix…</p></div>';
  }

  function drawPae(canvas) {
    var status = canvas.parentElement.querySelector('[data-ps-pae-status]');
    window.fetch(canvas.getAttribute('data-ps-pae'), { mode: 'cors' })
      .then(function (response) {
        if (!response.ok) { throw new Error('pae'); }
        return response.json();
      })
      .then(function (payload) {
        var data = Array.isArray(payload) ? payload[0] : payload;
        var matrix = data.predicted_aligned_error || [];
        if (!matrix.length) { throw new Error('pae'); }
        var size = matrix.length;
        var maximum = Number(data.max_predicted_aligned_error) || 31;
        canvas.width = size;
        canvas.height = size;
        var context = canvas.getContext('2d');
        var image = context.createImageData(size, size);
        for (var row = 0; row < size; row++) {
          for (var column = 0; column < size; column++) {
            /* Low error is dark green, high error is near the page's cream
               surface — the same direction as the AlphaFold viewer, in this
               site's palette. */
            var confidence = 1 - Math.min(maximum, Number(matrix[row][column]) || 0) / maximum;
            var offset = (row * size + column) * 4;
            image.data[offset]     = Math.round(251 - confidence * 235);
            image.data[offset + 1] = Math.round(249 - confidence * 185);
            image.data[offset + 2] = Math.round(242 - confidence * 184);
            image.data[offset + 3] = 255;
          }
        }
        context.putImageData(image, 0, 0);
        (data.chains || []).slice(0, -1).forEach(function (chain) {
          var boundary = Number(chain.sequenceEnd);
          context.strokeStyle = 'rgba(20,52,37,.75)';
          context.lineWidth = Math.max(1, size / 400);
          context.beginPath(); context.moveTo(boundary, 0); context.lineTo(boundary, size); context.stroke();
          context.beginPath(); context.moveTo(0, boundary); context.lineTo(size, boundary); context.stroke();
        });
        if (status) { status.textContent = 'Residue × residue, chain boundaries marked.'; }
      })
      .catch(function () {
        if (status) { status.textContent = 'No aligned-error matrix is published for this model.'; }
        canvas.hidden = true;
      });
  }

  /* ------------------------------------------------------------------------
     Assembling one model
     ------------------------------------------------------------------------ */

  function viewerMarkup(record, type) {
    var title = (record.partners || []).map(partnerLabel).join(' + ') || record.id;
    var badge = type === 'monomer' ? 'AlphaFold monomer'
              : (type === 'homodimer' ? 'Homodimer' : (type === 'esmfold' ? 'ESMFold model' : 'Heterodimer'));

    /* Every control is addressed by a data attribute and looked up inside this
       root, never by id. The workspace viewer and the ESMFold viewer are both
       on the page at once, so a shared id like "ps-viewport" resolves to
       whichever came first in the document — which had the effect of rendering
       the ESMFold model into the workspace's viewport and overwriting its
       confidence strip. Ids here would have to be uniquified; scoping is
       simpler and cannot drift. */
    return '<div class="ps-viewer" data-ps-viewer>'
      + '<div class="ps-viewer-head">'
      +   '<div class="ps-viewer-title"><strong>' + escape(title) + '</strong>'
      +     '<span>' + escape(badge) + ' · ' + escape(record.id)
      +     (record.tool ? ' · ' + escape(record.tool) : '') + '</span></div>'
      +   '<div class="ps-viewer-actions">'
      +     '<button class="ps-viewer-button" type="button" data-ps-spin aria-pressed="false">Auto-rotate</button>'
      +     '<button class="ps-viewer-button" type="button" data-ps-reset>Reset view</button>'
      +     '<button class="ps-viewer-button" type="button" data-ps-png>Save PNG</button>'
      +     '<a class="ps-viewer-button" href="' + escape(record.pdb) + '" download>Download PDB</a>'
      +     (record.cif ? '<a class="ps-viewer-button" href="' + escape(record.cif) + '" download>mmCIF</a>' : '')
      +     (record.entry ? '<a class="ps-viewer-button" rel="noopener" href="' + escape(record.entry) + '">AlphaFold entry ↗</a>' : '')
      +     '<button class="ps-viewer-button" type="button" data-ps-fullscreen>Fullscreen</button>'
      +   '</div>'
      + '</div>'
      + '<div class="ps-viewport" data-ps-viewport>'
      +   '<div class="ps-hud"><b>' + escape(title) + '</b><span>' + escape(record.id) + '</span></div>'
      +   '<div class="ps-colorbar" data-ps-colorbar hidden>'
      +     '<div data-ps-colorbar-title></div><div class="ps-colorbar-grad" data-ps-colorbar-grad></div>'
      +     '<div class="ps-colorbar-ends"><span data-ps-colorbar-lo></span><span data-ps-colorbar-hi></span></div>'
      +   '</div>'
      +   '<div class="ps-viewer-status" data-ps-status>Loading structure…</div>'
      + '</div>'
      + '<div class="ps-viewer-rail">'
      +   '<div class="ps-rail-group"><h4>Representation</h4>'
      +     '<span class="ps-rail-label">Style</span>'
      +     '<select class="ps-rail-select" data-ps-rep aria-label="Molecular representation">'
      +       '<option value="cartoon">Cartoon</option>'
      +       '<option value="ribbon">Ribbon</option>'
      +       '<option value="tube">Backbone trace</option>'
      +       '<option value="cartoon+stick">Cartoon + side chains</option>'
      +       '<option value="stick">Sticks</option>'
      +       '<option value="line">Lines</option>'
      +       '<option value="sphere">Space-fill</option>'
      +       '<option value="none">Hidden</option>'
      +     '</select>'
      +     '<span class="ps-rail-label">Thickness</span>'
      +     '<input class="ps-rail-range" type="range" data-ps-thickness min="20" max="180" value="100" aria-label="Representation thickness" />'
      +   '</div>'
      +   '<div class="ps-rail-group"><h4>Colour</h4>'
      +     '<span class="ps-rail-label">Scheme</span>'
      +     '<select class="ps-rail-select" data-ps-color aria-label="Colour scheme">'
      +       '<option value="plddt5">pLDDT — AlphaFold bins</option>'
      +       '<option value="plddtc">pLDDT — continuous</option>'
      +       '<option value="chain">Chain</option>'
      +       '<option value="spectrum">Rainbow N→C</option>'
      +       '<option value="ss">Secondary structure</option>'
      +       '<option value="hydro">Hydrophobicity</option>'
      +     '</select>'
      +     '<div class="ps-legend" data-ps-legend></div>'
      +   '</div>'
      +   '<div class="ps-rail-group"><h4>Surface</h4>'
      +     '<label class="ps-rail-check"><input type="checkbox" data-ps-surface /> Show molecular surface</label>'
      +     '<span class="ps-rail-label">Opacity</span>'
      +     '<input class="ps-rail-range" type="range" data-ps-surface-opacity min="5" max="100" value="45" aria-label="Surface opacity" />'
      +     '<p class="ps-rail-hint">Surfaces are the slowest thing here to compute. Turn one off before rotating if the view stutters.</p>'
      +   '</div>'
      +   '<div class="ps-rail-group"><h4>Display</h4>'
      +     '<label class="ps-rail-check"><input type="checkbox" data-ps-outline /> Cartoon outline</label>'
      +     '<p class="ps-rail-hint">Click any bar in the confidence chart below to zoom to that residue.</p>'
      +   '</div>'
      + '</div>'
      + '<div class="ps-strip-wrap">'
      +   '<div class="ps-strip-head"><span>Per-residue pLDDT — click to focus a residue</span>'
      +     '<span data-ps-strip-meta></span></div>'
      +   '<div class="ps-strip-shell"><canvas class="ps-strip" data-ps-strip></canvas>'
      +     '<div class="ps-strip-tip" data-ps-strip-tip hidden></div></div>'
      + '</div>'
      + '</div>';
  }

  function disposeViewer() {
    if (viewer && viewer.viewer) {
      try { viewer.viewer.clear(); } catch (error) { /* already gone */ }
    }
    viewer = null;
  }

  function showModel(record, type) {
    var host = byId('ps-model');
    if (!host || !record) { return; }

    disposeViewer();

    /* The viewer gets the full width of the detail column. It was previously
       sharing that column with the aligned-error panel, which left the 3D
       viewport 322 px wide once the control rail had taken its 240 — narrow
       enough that a dimer did not fit side-on. The PAE map is a small square
       and sits with the partner cards below instead. */
    host.innerHTML = renderMetrics(record, type)
      + viewerMarkup(record, type)
      + '<div class="ps-visual-grid">'
      +   '<div>' + renderPartners(record) + '</div>'
      +   '<div>' + renderPae(record) + '</div>'
      + '</div>';

    Array.prototype.forEach.call(results.querySelectorAll('[data-ps-model]'), function (button) {
      button.setAttribute('aria-current', button.getAttribute('data-ps-model') === record.id ? 'true' : 'false');
    });
    Array.prototype.forEach.call(host.querySelectorAll('canvas[data-ps-pae]'), drawPae);

    startViewer(host, record, type);
  }

  /* host is the element the viewer markup was written into; the root is the
     .ps-viewer inside it. Everything this viewer touches is found under that
     root, so two viewers can coexist on the page. */
  function startViewer(host, record, type) {
    var root = host && host.querySelector('[data-ps-viewer]');
    var state = {
      viewer: null, model: null, record: record, type: type, root: root,
      profile: { residues: [], oneLetter: {}, chains: [] },
      scheme: 'plddt5', spinning: false
    };
    if (!root) { return; }

    var viewport = q(state, '[data-ps-viewport]');
    if (!viewport || typeof window.$3Dmol === 'undefined') {
      setViewerStatus(state, 'The 3D viewer could not start in this browser.');
      return;
    }
    viewer = state;

    state.viewer = $3Dmol.createViewer(viewport, { backgroundColor: '#000000' });

    /* The file is fetched from the archive that published it — AlphaFold DB or
       images.maizegdb.org — not proxied through MaizeGDB. Both send
       Access-Control-Allow-Origin, so this is a plain cross-origin GET. */
    window.fetch(record.pdb, { mode: 'cors' })
      .then(function (response) {
        if (!response.ok) { throw new Error('structure'); }
        return response.text();
      })
      .then(function (text) {
        if (viewer !== state) { return; }   /* superseded while loading */
        state.model = state.viewer.addModel(text, 'pdb');
        state.profile = residueProfile(state.model);
        applyStyles(state);
        state.viewer.zoomTo();
        state.viewer.render();
        drawStrip(state);
        setViewerStatus(state, 'Drag to rotate · scroll to zoom');
        bindViewerControls(state);
      })
      .catch(function () {
        if (viewer !== state) { return; }
        setViewerStatus(state, 'This structure file could not be loaded from its archive.');
      });
  }

  function bindViewerControls(state) {
    ['[data-ps-rep]', '[data-ps-color]', '[data-ps-thickness]',
     '[data-ps-surface]', '[data-ps-surface-opacity]', '[data-ps-outline]']
      .forEach(function (selector) {
        var control = q(state, selector);
        if (!control) { return; }
        control.addEventListener(control.type === 'range' ? 'input' : 'change', function () {
          applyStyles(state);
        });
      });

    var spin = q(state, '[data-ps-spin]');
    if (spin) {
      spin.addEventListener('click', function () {
        state.spinning = !state.spinning;
        state.viewer.spin(state.spinning ? 'y' : false);
        spin.setAttribute('aria-pressed', state.spinning ? 'true' : 'false');
        spin.textContent = state.spinning ? 'Stop rotation' : 'Auto-rotate';
      });
    }

    var reset = q(state, '[data-ps-reset]');
    if (reset) {
      reset.addEventListener('click', function () {
        state.viewer.zoomTo({}, reducedMotion() ? 0 : 500);
        state.viewer.render();
      });
    }

    var png = q(state, '[data-ps-png]');
    if (png) {
      png.addEventListener('click', function () {
        /* 3Dmol renders to a canvas we can read directly; no server round trip
           and no dependency on its own download helper. */
        var uri = state.viewer.pngURI();
        var link = document.createElement('a');
        link.href = uri;
        link.download = state.record.id + '.png';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
      });
    }

    var full = q(state, '[data-ps-fullscreen]');
    if (full) {
      full.addEventListener('click', function () {
        if (!document.fullscreenElement && state.root.requestFullscreen) {
          state.root.requestFullscreen();
        } else if (document.exitFullscreen) {
          document.exitFullscreen();
        }
      });
    }

    /* The viewport is a grid cell, so it resizes with the page rather than on
       its own; 3Dmol needs telling. */
    if (window.ResizeObserver) {
      var observer = new window.ResizeObserver(function () {
        if (viewer !== state) { observer.disconnect(); return; }
        state.viewer.resize();
        drawStrip(state);
      });
      observer.observe(q(state, '[data-ps-viewport]'));
    }
  }

  /* ------------------------------------------------------------------------
     ESMFold panel
     ------------------------------------------------------------------------ */

  function runEsmLookup(term) {
    term = String(term || '').trim();
    if (!term) { return; }
    esmResults.innerHTML = '<div class="mgdb-loading" role="status">Resolving isoform…</div>';
    MGDB.request(API + '?action=esmfold&term=' + encodeURIComponent(term), { key: 'ps-esm' })
      .then(function (data) {
        if (!data.found) {
          esmResults.innerHTML = '<div class="mgdb-message mgdb-message-info"><p>'
            + escape(data.reason || 'No ESMFold model is available for this identifier.')
            + '</p></div>';
          return;
        }
        /* Shaped like a record so the same viewer renders it. ESMFold files
           carry pLDDT in the B-factor column exactly as AlphaFold's do, so the
           confidence strip and colouring work unchanged. */
        var record = {
          id: data.protein,
          pdb: data.pdb,
          tool: 'ESMFold',
          partners: [{
            symbol: data.locus || data.gene,
            gene: data.gene,
            gene_ids: data.gene ? [data.gene] : [],
            description: 'B73 RefGen_v5 protein isoform ' + data.protein
          }],
          metrics: {}
        };
        /* Opening an ESMFold model tears down the workspace viewer: two live
           WebGL contexts on one page is a cost with no benefit, and the reader
           is looking at this one. */
        disposeViewer();
        esmResults.innerHTML = '<div class="ps-model-detail"></div>';
        var esmHost = esmResults.querySelector('.ps-model-detail');
        esmHost.innerHTML = viewerMarkup(record, 'esmfold') + renderPartners(record);
        startViewer(esmHost, record, 'esmfold');
      })
      .catch(function () {
        esmResults.innerHTML = '<div class="mgdb-message mgdb-message-error">'
          + '<p>The isoform could not be resolved. Try again in a moment.</p></div>';
      });
  }

  /* ── Section Tabs & Scrollspy ───────────────────────────────────────────── */

  /* The shared section-tab behaviour: scroll-driven, with aria-current and a
     click hold released by real scrolling. The IntersectionObserver-only
     version this replaced marks nothing in embedded browsers, which deliver no
     entries at all. */
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

  /* ------------------------------------------------------------------------
     Init
     ------------------------------------------------------------------------ */

  function init() {
    buildTabs();

    form = byId('ps-search-form');
    input = byId('ps-search-input');
    panel = byId('ps-suggestions');
    results = byId('ps-results');
    esmForm = byId('ps-esm-form');
    esmInput = byId('ps-esm-input');
    esmResults = byId('ps-esm-results');

    if (!form || !input || !results) { return; }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      runLookup(input.value);
    });

    input.addEventListener('input', function () {
      var term = input.value.trim();
      if (term.length < 2) { closeSuggestions(); return; }
      askSuggest(term);
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { closeSuggestions(); return; }
      if (panel.hidden) { return; }
      if (event.key === 'ArrowDown') { event.preventDefault(); moveSuggestion(1); }
      else if (event.key === 'ArrowUp') { event.preventDefault(); moveSuggestion(-1); }
      else if (event.key === 'Enter' && activeSuggestion >= 0) {
        event.preventDefault();
        panel.querySelectorAll('[role="option"]')[activeSuggestion].click();
      }
    });

    document.addEventListener('click', function (event) {
      if (!form.contains(event.target)) { closeSuggestions(); }
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-ps-example]'), function (button) {
      button.addEventListener('click', function () {
        input.value = button.getAttribute('data-ps-example');
        runLookup(input.value);
        form.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
      });
    });

    if (esmForm && esmInput && esmResults) {
      esmForm.addEventListener('submit', function (event) {
        event.preventDefault();
        runEsmLookup(esmInput.value);
      });
      Array.prototype.forEach.call(document.querySelectorAll('[data-ps-esm-example]'), function (button) {
        button.addEventListener('click', function () {
          esmInput.value = button.getAttribute('data-ps-esm-example');
          runEsmLookup(esmInput.value);
        });
      });
    }

    /* A shared link should open on its result, not on an empty form. */
    var params = new window.URLSearchParams(window.location.search);
    var initial = params.get('term') || params.get('id');
    if (initial) {
      input.value = initial;
      runLookup(initial);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

}(window, document));
