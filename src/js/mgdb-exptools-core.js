/* file: mgdb-exptools-core.js
 *
 * purpose: Expression Tools (/expression/tools) -- the shared core: state,
 *          the API client, the sample selection, the router, the UI kit, the
 *          data table, chart helpers and statistics. The tools themselves
 *          register from mgdb-exptools-genes.js, -discover.js and
 *          -pangenome.js, which load after this file; everything starts on
 *          DOMContentLoaded, once all of them have registered.
 *
 *          Rebuilt from ExpressionTools (github andorfc/rna_seq_tools) to run
 *          inside MaizeGDB: it reads the site's own expression releases
 *          through search/expression_tools/expression_tools_api.php, and the
 *          gene-model and domain datasets through /api/v1/data, rather than
 *          qTeller files of its own.
 *
 *          A view is {id, group, title, nav, summary, selection, requires,
 *          render(root, ctx)}. ctx carries the view's URL parameters, an
 *          abort signal that fires when the reader navigates away, and
 *          subscriptions that are released at the same moment.
 *
 *          Every value shown is either measured or says "not measured";
 *          nothing is drawn as zero that was not measured.
 *
 * history:
 *  09/24/26  claude  created
 */
(function (window, document) {
  'use strict';

  var MGDB = window.MGDB = window.MGDB || {};
  var ET = MGDB.ET = MGDB.ET || {};

  /* ------------------------------------------------------------------------
     Constants
     ------------------------------------------------------------------------ */

  ET.views = [];
  ET.register = function (view) { ET.views.push(view); };

  ET.GROUPS = ['Genes', 'Visualize', 'Discover', 'Pan-genome', 'Protein', 'Data'];

  /* Tissue readings in anatomical order, each with a hue from the validated
     reference palette. The assignment was enumerated rather than picked:
     leaf green, floral magenta and seed yellow were fixed, the other four
     assigned to maximize the worst adjacent separation, which is 15.2 (OKLab
     x100, deuteranope) and 15.6 for normal vision. Adjacent means adjacent
     in THIS order, so bars are drawn in it; three of the hues are under 3:1
     on white, so every tissue-colored figure has a legend and a table. */
  ET.TISSUES = ['root', 'seedling / whole plant', 'leaf', 'stem', 'shoot apex', 'floral', 'seed', 'other'];
  ET.TISSUE_COLORS = {
    'root': '#eb6834', 'seedling / whole plant': '#2a78d6', 'leaf': '#008300', 'stem': '#1baf7a',
    'shoot apex': '#4a3aa7', 'floral': '#e87ba4', 'seed': '#eda100', 'other': '#9a9994'
  };
  ET.TISSUE_SYMBOLS = {
    'root': 'circle', 'seedling / whole plant': 'square', 'leaf': 'diamond', 'stem': 'triangle-up',
    'shoot apex': 'cross', 'floral': 'star', 'seed': 'hexagon', 'other': 'circle-open'
  };
  ET.CONDITIONS = ['abiotic stress', 'biotic stress', 'control', 'stress study'];
  ET.CONDITION_COLORS = { 'abiotic stress': '#2a78d6', 'biotic stress': '#eb6834', 'control': '#1baf7a', 'stress study': '#9a9994' };
  /* Genes in a chart take these in order and never cycle; a ninth folds
     into gray. Validated adjacent: worst CVD 9.1, normal vision 19.6. */
  ET.SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];
  ET.OTHER = '#9a9994';
  /* One hue for magnitude, blue light to dark; blue to red through gray for
     a signed value. */
  ET.SEQ = ['#eef4fd', '#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#184f95', '#0d366b'];
  ET.DIV = ['#2a78d6', '#86b6ef', '#f0efec', '#f19a8b', '#e34948'];
  ET.INK = '#1f2723';
  ET.MUTED = '#5b655e';
  ET.GRID = '#ece7dd';
  ET.LINE = '#dcd8cf';

  /* ------------------------------------------------------------------------
     Utilities
     ------------------------------------------------------------------------ */

  function esc(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* An expression value: three significant figures, never "0" for what was
     not measured. Below 0.01 it is "<0.01": pipelines write values such as
     4.8e-8 FPKM, which are zero for any reader, and scientific notation made
     them look like data. */
  function fmt(v, digits) {
    if (v == null || !isFinite(v)) { return '—'; }
    if (digits != null) { return Number(v).toFixed(digits); }
    var a = Math.abs(v);
    if (a === 0) { return '0'; }
    if (a >= 1000) { return Math.round(v).toLocaleString('en-US'); }
    if (a >= 100) { return v.toFixed(0); }
    if (a >= 10) { return v.toFixed(1); }
    if (a >= 0.01) { return v.toFixed(2); }
    return v > 0 ? '<0.01' : '>−0.01';
  }
  function fmtInt(n) { return (n == null || !isFinite(n)) ? '—' : Math.round(n).toLocaleString('en-US'); }
  function fmtR(r) { return (r == null || !isFinite(r)) ? 'n/a' : r.toFixed(3); }
  function plural(n, one, many) { return fmtInt(n) + ' ' + (n === 1 ? one : (many || one + 's')); }
  function locus(g) {
    return g && g.chr ? g.chr + ':' + fmtInt(g.start) + '–' + fmtInt(g.end) : '—';
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var args = arguments, self = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, ms || 250);
    };
  }

  function parseGeneList(text) {
    var seen = {}, out = [];
    String(text || '').split(/[\s,;|]+/).forEach(function (s) {
      s = s.trim();
      if (s && !seen[s]) { seen[s] = true; out.push(s); }
    });
    return out;
  }

  /* "chr5:11,000,000-11,500,000" -> {chr, start, end}, or null */
  function parseLocus(text) {
    var m = String(text || '').trim().replace(/,/g, '').match(/^([\w.\-]+)\s*[:\s]\s*(\d+)\s*(?:-|–|\.\.|\s)\s*(\d+)$/);
    if (!m) { return null; }
    return { chr: /^\d+$/.test(m[1]) ? 'chr' + m[1] : m[1], start: +m[2], end: +m[3] };
  }

  /* Sample ids <-> "1-40,52" */
  function compressIds(ids) {
    var s = ids.slice().sort(function (a, b) { return a - b; });
    var out = [], start = null, prev = null;
    s.forEach(function (i) {
      if (prev !== null && i === prev + 1) { prev = i; return; }
      if (start !== null) { out.push(start === prev ? String(start) : start + '-' + prev); }
      start = prev = i;
    });
    if (start !== null) { out.push(start === prev ? String(start) : start + '-' + prev); }
    return out.join(',');
  }
  function parseIds(spec) {
    var out = [];
    String(spec || '').split(/[\s,;]+/).forEach(function (part) {
      var m = part.match(/^(\d+)-(\d+)$/);
      if (m) {
        var a = +m[1], b = +m[2];
        if (b < a) { var t = a; a = b; b = t; }
        for (var i = a; i <= b && out.length < 5000; i++) { out.push(i); }
      } else if (/^\d+$/.test(part)) {
        out.push(+part);
      }
    });
    return out;
  }

  function csvCell(c) {
    var s = String(c == null ? '' : c);
    return /[",\n\r\t]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }
  function safeName(s) {
    return String(s || 'export').replace(/[^\w.\-]+/g, '_').replace(/_+/g, '_').slice(0, 80);
  }
  function downloadText(filename, text, type) {
    var url = URL.createObjectURL(new Blob([text], { type: type || 'text/plain' }));
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
  }
  function downloadTable(filename, header, rows, notes) {
    var lines = (notes || []).map(function (n) { return '# ' + n; });
    lines.push(header.map(csvCell).join('\t'));
    rows.forEach(function (r) { lines.push(r.map(function (c) { return String(c == null ? '' : c).replace(/[\t\n\r]/g, ' '); }).join('\t')); });
    downloadText(safeName(filename) + '.tsv', lines.join('\n') + '\n', 'text/tab-separated-values');
  }
  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return legacyCopy(text); });
    }
    return Promise.resolve(legacyCopy(text));
  }
  function legacyCopy(text) {
    var t = document.createElement('textarea');
    t.value = text;
    t.setAttribute('readonly', '');
    t.style.position = 'fixed';
    t.style.opacity = '0';
    document.body.appendChild(t);
    t.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    t.remove();
    return ok;
  }

  /* localStorage that never throws: private windows and blocked storage
     simply forget. */
  var storage = {
    get: function (k, d) {
      try { var v = window.localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch (e) { return d; }
    },
    set: function (k, v) {
      try { window.localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* forget */ }
    }
  };

  function shortStudy(name) {
    var clean = String(name || '').replace(/\s*\[.*?\]\s*/g, '');
    var m = clean.match(/([A-Z][\w\-]+)\s+(\d{4})/);
    return m ? m[1] + ' ' + m[2] : clean.slice(0, 24);
  }

  ET.util = {
    esc: esc, fmt: fmt, fmtInt: fmtInt, fmtR: fmtR, plural: plural, locus: locus, debounce: debounce,
    parseGeneList: parseGeneList, parseLocus: parseLocus, compressIds: compressIds, parseIds: parseIds,
    downloadText: downloadText, downloadTable: downloadTable, copyText: copyText, safeName: safeName,
    storage: storage, shortStudy: shortStudy, csvCell: csvCell
  };

  /* ------------------------------------------------------------------------
     Events
     ------------------------------------------------------------------------ */

  var listeners = {};
  var bus = ET.bus = {
    on: function (evt, fn) {
      (listeners[evt] = listeners[evt] || []).push(fn);
      return function () { bus.off(evt, fn); };
    },
    off: function (evt, fn) {
      listeners[evt] = (listeners[evt] || []).filter(function (f) { return f !== fn; });
    },
    emit: function (evt, data) {
      (listeners[evt] || []).slice().forEach(function (fn) {
        try { fn(data); } catch (e) { if (window.console) { console.error(e); } }
      });
    }
  };

  /* ------------------------------------------------------------------------
     The API
     ------------------------------------------------------------------------ */

  var memo = {};

  function apiUrl() {
    var app = document.getElementById('et-app');
    return (app && app.getAttribute('data-api')) || '/search/expression_tools/expression_tools_api.php';
  }

  /* action + params -> the data of {ok, data}. GET results are kept for the
     session; post: true sends a JSON body (gene lists can run to thousands). */
  function api(action, params, opts) {
    opts = opts || {};
    var clean = {};
    Object.keys(params || {}).forEach(function (k) {
      var v = params[k];
      if (v !== undefined && v !== null && v !== '') { clean[k] = v; }
    });
    var url = apiUrl() + '?action=' + encodeURIComponent(action);
    var init = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (opts.signal) { init.signal = opts.signal; }
    var key;
    if (opts.post) {
      clean.action = action;
      init.method = 'POST';
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(clean);
      key = url + '|' + init.body;
    } else {
      var qs = Object.keys(clean).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(clean[k]); }).join('&');
      if (qs) { url += '&' + qs; }
      key = url;
    }
    if (opts.cache !== false && memo[key]) { return memo[key]; }
    /* One retry when the transport fails -- a connection reset partway
       through a large response reads as an unparseable body -- but not when
       the server answered with an error of its own, and never after an
       abort. Every action is a read, so a POST is as safe to repeat. */
    var transport = function (message) { var e = new Error(message); e.transport = true; return e; };
    var attempt = function (left) {
      return fetch(url, init).then(function (res) {
        return res.json().catch(function () {
          throw transport('The server returned an unreadable response (HTTP ' + res.status + ').');
        }).then(function (body) {
          if (!body || !body.ok) { throw new Error((body && body.error) || ('Request failed (HTTP ' + res.status + ').')); }
          return body.data;
        });
      }, function (err) {
        if (err && err.name === 'AbortError') { throw err; }
        throw transport('The expression server could not be reached.');
      }).catch(function (err) {
        if (err && err.transport && left > 0 && !(opts.signal && opts.signal.aborted)) { return attempt(left - 1); }
        throw err;
      });
    };
    var p = attempt(1);
    if (opts.cache !== false) {
      memo[key] = p;
      p.catch(function () { delete memo[key]; });
    }
    return p;
  }
  ET.api = api;

  /* Values of many genes, kept gene by gene for the session, so a second view
     of the same genes costs nothing. -> {genes: [{row, gene, symbol, ...,
     values}], missing, map} with values aligned to the assay's columns.
     opts.genome reads another genome than the current one. */
  var geneCache = {};
  var aliasCache = {};

  function values(ids, opts) {
    opts = opts || {};
    var gk = opts.genome || ET.state.genome.key;
    var assay = opts.assay || 'rna';
    var need = [];
    ids.forEach(function (id) {
      var canon = aliasCache[gk + '|' + id];
      if (canon === null) { return; }
      if (!canon || !geneCache[gk + '|' + assay + '|' + canon]) { need.push(id); }
    });
    var chunks = [];
    for (var i = 0; i < need.length; i += 1000) { chunks.push(need.slice(i, i + 1000)); }
    var chain = Promise.resolve();
    chunks.forEach(function (chunk) {
      chain = chain.then(function () {
        return api('values', { genome: gk, assay: assay, ids: chunk }, { post: true, cache: false, signal: opts.signal }).then(function (d) {
          d.genes.forEach(function (g) { geneCache[gk + '|' + assay + '|' + g.gene] = g; });
          Object.keys(d.map).forEach(function (input) {
            var rows = d.map[input];
            var first = d.genes.filter(function (g) { return g.row === rows[0]; })[0];
            aliasCache[gk + '|' + input] = first ? first.gene : null;
          });
          d.missing.forEach(function (input) { aliasCache[gk + '|' + input] = null; });
        });
      });
    });
    return chain.then(function () {
      var genes = [], missing = [], map = {}, seen = {};
      ids.forEach(function (id) {
        var canon = aliasCache[gk + '|' + id];
        if (!canon) { missing.push(id); return; }
        map[id] = canon;
        var rec = geneCache[gk + '|' + assay + '|' + canon];
        if (rec && !seen[canon]) { genes.push(rec); seen[canon] = true; }
      });
      return { genes: genes, missing: missing, map: map };
    });
  }
  ET.values = values;

  /* ------------------------------------------------------------------------
     State: genomes, the current genome's catalog, the basket
     ------------------------------------------------------------------------ */

  var state = ET.state = {
    boot: null,          // the bootstrap payload the page carries
    genomes: [],
    genome: null,        // the current genome's entry
    catalog: null,       // its samples and studies
    basket: []
  };

  ET.genomeByKey = function (key) {
    var k = String(key || '').toLowerCase();
    return state.genomes.filter(function (g) { return g.key.toLowerCase() === k || g.genome.toLowerCase() === k; })[0] || null;
  };

  /* The catalog of the current genome, prepared once: every sample gets
     its column (idx), its study record, and a tissue that is always one of
     ET.TISSUES. */
  function prepareCatalog(cat) {
    var studies = {};
    cat.studies.forEach(function (s) { studies[s.id] = s; });
    Object.keys(cat.assays).forEach(function (a) {
      cat.assays[a].samples.forEach(function (s, i) {
        s.idx = i;
        s.assay = a;
        s.studyRec = studies[s.study] || { id: s.study, name: 'unknown' };
        s.studyName = s.studyRec.name;
        if (ET.TISSUES.indexOf(s.tissue) === -1) { s.tissue = 'other'; }
      });
      cat.assays[a].byId = {};
      cat.assays[a].byKey = {};
      cat.assays[a].samples.forEach(function (s) { cat.assays[a].byId[s.id] = s; cat.assays[a].byKey[s.studyName + '|' + s.label] = s; });
    });
    cat.studyById = studies;
    return cat;
  }

  /* Another genome's catalog, prepared, without switching to it. */
  ET.catalogOf = function (key) {
    if (state.genome && state.catalog && state.genome.key === key) { return Promise.resolve(state.catalog); }
    return api('catalog', { genome: key }).then(prepareCatalog);
  };

  var useSeq = 0;
  ET.useGenome = function (key) {
    var g = ET.genomeByKey(key) || ET.genomeByKey('B73v5') || state.genomes[0];
    /* Every request supersedes a switch still in flight, including one for
       the genome already loaded: a slow catalog must not land on top of the
       reader's later choice. */
    var my = ++useSeq;
    if (state.genome && state.catalog && state.genome.key === g.key) { return Promise.resolve(g); }
    return api('catalog', { genome: g.key }).then(function (cat) {
      /* A later switch won while this catalog was in flight: leave the
         state to it (the render that asked checks its own sequence). */
      if (my !== useSeq) { return state.genome; }
      state.genome = g;
      state.catalog = prepareCatalog(cat);
      state.basket = storage.get('mgdb-exptools-basket-' + g.key, []);
      SEL.reset();
      bus.emit('genome', g);
      bus.emit('basket');
      return g;
    });
  };

  ET.samples = function (assay) {
    var a = state.catalog && state.catalog.assays[assay || 'rna'];
    return a ? a.samples : [];
  };
  ET.hasAssay = function (assay) { return !!(state.catalog && state.catalog.assays[assay]); };
  ET.unit = function () { return state.genome && state.genome.nam && state.genome.key !== 'B73v5' ? 'FPKM' : 'FPKM or TPM'; };

  ET.basket = {
    list: function () { return state.basket.slice(); },
    add: function (genes) {
      var before = state.basket.length;
      genes.forEach(function (g) { if (g && state.basket.indexOf(g) === -1) { state.basket.push(g); } });
      if (state.basket.length !== before) { ET.basket.save(); }
      return state.basket.length - before;
    },
    remove: function (g) { state.basket = state.basket.filter(function (x) { return x !== g; }); ET.basket.save(); },
    clear: function () { state.basket = []; ET.basket.save(); },
    save: function () { storage.set('mgdb-exptools-basket-' + state.genome.key, state.basket); bus.emit('basket'); }
  };

  /* ------------------------------------------------------------------------
     The sample selection

     One selection of RNA samples, shared by every tool, carried in the URL
     (s=) so a link reproduces a figure. Presets are rules over the catalog:
     every usable sample, a study, a tissue reading, a stress condition, the
     23 samples all 26 NAM genomes share. A hand-picked set is carried as
     sample ids. Samples that were not measured (the index builder lists
     them) are never in a default.
     ------------------------------------------------------------------------ */

  var SEL = ET.SEL = {
    _id: 'all', _label: 'All samples', _set: {},

    reset: function () { this.apply('all', true); },

    usable: function () { return ET.samples('rna').filter(function (s) { return s.usable; }); },

    options: function () {
      var samples = ET.samples('rna');
      var usable = this.usable();
      var ids = function (list) { return list.map(function (s) { return s.id; }); };
      var out = [{ id: 'all', group: 'Built in', label: 'All samples', ids: ids(usable) }];
      var shared = (state.catalog.shared_sample_ids || []);
      if (shared.length && shared.length < usable.length) {
        out.push({ id: 'shared', group: 'Built in', label: 'The 23 samples shared by the NAM genomes', ids: shared.slice() });
      }
      var studies = state.catalog.studies.filter(function (st) { return st.assay === 'rna'; });
      if (studies.length > 1) {
        studies.forEach(function (st) {
          var list = usable.filter(function (s) { return s.study === st.id; });
          if (list.length) { out.push({ id: 'study:' + st.id, group: 'By study', label: shortStudy(st.name) + ' — ' + st.name.replace(/\s*\[.*?\]\s*/g, ''), ids: ids(list) }); }
        });
      }
      ET.TISSUES.forEach(function (t) {
        var list = usable.filter(function (s) { return s.tissue === t; });
        if (list.length && list.length < usable.length) { out.push({ id: 'tissue:' + t, group: 'By tissue', label: t, ids: ids(list) }); }
      });
      ET.CONDITIONS.forEach(function (c) {
        var list = usable.filter(function (s) { return s.condition === c; });
        if (list.length) { out.push({ id: 'cond:' + c.split(' ')[0], group: 'By stress condition', label: c, ids: ids(list) }); }
      });
      var stressIds = usable.filter(function (s) { return !!s.condition; });
      if (stressIds.length && stressIds.length < usable.length) {
        out.push({ id: 'nostress', group: 'By stress condition', label: 'Leave out the stress studies', ids: ids(usable.filter(function (s) { return !s.condition; })) });
      }
      this.saved().forEach(function (s) {
        var keep = SEL.resolveSaved(s), had = (s.keys || s.ids || []).length;
        if (keep.length) { out.push({ id: s.id, group: 'Saved sets', label: s.name + (keep.length < had ? ' (' + keep.length + ' of ' + had + ' samples)' : ''), ids: keep, saved: true }); }
      });
      void samples;
      return out;
    },
    find: function (id) { return this.options().filter(function (o) { return o.id === id; })[0] || null; },

    apply: function (id, silent) {
      var o = this.find(id) || this.find('all');
      this._id = o.id;
      this._label = o.label;
      this._set = {};
      var set = this._set;
      o.ids.forEach(function (i) { set[i] = true; });
      if (!silent) { bus.emit('selection'); }
    },
    /* A saved set is found again by study name and label, which outlive a
       release; sets saved before keys were kept fall back to their ids. */
    resolveSaved: function (s) {
      var rna = state.catalog.assays.rna;
      if (s.keys && s.keys.length) { return s.keys.map(function (k) { return rna.byKey[k]; }).filter(Boolean).map(function (x) { return x.id; }); }
      return (s.ids || []).filter(function (i) { return !!rna.byId[i]; });
    },
    applyIds: function (ids, silent, warn) {
      var byId = state.catalog.assays.rna.byId;
      var set = {};
      var unknown = 0;
      ids.forEach(function (i) { if (byId[i]) { set[i] = true; } else { unknown++; } });
      if (warn && unknown) { toast(plural(unknown, 'sample id') + ' in the link ' + (unknown === 1 ? 'is' : 'are') + ' not in this release and ' + (unknown === 1 ? 'was' : 'were') + ' left out.'); }
      var n = Object.keys(set).length;
      var match = this.options().filter(function (o) {
        return o.ids.length === n && o.ids.every(function (i) { return set[i]; });
      })[0];
      this._set = set;
      this._id = match ? match.id : 'custom';
      this._label = match ? match.label : 'Custom selection';
      if (!silent) { bus.emit('selection'); }
    },
    has: function (id) { return !!this._set[id]; },
    id: function () { return this._id; },
    label: function () { return this._id === 'custom' ? 'Custom (' + this.size() + ' samples)' : this._label; },
    size: function () { return Object.keys(this._set).length; },
    total: function () { return ET.samples('rna').length; },
    /* Selected samples in catalog order. */
    list: function () { var set = this._set; return ET.samples('rna').filter(function (s) { return set[s.id]; }); },
    ids: function () { return this.list().map(function (s) { return s.id; }); },
    cols: function () { return this.list().map(function (s) { return s.idx; }); },
    isDefault: function () { return this._id === 'all'; },
    /* What the API is sent: nothing for the default, ids otherwise. */
    api: function () { return this.isDefault() ? '' : compressIds(this.ids()); },
    /* Sample ids are numbered per release, so a hand-picked selection
       carries the release it was made on; a link from another release says
       so instead of silently choosing other samples. */
    toParam: function () {
      if (this._id === 'all') { return ''; }
      if (this._id === 'custom') { return 'ids:' + compressIds(this.ids()) + (state.genome && state.genome.release ? '@' + state.genome.release : ''); }
      return this._id;
    },
    fromParam: function (p) {
      if (!p) { this.apply('all', true); return; }
      if (p.indexOf('ids:') === 0) {
        var spec = p.slice(4), at = spec.indexOf('@');
        var rel = at >= 0 ? spec.slice(at + 1) : '', ids = parseIds(at >= 0 ? spec.slice(0, at) : spec);
        var now = state.genome && state.genome.release ? state.genome.release : '';
        if (rel && now && rel !== now) {
          toast('This link chose its samples on release ' + rel + '; this is ' + now + ', whose sample ids differ. Showing all samples.');
          this.apply('all', true);
          return;
        }
        this.applyIds(ids, true, true);
        return;
      }
      if (this.find(p)) { this.apply(p, true); return; }
      if (p.indexOf('saved:') === 0) { toast('The saved sample set “' + p.slice(6).replace(/-/g, ' ') + '” is not in this browser. Showing all samples.'); }
      this.apply('all', true);
    },
    saved: function () { return (storage.get('mgdb-exptools-sets', {})[state.genome.key] || []); },
    save: function (name) {
      name = String(name || '').trim();
      if (!name) { return; }
      var all = storage.get('mgdb-exptools-sets', {});
      var list = (all[state.genome.key] || []).filter(function (s) { return s.name !== name; });
      var set = { id: 'saved:' + name.toLowerCase().replace(/\W+/g, '-'), name: name, ids: this.ids(),
                  keys: this.list().map(function (s) { return s.studyName + '|' + s.label; }), release: state.genome.release || '' };
      list.push(set);
      all[state.genome.key] = list;
      storage.set('mgdb-exptools-sets', all);
      this._id = set.id;
      this._label = set.name;
      bus.emit('selection');
    },
    removeSaved: function (id) {
      var all = storage.get('mgdb-exptools-sets', {});
      all[state.genome.key] = (all[state.genome.key] || []).filter(function (s) { return s.id !== id; });
      storage.set('mgdb-exptools-sets', all);
      if (this._id === id) { this.apply('all'); } else { bus.emit('selection'); }
    }
  };

  /* ------------------------------------------------------------------------
     UI kit. Plain DOM; each piece returns an element.
     ------------------------------------------------------------------------ */

  function el(html) {
    var t = document.createElement('template');
    t.innerHTML = String(html).trim();
    return t.content.firstElementChild;
  }
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  var panelCount = 0;
  /* A white card with a heading. The top edge rotates through the hub
     shell's eight colors, so panels in a stack read as distinct. */
  function panel(opts) {
    opts = opts || {};
    panelCount++;
    var id = opts.id || ('et-panel-' + panelCount);
    var p = el('<section class="et-panel" id="' + esc(id) + '"' + (opts.title ? ' aria-labelledby="' + esc(id) + '-title"' : '') + '>' +
      (opts.title || opts.actions ? '<div class="et-panel-head"><div class="et-panel-titles">' +
        (opts.title ? '<h2 id="' + esc(id) + '-title">' + opts.title + '</h2>' : '') +
        (opts.sub ? '<p class="et-panel-sub">' + opts.sub + '</p>' : '') +
        '</div><div class="et-panel-actions"></div></div>' : '') +
      '<div class="et-panel-body"></div></section>');
    if (opts.tone) { p.classList.add('et-tone-' + opts.tone); }
    if (opts.actions) {
      var holder = qs('.et-panel-actions', p);
      (Array.isArray(opts.actions) ? opts.actions : [opts.actions]).forEach(function (a) { holder.appendChild(a); });
    }
    return p;
  }
  function body(p) { return qs('.et-panel-body', p); }

  function button(label, opts) {
    opts = opts || {};
    var b = el('<button type="button" class="mgdb-button ' + (opts.kind || 'mgdb-button-quiet') + ' mgdb-button-sm"' +
      (opts.title ? ' title="' + esc(opts.title) + '"' : '') + '>' + label + '</button>');
    if (opts.onClick) { b.addEventListener('click', opts.onClick); }
    return b;
  }

  /* A row of mutually exclusive chips (the site's .mgdb-chip). */
  function segmented(options, value, onChange, label) {
    var w = el('<div class="et-seg" role="group"' + (label ? ' aria-label="' + esc(label) + '"' : '') + '></div>');
    w.value = value;
    options.forEach(function (o) {
      var b = el('<button type="button" class="mgdb-chip" data-v="' + esc(o.value) + '"' + (o.title ? ' title="' + esc(o.title) + '"' : '') + '>' + esc(o.label) + '</button>');
      b.addEventListener('click', function () {
        if (w.value === o.value) { return; }
        w.set(o.value);
        if (onChange) { onChange(o.value); }
      });
      w.appendChild(b);
    });
    w.set = function (v) {
      w.value = v;
      qsa('button', w).forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-v') === String(v) ? 'true' : 'false'); });
    };
    w.set(value);
    return w;
  }

  function select(options, value, cls) {
    var s = el('<select class="et-select' + (cls ? ' ' + cls : '') + '"></select>');
    options.forEach(function (o) {
      if (o.group) {
        var og = document.createElement('optgroup');
        og.label = o.group;
        o.options.forEach(function (x) { og.appendChild(new Option(x.label, x.value)); });
        s.appendChild(og);
      } else {
        var opt = typeof o === 'string' ? { value: o, label: o } : o;
        s.appendChild(new Option(opt.label, opt.value));
      }
    });
    if (value != null) { s.value = value; }
    return s;
  }

  /* A labeled control in the hub shell's form row. */
  function field(label, control, hint, cls) {
    var f = el('<div class="mgdb-hub-field et-field' + (cls ? ' ' + cls : '') + '"><label></label></div>');
    var lab = qs('label', f);
    lab.innerHTML = label;
    var c = typeof control === 'string' ? el(control) : control;
    var input = c.matches && c.matches('input, select, textarea') ? c : qs('input, select, textarea', c);
    if (input) {
      var id = input.id || ('et-f-' + Math.random().toString(36).slice(2, 9));
      input.id = id;
      lab.setAttribute('for', id);
    }
    f.appendChild(c);
    if (hint) { f.appendChild(el('<span class="mgdb-hint">' + hint + '</span>')); }
    return f;
  }

  function loading(text) {
    return el('<div class="mgdb-loading" role="status"><span class="mgdb-spinner" aria-hidden="true"></span><span>' + esc(text || 'Loading…') + '</span></div>');
  }
  function message(html, kind) {
    return el('<div class="mgdb-message mgdb-message-' + (kind || 'info') + ' et-message" role="' + (kind === 'error' ? 'alert' : 'status') + '"><div>' + html + '</div></div>');
  }
  function errorBox(err) {
    if (err && err.name === 'AbortError') { return el('<div hidden></div>'); }
    if (window.console && err && err.stack) { console.error(err); }
    return message('<strong>That did not work.</strong> ' + esc(err && err.message ? err.message : err), 'error');
  }
  function empty(title, html) {
    return el('<div class="mgdb-empty et-empty"><h3>' + esc(title) + '</h3>' + (html ? '<p>' + html + '</p>' : '') + '</div>');
  }

  function toast(msg) {
    var root = qs('#et-toasts');
    if (!root) { return; }
    var t = el('<div class="et-toast" role="status"></div>');
    t.textContent = msg;
    root.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('is-in'); });
    setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 300); }, 3200);
  }

  /* A modal dialog on <dialog>; Escape and the backdrop close it, and focus
     returns to whatever opened it. */
  function modal(opts) {
    var d = el('<dialog class="et-dialog' + (opts.wide ? ' is-wide' : '') + '" aria-labelledby="et-dialog-title">' +
      '<div class="et-dialog-head"><h2 id="et-dialog-title"></h2><button type="button" class="mgdb-button mgdb-button-quiet mgdb-button-sm" data-close>Close</button></div>' +
      '<div class="et-dialog-body"></div><div class="et-dialog-foot"></div></dialog>');
    qs('h2', d).textContent = opts.title || '';
    var b = qs('.et-dialog-body', d);
    if (typeof opts.body === 'string') { b.innerHTML = opts.body; } else if (opts.body) { b.appendChild(opts.body); }
    var foot = qs('.et-dialog-foot', d);
    (opts.actions || []).forEach(function (a) { foot.appendChild(a); });
    if (!(opts.actions || []).length) { foot.remove(); }
    var opener = document.activeElement;
    var close = function () {
      if (d.open) { d.close(); }
      d.remove();
      if (opener && opener.focus) { try { opener.focus(); } catch (e) { /* gone */ } }
    };
    qs('[data-close]', d).addEventListener('click', close);
    d.addEventListener('cancel', function (e) { e.preventDefault(); close(); });
    d.addEventListener('click', function (e) { if (e.target === d) { close(); } });
    document.body.appendChild(d);
    if (d.showModal) { d.showModal(); } else { d.setAttribute('open', ''); }
    return { el: d, close: close };
  }

  function promptText(title, label, value) {
    return new Promise(function (resolve) {
      var input = el('<input type="text" class="et-input">');
      input.value = value || '';
      var done = false;
      var m;
      var ok = button('Save', { kind: 'mgdb-button-primary', onClick: function () { done = true; resolve(input.value.trim() || null); m.close(); } });
      m = modal({ title: title, body: field(label, input), actions: [ok] });
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); ok.click(); } });
      m.el.addEventListener('close', function () { if (!done) { resolve(null); } });
      setTimeout(function () { input.focus(); }, 30);
    });
  }

  function geneLink(gene, label) {
    if (!gene) { return '—'; }
    return '<a class="et-gene-link" href="' + esc(ET.href('gene', { id: gene })) + '">' + esc(label || gene) + '</a>';
  }
  /* A gene id with its symbol (or, off B73 v5, its B73 v5 counterpart's). */
  function geneLabel(g) {
    if (!g) { return '—'; }
    var sym = g.symbol || (g.b73_symbol ? 'B73 ' + g.b73_symbol : '');
    return geneLink(g.gene) + (sym ? ' <span class="et-sym">' + esc(sym) + '</span>' : '');
  }

  ET.ui = {
    el: el, qs: qs, qsa: qsa, panel: panel, body: body, button: button, segmented: segmented, select: select,
    field: field, loading: loading, message: message, errorBox: errorBox, empty: empty, toast: toast,
    modal: modal, promptText: promptText, geneLink: geneLink, geneLabel: geneLabel
  };

  /* ------------------------------------------------------------------------
     Gene inputs
     ------------------------------------------------------------------------ */

  /* One gene, with suggestions from the genome's annotation index: ids,
     symbols, older ids, words in names and descriptions. opts.genome, a
     function returning a genome key, suggests from another genome. */
  function geneInput(opts) {
    opts = opts || {};
    var w = el('<div class="et-ta"><input type="text" class="et-input et-mono" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false"><ul class="et-ta-list" role="listbox" hidden></ul></div>');
    var input = qs('input', w);
    var list = qs('ul', w);
    var listId = 'et-ta-' + Math.random().toString(36).slice(2, 9);
    list.id = listId;
    input.setAttribute('aria-controls', listId);
    input.placeholder = opts.placeholder || 'Gene id, symbol or name';
    input.value = opts.value || '';
    if (opts.label) { input.setAttribute('aria-label', opts.label); }
    var items = [], active = -1, seq = 0;
    w.input = input;
    Object.defineProperty(w, 'value', { get: function () { return input.value.trim(); }, set: function (v) { input.value = v; } });

    function render() {
      list.innerHTML = '';
      if (!items.length) { list.hidden = true; input.setAttribute('aria-expanded', 'false'); return; }
      items.forEach(function (it, i) {
        var li = el('<li role="option" class="et-ta-item' + (i === active ? ' is-active' : '') + '" id="' + listId + '-' + i + '"></li>');
        li.setAttribute('aria-selected', i === active ? 'true' : 'false');
        var sym = it.symbol || (it.b73_symbol ? 'B73 ' + it.b73_symbol : '');
        li.innerHTML = '<span class="et-mono">' + esc(it.gene) + '</span>' + (sym ? ' <strong>' + esc(sym) + '</strong>' : '') +
          '<span class="et-ta-meta">' + esc([it.chr ? it.chr + ':' + fmtInt(it.start) : '', it.name || '', it.match && it.match !== 'gene id' && it.match !== 'symbol' ? it.match : ''].filter(Boolean).join(' · ')) + '</span>';
        li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(it.gene); });
        list.appendChild(li);
      });
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      input.setAttribute('aria-activedescendant', active >= 0 ? listId + '-' + active : '');
    }
    function pick(g) {
      input.value = g;
      items = [];
      render();
      if (opts.onPick) { opts.onPick(g); }
    }
    var search = debounce(function () {
      var q = input.value.trim();
      if (q.length < 2 || /[\s,;]/.test(q)) { items = []; render(); return; }
      var my = ++seq;
      api('search', { genome: (opts.genome && opts.genome()) || state.genome.key, q: q, limit: 10 }).then(function (d) {
        if (my !== seq) { return; }
        items = d.results;
        active = -1;
        render();
      }, function () { /* suggestions are best effort */ });
    }, 180);
    input.addEventListener('input', search);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' && items.length) { active = (active + 1) % items.length; render(); e.preventDefault(); }
      else if (e.key === 'ArrowUp' && items.length) { active = (active - 1 + items.length) % items.length; render(); e.preventDefault(); }
      else if (e.key === 'Enter') {
        e.preventDefault();
        if (active >= 0 && items[active]) { pick(items[active].gene); }
        else { items = []; render(); if (opts.onPick && input.value.trim()) { opts.onPick(input.value.trim()); } }
      } else if (e.key === 'Escape') { items = []; render(); }
    });
    input.addEventListener('blur', function () { setTimeout(function () { items = []; render(); }, 150); });
    return w;
  }

  /* Many genes: paste, the basket, a file, an example. */
  function geneListInput(opts) {
    opts = opts || {};
    var w = el('<div class="et-genelist"><textarea class="et-input et-mono" rows="' + (opts.rows || 5) + '" spellcheck="false" placeholder="Gene ids or symbols, one per line or separated by commas"></textarea>' +
      '<div class="et-genelist-bar"><span class="et-count" aria-live="polite"></span><span class="et-spacer"></span></div></div>');
    var ta = qs('textarea', w);
    if (opts.label) { ta.setAttribute('aria-label', opts.label); }
    var bar = qs('.et-genelist-bar', w);
    var countEl = qs('.et-count', w);
    var basketBtn = button('Use basket', { onClick: function () { ta.value = ET.basket.list().join('\n'); count(); } });
    var fileInput = el('<input type="file" accept=".txt,.csv,.tsv,.list" hidden>');
    var fileBtn = button('Load a file', { title: 'A text file with one identifier per line', onClick: function () { fileInput.click(); } });
    bar.appendChild(basketBtn);
    bar.appendChild(fileBtn);
    bar.appendChild(fileInput);
    if (opts.example && opts.example.length) {
      bar.appendChild(button('Example', { onClick: function () { ta.value = opts.example.join('\n'); count(); } }));
    }
    bar.appendChild(button('Clear', { onClick: function () { ta.value = ''; count(); } }));
    function count() {
      var n = parseGeneList(ta.value).length;
      countEl.textContent = n ? plural(n, 'gene') : '';
      basketBtn.textContent = 'Use basket (' + ET.basket.list().length + ')';
      basketBtn.disabled = !ET.basket.list().length;
    }
    ta.value = (opts.value || []).join('\n');
    ta.addEventListener('input', count);
    fileInput.addEventListener('change', function (e) {
      var f = e.target.files[0];
      if (!f) { return; }
      f.text().then(function (text) {
        var ids = text.split(/\r?\n/).map(function (l) { return l.split(/[\t,; ]/)[0].trim(); }).filter(function (x) { return x && !/^#/.test(x); });
        ta.value = ids.join('\n');
        count();
        toast('Loaded ' + plural(ids.length, 'line') + ' from ' + f.name);
      });
    });
    var off = bus.on('basket', count);
    count();
    return { el: w, textarea: ta, get: function () { return parseGeneList(ta.value); },
             set: function (l) { ta.value = l.join('\n'); count(); }, dispose: off };
  }

  ET.ui.geneInput = geneInput;
  ET.ui.geneListInput = geneListInput;

  /* ------------------------------------------------------------------------
     Data table: sortable, filterable, paged, exported as TSV, rows can go to
     the basket. Built on the site's .mgdb-table, sort control on the header
     button as the shared stylesheet expects.

     columns: [{key, label, type: 'num'|'str', value(row), render(row) -> html,
                export(row), title, heat, action}]

     An action column holds a link per row (Compare): it has no sort button,
     and the filter and the TSV leave it out.
     ------------------------------------------------------------------------ */

  function DataTable(container, opts) {
    this.columns = opts.columns;
    this.pageSize = opts.pageSize || 25;
    this.selectable = !!opts.selectable;
    this.exportName = opts.exportName || 'table';
    this.notes = opts.notes || [];
    this.rowKey = opts.rowKey || 'gene';
    this.caption = opts.caption || '';
    this.sort = opts.sort || null;
    this.emptyText = opts.emptyText || 'No rows.';
    this.page = 0;
    this.filterText = '';
    this.selected = {};
    var self = this;
    this.el = el('<div class="et-dt">' +
      '<div class="et-dt-bar">' +
        (opts.filter === false ? '' : '<div class="et-dt-filter"><label class="mgdb-visually-hidden">Filter rows</label><input type="search" class="et-input" placeholder="Filter rows"></div>') +
        '<span class="et-dt-count" aria-live="polite"></span><span class="et-spacer"></span><span class="et-dt-tools"></span></div>' +
      '<div class="mgdb-table-scroll et-dt-scroll" tabindex="0"><table class="mgdb-table et-table"><caption class="mgdb-visually-hidden"></caption><thead></thead><tbody></tbody></table></div>' +
      '<nav class="et-dt-pager" aria-label="Table pages"></nav></div>');
    qs('caption', this.el).textContent = this.caption || this.exportName.replace(/_/g, ' ');
    var tools = qs('.et-dt-tools', this.el);
    if (this.selectable) {
      this.selBtn = button('Add to basket', { onClick: function () {
        var keys = Object.keys(self.selected);
        var n = ET.basket.add(keys);
        toast(n ? 'Added ' + plural(n, 'gene') + ' to the basket' : 'Already in the basket');
      } });
      this.selBtn.disabled = true;
      tools.appendChild(this.selBtn);
    }
    (opts.toolbar || []).forEach(function (t) { tools.appendChild(t); });
    tools.appendChild(button('Download TSV', { onClick: function () { self.exportTsv(); } }));
    var f = qs('.et-dt-filter input', this.el);
    if (f) {
      var fid = 'et-dt-f-' + Math.random().toString(36).slice(2, 8);
      f.id = fid;
      qs('.et-dt-filter label', this.el).setAttribute('for', fid);
      f.addEventListener('input', debounce(function () { self.filterText = f.value.trim().toLowerCase(); self.page = 0; self.render(); }, 150));
    }
    container.appendChild(this.el);
    this.setRows(opts.rows || []);
  }
  DataTable.prototype.val = function (c, r) { return c.value ? c.value(r) : r[c.key]; };
  DataTable.prototype.setRows = function (rows) { this.rows = rows; this.page = 0; this.selected = {}; this.render(); };
  DataTable.prototype.view = function () {
    var self = this;
    var rows = this.rows;
    if (this.filterText) {
      var f = this.filterText;
      var cols = this.columns.filter(function (c) { return c.type !== 'num' && !c.action; });
      rows = rows.filter(function (r) {
        return cols.some(function (c) { return String(self.val(c, r) == null ? '' : self.val(c, r)).toLowerCase().indexOf(f) !== -1; });
      });
    }
    if (this.sort) {
      var c = this.columns.filter(function (x) { return x.key === self.sort.key; })[0];
      if (c) {
        var dir = this.sort.dir === 'asc' ? 1 : -1;
        var num = c.type === 'num';
        rows = rows.slice().sort(function (a, b) {
          var x = self.val(c, a), y = self.val(c, b);
          var xm = x == null || x === '' || (num && !isFinite(x)), ym = y == null || y === '' || (num && !isFinite(y));
          if (xm || ym) { return (xm ? 1 : 0) - (ym ? 1 : 0); }   // missing last, both directions
          if (num) { return dir * (x - y); }
          return dir * String(x).localeCompare(String(y), undefined, { numeric: true, sensitivity: 'base' });
        });
      }
    }
    return rows;
  };
  DataTable.prototype.render = function () {
    var self = this;
    var rows = this.view();
    var size = this.pageSize === 'all' ? Math.max(1, rows.length) : this.pageSize;
    var pages = Math.max(1, Math.ceil(rows.length / size));
    this.page = Math.min(this.page, pages - 1);
    var slice = rows.slice(this.page * size, (this.page + 1) * size);
    var thead = qs('thead', this.el), tbody = qs('tbody', this.el);
    var heatMax = {};
    this.columns.forEach(function (c) {
      if (!c.heat) { return; }
      var m = 0;
      rows.forEach(function (r) { var v = self.val(c, r); if (isFinite(v) && v > m) { m = v; } });
      heatMax[c.key] = m;
    });
    var head = '<tr>' + (this.selectable ? '<th scope="col" class="et-sel"><input type="checkbox" aria-label="Select this page"></th>' : '');
    this.columns.forEach(function (c) {
      if (c.action) { head += '<th scope="col" class="et-dt-action">' + c.label + '</th>'; return; }
      var sort = self.sort && self.sort.key === c.key ? (self.sort.dir === 'asc' ? 'ascending' : 'descending') : 'none';
      head += '<th scope="col" aria-sort="' + sort + '"' + (c.type === 'num' ? ' class="mgdb-numeric"' : '') + (c.title ? ' title="' + esc(c.title) + '"' : '') +
        '><button type="button" data-k="' + esc(c.key) + '">' + c.label + '</button></th>';
    });
    thead.innerHTML = head + '</tr>';
    if (!slice.length) {
      tbody.innerHTML = '<tr><td colspan="' + (this.columns.length + (this.selectable ? 1 : 0)) + '" class="et-dt-empty">' + this.emptyText + '</td></tr>';
    } else {
      tbody.innerHTML = slice.map(function (r) {
        var key = r[self.rowKey];
        var cells = self.selectable ? '<td class="et-sel"><input type="checkbox" data-key="' + esc(key) + '"' + (self.selected[key] ? ' checked' : '') + ' aria-label="Select ' + esc(key) + '"></td>' : '';
        self.columns.forEach(function (c) {
          var v = self.val(c, r);
          var html = c.render ? c.render(r) : (c.type === 'num' ? (c.int ? fmtInt(v) : fmt(v)) : esc(v == null || v === '' ? '—' : v));
          var style = '';
          if (c.heat && isFinite(v) && heatMax[c.key] > 0) {
            var t = Math.min(1, Math.log(v + 1) / Math.log(heatMax[c.key] + 1));
            style = ' style="--et-heat:' + t.toFixed(3) + '"';
          }
          cells += '<td' + (c.type === 'num' ? ' class="mgdb-numeric' + (c.heat ? ' et-heat' : '') + '"' : (c.action ? ' class="et-dt-action"' : '')) + style + '>' + html + '</td>';
        });
        return '<tr>' + cells + '</tr>';
      }).join('');
    }
    qsa('th button[data-k]', thead).forEach(function (b) {
      b.addEventListener('click', function () {
        var k = b.getAttribute('data-k');
        var c = self.columns.filter(function (x) { return x.key === k; })[0];
        if (self.sort && self.sort.key === k) { self.sort.dir = self.sort.dir === 'asc' ? 'desc' : 'asc'; }
        else { self.sort = { key: k, dir: c.type === 'num' ? 'desc' : 'asc' }; }
        self.render();
        var again = qs('th button[data-k="' + k + '"]', self.el);
        if (again) { again.focus(); }
      });
    });
    if (this.selectable) {
      var all = qs('thead input', this.el);
      all.checked = slice.length > 0 && slice.every(function (r) { return self.selected[r[self.rowKey]]; });
      all.addEventListener('change', function () {
        slice.forEach(function (r) { if (all.checked) { self.selected[r[self.rowKey]] = true; } else { delete self.selected[r[self.rowKey]]; } });
        self.render();
      });
      qsa('tbody input[data-key]', this.el).forEach(function (cb) {
        cb.addEventListener('change', function () {
          if (cb.checked) { self.selected[cb.getAttribute('data-key')] = true; } else { delete self.selected[cb.getAttribute('data-key')]; }
          self.updateSel();
        });
      });
      this.updateSel();
    }
    qs('.et-dt-count', this.el).textContent = rows.length === this.rows.length ? plural(rows.length, 'row') : fmtInt(rows.length) + ' of ' + plural(this.rows.length, 'row');
    var pager = qs('.et-dt-pager', this.el);
    pager.innerHTML = '';
    if (rows.length > 10) {
      var sizeSel = select([{ value: '10', label: '10 rows' }, { value: '25', label: '25 rows' }, { value: '50', label: '50 rows' }, { value: '100', label: '100 rows' }, { value: 'all', label: 'All rows' }], String(this.pageSize), 'et-select-sm');
      sizeSel.setAttribute('aria-label', 'Rows per page');
      sizeSel.addEventListener('change', function () { self.pageSize = sizeSel.value === 'all' ? 'all' : +sizeSel.value; self.page = 0; self.render(); });
      pager.appendChild(sizeSel);
    }
    if (pages > 1) {
      var prev = button('Previous', { onClick: function () { self.page--; self.render(); } });
      var next = button('Next', { onClick: function () { self.page++; self.render(); } });
      prev.disabled = this.page === 0;
      next.disabled = this.page >= pages - 1;
      pager.appendChild(prev);
      pager.appendChild(el('<span class="et-dt-page">Page ' + (this.page + 1) + ' of ' + pages + '</span>'));
      pager.appendChild(next);
    }
  };
  DataTable.prototype.updateSel = function () {
    if (!this.selBtn) { return; }
    var n = Object.keys(this.selected).length;
    this.selBtn.disabled = !n;
    this.selBtn.textContent = n ? 'Add ' + fmtInt(n) + ' to basket' : 'Add to basket';
  };
  DataTable.prototype.exportTsv = function () {
    var self = this;
    var rows = this.view();
    var cols = this.columns.filter(function (c) { return !c.action; });
    var header = cols.map(function (c) { return c.exportLabel || String(c.label).replace(/<[^>]+>/g, ''); });
    var data = rows.map(function (r) {
      return cols.map(function (c) { return c.export ? c.export(r) : self.val(c, r); });
    });
    var notes = ['MaizeGDB Expression Tools, ' + new Date().toISOString().slice(0, 10), 'genome: ' + (state.genome ? state.genome.genome : '')].concat(this.notes);
    downloadTable(this.exportName, header, data, notes);
  };
  ET.DataTable = DataTable;

  /* ------------------------------------------------------------------------
     Charts. Plotly, drawn with Plotly.react so a view can redraw in place;
     the base layout is the site's (MGDB.mergeLayout), the legend sits above
     the plot as MGDB.chart places it, and every figure gets a toolbar to
     download PNG, SVG and the data behind it.
     ------------------------------------------------------------------------ */

  function baseLayout(over) {
    var layout = MGDB.mergeLayout ? MGDB.mergeLayout({}) : {};
    layout.font = { family: 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif', size: 12, color: ET.INK };
    layout.margin = { l: 64, r: 16, t: 12, b: 48 };
    layout.hoverlabel = { bgcolor: '#ffffff', bordercolor: ET.LINE, font: { color: ET.INK, size: 12 }, align: 'left' };
    layout.xaxis = Object.assign({}, layout.xaxis || {}, { gridcolor: ET.GRID, zerolinecolor: ET.LINE, linecolor: ET.LINE, automargin: true });
    layout.yaxis = Object.assign({}, layout.yaxis || {}, { gridcolor: ET.GRID, zerolinecolor: ET.LINE, linecolor: ET.LINE, automargin: true });
    layout.bargap = 0.2;
    return deepMerge(layout, over || {});
  }
  function deepMerge(a, b) {
    var out = Array.isArray(a) ? a.slice() : Object.assign({}, a);
    Object.keys(b || {}).forEach(function (k) {
      var v = b[k];
      out[k] = (v && typeof v === 'object' && !Array.isArray(v) && a[k] && typeof a[k] === 'object' && !Array.isArray(a[k])) ? deepMerge(a[k], v) : v;
    });
    return out;
  }

  /* Draw (or redraw) a figure. A legend, when there is one, goes above the
     plot with a band reserved for it; opts.height sets the element and the
     layout from one number. */
  function plot(node, traces, layout, opts) {
    opts = opts || {};
    var lay = baseLayout(layout);
    var height = opts.height || 360;
    node.style.height = height + 'px';
    lay.height = height;
    var legend = lay.showlegend !== false && (lay.showlegend === true || traces.filter(function (t) { return t.showlegend !== false; }).length > 1);
    if (legend && !opts.legendManual) {
      lay.legend = Object.assign({}, lay.legend || {}, { orientation: 'h', x: 0, xanchor: 'left', y: 1, yanchor: 'bottom', font: { size: 12 } });
      lay.margin = Object.assign({}, lay.margin, { t: Math.max((lay.margin && lay.margin.t) || 0, opts.legendBand || 34) });
    } else if (!legend) {
      lay.showlegend = false;
    }
    /* Plotly is fetched with the first figure rather than with the page;
       every figure after that gets the copy already loaded. */
    return MGDB.loadPlotly().then(function (Plotly) {
      if (!node._fullLayout) { node.innerHTML = ''; }
      return Plotly.react(node, traces, lay, {
        responsive: true, displaylogo: false, displayModeBar: false, scrollZoom: false,
        toImageButtonOptions: { filename: safeName(opts.filename || 'figure'), scale: 2 }
      }).then(function () {
        var svg = qs('.main-svg', node);
        if (svg) { svg.setAttribute('aria-hidden', 'true'); }
        return node;
      });
    }, function () {
      node.innerHTML = '';
      node.appendChild(message('The chart library did not load. The values are in the table view.', 'error'));
    });
  }

  /* Empty a plot node for a message or a fresh start. A node Plotly has
     drawn keeps its layout: wiping its HTML without purging leaves the next
     react() redrawing into a DOM that is gone. */
  function clear(node) {
    if (window.Plotly && node._fullLayout) { try { window.Plotly.purge(node); } catch (e) { /* already gone */ } }
    node.innerHTML = '';
  }

  /* A figure: the plot, a toolbar (PNG, SVG, table view), and the table view
     itself, which is the figure's values as a table -- the way to read every
     number without a pointer. getTable() -> {header, rows, notes}. */
  function figure(opts) {
    /* The legend slot sits between the tools and the plot: the pattern
       library puts a figure's key above what it explains. */
    var f = el('<figure class="et-figure"><div class="et-figure-tools"></div><div class="et-figure-legend"></div><div class="et-plot" role="img"></div>' +
      '<figcaption class="et-caption"></figcaption><div class="et-figure-table" hidden></div></figure>');
    var node = qs('.et-plot', f);
    if (opts.label) { node.setAttribute('aria-label', opts.label); }
    var tools = qs('.et-figure-tools', f);
    var tableHost = qs('.et-figure-table', f);
    var name = function () { return safeName(typeof opts.filename === 'function' ? opts.filename() : (opts.filename || 'figure')); };
    if (opts.controls) { (Array.isArray(opts.controls) ? opts.controls : [opts.controls]).forEach(function (c) { tools.appendChild(c); }); }
    tools.appendChild(el('<span class="et-spacer"></span>'));
    var tableBtn = button('Table', { title: 'Show the values behind this figure' });
    tableBtn.setAttribute('aria-expanded', 'false');
    tableBtn.addEventListener('click', function () {
      var open = tableHost.hidden;
      tableHost.hidden = !open;
      tableBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { f.renderTable(); }
    });
    if (opts.getTable) { tools.appendChild(tableBtn); }
    ['PNG', 'SVG'].forEach(function (kind) {
      tools.appendChild(button(kind, { title: 'Download the figure as ' + kind, onClick: function () {
        if (!window.Plotly || !node._fullLayout) { return; }
        /* The key of a figure is HTML above the plot; the file gets Plotly's
           own legend under the plot for the download, then loses it again. */
        var fl = node._fullLayout;
        var named = (node.data || []).filter(function (t) { return t.showlegend !== false && t.name; }).length;
        var addKey = !fl.showlegend && named > 1;
        var bottom = fl.margin ? fl.margin.b : 40;
        var extra = addKey ? 30 + 22 * Math.ceil(named / 5) : 0;
        var before = addKey
          ? window.Plotly.relayout(node, { showlegend: true, legend: { orientation: 'h', x: 0, xanchor: 'left', y: -0.14 * (fl.height ? 380 / fl.height : 1), yanchor: 'top', font: { size: 11 } }, 'margin.b': bottom + extra })
          : Promise.resolve();
        before.then(function () {
          return window.Plotly.downloadImage(node, { format: kind.toLowerCase(), filename: name(), scale: kind === 'PNG' ? 2 : 1,
                                                     width: node.clientWidth, height: node.clientHeight + extra });
        }).then(function () {
          if (addKey) { return window.Plotly.relayout(node, { showlegend: false, 'margin.b': bottom }); }
        }, function () {
          if (addKey) { window.Plotly.relayout(node, { showlegend: false, 'margin.b': bottom }); }
        });
      } }));
    });
    f.plotNode = node;
    f.legendHost = qs('.et-figure-legend', f);
    f.caption = qs('.et-caption', f);
    f.renderTable = function () {
      if (tableHost.hidden || !opts.getTable) { return; }
      var t = opts.getTable();
      tableHost.innerHTML = '';
      if (!t) { return; }
      var columns = t.header.map(function (h, i) {
        return { key: 'c' + i, label: esc(h), exportLabel: h, type: t.numeric && t.numeric[i] ? 'num' : 'str', value: function (r) { return r[i]; } };
      });
      new DataTable(tableHost, { columns: columns, rows: t.rows, exportName: name() + '_values', notes: t.notes || [], rowKey: 0,
                                 pageSize: 10, caption: opts.label || name() });
    };
    return f;
  }

  function tissueColor(t) { return ET.TISSUE_COLORS[t] || ET.OTHER; }
  function conditionColor(c) { return ET.CONDITION_COLORS[c] || ET.OTHER; }
  function seriesColor(i) { return i < ET.SERIES.length ? ET.SERIES[i] : ET.OTHER; }
  function seqScale() { return ET.SEQ.map(function (c, i) { return [i / (ET.SEQ.length - 1), c]; }); }
  function divScale() { return ET.DIV.map(function (c, i) { return [i / (ET.DIV.length - 1), c]; }); }
  function seqColor(t) {
    if (!isFinite(t)) { return ET.OTHER; }
    t = Math.max(0, Math.min(1, t));
    var s = ET.SEQ, x = t * (s.length - 1), i = Math.min(s.length - 2, Math.floor(x)), f = x - i;
    var a = hexRgb(s[i]), b = hexRgb(s[i + 1]);
    return 'rgb(' + [0, 1, 2].map(function (k) { return Math.round(a[k] + (b[k] - a[k]) * f); }).join(',') + ')';
  }
  function hexRgb(h) {
    h = h.replace('#', '');
    return [0, 2, 4].map(function (i) { return parseInt(h.slice(i, i + 2), 16); });
  }

  /* A legend of swatches, as HTML beside a figure, for the tissues (or
     conditions) actually on it; each is a toggle when onToggle is given. */
  function legend(kind, present, hidden, onToggle) {
    var order = kind === 'condition' ? ET.CONDITIONS : ET.TISSUES;
    var color = kind === 'condition' ? conditionColor : tissueColor;
    var w = el('<ul class="et-legend" aria-label="' + (kind === 'condition' ? 'Stress conditions' : 'Tissues') + '"></ul>');
    order.forEach(function (t) {
      if (present && !present[t]) { return; }
      var li = el('<li></li>');
      var inner = '<span class="et-swatch" style="background:' + color(t) + '"></span>' + esc(t) + (present && present[t] > 0 ? ' <span class="et-muted">' + present[t] + '</span>' : '');
      if (onToggle) {
        var b = el('<button type="button" class="et-legend-btn" aria-pressed="' + (hidden && hidden[t] ? 'false' : 'true') + '">' + inner + '</button>');
        b.addEventListener('click', function () { onToggle(t); });
        li.appendChild(b);
      } else {
        li.innerHTML = inner;
      }
      w.appendChild(li);
    });
    return w;
  }

  ET.chart = {
    plot: plot, clear: clear, figure: figure, baseLayout: baseLayout, tissueColor: tissueColor, conditionColor: conditionColor,
    seriesColor: seriesColor, seqScale: seqScale, divScale: divScale, seqColor: seqColor, legend: legend
  };

  /* ------------------------------------------------------------------------
     Statistics for the small matrices a page holds (genome-wide passes run
     on the server).
     ------------------------------------------------------------------------ */

  var stats = ET.stats = {
    log2p1: function (v) { return Math.log(Math.max(0, v) + 1) / Math.LN2; },
    mean: function (a) { return a.length ? a.reduce(function (s, v) { return s + v; }, 0) / a.length : NaN; },
    median: function (a) {
      if (!a.length) { return NaN; }
      var s = a.slice().sort(function (x, y) { return x - y; }), m = s.length >> 1;
      return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
    },
    sd: function (a) {
      var m = stats.mean(a);
      return Math.sqrt(a.reduce(function (s, v) { return s + (v - m) * (v - m); }, 0) / a.length);
    },
    /* Over the pairs both have; NaN when undefined. */
    pearson: function (a, b) {
      var n = 0, sa = 0, sb = 0, saa = 0, sbb = 0, sab = 0;
      for (var i = 0; i < a.length; i++) {
        var x = a[i], y = b[i];
        if (x == null || y == null || !isFinite(x) || !isFinite(y)) { continue; }
        n++; sa += x; sb += y; saa += x * x; sbb += y * y; sab += x * y;
      }
      if (n < 3) { return NaN; }
      var den = Math.sqrt((n * saa - sa * sa) * (n * sbb - sb * sb));
      return den > 1e-12 ? (n * sab - sa * sb) / den : NaN;
    },
    ranks: function (a) {
      var idx = a.map(function (v, i) { return [v, i]; }).sort(function (x, y) { return x[0] - y[0]; });
      var r = new Array(a.length);
      for (var i = 0; i < idx.length;) {
        var j = i;
        while (j + 1 < idx.length && idx[j + 1][0] === idx[i][0]) { j++; }
        for (var t = i; t <= j; t++) { r[idx[t][1]] = (i + j) / 2 + 1; }
        i = j + 1;
      }
      return r;
    },
    spearman: function (a, b) {
      var x = [], y = [];
      for (var i = 0; i < a.length; i++) {
        if (a[i] == null || b[i] == null || !isFinite(a[i]) || !isFinite(b[i])) { continue; }
        x.push(a[i]); y.push(b[i]);
      }
      return stats.pearson(stats.ranks(x), stats.ranks(y));
    },
    linfit: function (x, y) {
      var n = x.length, mx = stats.mean(x), my = stats.mean(y), sxy = 0, sxx = 0;
      for (var i = 0; i < n; i++) { sxy += (x[i] - mx) * (y[i] - my); sxx += (x[i] - mx) * (x[i] - mx); }
      var b = sxx ? sxy / sxx : 0;
      return { a: my - b * mx, b: b };
    },
    /* Yanai's tissue specificity on log2(x + 1); null unless something
       reaches the detection threshold (1 for RNA), because at noise level it
       says the opposite of the truth. */
    tau: function (values, threshold) {
      var x = values.filter(function (v) { return v != null && isFinite(v); }).map(stats.log2p1);
      if (x.length < 2) { return null; }
      var mx = Math.max.apply(null, x);
      if (mx <= 0 || mx < stats.log2p1(threshold == null ? 1 : threshold)) { return null; }
      return x.reduce(function (s, v) { return s + (1 - v / mx); }, 0) / (x.length - 1);
    },
    zscoreRows: function (M) {
      return M.map(function (r) {
        var f = r.filter(isFinite), m = stats.mean(f), s = stats.sd(f);
        return r.map(function (v) { return isFinite(v) ? (s > 1e-12 ? (v - m) / s : 0) : NaN; });
      });
    },
    /* Percentile (0-100) of v among a sample's 101 quantiles. */
    percentile: function (v, q) {
      if (!q || !q.length || v == null || !isFinite(v)) { return NaN; }
      if (v <= q[0]) { return 0; }
      if (v >= q[100]) { return 100; }
      var lo = 0, hi = 100;
      while (hi - lo > 1) { var m = (lo + hi) >> 1; if (q[m] <= v) { lo = m; } else { hi = m; } }
      var span = q[hi] - q[lo];
      return lo + (span > 0 ? (v - q[lo]) / span : 0);
    },
    /* Average-linkage clustering on 1 - r; the leaf order. O(n^3), fine for
       the few hundred rows a heatmap holds. */
    hclustOrder: function (rows) {
      var n = rows.length;
      if (n < 3) { return rows.map(function (_, i) { return i; }); }
      var D = [];
      var i, j;
      for (i = 0; i < n; i++) { D.push(new Float64Array(n)); }
      for (i = 0; i < n; i++) {
        for (j = i + 1; j < n; j++) {
          var r = stats.pearson(rows[i], rows[j]);
          D[i][j] = D[j][i] = isFinite(r) ? 1 - r : 1;
        }
      }
      return stats.orderFromDistance(D);
    },
    /* Average-linkage leaf order from a symmetric distance matrix, which is
       consumed. */
    orderFromDistance: function (D) {
      var n = D.length;
      if (n < 3) { return D.map(function (_, i) { return i; }); }
      var clusters = D.map(function (_, k) { return { items: [k], size: 1 }; });
      var active = D.map(function (_, k) { return k; });
      while (active.length > 1) {
        var best = Infinity, bi = -1, bj = -1;
        for (var a = 0; a < active.length; a++) {
          for (var b = a + 1; b < active.length; b++) {
            var d = D[active[a]][active[b]];
            if (d < best) { best = d; bi = active[a]; bj = active[b]; }
          }
        }
        var ci = clusters[bi], cj = clusters[bj];
        active.forEach(function (k) {
          if (k === bi || k === bj) { return; }
          D[bi][k] = D[k][bi] = (D[bi][k] * ci.size + D[bj][k] * cj.size) / (ci.size + cj.size);
        });
        clusters[bi] = { items: ci.items.concat(cj.items), size: ci.size + cj.size };
        active = active.filter(function (k) { return k !== bj; });
      }
      return clusters[active[0]].items;
    },
    /* Correlations between the columns of a genes x samples matrix. */
    columnCorrelation: function (M) {
      var k = M.length ? M[0].length : 0;
      var cols = [];
      for (var j = 0; j < k; j++) { cols.push(M.map(function (r) { return r[j]; })); }
      var C = [];
      for (var i = 0; i < k; i++) { C.push(new Array(k)); C[i][i] = 1; }
      for (i = 0; i < k; i++) { for (j = i + 1; j < k; j++) { C[i][j] = C[j][i] = stats.pearson(cols[i], cols[j]); } }
      return C;
    },
    /* The first components of the samples (columns) of a genes x samples
       matrix: power iteration with deflation on the samples' covariance,
       which for a few hundred samples is milliseconds. */
    pca: function (M, nComp) {
      var g = M.length, k = g ? M[0].length : 0, i, j, t;
      var X = M.map(function (r) { var m = stats.mean(r); return r.map(function (v) { return v - m; }); });
      var G = [];
      for (i = 0; i < k; i++) { G.push(new Float64Array(k)); }
      X.forEach(function (r) {
        for (i = 0; i < k; i++) { var ri = r[i]; if (!ri) { continue; } for (j = i; j < k; j++) { G[i][j] += ri * r[j]; } }
      });
      for (i = 0; i < k; i++) { for (j = 0; j < i; j++) { G[i][j] = G[j][i]; } }
      var total = 0;
      for (i = 0; i < k; i++) { total += G[i][i]; }
      var vecs = [], vals = [];
      for (var c = 0; c < Math.min(nComp || 3, k); c++) {
        var v = new Float64Array(k);
        for (i = 0; i < k; i++) { v[i] = Math.sin(i * 7.13 + c * 3.1) + 1.5; }
        var lambda = 0;
        for (t = 0; t < 300; t++) {
          var w = new Float64Array(k);
          for (i = 0; i < k; i++) { var s = 0; for (j = 0; j < k; j++) { s += G[i][j] * v[j]; } w[i] = s; }
          vecs.forEach(function (u) {
            var d = 0; for (i = 0; i < k; i++) { d += w[i] * u[i]; }
            for (i = 0; i < k; i++) { w[i] -= d * u[i]; }
          });
          var norm = 0;
          for (i = 0; i < k; i++) { norm += w[i] * w[i]; }
          norm = Math.sqrt(norm);
          if (norm < 1e-12) { break; }
          var diff = 0;
          for (i = 0; i < k; i++) { var nv = w[i] / norm; diff += Math.abs(nv - v[i]); v[i] = nv; }
          lambda = norm;
          if (diff < 1e-9) { break; }
        }
        vecs.push(v);
        vals.push(lambda);
      }
      var scores = [];
      for (i = 0; i < k; i++) { scores.push(vecs.map(function (u, ci) { return u[i] * Math.sqrt(Math.max(0, vals[ci])); })); }
      return { scores: scores, explained: vals.map(function (l) { return total > 0 ? l / total : 0; }), genes: g };
    }
  };

  /* ------------------------------------------------------------------------
     Router: #<view>?g=<genome>&s=<selection>&<the view's own parameters>
     ------------------------------------------------------------------------ */

  var current = null;
  var renderSeq = 0;

  function parseHash() {
    var h = window.location.hash || '';
    var raw = h.replace(/^#/, '');
    var q = raw.indexOf('?');
    var id = decodeURIComponent(q < 0 ? raw : raw.slice(0, q)) || 'home';
    return { id: id, params: new URLSearchParams(q < 0 ? '' : raw.slice(q + 1)) };
  }

  function buildHash(id, obj) {
    var p = new URLSearchParams();
    Object.keys(obj).forEach(function (k) {
      var v = obj[k];
      if (v !== undefined && v !== null && v !== '') { p.set(k, String(v)); }
    });
    var s = p.toString();
    return '#' + id + (s ? '?' + s : '');
  }

  /* The hash a view would have with these parameters, the current genome and
     the current selection. */
  ET.href = function (id, params, opts) {
    opts = opts || {};
    var obj = { g: (params && params.g) || (state.genome ? state.genome.key : '') };
    if (obj.g === 'B73v5') { obj.g = ''; }
    if (opts.keepSelection !== false && state.genome && (!params || !params.g || params.g === state.genome.key)) {
      obj.s = SEL.toParam();
    }
    Object.keys(params || {}).forEach(function (k) { if (k !== 'g') { obj[k] = params[k]; } });
    return buildHash(id, obj);
  };

  ET.go = function (id, params, opts) {
    window.location.hash = ET.href(id, params, opts);
  };

  function makeCtx(view, params) {
    var offs = [];
    var controller = window.AbortController ? new AbortController() : null;
    var alive = true;
    var ctx = {
      view: view,
      params: params,
      signal: controller ? controller.signal : undefined,
      get: function (k, d) { var v = ctx.params.get(k); return v == null || v === '' ? (d === undefined ? '' : d) : v; },
      alive: function () { return alive; },
      on: function (evt, fn) { offs.push(bus.on(evt, fn)); },
      onDispose: function (fn) { offs.push(fn); },
      /* Merge parameters into the URL without redrawing the view. */
      set: function (obj) {
        var merged = {};
        ctx.params.forEach(function (v, k) { merged[k] = v; });
        Object.keys(obj).forEach(function (k) {
          var v = obj[k];
          if (v === undefined || v === null || v === '' || v === false) { delete merged[k]; } else { merged[k] = v; }
        });
        merged.g = state.genome.key === 'B73v5' ? '' : state.genome.key;
        merged.s = SEL.toParam();
        var next = buildHash(view.id, merged);
        ctx.params = new URLSearchParams(next.split('?')[1] || '');
        if (window.location.hash !== next) {
          if (window.history && window.history.replaceState) { window.history.replaceState(null, '', next); }
        }
      },
      dispose: function () {
        alive = false;
        if (controller) { controller.abort(); }
        offs.splice(0).forEach(function (f) { try { f(); } catch (e) { /* ignore */ } });
      }
    };
    return ctx;
  }

  function render() {
    var seq = ++renderSeq;
    var parsed = parseHash();
    var root = qs('#et-view');
    if (current && current.ctx) { current.ctx.dispose(); }
    qsa('.js-plotly-plot', root).forEach(function (n) { if (window.Plotly) { window.Plotly.purge(n); } });
    var want = parsed.params.get('g') || 'B73v5';
    var wantG = ET.genomeByKey(want);
    if (!(wantG && state.genome && state.catalog && state.genome.key === wantG.key)) {
      root.innerHTML = '';
      root.appendChild(loading('Opening ' + ((wantG || {}).short || 'the genome') + '…'));
    }
    /* Always through useGenome, so a route back to the loaded genome
       cancels a slower switch still in flight. */
    var ready = ET.useGenome(want);
    ready.then(function () {
      if (seq !== renderSeq) { return; }
      SEL.fromParam(parsed.params.get('s') || '');
      var view = ET.views.filter(function (v) { return v.id === parsed.id; })[0] || ET.views.filter(function (v) { return v.id === 'home'; })[0];
      var ctx = makeCtx(view, parsed.params);
      current = { view: view, ctx: ctx };
      bus.emit('route', view.id);
      document.title = (view.id === 'home' ? '' : view.title + ' | ') + 'Expression Tools | MaizeGDB';
      root.innerHTML = '';
      var head = el('<div class="et-view-head"><div><h2 class="et-view-title"></h2><p class="et-view-sub"></p></div><div class="et-view-actions"></div></div>');
      qs('.et-view-title', head).textContent = view.title;
      qs('.et-view-sub', head).innerHTML = view.summary || '';
      var share = button('Copy link', { title: 'Copy a link that opens this view as it is now' });
      share.addEventListener('click', function () {
        copyText(window.location.href).then(function () { toast('Link copied. It reopens this view with this genome and these samples.'); });
      });
      qs('.et-view-actions', head).appendChild(share);
      root.appendChild(head);
      var reason = view.requires ? view.requires(state.genome, state.catalog) : null;
      if (reason) {
        root.appendChild(unavailable(view, reason));
        return;
      }
      if (view.selection) {
        root.appendChild(selectionBar(ctx));
        ctx.on('selection', function () { ctx.set({}); });
      }
      var main = el('<div class="et-view-body"></div>');
      root.appendChild(main);
      try {
        var r = view.render(main, ctx);
        if (r && r.catch) { r.catch(function (e) { if (ctx.alive()) { main.appendChild(errorBox(e)); } }); }
      } catch (e) {
        main.appendChild(errorBox(e));
      }
      if (focusAfterRender) {
        focusAfterRender = false;
        var t = qs('.et-view-title', root);
        if (t) { t.setAttribute('tabindex', '-1'); t.focus({ preventScroll: true }); }
      }
    }, function (e) {
      root.innerHTML = '';
      root.appendChild(errorBox(e));
    });
  }
  var focusAfterRender = false;

  function unavailable(view, reason) {
    var box = el('<div class="et-panel"><div class="et-panel-body"></div></div>');
    var b = body(box);
    b.appendChild(message('<strong>' + esc(view.title) + ' is not available for ' + esc(state.genome.short) + '.</strong> ' + reason, 'info'));
    var ok = state.genomes.filter(function (g) { return !view.requires(g, null); });
    if (ok.length) {
      var row = el('<div class="et-choices"><span>Open it for</span></div>');
      ok.slice(0, 6).forEach(function (g) {
        row.appendChild(el('<a class="mgdb-button mgdb-button-secondary mgdb-button-sm" href="' + esc(ET.href(view.id, { g: g.key }, { keepSelection: false })) + '">' + esc(g.short) + '</a>'));
      });
      b.appendChild(row);
    }
    return box;
  }

  /* ------------------------------------------------------------------------
     The selection bar and its picker
     ------------------------------------------------------------------------ */

  function selectionBar(ctx) {
    var bar = el('<div class="et-selbar" role="region" aria-label="Sample selection"></div>');
    function draw() {
      var opts = SEL.options();
      var groups = [];
      opts.forEach(function (o) { if (groups.indexOf(o.group) === -1) { groups.push(o.group); } });
      bar.innerHTML = '';
      var sel = el('<select class="et-select" aria-label="Samples"></select>');
      if (SEL.id() === 'custom') { sel.appendChild(new Option(SEL.label(), 'custom')); }
      groups.forEach(function (g) {
        var og = document.createElement('optgroup');
        og.label = g;
        opts.filter(function (o) { return o.group === g; }).forEach(function (o) {
          og.appendChild(new Option(o.label + ' (' + o.ids.length + ')', o.id));
        });
        sel.appendChild(og);
      });
      sel.value = SEL.id();
      sel.addEventListener('change', function () { if (sel.value !== 'custom') { SEL.apply(sel.value); } });
      bar.appendChild(el('<span class="et-selbar-label">Samples</span>'));
      bar.appendChild(sel);
      var usable = SEL.usable().length;
      bar.appendChild(el('<span class="et-selbar-count"><strong>' + fmtInt(SEL.size()) + '</strong> of ' + fmtInt(usable) + '</span>'));
      bar.appendChild(el('<span class="et-spacer"></span>'));
      bar.appendChild(button('Choose samples', { onClick: function () { openPicker(); } }));
      if (SEL.id() === 'custom') {
        bar.appendChild(button('Save this set', { onClick: function () {
          promptText('Save the selection', 'A name for these ' + SEL.size() + ' samples', '').then(function (name) {
            if (name) { SEL.save(name); toast('Saved "' + name + '"'); }
          });
        } }));
      }
      if (SEL.id().indexOf('saved:') === 0) {
        var sid = SEL.id();
        bar.appendChild(button('Forget this set', { onClick: function () { SEL.removeSaved(sid); } }));
      }
      if (!SEL.isDefault()) {
        bar.appendChild(button('All samples', { onClick: function () { SEL.apply('all'); } }));
      }
    }
    ctx.on('selection', draw);
    draw();
    return bar;
  }

  /* Samples by study, tissue and condition, with a search; edits a draft and
     applies it once, so a genome-wide analysis is not rerun per checkbox. */
  function openPicker() {
    var samples = ET.samples('rna');
    var draft = {};
    SEL.ids().forEach(function (i) { draft[i] = true; });
    var facets = { study: {}, tissue: {}, condition: {} };
    var q = '';
    var wrap = el('<div class="et-picker"><aside class="et-picker-facets"></aside><div class="et-picker-main">' +
      '<div class="et-picker-tools"><input type="search" class="et-input" placeholder="Search samples and studies" aria-label="Search samples"></div>' +
      '<div class="mgdb-table-scroll et-picker-table"><table class="mgdb-table et-table"><caption class="mgdb-visually-hidden">Samples</caption>' +
      '<thead><tr><th scope="col" class="et-sel"><span class="mgdb-visually-hidden">Selected</span></th><th scope="col">Sample</th><th scope="col">Study</th><th scope="col">Tissue</th><th scope="col">Condition</th><th scope="col" class="mgdb-numeric">Genes ≥ 1</th></tr></thead><tbody></tbody></table></div></div></div>');
    var tools = qs('.et-picker-tools', wrap);
    [['Select shown', 'add'], ['Clear shown', 'drop'], ['Only shown', 'only'], ['Invert', 'invert'], ['All', 'all'], ['None', 'none']].forEach(function (a) {
      var b = button(a[0]);
      b.setAttribute('data-a', a[1]);
      tools.appendChild(b);
    });
    function pass(s) {
      var st = facets.study, ti = facets.tissue, co = facets.condition;
      if (Object.keys(st).length && !st[s.study]) { return false; }
      if (Object.keys(ti).length && !ti[s.tissue]) { return false; }
      if (Object.keys(co).length && !co[s.condition || 'none']) { return false; }
      if (q) {
        var hay = (s.label + ' ' + s.studyName + ' ' + s.tissue + ' ' + (s.condition || '')).toLowerCase();
        if (hay.indexOf(q) === -1) { return false; }
      }
      return true;
    }
    function facetBlock(title, key, entries) {
      var sec = el('<fieldset class="et-facet"><legend></legend></fieldset>');
      qs('legend', sec).textContent = title;
      entries.forEach(function (e) {
        var lab = el('<label class="et-check"><input type="checkbox"><span></span><em></em></label>');
        qs('span', lab).textContent = e.label;
        qs('em', lab).textContent = e.n;
        var cb = qs('input', lab);
        cb.checked = !!facets[key][e.value];
        cb.addEventListener('change', function () {
          if (cb.checked) { facets[key][e.value] = true; } else { delete facets[key][e.value]; }
          rows();
        });
        sec.appendChild(lab);
      });
      return sec;
    }
    var count = function (fn) {
      var m = {};
      samples.forEach(function (s) { var k = fn(s); m[k] = (m[k] || 0) + 1; });
      return m;
    };
    var fa = qs('.et-picker-facets', wrap);
    var sc = count(function (s) { return s.study; });
    fa.appendChild(facetBlock('Study', 'study', state.catalog.studies.filter(function (st) { return sc[st.id]; }).map(function (st) {
      return { value: st.id, label: shortStudy(st.name), n: sc[st.id] };
    })));
    var tc = count(function (s) { return s.tissue; });
    fa.appendChild(facetBlock('Tissue', 'tissue', ET.TISSUES.filter(function (t) { return tc[t]; }).map(function (t) { return { value: t, label: t, n: tc[t] }; })));
    var cc = count(function (s) { return s.condition || 'none'; });
    if (Object.keys(cc).length > 1) {
      fa.appendChild(facetBlock('Condition', 'condition', ET.CONDITIONS.concat(['none']).filter(function (c) { return cc[c]; }).map(function (c) {
        return { value: c, label: c === 'none' ? 'not a stress study' : c, n: cc[c] };
      })));
    }
    var tbody = qs('tbody', wrap);
    var status = el('<span class="et-picker-count" aria-live="polite"></span>');
    function rows() {
      var vis = samples.filter(pass);
      tbody.innerHTML = vis.map(function (s) {
        return '<tr' + (s.usable ? '' : ' class="is-unusable"') + '><td class="et-sel"><input type="checkbox" data-id="' + s.id + '"' + (draft[s.id] ? ' checked' : '') +
          (s.usable ? '' : ' disabled') + ' aria-label="' + esc(s.label) + '"></td><td>' + esc(s.label) + (s.usable ? '' : ' <span class="mgdb-pill mgdb-pill-warn">not measured</span>') +
          '</td><td>' + esc(shortStudy(s.studyName)) + '</td><td><span class="et-swatch" style="background:' + tissueColor(s.tissue) + '"></span>' + esc(s.tissue) +
          '</td><td>' + esc(s.condition || '—') + '</td><td class="mgdb-numeric">' + fmtInt(s.ge1) + '</td></tr>';
      }).join('') || '<tr><td colspan="6" class="et-dt-empty">No samples match.</td></tr>';
      qsa('input[data-id]', tbody).forEach(function (cb) {
        cb.addEventListener('change', function () {
          if (cb.checked) { draft[cb.getAttribute('data-id')] = true; } else { delete draft[cb.getAttribute('data-id')]; }
          tally(vis.length);
        });
      });
      tally(vis.length);
    }
    var shownCount = samples.length;
    function tally(shown) {
      if (shown != null) { shownCount = shown; }
      status.textContent = Object.keys(draft).length + ' of ' + samples.length + ' selected · ' + shownCount + ' shown';
      apply.disabled = !Object.keys(draft).length;
    }
    qs('input[type=search]', wrap).addEventListener('input', debounce(function (e) { q = e.target.value.trim().toLowerCase(); rows(); }, 120));
    tools.addEventListener('click', function (e) {
      var a = e.target.closest('[data-a]');
      if (!a) { return; }
      var vis = samples.filter(pass).filter(function (s) { return s.usable; }).map(function (s) { return s.id; });
      var act = a.getAttribute('data-a');
      if (act === 'add') { vis.forEach(function (i) { draft[i] = true; }); }
      if (act === 'drop') { vis.forEach(function (i) { delete draft[i]; }); }
      if (act === 'only') { draft = {}; vis.forEach(function (i) { draft[i] = true; }); }
      if (act === 'invert') { var cur = draft; draft = {}; samples.forEach(function (s) { if (s.usable && !cur[s.id]) { draft[s.id] = true; } }); }
      if (act === 'all') { samples.forEach(function (s) { if (s.usable) { draft[s.id] = true; } }); }
      if (act === 'none') { draft = {}; }
      rows();
    });
    var apply = button('Apply', { kind: 'mgdb-button-primary' });
    var m = modal({ title: 'Choose samples', body: wrap, wide: true, actions: [status, apply] });
    apply.addEventListener('click', function () {
      SEL.applyIds(Object.keys(draft).map(Number));
      m.close();
    });
    rows();
  }

  /* ------------------------------------------------------------------------
     The shell: genome picker, search, basket, the tool rail
     ------------------------------------------------------------------------ */

  function buildRail() {
    var rail = qs('#et-rail');
    rail.innerHTML = '';
    var nav = el('<nav class="et-rail-nav" aria-label="Expression tools"></nav>');
    ET.GROUPS.forEach(function (g) {
      var views = ET.views.filter(function (v) { return v.group === g && !v.hidden; });
      if (!views.length) { return; }
      var sec = el('<div class="et-rail-group"><h3></h3><ul></ul></div>');
      qs('h3', sec).textContent = g;
      var ul = qs('ul', sec);
      views.forEach(function (v) {
        var reason = v.requires && state.genome ? v.requires(state.genome, state.catalog) : null;
        var li = el('<li><a class="et-rail-link" data-view="' + esc(v.id) + '"></a></li>');
        var a = qs('a', li);
        a.textContent = v.nav || v.title;
        a.href = ET.href(v.id, {});
        if (reason) { a.classList.add('is-unavailable'); a.setAttribute('aria-describedby', 'et-rail-na'); }
        ul.appendChild(li);
      });
      nav.appendChild(sec);
    });
    var home = el('<a class="et-rail-home" data-view="home">Overview</a>');
    home.href = ET.href('home', {});
    rail.appendChild(home);
    rail.appendChild(nav);
    rail.appendChild(el('<p class="et-rail-note" id="et-rail-na">Tools in gray need data this genome does not have.</p>'));
    markRail(current ? current.view.id : parseHash().id);
    /* Every link re-derives its href from the state at click time, so the
       selection travels with the reader. */
    qsa('a[data-view]', rail).forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        focusAfterRender = true;
        var id = a.getAttribute('data-view');
        var keep = current && current.view.id === id ? paramsOf(current.ctx.params) : {};
        ET.go(id, keep);
      });
    });
  }
  function paramsOf(p) {
    var o = {};
    p.forEach(function (v, k) { if (k !== 'g' && k !== 's') { o[k] = v; } });
    return o;
  }
  function markRail(id) {
    var rail = qs('#et-rail');
    qsa('#et-rail a[data-view]').forEach(function (a) {
      var on = a.getAttribute('data-view') === id;
      a.classList.toggle('is-current', on);
      if (on) {
        a.setAttribute('aria-current', 'page');
        /* Below 960px the rail is one scrolling row; bring the current tool
           into it. scrollLeft, not scrollIntoView, which would also move
           the page. */
        if (rail && rail.scrollWidth > rail.clientWidth + 1) {
          var r = a.getBoundingClientRect(), box = rail.getBoundingClientRect();
          if (r.left < box.left || r.right > box.right) { rail.scrollLeft += (r.left - box.left) - (box.width - r.width) / 2; }
        }
      } else { a.removeAttribute('aria-current'); }
    });
  }

  function buildContext() {
    var bar = qs('#et-context');
    bar.innerHTML = '';
    var groups = [
      { group: 'B73 references', options: [] }, { group: 'NAM founders (23 shared samples)', options: [] }
    ];
    state.genomes.forEach(function (g) {
      var usable = g.samples_usable || g.samples;
      var label = g.short + ' — ' + fmtInt(usable.rna) + ' RNA samples' + (g.samples.protein ? ', ' + g.samples.protein + ' protein' : '');
      (g.key === 'B73v5' || g.key === 'B73v4' ? groups[0] : groups[1]).options.push({ value: g.key, label: label });
    });
    var gsel = select(groups, state.genome ? state.genome.key : 'B73v5', 'et-genome-select');
    gsel.addEventListener('change', function () {
      var id = current ? current.view.id : 'home';
      var params = current ? paramsOf(current.ctx.params) : {};
      /* Gene ids belong to one genome; a tool opened on another genome starts
         empty rather than looking up an id it cannot have. */
      ['id', 'gene', 'genes', 'g1', 'g2', 'chr', 'start', 'end', 'target', 'a', 'b'].forEach(function (k) { delete params[k]; });
      params.g = gsel.value;
      ET.go(id, params, { keepSelection: false });
    });
    bar.appendChild(field('Genome', gsel, null, 'et-field-genome'));

    var search = geneInput({ placeholder: 'Gene id, symbol, locus (chr5:1-500000) or a list', label: 'Find a gene, a region or a list', onPick: function (q) {
      var loc = parseLocus(q);
      var ids = parseGeneList(q);
      if (loc) { ET.go('region', { chr: loc.chr, start: loc.start, end: loc.end }); }
      else if (ids.length > 1) { ET.go('list', { genes: ids.slice(0, 500).join(',') }); }
      else if (ids.length) { ET.go('gene', { id: ids[0] }); }
      search.value = '';
    } });
    bar.appendChild(field('Find', search, null, 'et-field-search'));

    var basketBtn = button('Basket', { title: 'Genes you have collected, kept per genome in this browser' });
    basketBtn.classList.add('et-basket-btn');
    basketBtn.setAttribute('aria-controls', 'et-basket');
    basketBtn.setAttribute('aria-expanded', 'false');
    basketBtn.addEventListener('click', function () { toggleBasket(); });
    var holder = el('<div class="mgdb-hub-field mgdb-hub-field-action et-field-basket"></div>');
    holder.appendChild(basketBtn);
    bar.appendChild(holder);
    var paint = function () { basketBtn.innerHTML = 'Basket <span class="et-badge">' + ET.basket.list().length + '</span>'; };
    bus.on('basket', paint);
    paint();
    document.addEventListener('keydown', function (e) {
      if (e.key === '/' && !/input|textarea|select/i.test(document.activeElement.tagName)) { e.preventDefault(); search.input.focus(); }
    });
  }

  function buildBasket() {
    var d = qs('#et-basket');
    function draw() {
      var genes = ET.basket.list();
      d.innerHTML = '<div class="et-drawer-head"><h2>Gene basket <span class="et-badge">' + genes.length + '</span></h2></div>' +
        '<p class="et-muted">Kept in this browser for ' + esc(state.genome ? state.genome.short : '') + '. Every tool can use it.</p>' +
        '<div class="et-drawer-actions"></div><ul class="et-basket-list"></ul><div class="et-drawer-foot"></div>';
      var acts = qs('.et-drawer-actions', d);
      [['Plot', 'plot', 8], ['Heatmap', 'heatmap', 300], ['Table', 'list', 2000], ['GO enrichment', 'enrich', 3000]].forEach(function (a) {
        var b = button(a[0], { kind: 'mgdb-button-secondary', onClick: function () { toggleBasket(false); ET.go(a[1], { genes: ET.basket.list().slice(0, a[2]).join(',') }); } });
        b.disabled = !genes.length;
        acts.appendChild(b);
      });
      var ul = qs('ul', d);
      if (!genes.length) { ul.appendChild(el('<li class="et-muted">Empty. Add genes with the basket buttons in any table.</li>')); }
      genes.forEach(function (g) {
        var li = el('<li><a class="et-mono"></a><button type="button" class="mgdb-button mgdb-button-quiet mgdb-button-sm">Remove</button></li>');
        var a = qs('a', li);
        a.textContent = g;
        a.href = ET.href('gene', { id: g });
        a.addEventListener('click', function () { toggleBasket(false); });
        qs('button', li).setAttribute('aria-label', 'Remove ' + g);
        qs('button', li).addEventListener('click', function () { ET.basket.remove(g); });
        ul.appendChild(li);
      });
      var foot = qs('.et-drawer-foot', d);
      foot.appendChild(button('Copy ids', { onClick: function () { copyText(genes.join('\n')).then(function () { toast('Copied ' + plural(genes.length, 'gene id')); }); } }));
      foot.appendChild(button('Download', { onClick: function () { downloadText('maizegdb_gene_basket_' + state.genome.key + '.txt', genes.join('\n') + '\n'); } }));
      foot.appendChild(button('Empty the basket', { onClick: function () { ET.basket.clear(); } }));
      foot.appendChild(button('Close', { kind: 'mgdb-button-primary', onClick: function () { toggleBasket(false); } }));
    }
    bus.on('basket', draw);
    bus.on('genome', draw);
    draw();
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !d.hidden) { toggleBasket(false); } });
  }
  function toggleBasket(open) {
    var d = qs('#et-basket');
    var show = open == null ? d.hidden : open;
    d.hidden = !show;
    var btn = qs('.et-basket-btn');
    if (btn) { btn.setAttribute('aria-expanded', show ? 'true' : 'false'); }
    if (show) { var f = qs('button, a', d); if (f) { f.focus(); } } else if (btn) { btn.focus(); }
  }
  ET.toggleBasket = toggleBasket;

  /* A button that puts genes in the basket. */
  ET.basketButton = function (getGenes, label) {
    return button(label || 'Add to basket', { onClick: function () {
      var genes = typeof getGenes === 'function' ? getGenes() : getGenes;
      var n = ET.basket.add(genes);
      toast(n ? 'Added ' + plural(n, 'gene') + ' to the basket' : 'Already in the basket');
    } });
  };

  /* ------------------------------------------------------------------------
     Boot
     ------------------------------------------------------------------------ */

  function boot() {
    var app = qs('#et-app');
    if (!app) { return; }
    var bootEl = qs('#et-bootstrap');
    try { state.boot = bootEl ? JSON.parse(bootEl.textContent) : null; } catch (e) { state.boot = null; }
    var start = state.boot && state.boot.genomes ? Promise.resolve(state.boot) : api('genomes', {});
    start.then(function (d) {
      state.boot = d;
      state.genomes = d.genomes;
      app.classList.add('is-ready');
      buildContext();
      buildBasket();
      var first = parseHash();
      return ET.useGenome(first.params.get('g') || 'B73v5');
    }).then(function () {
      buildRail();
      bus.on('genome', function () { buildRail(); var gs = qs('.et-genome-select'); if (gs) { gs.value = state.genome.key; } });
      bus.on('route', function (id) { markRail(id); });
      bus.on('selection', function () {
        qsa('#et-rail a[data-view]').forEach(function (a) { a.href = ET.href(a.getAttribute('data-view'), {}); });
      });
      /* Every hash change is the reader's: a view writes its own state with
         replaceState, which fires no hashchange, so Back and Forward always
         render. */
      window.addEventListener('hashchange', function () { render(); });
      render();
    }).catch(function (e) {
      var root = qs('#et-view');
      root.innerHTML = '';
      root.appendChild(errorBox(e));
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window, document);
