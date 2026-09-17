(function () {
  const csrf = document.getElementById('pipeline-csrf')?.value;
  const role = document.getElementById('pipeline-role')?.value;
  const messages = window.PIPELINE_TRANSITION_MESSAGES || {};
  const stageLabels = window.PIPELINE_STAGE_LABELS || {};
  const stageOrder = Object.keys(stageLabels);

  const moveModal = document.querySelector('[data-modal-move]');
  const confirmModal = document.querySelector('[data-modal-confirm]');
  const previewPanel = document.querySelector('[data-preview-overlay]');
  let pending = null; // { card, fromCol, toCol, id, fromStage, toStage, override }
  let wasDragged = false;

  function stageBadgeHtml(stageKey) {
    return `<span class="stage-badge stage-${stageKey}">${stageLabels[stageKey] || stageKey}</span>`;
  }

  function isSkip(fromStage, toStage) {
    if (toStage === 'rejected') return false;
    const fromIdx = stageOrder.indexOf(fromStage);
    const toIdx = stageOrder.indexOf(toStage);
    if (fromIdx === -1 || toIdx === -1) return false;
    return toIdx > fromIdx + 1;
  }

  function openMoveModal() {
    if (!pending) return;
    document.querySelector('[data-move-candidate-name]').textContent = pending.name;
    document.querySelector('[data-move-from]').outerHTML = `<span class="stage-badge stage-${pending.fromStage}" data-move-from>${stageLabels[pending.fromStage] || pending.fromStage}</span>`;
    document.querySelector('[data-move-to]').outerHTML = `<span class="stage-badge stage-${pending.toStage}" data-move-to>${stageLabels[pending.toStage] || pending.toStage}</span>`;
    document.querySelector('[data-move-view-profile]').href = `candidate.php?id=${pending.id}`;

    const warningEl = document.querySelector('[data-move-warning]');
    const continueBtn = document.querySelector('[data-move-continue]');
    const skip = isSkip(pending.fromStage, pending.toStage);
    pending.override = false;

    if (skip && role !== 'admin') {
      warningEl.hidden = false;
      warningEl.classList.add('blocked');
      warningEl.textContent = 'This candidate cannot be moved directly to ' + (stageLabels[pending.toStage] || pending.toStage) + '. Complete the required recruitment stages first.';
      continueBtn.textContent = 'Continue';
      continueBtn.disabled = true;
    } else if (skip && role === 'admin') {
      warningEl.hidden = false;
      warningEl.classList.remove('blocked');
      warningEl.textContent = (messages[pending.fromStage + '>' + pending.toStage] || 'This skips one or more required stages.') + ' As an admin, you can override this.';
      continueBtn.textContent = 'Override & Continue';
      continueBtn.disabled = false;
      pending.override = true;
    } else {
      const key = pending.fromStage + '>' + pending.toStage;
      if (messages[key]) {
        warningEl.hidden = false;
        warningEl.classList.remove('blocked');
        warningEl.textContent = messages[key];
      } else {
        warningEl.hidden = true;
      }
      continueBtn.textContent = 'Continue';
      continueBtn.disabled = false;
    }
    moveModal.hidden = false;
  }

  function openConfirmModal() {
    if (!pending) return;
    moveModal.hidden = true;
    document.querySelector('[data-confirm-candidate-name]').textContent = pending.name;
    document.querySelector('[data-confirm-from]').outerHTML = `<span class="stage-badge stage-${pending.fromStage}" data-confirm-from>${stageLabels[pending.fromStage] || pending.fromStage}</span>`;
    document.querySelector('[data-confirm-to]').outerHTML = `<span class="stage-badge stage-${pending.toStage}" data-confirm-to>${stageLabels[pending.toStage] || pending.toStage}</span>`;
    const checkbox = document.querySelector('[data-confirm-checkbox]');
    const moveBtn = document.querySelector('[data-confirm-move]');
    checkbox.checked = false;
    moveBtn.disabled = true;
    confirmModal.hidden = false;
  }

  function closeAllModals() {
    moveModal.hidden = true;
    confirmModal.hidden = true;
  }

  function discardPending() {
    pending = null;
    closeAllModals();
  }

  function updateCounts() {
    document.querySelectorAll('.kanban-col').forEach(col => {
      const body = col.querySelector('[data-col-body]');
      const count = body.querySelectorAll('.candidate-card').length;
      col.querySelector('[data-col-count]').textContent = count;
      let empty = body.querySelector('.kanban-empty');
      if (count === 0 && !empty) body.insertAdjacentHTML('beforeend', '<p class="kanban-empty meta small">Drop a candidate here</p>');
      if (count > 0 && empty) empty.remove();
    });
  }

  function toast(message) {
    const t = document.querySelector('#toast');
    if (!t) return;
    t.textContent = message;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3200);
  }

  function saveMove() {
    if (!pending) return;
    const moveBtn = document.querySelector('[data-confirm-move]');
    moveBtn.disabled = true; moveBtn.textContent = 'Saving...';
    fetch('pipeline.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `action=move_stage&csrf=${encodeURIComponent(csrf)}&id=${encodeURIComponent(pending.id)}&stage=${encodeURIComponent(pending.toStage)}&override=${pending.override ? '1' : ''}`
    })
      .then(r => r.json())
      .then(data => {
        moveBtn.disabled = false; moveBtn.textContent = 'Confirm & Move';
        if (data.ok) {
          pending.toCol.querySelector('[data-col-body]').appendChild(pending.card);
          updateCounts();
          toast(`✓ ${pending.name} moved from ${stageLabels[data.from] || data.from} to ${stageLabels[data.to] || data.to}.`);
          closeAllModals();
          pending = null;
        } else {
          toast('⚠ ' + (data.message || 'Unable to move candidate. The change was not saved.'));
        }
      })
      .catch(() => {
        moveBtn.disabled = false; moveBtn.textContent = 'Confirm & Move';
        toast('⚠ Unable to move candidate. The change was not saved.');
      });
  }

  // Drag and drop — defer the actual move until double confirmation.
  document.querySelectorAll('.kanban-col[data-drop]').forEach(col => {
    col.addEventListener('dragover', e => { e.preventDefault(); col.classList.add('drag-over'); });
    col.addEventListener('dragleave', () => col.classList.remove('drag-over'));
    col.addEventListener('drop', e => {
      e.preventDefault(); col.classList.remove('drag-over');
      const id = e.dataTransfer.getData('text/plain');
      const card = document.querySelector(`.candidate-card[data-id="${id}"]`);
      if (!card) return;
      const fromCol = card.closest('.kanban-col');
      const toStage = col.dataset.stage;
      const fromStage = fromCol.dataset.stage;
      if (fromCol === col) return; // dropped back in the same column, nothing to confirm
      const data = JSON.parse(card.dataset.preview);
      pending = { card, fromCol, toCol: col, id, fromStage, toStage, name: data.name };
      openMoveModal();
    });
  });

  document.querySelectorAll('.candidate-card').forEach(c => {
    c.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', c.dataset.id); c.classList.add('dragging'); wasDragged = true; });
    c.addEventListener('dragend', () => { c.classList.remove('dragging'); setTimeout(() => { wasDragged = false; }, 50); });
  });

  // Modal 1 controls
  document.querySelector('[data-move-cancel]')?.addEventListener('click', discardPending);
  document.querySelector('[data-move-continue]')?.addEventListener('click', openConfirmModal);

  // Modal 2 controls
  document.querySelector('[data-confirm-back]')?.addEventListener('click', openMoveModal);
  document.querySelector('[data-confirm-checkbox]')?.addEventListener('change', function () {
    document.querySelector('[data-confirm-move]').disabled = !this.checked;
  });
  document.querySelector('[data-confirm-move]')?.addEventListener('click', saveMove);

  // Clicking the dark overlay outside the box cancels (but not on the box itself)
  [moveModal, confirmModal].forEach(modal => {
    modal?.addEventListener('click', e => { if (e.target === modal) discardPending(); });
  });

  // Candidate preview modal — one reliable state, one overlay.
  function setBodyLocked(locked){
    document.body.classList.toggle('modal-open', locked);
  }
  function closePreview(){
    if (!previewPanel) return;
    previewPanel.hidden = true;
    previewPanel.classList.remove('open');
    setBodyLocked(false);
  }
  function openPreview(card){
    const data = JSON.parse(card.dataset.preview || '{}');
    const nameEl=document.querySelector('[data-preview-name]');
    const titleEl=document.querySelector('[data-preview-title]');
    const stageEl=document.querySelector('[data-preview-stage]');
    nameEl.textContent=data.name || '';
    titleEl.textContent=data.title || '';
    stageEl.textContent=data.stage || '';
    stageEl.className='stage-badge stage-'+(Object.keys(stageLabels).find(k=>stageLabels[k]===data.stage)||'new');

    const photo=document.querySelector('[data-preview-photo]');
    const initials=(data.name||'').split(/\s+/).map(x=>x[0]||'').join('').slice(0,2).toUpperCase();
    photo.innerHTML=data.profileImage
      ? `<img class="candidate-preview-avatar avatar-image-large" src="${escapeHtml(data.profileImage)}" alt="${escapeHtml(data.name||'Candidate')}">`
      : `<span class="candidate-preview-avatar">${escapeHtml(initials)}</span>`;

    document.querySelector('[data-preview-ai]').textContent=data.aiScore!==null && data.aiScore!==undefined ? data.aiScore+' / 100' : 'Not analyzed yet';
    document.querySelector('[data-preview-screening]').textContent=data.screeningScore!==null && data.screeningScore!==undefined ? data.screeningScore+' / 100' : '—';
    document.querySelector('[data-preview-interview]').textContent=data.interviewScore!==null && data.interviewScore!==undefined ? data.interviewScore+' / 100' : '—';
    const resumeEl=document.querySelector('[data-preview-resume]');
    resumeEl.innerHTML=data.resume ? `<a href="${escapeHtml(data.resume)}" target="_blank" rel="noopener">View resume</a>` : 'Not uploaded';
    document.querySelector('[data-preview-notes]').textContent=(data.noteCount||0)+' '+((data.noteCount||0)===1?'note':'notes');
    document.querySelector('[data-preview-latest-note]').textContent=data.latestNote ? '“'+data.latestNote+'”' : '';
    document.querySelector('[data-preview-full-link]').href=`candidate.php?id=${encodeURIComponent(data.applicationId)}`;
    previewPanel.hidden=false;
    previewPanel.classList.add('open');
    setBodyLocked(true);
  }
  function escapeHtml(value){
    return String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  }

  document.querySelectorAll('.candidate-card').forEach(c=>{
    c.addEventListener('click',e=>{
      if(wasDragged || e.target.closest('[data-no-preview]')) return;
      openPreview(c);
    });
  });
  document.querySelectorAll('[data-preview-close]').forEach(btn=>btn.addEventListener('click',closePreview));
  previewPanel?.addEventListener('click',e=>{ if(e.target===previewPanel) closePreview(); });
  document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
      if(!previewPanel?.hidden) closePreview();
      else if(!moveModal?.hidden || !confirmModal?.hidden) discardPending();
    }
  });
  window.addEventListener('pageshow',()=>{ setBodyLocked(false); closePreview(); });
})();
