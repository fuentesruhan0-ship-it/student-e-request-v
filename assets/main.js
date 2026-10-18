(() => {
  'use strict';

  let confirmEl = null;
  let confirmFinish = null;

  function ensureConfirmDialog() {
    if (confirmEl) return confirmEl;

    const d = document.createElement('dialog');
    d.className = 'glass-modal glass-confirm';
    d.innerHTML = `
      <div class="modal-card">
        <div class="gc-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0
                     0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/>
          </svg>
        </div>
        <h3 class="gc-title">Are you sure?</h3>
        <p class="gc-message"></p>
        <div class="gc-actions">
          <button type="button" class="btn btn-ghost" data-act="no">No</button>
          <button type="button" class="btn btn-primary" data-act="yes">Yes</button>
        </div>
      </div>`;
    document.body.appendChild(d);

    d.querySelector('[data-act="yes"]').addEventListener('click', () => {
      if (confirmFinish) confirmFinish(true);
    });
    d.querySelector('[data-act="no"]').addEventListener('click', () => {
      if (confirmFinish) confirmFinish(false);
    });
    d.addEventListener('cancel', (e) => {
      e.preventDefault();
      if (confirmFinish) confirmFinish(false);
    });
    d.addEventListener('click', (e) => {
      if (e.target === d && confirmFinish) confirmFinish(false);
    });

    confirmEl = d;
    return d;
  }

  function askConfirm(title, message, yesLabel, noLabel) {
    const d = ensureConfirmDialog();
    d.querySelector('.gc-title').textContent = title || 'Are you sure?';
    d.querySelector('.gc-message').textContent = message || '';
    d.querySelector('[data-act="yes"]').textContent = yesLabel || 'Yes';
    d.querySelector('[data-act="no"]').textContent = noLabel || 'No';

    return new Promise((resolve) => {
      let done = false;
      confirmFinish = (v) => {
        if (done) return;
        done = true;
        confirmFinish = null;
        try { d.close(); } catch (_) {}
        resolve(v);
      };
      d.showModal();
    });
  }

  document.addEventListener('click', async (e) => {
    const trigger = e.target.closest('[data-confirm]');
    if (!trigger) return;
    if (trigger.dataset.confirmed === '1') {
      trigger.dataset.confirmed = '';
      return;
    }
    e.preventDefault();
    e.stopPropagation();

    const ok = await askConfirm(
      trigger.dataset.confirmTitle || 'Are you sure?',
      trigger.dataset.confirm || 'This action cannot be undone.',
      trigger.dataset.confirmYes || 'Yes',
      trigger.dataset.confirmNo || 'No'
    );

    if (ok) {
      trigger.dataset.confirmed = '1';
      trigger.click();
    }
  }, true);

  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-open]');
    if (opener) {
      const t = document.getElementById(opener.dataset.open);
      if (t && typeof t.showModal === 'function') t.showModal();
      return;
    }
    const closer = e.target.closest('[data-close]');
    if (closer) {
      const d = closer.closest('dialog');
      if (d) d.close();
      return;
    }
    if (e.target instanceof HTMLDialogElement && e.target.classList.contains('glass-modal')) {
      const r = e.target.getBoundingClientRect();
      const inside =
        e.clientX >= r.left && e.clientX <= r.right &&
        e.clientY >= r.top  && e.clientY <= r.bottom;
      if (!inside) e.target.close();
    }
  });

  if (window.__OPEN_MODAL__) {
    const t = document.getElementById(window.__OPEN_MODAL__);
    if (t && typeof t.showModal === 'function') {
      requestAnimationFrame(() => t.showModal());
    }
  }

  function countUp(el) {
    const raw = el.textContent.replace(/[^\d.]/g, '');
    const target = parseFloat(raw);
    if (isNaN(target) || target === 0) return;
    const prefix = el.textContent.match(/^[^\d]*/)?.[0] || '';
    const suffix = el.textContent.match(/[^\d.]*$/)?.[0] || '';
    const decimals = (raw.split('.')[1] || '').length;
    const start = performance.now();
    const dur = 700;
    function frame(t) {
      const k = Math.min(1, (t - start) / dur);
      const eased = 1 - Math.pow(1 - k, 3);
      const v = target * eased;
      el.textContent = prefix + v.toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      }) + suffix;
      if (k < 1) requestAnimationFrame(frame);
      else el.textContent = prefix + target.toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      }) + suffix;
    }
    requestAnimationFrame(frame);
  }
  document.querySelectorAll('.stat-card .num, .hero-stats .stat-num').forEach(countUp);

  document.addEventListener('click', async (e) => {
    const el = e.target.closest('[data-copy]');
    if (!el) return;
    const value = el.dataset.copy || el.textContent.trim();
    try {
      await navigator.clipboard.writeText(value);
      el.classList.add('copied');
      setTimeout(() => el.classList.remove('copied'), 1400);
    } catch (_) {}
  });

  document.querySelectorAll('[data-time]').forEach((el) => {
    const iso = el.dataset.time;
    if (!iso) return;
    el.title = new Date(iso).toLocaleString();
  });

})();