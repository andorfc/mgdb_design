/**
 * file: js/mgdb-past.js
 *
 * purpose: /past — section tab scrollspy, and the Copy buttons on the install
 *          commands.
 *
 * The page is complete without this file. The commands are in <pre><code> and
 * can be selected by hand; what is lost is one click, and the active tab moving
 * as you scroll.
 */

(function () {
  'use strict';

  /* The button reads the command out of the <code> beside it rather than from a
     data- attribute, so the command lives in exactly one place in the template
     and the copied text cannot drift from the printed one. */
  function copyButtons() {
    var buttons = [].slice.call(document.querySelectorAll('.past-copy'));
    if (!buttons.length) { return; }

    buttons.forEach(function (button) {
      var box = button.closest('.past-code');
      var code = box ? box.querySelector('code') : null;
      if (!code) {
        /* Nothing to copy means nothing to offer. A button that silently does
           nothing is worse than no button. */
        button.hidden = true;
        return;
      }

      var label = button.textContent;
      var timer = null;

      button.addEventListener('click', function () {
        var text = code.textContent;

        function done(ok) {
          button.textContent = ok ? 'Copied' : manualHint();
          window.clearTimeout(timer);
          timer = window.setTimeout(function () { button.textContent = label; }, 2000);
        }

        /* navigator.clipboard needs a secure context and is not there on an
           http:// origin or an older browser, so the range-selection fallback
           stays. */
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function () { done(true); },
                                                   function () { selectCode(code); done(false); });
          return;
        }
        selectCode(code);
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        done(ok);
      });
    });
  }

  /* When the write is refused -- an insecure origin, a browser that wants a
     stronger user gesture -- the command is selected instead and the button
     says which key finishes the job. Naming the wrong modifier is worse than
     naming none, so the platform is read rather than assumed. */
  function manualHint() {
    var mac = /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent);
    return mac ? 'Press \u2318C' : 'Press Ctrl+C';
  }

  function selectCode(code) {
    var range = document.createRange();
    range.selectNodeContents(code);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  function init() {
    if (window.MGDB && window.MGDB.sectionTabs) {
      window.MGDB.sectionTabs();
    }
    copyButtons();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
