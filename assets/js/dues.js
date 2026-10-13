(() => {
  'use strict';
  const CURRENCY = '₹';
  const el = (id) => document.getElementById(id);
  const inr = (n) => CURRENCY + (Math.round(n * 100) / 100).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
  const debounce = (fn, ms = 260) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

  const mobEl   = el('custMobile');
  const nameEl  = el('custName');
  const hintEl  = el('custHint');
  const tbody   = document.querySelector('#invTable tbody');
  const emptyEl = () => el('invEmpty');
  const fCustId = el('fCustId');
  const fAllocs = el('fAllocs');
  const btn     = el('btnCollect');
  const hint    = el('collectHint');
  const dispTot = el('dispTotal');
  const selCnt  = el('selectedCount');

  let invoices = [];

  const render = () => {
    if (!invoices.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3" id="invEmpty">No outstanding invoices for this customer.</td></tr>';
      recalc();
      return;
    }
    tbody.innerHTML = invoices.map((inv, i) => `
      <tr>
        <td><input type="checkbox" class="form-check-input" data-i="${i}" data-f="sel"></td>
        <td><code>${inv.invoice_no}</code></td>
        <td class="text-muted small">${new Date(inv.date).toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'})}</td>
        <td class="sb-money">${inr(inv.grand_total)}</td>
        <td class="sb-money">${inr(inv.paid)}</td>
        <td class="sb-money fw-semibold text-danger">${inr(inv.outstanding)}</td>
        <td><input class="form-control form-control-sm text-end amt" type="number" step="0.01" min="0" max="${inv.outstanding}"
                   data-i="${i}" data-f="amt" value="0" disabled></td>
      </tr>`).join('');
    recalc();
  };

  const lookup = debounce(async () => {
    const m = mobEl.value.trim();
    nameEl.value = ''; fCustId.value = ''; invoices = [];
    if (m.length < 6) { hintEl.textContent = 'Enter at least 6 digits.'; render(); return; }
    try {
      const r = await fetch(window.SB_URL.customerByMobile + '?mobile=' + encodeURIComponent(m));
      const d = await r.json();
      if (!d.customer) {
        hintEl.innerHTML = '<span class="text-danger">No customer with that mobile.</span>';
        render(); return;
      }
      nameEl.value = d.customer.name + (d.customer.mobile ? ' · ' + d.customer.mobile : '');
      fCustId.value = d.customer.id;
      hintEl.innerHTML = `<span class="text-success">Loading invoices for ${d.customer.name}…</span>`;
      const r2 = await fetch(window.SB_URL.customerInvoices + '?customer_id=' + d.customer.id);
      const d2 = await r2.json();
      invoices = d2.items || [];
      hintEl.innerHTML = invoices.length
        ? `<span class="text-success">${invoices.length} invoice(s) with pending amount.</span>`
        : `<span class="text-muted">All invoices settled for this customer.</span>`;
      render();
    } catch { hintEl.innerHTML = '<span class="text-danger">Lookup failed — try again.</span>'; }
  });

  mobEl.addEventListener('input', lookup);
  mobEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); lookup(); } });

  tbody.addEventListener('change', (e) => {
    const t = e.target.closest('input[data-i][data-f="sel"]');
    if (!t) return;
    const i = +t.dataset.i;
    const amtEl = tbody.querySelector(`input[data-i="${i}"][data-f="amt"]`);
    if (t.checked) {
      amtEl.disabled = false;
      amtEl.value = invoices[i].outstanding; // default to full outstanding
      amtEl.focus(); amtEl.select();
    } else {
      amtEl.disabled = true;
      amtEl.value = 0;
    }
    recalc();
  });

  tbody.addEventListener('input', (e) => {
    const t = e.target.closest('input[data-i][data-f="amt"]'); if (!t) return;
    const i = +t.dataset.i;
    const v = parseFloat(t.value) || 0;
    const cap = invoices[i].outstanding;
    if (v > cap) t.value = cap;
    recalc();
  });

  const recalc = () => {
    const allocs = [];
    let total = 0, count = 0;
    tbody.querySelectorAll('input[data-f="amt"]').forEach(a => {
      const i = +a.dataset.i;
      const amt = parseFloat(a.value) || 0;
      if (amt > 0 && !a.disabled) {
        allocs.push({ sale_id: invoices[i].id, amount: amt });
        total += amt;
        count++;
      }
    });
    fAllocs.value = JSON.stringify(allocs);
    dispTot.textContent = inr(total);
    selCnt.textContent  = count ? `${count} invoice(s) · ${inr(total)}` : 'Nothing selected';
    btn.disabled = !(fCustId.value && count > 0 && total > 0);
    if (btn.disabled) {
      if (!fCustId.value)      hint.textContent = 'Find a customer first.';
      else if (count === 0)    hint.textContent = 'Select at least one invoice and enter an amount.';
    } else {
      hint.textContent = 'Ready to save.';
    }
  };
})();
