(() => {
  'use strict';
  const url  = window.SB_URL?.smarty;
  const csrf = window.SB_URL?.csrf;
  if (!url || !csrf) return;

  const KEY = 'smarty_hist_' + (window.SB_URL?.shopId || 's');
  const starter = [
    "Today's sales?",
    "Who owes me money?",
    "Low stock items",
    "Top product this week",
    "How do I add a staff?",
  ];

  // Build the widget
  const fab = document.createElement('button');
  fab.className = 'smarty-fab';
  fab.title = 'Ask Smarty';
  fab.innerHTML = '<i class="bi bi-stars"></i>';
  document.body.appendChild(fab);

  const panel = document.createElement('div');
  panel.className = 'smarty-panel';
  panel.innerHTML = `
    <div class="smarty-head">
      <div class="av"><i class="bi bi-stars"></i></div>
      <div>
        <div class="title">Smarty</div>
        <div class="sub">Your shop AI · ask me anything</div>
      </div>
      <button type="button" title="Close">&times;</button>
    </div>
    <div class="smarty-body" id="smartyBody"></div>
    <div class="smarty-chips" id="smartyChips">
      ${starter.map(s => `<button type="button" class="smarty-chip">${s}</button>`).join('')}
    </div>
    <form class="smarty-input" id="smartyForm">
      <textarea placeholder="Ask Smarty… (English / हिंदी / ଓଡ଼ିଆ)" rows="1" id="smartyInput"></textarea>
      <button type="submit" title="Send"><i class="bi bi-send-fill"></i></button>
    </form>
  `;
  document.body.appendChild(panel);

  const body  = panel.querySelector('#smartyBody');
  const input = panel.querySelector('#smartyInput');
  const form  = panel.querySelector('#smartyForm');
  const chips = panel.querySelector('#smartyChips');
  const closeBtn = panel.querySelector('button[title="Close"]');

  const open = () => {
    panel.classList.add('show');
    setTimeout(() => input.focus(), 50);
    if (!body.children.length) {
      // Restore history or show greeting
      const h = loadHistory();
      if (h.length) h.forEach(m => bubble(m.role, m.text));
      else bubble('b', "Hi 👋 I'm Smarty. I can tell you about your sales, dues, stock, products and more. What would you like to know?");
      scroll();
    }
  };
  const close = () => panel.classList.remove('show');

  fab.addEventListener('click', () => panel.classList.contains('show') ? close() : open());
  closeBtn.addEventListener('click', close);

  chips.addEventListener('click', (e) => {
    const b = e.target.closest('.smarty-chip');
    if (!b) return;
    input.value = b.textContent;
    form.requestSubmit();
  });

  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
    // Auto-grow
    input.style.height = 'auto';
    input.style.height = Math.min(110, input.scrollHeight) + 'px';
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const q = input.value.trim();
    if (!q) return;
    input.value = ''; input.style.height = 'auto';
    bubble('u', q);
    const typing = showTyping();

    const fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('q', q);
    try {
      const r = await fetch(url, { method:'POST', body: fd });
      const d = await r.json();
      typing.remove();
      if (d.ok) { bubble('b', d.text); }
      else      { bubble('err', '⚠️ ' + (d.error || 'Something went wrong.')); }
    } catch (err) {
      typing.remove();
      bubble('err', '⚠️ Network error. Try again.');
    }
  });

  function bubble(role, text) {
    const div = document.createElement('div');
    div.className = 'smarty-msg ' + role;
    div.textContent = text;
    body.appendChild(div);
    scroll();
    if (role !== 'err') saveToHistory(role, text);
  }
  function showTyping() {
    const t = document.createElement('div');
    t.className = 'smarty-typing';
    t.innerHTML = '<span></span><span></span><span></span>';
    body.appendChild(t); scroll(); return t;
  }
  function scroll() { body.scrollTop = body.scrollHeight; }

  function loadHistory() {
    try { return JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch { return []; }
  }
  function saveToHistory(role, text) {
    try {
      const h = loadHistory();
      h.push({ role, text });
      // Keep last 20
      sessionStorage.setItem(KEY, JSON.stringify(h.slice(-20)));
    } catch {}
  }
})();
