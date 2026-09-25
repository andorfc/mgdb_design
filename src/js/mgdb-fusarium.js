/* ==========================================================================
   /fusarium — what every page of the Fusarium Protein Toolkit shares
   --------------------------------------------------------------------------
   Two things, both opt-in from the markup:

     [data-fpt-suggest]   a search field that suggests gene ids, symbols and
                          accessions of the six species as the reader types,
                          from search/fusarium/fusarium_api.php
     main[data-fpt-tabs]  the section tab bar's scrollspy, for pages whose own
                          script does not already run one

   The suggestions only suggest. A pick puts the identifier in the field and
   submits the form, so the page's own lookup runs exactly as though it had
   been typed.

   Exposes window.FPT.suggest for the page scripts that bind a field
   themselves.

   Nothing here touches the DOM before DOMContentLoaded: Bauplan emits every
   includeScript() into <head>.

   Depends on MGDB from /js/mgdb-modern.js.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var MGDB = window.MGDB;
  if (!MGDB) { return; }

  var API = '/search/fusarium/fusarium_api.php';

  /* MGDB.typeahead calls the source on every keystroke, so the wait and the
     cache live here. */
  var cache = {};
  var timer = null;

  function suggest(text) {
    if (cache[text]) { return cache[text]; }
    return new Promise(function (resolve) {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        MGDB.request(API + '?action=suggest&term=' + encodeURIComponent(text), { key: 'fpt-suggest' })
          .then(function (data) {
            var items = (data && data.items) || [];
            cache[text] = items;
            resolve(items);
          })
          .catch(function () { resolve([]); });
      }, 110);
    });
  }

  window.FPT = { api: API, suggest: suggest };

  function init() {
    if (MGDB.typeahead) {
      Array.prototype.forEach.call(document.querySelectorAll('input[data-fpt-suggest]'), function (input) {
        MGDB.typeahead(input, { source: suggest, min: 2 });
      });
    }
    if (MGDB.sectionTabs && document.querySelector('main[data-fpt-tabs]')) {
      MGDB.sectionTabs();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
