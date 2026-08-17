/* ==========================================================================
   MaizeGDB Modern — global header search
   --------------------------------------------------------------------------
   Two behaviours over the search form:

     1. Category listbox — replaces the native <select> with a styled listbox
        that matches the search shell. Each row carries the data type's icon,
        and the toggle mirrors the icon of the chosen row. The native select
        stays in the DOM and remains the submitted value, so the form works
        with JavaScript off.

     2. Suggestions — debounced, cancellable autocomplete against
        /search_engine/autocomplete, following the ARIA combobox pattern.

   Adapted from the header search prototype in the MaizeGDB website repository.
   Changes made here:
     - The suggestion panel exposes its groups as role="group" so the listbox
       contains only options and groups, which is what the ARIA spec allows.
       Previously the groups were plain <section> elements inside a listbox.
     - The response cache is a real LRU: a cache hit re-inserts the key so a
       frequently used query is not evicted ahead of a stale one.
     - aria-busy is set while a request is in flight.
     - The loading spinner is suppressed under prefers-reduced-motion.
     - Recent searches are capped and validated on read, so a corrupted or
       hand-edited localStorage entry cannot inject markup or break the panel.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MIN_QUERY_LENGTH = 2;
  var DEBOUNCE_MS = 220;
  var CACHE_TTL_MS = 5 * 60 * 1000;
  var CACHE_MAX = 60;
  var RECENT_KEY = 'maizegdb_recent_searches_v1';
  var RECENT_MAX = 6;

  var SVG_NS = 'http://www.w3.org/2000/svg';

  function reducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /* ------------------------------------------------------------------------
     Data-type icons

     The category listbox already carries one chip per data type, so the sprite
     targets are read back out of it rather than duplicated here. A record whose
     type has no row in the listbox simply gets no icon.
     ------------------------------------------------------------------------ */

  function readIconIndex(form) {
    var index = {};
    var rows = form.querySelectorAll('#mgdb-search-menu [role="option"]');
    Array.prototype.forEach.call(rows, function (row) {
      var use = row.querySelector('use');
      var value = row.getAttribute('data-value');
      if (use && value) { index[value] = use.getAttribute('href'); }
    });
    return index;
  }

  function buildIcon(index, cat) {
    if (!cat || !index[cat]) { return null; }
    var chip = document.createElement('span');
    chip.className = 'mgdb-search-icon';
    chip.setAttribute('data-cat', cat);
    chip.setAttribute('aria-hidden', 'true');
    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('focusable', 'false');
    var use = document.createElementNS(SVG_NS, 'use');
    use.setAttribute('href', index[cat]);
    svg.appendChild(use);
    chip.appendChild(svg);
    return chip;
  }

  /* ------------------------------------------------------------------------
     Category listbox
     ------------------------------------------------------------------------ */

  function initCategory(form) {
    var root = form.querySelector('[data-search-category]');
    if (!root) { return null; }

    var select = root.querySelector('#global_search_type');
    var toggle = root.querySelector('.mgdb-search-category-button');
    var menu = root.querySelector('.mgdb-search-menu');
    var label = root.querySelector('[data-search-category-label]');
    var icon = root.querySelector('[data-search-category-icon]');
    var options = menu ? Array.prototype.slice.call(menu.querySelectorAll('[role="option"]')) : [];
    if (!select || !toggle || !menu || !label || !options.length) { return null; }

    function optionFor(value) {
      for (var i = 0; i < options.length; i++) {
        if (options[i].getAttribute('data-value') === value) { return options[i]; }
      }
      return options[0];
    }

    /* The toggle shows the chosen category's icon. Rather than keep a second
       copy of the sprite map in script, it retargets its own <use> at whatever
       the chosen row points to and copies the row's data-cat, which is what
       the stylesheet keys the chip colours on. */
    function syncIcon(chosen, value) {
      if (!icon) { return; }
      icon.setAttribute('data-cat', value);
      var source = chosen.querySelector('use');
      var slot = icon.querySelector('use');
      if (source && slot) { slot.setAttribute('href', source.getAttribute('href')); }
    }

    function sync(value) {
      var chosen = optionFor(value);
      var chosenValue = chosen.getAttribute('data-value');
      select.value = chosenValue;
      label.textContent = chosen.getAttribute('data-label') || chosen.textContent.trim();
      syncIcon(chosen, chosenValue);
      options.forEach(function (option) {
        option.setAttribute('aria-selected', option === chosen ? 'true' : 'false');
      });
    }

    function setOpen(open, focusSelected) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      menu.hidden = !open;
      root.classList.toggle('is-open', open);
      if (open && focusSelected) { optionFor(select.value).focus(); }
    }

    toggle.addEventListener('click', function () { setOpen(menu.hidden, true); });

    toggle.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        setOpen(true, true);
      }
    });

    options.forEach(function (option, index) {
      option.addEventListener('click', function () {
        sync(option.getAttribute('data-value'));
        select.dispatchEvent(new Event('change', { bubbles: true }));
        setOpen(false, false);
        toggle.focus();
      });

      option.addEventListener('keydown', function (event) {
        var next = index;
        if (event.key === 'ArrowDown') { next = Math.min(options.length - 1, index + 1); }
        else if (event.key === 'ArrowUp') { next = Math.max(0, index - 1); }
        else if (event.key === 'Home') { next = 0; }
        else if (event.key === 'End') { next = options.length - 1; }
        else if (event.key === 'Escape') {
          event.preventDefault();
          setOpen(false, false);
          toggle.focus();
          return;
        } else { return; }
        event.preventDefault();
        options[next].focus();
      });
    });

    select.addEventListener('change', function () { sync(select.value); });
    document.addEventListener('click', function (event) {
      if (!root.contains(event.target)) { setOpen(false, false); }
    });

    sync(select.value);
    root.classList.add('is-ready');

    return { close: function () { setOpen(false, false); } };
  }

  /* ------------------------------------------------------------------------
     Suggestions
     ------------------------------------------------------------------------ */

  function initSuggestions(form, category) {
    var input = form.querySelector('#global_search_term');
    var select = form.querySelector('#global_search_type');
    var panel = form.querySelector('#mgdb-search-suggestions');
    var content = panel && panel.querySelector('[data-suggestions-content]');
    var footer = panel && panel.querySelector('[data-suggestions-footer]');
    var status = form.querySelector('#mgdb-search-status');
    if (!input || !select || !panel || !content || !footer || !status || !window.fetch) { return; }

    var icons = readIconIndex(form);

    var timer = null;
    var controller = null;
    var requestSeq = 0;
    var activeIndex = -1;
    var optionEls = [];
    var cache = new Map();

    function typeLabel() {
      var option = select.options[select.selectedIndex];
      return option ? option.textContent.trim() : 'All data';
    }

    function setStatus(message) { status.textContent = message; }

    function open() {
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    }

    function close() {
      panel.hidden = true;
      panel.removeAttribute('aria-busy');
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      activeIndex = -1;
      optionEls = [];
    }

    function clear() {
      while (content.firstChild) { content.removeChild(content.firstChild); }
      footer.hidden = true;
      footer.textContent = '';
      optionEls = [];
      activeIndex = -1;
      input.removeAttribute('aria-activedescendant');
    }

    /* Builds text with the matched substring wrapped in <mark>. Everything is
       appended as text nodes, so record labels can never inject markup. */
    function highlight(parent, text, query) {
      text = String(text == null ? '' : text);
      query = String(query == null ? '' : query).trim();
      if (!query) {
        parent.appendChild(document.createTextNode(text));
        return;
      }
      var haystack = text.toLowerCase();
      var needle = query.toLowerCase();
      var from = 0;
      var at = haystack.indexOf(needle, from);
      while (at !== -1) {
        if (at > from) { parent.appendChild(document.createTextNode(text.slice(from, at))); }
        var mark = document.createElement('mark');
        mark.textContent = text.slice(at, at + needle.length);
        parent.appendChild(mark);
        from = at + needle.length;
        at = haystack.indexOf(needle, from);
      }
      if (from < text.length) { parent.appendChild(document.createTextNode(text.slice(from))); }
    }

    /* -- recent searches -- */

    function readRecent() {
      try {
        var parsed = JSON.parse(window.localStorage.getItem(RECENT_KEY) || '[]');
        if (!Array.isArray(parsed)) { return []; }
        return parsed.filter(function (item) {
          return item && typeof item.term === 'string' && item.term.length > 0 && item.term.length < 200;
        }).slice(0, RECENT_MAX);
      } catch (error) {
        return [];
      }
    }

    function saveRecent(term, type, label) {
      term = String(term || '').trim();
      if (!term) { return; }
      var recent = readRecent().filter(function (item) {
        return !(item.term.toLowerCase() === term.toLowerCase() && item.type === type);
      });
      recent.unshift({ term: term, type: type, typeLabel: label });
      try {
        window.localStorage.setItem(RECENT_KEY, JSON.stringify(recent.slice(0, RECENT_MAX)));
      } catch (error) { /* storage full or blocked; recents are optional */ }
    }

    function showRecent() {
      var recent = readRecent();
      if (!recent.length) { close(); return; }
      clear();

      var heading = document.createElement('div');
      heading.className = 'mgdb-suggestions-heading';
      heading.textContent = 'Recent searches';
      content.appendChild(heading);

      var group = document.createElement('div');
      group.setAttribute('role', 'group');
      group.setAttribute('aria-label', 'Recent searches');

      recent.forEach(function (item, index) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'mgdb-suggestion mgdb-suggestion-recent';
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', 'false');
        button.id = 'mgdb-suggestion-' + index;

        var icon = buildIcon(icons, item.type);
        if (icon) { button.appendChild(icon); }

        var term = document.createElement('strong');
        term.textContent = item.term;
        var scope = document.createElement('small');
        scope.textContent = item.typeLabel || 'All data';
        button.appendChild(term);
        button.appendChild(scope);

        button.addEventListener('click', function () {
          input.value = item.term;
          var known = Array.prototype.some.call(select.options, function (option) {
            return option.value === item.type;
          });
          if (known) {
            select.value = item.type;
            select.dispatchEvent(new Event('change', { bubbles: true }));
          }
          input.focus();
          schedule(true);
        });

        group.appendChild(button);
      });

      content.appendChild(group);
      optionEls = Array.prototype.slice.call(group.querySelectorAll('[role="option"]'));
      open();
      setStatus(recent.length + ' recent searches available.');
    }

    function showLoading() {
      clear();
      panel.setAttribute('aria-busy', 'true');
      var box = document.createElement('div');
      box.className = 'mgdb-suggestions-message';
      if (!reducedMotion()) {
        var spinner = document.createElement('span');
        spinner.className = 'mgdb-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        box.appendChild(spinner);
      }
      box.appendChild(document.createTextNode('Finding matching records…'));
      content.appendChild(box);
      open();
      setStatus('Loading search suggestions.');
    }

    function setFooter(query) {
      footer.hidden = false;
      footer.textContent = 'See all results for “' + query + '” in ' + typeLabel();
    }

    function showMessage(message, query) {
      clear();
      panel.removeAttribute('aria-busy');
      var box = document.createElement('div');
      box.className = 'mgdb-suggestions-message';
      box.textContent = message;
      content.appendChild(box);
      setFooter(query);
      open();
      setStatus(message);
    }

    function buildOption(item, group, query, index, isTop) {
      var link = document.createElement('a');
      link.className = isTop ? 'mgdb-suggestion mgdb-suggestion-top' : 'mgdb-suggestion';
      if (item.exact) { link.className += ' is-exact'; }
      link.href = item.url;
      link.id = 'mgdb-suggestion-' + index;
      link.setAttribute('role', 'option');
      link.setAttribute('aria-selected', 'false');

      /* The type is on the item, not the group, because the promoted top hit
         belongs to no group and a group can mix types. */
      var icon = buildIcon(icons, item.cat || (group && group.cat));
      if (icon) { link.appendChild(icon); }

      var copy = document.createElement('span');
      copy.className = 'mgdb-suggestion-copy';
      var label = document.createElement('strong');
      highlight(label, item.label, query);
      copy.appendChild(label);
      if (item.secondary) {
        var secondary = document.createElement('small');
        highlight(secondary, item.secondary, query);
        copy.appendChild(secondary);
      }

      var badge = document.createElement('span');
      badge.className = 'mgdb-suggestion-badge';
      badge.textContent = isTop ? (item.action || 'Best match') : (item.badge || (group && group.label) || '');

      link.appendChild(copy);
      link.appendChild(badge);
      link.addEventListener('click', function () {
        saveRecent(query, select.value, typeLabel());
      });
      return link;
    }

    function render(data) {
      var query = String(data.query || input.value).trim();
      clear();
      panel.removeAttribute('aria-busy');

      if ((!data.groups || !data.groups.length) && !data.top_hit) {
        showMessage(data.error || 'No quick matches found. Press Enter to run the full search.', query);
        return;
      }

      var index = 0;

      if (data.top_hit) {
        content.appendChild(buildOption(data.top_hit, null, query, index++, true));
      }

      (data.groups || []).forEach(function (group) {
        /* role="group" rather than a bare <section>: a listbox may only contain
           options and groups, and screen readers announce the group label as
           the option's context. */
        var section = document.createElement('div');
        section.setAttribute('role', 'group');
        section.setAttribute('aria-label', group.label);
        section.className = 'mgdb-suggestions-group';

        var heading = document.createElement('div');
        heading.className = 'mgdb-suggestions-heading';
        var title = document.createElement('span');
        title.textContent = group.label;
        var count = document.createElement('span');
        var total = Number(group.total || group.items.length);
        count.className = 'mgdb-suggestions-count';
        count.textContent = total.toLocaleString() + (group.has_more ? '+' : '') +
          (total === 1 && !group.has_more ? ' match' : ' matches');
        heading.appendChild(title);
        heading.appendChild(count);
        /* The heading is decorative: aria-label on the group already names it. */
        heading.setAttribute('aria-hidden', 'true');
        section.appendChild(heading);

        (group.items || []).forEach(function (item) {
          section.appendChild(buildOption(item, group, query, index++));
        });

        content.appendChild(section);
      });

      optionEls = Array.prototype.slice.call(content.querySelectorAll('[role="option"]'));
      setFooter(query);
      open();
      setStatus(optionEls.length + ' search suggestion' + (optionEls.length === 1 ? '' : 's') +
        ' available. Use the up and down arrow keys to review them.');
    }

    /* -- request handling -- */

    function cacheKey(query) { return select.value + '|' + query.toLowerCase(); }

    function cacheGet(key) {
      var entry = cache.get(key);
      if (!entry) { return null; }
      if (Date.now() - entry.time >= CACHE_TTL_MS) {
        cache.delete(key);
        return null;
      }
      /* Re-insert so Map iteration order reflects recency, making eviction LRU
         rather than first-in-first-out. */
      cache.delete(key);
      cache.set(key, entry);
      return entry.data;
    }

    function cacheSet(key, data) {
      cache.set(key, { time: Date.now(), data: data });
      while (cache.size > CACHE_MAX) {
        cache.delete(cache.keys().next().value);
      }
    }

    function fetchSuggestions(query) {
      var key = cacheKey(query);
      var hit = cacheGet(key);
      if (hit) { render(hit); return; }

      if (controller && controller.abort) { controller.abort(); }
      controller = window.AbortController ? new window.AbortController() : null;
      var seq = ++requestSeq;

      showLoading();

      var url = '/search_engine/autocomplete?global_search_term=' + encodeURIComponent(query) +
                '&global_search_type=' + encodeURIComponent(select.value);
      var options = { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
      if (controller) { options.signal = controller.signal; }

      window.fetch(url, options)
        .then(function (response) {
          if (!response.ok) { throw new Error('Suggestion request failed'); }
          return response.json();
        })
        .then(function (data) {
          // Ignore a response that a newer keystroke has already superseded.
          if (seq !== requestSeq || input.value.trim() !== query) { return; }
          cacheSet(key, data);
          render(data);
        })
        .catch(function (error) {
          if (error && error.name === 'AbortError') { return; }
          if (seq !== requestSeq) { return; }
          showMessage('Suggestions are temporarily unavailable. Press Enter to search.', query);
        });
    }

    function schedule(immediate) {
      window.clearTimeout(timer);
      var query = input.value.trim();

      // Static page search is handled by an external engine; no suggestions.
      if (select.value === 'goog') { close(); return; }

      if (!query) { showRecent(); return; }

      if (query.length < MIN_QUERY_LENGTH && select.value !== 'id') {
        close();
        setStatus('Enter at least ' + MIN_QUERY_LENGTH + ' characters for suggestions.');
        return;
      }

      timer = window.setTimeout(function () { fetchSuggestions(query); }, immediate ? 0 : DEBOUNCE_MS);
    }

    function setActive(next) {
      if (!optionEls.length) { return; }
      if (activeIndex >= 0 && optionEls[activeIndex]) {
        optionEls[activeIndex].setAttribute('aria-selected', 'false');
      }
      activeIndex = (next + optionEls.length) % optionEls.length;
      var active = optionEls[activeIndex];
      active.setAttribute('aria-selected', 'true');
      input.setAttribute('aria-activedescendant', active.id);
      if (active.scrollIntoView) { active.scrollIntoView({ block: 'nearest' }); }
    }

    input.addEventListener('input', function () { schedule(false); });

    input.addEventListener('focus', function () {
      if (!input.value.trim()) { showRecent(); }
      else { schedule(false); }
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' && !panel.hidden) {
        event.preventDefault();
        setActive(activeIndex + 1);
      } else if (event.key === 'ArrowUp' && !panel.hidden) {
        event.preventDefault();
        setActive(activeIndex <= 0 ? optionEls.length - 1 : activeIndex - 1);
      } else if (event.key === 'Enter' && !panel.hidden && activeIndex >= 0 && optionEls[activeIndex]) {
        event.preventDefault();
        saveRecent(input.value, select.value, typeLabel());
        var active = optionEls[activeIndex];
        if (active.href) { window.location.assign(active.href); }
        else { active.click(); }
      } else if (event.key === 'Escape') {
        event.preventDefault();
        close();
      }
    });

    select.addEventListener('change', function () {
      close();
      if (input.value.trim()) { schedule(true); }
    });

    if (category) {
      form.querySelector('.mgdb-search-category-button')
        .addEventListener('click', function () { close(); });
    }

    form.addEventListener('submit', function () {
      saveRecent(input.value, select.value, typeLabel());
      close();
    });

    document.addEventListener('click', function (event) {
      if (!form.contains(event.target)) { close(); }
    });
  }

  function init() {
    var form = document.getElementById('global_search_form');
    if (!form || form.getAttribute('data-search-ready') === 'true') { return; }
    var category = initCategory(form);
    initSuggestions(form, category);
    form.setAttribute('data-search-ready', 'true');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
