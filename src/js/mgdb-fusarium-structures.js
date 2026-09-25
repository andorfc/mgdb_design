/* ==========================================================================
   /fusarium/structures — AlphaFold and ESMFold models, one protein at a time
   --------------------------------------------------------------------------
   Asks search/fusarium/fusarium_api.php which proteins an identifier names,
   and draws, for the one chosen:

     the protein     its species, gene ids, UniProt entry, and every toolkit
                     page and outside resource that has it
     AlphaFold       the model in the Protein Structure Hub's own viewer,
     ESMFold         MGDB.proteinStructureViewer from mgdb-protein-structure.js
                     -- the same controls and pLDDT strip as every structure
                     on MaizeGDB
     Compare         both models superposed, residue for residue, scored with
                     TM-align's TM-score, shaded by how far apart the two put
                     each residue, with both confidence traces beneath

   An identifier can name more than one protein -- F. oxysporum's FOXG genes
   were entered in UniProt twice, and a symbol can recur across species -- so
   when it does the page lists them all and opens the first; the API orders
   them so that is the one UniProt still has, with a name, and the longest.

   Why the comparison needs no alignment: both models are of the same
   sequence, numbered from 1, so residue i pairs with residue i. What TM-align
   contributes is the superposition that maximizes the TM-score and the score
   itself; js/mgdb-tmalign.js is the same port /foldseek uses, run here in a
   Web Worker because a 2,000-residue protein takes a couple of seconds.

   The tab bar's scrollspy comes with mgdb-protein-structure.js, which runs one
   for any page carrying .mgdb-section-tabs; starting a second here would
   double every update.

   Nothing here touches the DOM before DOMContentLoaded.

   Depends on MGDB (mgdb-modern.js), MGDB.proteinStructureViewer
   (mgdb-protein-structure.js), $3Dmol (js/lib/3dmol/), MGDBTMalign
   (mgdb-tmalign.js) and FPT.suggest (mgdb-fusarium.js).
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var escape = MGDB.escapeHtml;
  var API = '';
  var els = {};

  var state = {
    term: '',
    serial: 0,
    data: null,
    index: 0,
    view: 'alphafold',
    cmp: null,
    cmpViewer: null,
    worker: null,
    workerBusy: false,
    tmJob: 0
  };

  var VIEWS = [
    { key: 'alphafold', label: 'AlphaFold' },
    { key: 'esmfold', label: 'ESMFold' },
    { key: 'compare', label: 'Compare' }
  ];

  /* The Protein Structure Hub's pLDDT bins and chain colors, so a model reads
     the same in the comparison as in the single-model viewer. */
  var PLDDT = [
    { min: 90, color: '#0053D6', label: 'Very high (≥90)' },
    { min: 70, color: '#65CBF3', label: 'Confident (70–90)' },
    { min: 50, color: '#FFDB13', label: 'Low (50–70)' },
    { min: 0, color: '#FF7D45', label: 'Very low (<50)' }
  ];
  var MODEL_COLOR = { alphafold: '#8fa6c4', esmfold: '#ff9f40' };
  var DISTANCE = [
    { max: 1, color: '#4dd0e1', label: 'under 1 Å' },
    { max: 2, color: '#7fd08b', label: '1–2 Å' },
    { max: 4, color: '#ffd166', label: '2–4 Å' },
    { max: 8, color: '#f4845f', label: '4–8 Å' },
    { max: Infinity, color: '#e63946', label: '8 Å or more' }
  ];

  var AA1 = {
    ALA: 'A', ARG: 'R', ASN: 'N', ASP: 'D', CYS: 'C', GLN: 'Q', GLU: 'E', GLY: 'G', HIS: 'H', ILE: 'I',
    LEU: 'L', LYS: 'K', MET: 'M', PHE: 'F', PRO: 'P', SER: 'S', THR: 'T', TRP: 'W', TYR: 'Y', VAL: 'V'
  };

  /* ----------------------------------------------------------------------
   * Small helpers
   * ---------------------------------------------------------------------- */

  function fmtInt(value) {
    return value === null || value === undefined || isNaN(value) ? '—' : Number(value).toLocaleString('en-US');
  }

  function fmtPct(fraction) {
    return fraction === null || fraction === undefined || isNaN(fraction) ? '—' : Math.round(fraction * 100) + '%';
  }

  function plddtColor(value) {
    for (var i = 0; i < PLDDT.length; i++) { if (value >= PLDDT[i].min) { return PLDDT[i].color; } }
    return PLDDT[PLDDT.length - 1].color;
  }

  function distanceColor(value) {
    for (var i = 0; i < DISTANCE.length; i++) { if (value < DISTANCE[i].max) { return DISTANCE[i].color; } }
    return DISTANCE[DISTANCE.length - 1].color;
  }

  function title(p) { return (p.genes && p.genes[0]) || p.accession; }

  function reducedMotion() { return MGDB.prefersReducedMotion ? MGDB.prefersReducedMotion() : false; }

  function setStatus(html) { if (els.status) { els.status.innerHTML = html || ''; } }

  function saveFile(name, text, type) {
    var blob = new window.Blob([text], { type: type });
    var url = window.URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.appendChild(link);
    link.click();
    window.setTimeout(function () {
      window.URL.revokeObjectURL(url);
      link.parentNode.removeChild(link);
    }, 0);
  }

  function button(label, href, primary) {
    return '<a class="mgdb-button mgdb-button-' + (primary ? 'secondary' : 'quiet') + ' mgdb-button-sm" href="'
      + escape(href) + '">' + escape(label) + '</a>';
  }

  /* ----------------------------------------------------------------------
   * Lookup
   * ---------------------------------------------------------------------- */

  function run(term, options) {
    term = String(term || '').trim();
    if (!term) { return; }
    options = options || {};
    state.term = term;
    var mine = ++state.serial;
    setStatus('<div class="mgdb-loading"><span class="mgdb-spinner" aria-hidden="true"></span> '
      + 'Looking up <b>' + escape(term) + '</b>&hellip;</div>');

    MGDB.request(API + '?action=protein&term=' + encodeURIComponent(term), { key: 'fst-protein' })
      .then(function (data) {
        if (mine !== state.serial) { return; }
        if (!data || !data.found) { renderNotFound(term, data || {}); return; }
        setStatus('');
        renderResults(data, options);
        MGDB.announce('Structures for ' + title(state.data.proteins[state.index]) + ' loaded.');
      })
      .catch(function (error) {
        if (mine !== state.serial || (error && error.name === 'AbortError')) { return; }
        var status = /status (\d+)/.exec(String((error && error.message) || ''));
        var code = status ? parseInt(status[1], 10) : 0;
        els.results.hidden = true;
        setStatus('<div class="mgdb-message mgdb-message-error"><div><strong>'
          + escape(code === 400 ? 'That is not a Fusarium gene id, gene symbol or UniProt accession.'
            : (code === 503 ? 'The toolkit’s protein index is not installed on this server.'
              : 'The lookup could not be completed. Try again in a moment.'))
          + '</strong></div></div>');
      });
  }

  function renderNotFound(term, data) {
    els.results.hidden = true;
    state.data = null;
    var chips = (data.suggestions || []).map(function (value) {
      return '<button class="mgdb-chip" type="button" data-fst-example="' + escape(value) + '">' + escape(value) + '</button>';
    });
    setStatus('<div class="mgdb-message mgdb-message-info"><div>'
      + '<strong>No Fusarium protein is named “' + escape(term) + '”.</strong> '
      + '<span>The toolkit has models for every protein of <i>F. graminearum</i>, <i>F. verticillioides</i>, '
      + '<i>F. fujikuroi</i>, <i>F. oxysporum</i>, <i>F. proliferatum</i> and <i>F. solani</i>, '
      + 'looked up by gene id, gene symbol or UniProt accession.</span>'
      + (chips.length ? '<p class="fpt-suggest-row"><span>Close matches</span>' + chips.join('') + '</p>' : '')
      + '</div></div>');
    writeUrl();
    MGDB.announce('No Fusarium protein is named ' + term + '.');
  }

  function writeUrl() {
    if (!window.history || !window.history.replaceState || !window.URLSearchParams) { return; }
    var params = new window.URLSearchParams();
    if (state.term) { params.set('id', state.term); }
    var p = state.data && state.data.proteins[state.index];
    if (p && state.data.proteins.length > 1 && state.index > 0) { params.set('acc', p.accession); }
    if (p && state.view !== 'alphafold') { params.set('model', state.view); }
    var query = params.toString();
    window.history.replaceState(null, '', '/fusarium/structures' + (query ? '?' + query : '') + window.location.hash);
  }

  /* ----------------------------------------------------------------------
   * Results
   * ---------------------------------------------------------------------- */

  function renderResults(data, options) {
    state.data = data;
    state.index = 0;
    if (options.acc) {
      for (var i = 0; i < data.proteins.length; i++) {
        if (data.proteins[i].accession === String(options.acc).toUpperCase()) { state.index = i; break; }
      }
    }
    els.resultsBody.innerHTML = chooserMarkup() + '<div data-fst-protein></div>';
    els.results.hidden = false;
    showProtein(state.index, options.view || 'alphafold');
  }

  /* When an identifier names several proteins, all of them, as buttons. */
  function chooserMarkup() {
    var list = state.data.proteins;
    if (list.length < 2) { return ''; }
    return '<div class="fst-chooser">'
      + '<p class="fst-chooser-lead"><b>' + escape(state.data.query) + '</b> names ' + list.length + ' proteins. '
      + 'Choose one to see its models.</p>'
      + '<div class="fst-chooser-list" role="group" aria-label="Proteins named ' + escape(state.data.query) + '">'
      + list.map(function (p, index) {
        return '<button type="button" class="fst-choice" data-fst-choose="' + index + '" aria-pressed="false">'
          + '<b>' + escape(p.accession) + '</b>'
          + '<span>' + escape(p.species_label) + ' · ' + fmtInt(p.length) + ' aa'
          + (p.in_uniprotkb ? '' : ' · deleted from UniProtKB') + '</span>'
          + '<span>' + escape(p.name || 'No functional name in UniProt') + '</span>'
          + '</button>';
      }).join('')
      + '</div></div>';
  }

  function showProtein(index, view) {
    state.index = index;
    var p = state.data.proteins[index];
    Array.prototype.forEach.call(els.resultsBody.querySelectorAll('[data-fst-choose]'), function (choice) {
      choice.setAttribute('aria-pressed', choice.getAttribute('data-fst-choose') === String(index) ? 'true' : 'false');
    });
    els.resultsTitle.textContent = 'Structures of ' + title(p);
    var host = els.resultsBody.querySelector('[data-fst-protein]');
    host.innerHTML = identityMarkup(p) + tabsMarkup(p) + '<div class="fst-panel" data-fst-panel role="tabpanel"></div>';
    if ((view === 'esmfold' || view === 'compare') && !p.esmfold) { view = 'alphafold'; }
    showView(view);
  }

  function identityMarkup(p) {
    var links = p.links || {};
    /* Every other name, less the two already in the heading. */
    var shown = [title(p), (p.symbols || [])[0]];
    var aliases = [].concat(p.genes || [], p.symbols || []).filter(function (value, i, all) {
      return value && shown.indexOf(value) === -1 && all.indexOf(value) === i;
    });
    var facts = [
      ['Species', '<i>' + escape(p.species_label) + '</i>'],
      ['UniProt', '<a href="' + escape(links.uniprot) + '">' + escape(p.accession) + '</a>'
        + (p.in_uniprotkb ? '' : ' <span class="fpt-flag">deleted from UniProtKB</span>')],
      ['Length', fmtInt(p.length) + ' aa'],
      p.effector ? ['Effector', '<a href="/fusarium/effectors?species=' + encodeURIComponent(p.species)
        + '&amp;q=' + encodeURIComponent(title(p)) + '">Predicted effector</a>'] : null
    ].filter(Boolean).map(function (pair) {
      return '<div><dt>' + pair[0] + '</dt><dd>' + pair[1] + '</dd></div>';
    }).join('');

    var actions = [];
    if (links.foldseek) { actions.push(button('Foldseek matches', links.foldseek, true)); }
    if (links.paneffect) { actions.push(button('PanEffect', links.paneffect)); }
    actions.push(button('SNPTools', 'https://fusarium-snptools.maizegdb.org/'));
    if (links.fungidb) { actions.push(button('FungiDB', links.fungidb)); }
    if (links.afdb) { actions.push(button('AlphaFold DB', links.afdb)); }

    var searched = p.foldseek ? ''
      : '<p class="fst-note">' + escape(p.species_label) + ' proteins were not searched with Foldseek; '
        + 'they appear as matches to <i>F. graminearum</i> and <i>F. verticillioides</i> proteins.</p>';

    return '<div class="ps-identity fst-identity">'
      + '<div class="fst-identity-main">'
      + '<h3>' + escape(title(p)) + (p.symbols && p.symbols.length ? ' <span class="fst-symbol">' + escape(p.symbols[0]) + '</span>' : '') + '</h3>'
      + '<p class="fst-name">' + escape(p.name || 'No functional name in UniProt') + '</p>'
      + (aliases.length ? '<p class="ps-identity-aliases">Also ' + aliases.map(escape).join(' · ') + '</p>' : '')
      + '<dl class="mgdb-record-facts fst-facts">' + facts + '</dl>'
      + searched
      + '</div>'
      + '<div class="ps-identity-links">' + actions.join('') + '</div>'
      + '</div>';
  }

  function tabsMarkup(p) {
    var why = p.esmfold_archive_only
      ? p.species_label + ' ESMFold models are in the download archive only'
      : 'No ESMFold model for this protein';
    var tabs = '<div class="ps-tabs fst-tabs" role="tablist" aria-label="Model">'
      + VIEWS.map(function (view) {
        var missing = (view.key === 'alphafold' && !p.alphafold) || (view.key !== 'alphafold' && !(p.esmfold && p.alphafold));
        return '<button class="ps-tab" type="button" role="tab" id="fst-tab-' + view.key + '"'
          + ' aria-selected="false" aria-controls="fst-panel" data-fst-view="' + view.key + '"'
          + (missing ? ' disabled title="' + escape(view.key === 'alphafold' ? 'No AlphaFold model for this protein' : why) + '"' : '') + '>'
          + escape(view.label) + '</button>';
      }).join('') + '</div>';
    /* Four species' ESMFold directories are listed on fusarium.maizegdb.org
       but answer 403 for every file; say where the model is instead of
       leaving two dead tabs unexplained. */
    if (p.esmfold_archive_only) {
      tabs += '<p class="fst-note fst-archive-note">The ESMFold model of this protein is in the toolkit’s '
        + '<a href="https://ars-usda.app.box.com/v/maizegdb-public/folder/227029737169">download archive</a>, '
        + 'in esm_' + escape(p.species) + '.tar.gz, but fusarium.maizegdb.org does not serve '
        + escape(p.species_label) + ' ESMFold models one at a time, so it cannot be drawn or compared here.</p>';
    }
    return tabs;
  }

  function showView(view) {
    var p = state.data.proteins[state.index];
    state.view = view;
    Array.prototype.forEach.call(els.resultsBody.querySelectorAll('[data-fst-view]'), function (tab) {
      tab.setAttribute('aria-selected', tab.getAttribute('data-fst-view') === view ? 'true' : 'false');
    });
    var panel = els.resultsBody.querySelector('[data-fst-panel]');
    panel.id = 'fst-panel';
    panel.setAttribute('aria-labelledby', 'fst-tab-' + view);
    disposeCompare();

    if (view === 'compare') {
      panel.innerHTML = compareMarkup(p);
      startCompare(p);
    } else {
      var esm = view === 'esmfold';
      var url = esm ? p.links.esmfold_pdb : p.links.alphafold_pdb;
      var record = {
        id: esm ? 'ESMFold-' + p.accession : 'AF-' + p.accession + '-F1-model_v4',
        pdb: url,
        tool: esm ? 'predicted for the toolkit' : 'AlphaFold DB version 4',
        entry: esm ? null : p.links.afdb,
        partners: [{ gene: title(p) }]
      };
      var ok = url && MGDB.proteinStructureViewer
        && MGDB.proteinStructureViewer(panel, record, esm ? 'esmfold' : 'monomer');
      if (!ok) {
        panel.innerHTML = '<p class="mgdb-message mgdb-message-info">The 3D viewer could not start in this browser. '
          + (url ? 'The model file is <a href="' + escape(url) + '">here</a>.' : '') + '</p>';
      } else {
        panel.insertAdjacentHTML('beforeend', '<p class="fpt-caption">'
          + (esm ? 'ESMFold model of ' + escape(p.accession) + ', predicted for the Fusarium Protein Toolkit from the UniProt sequence.'
                 : 'AlphaFold model AF-' + escape(p.accession) + '-F1, version 4, as the toolkit downloaded it from the AlphaFold Protein Structure Database.')
          + ' Served from fusarium.maizegdb.org.</p>');
      }
    }
    writeUrl();
  }

  /* ----------------------------------------------------------------------
   * Compare: the two models superposed
   * ---------------------------------------------------------------------- */

  function compareMarkup(p) {
    return '<dl class="ps-metric-row fst-cmp-metrics" data-fst-metrics>' + metricsMarkup(null) + '</dl>'
      + '<div class="ps-viewer fst-cmp" data-fst-cmp>'
      + '<div class="ps-viewer-head">'
      +   '<div class="ps-viewer-title"><strong>' + escape(title(p)) + '</strong>'
      +     '<span>AlphaFold and ESMFold superposed · ' + escape(p.accession) + '</span></div>'
      +   '<div class="ps-viewer-actions">'
      +     '<button class="ps-viewer-button" type="button" data-fst-spin aria-pressed="false">Auto-rotate</button>'
      +     '<button class="ps-viewer-button" type="button" data-fst-reset>Reset view</button>'
      +     '<button class="ps-viewer-button" type="button" data-fst-png disabled>Save PNG</button>'
      +     '<button class="ps-viewer-button" type="button" data-fst-pdb disabled>Download superposition</button>'
      +   '</div>'
      + '</div>'
      + '<div class="ps-viewport" data-fst-viewport>'
      +   '<div class="ps-hud"><b>' + escape(title(p)) + '</b><span>AlphaFold · ESMFold</span></div>'
      +   '<div class="ps-viewer-status" data-fst-status>Loading both models…</div>'
      + '</div>'
      + '<div class="ps-viewer-rail">'
      +   '<div class="ps-rail-group"><h4>Color</h4>'
      +     '<span class="ps-rail-label">Scheme</span>'
      +     '<select class="ps-rail-select" data-fst-scheme aria-label="Color scheme">'
      +       '<option value="model">By predictor</option>'
      +       '<option value="distance">By distance between the two</option>'
      +       '<option value="plddt">By each model’s pLDDT</option>'
      +     '</select>'
      +     '<div class="ps-legend" data-fst-legend></div>'
      +   '</div>'
      +   '<div class="ps-rail-group"><h4>Show</h4>'
      +     '<label class="ps-rail-check"><input type="checkbox" data-fst-show="alphafold" checked /> AlphaFold</label>'
      +     '<label class="ps-rail-check"><input type="checkbox" data-fst-show="esmfold" checked /> ESMFold</label>'
      +     '<p class="ps-rail-hint">Click a residue in the chart below to zoom to it.</p>'
      +   '</div>'
      + '</div>'
      + '<div class="ps-strip-wrap">'
      +   '<div class="ps-strip-head"><span>Per residue: both models’ pLDDT, and the distance between them once superposed</span>'
      +     '<span data-fst-strip-meta></span></div>'
      +   '<div class="ps-strip-shell"><canvas class="ps-strip fst-strip" data-fst-strip></canvas>'
      +     '<div class="ps-strip-tip" data-fst-strip-tip hidden></div></div>'
      + '</div>'
      + '</div>'
      + '<p class="fpt-caption">The ESMFold model is moved onto the AlphaFold model with the rotation that maximizes the '
      + 'TM-score, computed by the same port of TM-align that scores Foldseek matches, pairing residue i with residue i.</p>';
  }

  function metric(term, value, note) {
    return '<div class="ps-metric"><dt>' + term + '</dt><dd>' + value + '</dd>' + (note ? '<p>' + note + '</p>' : '') + '</div>';
  }

  function metricsMarkup(cmp) {
    var tm = cmp && cmp.tm;
    var pending = cmp && cmp.error ? '—' : '<span class="fpt-pending">computing</span>';
    return metric('TM-score', tm ? tm.tmTarget.toFixed(3) : pending, 'the two predictions, 0 to 1')
      + metric('RMSD', tm ? tm.rmsd.toFixed(2) + ' Å' : '—', tm ? 'over all ' + fmtInt(tm.aligned) + ' residues' : 'over all residues')
      + metric('Within 2 Å', cmp && cmp.within2 !== undefined ? fmtPct(cmp.within2) : '—', 'of residues, superposed')
      + metric('Mean pLDDT', cmp && cmp.meanAf !== undefined ? cmp.meanAf.toFixed(1) + ' · ' + cmp.meanEsm.toFixed(1) : '—',
               'AlphaFold · ESMFold')
      + metric('Both confident', cmp && cmp.bothConfident !== undefined ? fmtPct(cmp.bothConfident) : '—',
               'of residues at pLDDT 70 or more in both');
  }

  /* Every Calpha, by residue number, plus the full text for drawing. */
  function parseModel(text) {
    var ca = {};
    var order = [];
    text.split('\n').forEach(function (line) {
      if (line.lastIndexOf('ATOM', 0) !== 0 || line.substr(12, 4).trim() !== 'CA') { return; }
      var resi = parseInt(line.substr(22, 4), 10);
      if (ca[resi]) { return; }
      ca[resi] = {
        resi: resi,
        aa: AA1[line.substr(17, 3).trim()] || 'X',
        p: [parseFloat(line.substr(30, 8)), parseFloat(line.substr(38, 8)), parseFloat(line.substr(46, 8))],
        b: parseFloat(line.substr(60, 6))
      };
      order.push(resi);
    });
    return { text: text, ca: ca, order: order };
  }

  function fetchModel(url) {
    return window.fetch(url, { mode: 'cors' }).then(function (response) {
      if (!response.ok) { var failed = new Error('status ' + response.status); failed.fetchFailed = true; throw failed; }
      return response.text();
    });
  }

  function transform(t, u, p) {
    return [t[0] + u[0][0] * p[0] + u[0][1] * p[1] + u[0][2] * p[2],
            t[1] + u[1][0] * p[0] + u[1][1] * p[1] + u[1][2] * p[2],
            t[2] + u[2][0] * p[0] + u[2][1] * p[1] + u[2][2] * p[2]];
  }

  function pad(value, width) {
    var text = String(value);
    while (text.length < width) { text = ' ' + text; }
    return text;
  }

  /* The ESMFold file with every coordinate moved, and its chain renamed B so
     the two can sit in one file. Columns per the PDB format: x, y, z at
     31-38, 39-46, 47-54. */
  function movedPdb(text, t, u, chain) {
    return text.split('\n').filter(function (line) {
      return line.lastIndexOf('ATOM', 0) === 0 || line.lastIndexOf('HETATM', 0) === 0;
    }).map(function (line) {
      var p = transform(t, u, [parseFloat(line.substr(30, 8)), parseFloat(line.substr(38, 8)), parseFloat(line.substr(46, 8))]);
      var padded = line.length < 54 ? line + new Array(55 - line.length).join(' ') : line;
      return padded.substr(0, 21) + chain + padded.substr(22, 8)
        + pad(p[0].toFixed(3), 8) + pad(p[1].toFixed(3), 8) + pad(p[2].toFixed(3), 8) + padded.substr(54);
    }).join('\n');
  }

  function atomLines(text, chain) {
    return text.split('\n').filter(function (line) {
      return line.lastIndexOf('ATOM', 0) === 0 || line.lastIndexOf('HETATM', 0) === 0;
    }).map(function (line) {
      return line.length > 21 ? line.substr(0, 21) + chain + line.substr(22) : line;
    }).join('\n');
  }

  function tmalignUrl() {
    var script = document.querySelector('script[src*="mgdb-tmalign.js"]');
    return script ? script.src : window.location.origin + '/js/mgdb-tmalign.js';
  }

  function makeWorker() {
    if (!window.Worker || !window.Blob || !window.URL) { return null; }
    try {
      var source = 'importScripts(' + JSON.stringify(tmalignUrl()) + ');'
        + 'self.onmessage=function(e){var d=e.data;try{'
        + 'self.postMessage({id:d.id,ok:true,result:self.MGDBTMalign.scoreFixed(d.x,d.y,d.xlen,d.ylen)});'
        + '}catch(err){self.postMessage({id:d.id,ok:false,error:String(err&&err.message||err)});}};';
      return new window.Worker(window.URL.createObjectURL(new window.Blob([source], { type: 'application/javascript' })));
    } catch (e) {
      return null;
    }
  }

  /* One scoring at a time: a newer request replaces the worker rather than
     queueing behind a large protein's search. */
  function computeTm(x, y, length) {
    if (state.workerBusy && state.worker) { state.worker.terminate(); state.worker = null; }
    if (!state.worker) { state.worker = makeWorker(); }
    var worker = state.worker;
    var direct = function () { return window.MGDBTMalign.scoreFixed(x, y, length, length); };
    if (!worker) {
      return new Promise(function (resolve, reject) {
        window.setTimeout(function () { try { resolve(direct()); } catch (e) { reject(e); } }, 30);
      });
    }
    var id = ++state.tmJob;
    state.workerBusy = true;
    return new Promise(function (resolve, reject) {
      function done(event) {
        if (!event.data || event.data.id !== id) { return; }
        worker.removeEventListener('message', done);
        worker.removeEventListener('error', failed);
        state.workerBusy = false;
        if (event.data.ok) { resolve(event.data.result); } else { reject(new Error(event.data.error)); }
      }
      function failed() {
        worker.removeEventListener('message', done);
        worker.removeEventListener('error', failed);
        state.workerBusy = false;
        state.worker = null;
        try { resolve(direct()); } catch (e) { reject(e); }
      }
      worker.addEventListener('message', done);
      worker.addEventListener('error', failed);
      worker.postMessage({ id: id, x: x, y: y, xlen: length, ylen: length });
    });
  }

  function q(selector) {
    var root = els.resultsBody.querySelector('[data-fst-cmp]');
    return root ? root.querySelector(selector) : null;
  }

  function cmpStatus(message) {
    var status = q('[data-fst-status]');
    if (status) { status.textContent = message; status.hidden = !message; }
  }

  function startCompare(p) {
    var cmp = { protein: p, serial: state.serial, scheme: 'model', show: { alphafold: true, esmfold: true } };
    state.cmp = cmp;
    Promise.all([fetchModel(p.links.alphafold_pdb), fetchModel(p.links.esmfold_pdb)])
      .then(function (texts) {
        if (state.cmp !== cmp) { return null; }
        cmp.af = parseModel(texts[0]);
        cmp.esm = parseModel(texts[1]);
        /* Residue i against residue i, over the residues both models have --
           which, for two predictions of one sequence, is all of them. */
        var x = [], y = [], residues = [];
        cmp.af.order.forEach(function (resi) {
          var a = cmp.af.ca[resi], e = cmp.esm.ca[resi];
          if (a && e && a.aa === e.aa) { y.push(a.p); x.push(e.p); residues.push(resi); }
        });
        cmp.residues = residues;
        cmp.x = x;
        cmp.y = y;
        if (residues.length < 3) { throw new Error('the two models do not share a sequence'); }
        cmpStatus('Superposing ' + fmtInt(residues.length) + ' residues…');
        return computeTm(x, y, residues.length);
      })
      .then(function (tm) {
        if (!tm || state.cmp !== cmp) { return; }
        cmp.tm = tm;
        var within = 0, both = 0, sumAf = 0, sumEsm = 0;
        cmp.distance = {};
        cmp.residues.forEach(function (resi, k) {
          var moved = transform(tm.t, tm.u, cmp.x[k]);
          var target = cmp.y[k];
          var d = Math.sqrt(Math.pow(moved[0] - target[0], 2) + Math.pow(moved[1] - target[1], 2) + Math.pow(moved[2] - target[2], 2));
          cmp.distance[resi] = d;
          if (d < 2) { within++; }
          var bA = cmp.af.ca[resi].b, bE = cmp.esm.ca[resi].b;
          sumAf += bA;
          sumEsm += bE;
          if (bA >= 70 && bE >= 70) { both++; }
        });
        var n = cmp.residues.length;
        cmp.within2 = within / n;
        cmp.bothConfident = both / n;
        cmp.meanAf = sumAf / n;
        cmp.meanEsm = sumEsm / n;
        cmp.esmMoved = movedPdb(cmp.esm.text, tm.t, tm.u, 'B');
        var metrics = els.resultsBody.querySelector('[data-fst-metrics]');
        if (metrics) { metrics.innerHTML = metricsMarkup(cmp); }
        drawCompare(cmp);
        drawStrip(cmp);
        bindCompare(cmp);
      })
      .catch(function (error) {
        if (state.cmp !== cmp) { return; }
        cmp.error = true;
        var metrics = els.resultsBody.querySelector('[data-fst-metrics]');
        if (metrics) { metrics.innerHTML = metricsMarkup(cmp); }
        /* A network failure rejects with a TypeError, a refused file with the
           flag set above; anything else went wrong in the superposition. */
        cmpStatus((error && (error.fetchFailed || error.name === 'TypeError'))
          ? 'One of the two model files could not be read from fusarium.maizegdb.org.'
          : 'The two models could not be superposed: ' + String((error && error.message) || error) + '.');
      });
  }

  function drawCompare(cmp) {
    var viewport = q('[data-fst-viewport]');
    if (!viewport || !window.$3Dmol) { cmpStatus('The 3D viewer could not start in this browser.'); return; }
    if (!state.cmpViewer) {
      state.cmpViewer = window.$3Dmol.createViewer(viewport, { backgroundColor: '#000000', antialias: true });
    }
    var viewer = state.cmpViewer;
    viewer.clear();
    cmp.models = {
      alphafold: viewer.addModel(atomLines(cmp.af.text, 'A'), 'pdb'),
      esmfold: viewer.addModel(cmp.esmMoved, 'pdb')
    };
    styleCompare(cmp);
    viewer.zoomTo();
    viewer.render();
    cmpStatus('');
    Array.prototype.forEach.call(els.resultsBody.querySelectorAll('[data-fst-png],[data-fst-pdb]'), function (b) { b.disabled = false; });
  }

  function styleCompare(cmp) {
    var viewer = state.cmpViewer;
    if (!viewer || !cmp.models) { return; }
    var legend = q('[data-fst-legend]');
    ['alphafold', 'esmfold'].forEach(function (key) {
      var model = cmp.models[key];
      if (!cmp.show[key]) { model.setStyle({}, {}); return; }
      var paint;
      if (cmp.scheme === 'distance') {
        paint = function (atom) {
          var d = cmp.distance[atom.resi];
          return d === undefined ? '#555555' : distanceColor(d);
        };
      } else if (cmp.scheme === 'plddt') {
        paint = function (atom) { return plddtColor(atom.b); };
      } else {
        paint = function () { return MODEL_COLOR[key]; };
      }
      model.setStyle({}, { cartoon: { colorfunc: paint, opacity: key === 'esmfold' && cmp.scheme !== 'model' ? 0.75 : 1 } });
    });
    if (legend) {
      var rows = cmp.scheme === 'distance' ? DISTANCE.map(function (d) { return [d.color, d.label]; })
        : (cmp.scheme === 'plddt' ? PLDDT.map(function (b) { return [b.color, b.label]; })
          : [[MODEL_COLOR.alphafold, 'AlphaFold'], [MODEL_COLOR.esmfold, 'ESMFold']]);
      legend.innerHTML = rows.map(function (row) {
        return '<div><i style="background:' + row[0] + '"></i>' + escape(row[1]) + '</div>';
      }).join('');
    }
    viewer.render();
  }

  /* Both pLDDT traces over the distance bars, on one residue axis. */
  function drawStrip(cmp) {
    var canvas = q('[data-fst-strip]');
    if (!canvas || !cmp.residues) { return; }
    var width = canvas.clientWidth || 800;
    var height = 110;
    var ratio = window.devicePixelRatio || 1;
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    canvas.style.height = height + 'px';
    var ctx = canvas.getContext('2d');
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);

    var left = 26, right = 6, top = 4, bandH = 58, gap = 8, barTop = top + bandH + gap, barH = height - barTop - 14;
    var n = cmp.residues.length;
    var step = (width - left - right) / n;
    cmp.stripGeom = { left: left, step: step };

    [[90, 100, 'rgba(0,83,214,.10)'], [70, 90, 'rgba(101,203,243,.10)'], [50, 70, 'rgba(255,219,19,.10)'], [0, 50, 'rgba(255,125,69,.10)']]
      .forEach(function (band) {
        var y0 = top + bandH * (1 - band[1] / 100), y1 = top + bandH * (1 - band[0] / 100);
        ctx.fillStyle = band[2];
        ctx.fillRect(left, y0, width - left - right, y1 - y0);
      });
    ctx.fillStyle = '#9aa7b8';
    ctx.font = '10px system-ui, sans-serif';
    ctx.fillText('100', 2, top + 8);
    ctx.fillText('0', 12, top + bandH);
    ctx.fillText('Å', 12, barTop + 10);

    ['alphafold', 'esmfold'].forEach(function (key) {
      var model = key === 'alphafold' ? cmp.af : cmp.esm;
      ctx.strokeStyle = MODEL_COLOR[key];
      ctx.lineWidth = 1.5;
      ctx.beginPath();
      cmp.residues.forEach(function (resi, i) {
        var x = left + (i + 0.5) * step;
        var y = top + bandH * (1 - Math.max(0, Math.min(100, model.ca[resi].b)) / 100);
        if (i === 0) { ctx.moveTo(x, y); } else { ctx.lineTo(x, y); }
      });
      ctx.stroke();
    });

    /* Distance bars, capped at 16 Å so one wild loop cannot flatten the rest. */
    cmp.residues.forEach(function (resi, i) {
      var d = cmp.distance[resi];
      var h = Math.max(1, Math.min(1, d / 16) * barH);
      ctx.fillStyle = distanceColor(d);
      ctx.fillRect(left + i * step, barTop + barH - h, Math.max(1, step - (step > 3 ? 0.5 : 0)), h);
    });

    var meta = q('[data-fst-strip-meta]');
    if (meta) {
      meta.innerHTML = '<i class="fst-key fst-key-af"></i>AlphaFold <i class="fst-key fst-key-esm"></i>ESMFold · '
        + fmtInt(n) + ' residues';
    }
  }

  function residueAt(cmp, event) {
    var canvas = q('[data-fst-strip]');
    if (!canvas || !cmp.stripGeom) { return null; }
    var rect = canvas.getBoundingClientRect();
    var i = Math.floor((event.clientX - rect.left - cmp.stripGeom.left) / cmp.stripGeom.step);
    if (i < 0 || i >= cmp.residues.length) { return null; }
    return { index: i, resi: cmp.residues[i], x: event.clientX - rect.left };
  }

  function bindCompare(cmp) {
    var scheme = q('[data-fst-scheme]');
    if (scheme) {
      scheme.addEventListener('change', function () { cmp.scheme = scheme.value; styleCompare(cmp); });
    }
    Array.prototype.forEach.call(els.resultsBody.querySelectorAll('[data-fst-show]'), function (box) {
      box.addEventListener('change', function () { cmp.show[box.getAttribute('data-fst-show')] = box.checked; styleCompare(cmp); });
    });
    var canvas = q('[data-fst-strip]');
    var tip = q('[data-fst-strip-tip]');
    if (canvas) {
      canvas.addEventListener('mousemove', function (event) {
        var hit = residueAt(cmp, event);
        if (!hit || !tip) { if (tip) { tip.hidden = true; } return; }
        var a = cmp.af.ca[hit.resi], e = cmp.esm.ca[hit.resi];
        tip.hidden = false;
        tip.textContent = a.aa + hit.resi + ' · pLDDT ' + a.b.toFixed(0) + ' / ' + e.b.toFixed(0)
          + ' · ' + cmp.distance[hit.resi].toFixed(1) + ' Å apart';
        tip.style.left = Math.max(0, Math.min(hit.x + 10, canvas.clientWidth - tip.offsetWidth)) + 'px';
      });
      canvas.addEventListener('mouseleave', function () { if (tip) { tip.hidden = true; } });
      canvas.addEventListener('click', function (event) {
        var hit = residueAt(cmp, event);
        if (!hit || !state.cmpViewer) { return; }
        state.cmpViewer.zoomTo({ resi: hit.resi }, reducedMotion() ? 0 : 400);
        state.cmpViewer.render();
      });
    }
    var spin = els.resultsBody.querySelector('[data-fst-spin]');
    if (spin) {
      spin.addEventListener('click', function () {
        cmp.spinning = !cmp.spinning;
        state.cmpViewer.spin(cmp.spinning ? 'y' : false);
        spin.setAttribute('aria-pressed', cmp.spinning ? 'true' : 'false');
        spin.textContent = cmp.spinning ? 'Stop rotation' : 'Auto-rotate';
      });
    }
    var reset = els.resultsBody.querySelector('[data-fst-reset]');
    if (reset) {
      reset.addEventListener('click', function () { state.cmpViewer.zoomTo({}, reducedMotion() ? 0 : 400); state.cmpViewer.render(); });
    }
    var png = els.resultsBody.querySelector('[data-fst-png]');
    if (png) {
      png.addEventListener('click', function () {
        var link = document.createElement('a');
        link.href = state.cmpViewer.pngURI();
        link.download = cmp.protein.accession + '_alphafold_esmfold.png';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
      });
    }
    var pdb = els.resultsBody.querySelector('[data-fst-pdb]');
    if (pdb) {
      pdb.addEventListener('click', function () {
        var p = cmp.protein;
        var remarks = [
          'REMARK   1 FUSARIUM PROTEIN TOOLKIT, MAIZEGDB: TWO PREDICTIONS OF ONE PROTEIN, SUPERPOSED',
          'REMARK   1 CHAIN A: ' + p.accession + ' ' + title(p) + ' ALPHAFOLD DB MODEL V4, AS PUBLISHED',
          'REMARK   1 CHAIN B: ' + p.accession + ' ESMFOLD MODEL, MOVED ONTO CHAIN A',
          'REMARK   1 TM-SCORE ' + cmp.tm.tmTarget.toFixed(5) + ' OVER ' + cmp.tm.aligned + ' RESIDUES PAIRED BY NUMBER',
          'REMARK   1 RMSD ' + cmp.tm.rmsd.toFixed(2) + ' A; B-FACTORS ARE EACH MODEL\'S OWN PLDDT'
        ];
        saveFile(p.accession + '_alphafold_esmfold.pdb',
          remarks.join('\n') + '\n' + atomLines(cmp.af.text, 'A') + '\nTER\n' + cmp.esmMoved + '\nTER\nEND\n',
          'chemical/x-pdb');
      });
    }
    if (window.ResizeObserver) {
      var viewport = q('[data-fst-viewport]');
      cmp.observer = new window.ResizeObserver(MGDB.debounce(function () {
        if (state.cmp !== cmp) { return; }
        if (state.cmpViewer) { state.cmpViewer.resize(); state.cmpViewer.render(); }
        drawStrip(cmp);
      }, 120));
      if (viewport) { cmp.observer.observe(viewport); }
    }
  }

  function disposeCompare() {
    var cmp = state.cmp;
    state.cmp = null;
    if (cmp && cmp.observer) { cmp.observer.disconnect(); }
    if (state.cmpViewer) {
      try { state.cmpViewer.spin(false); state.cmpViewer.clear(); } catch (e) { /* already gone */ }
      /* The viewer's canvas lives in the panel being replaced; a new one is
         made on the next comparison. */
      state.cmpViewer = null;
    }
  }

  /* ----------------------------------------------------------------------
   * Init
   * ---------------------------------------------------------------------- */

  function bindExamples(scope) {
    Array.prototype.forEach.call(scope.querySelectorAll('[data-fst-example]'), function (chip) {
      if (chip.getAttribute('data-fst-bound')) { return; }
      chip.setAttribute('data-fst-bound', '1');
      chip.addEventListener('click', function () {
        els.input.value = chip.getAttribute('data-fst-example');
        run(els.input.value);
      });
    });
  }

  function init() {
    var root = document.querySelector('.fpt-structures-page');
    if (!root) { return; }
    API = root.getAttribute('data-api') || '/search/fusarium/fusarium_api.php';

    els.form = document.getElementById('fst-form');
    els.input = document.getElementById('fst-term');
    els.status = document.getElementById('fst-status');
    els.results = document.getElementById('fst-results');
    els.resultsTitle = document.getElementById('fst-results-title');
    els.resultsBody = document.getElementById('fst-results-body');
    if (!els.form || !els.input || !els.results || !els.resultsBody) { return; }

    els.form.addEventListener('submit', function (event) {
      event.preventDefault();
      run(els.input.value);
    });
    bindExamples(els.form);
    if (MGDB.typeahead && window.FPT) {
      MGDB.typeahead(els.input, { source: window.FPT.suggest, min: 2 });
    }

    /* Delegated once, for everything the results redraw. */
    els.resultsBody.addEventListener('click', function (event) {
      var choice = event.target.closest && event.target.closest('[data-fst-choose]');
      if (choice) { showProtein(parseInt(choice.getAttribute('data-fst-choose'), 10), state.view); return; }
      var tab = event.target.closest && event.target.closest('[data-fst-view]');
      if (tab && !tab.disabled) { showView(tab.getAttribute('data-fst-view')); }
    });
    els.resultsBody.addEventListener('keydown', function (event) {
      var tab = event.target.closest && event.target.closest('[role="tab"]');
      if (!tab || (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft')) { return; }
      var tabs = Array.prototype.filter.call(els.resultsBody.querySelectorAll('[role="tab"]'), function (t) { return !t.disabled; });
      var next = tabs[(tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
      if (next) { event.preventDefault(); next.focus(); showView(next.getAttribute('data-fst-view')); }
    });
    els.status.addEventListener('click', function (event) {
      var chip = event.target.closest && event.target.closest('[data-fst-example]');
      if (chip) { els.input.value = chip.getAttribute('data-fst-example'); run(els.input.value); }
    });

    var params = window.URLSearchParams ? new window.URLSearchParams(window.location.search) : null;
    var initial = (root.getAttribute('data-initial') || '').trim();
    if (initial) {
      run(initial, {
        view: root.getAttribute('data-initial-model') || 'alphafold',
        acc: params ? params.get('acc') : null
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
