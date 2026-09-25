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

  var MAX_BYTES = 5 * 1024 * 1024;
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
   * Resume drop zone
   * ------------------------------------------------------------------ */
  var zone = document.querySelector('[data-resume-zone]');
  var resumeInput = document.querySelector('[data-resume-input]');
  var promptEl = document.querySelector('[data-resume-prompt]');
  var pickedEl = document.querySelector('[data-resume-picked]');
  var nameEl = document.querySelector('[data-resume-name]');
  var sizeEl = document.querySelector('[data-resume-size]');
  var extEl = document.querySelector('[data-resume-ext]');
  var removeBtn = document.querySelector('[data-resume-remove]');
  var resumeError = document.getElementById('resume-error');
  if (zone) zone.dataset.invalidClass = 'is-invalid';

  function fileExt(name) {
    var m = /\.([a-z0-9]+)$/i.exec(name || '');
    return m ? m[1].toLowerCase() : '';
  }

  function humanSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return Math.round(bytes / 1024) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  /** Why this file cannot be sent, or '' if it can. */
  function resumeProblem(file) {
    if (!file) return 'Please attach your resume.';
    var ext = fileExt(file.name);
    if (ALLOWED_EXT.indexOf(ext) === -1 || ALLOWED_MIME.indexOf(file.type || '') === -1) {
      return '"' + file.name + '" is not a PDF, DOC or DOCX file. Please choose a different file.';
    }
    if (file.size > MAX_BYTES) {
      return '"' + file.name + '" is ' + humanSize(file.size) + '. The limit is 5MB — try exporting a smaller PDF.';
    }
    if (file.size === 0) return '"' + file.name + '" is empty. Please choose a different file.';
    return '';
  }

  function showPicked(file) {
    var has = !!file;
    if (promptEl) promptEl.hidden = has;
    if (pickedEl) pickedEl.hidden = !has;
    if (removeBtn) removeBtn.hidden = !has;
    if (zone) zone.classList.toggle('has-file', has);
    if (!has) return;
    if (nameEl) nameEl.textContent = file.name;
    if (sizeEl) sizeEl.textContent = humanSize(file.size);
    if (extEl) extEl.textContent = (fileExt(file.name) || 'file').toUpperCase();
  }

  /**
   * Check the chosen file and reflect it. A rejected file is taken off the
   * input straight away, so what the form would submit and what the zone
   * shows can never disagree.
   */
  function handleResume() {
    var file = resumeInput && resumeInput.files && resumeInput.files[0];
    // No file is NOT the same as "valid": a rejected file has just been taken
    // off the input, and a later `change` arriving on the now-empty input must
    // not wipe the message that says why. Only a good file, or Remove, clears it.
    if (!file) {
      showPicked(null);
      return false;
    }
    var problem = resumeProblem(file);
    if (problem) {
      try { resumeInput.value = ''; } catch (e) { /* older browsers */ }
      showPicked(null);
      // setCustomValidity is not used for this one: the input is now empty, and
      // `required` already blocks submission of an empty input on its own.
      setFieldError(null, resumeError, zone, problem);
      resumeInput.setAttribute('aria-invalid', 'true');
      return false;
    }
    setFieldError(resumeInput, resumeError, zone, '');
    showPicked(file);
    return true;
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
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      zone.classList.remove('is-dragover');
      var files = e.dataTransfer && e.dataTransfer.files;
      if (!files || !files.length) return;
      if (files.length > 1) {
        setFieldError(null, resumeError, zone, 'Please drop a single file — your resume.');
        return;
      }
      // Assigning DataTransfer.files is how a dropped file reaches the real
      // input, so the normal submission carries it with no custom upload code.
      try { resumeInput.files = files; } catch (err) { return; }
      // `change` is what the inline progress/review panel listens for.
      resumeInput.dispatchEvent(new Event('change', { bubbles: true }));
    });

    resumeInput.addEventListener('change', handleResume);
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
    resumeInput.addEventListener('focus', function () { zone.classList.add('is-focused'); });
    resumeInput.addEventListener('blur', function () { zone.classList.remove('is-focused'); });

    // Stop a file dropped just outside the zone from navigating away to it
    // and discarding everything typed so far.
    ['dragover', 'drop'].forEach(function (evt) {
      window.addEventListener(evt, function (e) {
        if (!zone.contains(e.target)) e.preventDefault();
      });
    });
  }

  if (removeBtn) {
    removeBtn.addEventListener('click', function () {
      try { resumeInput.value = ''; } catch (e) { /* older browsers */ }
      showPicked(null);
      setFieldError(resumeInput, resumeError, zone, '');
      resumeInput.dispatchEvent(new Event('change', { bubbles: true }));
      resumeInput.focus();
    });
  }

  // A file survives a same-page validation error only on the XHR path; after a
  // normal POST the browser clears it, so the zone starts empty either way.
  if (resumeInput && resumeInput.files && resumeInput.files[0]) handleResume();

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
    if (!handleResume()) { e.preventDefault(); resumeInput.focus(); return; }
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
        window.location.href = data.redirect;
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
