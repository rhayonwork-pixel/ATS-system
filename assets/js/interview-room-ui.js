/* ============================================================================
   INTERVIEW ROOM — meeting UI
   Loaded by interview-room.php. Owns everything the person in the meeting
   touches: the controls, the side panel, chat, the status indicators,
   Picture-in-Picture and the floating window.

   It does NOT own the media or the connection. interview-room.php keeps the
   state machine, getUserMedia, the WebRTC signalling over api/interview/* and
   the join/leave flow, and publishes what happens on a small event bus:

     ACME_ROOM.on('local-stream',  stream)   camera + mic are live (sticky)
     ACME_ROOM.on('joined',        {stream}) the meeting screen is mounted
     ACME_ROOM.on('pc',            pc)       RTCPeerConnection created (sticky)
     ACME_ROOM.on('remote-stream', stream)   the other side's media arrived
     ACME_ROOM.on('channel-open',  channel)  data channel ready
     ACME_ROOM.on('peer:<type>',   message)  a message from the other side
     ACME_ROOM.on('teardown',      null)     the call is closing

   AUDIO CONTINUITY — the rule this file must never break
   ------------------------------------------------------
   Minimising, entering Picture-in-Picture and switching panels are layout
   changes only. No track is ever stopped, no srcObject is ever cleared, and the
   <video> that carries the remote audio is never removed from the document. The
   floating window has its own <video> element that shares the same MediaStream
   and is permanently muted, so there is exactly one audio path at all times.
   ========================================================================== */
(function () {
  'use strict';

  const ROOM = (window.ACME_ROOM = window.ACME_ROOM || { onMount: [], onUnmount: [] });
  if (!ROOM.on) return;                 // inline bus missing: nothing to attach to

  /* ── Settings ─────────────────────────────────────────────────────────
     Per browser, not per meeting: the same preferences apply next time. */
  const SETTINGS_KEY = 'acme-room-settings';
  const DEFAULTS = { autoPip: true, miniOnBlur: false, mirror: true, place: 'bottom-right' };
  let settings = Object.assign({}, DEFAULTS);
  try { Object.assign(settings, JSON.parse(localStorage.getItem(SETTINGS_KEY) || '{}')); } catch (e) {}
  function saveSettings() {
    try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings)); } catch (e) {}
  }

  /* ── Live UI state ───────────────────────────────────────────────────── */
  const S = {
    mounted: false,
    micOn: true, camOn: true, sharing: false, hand: false,
    minimised: false, pipOn: false,
    panel: null, unread: 0,
    localStream: null, remoteStream: null, screenStream: null, cameraTrack: null,
    channel: null,
    peer: { joined: false, mic: true, cam: true, hand: false },
    audio: { ctx: null, nodes: [], raf: 0 },
  };

  const $ = sel => document.querySelector(sel);
  const $$ = sel => Array.prototype.slice.call(document.querySelectorAll(sel));
  const live = () => !!$('[data-call]');
  const roomEl = () => $('[data-room]');
  const myName = () => (roomEl() && roomEl().dataset.myName) || 'You';
  const peerName = () => {
    const el = $('[data-tile="peer"] .mr-name');
    return (el && el.textContent.trim()) || 'Participant';
  };

  function setPressed(sel, on) {
    $$(sel).forEach(function (b) {
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.classList.toggle('is-on', !!on);
    });
  }

  /* ── Data channel: chat + participant state ──────────────────────────── */
  function send(type, data) {
    const ch = S.channel;
    if (!ch || ch.readyState !== 'open') return false;
    try { ch.send(JSON.stringify(Object.assign({ type: type }, data || {}))); return true; }
    catch (e) { return false; }
  }
  function sendState() {
    send('state', { mic: S.micOn, cam: S.camOn, hand: S.hand, name: myName() });
  }

  /* ══ Controls ══════════════════════════════════════════════════════════ */
  function toggleTrack(kind, want) {
    const stream = S.localStream;
    if (!stream) return want;
    const tracks = stream.getTracks().filter(t => t.kind === kind);
    if (!tracks.length) return want;
    tracks.forEach(t => { t.enabled = want; });
    return want;
  }

  function setMic(on) {
    S.micOn = toggleTrack('audio', on);
    setPressed('[data-toggle-mic-call], [data-mini-mic]', S.micOn);
    $$('[data-self-mic], [data-people-mic-you]').forEach(el => el.classList.toggle('is-muted', !S.micOn));
    updatePeopleText();
    sendState();
  }

  function setCam(on) {
    S.camOn = toggleTrack('video', on);
    setPressed('[data-toggle-cam-call], [data-mini-cam]', S.camOn);
    // Camera off shows the avatar instead of a black rectangle.
    const avatar = $('[data-preview-empty-call]');
    if (avatar) avatar.hidden = S.camOn && !!S.localStream;
    updatePeopleText();
    updateMiniSource();
    sendState();
  }

  function updatePeopleText() {
    const you = $('[data-people-status-you]');
    if (you) you.textContent = 'Mic ' + (S.micOn ? 'on' : 'off') + ' · Cam ' + (S.camOn ? 'on' : 'off');
    const peer = $('[data-people-status-peer]');
    if (peer && S.peer.joined) peer.textContent = 'Mic ' + (S.peer.mic ? 'on' : 'off') + ' · Cam ' + (S.peer.cam ? 'on' : 'off');
    const count = $('[data-people-count]');
    if (count) count.textContent = String(S.peer.joined ? 2 : 1);
  }

  /* Screen share. The presented screen becomes its own tile AND replaces the
     outgoing camera track, so the other side actually sees it — replaceTrack
     keeps the existing connection, no renegotiation needed. */
  async function toggleShare() {
    if (S.sharing) { stopShare(); return; }
    let display;
    try {
      display = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
    } catch (e) { return; }                       // the picker was dismissed
    S.screenStream = display;
    S.sharing = true;
    setPressed('[data-toggle-share]', true);

    const track = display.getVideoTracks()[0];
    const tile = $('[data-tile="screen"]');
    const video = $('[data-screen-video]');
    const placeholder = $('[data-screen-placeholder]');
    if (video) { video.srcObject = display; }
    if (placeholder) placeholder.hidden = true;
    if (tile) tile.hidden = false;
    const owner = $('[data-screen-owner]');
    if (owner) owner.textContent = myName() + ' is presenting';

    const sender = senderFor('video');
    if (sender) {
      S.cameraTrack = S.cameraTrack || sender.track;
      sender.replaceTrack(track).catch(() => {});
    }
    send('share', { on: true, name: myName() });
    track.addEventListener('ended', stopShare);   // the browser's own "Stop sharing"
  }

  function stopShare() {
    if (!S.sharing) return;
    S.sharing = false;
    setPressed('[data-toggle-share]', false);
    if (S.screenStream) { S.screenStream.getTracks().forEach(t => t.stop()); S.screenStream = null; }
    const tile = $('[data-tile="screen"]');
    if (tile) tile.hidden = true;
    const video = $('[data-screen-video]');
    if (video) video.srcObject = null;            // a stopped screen, not the call
    const sender = senderFor('video');
    const cam = S.cameraTrack || (S.localStream && S.localStream.getVideoTracks()[0]);
    if (sender && cam) sender.replaceTrack(cam).catch(() => {});
    send('share', { on: false });
  }

  function senderFor(kind) {
    const pc = ROOM.pc;
    if (!pc || !pc.getSenders) return null;
    return pc.getSenders().find(s => s.track && s.track.kind === kind) || null;
  }

  function toggleHand() {
    S.hand = !S.hand;
    setPressed('[data-toggle-hand]', S.hand);
    const badge = $('[data-hand-badge="self"]');
    if (badge) badge.hidden = !S.hand;
    sendState();
    toast(S.hand ? 'You raised your hand' : 'Hand lowered');
  }

  function toast(message) {
    const t = $('#toast');
    if (!t) return;
    t.textContent = message;
    t.classList.add('show');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => t.classList.remove('show'), 2600);
  }

  /* ══ Side panel ════════════════════════════════════════════════════════ */
  function openPanel(name) {
    const root = $('[data-panel-root]');
    if (!root) return;
    const pane = $('[data-panel="' + name + '"]');
    if (!pane) return;
    S.panel = name;
    root.hidden = false;
    // The transition needs the element to be laid out before the class lands.
    requestAnimationFrame(() => { const c = $('[data-call]'); if (c) c.classList.add('panel-open'); });
    $$('[data-panel]').forEach(p => { p.hidden = p.dataset.panel !== name; });
    $$('[data-panel-tab]').forEach(t => t.setAttribute('aria-selected', String(t.dataset.panelTab === name)));
    $$('[data-toggle-panel]').forEach(b => {
      const on = b.dataset.togglePanel === name;
      b.setAttribute('aria-pressed', String(on));
      b.classList.toggle('is-on', on);
    });
    if (name === 'chat') clearUnread();
    if (name === 'notes') { const f = $('[data-notes-field]'); if (f) f.focus(); }
  }

  function closePanel() {
    const call = $('[data-call]');
    const root = $('[data-panel-root]');
    S.panel = null;
    if (call) call.classList.remove('panel-open');
    $$('[data-toggle-panel]').forEach(b => { b.setAttribute('aria-pressed', 'false'); b.classList.remove('is-on'); });
    if (!root) return;
    // Let the slide-out finish before the element leaves the layout.
    setTimeout(() => { if (!S.panel && root) root.hidden = true; }, 240);
  }

  function togglePanel(name) {
    if (S.panel === name) closePanel(); else openPanel(name);
  }

  /* ══ Chat ══════════════════════════════════════════════════════════════ */
  function addMessage(from, text, mine, delivered) {
    const log = $('[data-chat-log]');
    if (!log) return;
    const empty = log.querySelector('.mr-chat-empty');
    if (empty) empty.remove();
    const row = document.createElement('div');
    row.className = 'mr-msg' + (mine ? ' is-me' : '') + (delivered === false ? ' is-undelivered' : '');
    const who = document.createElement('strong');
    who.textContent = mine ? 'You' : from;
    const body = document.createElement('p');
    body.textContent = text;                 // textContent: a message cannot inject markup
    const when = document.createElement('time');
    when.textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) +
      (delivered === false ? ' · not delivered' : '');
    row.append(who, body, when);
    log.appendChild(row);
    log.scrollTop = log.scrollHeight;
  }

  function emptyChat() {
    const log = $('[data-chat-log]');
    if (log && !log.children.length) {
      const p = document.createElement('p');
      p.className = 'mr-chat-empty';
      p.textContent = 'No messages yet. Messages are sent straight to the other participant.';
      log.appendChild(p);
    }
  }

  function bumpUnread() {
    S.unread++;
    const badge = $('[data-chat-unread]');
    const dot = $('[data-chat-dot]');
    if (badge) { badge.textContent = String(S.unread); badge.hidden = false; }
    if (dot) dot.hidden = false;
  }
  function clearUnread() {
    S.unread = 0;
    const badge = $('[data-chat-unread]');
    const dot = $('[data-chat-dot]');
    if (badge) badge.hidden = true;
    if (dot) dot.hidden = true;
  }

  /* ══ Active speaker ════════════════════════════════════════════════════
     One AudioContext, one analyser per stream. The loop reads the time-domain
     data and compares RMS against a floor; a short hold stops the border from
     flickering between syllables. Nothing here touches playback: an analyser is
     a tap, not a sink, so audio continues untouched. */
  const SPEAK_ON = 0.045, SPEAK_HOLD_MS = 420;

  function watchAudio(stream, tileKey) {
    if (!stream || !stream.getAudioTracks || !stream.getAudioTracks().length) return;
    try {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      S.audio.ctx = S.audio.ctx || new Ctx();
      const ctx = S.audio.ctx;
      if (ctx.state === 'suspended') ctx.resume().catch(() => {});
      const src = ctx.createMediaStreamSource(stream);
      const analyser = ctx.createAnalyser();
      analyser.fftSize = 512;
      analyser.smoothingTimeConstant = 0.6;
      src.connect(analyser);                     // not connected to the destination
      S.audio.nodes = S.audio.nodes.filter(n => n.key !== tileKey);
      S.audio.nodes.push({ key: tileKey, analyser: analyser, src: src, buf: new Uint8Array(analyser.fftSize), until: 0 });
      startSpeakerLoop();
    } catch (e) { /* no analyser: the room still works, just without the highlight */ }
  }

  /* The remote side is measured through WebRTC, not WebAudio: an analyser tap on
     a remote MediaStream reads silence in several browsers (the audio is
     rendered by the <video> element, not by the graph). RTCRtpReceiver's
     synchronization sources carry the sender's own audioLevel, which is exactly
     what "who is talking" needs, so it is preferred whenever it exists. */
  const PEER_LEVEL_ON = 0.02;

  function peerAudioLevel() {
    const pc = ROOM.pc;
    if (!pc || !pc.getReceivers) return null;
    const receiver = pc.getReceivers().find(r => r.track && r.track.kind === 'audio');
    if (!receiver || !receiver.getSynchronizationSources) return null;
    const sources = receiver.getSynchronizationSources();
    if (!sources || !sources.length) return null;
    return sources.reduce((max, s) => Math.max(max, typeof s.audioLevel === 'number' ? s.audioLevel : 0), 0);
  }

  function startSpeakerLoop() {
    if (S.audio.raf) return;
    const tick = function () {
      S.audio.raf = requestAnimationFrame(tick);
      const now = performance.now();

      S.audio.nodes.forEach(function (n) {
        let level;
        if (n.key === 'peer') {
          const rtc = peerAudioLevel();
          if (rtc !== null) level = rtc;
        }
        if (level === undefined) {
          n.analyser.getByteTimeDomainData(n.buf);
          let sum = 0;
          for (let i = 0; i < n.buf.length; i++) { const v = (n.buf[i] - 128) / 128; sum += v * v; }
          level = Math.sqrt(sum / n.buf.length);
        }
        const floor = n.key === 'peer' ? PEER_LEVEL_ON : SPEAK_ON;
        if (level > floor) n.until = now + SPEAK_HOLD_MS;
        const speaking = now < n.until && (n.key === 'self' ? S.micOn : S.peer.mic);
        paintSpeaking(n.key, speaking);
      });
    };
    S.audio.raf = requestAnimationFrame(tick);
  }

  function paintSpeaking(key, speaking) {
    const tile = $('[data-tile="' + key + '"]');
    if (tile && tile.classList.contains('is-speaking') !== speaking) tile.classList.toggle('is-speaking', speaking);
    const mic = $(key === 'self' ? '[data-self-mic]' : '[data-peer-mic]');
    if (mic && mic.classList.contains('is-talking') !== speaking) mic.classList.toggle('is-talking', speaking);
    if (key === 'peer') updateMiniName(speaking);
  }

  function stopAudioWatch() {
    if (S.audio.raf) cancelAnimationFrame(S.audio.raf);
    S.audio.raf = 0;
    S.audio.nodes.forEach(n => { try { n.src.disconnect(); n.analyser.disconnect(); } catch (e) {} });
    S.audio.nodes = [];
    if (S.audio.ctx) { try { S.audio.ctx.close(); } catch (e) {} S.audio.ctx = null; }
  }

  /* ══ Picture-in-Picture ════════════════════════════════════════════════
     The element that goes into PiP is the one already carrying the remote
     audio, so the stream is never re-attached and the call never blips.

     Browsers require a user gesture for requestPictureInPicture(). Switching
     tabs is not a gesture, so an automatic attempt CAN be refused with
     NotAllowedError — Safari and installed PWAs honour the autoPictureInPicture
     hint, other browsers usually do not. Refusal is expected, not an error: we
     fall back to the in-page floating window. */
  function pipTarget() {
    const remote = $('[data-remote-video]');
    if (remote && !remote.hidden && remote.srcObject) return remote;
    return $('[data-local-preview-call]');
  }

  function pipSupported() {
    return !!(document.pictureInPictureEnabled && HTMLVideoElement.prototype.requestPictureInPicture);
  }

  async function enterPip() {
    if (!pipSupported() || document.pictureInPictureElement) return false;
    const video = pipTarget();
    if (!video || !video.srcObject) return false;
    try {
      if (video.readyState === 0) await video.play().catch(() => {});
      await video.requestPictureInPicture();
      S.pipOn = true;
      setPressed('[data-toggle-pip]', true);
      return true;
    } catch (e) {
      return false;                              // NotAllowedError when there is no gesture
    }
  }

  async function exitPip() {
    if (!document.pictureInPictureElement) return;
    try { await document.exitPictureInPicture(); } catch (e) {}
  }

  async function togglePip() {
    if (document.pictureInPictureElement) { await exitPip(); return; }
    const ok = await enterPip();
    if (!ok) toast('This browser would not open Picture-in-Picture here. Using the floating window instead.');
    if (!ok) minimise(true);
  }

  /* Tab switching. Try native PiP first, fall back to the floating window so
     there is always something to come back to. */
  async function onVisibility() {
    if (!live() || !S.mounted) return;
    if (document.visibilityState === 'hidden') {
      if (settings.autoPip && !document.pictureInPictureElement) {
        const ok = await enterPip();
        if (!ok && settings.miniOnBlur) minimise(true);
      } else if (settings.miniOnBlur) {
        minimise(true);
      }
    }
  }

  /* ══ Floating window ═══════════════════════════════════════════════════ */
  function minimise(on) {
    const call = $('[data-call]');
    const mini = $('[data-mini]');
    const page = $('[data-minimised-page]');
    if (!call || !mini) return;
    S.minimised = !!on;
    call.classList.toggle('is-minimised', S.minimised);
    mini.hidden = !S.minimised;
    if (page) page.hidden = !S.minimised;
    document.body.classList.toggle('room-minimised', S.minimised);
    if (S.minimised) {
      applyPlacement();
      updateMiniSource();
      syncMiniControls();
    }
  }

  function applyPlacement() {
    const mini = $('[data-mini]');
    if (!mini) return;
    if (settings.place === 'custom' && settings.pos) {
      mini.dataset.place = 'custom';
      mini.style.left = settings.pos.left + 'px';
      mini.style.top = settings.pos.top + 'px';
      clampMini();
    } else {
      mini.dataset.place = settings.place;
      mini.style.left = mini.style.top = '';
    }
    $$('[data-place-choice]').forEach(b => b.setAttribute('aria-checked', String(b.dataset.placeChoice === settings.place)));
  }

  /* The floating video shares the MediaStream with the grid and stays muted, so
     no second audio path is ever created. */
  function updateMiniSource() {
    const mini = $('[data-mini-video]');
    if (!mini) return;
    const remote = $('[data-remote-video]');
    const stream = (remote && remote.srcObject) ? remote.srcObject : S.localStream;
    if (stream && mini.srcObject !== stream) mini.srcObject = stream;
    mini.muted = true;                            // non-negotiable
    const showing = !!stream && (stream === S.localStream ? S.camOn : S.peer.cam);
    const avatar = $('[data-mini-avatar]');
    if (avatar) avatar.hidden = showing;
    const initials = $('[data-mini-initials]');
    const name = (remote && remote.srcObject) ? peerName() : myName();
    if (initials) initials.textContent = (name.trim()[0] || '?').toUpperCase();
    updateMiniName(false);
    if (mini.paused) mini.play().catch(() => {});
  }

  function updateMiniName(speaking) {
    const el = $('[data-mini-name]');
    if (!el) return;
    const remote = $('[data-remote-video]');
    const name = (remote && remote.srcObject) ? peerName() : myName() + ' (you)';
    el.textContent = speaking ? name + ' · speaking' : name;
  }

  function syncMiniControls() {
    setPressed('[data-mini-mic]', S.micOn);
    setPressed('[data-mini-cam]', S.camOn);
    const t = $('[data-timer]'), m = $('[data-mini-timer]');
    if (t && m) m.textContent = t.textContent;
  }

  /* Drag: Pointer Events, so mouse, touch and pen all work from one path.
     Position is written to left/top and clamped to the viewport, then stored so
     the window comes back where it was left. */
  function initDrag() {
    const mini = $('[data-mini]');
    const handle = $('[data-mini-drag]');
    if (!mini || !handle) return;
    let dragging = false, dx = 0, dy = 0, id = null;

    const down = function (ev) {
      if (ev.button !== undefined && ev.button !== 0) return;
      const rect = mini.getBoundingClientRect();
      dragging = true; id = ev.pointerId;
      dx = ev.clientX - rect.left;
      dy = ev.clientY - rect.top;
      mini.dataset.place = 'custom';
      mini.style.left = rect.left + 'px';
      mini.style.top = rect.top + 'px';
      mini.classList.add('is-dragging');
      try { handle.setPointerCapture(id); } catch (e) {}
      ev.preventDefault();
    };
    const move = function (ev) {
      if (!dragging || ev.pointerId !== id) return;
      // Clamped on every frame, not just on release, so the window can never be
      // dragged off the edge of the screen and stranded there.
      const pos = clampTo(ev.clientX - dx, ev.clientY - dy);
      mini.style.left = pos.left + 'px';
      mini.style.top = pos.top + 'px';
      ev.preventDefault();
    };
    const up = function (ev) {
      if (!dragging || (ev.pointerId !== undefined && ev.pointerId !== id)) return;
      dragging = false;
      mini.classList.remove('is-dragging');
      try { handle.releasePointerCapture(id); } catch (e) {}
      clampMini();
      const rect = mini.getBoundingClientRect();
      settings.place = 'custom';
      settings.pos = { left: Math.round(rect.left), top: Math.round(rect.top) };
      saveSettings();
      $$('[data-place-choice]').forEach(b => b.setAttribute('aria-checked', 'false'));
    };

    handle.addEventListener('pointerdown', down);
    handle.addEventListener('pointermove', move);
    handle.addEventListener('pointerup', up);
    handle.addEventListener('pointercancel', up);
    cleanups.push(function () {
      handle.removeEventListener('pointerdown', down);
      handle.removeEventListener('pointermove', move);
      handle.removeEventListener('pointerup', up);
      handle.removeEventListener('pointercancel', up);
    });
  }

  /* offsetWidth/offsetHeight rather than getBoundingClientRect: the border box
     is known even before the <video> has laid out its aspect-ratio box, so the
     first clamp after a drag uses the real size instead of a stale one. */
  function clampTo(left, top) {
    const mini = $('[data-mini]');
    const w = mini ? mini.offsetWidth : 0;
    const h = mini ? mini.offsetHeight : 0;
    return {
      left: Math.round(Math.min(Math.max(8, left), Math.max(8, window.innerWidth - w - 8))),
      top: Math.round(Math.min(Math.max(8, top), Math.max(8, window.innerHeight - h - 8))),
    };
  }

  function clampMini() {
    const mini = $('[data-mini]');
    if (!mini || mini.dataset.place !== 'custom') return;
    const pos = clampTo(parseFloat(mini.style.left) || mini.offsetLeft, parseFloat(mini.style.top) || mini.offsetTop);
    mini.style.left = pos.left + 'px';
    mini.style.top = pos.top + 'px';
  }

  /* ══ Settings modal ════════════════════════════════════════════════════ */
  function openSettings(open) {
    const modal = $('[data-settings-modal]');
    if (!modal) return;
    modal.hidden = !open;
    if (!open) return;
    $$('[data-setting]').forEach(input => { input.checked = !!settings[input.dataset.setting]; });
    applyPlacement();
    const first = modal.querySelector('input, button');
    if (first) first.focus();
  }

  function applyMirror() {
    const call = $('[data-call]');
    if (call) call.classList.toggle('mirror-self', !!settings.mirror);
  }

  /* ══ Wiring ════════════════════════════════════════════════════════════ */
  const cleanups = [];

  function onDocClick(ev) {
    const hit = sel => (ev.target.closest ? ev.target.closest(sel) : null);
    if (!live()) return;

    if (hit('[data-toggle-mic-call], [data-mini-mic]')) { setMic(!S.micOn); return; }
    if (hit('[data-toggle-cam-call], [data-mini-cam]')) { setCam(!S.camOn); return; }
    if (hit('[data-toggle-share]')) { toggleShare(); return; }
    if (hit('[data-toggle-hand]')) { toggleHand(); return; }

    const panelBtn = hit('[data-toggle-panel]');
    if (panelBtn) { togglePanel(panelBtn.dataset.togglePanel); return; }
    const tab = hit('[data-panel-tab]');
    if (tab) { openPanel(tab.dataset.panelTab); return; }
    if (hit('[data-panel-close]')) { closePanel(); return; }

    if (hit('[data-open-settings]')) { openSettings(true); return; }
    if (hit('[data-settings-close]')) { openSettings(false); return; }
    const place = hit('[data-place-choice]');
    if (place) {
      settings.place = place.dataset.placeChoice;
      settings.pos = null;
      saveSettings();
      applyPlacement();
      if (!S.minimised) toast('Floating window will open ' + place.textContent.toLowerCase());
      return;
    }

    if (hit('[data-toggle-pip]')) { togglePip(); return; }
    if (hit('[data-minimise]')) { minimise(true); return; }
    if (hit('[data-mini-expand]')) { minimise(false); exitPip(); return; }
  }

  function onDocChange(ev) {
    const input = ev.target.closest ? ev.target.closest('[data-setting]') : null;
    if (!input) return;
    settings[input.dataset.setting] = !!input.checked;
    saveSettings();
    if (input.dataset.setting === 'mirror') applyMirror();
  }

  function onChatSubmit(ev) {
    const form = ev.target.closest ? ev.target.closest('[data-chat-form]') : null;
    if (!form) return;
    ev.preventDefault();
    const input = form.querySelector('input');
    const text = (input.value || '').trim();
    if (!text) return;
    const delivered = send('chat', { text: text, name: myName() });
    addMessage(myName(), text, true, delivered);
    input.value = '';
  }

  function onKey(ev) {
    if (!live() || !S.mounted) return;
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(ev.target.tagName) || ev.target.isContentEditable;
    if (ev.key === 'Escape') {
      if ($('[data-settings-modal]') && !$('[data-settings-modal]').hidden) { openSettings(false); return; }
      if (S.panel) { closePanel(); return; }
      return;
    }
    if (typing || ev.metaKey || ev.ctrlKey || ev.altKey) return;
    const k = ev.key.toLowerCase();
    if (k === 'm') { ev.preventDefault(); setMic(!S.micOn); }
    else if (k === 'v') { ev.preventDefault(); setCam(!S.camOn); }
  }

  /* While a meeting is live, an in-app link opens in a NEW TAB. This app is not
     a single-page app: following a link in this tab tears down the page, and
     with it the RTCPeerConnection, so the call would end. Opening a second tab
     is what keeps the meeting alive while the person looks something up. */
  function onLinkClick(ev) {
    if (!live() || !S.mounted) return;
    const a = ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!a || a.target === '_blank' || a.hasAttribute('data-external-ok')) return;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('#') || /^(mailto:|tel:|javascript:)/i.test(href)) return;
    if (a.origin && a.origin !== window.location.origin) return;
    ev.preventDefault();
    window.open(a.href, '_blank', 'noopener');
    toast('Opened in a new tab so the meeting keeps running here.');
  }

  function onBeforeUnload(ev) {
    if (!live() || !S.mounted) return;
    ev.preventDefault();
    ev.returnValue = '';                          // browsers show their own wording
    return '';
  }

  /* ── Bus subscriptions (once, at load) ───────────────────────────────── */
  ROOM.on('local-stream', function (stream) {
    S.localStream = stream;
    S.micOn = !!(stream.getAudioTracks()[0] || {}).enabled;
    S.camOn = !!(stream.getVideoTracks()[0] || {}).enabled;
    document.querySelectorAll('[data-local-preview],[data-local-preview-call]').forEach(function (v) {
      if (v.srcObject !== stream) v.srcObject = stream;
    });
    const avatar = $('[data-preview-empty-call]');
    if (avatar) avatar.hidden = S.camOn;
    const preAvatar = $('[data-preview-empty]');
    if (preAvatar) preAvatar.style.display = 'none';
    watchAudio(stream, 'self');
    updatePeopleText();
    updateMiniSource();
  });

  ROOM.on('remote-stream', function (stream) {
    S.remoteStream = stream;
    S.peer.joined = true;
    const video = $('[data-remote-video]');
    if (video) video.hidden = false;
    const avatar = $('[data-peer-avatar]');
    if (avatar) avatar.hidden = true;
    const status = $('[data-tile="peer"] [data-peer-status]');
    if (status) status.textContent = '';
    watchAudio(stream, 'peer');
    updatePeopleText();
    updateMiniSource();
  });

  ROOM.on('channel', function (ch) { S.channel = ch; });
  ROOM.on('channel-open', function (ch) {
    S.channel = ch;
    sendState();
    const note = $('[data-chat-note]');
    if (note) note.textContent = 'Connected. Messages go straight to the other participant and are not stored.';
  });
  ROOM.on('channel-closed', function () {
    const note = $('[data-chat-note]');
    if (note) note.textContent = 'Not connected — messages cannot be delivered right now.';
  });

  ROOM.on('peer:chat', function (msg) {
    addMessage(msg.name || peerName(), String(msg.text || ''), false, true);
    if (S.panel !== 'chat' || document.visibilityState === 'hidden' || S.minimised) bumpUnread();
  });

  ROOM.on('peer:state', function (msg) {
    S.peer.mic = msg.mic !== false;
    S.peer.cam = msg.cam !== false;
    S.peer.hand = !!msg.hand;
    S.peer.joined = true;
    const mic = $('[data-peer-mic]'), pMic = $('[data-people-mic-peer]');
    if (mic) mic.classList.toggle('is-muted', !S.peer.mic);
    if (pMic) pMic.classList.toggle('is-muted', !S.peer.mic);
    const hand = $('[data-hand-badge="peer"]');
    if (hand) hand.hidden = !S.peer.hand;
    if (S.peer.hand) toast(peerName() + ' raised their hand');
    const avatar = $('[data-peer-avatar]');
    if (avatar) avatar.hidden = S.peer.cam && !!S.remoteStream;
    updatePeopleText();
    updateMiniSource();
  });

  ROOM.on('peer:share', function (msg) {
    const tile = $('[data-tile="screen"]');
    const owner = $('[data-screen-owner]');
    const placeholder = $('[data-screen-placeholder]');
    if (!tile) return;
    if (msg.on) {
      // Their screen arrives on the existing video track (replaceTrack), so the
      // peer tile shows it; this tile is the label for what is happening.
      tile.hidden = false;
      if (placeholder) placeholder.hidden = false;
      if (owner) owner.textContent = (msg.name || peerName()) + ' is presenting';
      toast((msg.name || peerName()) + ' started presenting');
    } else {
      tile.hidden = true;
    }
  });

  ROOM.on('teardown', function () { stopAudioWatch(); });

  /* ── Mount / unmount ─────────────────────────────────────────────────── */
  ROOM.onMount.push(function () {
    if (!live()) return;
    S.mounted = true;
    S.panel = null;
    S.unread = 0;

    applyMirror();
    applyPlacement();
    emptyChat();
    updatePeopleText();
    initDrag();

    // Reflect whatever the media is already doing (the device check ran first).
    if (ROOM.stream) {
      S.localStream = ROOM.stream;
      S.micOn = !!(ROOM.stream.getAudioTracks()[0] || {}).enabled;
      S.camOn = !!(ROOM.stream.getVideoTracks()[0] || {}).enabled;
      watchAudio(ROOM.stream, 'self');
    }
    setPressed('[data-toggle-mic-call], [data-mini-mic]', S.micOn);
    setPressed('[data-toggle-cam-call], [data-mini-cam]', S.camOn);
    const avatar = $('[data-preview-empty-call]');
    if (avatar) avatar.hidden = S.camOn && !!S.localStream;

    const pipBtn = $('[data-toggle-pip]');
    if (pipBtn && !pipSupported()) { pipBtn.hidden = true; }

    // Safari and installed PWAs honour this hint and pop out automatically.
    const target = pipTarget();
    if (target) { try { target.autoPictureInPicture = true; } catch (e) {} }

    const clock = $('[data-bar-clock]');
    if (clock) {
      const paint = () => { clock.textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); };
      paint();
      const t = setInterval(paint, 30000);
      cleanups.push(() => clearInterval(t));
    }
    const miniTimer = setInterval(syncMiniControls, 1000);
    cleanups.push(() => clearInterval(miniTimer));
  });

  ROOM.onUnmount.push(function () {
    S.mounted = false;
    S.minimised = false;
    S.panel = null;
    stopShare();
    stopAudioWatch();
    exitPip();
    document.body.classList.remove('room-minimised');
    cleanups.splice(0).forEach(fn => { try { fn(); } catch (e) {} });
  });

  /* ── Document-level listeners (bound once) ───────────────────────────── */
  document.addEventListener('click', onDocClick);
  document.addEventListener('click', onLinkClick, true);
  document.addEventListener('change', onDocChange);
  document.addEventListener('submit', onChatSubmit);
  document.addEventListener('keydown', onKey);
  document.addEventListener('visibilitychange', onVisibility);
  window.addEventListener('resize', clampMini);
  window.addEventListener('beforeunload', onBeforeUnload);
  document.addEventListener('enterpictureinpicture', function () { S.pipOn = true; setPressed('[data-toggle-pip]', true); }, true);
  document.addEventListener('leavepictureinpicture', function () { S.pipOn = false; setPressed('[data-toggle-pip]', false); }, true);

  // Exposed for the QA harness and for debugging from the console.
  ROOM.ui = {
    state: S, settings: settings,
    minimise: minimise, togglePip: togglePip, openPanel: openPanel, closePanel: closePanel,
    setMic: setMic, setCam: setCam, toggleHand: toggleHand, addMessage: addMessage,
  };
})();
