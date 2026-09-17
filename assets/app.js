const assistantReplies=['I can help with open roles, interview stages, leave requests, and attendance questions.','For attendance, open My attendance to clock in or out and review your history.','Try the Recruiter hub for candidate pipeline updates and hiring tasks.'];const toast=(m)=>{const e=document.querySelector('#toast');if(!e)return;e.textContent=m;e.classList.add('show');setTimeout(()=>e.classList.remove('show'),2600)};document.addEventListener('click',e=>{if(e.target.matches('[data-toast]'))toast(e.target.dataset.toast)});document.querySelectorAll('[data-ai-toggle]').forEach(b=>b.addEventListener('click',()=>{const root=document.querySelector('[data-ai-assistant]');const panel=document.querySelector('[data-ai-panel]');const orb=document.querySelector('.ai-orb');const open=!root.classList.contains('is-open');root.classList.toggle('is-open',open);panel.setAttribute('aria-hidden',String(!open));orb?.setAttribute('aria-expanded',String(open));if(open)setTimeout(()=>document.querySelector('[data-ai-form] input')?.focus(),420);}));document.querySelector('[data-ai-form]')?.addEventListener('submit',e=>{e.preventDefault();const input=e.currentTarget.querySelector('input');const text=input.value.trim();if(!text)return;const messages=document.querySelector('[data-ai-messages]');messages.insertAdjacentHTML('beforeend',`<div class="ai-message user">${text.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}</div>`);input.value='';setTimeout(()=>messages.insertAdjacentHTML('beforeend',`<div class="ai-message bot"><img class="ai-msg-avatar" src="assets/ai-agent.png" alt=""><p>${assistantReplies[Math.floor(Math.random()*assistantReplies.length)]}</p></div>`),450)});document.querySelectorAll('form[data-validate]').forEach(f=>f.addEventListener('submit',e=>{let ok=true;f.querySelectorAll('[required]').forEach(i=>{if(!i.value.trim()){i.style.borderColor='#b7492d';ok=false}});if(!ok){e.preventDefault();toast('Please complete the required fields.')}}));document.querySelectorAll('[data-filter]').forEach(i=>i.addEventListener('input',()=>{const q=i.value.toLowerCase();document.querySelectorAll('[data-row]').forEach(r=>r.style.display=r.textContent.toLowerCase().includes(q)?'':'none')}));document.querySelectorAll('[draggable]').forEach(c=>c.addEventListener('dragstart',e=>e.dataTransfer.setData('text/plain',c.dataset.id)));document.querySelectorAll('[data-drop]').forEach(col=>col.addEventListener('dragover',e=>e.preventDefault()));document.querySelectorAll('[data-drop]').forEach(col=>col.addEventListener('drop',e=>{e.preventDefault();const c=document.querySelector(`[data-id="${e.dataTransfer.getData('text/plain')}"]`);if(c){col.append(c);toast('Candidate stage updated')}}));

/* Cross-tab "meeting is ongoing" awareness for the private HR app.
   Backed by the database (see room-presence.php) — not localStorage — so it
   reflects whether a room is *actually* live right now, and can never get
   stuck showing an interview that was never created or already ended. */
(function(){
  const overlay=document.querySelector('[data-room-overlay]'); if(!overlay) return;
  // One-time cleanup: earlier versions of this app tracked "meeting ongoing"
  // in localStorage. That flag is no longer read anywhere — wipe it so it
  // can never re-appear (e.g. via a stale cached copy of an older app.js).
  try{ localStorage.removeItem('acme_active_room'); }catch(e){}

  let dismissed=false;
  let currentCode = overlay.dataset.roomCode || '';

  function show(code, title){
    currentCode = code;
    overlay.dataset.roomCode = code;
    overlay.hidden = dismissed ? true : false;
    const t=overlay.querySelector('[data-room-overlay-title]');
    if(t) t.textContent=(title?title+' — ':'')+'this interview room is open in another tab.';
  }
  function hide(){ currentCode=''; overlay.dataset.roomCode=''; overlay.hidden=true; }

  function poll(){
    fetch('room-presence.php?action=check', {headers:{'Accept':'application/json'}})
      .then(r=>r.json())
      .then(data=>{
        const active = data && data.active;
        if(active && active.code){
          if(active.code !== currentCode) dismissed=false; // a *new* live room should re-announce itself
          if(!dismissed) show(active.code, active.title);
        } else {
          hide();
        }
      })
      .catch(()=>{});
  }

  overlay.querySelector('[data-room-overlay-back]')?.addEventListener('click',()=>{
    if(!currentCode) return;
    window.open('interview-room.php?code='+encodeURIComponent(currentCode),'acme-room-'+currentCode,'noopener');
  });
  overlay.querySelector('[data-room-overlay-dismiss]')?.addEventListener('click',()=>{ dismissed=true; overlay.hidden=true; });

  poll();
  setInterval(poll, 5000);
})();

// Sidebar collapse/expand (desktop only — mobile uses the existing .open drawer toggle).
(function(){
  // Declared first: setCollapsed() runs during init and calls closeFlyout(),
  // so these must be initialised before that happens.
  let flyoutEl = null;
  let flyoutGroup = null;
  let pillEl = null;      // same reason: setCollapsed() calls hidePill() at init

  const sidebar = document.querySelector('[data-sidebar]');
  const expandBtn = document.querySelector('[data-sidebar-expand]');
  const collapseBtn = document.querySelector('[data-sidebar-collapse]');
  const KEY = 'ats-sidebar-collapsed';
  const GROUPS_KEY = 'ats-sidebar-groups';

  /* ---------- collapse / expand ----------
     A single piece of state drives both controls, so the logo and the arrow can
     never disagree. Collapsed is stored so it survives navigation. */
  function setCollapsed(collapsed, remember) {
    if (!sidebar) return;
    sidebar.classList.toggle('collapsed', collapsed);
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    closeFlyout();          // state changed, so any open flyout is stale
    hidePill();             // and the pill belongs to the collapsed rail only

    // The logo only acts when collapsed; the arrow only when expanded.
    if (expandBtn) {
      expandBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      expandBtn.setAttribute('aria-disabled', collapsed ? 'false' : 'true');
      expandBtn.tabIndex = collapsed ? 0 : -1;
      expandBtn.title = collapsed ? 'Expand sidebar' : '';
      expandBtn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Company logo');
    }
    if (collapseBtn) {
      collapseBtn.hidden = collapsed;
      collapseBtn.tabIndex = collapsed ? -1 : 0;
    }
    if (remember) { try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) {} }
  }

  if (sidebar) setCollapsed(localStorage.getItem(KEY) === '1', false);

  // Logo expands. It never collapses.
  if (expandBtn) {
    expandBtn.addEventListener('click', function () {
      if (!sidebar || !sidebar.classList.contains('collapsed')) return;
      setCollapsed(false, true);
    });
  }

  // Arrow collapses. It never expands.
  if (collapseBtn) {
    collapseBtn.addEventListener('click', function () {
      if (!sidebar || sidebar.classList.contains('collapsed')) return;
      setCollapsed(true, true);
    });
  }

  /* ---------- collapsed-sidebar flyout ----------
     The sidebar is a scroll container (overflow-y:auto), so an absolutely
     positioned child can never escape it — it gets clipped no matter what
     z-index it has. The flyout is therefore a real element appended to <body>
     and positioned with fixed coordinates from the icon's bounding rect. */
  function closeFlyout() {
    if (!flyoutEl) return;
    flyoutEl.classList.remove('is-open');
    const el = flyoutEl, grp = flyoutGroup;
    flyoutEl = null;
    flyoutGroup = null;
    if (grp) grp.classList.remove('flyout-open');
    setTimeout(function () { if (el && el.parentNode) el.parentNode.removeChild(el); }, 160);
  }

  function positionFlyout(panel, anchorEl) {
    const rect = anchorEl.getBoundingClientRect();
    const gap = 8;
    panel.style.left = (rect.right + gap) + 'px';
    panel.style.top = rect.top + 'px';
    // Measure, then keep it on screen both ways.
    const box = panel.getBoundingClientRect();
    if (box.bottom > window.innerHeight - 12) {
      panel.style.top = Math.max(12, window.innerHeight - 12 - box.height) + 'px';
    }
    if (box.right > window.innerWidth - 12) {
      // No room on the right: flip to the other side of the icon.
      panel.style.left = Math.max(12, rect.left - gap - box.width) + 'px';
    }
  }

  function openFlyout(group) {
    const btn = group.querySelector('[data-group-toggle]');
    const items = group.querySelector('[data-group-items]');
    if (!btn || !items) return;

    closeFlyout();   // only ever one open at a time

    const panel = document.createElement('div');
    panel.className = 'nav-flyout';
    panel.setAttribute('role', 'menu');

    const title = document.createElement('div');
    title.className = 'nav-flyout-title';
    title.textContent = (btn.querySelector('.nav-text') || {}).textContent || 'Menu';
    panel.appendChild(title);

    // Clone the real links, so routes, labels, badges and the active class all
    // come across untouched. Nothing is rebuilt or faked.
    items.querySelectorAll('.nav-item').forEach(function (link) {
      const copy = link.cloneNode(true);
      copy.setAttribute('role', 'menuitem');
      // Following a link closes the flyout on the way out.
      copy.addEventListener('click', function () { closeFlyout(); });
      panel.appendChild(copy);
    });

    document.body.appendChild(panel);
    positionFlyout(panel, btn);
    requestAnimationFrame(function () { panel.classList.add('is-open'); });

    hidePill();             // the flyout takes over from the label
    flyoutEl = panel;
    flyoutGroup = group;
    group.classList.add('flyout-open');
    panel.querySelector('.nav-item')?.focus?.();
  }

  function flyoutMode() {
    // Flyouts apply whenever the labels are hidden: the collapsed state, or the
    // automatic compact width. Not in the mobile drawer, which is full width.
    if (!sidebar) return false;
    if (window.matchMedia('(max-width:767px)').matches) return false;   // drawer, full width
    return sidebar.classList.contains('collapsed') ||
           window.matchMedia('(max-width:1023px)').matches;   // icon rail
  }

  // Dismissals: outside click, Escape, scroll or resize moving the anchor.
  document.addEventListener('click', function (ev) {
    if (!flyoutEl) return;
    if (flyoutEl.contains(ev.target)) return;           // inside the panel is fine
    if (ev.target.closest('[data-group-toggle]')) return; // handled by the toggle
    closeFlyout();
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && flyoutEl) {
      const btn = flyoutGroup && flyoutGroup.querySelector('[data-group-toggle]');
      closeFlyout();
      btn?.focus?.();
    }
  });
  window.addEventListener('resize', closeFlyout);
  window.addEventListener('scroll', closeFlyout, true);

  /* ---------- floating pill label for the collapsed rail ----------
     One element, reused. Rendered on <body> for the same reason as the group
     flyout: .sidebar is a scroll container and would clip it.
     pointer-events:none, so it never intercepts a click meant for the icon. */
  function ensurePill() {
    if (pillEl) return pillEl;
    pillEl = document.createElement('div');
    pillEl.className = 'nav-pill';
    pillEl.setAttribute('aria-hidden', 'true');   // the link's own label is what
    document.body.appendChild(pillEl);            // screen readers announce
    return pillEl;
  }

  function pillMode() {
    // Only where the labels are hidden: the collapsed state or the automatic
    // icon rail. Never in the mobile drawer, which shows real inline labels.
    if (!sidebar) return false;
    if (window.matchMedia('(max-width:767px)').matches) return false;
    return sidebar.classList.contains('collapsed') ||
           window.matchMedia('(min-width:768px) and (max-width:1023px)').matches;
  }

  function hidePill() {
    if (pillEl) pillEl.classList.remove('is-visible');
  }

  function showPill(target) {
    if (!pillMode()) return hidePill();
    // A group flyout is open: the pill would sit on top of it.
    if (flyoutEl) return hidePill();

    const label = (target.querySelector('.nav-text') || {}).textContent ||
                  target.getAttribute('title') || target.getAttribute('aria-label') || '';
    if (!label.trim()) return hidePill();

    const pill = ensurePill();
    pill.textContent = label.trim();
    pill.classList.remove('is-visible');           // measure at natural size

    const rect = target.getBoundingClientRect();
    const gap = 14;                                 // clean horizontal breathing room
    pill.style.left = (rect.right + gap) + 'px';
    pill.style.top = '0px';

    // Vertically centre on the icon, then keep it inside the viewport.
    const box = pill.getBoundingClientRect();
    let top = rect.top + (rect.height - box.height) / 2;
    top = Math.max(8, Math.min(top, window.innerHeight - box.height - 8));
    pill.style.top = top + 'px';

    if (rect.right + gap + box.width > window.innerWidth - 8) {
      // No room on the right: sit it to the left of the icon instead.
      pill.style.left = Math.max(8, rect.left - gap - box.width) + 'px';
    }
    pill.classList.add('is-visible');
  }

  if (sidebar) {
    const PILL_TARGETS = '.nav-item, .nav-group-btn, .brand-button';

    // pointerenter/leave do not bubble, so delegation uses the capture phase.
    sidebar.addEventListener('mouseover', function (ev) {
      const t = ev.target.closest(PILL_TARGETS);
      if (t) showPill(t);
    });
    sidebar.addEventListener('mouseleave', hidePill);
    // Moving quickly down the rail: hide as soon as the pointer leaves an item
    // that it is not replacing, so nothing lingers.
    sidebar.addEventListener('mouseout', function (ev) {
      const to = ev.relatedTarget;
      if (!to || !sidebar.contains(to)) hidePill();
    });

    // Keyboard parity: Tab through the rail and the pill follows focus.
    sidebar.addEventListener('focusin', function (ev) {
      const t = ev.target.closest(PILL_TARGETS);
      if (t) showPill(t);
    });
    sidebar.addEventListener('focusout', hidePill);

    // Anything that moves the anchor or changes the mode invalidates it.
    window.addEventListener('resize', hidePill);
    window.addEventListener('scroll', hidePill, true);
    sidebar.addEventListener('scroll', hidePill);
  }

  /* ---------- collapsible navigation groups ----------
     A group holding the current page is rendered open by PHP, so the active
     item is visible on arrival without the person reopening anything. Manual
     opens and closes are remembered per group. */
  function readGroups() {
    try { return JSON.parse(localStorage.getItem(GROUPS_KEY) || '{}'); }
    catch (e) { return {}; }
  }

  function writeGroups(state) {
    try { localStorage.setItem(GROUPS_KEY, JSON.stringify(state)); } catch (e) {}
  }

  const saved = readGroups();

  document.querySelectorAll('[data-nav-group]').forEach(function (group) {
    const key = group.dataset.navGroup;
    const toggle = group.querySelector('[data-group-toggle]');
    const items = group.querySelector('[data-group-items]');
    if (!toggle || !items) return;

    // A group containing the active page always wins over a stored "closed".
    const hasActive = !!group.querySelector('.nav-item.active');
    let open = hasActive || saved[key] === true;
    setOpen(open, false);

    function setOpen(next, remember) {
      open = next;
      group.classList.toggle('is-open', next);
      toggle.setAttribute('aria-expanded', next ? 'true' : 'false');
      // Animate to the measured height, then release it so the group can
      // still grow if its contents change.
      if (next) {
        items.style.maxHeight = items.scrollHeight + 'px';
        setTimeout(function () { if (open) items.style.maxHeight = 'none'; }, 200);
      } else {
        items.style.maxHeight = items.scrollHeight + 'px';
        requestAnimationFrame(function () { items.style.maxHeight = '0px'; });
      }
      if (remember) {
        const state = readGroups();
        state[key] = next;
        writeGroups(state);
      }
    }

    toggle.addEventListener('click', function () {
      if (flyoutMode()) {
        // Collapsed: the children belong outside the sidebar, not inside it.
        if (flyoutGroup === group) closeFlyout();   // same icon again = close
        else openFlyout(group);                     // another icon = switch
        return;
      }
      setOpen(!open, true);
    });
    toggle.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowRight' && !open) { ev.preventDefault(); setOpen(true, true); }
      if (ev.key === 'ArrowLeft' && open) { ev.preventDefault(); setOpen(false, true); }
    });
  });

  /* ---------- mobile drawer ----------
     One function keeps the sidebar, the backdrop and the button's
     aria-expanded in step, so assistive tech is never told the wrong thing. */
  const backdrop = document.querySelector('[data-sidebar-backdrop]');
  const menuBtn = document.querySelector('[data-menu-toggle]');

  function setDrawer(open) {
    if (!sidebar) return;
    sidebar.classList.toggle('open', open);
    if (backdrop) backdrop.hidden = !open;
    if (menuBtn) {
      menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      menuBtn.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    }
    document.body.classList.toggle('drawer-open', open);
  }

  if (menuBtn) {
    menuBtn.addEventListener('click', function (ev) {
      ev.stopPropagation();
      setDrawer(!sidebar.classList.contains('open'));
    });
  }
  if (backdrop) backdrop.addEventListener('click', function () { setDrawer(false); });

  document.addEventListener('click', function (ev) {
    if (!sidebar || !sidebar.classList.contains('open')) return;
    if (sidebar.contains(ev.target) || ev.target.closest('[data-menu-toggle]')) return;
    setDrawer(false);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && sidebar && sidebar.classList.contains('open')) {
      setDrawer(false);
      menuBtn?.focus?.();
    }
  });
})();

/* ==========================================================================
   Global notification bell
   Short polling rather than SSE: on XAMPP/mod_php every open EventSource holds
   an Apache worker for its lifetime, so a few signed-in recruiters would
   exhaust MaxRequestWorkers. Polls every 20s while the tab is visible and 60s
   when it is hidden, and pauses entirely while the flyout is open (the flyout
   refreshes itself on open).
   ========================================================================== */
(function () {
  const trigger = document.querySelector('[data-notif-trigger]');
  const flyout  = document.querySelector('[data-notif-flyout]');
  if (!trigger || !flyout) return;

  const list    = flyout.querySelector('[data-notif-list]');
  const badge   = document.querySelector('[data-notif-badge]');
  const summary = flyout.querySelector('[data-notif-summary]');
  const markAll = flyout.querySelector('[data-notif-markall]');
  const chips   = [...flyout.querySelectorAll('[data-notif-cat]')];

  // Read from the flyout itself: most pages have no form to borrow a token from.
  const CSRF = flyout.dataset.csrf || '';
  let category = '';
  let timer = null;
  let lastUnread = parseInt(badge?.dataset.unreadCount || '0', 10) || 0;

  const ICONS = {
    application: '\u{1F4C4}', interview: '\u{1F5D3}',
    message: '\u{1F4AC}',     system: '\u{2699}'
  };
  const backdrop = document.querySelector('[data-notif-backdrop]');
  const countPill = flyout.querySelector('[data-notif-summary]');

  function setBadge(n) {
    if (!badge) return;
    // The dot carries no text now -- the count still shows in the flyout's
    // own "N New" pill and in this trigger's aria-label below, so nothing
    // sighted or assistive-tech users relied on is actually lost.
    badge.classList.toggle('is-empty', n === 0);
    badge.dataset.unreadCount = String(n);      // hidden at zero
    if (n > lastUnread) {                              // new arrival: pulse + ring once
      badge.classList.remove('pulse');
      void badge.offsetWidth;                          // restart the animation
      badge.classList.add('pulse');
      const bellIcon = trigger.querySelector('.icon-bell');
      if (bellIcon) {
        bellIcon.classList.remove('is-ringing');
        void bellIcon.offsetWidth;
        bellIcon.classList.add('is-ringing');
      }
    }
    lastUnread = n;
    trigger.setAttribute('aria-label', 'Notifications' + (n ? ', ' + n + ' unread' : ''));
    if (countPill) {
      countPill.textContent = n + ' New';
      countPill.hidden = n === 0;          // no pill when there is nothing new
    }
  }

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function render(data) {
    if (!list) return;
    list.setAttribute('aria-busy', 'false');
    if (!data || !data.ok) { list.innerHTML = '<p class="notif-empty">Could not load notifications.</p>'; return; }
    setBadge(data.unread || 0);

    if (!data.items.length) {
      list.innerHTML =
        '<div class="notif-empty-state">' +
          '<span class="notif-empty-icon" aria-hidden="true">\u2713</span>' +
          '<strong>You\u2019re all caught up!</strong>' +
          '<span>' + (category ? 'Nothing in this category right now.' : 'No new notifications at this time.') + '</span>' +
        '</div>';
      return;
    }

    list.innerHTML = data.items.map(function (n) {
      const href = n.action_url ? esc(n.action_url) : '';
      return '<article class="notif-item' + (n.is_read ? '' : ' is-unread') + '" data-notif-id="' + n.id +
             '"' + (href ? ' data-notif-href="' + href + '"' : '') + ' tabindex="0">' +
        '<span class="notif-icon cat-' + esc(n.category) + '" aria-hidden="true">' + (ICONS[n.category] || '') + '</span>' +
        '<div class="notif-body">' +
          '<strong>' + esc(n.title) + '</strong>' +
          (n.message ? '<p>' + esc(n.message) + '</p>' : '') +
          '<div class="notif-meta"><time>' + esc(n.ago) + '</time>' +
            (n.actor ? '<span>by ' + esc(n.actor) + '</span>' : '') + '</div>' +
          '<div class="notif-actions">' +
            (href ? '<a class="notif-act" href="' + href + '">' + esc(n.action_label) + '</a>' : '') +
            (n.is_read ? '' : '<button type="button" class="notif-act ghost" data-notif-read="' + n.id + '" aria-label="Dismiss">Dismiss</button>') +
          '</div>' +
        '</div>' +
        (n.is_read ? '' : '<span class="notif-unread-dot" aria-label="Unread"></span>') +
      '</article>';
    }).join('');
  }

  function load() {
    if (!list) return;
    list.setAttribute('aria-busy', 'true');
    return fetch('notifications-api.php?action=feed' + (category ? '&category=' + encodeURIComponent(category) : ''))
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(render)
      .catch(function () { render(null); });
  }

  function poll() {
    // Cheap count-only call; the full feed is fetched when the flyout opens.
    if (!flyout.hidden) return;
    fetch('notifications-api.php?action=count')
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.ok) setBadge(d.unread); })
      .catch(function () { /* try again next tick */ });
  }

  function schedule() {
    clearInterval(timer);
    const hidden = document.visibilityState === 'hidden';
    timer = setInterval(poll, hidden ? 60000 : 20000);
  }
  document.addEventListener('visibilitychange', schedule);
  schedule();

  function setOpen(open) {
    flyout.hidden = !open;
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('notif-open', open);
    if (backdrop) backdrop.hidden = !open;   // mobile sheet backdrop
    if (open) load();
  }
  if (backdrop) backdrop.addEventListener('click', function () { setOpen(false); });

  trigger.addEventListener('click', function (ev) {
    ev.stopPropagation();
    setOpen(flyout.hidden);
  });

  // Click-away and Escape.
  document.addEventListener('click', function (ev) {
    if (flyout.hidden) return;
    if (flyout.contains(ev.target) || trigger.contains(ev.target)) return;
    setOpen(false);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && !flyout.hidden) { setOpen(false); trigger.focus(); }
  });

  chips.forEach(function (chip) {
    chip.addEventListener('click', function () {
      category = chip.dataset.notifCat || '';
      chips.forEach(function (c) {
        const on = c === chip;
        c.classList.toggle('is-active', on);
        c.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      load();
    });
  });

  if (markAll) {
    markAll.addEventListener('click', function () {
      const body = new FormData();
      body.append('csrf', CSRF);
      fetch('notifications-api.php?action=mark_all', { method: 'POST', body: body })
        .then(function () { return load(); });
    });
  }

  // Dismiss a single item without leaving the page.
  // Clicking a card routes to its target; Enter does the same for keyboard use.
  list?.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter') return;
    const card = ev.target.closest('[data-notif-href]');
    if (card) window.location.href = card.dataset.notifHref;
  });

  list?.addEventListener('click', function (ev) {
    const btn = ev.target.closest('[data-notif-read]');
    if (!btn) {
      // Anywhere else on the card follows its link, unless a real link was hit.
      if (ev.target.closest('a')) return;
      const card = ev.target.closest('[data-notif-href]');
      if (card) { window.location.href = card.dataset.notifHref; return; }
      return;
    }
    ev.stopPropagation();
    const body = new FormData();
    body.append('csrf', CSRF);
    body.append('id', btn.dataset.notifRead);
    fetch('notifications-api.php?action=mark_read', { method: 'POST', body: body })
      .then(function () { return load(); });
  });
})();

/* ==========================================================================
   Floating utility bar — scroll state
   State A (default): the bar stays fully transparent at every scroll position.
   State B: set UTIL_BAR_BLUR_ON_SCROLL to true for an ultra-subtle backdrop
   blur once the page has scrolled past 20px. Icons already carry a drop shadow,
   so State A stays legible; State B is there for pages with very dense content.
   ========================================================================== */
(function () {
  const UTIL_BAR_BLUR_ON_SCROLL = false;   // <-- flip to true for State B

  const bar = document.querySelector('[data-util-bar]');
  if (!bar || !UTIL_BAR_BLUR_ON_SCROLL) return;

  let ticking = false;
  function update() {
    bar.classList.toggle('is-scrolled', window.scrollY > 20);
    ticking = false;
  }
  // rAF-throttled: one class check per frame at most, never per scroll event.
  window.addEventListener('scroll', function () {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(update);
  }, { passive: true });
  update();
})();

/* ==========================================================================
   Theme switcher
   One source of truth: data-theme on <html>. body.dark is mirrored because a
   large amount of existing CSS is written against it. The head script applies
   the theme before first paint; this module handles toggling afterwards.
   ========================================================================== */
(function () {
  const KEY = 'ats_theme_preference';
  const root = document.documentElement;
  const media = window.matchMedia('(prefers-color-scheme: dark)');

  function stored() {
    try { return localStorage.getItem(KEY); } catch (e) { return null; }
  }

  function apply(dark) {
    root.setAttribute('data-theme', dark ? 'dark' : 'light');
    root.classList.toggle('dark', dark);
    document.body.classList.toggle('dark', dark);      // legacy selectors
    document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
      b.setAttribute('aria-pressed', dark ? 'true' : 'false');
      b.setAttribute('title', dark ? 'Switch to light theme' : 'Switch to dark theme');
      b.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
    });
  }

  // Sync the class the head script could not set (body did not exist yet).
  apply(root.getAttribute('data-theme') === 'dark');

  document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-theme-toggle]')) return;
    const dark = root.getAttribute('data-theme') !== 'dark';
    apply(dark);
    try { localStorage.setItem(KEY, dark ? 'dark' : 'light'); } catch (err) {}
  });

  // Follow the OS only while the user has expressed no preference.
  media.addEventListener('change', function (ev) {
    if (!stored()) apply(ev.matches);
  });
})();

/* ==========================================================================
   Dropzones
   Progressive enhancement over a real <input type="file">: the input is still
   the control, still inside the form, still named — so uploads work exactly as
   before with JavaScript off. This only adds drag-over feedback and the chosen
   filename.
   ========================================================================== */
(function () {
  document.querySelectorAll('[data-dropzone]').forEach(function (zone) {
    const input = zone.querySelector('[data-dropzone-input]');
    const nameEl = zone.querySelector('[data-dropzone-name]');
    if (!input) return;

    function showName() {
      if (!nameEl) return;
      const f = input.files && input.files[0];
      nameEl.textContent = f ? f.name : '';
      nameEl.hidden = !f;
    }

    ['dragenter', 'dragover'].forEach(function (evt) {
      zone.addEventListener(evt, function (e) {
        e.preventDefault();
        zone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
      zone.addEventListener(evt, function (e) {
        // dragleave fires when crossing a child; ignore those.
        if (evt === 'dragleave' && zone.contains(e.relatedTarget)) return;
        zone.classList.remove('is-dragover');
      });
    });

    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      if (!e.dataTransfer || !e.dataTransfer.files.length) return;
      // DataTransfer assignment is how a dropped file reaches the input, so the
      // normal form submission carries it without any custom upload code.
      try { input.files = e.dataTransfer.files; } catch (err) { return; }
      showName();
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });

    input.addEventListener('change', showName);
    showName();
  });
})();
