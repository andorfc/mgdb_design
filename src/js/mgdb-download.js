/**
 * file: js/mgdb-download.js
 *
 * purpose: Scrollspy and clipboard copy helpers for Bulk Downloads & Globus Data Portal
 */

(function () {
  'use strict';

  function init() {
    initScrollSpy();
  }

  function initScrollSpy() {
    var tabs = document.querySelectorAll('.mgdb-section-tabs a');
    if (!tabs.length || !('IntersectionObserver' in window)) return;

    var sectionIds = [];
    tabs.forEach(function (tab) {
      var href = tab.getAttribute('href');
      if (href && href.charAt(0) === '#') {
        sectionIds.push(href.substring(1));
      }
    });

    var sections = sectionIds.map(function (id) {
      return document.getElementById(id);
    }).filter(Boolean);

    if (!sections.length) return;

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          var id = entry.target.id;
          tabs.forEach(function (tab) {
            if (tab.getAttribute('href') === '#' + id) {
              tab.classList.add('is-current');
              tab.setAttribute('aria-current', 'true');
            } else {
              tab.classList.remove('is-current');
              tab.removeAttribute('aria-current');
            }
          });
        }
      });
    }, {
      rootMargin: '-10% 0px -75% 0px',
      threshold: 0
    });

    sections.forEach(function (sec) {
      observer.observe(sec);
    });
  }

  // Global clipboard copy helper
  window.copyText = function (elementId, btn) {
    var el = document.getElementById(elementId);
    if (!el) return;
    var text = el.textContent || el.innerText;
    navigator.clipboard.writeText(text.trim()).then(function () {
      var oldText = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(function () {
        btn.textContent = oldText;
      }, 2000);
    }).catch(function (err) {
      console.error('Failed to copy: ', err);
    });
  };

  window.copySnippet = function (codeId, btn) {
    var el = document.getElementById(codeId);
    if (!el) return;
    var text = el.textContent || el.innerText;
    navigator.clipboard.writeText(text.trim()).then(function () {
      var oldText = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(function () {
        btn.textContent = oldText;
      }, 2000);
    }).catch(function (err) {
      console.error('Failed to copy code snippet: ', err);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
