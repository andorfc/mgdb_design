/* AI and machine learning resources (/ai).

   Resource finder and citation copying, ported from the AI page in the MaizeGDB
   website repository. The cards are rendered server-side, so filtering only
   hides and shows what is already present.

   Adapted for the redesign: the section-hiding selector follows the class the
   template now uses, and copying falls back to selecting the citation when the
   clipboard API is unavailable or refused. */

(function () {
  'use strict';

  function byId(id) {
    return document.getElementById(id);
  }

  var activeCategory = 'all';

  function normalize(value) {
    return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
  }

  function updateResources() {
    var input = byId('ai-resource-query');
    var query = normalize(input ? input.value : '');
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-ai-resource]'));
    var visible = 0;

    cards.forEach(function (card) {
      var categoryMatch = activeCategory === 'all' || card.getAttribute('data-ai-category') === activeCategory;
      var searchText = normalize(card.getAttribute('data-ai-search') + ' ' + card.textContent);
      var queryMatch = !query || query.split(' ').every(function (term) { return searchText.indexOf(term) !== -1; });
      card.hidden = !(categoryMatch && queryMatch);
      if (!card.hidden) visible += 1;
    });

    document.querySelectorAll('.ai-section').forEach(function (section) {
      if (section.id === 'ai-publications') return;
      var resourceCards = section.querySelectorAll('[data-ai-resource]');
      if (!resourceCards.length) return;
      var sectionVisible = Array.prototype.some.call(resourceCards, function (card) { return !card.hidden; });
      section.hidden = !sectionVisible;
    });

    byId('ai-resource-count').textContent = visible + (visible === 1 ? ' resource shown' : ' resources shown');
    byId('ai-no-results').hidden = visible !== 0;
    byId('ai-resource-clear').hidden = !query;
  }

  function setCategory(category) {
    activeCategory = category;
    document.querySelectorAll('[data-ai-filter]').forEach(function (button) {
      var active = button.getAttribute('data-ai-filter') === category;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    updateResources();
  }

  function resetFinder() {
    byId('ai-resource-query').value = '';
    setCategory('all');
    byId('ai-resource-query').focus();
  }

  function copyCitation(button) {
    var target = byId(button.getAttribute('data-copy-target'));
    if (!target) return;
    var citation = target.textContent.replace(/\s+/g, ' ').trim();
    var original = button.textContent;
    function done() {
      button.textContent = 'Copied';
      window.setTimeout(function () { button.textContent = original; }, 1200);
    }
    function selectTarget() {
      var range = document.createRange();
      range.selectNodeContents(target);
      var selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
      button.textContent = 'Press Ctrl or Cmd + C';
      window.setTimeout(function () { button.textContent = original; }, 2000);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(citation).then(done).catch(selectTarget);
    } else {
      var area = document.createElement('textarea');
      area.value = citation;
      document.body.appendChild(area);
      area.select();
      document.execCommand('copy');
      document.body.removeChild(area);
      done();
    }
  }

  function initialize() {
    if (!byId('ai-resource-query')) return;

    byId('ai-resource-query').addEventListener('input', updateResources);
    byId('ai-resource-clear').addEventListener('click', function () {
      byId('ai-resource-query').value = '';
      updateResources();
      byId('ai-resource-query').focus();
    });
    byId('ai-no-results-reset').addEventListener('click', resetFinder);

    document.querySelectorAll('[data-ai-filter]').forEach(function (button) {
      button.addEventListener('click', function () { setCategory(button.getAttribute('data-ai-filter')); });
    });
    document.querySelectorAll('.ai-copy-citation').forEach(function (button) {
      button.addEventListener('click', function () { copyCitation(button); });
    });

    setCategory('all');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize);
  } else {
    initialize();
  }
}());
