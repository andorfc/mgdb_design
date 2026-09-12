/* file: mgdb-coop-order.js
 *
 * The Maize Genetics Cooperation Stock Center request form, on the design
 * system. This drives the page; the server does not.
 *
 * Every request goes to the same endpoints the legacy page used --
 * controllers/ordering/coop_order.php, selected by an `action` field -- so the
 * basket store and the order email are untouched. Only the presentation is new.
 *
 *   get-list      the stocks currently on the order (":::"-joined, "|||"-comment)
 *   check-stock   validate a typed name; "Multiple: a||b", an error, or the name
 *   add-stock     add a validated name (with optional comment)
 *   remove-stock  remove by descriptive name
 *   get-comment   the stored comment for a descriptive name
 *   clear-order   empty the order
 *   submit        e-mail the whole order to the Stock Center
 *
 * The one legacy round-trip dropped is check-country: findCountry() runs
 * `= ANY(variations)` against a varchar column, which errors and returns empty
 * for every country, so the check only ever produced a spurious confirm. The
 * client-side rule -- a phone number is required outside the USA -- is kept.
 *
 * This script is emitted into <head>, so it waits for the DOM before touching
 * it (mgdb-modern.js does the same).
 */
(function () {
  'use strict';

  var ENDPOINT = '/ordering/coop_order';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  /* One small POST helper. MGDB.request is JSON-only; these endpoints answer
     plain text, so this uses fetch directly and returns the trimmed body. */
  function post(fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    return fetch(ENDPOINT, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    }).then(function (r) {
      if (!r.ok) { throw new Error('status ' + r.status); }
      return r.text();
    }).then(function (t) { return (t || '').trim(); });
  }

  var esc = (window.MGDB && MGDB.escapeHtml) ? MGDB.escapeHtml : function (v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  };
  function announce(msg) { if (window.MGDB && MGDB.announce) { MGDB.announce(msg); } }

  ready(function () {
    var root = document.getElementById('mgdb-coop-order-main');
    if (!root) { return; }

    var nameInput    = document.getElementById('coop-stock-name');
    var commentInput = document.getElementById('coop-stock-comment');
    var addBtn       = document.getElementById('coop-add-btn');
    var addError     = document.getElementById('coop-add-error');
    var matches      = document.getElementById('coop-matches');
    var matchesLead  = document.getElementById('coop-matches-lead');
    var matchesList  = document.getElementById('coop-matches-list');
    var listBox      = document.getElementById('coop-list');
    var countBadge   = document.getElementById('coop-count');
    var statusBox    = document.getElementById('coop-status');
    var submitBtn    = document.getElementById('coop-submit-btn');

    /* The order, as [{ name, comment }]. Rebuilt from get-list on every change
       so the client never disagrees with the stored file. */
    var order = [];
    /* When a comment is being edited, the descriptive name it belongs to. */
    var editingName = null;

    /* ---- status + errors ------------------------------------------------- */

    function setStatus(kind, html) {
      if (!html) { statusBox.hidden = true; statusBox.innerHTML = ''; return; }
      statusBox.className = 'coop-status mgdb-message mgdb-message-' + kind;
      statusBox.innerHTML = html;
      statusBox.hidden = false;
    }
    function setAddError(msg) {
      if (!msg) { addError.hidden = true; addError.textContent = ''; nameInput.removeAttribute('aria-invalid'); return; }
      addError.textContent = msg;
      addError.hidden = false;
      nameInput.setAttribute('aria-invalid', 'true');
    }
    function hideMatches() { matches.hidden = true; matchesList.innerHTML = ''; }

    /* ---- the list -------------------------------------------------------- */

    /* A descriptive name may carry a trailing "   [comment]" from get-list; the
       stored key is the part before it. */
    function splitEntry(str) {
      var m = /^(.*?)\s+\[(.*)\]\s*$/.exec(str);
      if (m) { return { name: m[1], comment: m[2] }; }
      return { name: str, comment: '' };
    }

    /* The leading token of a descriptive name is the stock id/name that the
       record page answers to -- "CML277" in "CML277". Only link when it looks
       like a stock identifier rather than a phrase. */
    function recordHref(name) {
      var token = name.split(/\s+/)[0];
      if (/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(token) && name.indexOf(' ') === -1) {
        return '/data_center/stock/' + encodeURIComponent(token);
      }
      return null;
    }

    function refreshList() {
      return post({ action: 'get-list' }).then(function (text) {
        order = [];
        if (text && text !== 'ERROR') {
          text.split(':::').forEach(function (chunk) {
            if (chunk === '' ) { return; }
            var parts = chunk.split('|||');
            var name = parts[0].trim();
            if (name === '') { return; }
            order.push({ name: name, comment: parts.length > 1 ? parts[1] : '' });
          });
        }
        renderList();
      });
    }

    function renderList() {
      var n = order.length;
      countBadge.hidden = n === 0;
      countBadge.textContent = n === 0 ? '' : (n + (n === 1 ? ' stock' : ' stocks'));

      if (n === 0) {
        listBox.innerHTML =
          '<div class="mgdb-empty"><h3>No stocks yet</h3>' +
          '<p>Add a stock above, or open a stock record and choose <strong>Order this stock</strong>. ' +
          'Everything you add collects here until you submit.</p></div>';
        return;
      }

      var rows = order.map(function (item) {
        var href = recordHref(item.name);
        var nameCell = href
          ? '<a href="' + esc(href) + '" target="_blank" rel="noopener">' + esc(item.name) + '</a>'
          : esc(item.name);
        return '<tr>' +
          '<td><span class="coop-stock-name">' + nameCell + '</span></td>' +
          '<td class="coop-stock-comment">' + esc(item.comment) + '</td>' +
          '<td class="coop-row-actions">' +
            '<button type="button" class="mgdb-button mgdb-button-quiet mgdb-button-sm" data-act="comment" data-name="' + esc(item.name) + '">' +
              (item.comment ? 'Edit comment' : 'Add comment') + '</button>' +
            '<button type="button" class="mgdb-button mgdb-button-quiet mgdb-button-sm" data-act="remove" data-name="' + esc(item.name) + '">Remove</button>' +
          '</td></tr>';
      }).join('');

      listBox.innerHTML =
        '<div class="mgdb-table-scroll"><table class="mgdb-table coop-list-table">' +
        '<caption class="mgdb-visually-hidden">Stocks in your order</caption>' +
        '<thead><tr><th scope="col">Stock</th><th scope="col">Comment</th>' +
        '<th scope="col"><span class="mgdb-visually-hidden">Actions</span></th></tr></thead>' +
        '<tbody>' + rows + '</tbody></table></div>' +
        '<div class="coop-list-footer">' +
          '<p class="mgdb-muted" style="margin:0">Add every stock before you submit &mdash; the list is sent as one request.</p>' +
          '<button type="button" class="mgdb-button mgdb-button-quiet" id="coop-clear-btn">Clear the list</button>' +
        '</div>';

      var clearBtn = document.getElementById('coop-clear-btn');
      if (clearBtn) { clearBtn.addEventListener('click', clearList); }
    }

    /* ---- adding ---------------------------------------------------------- */

    /* Encode a name the way the legacy client did, so add-stock's urldecode()
       reproduces the exact stored value: encodeURI leaves "+" alone, which the
       server would read as a space, so it is escaped explicitly. */
    function encodeName(name) {
      return encodeURI(name).replace(/\+/g, '%2B');
    }

    function addStock(name, comment) {
      comment = (comment || '').replace(/[\r\n]+/g, ' ');
      var fields = { action: 'add-stock', stock_name: encodeName(name), stock_comment: comment };
      return post(fields).then(function (res) {
        if (res === 'ERROR') {
          setStatus('error', '<span><strong>Could not save the order.</strong> Please send your request directly to <a href="mailto:maize@uiuc.edu">maize@uiuc.edu</a>.</span>');
          return false;
        }
        /* add-stock drops the comment when it appends a stock to a non-empty
           order -- only its update branch stores one. A second, identical add
           takes that update branch, so a comment given on the first add is not
           silently lost. Harmless when the first add already kept it. */
        if (comment === '') { return true; }
        return post(fields).then(function (r2) { return r2 !== 'ERROR'; });
      });
    }

    function submitAdd() {
      setAddError('');
      hideMatches();
      var typed = nameInput.value.trim();
      if (typed === '') { setAddError('Enter a stock name first.'); nameInput.focus(); return; }

      var comment = commentInput.value;

      /* When editing a comment, the name is fixed -- go straight to add-stock,
         which updates the existing entry's comment. */
      if (editingName !== null) {
        var target = editingName;
        addStock(target, comment).then(function (ok) {
          if (!ok) { return; }
          editingName = null;
          addBtn.textContent = 'Add to list';
          nameInput.value = ''; commentInput.value = '';
          setStatus('ok', '<span><strong>Comment updated</strong> for ' + esc(target) + '.</span>');
          announce('Comment updated for ' + target);
          refreshList();
        });
        return;
      }

      addBtn.disabled = true;
      post({ action: 'check-stock', stock_name: typed }).then(function (res) {
        if (res.indexOf('Multiple: ') === 0) {
          showMatches(typed, res.slice('Multiple: '.length).split('||'));
          return null;
        }
        if (res.indexOf('Stock') === 0) {          // "Stock not found." / "Stock is not available."
          setAddError(res);
          return null;
        }
        if (res === '' || res === 'No stock name.') {
          setAddError('Enter a stock name first.');
          return null;
        }
        /* res is the descriptive name the catalog holds. */
        return addStock(res, comment).then(function (ok) {
          if (!ok) { return; }
          nameInput.value = ''; commentInput.value = '';
          setStatus('ok', '<span><strong>Added to your order:</strong> ' + esc(res) + '.</span>');
          announce('Added ' + res);
          nameInput.focus();
          return refreshList();
        });
      }).catch(function () {
        setAddError('Could not reach the catalog. Please try again.');
      }).then(function () { addBtn.disabled = false; });
    }

    function showMatches(term, names) {
      matchesLead.textContent = names.length > 20
        ? ('Many stocks match "' + term + '". Narrow the name, or use the stock search or catalog to find the exact one.')
        : ('Several stocks match "' + term + '". Choose the one you want:');
      matchesList.innerHTML = names.slice(0, 60).map(function (nm) {
        nm = nm.trim();
        var href = recordHref(nm);
        var view = href
          ? '<a class="mgdb-button mgdb-button-quiet mgdb-button-sm" href="' + esc(href) + '" target="_blank" rel="noopener">View</a>'
          : '';
        return '<li><span class="coop-matches-name">' + esc(nm) + '</span>' +
          '<span class="coop-matches-actions">' +
          '<button type="button" class="mgdb-button mgdb-button-secondary mgdb-button-sm" data-add="' + esc(nm) + '">Add</button>' +
          view + '</span></li>';
      }).join('');
      matches.hidden = false;
    }

    /* ---- removing + clearing --------------------------------------------- */

    function removeStock(name) {
      post({ action: 'remove-stock', stock_name: name }).then(function () {
        setStatus('info', '<span><strong>Removed</strong> ' + esc(name) + ' from your order.</span>');
        announce('Removed ' + name);
        if (editingName === name) {
          editingName = null; addBtn.textContent = 'Add to list';
          nameInput.value = ''; commentInput.value = '';
        }
        refreshList();
      });
    }

    function clearList() {
      if (order.length && !window.confirm('Remove all ' + order.length + ' stocks from your order?')) { return; }
      post({ action: 'clear-order' }).then(function () {
        setStatus('info', '<span><strong>Order cleared.</strong> Nothing is waiting to be submitted.</span>');
        announce('Order cleared');
        editingName = null; addBtn.textContent = 'Add to list';
        refreshList();
      });
    }

    function editComment(name) {
      post({ action: 'get-comment', stock_descriptive_name: name }).then(function (comment) {
        editingName = name;
        nameInput.value = name;
        commentInput.value = comment || '';
        addBtn.textContent = 'Update comment';
        setAddError('');
        commentInput.focus();
        setStatus('info', '<span>Editing the comment for <strong>' + esc(name) + '</strong>. Change it above and choose <strong>Update comment</strong>.</span>');
      });
    }

    /* ---- submit ---------------------------------------------------------- */

    function fieldError(id, msg) {
      var input = document.getElementById(id);
      var box = document.getElementById(id + '-error');
      if (msg) {
        if (box) { box.textContent = msg; box.hidden = false; }
        if (input) { input.setAttribute('aria-invalid', 'true'); }
      } else {
        if (box) { box.hidden = true; box.textContent = ''; }
        if (input) { input.removeAttribute('aria-invalid'); }
      }
    }

    function isUSA(country) {
      return /^(usa|us|u\.s\.a?\.?|united states( of america)?)$/i.test(country.trim());
    }

    function validateShipping() {
      ['coop-name', 'coop-email', 'coop-address', 'coop-country', 'coop-phone'].forEach(function (id) { fieldError(id, ''); });
      var errs = [];
      var name = document.getElementById('coop-name').value.trim();
      var email = document.getElementById('coop-email').value.trim();
      var address = document.getElementById('coop-address').value.trim();
      var country = document.getElementById('coop-country').value.trim();
      var phone = document.getElementById('coop-phone').value;

      if (order.length === 0) { errs.push({ msg: 'Add at least one stock before submitting.' }); }
      if (name === '') { errs.push({ id: 'coop-name', msg: 'Please provide a name.' }); }
      if (email === '') { errs.push({ id: 'coop-email', msg: 'Please provide an e-mail address.' }); }
      else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { errs.push({ id: 'coop-email', msg: 'That does not look like an e-mail address.' }); }
      if (address === '') { errs.push({ id: 'coop-address', msg: 'Please provide a shipping address.' }); }
      if (country === '') { errs.push({ id: 'coop-country', msg: 'Please indicate your country.' }); }
      if (country !== '' && !isUSA(country)) {
        var digits = phone.replace(/[^\d]/g, '');
        if (digits.length < 10) { errs.push({ id: 'coop-phone', msg: 'A phone number is required for shipping outside the USA.' }); }
      }
      errs.forEach(function (e) { if (e.id) { fieldError(e.id, e.msg); } });
      return errs;
    }

    function submitOrder() {
      /* Re-read the list so what is submitted is exactly what the server holds. */
      refreshList().then(function () {
        var errs = validateShipping();
        if (errs.length) {
          var lead = errs.length === 1 ? errs[0].msg
            : 'Please fix the highlighted fields and try again.';
          setStatus('error', '<span><strong>Not submitted.</strong> ' + esc(lead) + '</span>');
          var first = document.querySelector('[aria-invalid="true"]');
          if (first) { first.focus(); }
          else { document.getElementById('coop-list').scrollIntoView({ block: 'center' }); }
          return;
        }

        var lines = order.map(function (o) { return o.comment ? (o.name + '|||' + o.comment) : o.name; });
        var msg = 'Submit this request for ' + order.length + (order.length === 1 ? ' stock' : ' stocks') + '?\n\n'
                + order.map(function (o) { return o.comment ? (o.name + '  [' + o.comment + ']') : o.name; }).join('\n') + '\n';
        if (!window.confirm(msg)) { return; }

        submitBtn.disabled = true;
        setStatus('info', '<span>Submitting your request&hellip;</span>');
        post({
          action: 'submit',
          stock_order: lines.join(':::'),
          name: document.getElementById('coop-name').value,
          email: document.getElementById('coop-email').value,
          address: document.getElementById('coop-address').value,
          country: document.getElementById('coop-country').value,
          phone: document.getElementById('coop-phone').value,
          instructions: document.getElementById('coop-instructions').value,
          genome: document.getElementById('coop-genome').checked ? 'Y' : 'N'
        }).then(function (res) {
          if (res === 'Missing information') {
            submitBtn.disabled = false;
            validateShipping();
            setStatus('error', '<span><strong>Not submitted.</strong> Please complete the required fields.</span>');
            return;
          }
          window.location = '/ordering/coop_order/completed';
        }).catch(function () {
          submitBtn.disabled = false;
          setStatus('error', '<span><strong>Could not submit.</strong> Please send your request directly to <a href="mailto:maize@uiuc.edu">maize@uiuc.edu</a>.</span>');
        });
      });
    }

    /* ---- wiring ---------------------------------------------------------- */

    addBtn.addEventListener('click', submitAdd);
    nameInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); submitAdd(); }
    });
    commentInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); submitAdd(); }
    });
    submitBtn.addEventListener('click', submitOrder);

    /* Delegated: per-row Remove / Edit-comment, and the match-panel Add. */
    listBox.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-act]');
      if (!btn) { return; }
      var name = btn.getAttribute('data-name');
      if (btn.getAttribute('data-act') === 'remove') { removeStock(name); }
      else if (btn.getAttribute('data-act') === 'comment') { editComment(name); }
    });
    matchesList.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-add]');
      if (!btn) { return; }
      var name = btn.getAttribute('data-add');
      hideMatches();
      addStock(name, commentInput.value).then(function (ok) {
        if (!ok) { return; }
        nameInput.value = ''; commentInput.value = '';
        setStatus('ok', '<span><strong>Added to your order:</strong> ' + esc(name) + '.</span>');
        announce('Added ' + name);
        refreshList();
      });
    });

    /* ---- start ----------------------------------------------------------- */

    var preadd = (root.getAttribute('data-preadd') || '').trim();
    if (preadd !== '') {
      addStock(preadd, '').then(function (ok) {
        if (ok) { setStatus('ok', '<span><strong>Added to your order:</strong> ' + esc(preadd) + '.</span>'); }
        refreshList();
      });
    } else {
      refreshList();
    }
  });
})();
