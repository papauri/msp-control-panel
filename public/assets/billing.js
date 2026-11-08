// Billing pages (invoice / quotation editor, confirm buttons). External file because the CSP forbids inline script.
// The server recalculates every total on save; the figures here are only a live preview.
(function () {
  // Forms that ask before doing something final (void, convert, mark sent).
  var confirms = document.querySelectorAll('form[data-confirm]');
  for (var i = 0; i < confirms.length; i++) {
    confirms[i].addEventListener('submit', function (ev) {
      if (!window.confirm(this.getAttribute('data-confirm'))) { ev.preventDefault(); }
    });
  }

  var form = document.getElementById('doc-form');
  var body = document.getElementById('lines');
  var tpl = document.getElementById('line-template');
  if (!form || !body || !tpl) { return; }

  function num(v) {
    v = String(v || '').replace(/,/g, '').trim();
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }
  function r2(n) { return Math.round((n + (n >= 0 ? 1e-9 : -1e-9)) * 100) / 100; }
  function currency() {
    var c = document.getElementById('currency');
    return (c && c.value ? c.value : form.getAttribute('data-currency') || '').toUpperCase();
  }
  function money(n) {
    n = r2(n);
    var neg = n < 0;
    var parts = Math.abs(n).toFixed(2).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (neg ? '-' : '') + currency() + parts.join('.');
  }
  function setText(id, text) {
    var el = document.getElementById(id);
    if (el) { el.textContent = text; }
  }

  function recalc() {
    var rows = body.querySelectorAll('tr.line');
    var sub = 0;
    for (var i = 0; i < rows.length; i++) {
      var q = rows[i].querySelector('.in-qty');
      var p = rows[i].querySelector('.in-price');
      var lt = r2(num(q && q.value) * num(p && p.value));
      sub = r2(sub + lt);
      var cell = rows[i].querySelector('.line-total');
      if (cell) { cell.textContent = money(lt); }
    }
    var discEl = document.getElementById('discount');
    var rateEl = document.getElementById('tax_rate');
    var disc = r2(num(discEl && discEl.value));
    var rate = num(rateEl && rateEl.value);
    var tax = r2((sub - disc) * rate / 100);
    setText('t-sub', money(sub));
    setText('t-disc', money(disc));
    setText('t-tax', money(tax));
    setText('t-total', money(r2(sub - disc + tax)));
  }

  function addLine(qty, item, desc, price) {
    var frag = tpl.content ? tpl.content.cloneNode(true) : null;
    var row;
    if (frag) {
      row = frag.querySelector('tr');
    } else { // very old browsers without <template>
      var tmp = document.createElement('tbody');
      tmp.innerHTML = tpl.innerHTML;
      row = tmp.querySelector('tr');
    }
    if (!row) { return; }
    // Replace a single empty starting line instead of leaving it blank above the new one.
    var rows = body.querySelectorAll('tr.line');
    if (rows.length === 1 && item) {
      var only = rows[0];
      var it = only.querySelector('.in-item');
      var pr = only.querySelector('.in-price');
      var de = only.querySelector('.in-desc');
      if (it && !it.value && (!pr || !pr.value) && (!de || !de.value)) { body.removeChild(only); }
    }
    if (qty !== undefined) { row.querySelector('.in-qty').value = qty; }
    if (item !== undefined) { row.querySelector('.in-item').value = item; }
    if (desc !== undefined) { row.querySelector('.in-desc').value = desc; }
    if (price !== undefined) { row.querySelector('.in-price').value = price; }
    body.appendChild(row);
    recalc();
    var focus = row.querySelector(item ? '.in-qty' : '.in-item');
    if (focus) { focus.focus(); }
  }

  body.addEventListener('click', function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest('.js-remove-line') : null;
    if (!btn) { return; }
    var row = btn.closest('tr');
    if (row && row.parentNode === body) { body.removeChild(row); }
    if (!body.querySelector('tr.line')) { addLine(); }
    recalc();
  });
  form.addEventListener('input', function (ev) {
    var t = ev.target;
    if (!t || !t.classList) { return; }
    if (t.classList.contains('in-qty') || t.classList.contains('in-price') || t.id === 'discount' || t.id === 'tax_rate' || t.id === 'currency') {
      recalc();
    }
  });

  var addBtn = document.getElementById('add-line');
  if (addBtn) { addBtn.addEventListener('click', function () { addLine(); }); }

  var pick = document.getElementById('catalog-pick');
  var addCat = document.getElementById('add-catalog');
  if (pick && addCat) {
    addCat.addEventListener('click', function () {
      var opt = pick.options[pick.selectedIndex];
      if (!opt || !opt.value) { pick.focus(); return; }
      var desc = opt.getAttribute('data-desc') || '';
      var tsel = document.getElementById('tenant-pick');
      var topt = tsel ? tsel.options[tsel.selectedIndex] : null;
      // "<domain>" in a saved description is filled with the business's website when one is chosen.
      if (topt && topt.value && desc.indexOf('<domain>') !== -1) {
        var dm = topt.getAttribute('data-domain') || '';
        if (dm) { desc = desc.replace('<domain>', dm); }
      }
      addLine('1', opt.getAttribute('data-item') || '', desc, opt.getAttribute('data-price') || '');
      pick.selectedIndex = 0;
    });
  }

  var tenantSel = document.getElementById('tenant-pick');
  var subBtn = document.getElementById('add-sub');
  var subMsg = document.getElementById('sub-msg');
  if (subBtn && tenantSel) {
    subBtn.addEventListener('click', function () {
      var opt = tenantSel.options[tenantSel.selectedIndex];
      if (!opt || !opt.value) {
        if (subMsg) { subMsg.hidden = false; }
        tenantSel.focus();
        return;
      }
      if (subMsg) { subMsg.hidden = true; }
      var mEl = document.getElementById('sub-months');
      var months = Math.max(1, Math.min(60, parseInt(mEl && mEl.value, 10) || 1));
      var desc = (opt.getAttribute('data-sub-desc') || '').replace(/\n1 month at the monthly fee\.$/, '\n' + months + ' month' + (months === 1 ? '' : 's') + ' at the monthly fee.');
      addLine(String(months), opt.getAttribute('data-sub-item') || 'Licence', desc, opt.getAttribute('data-sub-price') || '0');
      var ext = document.getElementById('extend_months');
      if (ext && !ext.disabled && (!ext.value || ext.value === '0')) { ext.value = String(months); }
    });
  }

  // Choosing a business fills the customer fields that are still empty (or still hold the previous business's details).
  if (tenantSel && !tenantSel.disabled) {
    var last = tenantSel.options[tenantSel.selectedIndex];
    tenantSel.addEventListener('change', function () {
      var opt = tenantSel.options[tenantSel.selectedIndex];
      var map = { customer_name: 'data-name', customer_contact: 'data-contact', customer_email: 'data-email', customer_address: 'data-address', currency: 'data-currency' };
      for (var id in map) {
        if (!Object.prototype.hasOwnProperty.call(map, id)) { continue; }
        var el = document.getElementById(id);
        if (!el) { continue; }
        var prev = last && last.value ? (last.getAttribute(map[id]) || '') : '';
        if (opt && opt.value && (el.value === '' || el.value === prev)) {
          el.value = opt.getAttribute(map[id]) || el.value;
        }
      }
      last = opt;
      if (subMsg) { subMsg.hidden = true; }
      recalc();
    });
  }

  recalc();
})();
