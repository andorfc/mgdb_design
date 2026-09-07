/**
 * file: js/mgdb-podcast.js
 *
 * purpose: /podcast — section tab scrollspy, and one playing episode at a time.
 *
 * The page works with this file absent. The players are the browser's own and
 * play whether or not any script runs; what is lost is the active tab moving as
 * you scroll, and the courtesy below.
 */

(function () {
  'use strict';

  /* Eight players on one page, all independent, so pressing play on a second
     one leaves the first one talking underneath it. The browser does not do
     this for us: `<audio>` elements have no shared exclusivity, only the
     Media Session API's platform-level one, which does not apply between two
     elements on the same page. */
  function soloPlayback() {
    var players = [].slice.call(document.querySelectorAll('.podcast-audio'));
    if (players.length < 2) { return; }

    players.forEach(function (player) {
      player.addEventListener('play', function () {
        players.forEach(function (other) {
          if (other !== player && !other.paused) { other.pause(); }
        });
      });
    });
  }

  function init() {
    if (window.MGDB && window.MGDB.sectionTabs) {
      window.MGDB.sectionTabs();
    }
    soloPlayback();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
