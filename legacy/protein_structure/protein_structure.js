(function () {
  'use strict';

  var stages = {};
  var confidenceScheme = null;

  function setExample(id, value) {
    var input = document.getElementById(id);
    if (input) {
      input.value = value;
      input.focus();
    }
  }

  window.changeExample = setExample;

  function getConfidenceScheme() {
    if (confidenceScheme || typeof NGL === 'undefined') return confidenceScheme;
    confidenceScheme = NGL.ColormakerRegistry.addScheme(function () {
      this.atomColor = function (atom) {
        if (atom.bfactor < 50) return 0xf1a340;
        if (atom.bfactor < 70) return 0xf6e75a;
        if (atom.bfactor < 90) return 0x8cbdec;
        return 0x4056c7;
      };
    });
    return confidenceScheme;
  }

  function representationFor(style, color) {
    var params = { color: color };
    if (style === 'surface') {
      params.opacity = 0.78;
      params.surfaceType = 'av';
    }
    if (style === 'ball+stick') params.multipleBond = 'symmetric';
    return params;
  }

  function applyRepresentation(record) {
    if (!record.component) return;
    var shell = record.shell;
    var style = shell.querySelector('[data-viewer-style]').value;
    var colorMode = shell.querySelector('[data-viewer-color]').value;
    var color = colorMode === 'confidence' ? getConfidenceScheme() : colorMode;
    record.component.removeAllRepresentations();
    record.component.addRepresentation(style, representationFor(style, color));
    record.stage.viewer.requestRender();
  }

  function setViewerStatus(shell, message) {
    var status = shell.querySelector('[data-viewer-status]');
    if (status) status.textContent = message;
  }

  function downloadImage(record) {
    setViewerStatus(record.shell, 'Preparing image…');
    record.stage.makeImage({ factor: 2, antialias: true, trim: false, transparent: false })
      .then(function (blob) {
        NGL.download(blob, (record.name || 'maize-protein-structure') + '.png');
        setViewerStatus(record.shell, 'Drag to rotate · scroll to zoom');
      })
      .catch(function () { setViewerStatus(record.shell, 'Could not export image'); });
  }

  function bindToolbar(record) {
    var shell = record.shell;
    var style = shell.querySelector('[data-viewer-style]');
    var color = shell.querySelector('[data-viewer-color]');
    var spin = shell.querySelector('[data-viewer-spin]');
    var reset = shell.querySelector('[data-viewer-reset]');
    var image = shell.querySelector('[data-viewer-image]');
    var fullscreen = shell.querySelector('[data-viewer-fullscreen]');

    style.addEventListener('change', function () { applyRepresentation(record); });
    color.addEventListener('change', function () { applyRepresentation(record); });
    spin.addEventListener('click', function () {
      record.spinning = !record.spinning;
      record.stage.setSpin(record.spinning);
      spin.classList.toggle('is-active', record.spinning);
      spin.textContent = record.spinning ? 'Stop rotation' : 'Auto-rotate';
    });
    reset.addEventListener('click', function () { record.component.autoView(500); });
    image.addEventListener('click', function () { downloadImage(record); });
    fullscreen.addEventListener('click', function () {
      if (!document.fullscreenElement && shell.requestFullscreen) shell.requestFullscreen();
      else if (document.exitFullscreen) document.exitFullscreen();
    });
  }

  function initializeViewer(viewport) {
    if (viewport.dataset.viewerReady === 'true' || typeof NGL === 'undefined') return;
    var url = viewport.dataset.structureUrl;
    if (!url) return;

    viewport.dataset.viewerReady = 'true';
    var shell = viewport.closest('.ps-viewer-shell');
    var stage = new NGL.Stage(viewport.id, { backgroundColor: '#f9fbfa' });
    var record = { stage: stage, shell: shell, component: null, spinning: false, name: viewport.dataset.structureName || 'maize-protein-structure' };
    stages[viewport.id] = record;
    bindToolbar(record);
    setViewerStatus(shell, 'Loading structure…');

    stage.loadFile(url, { defaultRepresentation: false }).then(function (component) {
      record.component = component;
      applyRepresentation(record);
      component.autoView();
      setViewerStatus(shell, 'Drag to rotate · scroll to zoom');
    }).catch(function () {
      setViewerStatus(shell, 'Structure could not be loaded');
      viewport.innerHTML = '<div class="ps-error">The structure file is currently unavailable.</div>';
    });

    var resizeObserver = new ResizeObserver(function () { stage.handleResize(); });
    resizeObserver.observe(viewport);
  }

  function initializeStructureViewers(scope) {
    (scope || document).querySelectorAll('.protein-viewer[data-structure-url]').forEach(initializeViewer);
  }

  window.initializeStructureViewers = initializeStructureViewers;

  var complexSearchTimer = null;
  var complexRequest = null;
  var complexLookupData = null;
  var complexActiveIndex = -1;

  function complexEscape(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character];
    });
  }

  function complexNumber(value, digits) {
    var number = Number(value);
    return Number.isFinite(number) ? number.toFixed(digits == null ? 2 : digits) : 'NA';
  }

  function complexPartnerLabel(partner) {
    return partner.symbol || partner.gene || partner.uniprot || 'Unmapped protein';
  }

  function complexPartnerMeta(partner) {
    var identifiers = [];
    if (partner.gene && identifiers.indexOf(partner.gene) === -1) identifiers.push(partner.gene);
    if (partner.uniprot && identifiers.indexOf(partner.uniprot) === -1) identifiers.push(partner.uniprot);
    return identifiers.join(' · ');
  }

  function complexQuality(record) {
    if (record.display) return {label:'Display-ready', className:'is-high'};
    if (Number(record.metrics.ipsae || 0) >= .4 || Number(record.metrics.pdockq || 0) >= .23) return {label:'Supporting evidence', className:'is-medium'};
    return {label:'Interpret cautiously', className:'is-low'};
  }

  function complexCard(record, index) {
    if (record.type === 'monomer') return monomerCard(record, index);
    var quality = complexQuality(record);
    var partners = record.partners.map(function (partner) { return complexPartnerLabel(partner); }).join(' + ');
    var descriptions = record.partners.map(function (partner) { return partner.description; }).filter(Boolean).join(' · ');
    return '<button type="button" class="ps-complex-candidate' + (index === 0 ? ' is-selected' : '') + '" data-complex-model="' + complexEscape(record.id) + '">' +
      '<span class="ps-complex-candidate-top"><b>' + complexEscape(partners) + '</b><i class="' + quality.className + '">' + quality.label + '</i></span>' +
      '<small>' + complexEscape(descriptions || record.id) + '</small>' +
      '<span class="ps-complex-candidate-metrics"><em>ipTM <b>' + complexNumber(record.metrics.iptm) + '</b></em><em>ipSAE <b>' + complexNumber(record.metrics.ipsae) + '</b></em><em>contacts <b>' + complexEscape(record.metrics.interactions == null ? 'NA' : record.metrics.interactions) + '</b></em></span>' +
      '</button>';
  }

  function monomerCard(record, index) {
    var partner = record.partners[0] || {};
    var confidence = Number(record.metrics.plddt || 0);
    var quality = confidence >= 90 ? 'Very high confidence' : (confidence >= 70 ? 'Confident' : (confidence >= 50 ? 'Low confidence' : 'Very low confidence'));
    var qualityClass = confidence >= 70 ? 'is-high' : (confidence >= 50 ? 'is-medium' : 'is-low');
    return '<button type="button" class="ps-complex-candidate' + (index === 0 ? ' is-selected' : '') + '" data-complex-model="' + complexEscape(record.id) + '">' +
      '<span class="ps-complex-candidate-top"><b>' + complexEscape(complexPartnerLabel(partner)) + '</b><i class="' + qualityClass + '">' + quality + '</i></span>' +
      '<small>' + complexEscape(partner.description || record.id) + '</small>' +
      '<span class="ps-complex-candidate-metrics"><em>pLDDT <b>' + complexNumber(record.metrics.plddt) + '</b></em><em>length <b>' + complexEscape(record.metrics.sequence_length || 'NA') + ' aa</b></em><em>' + (record.reviewed ? 'reviewed UniProt' : 'UniProtKB') + '</em></span></button>';
  }

  function complexAvailability(data) {
    var entries = [
      {label:'Single protein', available:(data.monomers || []).length > 0, count:(data.monomers || []).length, key:'monomer'},
      {label:'Homodimer', available:data.homodimers.length > 0, count:data.homodimers.length, key:'homodimer'},
      {label:'Heterodimer', available:data.heterodimers.length > 0, count:data.heterodimers.length, key:'heterodimer'}
    ];
    return entries.map(function (entry) {
      return '<div class="ps-complex-availability ' + (entry.available ? 'is-available' : 'is-unavailable') + '"><span aria-hidden="true">' + (entry.available ? '✓' : '—') + '</span><div><b>' + entry.label + '</b><small>' + (entry.available ? entry.count + (entry.count === 1 ? ' model' : ' models') : 'not indexed') + '</small></div></div>';
    }).join('');
  }

  function complexIdentity(data) {
    var identity = data.identity || {};
    var label = identity.label || data.query;
    var related = [].concat(identity.gene_ids || [], identity.symbols || [], identity.uniprots || []).filter(function (value, index, values) { return value && values.indexOf(value) === index; });
    return '<div class="ps-complex-identity"><div><span class="ps-eyebrow">Matched protein</span><h3>' + complexEscape(label) + '</h3><p>' + complexEscape(related.join(' · ') || 'Indexed AlphaFold complex participant') + '</p></div>' +
      '<a href="/gene_center/gene/' + encodeURIComponent((identity.gene_ids || [])[0] || label) + '">Open gene record →</a></div>';
  }

  function complexViewerShell(record) {
    var viewportId = 'viewport_complex_' + record.id.replace(/[^A-Za-z0-9]/g, '_');
    return '<div class="ps-complex-viewer-card"><div class="ps-viewer-shell">' +
      '<div class="ps-viewer-toolbar" aria-label="Complex structure viewer controls"><select class="ps-viewer-select" data-viewer-style aria-label="Molecular style"><option value="cartoon">Cartoon</option><option value="surface">Surface</option><option value="ball+stick">Ball and stick</option><option value="backbone">Backbone</option></select><select class="ps-viewer-select" data-viewer-color aria-label="Color scheme"><option value="chainname" selected>Color: chain</option><option value="confidence">Color: confidence</option><option value="element">Color: element</option></select><button class="ps-viewer-button" type="button" data-viewer-spin>Auto-rotate</button><button class="ps-viewer-button" type="button" data-viewer-reset>Reset view</button><span class="ps-viewer-spacer"></span><button class="ps-viewer-button" type="button" data-viewer-image>Save PNG</button><a class="ps-viewer-button" data-viewer-download href="' + complexEscape(record.pdb) + '" download>Download PDB</a><button class="ps-viewer-button" type="button" data-viewer-fullscreen>Fullscreen</button></div>' +
      '<div id="' + viewportId + '" class="protein-viewer ps-complex-viewer" data-structure-url="' + complexEscape(record.pdb) + '" data-structure-name="' + complexEscape(record.id) + '"></div><div class="ps-viewer-status" data-viewer-status>Loading complex…</div></div></div>';
  }

  function complexPaePanel(record) {
    var paeJson = record.pae_json || record.pae.replace(/\.png(?:\?.*)?$/i, '.json');
    return '<div class="ps-complex-pae-card"><div class="ps-complex-panel-heading"><span class="ps-eyebrow">Aligned error</span><h4>Predicted aligned error</h4><p>Lower-error blocks indicate regions whose relative positions are predicted more confidently.</p></div><div class="ps-complex-pae-plot"><canvas data-pae-url="' + complexEscape(paeJson) + '" role="img" aria-label="Predicted aligned error heatmap for ' + complexEscape(record.id) + '"></canvas><span data-pae-status>Loading aligned-error matrix…</span><div class="ps-complex-pae-scale"><i></i><small>0 Å</small><small>31 Å+</small></div></div></div>';
  }

  function renderPaeCanvas(canvas) {
    var status = canvas.parentElement.querySelector('[data-pae-status]');
    fetch(canvas.dataset.paeUrl, {mode:'cors'}).then(function (response) {
      if (!response.ok) throw new Error('pae');
      return response.json();
    }).then(function (payload) {
      var data = Array.isArray(payload) ? payload[0] : payload;
      var matrix = data.predicted_aligned_error || [];
      if (!matrix.length) throw new Error('pae');
      var size = matrix.length;
      var maximum = Number(data.max_predicted_aligned_error) || 31;
      canvas.width = size;
      canvas.height = size;
      var context = canvas.getContext('2d');
      var image = context.createImageData(size, size);
      for (var row = 0; row < size; row++) {
        for (var column = 0; column < size; column++) {
          var confidence = 1 - Math.min(maximum, Number(matrix[row][column]) || 0) / maximum;
          var offset = (row * size + column) * 4;
          image.data[offset] = Math.round(250 - confidence * 205);
          image.data[offset + 1] = Math.round(249 - confidence * 104);
          image.data[offset + 2] = Math.round(242 - confidence * 207);
          image.data[offset + 3] = 255;
        }
      }
      context.putImageData(image, 0, 0);
      (data.chains || []).slice(0, -1).forEach(function (chain) {
        var boundary = Number(chain.sequenceEnd);
        context.strokeStyle = 'rgba(20,52,37,.72)';
        context.lineWidth = Math.max(1, size / 500);
        context.beginPath(); context.moveTo(boundary, 0); context.lineTo(boundary, size); context.stroke();
        context.beginPath(); context.moveTo(0, boundary); context.lineTo(size, boundary); context.stroke();
      });
      if (status) status.textContent = 'Residue × residue · chain boundaries outlined';
    }).catch(function () {
      if (status) status.textContent = 'Aligned-error matrix is currently unavailable';
      canvas.hidden = true;
    });
  }

  function complexMetrics(record) {
    if (record.type === 'monomer') return monomerMetrics(record);
    var partnerHtml = record.partners.map(function (partner, index) {
      var color = index === 0 ? 'A' : 'B';
      return '<article><span class="ps-complex-chain ps-complex-chain-' + color.toLowerCase() + '">Chain ' + color + '</span><h4>' + complexEscape(complexPartnerLabel(partner)) + '</h4><p>' + complexEscape(partner.description || 'Description not reported') + '</p><small>' + complexEscape(complexPartnerMeta(partner) || 'No mapped identifier') + '</small></article>';
    }).join('');
    return '<div class="ps-complex-model-details"><div class="ps-complex-model-title"><div><span class="ps-model-badge">' + complexEscape(record.type === 'homodimer' ? 'Homodimer' : 'Heterodimer') + '</span><h3>' + complexEscape(record.partners.map(complexPartnerLabel).join(' + ')) + '</h3><p>' + complexEscape(record.id) + ' · ' + complexEscape(record.tool) + '</p></div><a href="' + complexEscape(record.cif) + '" target="_blank" rel="noopener">Download mmCIF ↗</a></div>' +
      '<div class="ps-complex-metric-grid"><div><b>ipTM</b><strong>' + complexNumber(record.metrics.iptm) + '</strong><span>complex confidence</span></div><div><b>ipSAE</b><strong>' + complexNumber(record.metrics.ipsae) + '</strong><span>interface alignment</span></div><div><b>pDockQ</b><strong>' + complexNumber(record.metrics.pdockq) + '</strong><span>interface quality</span></div><div><b>Contacts</b><strong>' + complexEscape(record.metrics.interactions == null ? 'NA' : record.metrics.interactions) + '</strong><span>predicted interactions</span></div></div>' +
      '<div class="ps-complex-partners">' + partnerHtml + '</div></div>';
  }

  function monomerMetrics(record) {
    var partner = record.partners[0] || {};
    var highFraction = Number(record.metrics.confident || 0) + Number(record.metrics.very_high || 0);
    return '<div class="ps-complex-model-details"><div class="ps-complex-model-title"><div><span class="ps-model-badge">AlphaFold monomer</span><h3>' + complexEscape(complexPartnerLabel(partner)) + '</h3><p>' + complexEscape(record.id) + ' · ' + complexEscape(record.tool) + '</p></div><a href="' + complexEscape(record.entry) + '" target="_blank" rel="noopener">Open AlphaFold entry ↗</a></div>' +
      '<div class="ps-complex-metric-grid"><div><b>pLDDT</b><strong>' + complexNumber(record.metrics.plddt) + '</strong><span>mean confidence</span></div><div><b>Length</b><strong>' + complexEscape(record.metrics.sequence_length || 'NA') + '</strong><span>amino acids</span></div><div><b>Confident</b><strong>' + (Number.isFinite(highFraction) ? Math.round(highFraction * 100) + '%' : 'NA') + '</strong><span>residues ≥70 pLDDT</span></div><div><b>UniProt</b><strong>' + complexEscape(partner.uniprot || 'NA') + '</strong><span>' + (record.reviewed ? 'reviewed accession' : 'unreviewed accession') + '</span></div></div>' +
      '<div class="ps-complex-partners"><article><span class="ps-complex-chain ps-complex-chain-a">Chain A</span><h4>' + complexEscape(complexPartnerLabel(partner)) + '</h4><p>' + complexEscape(partner.description || 'Description not reported') + '</p><small>' + complexEscape(complexPartnerMeta(partner) || 'No mapped identifier') + '</small></article></div></div>';
  }

  function displayComplexModel(record) {
    var detail = document.getElementById('ps-complex-model-detail');
    if (!detail || !record) return;
    Object.keys(stages).forEach(function (viewportId) {
      if (viewportId.indexOf('viewport_complex_') !== 0) return;
      if (stages[viewportId].stage && typeof stages[viewportId].stage.dispose === 'function') stages[viewportId].stage.dispose();
      delete stages[viewportId];
    });
    detail.innerHTML = complexMetrics(record) + '<div class="ps-complex-visual-grid">' + complexViewerShell(record) + complexPaePanel(record) + '</div>';
    initializeStructureViewers(detail);
    detail.querySelectorAll('canvas[data-pae-url]').forEach(renderPaeCanvas);
    document.querySelectorAll('[data-complex-model]').forEach(function (button) { button.classList.toggle('is-selected', button.dataset.complexModel === record.id); });
  }

  function renderComplexResults(data) {
    var target = document.getElementById('ps-complex-results');
    complexLookupData = data;
    if (!data.found) {
      target.innerHTML = '<div class="ps-complex-empty"><strong>No indexed complex was found for “' + complexEscape(data.query) + '”.</strong><p>Try a RefGen_v5 gene model, gene or locus symbol, UniProt accession, or an AF model identifier.</p><a href="#structure-viewers">Check the single-protein viewers instead ↓</a></div>';
      return;
    }
    var hasStructure = (data.monomers || []).length || data.homodimers.length || data.heterodimers.length;
    var defaultType = (data.monomers || []).length ? 'monomer' : (data.heterodimers.length ? 'heterodimer' : 'homodimer');
    target.innerHTML = complexIdentity(data) + '<div class="ps-complex-availability-grid">' + complexAvailability(data) + '</div>' +
      (hasStructure ? '<div class="ps-complex-browser"><div class="ps-complex-browser-heading"><div><span class="ps-eyebrow">Structure candidates</span><h3>Choose an assembly to inspect</h3></div><div class="ps-complex-type-tabs"><button type="button" data-complex-type="monomer"' + (defaultType === 'monomer' ? ' class="is-active"' : '') + '>Monomers <span>' + (data.monomers || []).length + '</span></button><button type="button" data-complex-type="homodimer"' + (defaultType === 'homodimer' ? ' class="is-active"' : '') + '>Homodimers <span>' + data.homodimers.length + '</span></button><button type="button" data-complex-type="heterodimer"' + (defaultType === 'heterodimer' ? ' class="is-active"' : '') + '>Heterodimers <span>' + data.heterodimers.length + '</span></button></div></div><div class="ps-complex-browser-layout"><div class="ps-complex-candidates" id="ps-complex-candidates"></div><div id="ps-complex-model-detail" class="ps-complex-model-detail"></div></div></div>' : '');
    if (!hasStructure) return;
    renderComplexCandidates(defaultType);
    target.querySelectorAll('[data-complex-type]').forEach(function (button) {
      button.addEventListener('click', function () {
        target.querySelectorAll('[data-complex-type]').forEach(function (item) { item.classList.toggle('is-active', item === button); });
        renderComplexCandidates(button.dataset.complexType);
      });
    });
  }

  function renderComplexCandidates(type) {
    var list = type === 'monomer' ? (complexLookupData.monomers || []) : (type === 'homodimer' ? complexLookupData.homodimers : complexLookupData.heterodimers);
    var target = document.getElementById('ps-complex-candidates');
    var detail = document.getElementById('ps-complex-model-detail');
    if (!list.length) {
      target.innerHTML = '<div class="ps-complex-empty"><strong>No ' + complexEscape(type) + ' model is indexed for this protein.</strong></div>';
      detail.innerHTML = '';
      return;
    }
    target.innerHTML = list.map(complexCard).join('');
    target.querySelectorAll('[data-complex-model]').forEach(function (button) {
      button.addEventListener('click', function () {
        displayComplexModel(list.find(function (record) { return record.id === button.dataset.complexModel; }));
      });
    });
    displayComplexModel(list[0]);
  }

  function lookupComplex(term) {
    term = String(term || '').trim();
    var results = document.getElementById('ps-complex-results');
    if (!term) {
      results.innerHTML = '<div class="ps-complex-empty"><strong>Enter a gene model, locus, symbol, or UniProt accession.</strong></div>';
      return;
    }
    results.innerHTML = '<div class="ps-loading" role="status">Finding monomer and complex structures…</div>';
    closeComplexSuggestions();
    fetch('/record_data/protein_complex_api.php?action=lookup&term=' + encodeURIComponent(term), {credentials:'same-origin'})
      .then(function (response) { if (!response.ok) throw new Error('lookup'); return response.json(); })
      .then(renderComplexResults)
      .catch(function () { results.innerHTML = '<div class="ps-error">The protein-complex index is temporarily unavailable.</div>'; });
  }

  function closeComplexSuggestions() {
    var panel = document.getElementById('ps-complex-suggestions');
    var input = document.getElementById('ps-complex-search');
    if (panel) panel.hidden = true;
    if (input) { input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); }
    complexActiveIndex = -1;
  }

  function renderComplexSuggestions(data) {
    var panel = document.getElementById('ps-complex-suggestions');
    var input = document.getElementById('ps-complex-search');
    var suggestions = data.suggestions || [];
    if (!suggestions.length) { closeComplexSuggestions(); return; }
    panel.innerHTML = suggestions.map(function (item, index) {
      var aliases = [].concat(item.gene_ids || [], item.symbols || [], item.uniprots || []).filter(function (value, pos, values) { return value && value !== item.label && values.indexOf(value) === pos; }).slice(0, 4);
      return '<button type="button" id="ps-complex-option-' + index + '" role="option" data-complex-term="' + complexEscape(item.key) + '"><span class="ps-complex-suggestion-icon" aria-hidden="true">' + (item.hetero_count ? 'A+B' : (item.homo_count ? 'A₂' : 'A')) + '</span><span><strong>' + complexEscape(item.label) + '</strong><small>' + complexEscape(aliases.join(' · ') || 'Indexed AlphaFold structure') + '</small></span><span class="ps-complex-suggestion-counts"><b>' + (item.monomer_count || 0) + '</b> mono <b>' + item.homo_count + '</b> homo <b>' + item.hetero_count + '</b> hetero</span></button>';
    }).join('');
    panel.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    panel.querySelectorAll('[data-complex-term]').forEach(function (button) {
      button.addEventListener('click', function () {
        input.value = button.querySelector('strong').textContent;
        lookupComplex(button.dataset.complexTerm);
      });
    });
  }

  function suggestComplex(term) {
    window.clearTimeout(complexSearchTimer);
    if (complexRequest) complexRequest.abort();
    term = String(term || '').trim();
    if (term.length < 2) { closeComplexSuggestions(); return; }
    complexSearchTimer = window.setTimeout(function () {
      complexRequest = new AbortController();
      fetch('/record_data/protein_complex_api.php?action=suggest&term=' + encodeURIComponent(term), {signal:complexRequest.signal, credentials:'same-origin'})
        .then(function (response) { return response.json(); })
        .then(renderComplexSuggestions)
        .catch(function (error) { if (error.name !== 'AbortError') closeComplexSuggestions(); });
    }, 180);
  }

  function initializeComplexSearch() {
    var form = document.getElementById('ps-complex-search-form');
    var input = document.getElementById('ps-complex-search');
    var panel = document.getElementById('ps-complex-suggestions');
    if (!form || !input || !panel || !window.fetch) return;
    form.addEventListener('submit', function (event) { event.preventDefault(); lookupComplex(input.value); });
    input.addEventListener('input', function () { suggestComplex(input.value); });
    input.addEventListener('keydown', function (event) {
      var options = Array.prototype.slice.call(panel.querySelectorAll('[role="option"]'));
      if (event.key === 'Escape') { closeComplexSuggestions(); return; }
      if (!options.length || panel.hidden) return;
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        complexActiveIndex = event.key === 'ArrowDown' ? (complexActiveIndex + 1) % options.length : (complexActiveIndex - 1 + options.length) % options.length;
        options.forEach(function (option, index) { option.classList.toggle('is-active', index === complexActiveIndex); option.setAttribute('aria-selected', index === complexActiveIndex ? 'true' : 'false'); });
        input.setAttribute('aria-activedescendant', options[complexActiveIndex].id);
      } else if (event.key === 'Enter' && complexActiveIndex >= 0) {
        event.preventDefault(); options[complexActiveIndex].click();
      }
    });
    document.addEventListener('click', function (event) { if (!form.contains(event.target)) closeComplexSuggestions(); });
    document.querySelectorAll('[data-complex-example]').forEach(function (button) {
      button.addEventListener('click', function () { input.value = button.dataset.complexExample; lookupComplex(input.value); });
    });
  }

  window.load_structure = function (div, term) {
    var target = document.getElementById(div);
    if (!target) return;
    var cleanTerm = String(term || '').trim();
    if (!cleanTerm) {
      target.innerHTML = '<div class="ps-empty">Enter a gene or protein identifier to search.</div>';
      return;
    }

    var tool = div.split('_')[0];
    target.innerHTML = '<div class="ps-loading" role="status">Loading predicted structure…</div>';

    $.ajax({
      url: '/record_data/protein_structure_data.php',
      data: { term: cleanTerm, tool: tool },
      cache: true
    }).done(function (data) {
      target.innerHTML = data;
      initializeStructureViewers(target);
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }).fail(function () {
      target.innerHTML = '<div class="ps-error">Unable to load this structure. Check the identifier and try again.</div>';
    });
  };

  document.addEventListener('DOMContentLoaded', function () {
    initializeStructureViewers(document);
    initializeComplexSearch();
    document.querySelectorAll('[data-example-target]').forEach(function (button) {
      button.addEventListener('click', function () {
        setExample(button.dataset.exampleTarget, button.dataset.exampleValue);
      });
    });
    document.querySelectorAll('form[data-structure-search]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        event.preventDefault();
        var input = form.querySelector('input[type="search"]');
        window.load_structure(form.dataset.structureSearch, input ? input.value : '');
      });
    });
  });
}());
