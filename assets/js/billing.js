(() => {
  'use strict';
  const CURRENCY = '₹';
  const el  = (id) => document.getElementById(id);
  const inr = (n) => CURRENCY + (Math.round(n * 100) / 100).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
  const debounce = (fn, ms = 220) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

  const cart = []; // {id, name, sku, unit, price, stock, qty, disc}

  // ------- Customer: mobile first → lookup → name -------
  const mobEl    = el('custMobile');
  const nameEl   = el('custName');
  const statusEl = el('custStatus');
  const fId      = el('fCustId');
  const fMobile  = el('fCustMobile');
  const fName    = el('fCustName');

  const setStatus = (html, cls = 'text-muted') => { statusEl.innerHTML = `<span class="${cls}">${html}</span>`; };

  // Core lookup — returns true if a customer was found.
  async function doLookup() {
    const m = mobEl.value.trim();
    fMobile.value = m;
    if (m.length < 6) {
      fId.value = ''; refreshConfirm();
      setStatus('Enter at least 6 digits.');
      return false;
    }
    try {
      const r = await fetch(window.SB_URL.customerByMobile + '?mobile=' + encodeURIComponent(m));
      const d = await r.json();
      if (d.customer) {
        fId.value   = d.customer.id;
        nameEl.value = d.customer.name;
        fName.value  = d.customer.name;
        setStatus(`<i class="bi bi-check2-circle me-1"></i>Existing customer — ${d.customer.name}`, 'text-success');
        refreshConfirm();
        return true;
      } else {
        fId.value = '';
        setStatus(`<i class="bi bi-person-plus me-1"></i>New customer — enter the name to create.`, 'text-primary');
        refreshConfirm();
        return false;
      }
    } catch {
      setStatus('Lookup failed — try again.', 'text-danger');
      return false;
    }
  }
  const lookupDebounced = debounce(doLookup, 260);

  mobEl.addEventListener('input', () => { fMobile.value = mobEl.value.trim(); lookupDebounced(); });
  nameEl.addEventListener('input', () => { fName.value = nameEl.value.trim(); refreshConfirm(); });

  // Enter in mobile → immediate lookup, then focus name
  mobEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      doLookup().then(found => {
        nameEl.focus();
        if (found) nameEl.select();
      });
    }
  });

  // Blur mobile → ensure lookup ran, then move focus to name if empty
  mobEl.addEventListener('blur', () => {
    if (mobEl.value.trim().length >= 6) {
      doLookup().then(found => {
        if (!found && nameEl.value.trim() === '') nameEl.focus();
      });
    }
  });

  // Enter in name → jump to product search
  nameEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); prodSearch.focus(); }
  });

  if (fId.value) setStatus(`<i class="bi bi-check2-circle me-1"></i>Existing customer selected.`, 'text-success');

  // ------- Product search -------
  const prodSearch = el('prodSearch');
  const prodMenu   = el('prodMenu');

  const showMenu = (html) => { prodMenu.innerHTML = html; prodMenu.classList.add('show'); };
  const hideMenu = () => { prodMenu.classList.remove('show'); };

  const searchProd = debounce(async () => {
    const q = prodSearch.value.trim();
    if (q.length < 1) { hideMenu(); return; }
    try {
      const r = await fetch(window.SB_URL.searchProducts + '?q=' + encodeURIComponent(q));
      const d = await r.json();
      const items = d.items || [];
      if (!items.length) { showMenu('<div class="dropdown-item text-muted">No match for "' + q + '"</div>'); return; }
      showMenu(items.map(p => {
        const bad = (!p.is_available || p.current_stock <= 0);
        const cls = bad ? 'text-danger' : '';
        return `<a class="dropdown-item ${cls}" href="#" data-id="${p.id}" data-name="${p.name}"
                  data-price="${p.selling_price}" data-stock="${p.current_stock}" data-unit="${p.unit}"
                  data-sku="${p.sku||''}" data-avail="${p.is_available}">
                  <strong>${p.name}</strong>${p.sku ? ' <span class="text-muted small">· '+p.sku+'</span>' : ''}
                  <div class="small text-muted">
                    Stock: ${p.current_stock} ${p.unit} · Price: ${CURRENCY}${Number(p.selling_price).toFixed(2)}
                  </div>
                </a>`;
      }).join(''));
    } catch { showMenu('<div class="dropdown-item text-danger">Search failed — try again</div>'); }
  });

  prodSearch.addEventListener('input', searchProd);
  prodSearch.addEventListener('focus', () => { if (prodSearch.value.trim()) searchProd(); });

  // Use mousedown instead of click — click can be swallowed by the blur-hide race.
  prodMenu.addEventListener('mousedown', (e) => {
    const a = e.target.closest('.dropdown-item[data-id]'); if (!a) return;
    e.preventDefault();
    addToCart({
      id: a.dataset.id, name: a.dataset.name, sku: a.dataset.sku,
      unit: a.dataset.unit, price: parseFloat(a.dataset.price),
      stock: parseFloat(a.dataset.stock), avail: a.dataset.avail === '1',
    });
    prodSearch.value = ''; hideMenu();
    setTimeout(() => prodSearch.focus(), 0);
  });

  // Hide when clicking anywhere else
  document.addEventListener('mousedown', (e) => {
    if (!e.target.closest('#prodSearch') && !e.target.closest('#prodMenu')) hideMenu();
  });

  function addToCart(p) {
    const existing = cart.find(x => x.id == p.id);
    if (existing) {
      existing.qty = (parseFloat(existing.qty) || 0) + 1;
    } else {
      cart.push({ ...p, qty: 1, disc: 0 });
    }
    render();
    // Focus the qty cell of the row we just added / bumped
    setTimeout(() => {
      const i = cart.findIndex(x => x.id == p.id);
      const q = document.querySelector(`#cartTable input[data-i="${i}"][data-f="qty"]`);
      if (q) { q.focus(); q.select(); }
    }, 0);
  }

  // ------- Cart rendering + totals -------
  const tbody    = document.querySelector('#cartTable tbody');
  const emptyRow = el('emptyRow');

  const render = () => {
    tbody.innerHTML = cart.map((it, i) => {
      const line = Math.max(0, (it.qty * it.price) - it.disc);
      const warn = (it.qty > it.stock) ? ' text-danger fw-semibold' : '';
      return `<tr>
        <td>
          <strong>${it.name}</strong>${it.sku ? ' <span class="text-muted small">· '+it.sku+'</span>' : ''}
          <div class="small text-muted">Stock: <span class="${warn}">${it.stock}</span> ${it.unit}</div>
        </td>
        <td><input class="form-control form-control-sm" type="number" step="0.001" min="0.001" data-i="${i}" data-f="qty"   value="${it.qty}"></td>
        <td><input class="form-control form-control-sm" type="number" step="0.01"  min="0"     data-i="${i}" data-f="price" value="${it.price}"></td>
        <td><input class="form-control form-control-sm" type="number" step="0.01"  min="0"     data-i="${i}" data-f="disc"  value="${it.disc}"></td>
        <td class="sb-money">${inr(line)}</td>
        <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-rem="${i}"><i class="bi bi-trash"></i></button></td>
      </tr>`;
    }).join('');
    emptyRow.style.display = cart.length ? 'none' : '';
    recalc();
  };

  tbody.addEventListener('input', (e) => {
    const t = e.target.closest('input[data-i]'); if (!t) return;
    const i = +t.dataset.i;
    cart[i][t.dataset.f] = parseFloat(t.value) || 0;
    recalc();
  });

  tbody.addEventListener('click', (e) => {
    const b = e.target.closest('[data-rem]'); if (!b) return;
    cart.splice(+b.dataset.rem, 1); render();
  });

  const orderDisc = el('orderDiscount');
  const taxPct    = el('taxPercent');
  const paid      = el('amountPaid');
  const btn       = el('btnConfirm');
  const hint      = el('confirmHint');

  function refreshConfirm() {
    const okMobile = (fMobile.value || '').length >= 6;
    const okName   = (fName.value || '').trim().length >= 1 || !!fId.value;
    const okCart   = cart.length > 0;
    btn.disabled = !(okMobile && okName && okCart);
    if (btn.disabled) {
      if (!okMobile)       hint.textContent = 'Enter a valid mobile number (≥ 6 digits).';
      else if (!okName)    hint.textContent = 'Enter the customer name.';
      else if (!okCart)    hint.textContent = 'Add at least one product.';
    } else {
      hint.textContent = 'Ready to save.';
    }
  }

  const recalc = () => {
    const sub       = cart.reduce((s, it) => s + (it.qty * it.price), 0);
    const lineDisc  = cart.reduce((s, it) => s + Math.min(it.disc, it.qty * it.price), 0);
    const afterLine = Math.max(0, sub - lineDisc);
    const od        = Math.max(0, Math.min(parseFloat(orderDisc.value) || 0, afterLine));
    const base      = afterLine - od;
    const tp        = Math.max(0, Math.min(parseFloat(taxPct.value) || 0, 100));
    const tax       = Math.round(base * tp) / 100;
    const total     = Math.max(0, base + tax);
    const bal       = Math.max(0, total - (parseFloat(paid.value) || 0));

    el('dispSub').textContent       = inr(sub);
    el('dispLineDisc').textContent  = '-' + inr(lineDisc);
    el('dispOrderDisc').textContent = '-' + inr(od);
    el('dispTax').textContent       = inr(tax);
    el('dispTotal').textContent     = inr(total);
    el('dispBalance').textContent   = inr(bal);

    el('fItemsJson').value = JSON.stringify(cart.map(it => ({
      product_id: +it.id, qty: it.qty, unit_price: it.price, discount: it.disc,
    })));
    refreshConfirm();
  };

  orderDisc.addEventListener('input', recalc);
  taxPct.addEventListener('input', recalc);
  paid.addEventListener('input', recalc);

  refreshConfirm();
})();
