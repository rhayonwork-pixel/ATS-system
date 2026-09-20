/**
 * Candidate portal — application status page.
 *
 * Everything here is progressive enhancement. With JavaScript off: the details
 * button is a link back to the page with ?detail=<id>, which server-renders the
 * same panel open; the withdraw button's modal stays hidden but the page is
 * still readable; the stepper is a plain list in a scroller. Nothing below is
 * required to read your status.
 */
(function () {
  'use strict';

  var live = document.querySelector('[data-cs-live]');
  function announce(msg) { if (live) live.textContent = msg; }

  /* ------------------------------------------------------------------ *
   * Horizontal stepper: bring the current stage into view.
   *
   * On a phone the stepper is a scroll-snapping track, and the stage that
   * matters is rarely the first one. scrollIntoView with inline:'center'
   * would also scroll the PAGE, so the scroller's own scrollLeft is set
   * instead — and only when it actually overflows, so the desktop layout is
   * never touched.
   * ------------------------------------------------------------------ */
  (function centreCurrentStep() {
    var scroller = document.querySelector('.cs-stepper-scroll');
    if (!scroller) return;
    var current = scroller.querySelector('.cs-step.is-current') ||
                  scroller.querySelector('.cs-step.is-done:last-of-type');
    if (!current) return;
    if (scroller.scrollWidth <= scroller.clientWidth + 4) return;
    var target = current.offsetLeft - (scroller.clientWidth - current.offsetWidth) / 2;
    scroller.scrollLeft = Math.max(0, target);
  })();

  /* ------------------------------------------------------------------ *
   * "View details" accordion.
   *
   * The panel content is fetched the first time it is opened and then kept,
   * so re-opening is instant and the server is asked once per application.
   * A skeleton is shown for the real duration of that request.
   * ------------------------------------------------------------------ */
  function skeleton(id) {
    var tpl = document.getElementById(id);
    return tpl ? tpl.content.cloneNode(true) : document.createDocumentFragment();
  }

  // Height has to be animated from a number, so the panel is measured, pinned
  // to that height for the transition, then released back to auto — otherwise
  // a lazily-filled panel would jump instead of sliding.
  function expand(panel) {
    panel.hidden = false;
    var h = panel.scrollHeight;
    panel.style.height = '0px';
    panel.classList.add('is-animating');
    requestAnimationFrame(function () {
      panel.style.height = h + 'px';
    });
    window.setTimeout(function () {
      panel.classList.remove('is-animating');
      panel.style.height = '';
    }, 220);
  }

  function collapse(panel) {
    panel.style.height = panel.scrollHeight + 'px';
    panel.classList.add('is-animating');
    requestAnimationFrame(function () { panel.style.height = '0px'; });
    window.setTimeout(function () {
      panel.classList.remove('is-animating');
      panel.style.height = '';
      panel.hidden = true;
    }, 220);
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.cs-toggle') : null;
    if (!btn) return;
    e.preventDefault();

    var panel = document.getElementById(btn.getAttribute('aria-controls'));
    if (!panel) { window.location.href = btn.dataset.fallback; return; }

    var open = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    btn.querySelector('.cs-toggle-label').textContent = open ? 'View details' : 'Hide details';

    if (open) { collapse(panel); return; }

    if (panel.dataset.loaded === '1') { expand(panel); return; }

    panel.replaceChildren(skeleton('cs-skeleton-detail'));
    expand(panel);
    announce('Loading your submitted application.');

    fetch(btn.dataset.detailUrl, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
      .then(function (html) {
        panel.innerHTML = html;
        panel.dataset.loaded = '1';
        // The skeleton and the real content are different heights, so the
        // pinned height from expand() has to be re-measured once.
        if (panel.style.height) panel.style.height = panel.scrollHeight + 'px';
        announce('Your submitted application is now showing.');
      })
      .catch(function () {
        panel.innerHTML = '<p class="cs-empty-line">We could not load this right now. ' +
          '<a href="' + btn.dataset.fallback + '">Open it on its own page</a> instead.</p>';
        announce('We could not load this application.');
      });
  });

  /* ------------------------------------------------------------------ *
   * Lookup form: show skeletons for the load that is about to happen.
   *
   * This is a normal GET navigation, not a fetch — the skeletons cover the
   * real wait between submit and the new document painting, which is exactly
   * the gap a skeleton is for. If the navigation is cancelled or blocked by
   * validation, nothing is replaced.
   * ------------------------------------------------------------------ */
  var form = document.querySelector('[data-lookup]');
  var results = document.querySelector('[data-results]');
  if (form && results) {
    form.addEventListener('submit', function () {
      if (!form.checkValidity()) return;
      results.replaceChildren(skeleton('cs-skeleton-results'));
      announce('Looking up your applications.');
    });
  }

  /* ------------------------------------------------------------------ *
   * Withdraw modal.
   * ------------------------------------------------------------------ */
  (function withdrawModal() {
    var overlay = document.querySelector('[data-modal-withdraw]');
    var openBtn = document.querySelector('[data-open-withdraw]');
    if (!overlay || !openBtn) return;
    var closeBtn = overlay.querySelector('[data-close-withdraw]');
    var lastFocus = null;

    function open() {
      lastFocus = document.activeElement;
      overlay.hidden = false;
      (closeBtn || overlay).focus();
    }
    function close() {
      overlay.hidden = true;
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    openBtn.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !overlay.hidden) close();
    });
  })();

  /* ------------------------------------------------------------------ *
   * Interview join state.
   *
   * Unchanged behaviour, moved out of the page: each scheduled interview
   * polls its own availability so nobody has to refresh to find out the room
   * opened. The control is swapped wholesale rather than re-styled, so an
   * enabled control is always a real link.
   * ------------------------------------------------------------------ */
  var HEADLINES = {
    interviewer_ready: 'Your interviewer is ready',
    available: 'Interview is ready',
    admitted: 'You have been admitted',
    waiting: 'Waiting for your interviewer',
    requested: 'Waiting for approval',
    not_available: 'Interview not available yet',
    ended: 'Interview ended',
    cancelled: 'Interview cancelled'
  };

  document.querySelectorAll('[data-join-block]').forEach(function (block) {
    var statusEl   = block.querySelector('[data-join-state]');
    var headlineEl = block.querySelector('[data-join-headline]');
    var messageEl  = block.querySelector('[data-join-message]');
    var actions    = block.querySelector('.ui-actions');
    if (!statusEl || !actions) return;

    function paint(data) {
      var headline = HEADLINES[data.state] || 'Interview';
      if (headlineEl.textContent !== headline) announce(headline);
      statusEl.className = 'ui-join-status state-' + data.state;
      headlineEl.textContent = headline;
      messageEl.textContent = data.message || '';
      var over = data.state === 'ended' || data.state === 'cancelled';
      var label = (data.state === 'interviewer_ready' || data.state === 'admitted') ? 'Join now' : 'Join interview';

      if (data.can_join && !over) {
        actions.innerHTML = '<a class="btn" data-join-btn href="' + block.dataset.url + '">' +
                            '<span data-join-label>' + label + '</span></a>';
      } else {
        actions.innerHTML = '<button class="btn secondary" type="button" disabled data-join-btn>' +
                            '<span data-join-label>Join interview</span></button>';
      }
      return over;
    }

    var timer = setInterval(function () {
      fetch('interview-status.php?code=' + encodeURIComponent(block.dataset.code) +
            '&t=' + encodeURIComponent(block.dataset.token))
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) { if (data && data.ok && paint(data)) clearInterval(timer); })
        .catch(function () { /* keep the last known state */ });
    }, 8000);
  });
})();
