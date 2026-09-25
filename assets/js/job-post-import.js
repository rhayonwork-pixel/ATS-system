/**
 * job-post-import.js — "Upload PDF description" on job-post.php.
 *
 * Progressive enhancement. With scripting off, the upload panel is an ordinary
 * multipart form that posts to job-post.php, which runs the same validation,
 * the same parser and the same field map and renders the filled form back. This
 * file only removes the page reload and adds the review affordances: per-field
 * badges, a summary banner and toasts.
 *
 * It never decides anything. Parsed values land in the form as editable text,
 * marked so the recruiter can see what to check, and nothing is saved until a
 * save button is pressed.
 */
(function () {
  'use strict';

  var cfgEl = document.getElementById('jp-config');
  var form = document.querySelector('[data-job-form]');
  if (!cfgEl || !form) return;

  var CFG;
  try { CFG = JSON.parse(cfgEl.textContent); } catch (e) { return; }

  var uploadForm = document.querySelector('[data-upload-form]');
  var dropzone = document.querySelector('[data-dropzone]');
  var input = document.querySelector('[data-dropzone-input]');
  var nameEl = document.querySelector('[data-dropzone-name]');
  var statusEl = document.querySelector('[data-upload-status]');
  var submitBtn = document.querySelector('[data-upload-submit]');
  var banner = document.querySelector('[data-review-banner]');

  /* ------------------------------------------------------------------ *
   * Toasts. Three error codes get three distinct messages, because
   * "something went wrong" tells a recruiter nothing about whether to try a
   * different file, retype the posting, or call IT.
   * ------------------------------------------------------------------ */
  var toastHost = null;
  function toast(kind, message, detail) {
    if (!toastHost) {
      toastHost = document.createElement('div');
      toastHost.className = 'jp-toasts';
      toastHost.setAttribute('role', 'status');
      toastHost.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastHost);
    }
    var el = document.createElement('div');
    el.className = 'jp-toast is-' + kind;
    var strong = document.createElement('strong');
    strong.textContent = message;
    el.appendChild(strong);
    if (detail) {
      var small = document.createElement('span');
      small.textContent = detail;
      el.appendChild(small);
    }
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'jp-toast-x';
    close.setAttribute('aria-label', 'Dismiss');
    close.textContent = '×';
    close.addEventListener('click', function () { remove(el); });
    el.appendChild(close);
    toastHost.appendChild(el);

    // Errors stay until dismissed: the person has to read them to know what to
    // do next. Successes clear themselves.
    if (kind !== 'error') window.setTimeout(function () { remove(el); }, 6000);
  }
  function remove(el) {
    el.classList.add('is-going');
    window.setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 200);
  }

  /* ------------------------------------------------------------------ *
   * Emoji stripping and bullet normalisation.
   *
   * This mirrors includes/text_sanitize.php exactly. The PHP copy is the
   * authoritative one -- it runs on every POST, including one that never met
   * this script -- and this copy exists so the person sees the result
   * immediately instead of discovering it after saving.
   *
   * Ranges deliberately excluded: en/em dash (salary ranges), smart quotes,
   * degree, plus-minus, multiplication and every currency sign. Those are
   * punctuation, and stripping "non-ASCII" would wreck real postings.
   * ------------------------------------------------------------------ */
  var EMOJI_RE = new RegExp(
    '[\\u{1F000}-\\u{1FAFF}' +      // pictographs, emoticons, transport, extended-A
    '\\u{2190}-\\u{21FF}' +         // arrows
    '\\u{2300}-\\u{23FF}' +         // misc technical
    '\\u{2460}-\\u{24FF}' +         // enclosed alphanumerics
    '\\u{25A0}-\\u{25FF}' +         // geometric shapes (bullets handled first)
    '\\u{2600}-\\u{27BF}' +         // misc symbols and dingbats
    '\\u{2B00}-\\u{2BFF}' +         // arrows and stars extended
    '\\u{FE0E}\\u{FE0F}\\u{200D}\\u{20E3}' +
    '\\u{E000}-\\u{F8FF}' +         // private use: Wingdings bullets
    '\\u{200B}-\\u{200C}\\u{FEFF}\\u{00AD}\\u{2060}]', 'gu');
  var FLAG_RE = /[\u{1F1E6}-\u{1F1FF}]{2}/gu;
  var TONE_RE = /[\u{1F3FB}-\u{1F3FF}]/gu;
  var BULLETS = '\u2022\u2023\u25CF\u25CB\u25AA\u25AB\u25A0\u25A1\u25E6\u2043\u2219\u00B7' +
                '\u2756\u27A4\u27A2\u279C\u2794\u2713\u2714\u2705\u2717\u2718\uF0B7\uF0A7';
  // One list marker: a run of glyph/dash/pipe/asterisk, OR a number, letter or
  // roman numeral closed by . ) or ]. It only counts at the start of a line and
  // must be followed by a space, so "e-commerce", "1.5 million", "Full-time"
  // and "24/7" are never touched.
  var MARKER = '(?:[' + BULLETS + '*+|\\-\u2013\u2014>]+' +
               '|\\(?(?:[0-9]{1,2}|[a-zA-Z]|[ivxlcIVXLC]{1,4})[.)\\]])';
  var MARKER_RE = new RegExp('^' + MARKER + '[ ]+');
  var MARKER_ONLY_RE = new RegExp('^' + MARKER + '[ ]*$');
  var ANY_BULLET_RE = new RegExp('[' + BULLETS + ']', 'g');

  /**
   * Mirror of strip_list_markers() + sanitize_job_text() in
   * includes/text_sanitize.php. The PHP copy is authoritative — it runs on
   * every POST, including ones this script never saw — and this exists so the
   * person sees the same result immediately.
   *
   * Markers go; the line break and the nesting stay. Indentation is snapped to
   * two spaces per level so ragged source indents come out regular.
   */
  function sanitizeText(value) {
    if (!value) return '';

    var rows = [];
    var indents = {};
    String(value).split(/\r\n|\r|\n/).forEach(function (raw) {
      var line = raw.replace(/\t/g, '    ');
      // Emoji first: a decorative one can sit in front of the real marker.
      line = line.replace(FLAG_RE, '').replace(TONE_RE, '').replace(EMOJI_RE, '');
      var lead = line.match(/^[ ]*/)[0].length;
      var body = line.slice(lead);

      for (var i = 0; i < 3; i++) {
        var stripped = body.replace(MARKER_RE, '');
        if (stripped !== body) { body = stripped; continue; }
        body = body.replace(MARKER_ONLY_RE, '');
        break;
      }
      body = body.replace(/\s+$/, '');
      if (body === '') { rows.push(null); return; }
      rows.push([lead, body]);
      indents[lead] = true;
    });

    var levels = Object.keys(indents).map(Number).sort(function (a, b) { return a - b; });
    var out = rows.map(function (row) {
      if (!row) return '';
      var depth = levels.indexOf(row[0]);
      return new Array(depth + 1).join('  ') + row[1];
    });

    return out.join('\n')
      .replace(/(\S)[ ]{2,}/g, '$1 ')
      .replace(/ +$/gm, '')
      .replace(/\n{3,}/g, '\n\n')
      .replace(/[ ]*\|[ ]*$/gm, '')
      .replace(/[ ]*\|[ ]*/g, ', ')
      .replace(ANY_BULLET_RE, ' ')
      .replace(/(\S)[ ]{2,}/g, '$1 ')
      .trim();
  }

  function sanitizeLine(value) {
    return sanitizeText(value).replace(/\s*\n\s*/g, ' ').replace(/\s{2,}/g, ' ').trim();
  }

  /**
   * Sanitise a control's value in place, preserving the caret.
   *
   * Runs on paste, change and blur -- never on every keystroke. Rewriting the
   * value as someone types moves the caret to the end and makes the field feel
   * broken, and there is nothing to gain: the emoji cannot reach the database
   * before one of these events fires.
   */
  function sanitizeControl(el) {
    if (!el || el.type === 'file' || el.type === 'checkbox' || el.tagName === 'SELECT') return;
    var before = el.value;
    var after = el.tagName === 'TEXTAREA' ? sanitizeText(before) : sanitizeLine(before);
    if (after === before) return;
    var caret = el.selectionStart;
    el.value = after;
    if (caret !== null && caret !== undefined) {
      var shift = before.length - after.length;
      var pos = Math.max(0, Math.min(after.length, caret - shift));
      try { el.setSelectionRange(pos, pos); } catch (e) { /* number/date inputs */ }
    }
    announce('Emoji and non-standard bullets were removed from ' + fieldLabel(el) + '.');
  }

  function fieldLabel(el) {
    var wrap = el.closest ? el.closest('.field') : null;
    var label = wrap && wrap.querySelector('label');
    return label ? label.textContent.replace(/\s+/g, ' ').trim().toLowerCase() : 'this field';
  }

  var liveRegion = null;
  function announce(message) {
    if (!liveRegion) {
      liveRegion = document.createElement('p');
      liveRegion.className = 'sr-only';
      liveRegion.setAttribute('role', 'status');
      liveRegion.setAttribute('aria-live', 'polite');
      document.body.appendChild(liveRegion);
    }
    liveRegion.textContent = message;
  }

  /* ------------------------------------------------------------------ *
   * Status lights.
   *
   * Empty = grey, filled = green, error = red. The dot is aria-hidden and a
   * visually hidden word beside it carries the same state, so the meaning does
   * not depend on seeing a colour.
   * ------------------------------------------------------------------ */
  function controlOf(name) {
    return form.querySelector('[name="' + name + '"]');
  }

  function isFilled(el) {
    if (!el) return false;
    if (el.type === 'checkbox') return el.checked;
    return String(el.value || '').trim() !== '';
  }

  function setLight(name, state) {
    var dot = form.querySelector('[data-light="' + name + '"]');
    var text = form.querySelector('[data-light-text="' + name + '"]');
    if (dot) dot.className = 'jp-light is-' + state;
    if (text) text.textContent = state === 'error' ? 'needs attention' : state;
  }

  function refreshLight(name) {
    var el = controlOf(name);
    if (!el) return;
    var wrap = el.closest('.field');
    if (wrap && wrap.classList.contains('is-invalid')) { setLight(name, 'error'); return; }
    setLight(name, isFilled(el) ? 'filled' : 'empty');
  }

  function refreshAllLights() {
    (CFG.fields || []).forEach(refreshLight);
  }

  // Debounced so a light does not repaint on every keystroke, and so a screen
  // reader is never told about a field mid-word.
  var lightTimers = {};
  function scheduleLight(name) {
    window.clearTimeout(lightTimers[name]);
    lightTimers[name] = window.setTimeout(function () { refreshLight(name); }, 120);
  }

  (CFG.fields || []).forEach(function (name) {
    var el = controlOf(name);
    if (!el) return;
    el.addEventListener('input', function () {
      if (el.closest('.field')) el.closest('.field').classList.remove('is-invalid');
      scheduleLight(name);
    });
    el.addEventListener('change', function () { sanitizeControl(el); refreshLight(name); });
    el.addEventListener('blur', function () { sanitizeControl(el); refreshLight(name); });
    el.addEventListener('paste', function () {
      // The pasted text is not in the value yet during the event.
      window.setTimeout(function () { sanitizeControl(el); refreshLight(name); }, 0);
    });
  });
  refreshAllLights();

  /* Required fields: mark them red only after a submit attempt, never while
     someone is still filling the form in.
     
     The inputs keep their `required` attribute, which is what protects the
     no-JavaScript path. Here it is switched off at the form level, because the
     browser's own validation aborts submission BEFORE a submit event fires --
     so the handler below never ran and no field ever turned red. With
     noValidate the same checks happen here, and they can also drive the status
     lights, which the native bubble cannot. */
  form.noValidate = true;
  form.addEventListener('submit', function (e) {
    var firstBad = null;
    (CFG.required || []).forEach(function (name) {
      var el = controlOf(name);
      if (!el) return;
      var wrap = el.closest('.field');
      if (!isFilled(el)) {
        if (wrap) wrap.classList.add('is-invalid');
        el.setAttribute('aria-invalid', 'true');
        setLight(name, 'error');
        if (!firstBad) firstBad = el;
      } else {
        if (wrap) wrap.classList.remove('is-invalid');
        el.removeAttribute('aria-invalid');
      }
    });
    if (firstBad) {
      e.preventDefault();
      // focus() does nothing on an element inside a hidden container, so the
      // form panel is revealed first. Otherwise the submit is blocked, the
      // field turns red, and the cursor is left on <body> with nothing to
      // show for it.
      showPanel('manual');
      firstBad.focus();
      toast('error', 'Some required fields are still empty.', 'They are marked in red.');
    }
  });

  /* ------------------------------------------------------------------ *
   * Clear form: confirm, clear, offer an undo.
   * ------------------------------------------------------------------ */
  (function clearForm() {
    var trigger = document.querySelector('[data-clear-form]');
    var overlay = document.querySelector('[data-modal-clear]');
    if (!trigger || !overlay) return;
    var cancel = overlay.querySelector('[data-clear-cancel]');
    var confirm = overlay.querySelector('[data-clear-confirm]');
    var lastFocus = null;

    function snapshot() {
      var state = { values: {}, token: '' };
      (CFG.fields || []).forEach(function (name) {
        var el = controlOf(name);
        if (!el) return;
        state.values[name] = el.type === 'checkbox' ? el.checked : el.value;
      });
      var token = form.querySelector('[name="source_pdf"]');
      state.token = token ? token.value : '';
      return state;
    }

    function anythingEntered() {
      return (CFG.fields || []).some(function (name) {
        var el = controlOf(name);
        return el && isFilled(el);
      });
    }

    function open() {
      // Confirming an empty form is noise, so it clears straight away.
      if (!anythingEntered()) { doClear(); return; }
      lastFocus = document.activeElement;
      overlay.hidden = false;
      (cancel || overlay).focus();
    }
    function close() {
      overlay.hidden = true;
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    function doClear() {
      var previous = snapshot();

      (CFG.fields || []).forEach(function (name) {
        var el = controlOf(name);
        if (!el) return;
        if (el.type === 'checkbox') el.checked = false;
        else if (el.tagName === 'SELECT') el.selectedIndex = 0;
        else el.value = '';
        var wrap = el.closest('.field');
        if (wrap) wrap.classList.remove('is-invalid');
        el.removeAttribute('aria-invalid');
      });

      // Auto-fill badges, hints, the review banner, the attachment token and
      // the dropzone are all part of "the form", so they go too.
      clearMarks();
      var token = form.querySelector('[name="source_pdf"]');
      if (token) token.value = '';
      if (banner) { banner.hidden = true; banner.classList.remove('is-on'); }
      if (input) { try { input.value = ''; } catch (e) {} }
      if (nameEl) { nameEl.hidden = true; nameEl.textContent = ''; }
      delete form.dataset.filled;
      refreshAllLights();

      var first = form.querySelector('input:not([type="hidden"]), textarea, select');
      if (first) first.focus();
      announce('All fields cleared. Undo is available for ten seconds.');

      // The undo is the real protection against a mis-click; the dialog only
      // slows one down.
      toastUndo(function () {
        Object.keys(previous.values).forEach(function (name) {
          var el = controlOf(name);
          if (!el) return;
          if (el.type === 'checkbox') el.checked = previous.values[name];
          else el.value = previous.values[name];
        });
        if (token) token.value = previous.token;
        refreshAllLights();
        announce('Your entries were restored.');
      });
    }

    trigger.addEventListener('click', open);
    if (cancel) cancel.addEventListener('click', close);
    if (confirm) confirm.addEventListener('click', function () { close(); doClear(); });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !overlay.hidden) close();
    });
    // Focus stays inside the dialog while it is open.
    overlay.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      var focusable = overlay.querySelectorAll('button, [href], input, select, textarea');
      if (!focusable.length) return;
      var first = focusable[0], last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
  })();

  function toastUndo(undo) {
    var el = document.createElement('div');
    el.className = 'jp-toast is-warn';
    var strong = document.createElement('strong');
    strong.textContent = 'Form cleared.';
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'jp-toast-undo';
    btn.textContent = 'Undo';
    var done = false;
    btn.addEventListener('click', function () {
      if (done) return;
      done = true;
      undo();
      remove(el);
    });
    el.appendChild(strong);
    el.appendChild(btn);
    if (!toastHost) toast('ok', '', '');          // makes sure the host exists
    toastHost.appendChild(el);
    window.setTimeout(function () { if (!done) remove(el); }, 10000);
  }

  /* ------------------------------------------------------------------ *
   * Dual entry: the tab bar is hidden in the markup and revealed here, so it
   * never appears as a dead control for someone without JavaScript.
   * ------------------------------------------------------------------ */
  var showPanel = function () {};        // replaced by tabs() below
  (function tabs() {
    var bar = document.querySelector('[data-entry-tabs]');
    if (!bar) return;
    bar.hidden = false;
    var buttons = [].slice.call(bar.querySelectorAll('[data-entry-tab]'));
    var panels = {
      upload: document.querySelector('[data-entry-panel="upload"]'),
      manual: document.querySelector('[data-entry-panel="manual"]')
    };

    function show(which) {
      buttons.forEach(function (b) {
        var on = b.dataset.entryTab === which;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      if (panels.upload) panels.upload.hidden = which !== 'upload';
      // The form is never hidden once it holds imported values — that is the
      // review step, and hiding it would look like the import was lost.
      if (panels.manual) panels.manual.hidden = (which !== 'manual' && !form.dataset.filled);
      if (which === 'manual' && panels.manual) {
        var first = panels.manual.querySelector('input, textarea, select');
        if (first) first.focus({ preventScroll: true });
      }
    }

    buttons.forEach(function (b) {
      b.addEventListener('click', function () { show(b.dataset.entryTab); });
      b.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        e.preventDefault();
        var i = buttons.indexOf(b);
        var next = buttons[(i + (e.key === 'ArrowRight' ? 1 : buttons.length - 1)) % buttons.length];
        next.focus();
        show(next.dataset.entryTab);
      });
    });

    showPanel = show;

    // A server-rendered import (the no-JS path) arrives with the form already
    // filled, so start on the form rather than hiding the recruiter's results.
    show(banner && !banner.hidden ? 'manual' : 'upload');
  })();

  /* ------------------------------------------------------------------ *
   * Dropzone
   * ------------------------------------------------------------------ */
  if (dropzone && input) {
    ['dragenter', 'dragover'].forEach(function (evt) {
      dropzone.addEventListener(evt, function (e) {
        e.preventDefault(); e.stopPropagation();
        dropzone.classList.add('is-over');
      });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
      dropzone.addEventListener(evt, function (e) {
        e.preventDefault(); e.stopPropagation();
        if (evt === 'dragleave' && dropzone.contains(e.relatedTarget)) return;
        dropzone.classList.remove('is-over');
      });
    });
    dropzone.addEventListener('drop', function (e) {
      var files = e.dataTransfer && e.dataTransfer.files;
      if (!files || !files.length) return;
      // DataTransfer to the input, so the no-JS submit path would still carry
      // the file if the upload below fails for any reason.
      try { input.files = files; } catch (err) { /* older browsers */ }
      accept(files[0]);
    });
    dropzone.addEventListener('click', function (e) {
      if (e.target !== input) input.click();
    });
    dropzone.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
    });
    input.addEventListener('change', function () {
      if (input.files && input.files[0]) accept(input.files[0]);
    });
  }

  function accept(file) {
    if (nameEl) {
      nameEl.hidden = false;
      nameEl.textContent = file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB';
    }
    // Both checks are repeated on the server; doing them here only saves the
    // person a round trip.
    var accepted = (CFG.accept || ['pdf']).some(function (ext) {
      return new RegExp('\\.' + ext + '$', 'i').test(file.name);
    });
    if (!accepted) {
      toast('error', CFG.messages.ERR_FILE_FORMAT, file.name);
      return;
    }
    if (file.size > CFG.maxBytes) {
      toast('error', 'That PDF is larger than 5MB.', 'Try exporting it again at a smaller size.');
      return;
    }
    upload(file);
  }

  /* ------------------------------------------------------------------ *
   * Upload + parse
   * ------------------------------------------------------------------ */
  var busy = false;
  function upload(file) {
    if (busy) return;
    busy = true;
    setBusy(true, 'Reading your file…');

    var body = new FormData();
    body.append('csrf', CFG.csrf);
    body.append('job_pdf', file);

    fetch(CFG.endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) {
        // includes/auth.php calls require_login() at include time, so an
        // expired session answers this POST with a 302 to login.php rather
        // than JSON. Saying so is far more useful than "we could not reach
        // the server", which is what the JSON parse failure would produce.
        if (r.redirected && /login\.php/.test(r.url)) throw new Error('session');
        return r.text().then(function (text) {
          try { return { status: r.status, data: JSON.parse(text) }; }
          catch (e) { throw new Error('bad-json:' + r.status); }
        });
      })
      .then(function (res) {
        var data = res.data;
        if (!data.ok) {
          var msg = CFG.messages[data.error_code] || data.message || CFG.messages.ERR_FILE_FORMAT;
          toast('error', msg, data.error_code === 'ERR_UNREADABLE'
            ? 'You can still write the posting by hand — switch to "Fill in manually".' : '');
          return;
        }
        var count = fill(data);
        if (data.notice) {
          toast('warn', data.notice, count ? count + ' field' + (count === 1 ? '' : 's') + ' were filled in.' : '');
        } else {
          toast('ok', 'PDF read. ' + count + ' field' + (count === 1 ? '' : 's') + ' filled in.',
                'Check the highlighted fields before saving.');
        }
        (data.warnings || []).forEach(function (w) { toast('warn', w, ''); });
      })
      .catch(function (err) {
        if (err && err.message === 'session') {
          toast('error', CFG.messages.ERR_SESSION, 'Your work on this form is still here — sign in again in another tab, then retry the upload.');
        } else {
          toast('error', CFG.messages.ERR_NETWORK, 'Nothing was saved.');
        }
      })
      .then(function () {
        busy = false;
        setBusy(false, '');
      });
  }

  function setBusy(on, message) {
    if (submitBtn) submitBtn.disabled = on;
    if (dropzone) dropzone.classList.toggle('is-busy', on);
    if (statusEl) statusEl.textContent = message;
    if (on) announce(message);
  }

  /* ------------------------------------------------------------------ *
   * Fill the form
   * ------------------------------------------------------------------ */
  function fill(data) {
    clearMarks();
    var filled = 0;

    Object.keys(CFG.map).forEach(function (from) {
      var value = data.fields[from];
      if (!value) return;
      var name = CFG.map[from];

      // A deadline the parser could not read without guessing arrives as a
      // phrase, not a date. Putting it in a date input would silently drop it,
      // so it is reported instead.
      if (name === 'application_deadline' && !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        hint('application_deadline', 'The PDF said “' + value + '” — please set the date yourself.');
        return;
      }
      if (setValue(name, sanitizeText(value))) filled++;
    });

    if (data.department_id) {
      if (setValue('department_id', String(data.department_id))) filled++;
    } else if (data.department_text) {
      hint('department_id', 'The PDF said “' + data.department_text +
           '” — no matching department exists, so please pick one.');
    }

    var token = form.querySelector('input[name="source_pdf"]');
    if (token) token.value = data.token || '';       // pasted text has no file
    form.dataset.filled = '1';
    refreshAllLights();

    if (banner) {
      banner.hidden = false;
      banner.classList.add('is-on');
      var title = banner.querySelector('[data-review-title]');
      if (title) title.textContent = filled + ' field' + (filled === 1 ? '' : 's') + ' filled in from your PDF.';
    }

    // Show the form and put the person at the top of it: the import is done,
    // the review is the next thing to do.
    var manual = document.querySelector('[data-entry-panel="manual"]');
    if (manual) manual.hidden = false;
    var tab = document.querySelector('[data-entry-tab="manual"]');
    if (tab) tab.click();
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return filled;
  }

  function field(name) {
    return form.querySelector('[data-field="' + name + '"]');
  }

  function setValue(name, value) {
    var el = form.querySelector('[name="' + name + '"]');
    if (!el) return false;
    if (el.tagName === 'SELECT') {
      var found = [].slice.call(el.options).some(function (o) { return o.value === value; });
      if (!found) return false;
    }
    el.value = value;
    markAutoFilled(name);
    return true;
  }

  /* ------------------------------------------------------------------ *
   * Review mode.
   *
   * A field the parser filled is highlighted — blue border, pale blue wash and
   * an "Auto-filled" badge — so the reviewer can see at a glance what the
   * system wrote and what is still theirs to do. Empty fields stay in the
   * default state, which is the whole point of the contrast.
   *
   * The highlight clears the moment the person puts the cursor in the field:
   * at that instant they are verifying it, so the prompt has done its job and
   * hanging on to it would just make the form noisy while they work.
   * ------------------------------------------------------------------ */
  var CHECK_SVG = '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false">' +
    '<path d="M6.2 11.6 2.8 8.2l1.1-1.1 2.3 2.3 5.9-5.9 1.1 1.1z" fill="currentColor"/></svg>';

  function markAutoFilled(name) {
    var wrap = field(name);
    if (!wrap || wrap.classList.contains('auto-filled')) return;
    wrap.classList.add('auto-filled');

    var label = wrap.querySelector('label');
    if (label && !label.querySelector('.auto-filled-badge')) {
      var badge = document.createElement('span');
      badge.className = 'auto-filled-badge';
      // The badge is decorative: the sentence below it is what a screen reader
      // reads, so the state never depends on seeing a colour or an icon.
      badge.setAttribute('aria-hidden', 'true');
      badge.innerHTML = CHECK_SVG + '<span>Auto-filled</span>';
      label.appendChild(badge);

      var sr = document.createElement('span');
      sr.className = 'sr-only';
      sr.setAttribute('data-auto-sr', '');
      sr.textContent = ' Auto-filled from your document, please verify.';
      label.appendChild(sr);
    }

  }

  /*
   * Clearing is DELEGATED on the form rather than bound per control, for two
   * reasons. The no-JavaScript import renders its highlighted fields in PHP,
   * and those never passed through markAutoFilled(), so a per-control listener
   * left them highlighted forever with no way to clear. And `focus` does not
   * bubble, so the delegated version listens for `focusin`, which does.
   *
   * pointerdown is there as well: it fires before focus settles, so the
   * highlight lifts the instant the field is clicked rather than a frame later.
   */
  ['focusin', 'pointerdown'].forEach(function (evt) {
    form.addEventListener(evt, function (e) {
      var wrap = e.target.closest ? e.target.closest('.field.auto-filled') : null;
      if (wrap) clearAutoFilled(wrap);
    });
  });

  function clearAutoFilled(wrap) {
    if (!wrap || !wrap.classList.contains('auto-filled')) return;
    wrap.classList.remove('auto-filled');
    wrap.classList.add('auto-filled-leaving');     // runs the transition out
    var badge = wrap.querySelector('.auto-filled-badge');
    var sr = wrap.querySelector('[data-auto-sr]');
    if (badge) badge.parentNode.removeChild(badge);
    if (sr) sr.parentNode.removeChild(sr);
    window.setTimeout(function () { wrap.classList.remove('auto-filled-leaving'); }, 260);
  }


  /**
   * Remove the leftovers of a previous import: the "the PDF said X" hints that
   * sit under a field the parser could not fill confidently. There are no
   * badges or tints to clear any more — an auto-filled field is visually
   * identical to a typed one.
   */
  function clearMarks() {
    [].slice.call(form.querySelectorAll('.jp-hint')).forEach(function (h) {
      h.parentNode.removeChild(h);
    });
    [].slice.call(form.querySelectorAll('.field.auto-filled')).forEach(clearAutoFilled);
  }

  function hint(name, text) {
    var wrap = field(name);
    if (!wrap) return;
    var el = document.createElement('span');
    el.className = 'hint jp-hint';
    el.textContent = text;
    wrap.appendChild(el);
  }

  /* Submitting the upload form by keyboard still works: it posts normally,
     unless fetch is available, in which case we intercept it. */
  if (uploadForm) {
    uploadForm.addEventListener('submit', function (e) {
      if (!window.fetch || !input || !input.files || !input.files[0]) return;   // let it post
      e.preventDefault();
      accept(input.files[0]);
    });
  }
})();
