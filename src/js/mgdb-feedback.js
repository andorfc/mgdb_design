/* ==========================================================================
   MaizeGDB Modern — feedback form
   --------------------------------------------------------------------------
   Progressive enhancement, in two parts.

   1. Any page: the Feedback button in the header, and any other link carrying
      one of the legacy trigger classes, opens a dialog holding the form. The
      markup is fetched from /feedback?embed=form, so the dialog and the page
      cannot drift apart. Without this file — or without <dialog>, or with the
      fetch failing — the link follows its href to /feedback and the form works
      there.

      Two kinds of report share this. `.feedback-form` is site feedback;
      `.trigger_gene_model_issue_form` is the gene model and assembly error
      report, which goes to a different collector and carries the gene model it
      is about. Both classes are the ones the legacy Atlassian collectors bound
      to, so a link that still carries either keeps working.

   2. /feedback itself: the form posts through fetch so the answer arrives
      without a page load, with the character count and the environment note
      filled in. Without this file the same form posts normally and the
      controller redirects back with a confirmation.

   Nothing here validates on the reader's behalf. The controller checks every
   field again, and it is the only place that decides whether a message is sent,
   so the browser-side messages are a convenience rather than the gate.
   ========================================================================== */

(function (window, document) {
  'use strict';

  var EMBED_URL   = '/feedback?embed=form';
  var POST_URL    = '/feedback';
  var MAX_DETAILS = 6000;   /* must match MGDB_FEEDBACK_MAX_DETAILS */

  var dialog = null;
  var dialogBody = null;
  var dialogTitle = null;
  var dialogIntro = null;
  var lastTrigger = null;
  /* What the dialog is currently holding: '<kind>|<id>', or '' for nothing.
     A different kind or a different gene model has to be fetched again. */
  var loadedKey = '';

  function can(feature) {
    return typeof feature === 'function';
  }

  /* ------------------------------------------------------------------ form */

  /* Everything that makes one form instance work. Called for the page's own
     form on load, and again for the copy inside the dialog each time it is
     filled, since that markup arrives after this script has run. */
  function enhanceForm(form) {
    if (!form || form.getAttribute('data-feedback-ready')) { return; }
    form.setAttribute('data-feedback-ready', '1');

    var started = form.querySelector('#feedback-started');
    if (started) { started.value = String(Date.now()); }

    setupCounter(form);
    prefillPage(form);

    form.addEventListener('submit', function (event) {
      if (!can(window.fetch) || !window.FormData || !window.URLSearchParams) {
        return;   /* no fetch: let the browser post the form itself */
      }
      event.preventDefault();
      send(form);
    });

    form.addEventListener('reset', function () {
      window.setTimeout(function () {
        clearErrors(form);
        setStatus(form, '', '');
        setupCounter(form);
      }, 0);
    });
  }

  function setupCounter(form) {
    var details = form.querySelector('#feedback-details');
    var count = form.querySelector('#feedback-count');
    if (!details || !count) { return; }

    function paint() {
      var used = details.value.length;
      var left = MAX_DETAILS - used;
      count.hidden = used === 0;
      count.textContent = left > 500
        ? used.toLocaleString() + ' characters'
        : left.toLocaleString() + ' characters left';
      if (left <= 500) {
        count.setAttribute('data-near-limit', '');
      } else {
        count.removeAttribute('data-near-limit');
      }
    }

    if (!details.getAttribute('data-counter-bound')) {
      details.setAttribute('data-counter-bound', '1');
      details.addEventListener('input', paint);
    }
    paint();
  }

  /* The controller fills this from the referring page, which covers both the
     page form and the dialog's fetch. The one case it cannot: a browser that
     sends no referrer. Only the dialog can fill that in, because there the
     page being read *is* the page concerned — on /feedback itself the current
     URL is the form, which is not what the field is asking for. */
  function prefillPage(form) {
    var page = form.querySelector('#feedback-page');
    if (page && page.value === '' && form.getAttribute('data-feedback-dialog-form')) {
      page.value = window.location.href;
    }
  }

  /* What the consent checkbox promises: the browser and the shape of the
     window, which is what a display problem usually turns on. Sent only when
     the box is ticked, and never anything the reader has not been shown. */
  function environmentNote(form) {
    var consent = form.querySelector('#feedback-env');
    var field = form.querySelector('#feedback-webinfo');
    if (!field) { return; }
    if (consent && !consent.checked) {
      field.value = '';
      return;
    }

    var lines = [];
    if (window.screen && window.screen.width) {
      lines.push('Screen: ' + window.screen.width + ' x ' + window.screen.height);
    }
    lines.push('Window: ' + window.innerWidth + ' x ' + window.innerHeight);
    if (window.navigator && window.navigator.language) {
      lines.push('Language: ' + window.navigator.language);
    }
    field.value = lines.join('\n');
  }

  function send(form) {
    if (form.getAttribute('data-sending')) { return; }

    environmentNote(form);
    clearErrors(form);
    setStatus(form, '', '');

    var button = form.querySelector('#feedback-submit');
    var buttonText = button ? button.textContent : '';
    form.setAttribute('data-sending', '1');
    if (button) {
      button.disabled = true;
      button.textContent = 'Sending…';
    }

    function done() {
      form.removeAttribute('data-sending');
      if (button) {
        button.disabled = false;
        button.textContent = buttonText;
      }
    }

    window.fetch(POST_URL, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: new window.URLSearchParams(new window.FormData(form)).toString(),
      credentials: 'same-origin'
    }).then(function (response) {
      return response.json();
    }).then(function (result) {
      done();
      if (result && result.ok) {
        showSent(form, result.key || '');
        return;
      }
      showErrors(form, result || {});
    })['catch'](function () {
      done();
      setStatus(form, 'error',
        'The message could not be sent from this browser. Try again, or write to ' +
        'mgdb-tech@iastate.edu.');
    });
  }

  /* -------------------------------------------------------------- messages */

  function setStatus(form, tone, message) {
    var status = form.querySelector('#feedback-status');
    if (!status) { return; }

    if (!message) {
      status.hidden = true;
      status.innerHTML = '';
      return;
    }

    var box = document.createElement('div');
    box.className = 'mgdb-message ' + (tone === 'error' ? 'mgdb-message-error' : 'mgdb-message-info');
    box.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    var body = document.createElement('div');
    body.textContent = message;
    box.appendChild(body);

    status.innerHTML = '';
    status.appendChild(box);
    status.hidden = false;
  }

  function clearErrors(form) {
    var fields = form.querySelectorAll('[aria-invalid="true"]');
    Array.prototype.forEach.call(fields, function (field) {
      field.removeAttribute('aria-invalid');
      var described = (field.getAttribute('aria-describedby') || '')
        .split(/\s+/)
        .filter(function (id) { return id && id.indexOf('-error') === -1; })
        .join(' ');
      if (described) {
        field.setAttribute('aria-describedby', described);
      } else {
        field.removeAttribute('aria-describedby');
      }
    });

    var messages = form.querySelectorAll('.mgdb-field-error');
    Array.prototype.forEach.call(messages, function (message) {
      message.parentNode.removeChild(message);
    });
  }

  var FIELD_IDS = {
    summary:     'feedback-summary',
    details:     'feedback-details',
    models:      'feedback-models',
    publication: 'feedback-publication',
    page:        'feedback-page',
    name:        'feedback-name',
    email:       'feedback-email'
  };

  function showErrors(form, result) {
    var errors = result.errors || {};
    var first = null;

    Object.keys(FIELD_IDS).forEach(function (key) {
      if (!errors[key]) { return; }

      var field = form.querySelector('#' + FIELD_IDS[key]);
      if (!field) { return; }

      var message = document.createElement('p');
      message.className = 'mgdb-field-error';
      message.id = 'feedback-' + key + '-error';
      message.textContent = errors[key];

      /* After the control, and after the character count when there is one, so
         the error is the last thing under the field. */
      var holder = field.closest ? field.closest('.mgdb-field') : field.parentNode;
      holder.appendChild(message);

      field.setAttribute('aria-invalid', 'true');
      var described = field.getAttribute('aria-describedby');
      field.setAttribute('aria-describedby', described ? described + ' ' + message.id : message.id);

      if (!first) { first = field; }
    });

    var note = result.message || 'Check the highlighted fields and send again.';
    setStatus(form, 'error', note);

    if (first) {
      first.focus();
    } else {
      var status = form.querySelector('#feedback-status');
      if (status && status.scrollIntoView) { status.scrollIntoView({ block: 'nearest' }); }
    }
  }

  /* The form is replaced rather than cleared: a confirmation that sits above an
     identical, still-filled form reads as though nothing happened. */
  function showSent(form, key) {
    var panel = document.createElement('div');
    panel.className = 'mgdb-feedback-sent';
    /* "Send another" has to ask for the same kind of form again. */
    panel.setAttribute('data-feedback-kind', form.getAttribute('data-feedback-kind') || 'site');
    var models = form.querySelector('#feedback-models');
    if (models) { panel.setAttribute('data-feedback-id', models.value); }

    var box = document.createElement('div');
    box.className = 'mgdb-message mgdb-message-ok';
    box.setAttribute('role', 'status');
    var body = document.createElement('div');
    var strong = document.createElement('strong');
    strong.textContent = key
      ? 'Thank you — your message reached the MaizeGDB issue queue as ' + key + '.'
      : 'Thank you — your message reached the MaizeGDB issue queue.';
    body.appendChild(strong);
    body.appendChild(document.createTextNode(' If you left an address, someone will reply to it.'));
    box.appendChild(body);
    panel.appendChild(box);

    var actions = document.createElement('div');
    actions.className = 'mgdb-form-actions';

    var again = document.createElement('button');
    again.type = 'button';
    again.className = 'mgdb-button mgdb-button-secondary';
    again.textContent = 'Send another';
    again.addEventListener('click', function () {
      if (dialog && dialog.contains(panel)) {
        fillDialog(panel.getAttribute('data-feedback-kind') || 'site',
                   panel.getAttribute('data-feedback-id') || '', true);
      } else {
        window.location.href = window.location.pathname + window.location.search;
      }
    });
    actions.appendChild(again);

    if (dialog && dialog.contains(form)) {
      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'mgdb-button mgdb-button-quiet';
      close.textContent = 'Close';
      close.addEventListener('click', closeDialog);
      actions.appendChild(close);
    }

    panel.appendChild(actions);
    form.parentNode.replaceChild(panel, form);

    /* The dialog keeps its markup between openings. Now that the form has been
       replaced by this panel, the next opening has to fetch a fresh one. */
    if (dialog && dialog.contains(panel)) { loadedKey = ''; }

    var heading = panel.querySelector('strong');
    if (heading && heading.scrollIntoView) { heading.scrollIntoView({ block: 'nearest' }); }
    again.focus();
  }

  /* -------------------------------------------------------------- dialog */

  function buildDialog() {
    if (dialog) { return dialog; }

    dialog = document.createElement('dialog');
    /* mgdb-page is not decoration: it carries the shared reset — box-sizing
       above all — that every control in the form is authored against, and
       which does not reach a child of <body> otherwise. */
    dialog.className = 'mgdb-feedback-dialog mgdb-page';
    dialog.id = 'mgdb-feedback-dialog';
    dialog.setAttribute('aria-labelledby', 'mgdb-feedback-dialog-title');

    var head = document.createElement('div');
    head.className = 'mgdb-feedback-dialog-head';

    var titles = document.createElement('div');
    dialogTitle = document.createElement('h2');
    dialogTitle.id = 'mgdb-feedback-dialog-title';
    dialogTitle.textContent = 'Send feedback to MaizeGDB';
    dialogIntro = document.createElement('p');
    dialogIntro.textContent = 'Goes to the MaizeGDB issue queue. Nothing you type here leaves the page until you send it.';
    titles.appendChild(dialogTitle);
    titles.appendChild(dialogIntro);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'mgdb-feedback-dialog-close';
    close.setAttribute('aria-label', 'Close feedback');
    close.innerHTML = '&times;';
    close.addEventListener('click', closeDialog);

    head.appendChild(titles);
    head.appendChild(close);

    dialogBody = document.createElement('div');
    dialogBody.className = 'mgdb-feedback-dialog-body';

    dialog.appendChild(head);
    dialog.appendChild(dialogBody);
    document.body.appendChild(dialog);

    /* A click on the backdrop lands on the dialog element itself, since the
       body fills it. Anything inside stops at its own target. */
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) { closeDialog(); }
    });

    dialog.addEventListener('close', function () {
      if (lastTrigger && lastTrigger.focus) { lastTrigger.focus(); }
      lastTrigger = null;
    });

    dialogBody.addEventListener('click', function (event) {
      var target = event.target.closest ? event.target.closest('[data-feedback-close]') : null;
      if (target) { closeDialog(); }
    });

    return dialog;
  }

  function embedUrl(kind, id) {
    var url = EMBED_URL;
    if (kind && kind !== 'site') { url += '&kind=' + encodeURIComponent(kind); }
    if (id) { url += '&id=' + encodeURIComponent(id); }
    return url;
  }

  /* The dialog heading is not hardcoded here: the fetched form carries the
     words for its own kind, so there is one place that says what each report
     is called. */
  function labelDialog(form) {
    if (!form) { return; }
    var title = form.getAttribute('data-feedback-title');
    var intro = form.getAttribute('data-feedback-intro');
    if (title) { dialogTitle.textContent = title; }
    if (intro) { dialogIntro.textContent = intro; }
  }

  function fillDialog(kind, id, force) {
    var key = kind + '|' + (id || '');
    if (loadedKey === key && !force) { return; }

    dialogBody.innerHTML =
      '<div class="mgdb-feedback-dialog-loading"><span class="mgdb-spinner" aria-hidden="true"></span>' +
      '<span>Loading the form…</span></div>';

    window.fetch(embedUrl(kind, id), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin'
    }).then(function (response) {
      if (!response.ok) { throw new Error('HTTP ' + response.status); }
      return response.text();
    }).then(function (markup) {
      dialogBody.innerHTML = markup;
      loadedKey = key;
      var form = dialogBody.querySelector('#feedback-form');
      labelDialog(form);
      enhanceForm(form);
      focusFirst(form);
    })['catch'](function () {
      loadedKey = '';
      dialogBody.innerHTML = '';
      var box = document.createElement('div');
      box.className = 'mgdb-message mgdb-message-error';
      box.setAttribute('role', 'alert');
      var body = document.createElement('div');
      body.appendChild(document.createTextNode('The form could not be loaded. '));
      var link = document.createElement('a');
      link.href = '/feedback';
      link.textContent = 'Open the feedback page';
      body.appendChild(link);
      body.appendChild(document.createTextNode(' instead.'));
      box.appendChild(body);
      dialogBody.appendChild(box);
    });
  }

  function focusFirst(form) {
    if (!form) { return; }
    var target = form.querySelector('input[name="feedback_type"]:checked')
              || form.querySelector('#feedback-models')
              || form.querySelector('#feedback-summary');
    if (target && target.focus) { target.focus(); }
  }

  function openDialog(trigger, kind, id) {
    buildDialog();
    lastTrigger = trigger || null;
    fillDialog(kind, id, false);
    if (!dialog.open) { dialog.showModal(); }
    if (loadedKey) { focusFirst(dialogBody.querySelector('#feedback-form')); }
  }

  function closeDialog() {
    if (dialog && dialog.open) { dialog.close(); }
  }

  /* -------------------------------------------------------------- wiring */

  function pageForm() {
    var form = document.getElementById('feedback-form');
    if (form && dialog && dialog.contains(form)) { return null; }
    return form;
  }

  /* Which report a link asks for. The class is what the legacy collectors bound
     to and is still the reliable signal on older markup; data-mgdb-feedback is
     the explicit form for anything new. */
  function triggerKind(trigger) {
    var stated = trigger.getAttribute('data-mgdb-feedback');
    if (stated) { return stated; }
    return trigger.className && trigger.className.indexOf('trigger_gene_model_issue_form') !== -1
      ? 'gene_model'
      : 'site';
  }

  /* The gene model a record page is reporting on. A link may state it, or carry
     it in its own href, which is also what makes the link work without this
     script. */
  function triggerId(trigger) {
    var stated = trigger.getAttribute('data-feedback-id');
    if (stated) { return stated; }
    var href = trigger.getAttribute('href') || '';
    var match = href.match(/[?&]id=([^&#]+)/);
    return match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
  }

  function supportsDialog() {
    return can(window.HTMLDialogElement)
        && can(window.HTMLDialogElement.prototype.showModal)
        && can(window.fetch);
  }

  function init() {
    var onPage = pageForm();
    if (onPage) { enhanceForm(onPage); }

    /* Delegated, so links added later — and the ones the megamenu templates
       render — are all covered by one listener. */
    document.addEventListener('click', function (event) {
      var trigger = event.target.closest
        ? event.target.closest('.feedback-form, .trigger_gene_model_issue_form, [data-mgdb-feedback]')
        : null;
      if (!trigger) { return; }

      var kind = triggerKind(trigger);
      var id = triggerId(trigger);

      /* On /feedback the form is already on the page. Opening a second copy in
         a dialog would put a duplicate of every field id in the document, so
         the link scrolls to the real one instead — but only when it is the same
         kind of form. A gene model report opened from the site feedback page
         still needs its own dialog.

         The dialog stays in the document after it closes, and its form carries
         the same id — so this has to ask for a form that is *not* in the
         dialog. Asking only for the id meant the second click on any page
         found the dialog's own form and scrolled to a closed dialog. */
      var here = pageForm();
      if (here && (here.getAttribute('data-feedback-kind') || 'site') === kind) {
        event.preventDefault();
        here.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var first = here.querySelector('#feedback-models')
                 || here.querySelector('#feedback-summary');
        if (first && first.focus) { first.focus({ preventScroll: true }); }
        return;
      }

      if (!supportsDialog()) { return; }   /* follow the href to /feedback */

      event.preventDefault();
      openDialog(trigger, kind, id);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window, document);
