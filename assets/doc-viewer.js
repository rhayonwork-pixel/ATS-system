/* ==========================================================================
   Resume / CV viewer — candidate.php, Row 2
   --------------------------------------------------------------------------
   Nothing is fetched until View is pressed, so a profile that is only skimmed
   never downloads the document.

   PDF   PDF.js (bundled in assets/vendor/pdfjs, loaded on first View) draws
         each page to a <canvas>. download.php answers byte-range requests, so
         PDF.js fetches only the chunks page 1 needs and paints it first; every
         other page renders when it scrolls near the viewport.
   DOCX  document-preview.php extracts the text on the server (the file never
         leaves the app) and it is shown as headings, paragraphs and lists.
   Other No preview (legacy .doc): Download only, handled in the markup.

   If PDF.js cannot load (very old browser, blocked module), the PDF falls
   back to the browser's own viewer in an iframe.
   ========================================================================== */
(function () {
  'use strict';

  const root = document.querySelector('[data-doc-viewer]');
  if (!root) return;

  const toggle   = root.querySelector('[data-doc-toggle]');
  const panel    = root.querySelector('[data-doc-panel]');
  const pagesEl  = root.querySelector('[data-doc-pages]');
  const status   = root.querySelector('[data-doc-status]');
  const skeleton = root.querySelector('[data-doc-skeleton]');
  if (!toggle || !panel || !pagesEl) return;

  const kind     = root.dataset.kind;          // "pdf" | "docx"
  const src      = root.dataset.src;           // inline PDF URL
  const preview  = root.dataset.preview;       // DOCX text endpoint
  const download = root.dataset.download;
  const label    = root.dataset.name || 'Resume';

  // Resolved against this script's own URL, so the page's location never matters.
  const BASE = new URL('.', document.currentScript ? document.currentScript.src : location.href).href;
  const PDFJS = BASE + 'vendor/pdfjs/pdf.min.js';
  const WORKER = BASE + 'vendor/pdfjs/pdf.worker.min.js';

  const openLabel = toggle.innerHTML;
  let started = false;

  function setStatus(text) { if (status) status.textContent = text; }
  function done() { if (skeleton) skeleton.hidden = true; panel.removeAttribute('aria-busy'); }

  function fail(message) {
    done();
    pagesEl.innerHTML = '';
    const box = document.createElement('div');
    box.className = 'cp-viewer-error';
    box.setAttribute('role', 'alert');
    box.append(document.createTextNode(message + ' '));
    if (download) {
      const a = document.createElement('a');
      a.href = download; a.className = 'btn small'; a.textContent = 'Download instead';
      box.append(a);
    }
    pagesEl.append(box);
    setStatus('Preview unavailable');
  }

  function setOpen(open) {
    panel.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.innerHTML = open ? 'Hide preview' : openLabel;
    if (open && !started) { started = true; start(); }
    if (open) panel.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
  }
  toggle.addEventListener('click', function () { setOpen(panel.hidden); });

  function start() {
    // The skeleton is up from the first frame; it comes down when real content
    // is on screen (PDF page 1 painted, or DOCX text inserted), not on a timer.
    if (skeleton) skeleton.hidden = false;
    panel.setAttribute('aria-busy', 'true');
    setStatus('Loading preview…');
    if (kind === 'pdf') loadPdf();
    else if (kind === 'docx') loadDocx();
    else fail('A preview is not available for this file type.');
  }

  /* ---------------------------------------------------------------- DOCX -- */
  function loadDocx() {
    fetch(preview, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
      .then(function (data) {
        if (!data || !data.ok) { fail((data && data.error) || 'The preview could not be loaded.'); return; }
        const article = document.createElement('article');
        article.className = 'cp-docx';
        article.setAttribute('aria-label', 'Text of ' + label);
        let list = null;
        data.blocks.forEach(function (b) {
          if (b.type === 'li') {
            if (!list) { list = document.createElement('ul'); article.append(list); }
            const li = document.createElement('li'); li.textContent = b.text; list.append(li);
            return;
          }
          list = null;
          const el = document.createElement(b.type === 'h' ? 'h3' : 'p');
          el.textContent = b.text;   // textContent only: document text is never parsed as HTML
          article.append(el);
        });
        if (!data.blocks.length) article.textContent = 'This document has no readable text.';
        pagesEl.replaceChildren(article);
        done();
        setStatus('Text preview · formatting is simplified — download for the original layout');
      })
      .catch(function () { fail('The preview could not be loaded.'); });
  }

  /* ----------------------------------------------------------------- PDF -- */
  function iframeFallback() {
    const f = document.createElement('iframe');
    f.className = 'cp-viewer-iframe';
    f.title = label;
    f.src = src;
    f.addEventListener('load', done, { once: true });
    pagesEl.replaceChildren(f);
    setStatus('Showing the browser’s built-in viewer');
  }

  function loadPdf() {
    import(PDFJS).then(function (pdfjs) {
      pdfjs.GlobalWorkerOptions.workerSrc = WORKER;
      const task = pdfjs.getDocument({
        url: src,
        withCredentials: true,
        rangeChunkSize: 65536,      // 64KB range requests
        disableAutoFetch: true,     // fetch pages as they are needed, not the whole file up front
      });
      task.onProgress = function (p) {
        if (p.total) setStatus('Loading… ' + Math.min(99, Math.round(p.loaded / p.total * 100)) + '%');
      };
      return task.promise.then(function (pdf) { return renderPdf(pdf); });
    }, function () {
      // The module itself could not load: use the browser's viewer instead.
      iframeFallback();
    }).catch(function (err) {
      const msg = err && err.name === 'PasswordException'
        ? 'This PDF is password-protected and cannot be previewed.'
        : 'This PDF could not be previewed.';
      fail(msg);
    });
  }

  function renderPdf(pdf) {
    const total = pdf.numPages;
    const slots = [];
    const rendered = new Map();   // page number -> width it was drawn at
    const tasks = new Map();      // page number -> in-flight RenderTask
    const DPR = Math.min(window.devicePixelRatio || 1, 2);   // sharp on HiDPI, bounded memory

    // One placeholder per page, sized to page 1's proportions so the
    // scrollbar is right from the start; each is corrected when it renders.
    return pdf.getPage(1).then(function (first) {
      const base = first.getViewport({ scale: 1 });
      const ratio = base.height / base.width;
      const frag = document.createDocumentFragment();
      for (let n = 1; n <= total; n++) {
        const slot = document.createElement('div');
        slot.className = 'cp-page';
        slot.dataset.page = String(n);
        slot.style.aspectRatio = '1 / ' + ratio;
        slot.setAttribute('role', 'img');
        slot.setAttribute('aria-label', 'Page ' + n + ' of ' + total + ' of ' + label);
        frag.append(slot);
        slots.push(slot);
      }
      pagesEl.replaceChildren(frag);

      function draw(n) {
        const slot = slots[n - 1];
        const width = Math.floor(slot.clientWidth);
        if (!width || rendered.get(n) === width) return Promise.resolve();
        if (tasks.has(n)) tasks.get(n).cancel();
        return pdf.getPage(n).then(function (page) {
          const vp1 = page.getViewport({ scale: 1 });
          const viewport = page.getViewport({ scale: width / vp1.width });
          slot.style.aspectRatio = vp1.width + ' / ' + vp1.height;
          const canvas = document.createElement('canvas');
          canvas.width = Math.floor(viewport.width * DPR);
          canvas.height = Math.floor(viewport.height * DPR);
          const task = page.render({
            canvas: canvas,
            viewport: viewport,
            transform: DPR !== 1 ? [DPR, 0, 0, DPR, 0, 0] : null,
          });
          tasks.set(n, task);
          return task.promise.then(function () {
            tasks.delete(n);
            rendered.set(n, width);
            slot.replaceChildren(canvas);   // swap in only once fully drawn: no half-painted flash
            page.cleanup();
          });
        }).catch(function (err) {
          if (err && err.name === 'RenderingCancelledException') return;
          throw err;
        });
      }

      // Page 1 first, immediately.
      return draw(1).then(function () {
        done();
        setStatus(total === 1 ? '1 page' : total + ' pages');

        // The rest render as they approach the viewport (600px ahead).
        const io = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
          entries.forEach(function (e) {
            if (e.isIntersecting) draw(Number(e.target.dataset.page)).catch(function () {});
          });
        }, { rootMargin: '600px 0px' }) : null;
        slots.slice(1).forEach(function (s) { io ? io.observe(s) : draw(Number(s.dataset.page)); });

        // Re-draw what is visible when the column width changes (rotation,
        // sidebar toggle, zoom), debounced so a drag-resize is not a storm.
        let timer = null, lastWidth = pagesEl.clientWidth;
        if ('ResizeObserver' in window) {
          new ResizeObserver(function () {
            const w = pagesEl.clientWidth;
            if (Math.abs(w - lastWidth) < 8) return;
            lastWidth = w;
            clearTimeout(timer);
            timer = setTimeout(function () {
              slots.forEach(function (s) {
                const r = s.getBoundingClientRect();
                const near = r.bottom > -600 && r.top < window.innerHeight + 600;
                if (near) draw(Number(s.dataset.page)).catch(function () {});
                else rendered.delete(Number(s.dataset.page));   // redraws at the new width when reached
              });
            }, 200);
          }).observe(pagesEl);
        }
      });
    });
  }
})();
