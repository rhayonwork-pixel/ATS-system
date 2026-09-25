/**
 * apply-form.js — the phone field, the resume drop zone and the submit on
 * apply.php.
 *
 * Progressive enhancement throughout. With JavaScript off the form is an
 * ordinary multipart POST: the file input is a real, focusable control, the
 * phone input has a native `pattern`, and apply.php validates everything again
 * on the server. None of what follows is required to apply.
 *
 * How validation reaches the user: every rule here calls setCustomValidity()
 * on the input it belongs to. The browser then refuses to submit and shows its
 * own message at that field, and the `submit` event below only fires once the
 * whole form is valid. The same message is also written into the inline error
 * beside the field and the input is marked aria-invalid, so a screen reader and
 * a sighted user get the same information in the same place.
 */
(function () {
  'use strict';

  var form = document.getElementById('apply-form');
  if (!form) return;

  var ALLOWED_EXT = ['pdf', 'doc', 'docx'];
  // What browsers report for those extensions. An empty type is allowed as
  // well: Windows frequently reports none at all for .doc, and the extension
  // check plus the server's own MIME sniffing still stand.
  var ALLOWED_MIME = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ''
  ];

  /* ------------------------------------------------------------------ *
   * Shared: mark a field invalid or clear it, in one place.
   * ------------------------------------------------------------------ */
  function setFieldError(input, errorEl, wrap, message) {
    if (input) {
      input.setCustomValidity(message || '');
      if (message) input.setAttribute('aria-invalid', 'true');
      else input.removeAttribute('aria-invalid');
    }
    if (errorEl) errorEl.textContent = message || '';
    if (wrap) wrap.classList.toggle(wrap.dataset.invalidClass || 'invalid', !!message);
  }

  /* ------------------------------------------------------------------ *
   * Phone
   *
   * The same rule apply.php enforces: only digits and the separators people
   * actually type, then 6 to 14 digits. Deliberately loose on length —
   * countries differ, and the aim is to catch typos, not to reject a valid
   * number for not looking Philippine.
   * ------------------------------------------------------------------ */
  var PHONE_CHARS = /^[0-9 ()+.\-]+$/;
  var phoneCountry = document.getElementById('f-phone-country');
  var phoneDial = document.getElementById('phone-dial-display');
  var phoneInput = document.getElementById('f-phone-number');
  var phoneWrap = document.querySelector('[data-phone-field]');
  var phoneError = document.getElementById('phone-error');
  var phoneValid = document.getElementById('phone-valid-msg');
  var PLACEHOLDERS = {
    PH: '917 123 4567', US: '(555) 123-4567', CA: '(555) 123-4567', GB: '7911 123456',
    JP: '90 1234 5678', KR: '10 1234 5678', SG: '9123 4567', AU: '412 345 678',
    DE: '151 23456789', FR: '6 12 34 56 78', IN: '98765 43210'
  };
  var phoneTouched = false;

  function phoneMessage(value) {
    var v = value.trim();
    if (v === '') return '';                                  // optional field
    if (!PHONE_CHARS.test(v)) return 'Use only numbers, spaces, brackets, dots or dashes.';
    var digits = v.replace(/\D/g, '').length;
    if (digits < 6) return 'That number looks too short — enter at least 6 digits.';
    if (digits > 14) return 'That number looks too long — enter no more than 14 digits.';
    return '';
  }

  function validatePhone(show) {
    if (!phoneInput) return true;
    var message = phoneMessage(phoneInput.value);
    // While someone is still typing their first attempt, the error waits for
    // them to leave the field; after that it updates live.
    setFieldError(phoneInput, phoneError, phoneWrap, (show || phoneTouched) ? message : '');
    if (!show && !phoneTouched) phoneInput.setCustomValidity(message);  // still block submit
    if (phoneValid) phoneValid.hidden = !(phoneInput.value.trim() !== '' && message === '');
    return message === '';
  }

  function updateDial() {
    if (!phoneCountry || !phoneDial) return;
    var opt = phoneCountry.options[phoneCountry.selectedIndex];
    phoneDial.textContent = '+' + opt.getAttribute('data-dial');
    if (phoneInput) phoneInput.placeholder = PLACEHOLDERS[opt.value] || 'Your number, without the country code';
  }

  if (phoneCountry) phoneCountry.addEventListener('change', function () { updateDial(); validatePhone(false); });
  if (phoneInput) {
    phoneInput.addEventListener('input', function () { validatePhone(false); });
    phoneInput.addEventListener('blur', function () {
      if (phoneInput.value.trim() !== '') phoneTouched = true;
      validatePhone(true);
    });
  }
  // When the browser itself blocks the submit it fires `invalid` on the field.
  // Without this, the only feedback would be the native bubble, which vanishes
  // after a few seconds and is never read out by some screen readers.
  if (phoneInput) phoneInput.addEventListener('invalid', function () { phoneTouched = true; validatePhone(true); });
  updateDial();
  // A server-side error arrives already marked; keep it until the user edits.
  if (phoneInput && phoneInput.getAttribute('aria-invalid') === 'true') phoneTouched = true;
  else validatePhone(false);

  /* ------------------------------------------------------------------ *
   * Resume: two states in one zone.
   *
   *   EMPTY     the prompt; the invisible file input covers the zone and is
   *             the tab stop.
   *   ATTACHED  the prompt is removed entirely and a file card replaces it —
   *             name, type, size, a "ready" mark, Replace and Remove. The
   *             input keeps the file but stops covering the zone and leaves
   *             the tab order, so the buttons can be clicked and reached.
   * ------------------------------------------------------------------ */
  var zone = document.querySelector('[data-resume-zone]');
  var resumeInput = document.querySelector('[data-resume-input]');
  var promptEl = document.querySelector('[data-resume-prompt]');
  var cardEl = document.querySelector('[data-resume-card]');
  var nameEl = document.querySelector('[data-resume-name]');
  var detailEl = document.querySelector('[data-resume-detail]');
  var readyTextEl = document.querySelector('[data-resume-ready-text]');
  var extEl = document.querySelector('[data-resume-ext]');
  var replaceBtn = document.querySelector('[data-resume-replace]');
  var removeBtn = document.querySelector('[data-resume-remove]');
  var resumeError = document.getElementById('resume-error');
  var resumeStatus = document.querySelector('[data-resume-status]');
  if (zone) zone.dataset.invalidClass = 'is-invalid';

  // The limit comes from the server (DOC_MAX_BYTES, printed into the page), so
  // what this checks and what apply.php enforces cannot drift apart.
  var MAX_BYTES = (resumeInput && parseInt(resumeInput.getAttribute('data-max-bytes'), 10)) || (10 * 1024 * 1024);
  var MAX_LABEL = Math.round(MAX_BYTES / (1024 * 1024)) + 'MB';

  // The last file that passed the checks. A rejected REPLACEMENT puts this one
  // back, so choosing a bad file never throws away the good one already there.
  var lastGood = null;

  var TYPE_LABEL = {
    pdf: 'PDF document',
    docx: 'Word document',
    doc: 'Word 97–2003 document'
  };

  function fileExt(name) {
    var m = /\.([a-z0-9]+)$/i.exec(name || '');
    return m ? m[1].toLowerCase() : '';
  }

  /** 240 KB, 1.4 MB — binary units, which is what "5MB" means on every upload form. */
  function humanSize(bytes) {
    if (bytes < 1024) return bytes + ' bytes';
    if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' KB';
    var mb = bytes / (1024 * 1024);
    return (mb < 10 ? mb.toFixed(1) : Math.round(mb)) + ' MB';
  }

  /** Why this file cannot be sent, or '' if it can. */
  function resumeProblem(file) {
    var ext = fileExt(file.name);
    if (ALLOWED_EXT.indexOf(ext) === -1 || ALLOWED_MIME.indexOf(file.type || '') === -1) {
      return '"' + file.name + '" is not a PDF, DOC or DOCX file. Please choose a resume in one of those formats.';
    }
    if (file.size === 0) return '"' + file.name + '" is empty. Please choose a different file.';
    if (file.size > MAX_BYTES) {
      return '"' + file.name + '" is ' + humanSize(file.size) + ', over the ' + MAX_LABEL +
             ' limit. Try saving it as a PDF, which is usually much smaller.';
    }
    return '';
  }

  function announce(text) {
    if (!resumeStatus) return;
    // Clear first so repeating the same message is still announced.
    resumeStatus.textContent = '';
    window.setTimeout(function () { resumeStatus.textContent = text; }, 40);
  }

  /** Put a File object into the real input, so the form submits it. */
  function setInputFile(file) {
    try {
      var dt = new DataTransfer();
      if (file) dt.items.add(file);
      resumeInput.files = dt.files;
      return true;
    } catch (e) {
      if (!file) { try { resumeInput.value = ''; } catch (err) { /* ignore */ } }
      return false;
    }
  }

  function describe(file) {
    var ext = fileExt(file.name);
    return (TYPE_LABEL[ext] || ext.toUpperCase() + ' file') + ' · ' + humanSize(file.size);
  }

  function render(file, opts) {
    opts = opts || {};
    var has = !!file;
    if (promptEl) promptEl.hidden = has;
    if (cardEl) cardEl.hidden = !has;
    if (zone) {
      zone.classList.toggle('has-file', has);
      zone.classList.toggle('is-restored', has && !!opts.restored);
    }
    // In the attached state the input must neither cover the card's buttons
    // nor be a second tab stop beside them.
    if (resumeInput) {
      if (has) resumeInput.setAttribute('tabindex', '-1');
      else resumeInput.removeAttribute('tabindex');
    }
    if (!has) return;

    var ext = fileExt(file.name);
    if (extEl) extEl.textContent = (ext || 'file').toUpperCase();
    if (nameEl) { nameEl.textContent = file.name; nameEl.title = file.name; }
    if (detailEl) detailEl.textContent = describe(file);
    if (readyTextEl) readyTextEl.textContent = opts.restored ? 'Kept from earlier — ready to submit' : 'Ready to submit';
    if (replaceBtn) replaceBtn.setAttribute('aria-label', 'Replace resume ' + file.name);
    if (removeBtn) removeBtn.setAttribute('aria-label', 'Remove resume ' + file.name);
  }

  /** Tell the inline progress / review panel the file changed. */
  function notifyPanels() {
    resumeInput.dispatchEvent(new CustomEvent('resume:sync', { bubbles: true }));
    // The inline script listens for `change`; flag this one as ours so our own
    // change handler does not process it a second time.
    syncing = true;
    resumeInput.dispatchEvent(new Event('change', { bubbles: true }));
    syncing = false;
  }
  var syncing = false;

  /**
   * Handle whatever is in the input now. Returns true when a good file is
   * attached.
   */
  function handleResume(source) {
    var file = resumeInput && resumeInput.files && resumeInput.files[0];

    // Empty input: show the empty state, but never wipe an error that is still
    // explaining why the last file was refused.
    if (!file) {
      if (lastGood) { setInputFile(lastGood); render(lastGood); return true; }
      render(null);
      return false;
    }

    var problem = resumeProblem(file);
    if (problem) {
      if (lastGood) {
        // A bad replacement: keep the good file that was already attached.
        setInputFile(lastGood);
        render(lastGood);
        setFieldError(resumeInput, resumeError, zone, '');
        resumeError.textContent = problem + ' Your previous file, "' + lastGood.name + '", is still attached.';
        zone.classList.add('is-invalid');
      } else {
        setInputFile(null);
        render(null);
        setFieldError(null, resumeError, zone, problem);
        resumeInput.setAttribute('aria-invalid', 'true');
      }
      return !!lastGood;
    }

    var replaced = lastGood && lastGood !== file;
    lastGood = file;
    setFieldError(resumeInput, resumeError, zone, '');
    render(file, { restored: source === 'restored' });
    draft.save(file);

    var what = file.name + ', ' + describe(file);
    if (source === 'restored') announce('Your resume from earlier in this visit is still attached: ' + what + '.');
    else if (source !== 'sync') announce((replaced ? 'Resume replaced: ' : 'Resume attached: ') + what + '. Ready to submit.');

    // Coming back from the picker, focus sits on the now-hidden input. Hand it
    // to Replace, the first thing that can be acted on in the new state.
    if (source === 'pick' && document.activeElement === resumeInput && replaceBtn) replaceBtn.focus();
    return true;
  }

  /* ------------------------------------------------------------------ *
   * Keeping the file when the applicant goes back and forth.
   *
   * Measured in Chrome: with the back/forward cache the whole page comes back
   * untouched. Without it, the page is fetched again and Chrome's form-state
   * restoration puts the file back into the input — but WITHOUT firing any
   * event, after this script has run, so the zone said "Drop your resume here"
   * over a file that was in fact attached. `pageshow` fires after that
   * restoration, so the zone re-reads the input there.
   *
   * Firefox and Safari do not restore file inputs at all. For them the file
   * is also kept in IndexedDB, and put back into the input when the page is
   * shown again with nothing in it.
   *
   * Privacy: the copy is keyed to THIS TAB (a random id in sessionStorage,
   * which dies with the tab), expires after an hour, and is deleted on Remove
   * and on a successful submit. A second person opening the form in a new
   * window on a shared computer gets a new id and sees nothing. Nothing is sent
   * to the server until the applicant submits.
   * ------------------------------------------------------------------ */
  var draft = (function () {
    var TTL = 60 * 60 * 1000;
    var jobInput = form.querySelector('input[name="job"]');
    var tabId = null;
    try {
      tabId = window.sessionStorage.getItem('ats-apply-tab');
      if (!tabId) {
        tabId = Math.random().toString(36).slice(2) + Date.now().toString(36);
        window.sessionStorage.setItem('ats-apply-tab', tabId);
      }
    } catch (e) { tabId = null; }
    var key = tabId && jobInput ? tabId + '|' + jobInput.value : null;
    var enabled = !!(key && window.indexedDB && window.DataTransfer);

    function open() {
      return new Promise(function (resolve, reject) {
        var req = window.indexedDB.open('acme-apply', 1);
        req.onupgradeneeded = function () { req.result.createObjectStore('resumes'); };
        req.onsuccess = function () { resolve(req.result); };
        req.onerror = function () { reject(req.error); };
      });
    }
    function tx(mode, fn) {
      if (!enabled) return Promise.resolve(null);
      return open().then(function (db) {
        return new Promise(function (resolve, reject) {
          var t = db.transaction('resumes', mode);
          var store = t.objectStore('resumes');
          var out = fn(store);
          t.oncomplete = function () { db.close(); resolve(out && out.result !== undefined ? out.result : null); };
          t.onerror = function () { db.close(); reject(t.error); };
        });
      }).catch(function () { return null; });
    }

    return {
      save: function (file) {
        return tx('readwrite', function (s) {
          return s.put({ file: file, name: file.name, type: file.type, savedAt: Date.now() }, key);
        });
      },
      clear: function () { return tx('readwrite', function (s) { return s.delete(key); }); },
      load: function () {
        return tx('readonly', function (s) { return s.get(key); }).then(function (row) {
          if (!row || !row.file || Date.now() - row.savedAt > TTL) return null;
          return row.file instanceof File ? row.file
               : new File([row.file], row.name, { type: row.type });
        });
      },
      /** Drop anything past its hour, from any tab — the tidy-up for closed tabs. */
      purge: function () {
        return tx('readwrite', function (s) {
          var req = s.openCursor();
          req.onsuccess = function () {
            var c = req.result;
            if (!c) return;
            if (!c.value || Date.now() - c.value.savedAt > TTL) c.delete();
            c.continue();
          };
          return req;
        });
      }
    };
  })();

  function syncFromInput() {
    if (!resumeInput) return;
    if (resumeInput.files && resumeInput.files[0]) {
      handleResume('sync');
      notifyPanels();
      return;
    }
    // Nothing in the input: the browser did not bring the file back. Try the
    // copy kept for this tab.
    draft.load().then(function (file) {
      if (!file || (resumeInput.files && resumeInput.files[0])) return;
      if (!setInputFile(file)) return;
      if (handleResume('restored')) notifyPanels();
    });
  }

  if (zone && resumeInput) {
    // Drag feedback. dragenter/dragover must cancel the default or the drop
    // never fires; dragleave also fires when the pointer crosses a child, so
    // leaving INTO a child is ignored.
    ['dragenter', 'dragover'].forEach(function (evt) {
      zone.addEventListener(evt, function (e) {
        e.preventDefault();
        if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
        zone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'dragend'].forEach(function (evt) {
      zone.addEventListener(evt, function (e) {
        if (evt === 'dragleave' && e.relatedTarget && zone.contains(e.relatedTarget)) return;
        zone.classList.remove('is-dragover');
      });
    });
    // Dropping onto the file card replaces the file, same as Replace.
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      zone.classList.remove('is-dragover');
      var files = e.dataTransfer && e.dataTransfer.files;
      if (!files || !files.length) return;
      if (files.length > 1) {
        resumeError.textContent = 'Please drop a single file — your resume.';
        zone.classList.add('is-invalid');
        return;
      }
      try { resumeInput.files = files; } catch (err) { return; }
      handleResume('drop');
      notifyPanels();
    });

    resumeInput.addEventListener('change', function () {
      if (syncing) return;                  // our own notification, not a pick
      handleResume('pick');
      // The inline progress/review script is registered first and has already
      // read the input -- possibly a file that was just refused and swapped
      // back for the previous one. Tell it again, with the settled state.
      notifyPanels();
    });

    // `required` blocking an empty submit: say it beside the zone, not only in
    // the browser's bubble.
    resumeInput.addEventListener('invalid', function () {
      if (!resumeInput.files || !resumeInput.files.length) {
        setFieldError(null, resumeError, zone, 'Please attach your resume.');
        resumeInput.setAttribute('aria-invalid', 'true');
      }
    });

    // Enter and Space open the picker. Space already does natively in most
    // browsers; Enter does not everywhere, and doing both here, once,
    // guarantees exactly one dialog per key press.
    resumeInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        resumeInput.click();
      }
    });

    // Stop a file dropped just outside the zone from navigating away to it
    // and discarding everything typed so far.
    ['dragover', 'drop'].forEach(function (evt) {
      window.addEventListener(evt, function (e) {
        if (!zone.contains(e.target)) e.preventDefault();
      });
    });
  }

  if (replaceBtn) {
    replaceBtn.addEventListener('click', function () {
      // Cancelling the picker changes nothing: the current file stays.
      resumeInput.click();
    });
  }

  if (removeBtn) {
    removeBtn.addEventListener('click', function () {
      var name = lastGood ? lastGood.name : 'the file';
      lastGood = null;
      setInputFile(null);
      render(null);
      setFieldError(resumeInput, resumeError, zone, '');
      draft.clear();
      notifyPanels();
      // Back to the empty state's one tab stop.
      resumeInput.focus();
      announce('Removed ' + name + '. Choose a resume to attach.');
    });
  }

  draft.purge();
  syncFromInput();
  // After a back/forward navigation — from the cache or not — re-read the
  // input once the browser has finished restoring form state.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted || !(resumeInput.files && resumeInput.files[0]) || !lastGood) syncFromInput();
  });

  /* ------------------------------------------------------------------ *
   * Submit with upload progress.
   *
   * A normal POST gives no progress at all — the browser just spins while a
   * 5MB file goes up. XMLHttpRequest exposes upload.onprogress, so the form is
   * sent that way and apply.php answers with JSON (it checks the
   * X-Requested-With header). On success the page moves to the status page;
   * on failure the message goes beside its field and the form — including the
   * chosen file — stays exactly as it was.
   * ------------------------------------------------------------------ */
  var progressWrap = document.querySelector('[data-upload-progress]');
  var progressBar = document.querySelector('[data-upload-bar]');
  var progressFill = document.querySelector('[data-upload-fill]');
  var progressText = document.querySelector('[data-upload-text]');
  var banner = document.querySelector('[data-form-error]');
  var submitBtn = document.getElementById('submit-btn');
  var sending = false;

  function setProgress(pct, label) {
    if (!progressWrap) return;
    progressWrap.hidden = false;
    var p = Math.max(0, Math.min(100, Math.round(pct)));
    if (progressFill) progressFill.style.transform = 'scaleX(' + (p / 100) + ')';
    if (progressBar) progressBar.setAttribute('aria-valuenow', String(p));
    if (progressText) progressText.textContent = label || ('Uploading… ' + p + '%');
  }

  function showFormError(message, field) {
    if (banner) { banner.textContent = message; banner.hidden = false; }
    if (field === 'phone' && phoneInput) {
      phoneTouched = true;
      setFieldError(phoneInput, phoneError, phoneWrap, message);
      phoneInput.focus();
    } else if (field === 'resume' && resumeInput) {
      setFieldError(null, resumeError, zone, message);
      resumeInput.setAttribute('aria-invalid', 'true');
      resumeInput.focus();
    } else if (banner) {
      banner.setAttribute('tabindex', '-1');
      banner.focus();
    }
  }

  function setSending(on) {
    sending = on;
    if (submitBtn) {
      submitBtn.disabled = on;
      submitBtn.setAttribute('aria-busy', on ? 'true' : 'false');
    }
    if (zone) zone.classList.toggle('is-uploading', on);
  }

  form.addEventListener('submit', function (e) {
    // The inline script's portfolio check runs first and may already have
    // cancelled this submit; if so, it has also moved focus, so step aside.
    if (e.defaultPrevented) return;
    // Belt and braces: re-run the two custom rules. `required` and the
    // browser's constraint validation have already passed by the time a submit
    // event fires at all.
    if (!validatePhone(true)) { e.preventDefault(); phoneInput.focus(); return; }
    if (!handleResume('sync')) { e.preventDefault(); resumeInput.focus(); return; }
    if (!window.XMLHttpRequest || !window.FormData) return;   // plain POST

    e.preventDefault();
    if (sending) return;
    if (banner) banner.hidden = true;
    setSending(true);
    setProgress(0);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', form.getAttribute('action') || window.location.href, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');

    xhr.upload.addEventListener('progress', function (ev) {
      if (ev.lengthComputable) setProgress((ev.loaded / ev.total) * 100);
    });
    // Once the bytes are up the server still has work to do (store the file,
    // write the rows), so the bar says so rather than sitting at 100%.
    xhr.upload.addEventListener('load', function () { setProgress(100, 'Uploaded — finishing up…'); });

    xhr.addEventListener('load', function () {
      var data = null;
      try { data = JSON.parse(xhr.responseText); } catch (err) { data = null; }
      if (data && data.ok && data.redirect) {
        setProgress(100, 'Application sent.');
        // The application is in, so the copy kept for back/forward is not
        // needed and must not resurface. Never let that delay the redirect.
        var go = function () { window.location.href = data.redirect; };
        Promise.race([draft.clear(), new Promise(function (r) { setTimeout(r, 400); })]).then(go, go);
        return;
      }
      setSending(false);
      if (progressWrap) progressWrap.hidden = true;
      showFormError(
        (data && data.error) || 'We could not submit your application. Please try again.',
        data && data.field
      );
    });

    xhr.addEventListener('error', function () {
      setSending(false);
      if (progressWrap) progressWrap.hidden = true;
      showFormError('The upload was interrupted. Check your connection and submit again — nothing was saved.', null);
    });

    xhr.send(new FormData(form));
  });
})();
