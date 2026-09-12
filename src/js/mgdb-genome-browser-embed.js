/* mgdb-genome-browser-embed.js
 *
 * /jbrowse and /gbrowse. One job: drop the loading overlay once the embedded
 * browser iframe has loaded. The page is fully usable without this -- the
 * overlay just sits behind the browser (pointer-events: none) if the script
 * never runs -- so there is no functional dependency on it.
 */
(function () {
  'use strict';

  function init() {
    var frame = document.querySelector('[data-browser-frame]');
    if (!frame) { return; }
    var iframe = frame.querySelector('[data-browser-iframe]');
    if (!iframe) { return; }

    var done = false;
    var markLoaded = function () {
      if (done) { return; }
      done = true;
      frame.classList.add('is-loaded');
    };

    // If the iframe already completed before we attached (cache), reveal now.
    try {
      if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
        markLoaded();
      }
    } catch (e) {
      // Cross-origin: cannot read readyState; the load event below covers it.
    }

    iframe.addEventListener('load', markLoaded);

    // Safety net: never leave the overlay up forever if load never fires.
    window.setTimeout(markLoaded, 12000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
