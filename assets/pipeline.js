/* Hiring pipeline board — pipeline.php.
 *
 * Three pieces, plain JS, no build step (see docs/feature-history/README-pipeline-approval.txt
 * for the full contract, the state machine and the QA checklist):
 *
 *   PipelineBoard  owns all state, the columns, rendering/virtualization and the
 *                  pointer + keyboard drag sensors.
 *   CandidateCard  creates and updates one card node from a candidate record.
 *   ApprovalModal  the verification dialog. Confirm is the ONLY path that calls
 *                  the move_stage API; Cancel, backdrop and Escape all revert.
 *
 * A drop never writes anything. It puts the move into a pending state (card in
 * the proposed column, a ghost left in the original one) and opens the modal.
 */
(function () {
  'use strict';

  const boardEl = document.querySelector('[data-pipeline-board]');
  if (!boardEl) return;

  /* ── Configuration ───────────────────────────────────────────────────── */
  const STAGE_LABELS = window.PIPELINE_STAGE_LABELS || {};
  const STAGE_ORDER = Object.keys(STAGE_LABELS);
  const MESSAGES = window.PIPELINE_TRANSITION_MESSAGES || {};
  const CSRF = boardEl.dataset.csrf || '';
  const CAN_OVERRIDE = boardEl.dataset.canOverride === '1';
  const QUERY = boardEl.dataset.query || '';

  const VIRTUALIZE_AFTER = 50;   // a column with more cards than this is windowed
  const OVERSCAN = 6;            // extra rows mounted above/below the visible window
  const ROW_GAP = 9;             // must match .pb-col-list gap in pipeline.css
  const LONG_PRESS_MS = 350;     // touch/pen: hold this long before a drag starts
  const TOUCH_SLOP = 8;          // px a finger may wander during the long press
  const MOUSE_SLOP = 5;          // px a mouse must travel before a drag starts
  const FLY_MS = 300;
  const FLY_EASE = 'cubic-bezier(0.2, 0.8, 0.2, 1)';
  const EDGE = 56;               // auto-scroll hot zone, px from an edge
  const MAX_SCROLL_STEP = 18;    // px per frame at the very edge

  const mqAccordion = window.matchMedia('(max-width: 480px)');
  const mqReduced = window.matchMedia('(prefers-reduced-motion: reduce)');

  const liveEl = document.querySelector('[data-pb-live]');
  const cardTpl = document.getElementById('pb-card-template');

  /**
   * @typedef {Object} Candidate
   * @property {number} id            application id (the board's key)
   * @property {string} stage         committed stage key — changes only after a successful save
   * @property {string} name
   * @property {string} initials
   * @property {string} title         job title
   * @property {string} updatedAgo
   * @property {?number} aiScore
   * @property {?string} resume
   * @property {?string} profileImage
   * @property {number} noteCount
   * @property {?string} latestNote
   * @property {?number|string} screeningScore
   * @property {?number|string} interviewScore
   *
   * @typedef {Object} PendingMove
   * @property {number} id
   * @property {string} original_stage_id
   * @property {string} proposed_stage_id
   * @property {boolean} override     admin skip override, sent with the commit
   */
  const state = {
    /** loading | idle | dragging | lifted | pending | committing | reverting | settling | error */
    phase: 'loading',
    /** @type {Object<string, Candidate[]>} display order per column */
    byStage: {},
    /** @type {Map<number, Candidate>} */
    byId: new Map(),
    total: 0,
    rowH: 0,
    drag: null,      // active pointer session (see onPointerDown)
    lift: null,      // keyboard pick-up: { id, originStage, targetStage }
    /** @type {?PendingMove} */
    pending: null,
    arriving: null,  // { id, stage }: card kept invisible while a flying clone lands on it
  };
  STAGE_ORDER.forEach(s => { state.byStage[s] = []; });

  const overlays = new Set();   // flying/dragged clones on <body>
  let suppressClickUntil = 0;

  /* ── Columns ─────────────────────────────────────────────────────────── */
  const columns = new Map();
  boardEl.querySelectorAll('[data-pb-col]').forEach(el => {
    const col = {
      stage: el.dataset.stage,
      el,
      head: el.querySelector('.pb-col-head'),
      list: el.querySelector('[data-pb-col-list]'),
      countEl: el.querySelector('[data-pb-col-count]'),
      pendingEl: el.querySelector('[data-pb-col-pending]'),
      fillEl: el.querySelector('[data-pb-col-progress-fill]'),
      labelEl: el.querySelector('[data-pb-col-progress-label]'),
      toggle: el.querySelector('[data-pb-col-toggle]'),
      nodes: new Map(),    // candidate id -> mounted card node
      emptyEl: null,
      virtual: false,
      collapsed: false,
      userToggled: false,
      raf: 0,
    };
    columns.set(col.stage, col);
    el.addEventListener('scroll', () => scheduleWindow(col), { passive: true });
    col.toggle?.addEventListener('click', () => {
      col.userToggled = true;
      setCollapsed(col, !col.collapsed);
    });
  });

  /* ── Small helpers ───────────────────────────────────────────────────── */
  const label = s => STAGE_LABELS[s] || s;
  const reduced = () => mqReduced.matches;
  const isGhost = (stage, c) => !!state.pending && state.pending.id === c.id && stage === state.pending.original_stage_id;

  function announce(msg) {
    if (!liveEl) return;
    liveEl.textContent = '';
    requestAnimationFrame(() => { liveEl.textContent = msg; });
  }

  function showToast(message) {
    const t = document.querySelector('#toast');
    if (!t) return;
    t.textContent = message;
    t.classList.add('show');
    clearTimeout(showToast.timer);
    showToast.timer = setTimeout(() => t.classList.remove('show'), 3200);
  }

  function isSkip(fromStage, toStage) {
    if (toStage === 'rejected') return false;
    const f = STAGE_ORDER.indexOf(fromStage), t = STAGE_ORDER.indexOf(toStage);
    return f !== -1 && t !== -1 && t > f + 1;
  }

  function fmtScore(v) {
    const n = Number(v);
    return Number.isFinite(n) ? String(Math.round(n)) : String(v);
  }

  function colOfNode(node) {
    const el = node && node.closest('[data-pb-col]');
    return el ? columns.get(el.dataset.stage) : null;
  }

  /* ══ CandidateCard ═══════════════════════════════════════════════════════
     create(candidate)            -> HTMLElement (from <template id="pb-card-template">)
     update(node, candidate, f)   applies text that can change and the flags
       f = { ghost, pending, source, lifted, arriving }
     Every field goes in through textContent or a URL attribute, never innerHTML. */
  const CandidateCard = {
    create(c) {
      const node = cardTpl.content.firstElementChild.cloneNode(true);
      node.dataset.id = String(c.id);
      const avatar = node.querySelector('[data-f="avatar"]');
      if (c.profileImage) {
        const img = document.createElement('img');
        img.className = 'candidate-card-avatar avatar-image';
        img.src = c.profileImage;
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.draggable = false;
        avatar.replaceWith(img);
      } else {
        avatar.textContent = c.initials || '?';
      }
      node.querySelector('[data-f="name"]').textContent = c.name;
      const score = node.querySelector('[data-f="score"]');
      if (c.aiScore !== null && c.aiScore !== undefined) {
        score.hidden = false;
        score.textContent = 'AI ' + fmtScore(c.aiScore);
      }
      node.querySelector('[data-f="view"]').href = 'candidate.php?id=' + encodeURIComponent(c.id);
      node.querySelector('[data-f="schedule"]').href = 'interviews.php?application=' + encodeURIComponent(c.id);
      return node;
    },

    update(node, c, f) {
      const meta = node.querySelector('[data-f="meta"]');
      const metaText = (c.title || '') + ' · ' + (c.updatedAgo || '');
      if (meta.textContent !== metaText) meta.textContent = metaText;
      node.classList.toggle('is-ghost', f.ghost);
      node.classList.toggle('is-pending', f.pending);
      node.classList.toggle('is-drag-source', f.source);
      node.classList.toggle('is-lifted', f.lifted);
      node.classList.toggle('is-arriving', f.arriving);
      // The ghost is a visual echo of where the card came from, not a second
      // copy: out of the tab order and hidden from assistive tech.
      if (f.ghost) {
        node.tabIndex = -1;
        node.setAttribute('aria-hidden', 'true');
        node.inert = true;
      } else if (node.inert || node.tabIndex !== 0) {
        node.tabIndex = 0;
        node.removeAttribute('aria-hidden');
        node.inert = false;
      }
    },
  };

  function cardFlags(stage, c) {
    const p = state.pending, d = state.drag, l = state.lift, a = state.arriving;
    return {
      ghost: isGhost(stage, c),
      pending: !!p && p.id === c.id && stage === p.proposed_stage_id,
      source: !!d && d.active && d.id === c.id && stage === d.originStage,
      lifted: !!l && l.id === c.id && stage === l.originStage,
      arriving: !!a && a.id === c.id && stage === a.stage,
    };
  }

  function refreshCard(stage, id) {
    const col = columns.get(stage);
    const node = col && col.nodes.get(id);
    const c = state.byId.get(id);
    if (node && c) CandidateCard.update(node, c, cardFlags(stage, c));
  }

  /* ══ PipelineBoard: rendering ════════════════════════════════════════════ */
  function renderColumn(col) {
    const items = state.byStage[col.stage];
    const virtual = items.length > VIRTUALIZE_AFTER;
    if (virtual !== col.virtual) {
      col.list.replaceChildren();
      col.nodes.clear();
      col.emptyEl = null;
      col.virtual = virtual;
      col.list.classList.toggle('is-virtual', virtual);
      if (!virtual) col.list.style.height = '';
    }
    if (virtual) renderWindow(col); else renderAll(col, items);
    renderHeader(col);
  }

  function renderAllColumns() { columns.forEach(renderColumn); }

  // Up to VIRTUALIZE_AFTER cards: every card mounted, keyed so existing nodes
  // are reused and only moved when their position actually changed (moving a
  // focused node would drop focus).
  function renderAll(col, items) {
    const keep = new Set();
    items.forEach((c, i) => {
      let node = col.nodes.get(c.id);
      if (!node) { node = CandidateCard.create(c); col.nodes.set(c.id, node); }
      CandidateCard.update(node, c, cardFlags(col.stage, c));
      node.style.transform = '';
      node.removeAttribute('aria-posinset');
      node.removeAttribute('aria-setsize');
      keep.add(c.id);
      const at = col.list.children[i];
      if (at !== node) col.list.insertBefore(node, at || null);
    });
    col.nodes.forEach((node, id) => {
      if (!keep.has(id)) { node.remove(); col.nodes.delete(id); }
    });
    const empty = items.length === 0;
    if (empty && !col.emptyEl) {
      col.emptyEl = document.createElement('p');
      col.emptyEl.className = 'pb-empty';
      col.emptyEl.textContent = 'Drop a candidate here';
      col.list.appendChild(col.emptyEl);
    } else if (!empty && col.emptyEl) {
      col.emptyEl.remove();
      col.emptyEl = null;
    }
  }

  // Cards are a fixed height (single-line text, see pipeline.css), so one
  // measurement gives the row height for every windowed column.
  function rowHeight(col) {
    if (state.rowH) return state.rowH;
    const sample = state.byStage[col.stage][0];
    if (!sample) return 110;
    const probe = CandidateCard.create(sample);
    probe.style.cssText = 'position:absolute;left:0;right:0;top:0;visibility:hidden';
    col.list.appendChild(probe);
    const h = probe.getBoundingClientRect().height;
    probe.remove();
    if (h > 0) state.rowH = Math.ceil(h) + ROW_GAP;
    return state.rowH || 110;
  }

  // More than VIRTUALIZE_AFTER cards: the list is a spacer of the full height
  // and only the rows inside the column's scroll window (plus overscan) exist in
  // the DOM, positioned with transform. Cards that something depends on — the
  // one being dragged, lifted, focused, pending or landing — stay mounted even
  // when scrolled away, so pointer streams, focus and animations keep their node.
  function renderWindow(col) {
    const items = state.byStage[col.stage];
    if (col.collapsed) {
      col.nodes.forEach(n => n.remove());
      col.nodes.clear();
      return;
    }
    const rowH = rowHeight(col);
    col.list.style.height = Math.max(0, items.length * rowH - ROW_GAP) + 'px';
    const viewTop = col.el.scrollTop - col.list.offsetTop;
    const viewH = col.el.clientHeight || window.innerHeight;
    const start = Math.max(0, Math.floor(viewTop / rowH) - OVERSCAN);
    const end = Math.min(items.length, Math.ceil((viewTop + viewH) / rowH) + OVERSCAN);

    const pinned = new Set();
    const focused = document.activeElement && document.activeElement.closest && document.activeElement.closest('.pb-card');
    if (focused && col.el.contains(focused)) pinned.add(Number(focused.dataset.id));
    if (state.drag && state.drag.originStage === col.stage) pinned.add(state.drag.id);
    if (state.lift && state.lift.originStage === col.stage) pinned.add(state.lift.id);
    if (state.pending) pinned.add(state.pending.id);
    if (state.arriving && state.arriving.stage === col.stage) pinned.add(state.arriving.id);

    const want = [];
    for (let i = start; i < end; i++) want.push(i);
    if (pinned.size) items.forEach((c, i) => { if (pinned.has(c.id) && (i < start || i >= end)) want.push(i); });
    want.sort((a, b) => a - b);   // DOM order == visual order, so Tab order stays sane

    const keep = new Set();
    let prev = null;
    want.forEach(i => {
      const c = items[i];
      let node = col.nodes.get(c.id);
      if (!node) { node = CandidateCard.create(c); col.nodes.set(c.id, node); }
      CandidateCard.update(node, c, cardFlags(col.stage, c));
      const y = 'translate3d(0,' + (i * rowH) + 'px,0)';
      if (node.style.transform !== y) node.style.transform = y;
      node.setAttribute('aria-posinset', String(i + 1));
      node.setAttribute('aria-setsize', String(items.length));
      keep.add(c.id);
      const expected = prev ? prev.nextSibling : col.list.firstChild;
      if (expected !== node) col.list.insertBefore(node, expected);
      prev = node;
    });
    col.nodes.forEach((node, id) => {
      if (!keep.has(id)) { node.remove(); col.nodes.delete(id); }
    });
  }

  function scheduleWindow(col) {
    if (!col.virtual || col.raf) return;
    col.raf = requestAnimationFrame(() => { col.raf = 0; renderWindow(col); });
  }

  // Sticky header: name, count, and the column's share of the whole pipeline
  // as a bar (scaleX, so updating it never triggers layout) plus average AI score.
  function renderHeader(col) {
    const items = state.byStage[col.stage].filter(c => !isGhost(col.stage, c));
    const n = items.length;
    const share = state.total ? n / state.total : 0;
    col.countEl.textContent = String(n);
    col.fillEl.style.transform = 'scaleX(' + share.toFixed(4) + ')';
    const scored = items.filter(c => c.aiScore !== null && c.aiScore !== undefined);
    const avg = scored.length ? scored.reduce((s, c) => s + Number(c.aiScore), 0) / scored.length : null;
    col.labelEl.textContent = Math.round(share * 100) + '% of pipeline' + (avg !== null ? ' · avg AI ' + Math.round(avg) : '');
    col.pendingEl.hidden = !(state.pending && state.pending.proposed_stage_id === col.stage);
    col.toggle?.setAttribute('aria-label', (col.collapsed ? 'Show ' : 'Hide ') + label(col.stage) + ' candidates (' + n + ')');
  }

  /* ── Phone accordion (<=480px) ───────────────────────────────────────── */
  function setCollapsed(col, collapsed) {
    col.collapsed = collapsed;
    col.el.classList.toggle('is-collapsed', collapsed);
    col.toggle?.setAttribute('aria-expanded', String(!collapsed));
    if (state.phase !== 'loading' && state.phase !== 'error') {
      renderColumn(col);
      // An expanded virtual column only knows its viewport after layout.
      if (!collapsed && col.virtual) requestAnimationFrame(() => renderWindow(col));
    }
  }

  function applyLayoutMode() {
    const accordion = mqAccordion.matches;
    boardEl.classList.toggle('is-accordion', accordion);
    // Default: open the first column that has anyone in it, collapse the rest.
    const firstFull = STAGE_ORDER.find(s => state.byStage[s].length) || STAGE_ORDER[0];
    columns.forEach(col => {
      if (!accordion) { if (col.collapsed) setCollapsed(col, false); return; }
      if (!col.userToggled) setCollapsed(col, col.stage !== firstFull);
    });
  }

  /* ── Geometry for the fly animations ─────────────────────────────────── */
  // Where a card sits on screen, if it is actually visible there. Otherwise
  // (collapsed column, scrolled out of its column or off the board sideways)
  // the fallback is a shrunken rect at the column header and the clone fades.
  function slotRect(stage, id, base) {
    const col = columns.get(stage);
    const node = col.nodes.get(id);
    const colRect = col.el.getBoundingClientRect();
    const boardRect = boardEl.getBoundingClientRect();
    if (node && node.isConnected && !col.collapsed) {
      const r = node.getBoundingClientRect();
      const top = colRect.top + col.head.offsetHeight;
      const visible = r.width > 0 && r.bottom > top && r.top < colRect.bottom &&
        r.right > boardRect.left && r.left < boardRect.right;
      if (visible) return { rect: r, fade: false };
    }
    const h = col.head.getBoundingClientRect();
    const w = base ? base.width * 0.6 : h.width * 0.6;
    const ht = base ? base.height * 0.6 : 40;
    return { rect: { left: h.left + 12, top: h.top + 6, width: w, height: ht }, fade: true };
  }

  // A fixed-position clone of a card, on <body>, so it can travel between
  // columns without being clipped by a column's overflow or scrolled with it.
  function makeOverlay(node, rect) {
    const o = node.cloneNode(true);
    o.removeAttribute('tabindex');
    o.removeAttribute('role');
    o.removeAttribute('aria-describedby');
    o.removeAttribute('aria-posinset');
    o.removeAttribute('aria-setsize');
    o.setAttribute('aria-hidden', 'true');
    o.inert = true;
    o.classList.remove('is-drag-source', 'is-pressing', 'is-pending', 'is-ghost', 'is-arriving', 'is-lifted');
    o.classList.add('pb-drag-overlay');
    o.style.cssText = 'left:' + rect.left + 'px;top:' + rect.top + 'px;width:' + rect.width +
      'px;height:' + rect.height + 'px;transform:translate3d(0,0,0)';
    document.body.appendChild(o);
    overlays.add(o);
    return o;
  }

  function dropOverlay(o) { if (!o) return; o.remove(); overlays.delete(o); }

  // Animate a clone from `base` (its untransformed rect) to `to`, using only
  // transform and opacity so the browser can run it on the compositor.
  function flyTo(el, base, to, opts) {
    const fade = !!(opts && opts.fade);
    return new Promise(resolve => {
      if (reduced() || !to) { resolve(); return; }
      const sx = base.width ? to.width / base.width : 1;
      const sy = base.height ? to.height / base.height : 1;
      el.style.transformOrigin = '0 0';
      el.style.transition = 'transform ' + FLY_MS + 'ms ' + FLY_EASE + ', opacity ' + FLY_MS + 'ms ' + FLY_EASE;
      void el.offsetWidth;   // commit the start state before changing it
      el.style.transform = 'translate3d(' + (to.left - base.left) + 'px,' + (to.top - base.top) + 'px,0) scale(' + sx + ',' + sy + ')';
      if (fade) el.style.opacity = '0';
      let done = false;
      const finish = () => { if (done) return; done = true; el.removeEventListener('transitionend', onEnd); resolve(); };
      const onEnd = e => { if (e.target === el && e.propertyName === 'transform') finish(); };
      el.addEventListener('transitionend', onEnd);
      setTimeout(finish, FLY_MS + 80);   // transitionend is not guaranteed
    });
  }

  /* ══ Pointer sensor (mouse, touch, pen) ══════════════════════════════════
     Mouse: drag starts after MOUSE_SLOP px of travel.
     Touch/pen: drag starts after a LONG_PRESS_MS hold within TOUCH_SLOP px, so
     an ordinary swipe still scrolls the page (phone) or the board (tablet). */
  boardEl.addEventListener('pointerdown', onPointerDown);
  boardEl.addEventListener('dragstart', e => e.preventDefault());   // no native HTML5 DnD
  // Registered once and non-passive so it can stop the page scrolling under an
  // active touch drag. Before a drag activates it does nothing, so a normal
  // swipe scrolls as usual.
  boardEl.addEventListener('touchmove', e => { if (state.drag && state.drag.active) e.preventDefault(); }, { passive: false });
  boardEl.addEventListener('contextmenu', e => { if (state.drag) e.preventDefault(); });

  function onPointerDown(e) {
    if (state.phase !== 'idle' || state.drag || !e.isPrimary) return;
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    const node = e.target.closest('.pb-card');
    if (!node || !boardEl.contains(node) || node.classList.contains('is-ghost')) return;
    if (e.target.closest('a, button, input, select, textarea, [data-no-drag]')) return;
    const col = colOfNode(node);
    const s = {
      pointerId: e.pointerId, pointerType: e.pointerType,
      id: Number(node.dataset.id), originStage: col.stage, node,
      startX: e.clientX, startY: e.clientY, x: e.clientX, y: e.clientY,
      originX: 0, originY: 0,
      active: false, timer: 0, raf: 0, overlay: null, baseRect: null, targetStage: null,
    };
    state.drag = s;
    if (e.pointerType !== 'mouse') {
      node.classList.add('is-pressing');
      s.timer = setTimeout(() => activateDrag(s), LONG_PRESS_MS);
    }
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointercancel', onPointerCancel);
  }

  function onPointerMove(e) {
    const s = state.drag;
    if (!s || e.pointerId !== s.pointerId) return;
    s.x = e.clientX; s.y = e.clientY;
    if (!s.active) {
      const dist = Math.hypot(s.x - s.startX, s.y - s.startY);
      if (s.pointerType === 'mouse') { if (dist > MOUSE_SLOP) activateDrag(s); }
      else if (dist > TOUCH_SLOP) endDragSession();   // the finger moved first: it's a scroll
      return;
    }
    e.preventDefault();
  }

  function onPointerUp(e) {
    const s = state.drag;
    if (!s || e.pointerId !== s.pointerId) return;
    if (!s.active) { endDragSession(); return; }   // a tap or click: the click handler opens the preview
    suppressClickUntil = performance.now() + 400;
    finishPointerDrag(s, hitTestStage(e.clientX, e.clientY));
  }

  function onPointerCancel(e) {
    const s = state.drag;
    if (!s || e.pointerId !== s.pointerId) return;
    if (s.active) finishPointerDrag(s, null); else endDragSession();
  }

  function activateDrag(s) {
    if (state.drag !== s || s.active || state.phase !== 'idle') return;
    clearTimeout(s.timer);
    s.node.classList.remove('is-pressing');
    const rect = s.node.getBoundingClientRect();
    s.active = true;
    state.phase = 'dragging';
    s.baseRect = rect;
    s.originX = s.x; s.originY = s.y;
    s.overlay = makeOverlay(s.node, rect);
    boardEl.classList.add('is-dragging');
    document.body.classList.add('pb-dragging');
    try { s.node.setPointerCapture(s.pointerId); } catch (err) { /* pointer already gone */ }
    refreshCard(s.originStage, s.id);
    if (s.pointerType !== 'mouse' && navigator.vibrate) { try { navigator.vibrate(8); } catch (err) { /* unsupported */ } }
    const c = state.byId.get(s.id);
    announce('Picked up ' + c.name + '. Release over a stage to move them, or press Escape to cancel.');
    s.raf = requestAnimationFrame(() => dragFrame(s));
  }

  // One rAF loop per drag: move the clone (transform only), hit-test, and
  // auto-scroll. It keeps running while the pointer rests at an edge.
  function dragFrame(s) {
    if (state.drag !== s || !s.active) return;
    const dx = s.x - s.originX, dy = s.y - s.originY;
    s.overlay.style.transform = 'translate3d(' + dx + 'px,' + dy + 'px,0) rotate(1.5deg)';
    const stage = hitTestStage(s.x, s.y);
    setDropTarget(s, stage);
    autoScroll(s.x, s.y, stage ? columns.get(stage) : null);
    s.raf = requestAnimationFrame(() => dragFrame(s));
  }

  function hitTestStage(x, y) {
    const el = document.elementFromPoint(x, y);   // the clone is pointer-events:none
    const colEl = el && el.closest('[data-pb-col]');
    return colEl && boardEl.contains(colEl) ? colEl.dataset.stage : null;
  }

  function setDropTarget(s, stage) {
    if (s.targetStage === stage) return;
    if (s.targetStage) columns.get(s.targetStage)?.el.classList.remove('is-drop-target');
    s.targetStage = stage;
    if (stage && stage !== s.originStage) columns.get(stage)?.el.classList.add('is-drop-target');
  }

  function edgeStep(pos, lo, hi) {
    if (hi - lo < EDGE * 2.5) return 0;
    if (pos < lo + EDGE) return -Math.ceil(MAX_SCROLL_STEP * Math.min(1, (lo + EDGE - pos) / EDGE));
    if (pos > hi - EDGE) return Math.ceil(MAX_SCROLL_STEP * Math.min(1, (pos - (hi - EDGE)) / EDGE));
    return 0;
  }

  function autoScroll(x, y, col) {
    if (boardEl.scrollWidth > boardEl.clientWidth) {
      const r = boardEl.getBoundingClientRect();
      if (y >= r.top && y <= r.bottom) { const v = edgeStep(x, r.left, r.right); if (v) boardEl.scrollLeft += v; }
    }
    if (col && col.el.scrollHeight > col.el.clientHeight) {
      const r = col.el.getBoundingClientRect();
      const v = edgeStep(y, r.top + col.head.offsetHeight, r.bottom);
      if (v) col.el.scrollTop += v;
    }
    const root = document.scrollingElement || document.documentElement;
    if (root.scrollHeight > window.innerHeight) {
      const v = edgeStep(y, 0, window.innerHeight);
      if (v) window.scrollBy(0, v);
    }
  }

  // Tear down the pointer session. Leaves the clone alone; callers decide
  // where it flies.
  function endDragSession() {
    const s = state.drag;
    if (!s) return;
    clearTimeout(s.timer);
    cancelAnimationFrame(s.raf);
    s.node.classList.remove('is-pressing');
    try { s.node.releasePointerCapture(s.pointerId); } catch (err) { /* not captured */ }
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('pointercancel', onPointerCancel);
    boardEl.classList.remove('is-dragging');
    document.body.classList.remove('pb-dragging');
    if (s.targetStage) columns.get(s.targetStage)?.el.classList.remove('is-drop-target');
    state.drag = null;
    if (s.active) {
      state.phase = 'idle';
      refreshCard(s.originStage, s.id);
    }
  }

  function finishPointerDrag(s, target) {
    const overlay = s.overlay, base = s.baseRect;
    endDragSession();
    if (!target || target === s.originStage) {
      settleBack(s.id, s.originStage, overlay, base);
      if (!target) announce('Drag cancelled. Nothing changed.');
      return;
    }
    beginPending(s.id, s.originStage, target, overlay, base);
  }

  // Dropped outside the board or back on its own column: the clone glides
  // home and nothing else happens.
  function settleBack(id, stage, overlay, base) {
    state.phase = 'settling';
    state.arriving = { id, stage };
    refreshCard(stage, id);
    const { rect, fade } = slotRect(stage, id, base);
    flyTo(overlay, base, rect, { fade }).then(() => {
      dropOverlay(overlay);
      if (state.arriving && state.arriving.id === id && state.arriving.stage === stage) state.arriving = null;
      refreshCard(stage, id);
      if (state.phase === 'settling') state.phase = 'idle';
    });
  }

  /* ══ Keyboard sensor ═════════════════════════════════════════════════════
     On a focused card: Space picks up; Left/Right (or Up/Down) choose a stage;
     Space/Enter drops (opens the approval modal); Escape or Tab cancels.
     Not lifted: Enter opens the preview, Up/Down/Home/End move within a column,
     Left/Right move to the neighbouring column. */
  boardEl.addEventListener('keydown', onBoardKey);

  function onBoardKey(e) {
    const node = e.target.closest && e.target.closest('.pb-card');
    if (!node || e.target !== node) return;   // keys on the View/Schedule links behave normally
    const col = colOfNode(node);
    const id = Number(node.dataset.id);
    if (state.lift) { onLiftKey(e); return; }
    if (state.phase !== 'idle') return;
    switch (e.key) {
      case ' ': case 'Spacebar': e.preventDefault(); startLift(col.stage, id); break;
      case 'Enter': e.preventDefault(); openPreview(state.byId.get(id)); break;
      case 'ArrowDown': e.preventDefault(); moveFocusWithin(col, id, 1); break;
      case 'ArrowUp': e.preventDefault(); moveFocusWithin(col, id, -1); break;
      case 'Home': e.preventDefault(); focusIndex(col, 0); break;
      case 'End': e.preventDefault(); focusIndex(col, Infinity); break;
      case 'ArrowRight': e.preventDefault(); moveFocusAcross(col, id, 1); break;
      case 'ArrowLeft': e.preventDefault(); moveFocusAcross(col, id, -1); break;
    }
  }

  function startLift(stage, id) {
    state.lift = { id, originStage: stage, targetStage: stage };
    state.phase = 'lifted';
    refreshCard(stage, id);
    announce('Picked up ' + state.byId.get(id).name + ' in ' + label(stage) +
      '. Use the arrow keys to choose a stage, Space to drop, Escape to cancel.');
  }

  function onLiftKey(e) {
    const L = state.lift;
    switch (e.key) {
      case 'ArrowLeft': case 'ArrowUp': case 'ArrowRight': case 'ArrowDown': {
        e.preventDefault();
        const dir = (e.key === 'ArrowLeft' || e.key === 'ArrowUp') ? -1 : 1;
        const i = Math.max(0, Math.min(STAGE_ORDER.length - 1, STAGE_ORDER.indexOf(L.targetStage) + dir));
        setLiftTarget(STAGE_ORDER[i]);
        break;
      }
      case ' ': case 'Spacebar': case 'Enter': e.preventDefault(); dropLift(); break;
      case 'Escape': e.preventDefault(); e.stopPropagation(); cancelLift(true); break;
      case 'Tab': cancelLift(true); break;
    }
  }

  function setLiftTarget(stage) {
    const L = state.lift;
    if (!L || L.targetStage === stage) return;
    columns.get(L.targetStage)?.el.classList.remove('is-drop-target');
    L.targetStage = stage;
    const col = columns.get(stage);
    if (stage !== L.originStage) col.el.classList.add('is-drop-target');
    col.el.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reduced() ? 'auto' : 'smooth' });
    announce(stage === L.originStage
      ? 'Back over ' + label(stage) + '. Dropping here changes nothing.'
      : 'Over ' + label(stage) + '. Press Space to drop.');
  }

  function cancelLift(speak) {
    const L = state.lift;
    if (!L) return;
    columns.get(L.targetStage)?.el.classList.remove('is-drop-target');
    state.lift = null;
    state.phase = 'idle';
    refreshCard(L.originStage, L.id);
    if (speak) announce('Move cancelled. ' + state.byId.get(L.id).name + ' stays in ' + label(L.originStage) + '.');
  }

  function dropLift() {
    const L = state.lift;
    cancelLift(false);
    if (L.targetStage === L.originStage) {
      announce('Dropped back in ' + label(L.originStage) + '. Nothing changed.');
      return;
    }
    const node = columns.get(L.originStage).nodes.get(L.id);
    const base = node.getBoundingClientRect();
    beginPending(L.id, L.originStage, L.targetStage, makeOverlay(node, base), base);
  }

  function visibleItems(col) { return state.byStage[col.stage].filter(c => !isGhost(col.stage, c)); }

  function moveFocusWithin(col, id, delta) {
    const items = visibleItems(col);
    const i = items.findIndex(c => c.id === id);
    focusIndex(col, Math.max(0, Math.min(items.length - 1, i + delta)));
  }

  function moveFocusAcross(col, id, dir) {
    const from = visibleItems(col).findIndex(c => c.id === id);
    let i = STAGE_ORDER.indexOf(col.stage) + dir;
    while (i >= 0 && i < STAGE_ORDER.length) {
      const next = columns.get(STAGE_ORDER[i]);
      if (visibleItems(next).length) {
        if (next.collapsed) { next.userToggled = true; setCollapsed(next, false); }
        focusIndex(next, from);
        return;
      }
      i += dir;
    }
  }

  function focusIndex(col, index) {
    const items = visibleItems(col);
    if (!items.length) return;
    const i = Math.max(0, Math.min(items.length - 1, index));
    focusCard(col.stage, items[i].id);
  }

  function focusCard(stage, id, opts) {
    const col = columns.get(stage);
    if (!col) return;
    let node = col.nodes.get(id);
    if (!node && col.virtual && !col.collapsed) {
      const idx = state.byStage[stage].findIndex(c => c.id === id);
      if (idx < 0) return;
      col.el.scrollTop = col.list.offsetTop + idx * rowHeight(col) - col.head.offsetHeight - 8;
      renderWindow(col);
      node = col.nodes.get(id);
    }
    if (node && !node.inert) node.focus({ preventScroll: !!(opts && opts.preventScroll) });
  }

  /* ══ Pending → approve / revert ══════════════════════════════════════════ */
  function beginPending(id, origin, proposed, overlay, base) {
    const c = state.byId.get(id);
    state.pending = { id, original_stage_id: origin, proposed_stage_id: proposed, override: false };
    state.byStage[proposed].unshift(c);   // newest activity sorts first, same as the server
    state.phase = 'pending';
    const target = columns.get(proposed);
    if (target.collapsed) setCollapsed(target, false);
    target.el.scrollTop = 0;
    state.arriving = { id, stage: proposed };
    renderColumn(columns.get(origin));
    renderColumn(target);
    const { rect, fade } = slotRect(proposed, id, base);
    flyTo(overlay, base, rect, { fade }).then(() => {
      dropOverlay(overlay);
      if (state.arriving && state.arriving.id === id && state.arriving.stage === proposed) {
        state.arriving = null;
        refreshCard(proposed, id);
      }
    });
    openApproval();
  }

  function openApproval() {
    const p = state.pending;
    const c = state.byId.get(p.id);
    const from = p.original_stage_id, to = p.proposed_stage_id;
    const key = from + '>' + to;
    const skip = isSkip(from, to);
    let warning = MESSAGES[key] || null, tone = 'info', blocked = false, requireAck = false;
    if (skip && !CAN_OVERRIDE) {
      blocked = true; tone = 'blocked';
      warning = 'This candidate cannot be moved directly to ' + label(to) + '. Complete the required recruitment stages first.';
    } else if (skip) {
      requireAck = true; p.override = true;
      warning = (MESSAGES[key] || 'This skips one or more required stages.') + ' As an admin, you can override this.';
    }
    ApprovalModal.open({
      id: c.id, name: c.name, from, to, warning, tone, blocked, requireAck,
      confirmLabel: requireAck ? 'Override & confirm' : 'Confirm move',
      onConfirm: commitPending,
      onCancel: revertPending,
    });
  }

  // Cancel, backdrop click, or Escape. Animates the card from the proposed
  // column back to its original slot, then returns to idle. No request is made.
  function revertPending(reason) {
    const p = state.pending;
    if (!p || state.phase !== 'pending') return;
    state.phase = 'reverting';
    ApprovalModal.close();
    overlays.forEach(dropOverlay);   // a drop animation may still be in flight
    const proposedCol = columns.get(p.proposed_stage_id);
    const originCol = columns.get(p.original_stage_id);
    const fromNode = proposedCol.nodes.get(p.id);
    let clone = null, base = null;
    if (fromNode && fromNode.isConnected && !proposedCol.collapsed) {
      base = fromNode.getBoundingClientRect();
      if (base.width) clone = makeOverlay(fromNode, base);
    }
    const list = state.byStage[p.proposed_stage_id];
    const i = list.findIndex(c => c.id === p.id);
    if (i > -1) list.splice(i, 1);
    state.pending = null;
    state.arriving = clone ? { id: p.id, stage: p.original_stage_id } : null;
    renderColumn(proposedCol);
    renderColumn(originCol);
    const done = () => {
      dropOverlay(clone);
      if (state.arriving && state.arriving.id === p.id && state.arriving.stage === p.original_stage_id) {
        state.arriving = null;
        refreshCard(p.original_stage_id, p.id);
      }
      if (state.phase === 'reverting') state.phase = 'idle';
      focusCard(p.original_stage_id, p.id, { preventScroll: true });
    };
    if (clone) {
      const { rect, fade } = slotRect(p.original_stage_id, p.id, base);
      flyTo(clone, base, rect, { fade }).then(done);
    } else {
      done();
    }
    const c = state.byId.get(p.id);
    announce('Move cancelled. ' + c.name + ' stays in ' + label(p.original_stage_id) + '.');
  }

  // Confirm. The only caller of move_stage.
  async function commitPending() {
    const p = state.pending;
    if (!p || state.phase !== 'pending') return;
    state.phase = 'committing';
    ApprovalModal.setBusy(true);
    const body = new URLSearchParams({
      action: 'move_stage', csrf: CSRF, id: String(p.id),
      stage: p.proposed_stage_id, from: p.original_stage_id, override: p.override ? '1' : '',
    });
    let data, status = 0;
    try {
      const res = await fetch('pipeline.php', {
        method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' },
      });
      status = res.status;
      data = await res.json().catch(() => ({ ok: false }));
    } catch (err) {
      data = { ok: false, message: 'Network error. Check your connection.' };
    }
    if (state.pending !== p) return;

    if (data && data.ok) {
      const c = state.byId.get(p.id);
      const origin = state.byStage[p.original_stage_id];
      const i = origin.indexOf(c);
      if (i > -1) origin.splice(i, 1);
      c.stage = p.proposed_stage_id;
      c.updatedAgo = 'just now';
      state.pending = null;
      state.phase = 'idle';
      ApprovalModal.close();
      renderColumn(columns.get(p.original_stage_id));
      renderColumn(columns.get(p.proposed_stage_id));
      focusCard(p.proposed_stage_id, p.id, { preventScroll: true });
      const msg = c.name + ' moved from ' + label(data.from) + ' to ' + label(data.to) + '.';
      showToast('✓ ' + msg);
      announce(msg);
      return;
    }

    // Nothing was saved. Stay pending so the person reads why; retry is only
    // offered when retrying could work.
    state.phase = 'pending';
    ApprovalModal.setBusy(false);
    const fatal = ['stale', 'skip_blocked'].includes(data && data.error) || [400, 404, 419].includes(status);
    const reason = (data && (data.message || data.error)) || 'The move could not be saved.';
    ApprovalModal.showError(reason + ' Nothing was changed.' + (fatal ? ' Cancel to put the card back.' : ' You can try again.'), fatal);
  }

  /* ══ ApprovalModal ═══════════════════════════════════════════════════════
     open(props)   props = { id, name, from, to, warning, tone:'info'|'blocked',
                             blocked, requireAck, confirmLabel,
                             onConfirm(), onCancel(reason: 'cancel'|'backdrop'|'escape') }
     close()       hides it; does not call onCancel (callers own state)
     setBusy(bool) disables every exit while the save request is in flight
     showError(message, fatal)                                              */
  const ApprovalModal = (function () {
    const root = document.querySelector('[data-approval-modal]');
    document.body.appendChild(root);   // escape any transformed ancestor
    const box = root.querySelector('.pb-modal-box');
    const q = sel => root.querySelector(sel);
    const els = {
      name: q('[data-approval-name]'), from: q('[data-approval-from]'), to: q('[data-approval-to]'),
      warning: q('[data-approval-warning]'), ackRow: q('[data-approval-ack-row]'), ack: q('[data-approval-ack]'),
      error: q('[data-approval-error]'), profile: q('[data-approval-profile]'),
      cancel: q('[data-approval-cancel]'), confirm: q('[data-approval-confirm]'),
    };
    let props = null, busy = false, downOnBackdrop = false;

    function badge(el, stage) { el.className = 'stage-badge stage-' + stage; el.textContent = label(stage); }
    function sync() {
      if (!props) return;
      els.confirm.disabled = busy || props.blocked || !!props.fatal || (props.requireAck && !els.ack.checked);
      els.cancel.disabled = busy;
    }

    function open(p) {
      props = Object.assign({}, p);
      busy = false;
      els.name.textContent = p.name;
      badge(els.from, p.from);
      badge(els.to, p.to);
      els.warning.hidden = !p.warning;
      els.warning.textContent = p.warning || '';
      els.warning.classList.toggle('blocked', p.tone === 'blocked');
      els.ackRow.hidden = !p.requireAck;
      els.ack.checked = false;
      els.error.hidden = true;
      els.error.textContent = '';
      els.profile.href = 'candidate.php?id=' + encodeURIComponent(p.id);
      els.confirm.textContent = p.confirmLabel;
      root.removeAttribute('aria-busy');
      sync();
      root.hidden = false;
      document.body.classList.add('modal-open');
      requestAnimationFrame(() => root.classList.add('is-open'));
      document.addEventListener('keydown', onKey, true);
      (p.blocked ? els.cancel : p.requireAck ? els.ack : els.confirm).focus();
    }

    function close() {
      if (root.hidden) return;
      root.classList.remove('is-open');
      root.hidden = true;
      document.body.classList.remove('modal-open');
      document.removeEventListener('keydown', onKey, true);
      props = null;
      busy = false;
    }

    function setBusy(b) {
      if (!props) return;
      busy = b;
      root.setAttribute('aria-busy', String(b));
      els.confirm.textContent = b ? 'Saving…' : props.confirmLabel;
      sync();
    }

    function showError(message, fatal) {
      if (!props) return;
      props.fatal = !!fatal;
      els.error.textContent = message;
      els.error.hidden = false;
      sync();
      (fatal ? els.cancel : els.confirm).focus();
    }

    function dismiss(reason) {
      if (!props || busy) return;
      props.onCancel(reason);
    }

    // Backdrop = the overlay element itself. Both the press and the release
    // must land on it, so a text selection dragged out of the box never cancels.
    root.addEventListener('pointerdown', e => { downOnBackdrop = e.target === e.currentTarget; });
    root.addEventListener('click', e => {
      if (e.target === e.currentTarget && downOnBackdrop) dismiss('backdrop');
      downOnBackdrop = false;
    });
    els.cancel.addEventListener('click', () => dismiss('cancel'));
    els.confirm.addEventListener('click', () => { if (props && !els.confirm.disabled) props.onConfirm(); });
    els.ack.addEventListener('change', sync);

    function onKey(e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        e.stopImmediatePropagation();   // the sidebar/notification Escape handlers must not also fire
        dismiss('escape');
        return;
      }
      if (e.key === 'Tab') {   // keep focus inside the dialog
        const f = Array.from(box.querySelectorAll('a[href], button, input'))
          .filter(el => !el.disabled && el.offsetParent !== null);
        if (!f.length) return;
        const first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        else if (!box.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
      }
    }

    return { open, close, setBusy, showError, isOpen: () => !root.hidden };
  })();

  /* ── Clicks: preview, and swallowing the click a drag leaves behind ─── */
  window.addEventListener('click', e => {
    if (performance.now() < suppressClickUntil && !e.target.closest('[data-approval-modal]')) {
      e.preventDefault();
      e.stopPropagation();
      suppressClickUntil = 0;
    }
  }, true);

  boardEl.addEventListener('click', e => {
    const node = e.target.closest('.pb-card');
    if (!node || e.target.closest('a, button, input, [data-no-drag]')) return;
    if (state.phase !== 'idle' || node.classList.contains('is-ghost') || node.classList.contains('is-pending')) return;
    const c = state.byId.get(Number(node.dataset.id));
    if (c) openPreview(c);
  });

  // Escape during a pointer drag: the card glides back, nothing is proposed.
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (state.drag && state.drag.active) { e.preventDefault(); finishPointerDrag(state.drag, null); }
    else if (previewPanel && !previewPanel.hidden) closePreview();
  });

  /* ── Candidate preview ───────────────────────────────────────────────── */
  const previewPanel = document.querySelector('[data-preview-overlay]');
  let previewReturn = null;

  function closePreview() {
    if (!previewPanel || previewPanel.hidden) return;
    previewPanel.hidden = true;
    previewPanel.classList.remove('open');
    document.body.classList.remove('modal-open');
    if (previewReturn && previewReturn.isConnected) previewReturn.focus({ preventScroll: true });
    previewReturn = null;
  }

  function openPreview(c) {
    if (!previewPanel || !c) return;
    previewReturn = document.activeElement;
    document.querySelector('[data-preview-name]').textContent = c.name || '';
    document.querySelector('[data-preview-title]').textContent = c.title || '';
    const stageEl = document.querySelector('[data-preview-stage]');
    stageEl.textContent = label(c.stage);
    stageEl.className = 'stage-badge stage-' + c.stage;

    const photo = document.querySelector('[data-preview-photo]');
    let avatar;
    if (c.profileImage) {
      avatar = document.createElement('img');
      avatar.className = 'candidate-preview-avatar avatar-image-large';
      avatar.src = c.profileImage;
      avatar.alt = c.name || 'Candidate';
    } else {
      avatar = document.createElement('span');
      avatar.className = 'candidate-preview-avatar';
      avatar.textContent = c.initials || '';
    }
    photo.replaceChildren(avatar);

    const has = v => v !== null && v !== undefined && v !== '';
    document.querySelector('[data-preview-ai]').textContent = has(c.aiScore) ? fmtScore(c.aiScore) + ' / 100' : 'Not analyzed yet';
    document.querySelector('[data-preview-screening]').textContent = has(c.screeningScore) ? c.screeningScore + ' / 100' : '—';
    document.querySelector('[data-preview-interview]').textContent = has(c.interviewScore) ? c.interviewScore + ' / 100' : '—';
    const resumeEl = document.querySelector('[data-preview-resume]');
    if (c.resume) {
      const a = document.createElement('a');
      a.href = c.resume; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'View resume';
      resumeEl.replaceChildren(a);
    } else {
      resumeEl.textContent = 'Not uploaded';
    }
    const notes = c.noteCount || 0;
    document.querySelector('[data-preview-notes]').textContent = notes + ' ' + (notes === 1 ? 'note' : 'notes');
    document.querySelector('[data-preview-latest-note]').textContent = c.latestNote ? '“' + c.latestNote + '”' : '';
    document.querySelector('[data-preview-full-link]').href = 'candidate.php?id=' + encodeURIComponent(c.id);
    previewPanel.hidden = false;
    previewPanel.classList.add('open');
    document.body.classList.add('modal-open');
    previewPanel.querySelector('[data-preview-close]')?.focus();
  }

  document.querySelectorAll('[data-preview-close]').forEach(btn => btn.addEventListener('click', closePreview));
  previewPanel?.addEventListener('click', e => { if (e.target === previewPanel) closePreview(); });

  /* ── Loading ─────────────────────────────────────────────────────────── */
  function skeletonNodes() {
    return [0, 1, 2].map(() => {
      const d = document.createElement('div');
      d.className = 'pb-skel';
      d.setAttribute('aria-hidden', 'true');
      d.innerHTML = '<span class="pb-skel-avatar"></span><span class="pb-skel-line"></span><span class="pb-skel-line short"></span>';
      return d;
    });
  }

  function loadBoard() {
    state.phase = 'loading';
    boardEl.setAttribute('aria-busy', 'true');
    document.querySelector('[data-pb-load-error]')?.remove();
    columns.forEach(col => {
      if (!col.list.querySelector('.pb-skel')) col.list.replaceChildren(...skeletonNodes());
      col.nodes.clear(); col.emptyEl = null; col.virtual = false;
      col.list.classList.remove('is-virtual'); col.list.style.height = '';
      col.countEl.textContent = '—';
      col.labelEl.textContent = 'Loading…';
    });
    const url = 'pipeline.php?action=board_data' + (QUERY ? '&' + QUERY : '');
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(data => { if (!data || !data.ok || !Array.isArray(data.candidates)) throw new Error('bad payload'); hydrate(data.candidates); })
      .catch(showLoadError);
  }

  function hydrate(list) {
    STAGE_ORDER.forEach(s => { state.byStage[s] = []; });
    state.byId.clear();
    list.forEach(c => {
      if (!state.byStage[c.stage]) return;
      state.byStage[c.stage].push(c);
      state.byId.set(c.id, c);
    });
    state.total = state.byId.size;
    state.rowH = 0;
    columns.forEach(col => { col.list.replaceChildren(); col.nodes.clear(); col.emptyEl = null; col.virtual = false; });
    state.phase = 'idle';
    boardEl.setAttribute('aria-busy', 'false');
    applyLayoutMode();
    renderAllColumns();
  }

  function showLoadError() {
    state.phase = 'error';
    boardEl.setAttribute('aria-busy', 'false');
    columns.forEach(col => {
      const p = document.createElement('p');
      p.className = 'pb-empty';
      p.textContent = 'Not loaded';
      col.list.replaceChildren(p);
      col.labelEl.textContent = '';
    });
    const box = document.createElement('div');
    box.className = 'notice pb-load-error';
    box.setAttribute('role', 'alert');
    box.setAttribute('data-pb-load-error', '');
    box.textContent = "Couldn't load the pipeline. Your session may have expired. ";
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'btn secondary small';
    retry.textContent = 'Retry';
    retry.addEventListener('click', loadBoard);
    box.appendChild(retry);
    boardEl.before(box);
  }

  /* ── Viewport changes ────────────────────────────────────────────────── */
  let resizeRaf = 0;
  window.addEventListener('resize', () => {
    if (resizeRaf) return;
    resizeRaf = requestAnimationFrame(() => {
      resizeRaf = 0;
      columns.forEach(col => { if (col.virtual) renderWindow(col); });
    });
  }, { passive: true });

  const onModeChange = () => { if (state.phase !== 'loading' && state.phase !== 'error') applyLayoutMode(); else boardEl.classList.toggle('is-accordion', mqAccordion.matches); };
  if (mqAccordion.addEventListener) mqAccordion.addEventListener('change', onModeChange);
  else if (mqAccordion.addListener) mqAccordion.addListener(onModeChange);

  // Leaving the page mid-gesture must not strand a clone or a lifted card.
  window.addEventListener('pagehide', () => {
    if (state.drag) { const o = state.drag.overlay; endDragSession(); dropOverlay(o); }
    if (state.lift) cancelLift(false);
  });
  window.addEventListener('pageshow', () => { if (!ApprovalModal.isOpen()) document.body.classList.remove('modal-open'); closePreview(); });

  boardEl.classList.toggle('is-accordion', mqAccordion.matches);
  loadBoard();
})();
