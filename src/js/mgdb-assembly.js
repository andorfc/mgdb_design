/**
 * file: js/mgdb-assembly.js
 *
 * purpose: Scrollspy, copy-to-clipboard, and accordion interactivity for Reference Assembly Data Hub
 */

(function () {
  'use strict';

  function init() {
    initScrollSpy();
    initCopyButtons();
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

  function initCopyButtons() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.reference-copy-id');
      if (!btn) return;

      var val = btn.getAttribute('data-copy-value');
      if (!val) return;

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(val).then(function () {
          flashCopyState(btn);
        });
      } else {
        var textarea = document.createElement('textarea');
        textarea.value = val;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
          document.execCommand('copy');
          flashCopyState(btn);
        } catch (err) {
          console.error('Could not copy text: ', err);
        }
        document.body.removeChild(textarea);
      }
    });
  }

  function flashCopyState(btn) {
    var originalText = btn.textContent;
    btn.textContent = 'Copied!';
    btn.classList.add('is-copied');
    setTimeout(function () {
      btn.textContent = originalText;
      btn.classList.remove('is-copied');
    }, 2000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
