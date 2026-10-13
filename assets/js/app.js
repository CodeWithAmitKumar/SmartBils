// Smart Billing — shared UI JS
(function () {
  'use strict';

  // Sidebar toggle (mobile)
  const sidebar = document.getElementById('sbSidebar');
  const overlay = document.getElementById('sbSidebarOverlay');
  const toggle  = document.getElementById('sbSidebarToggle');
  const close   = document.getElementById('sbSidebarClose');
  const open  = () => { sidebar?.classList.add('show'); overlay?.classList.add('show'); };
  const shut  = () => { sidebar?.classList.remove('show'); overlay?.classList.remove('show'); };
  toggle?.addEventListener('click', open);
  close ?.addEventListener('click', shut);
  overlay?.addEventListener('click', shut);

  // Auto-hide flash after 5s
  document.querySelectorAll('.sb-flash').forEach(el => {
    setTimeout(() => {
      const inst = bootstrap?.Alert?.getOrCreateInstance?.(el);
      inst ? inst.close() : el.remove();
    }, 5000);
  });

  // Confirm on data-confirm elements
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-confirm]');
    if (!t) return;
    if (!confirm(t.dataset.confirm)) { e.preventDefault(); e.stopPropagation(); }
  });

  // Prevent double-submit on forms with .js-once
  document.querySelectorAll('form.js-once').forEach(form => {
    form.addEventListener('submit', () => {
      const btn = form.querySelector('button[type="submit"], input[type="submit"]');
      if (btn) { btn.disabled = true; btn.dataset._label = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Please wait…'; }
    });
  });
})();
