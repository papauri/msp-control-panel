// Billing pages (invoice / quotation editor, recurring schedule editor, confirm buttons). External file because the CSP
// forbids inline script. The server recalculates every total on save; the figures here are only a live preview.
(function () {
  // Forms that ask before doing something final (void, convert, mark sent).
  var confirms = document.querySelectorAll('form[data-confirm]');
  for (var i = 0; i < confirms.length; i++) {
    confirms[i].addEventListener('submit', function (ev) {
      if (!window.confirm(this.getAttribute('data-confirm'))) { ev.preventDefault(); }
    });
  }
  // Forms sent once (record payment, save): the button is disabled after the first click. The server also refuses a
  // repeat (one-time token), this only spares the owner the message.
  var onceForms = document.querySelectorAll('form.js-once');
  for (var o = 0; o < onceForms.length; o++) {
    onceForms[o].addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) { return; }
      var f = this;
      if (f.getAttribute('data-sent') === '1') { ev.preventDefault(); return; }
      f.setAttribute('data-sent', '1');
      window.setTimeout(function () {
        var b = f.querySelectorAll('button[type=submit]');
        for (var j = 0; j < b.length; j++) { b[j].disabled = true; }
      }, 0);
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
    var cost = 0;
    var revKnown = 0;
    var known = 0;
    var unknown = 0;
    for (var i = 0; i < rows.length; i++) {
      var q = rows[i].querySelector('.in-qty');
      var p = rows[i].querySelector('.in-price');
      var c = rows[i].querySelector('.in-cost');
      var it = rows[i].querySelector('.in-item');
      var lt = r2(num(q && q.value) * num(p && p.value));
      sub = r2(sub + lt);
      var cell = rows[i].querySelector('.line-total');
      if (cell) { cell.textContent = money(lt); }
      if (!it || !it.value) { continue; }
      if (c && String(c.value).trim() !== '') {
        known++;
        cost = r2(cost + r2(num(q && q.value) * num(c.value)));
        revKnown = r2(revKnown + lt);
      } else {
        unknown++;
      }
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
    // Same rule as billing_profit(): complete = every line has a cost; otherwise only the lines with one.
    if (known === 0) {
      setText('t-profit', 'add cost prices');
    } else if (unknown === 0) {
      setText('t-profit', money(r2(sub - disc - cost)));
    } else {
      setText('t-profit', money(r2(revKnown - cost)) + ' (lines with a cost)');
    }
  }

  // Recurring schedules: a line whose price follows the Catalog / the monthly fee / the discount cannot be typed over.
  function setFollow(row, follow) {
    var f = row ? row.querySelector('.in-follow') : null;
    if (!f) { return; }
    if (follow !== undefined) { f.value = follow; }
    var pr = row.querySelector('.in-price');
    var ce = row.querySelector('.in-cost');
    if (pr) { pr.readOnly = f.value !== ''; }
    if (ce) { ce.readOnly = f.value === 'plan'; }
  }

  function addLine(qty, item, desc, price, cost, catId, follow) {
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
    var ce = row.querySelector('.in-cost');
    if (ce && cost !== undefined) { ce.value = cost; }
    var ci = row.querySelector('.in-cat');
    if (ci && catId !== undefined) { ci.value = catId; }
    setFollow(row, follow === undefined ? '' : follow);
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
    if (t.classList.contains('in-item')) {
      // A renamed line is no longer the saved item it came from.
      var row = t.closest('tr');
      var ci = row ? row.querySelector('.in-cat') : null;
      if (ci && ci.value && t.getAttribute('data-picked') && t.value !== t.getAttribute('data-picked')) {
        ci.value = '';
        var fo = row.querySelector('.in-follow');
        if (fo && fo.value === 'plan') { setFollow(row, ''); }
      }
    }
    if (t.classList.contains('in-qty') || t.classList.contains('in-price') || t.classList.contains('in-cost') || t.classList.contains('in-item')
        || t.id === 'discount' || t.id === 'tax_rate' || t.id === 'currency') {
      recalc();
    }
  });

  form.addEventListener('change', function (ev) {
    var t = ev.target;
    if (t && t.classList && t.classList.contains('in-follow')) {
      var row = t.closest('tr');
      var ci = row ? row.querySelector('.in-cat') : null;
      if (t.value === 'plan' && ci && !ci.value) {
        t.value = ''; // only a line added from a saved item can follow the Catalog
        t.setCustomValidity && t.setCustomValidity('');
      }
      setFollow(row);
    }
  });

  var addBtn = document.getElementById('add-line');
  if (addBtn) { addBtn.addEventListener('click', function () { addLine(); }); }

  var clientSel = document.getElementById('client-pick');
  var pick = document.getElementById('catalog-pick');
  var addCat = document.getElementById('add-catalog');
  if (pick && addCat) {
    addCat.addEventListener('click', function () {
      var opt = pick.options[pick.selectedIndex];
      if (!opt || !opt.value) { pick.focus(); return; }
      var desc = opt.getAttribute('data-desc') || '';
      var copt = clientSel ? clientSel.options[clientSel.selectedIndex] : null;
      // "<domain>" in a saved description is filled with the hosted business's website when the client has one.
      if (copt && copt.value && desc.indexOf('<domain>') !== -1) {
        var dm = copt.getAttribute('data-domain') || '';
        if (dm) { desc = desc.replace('<domain>', dm); }
      }
      addLine('1', opt.getAttribute('data-item') || '', desc, opt.getAttribute('data-price') || '', opt.getAttribute('data-cost') || '', opt.value, 'plan');
      var rows = body.querySelectorAll('tr.line');
      var last = rows.length ? rows[rows.length - 1].querySelector('.in-item') : null;
      if (last) { last.setAttribute('data-picked', last.value); }
      pick.selectedIndex = 0;
    });
  }

  var subBtn = document.getElementById('add-sub');
  var subMsg = document.getElementById('sub-msg');
  if (subBtn && clientSel) {
    subBtn.addEventListener('click', function () {
      var opt = clientSel.options[clientSel.selectedIndex];
      if (!opt || !opt.value || opt.getAttribute('data-hosted') !== '1') {
        if (subMsg) { subMsg.hidden = false; }
        clientSel.focus();
        return;
      }
      if (subMsg) { subMsg.hidden = true; }
      var mEl = document.getElementById('sub-months');
      var months = Math.max(1, Math.min(60, parseInt(mEl && mEl.value, 10) || 1));
      var desc = (opt.getAttribute('data-sub-desc') || '').replace(/\n1 month at the monthly fee\.$/, '\n' + months + ' month' + (months === 1 ? '' : 's') + ' at the monthly fee.');
      addLine(String(months), opt.getAttribute('data-sub-item') || 'Licence', desc, opt.getAttribute('data-sub-price') || '0', '', '', 'monthly_fee');
      var ext = document.getElementById('extend_months');
      if (ext && !ext.disabled && (!ext.value || ext.value === '0')) { ext.value = String(months); }
    });
  }

  // Choosing a client fills the printed customer fields that are still empty (or still hold the previous client's
  // details); "New client" shows the new-client fields.
  var newBox = document.getElementById('new-client');
  if (clientSel && !clientSel.disabled) {
    var lastOpt = clientSel.options[clientSel.selectedIndex];
    clientSel.addEventListener('change', function () {
      var opt = clientSel.options[clientSel.selectedIndex];
      var isNew = opt && opt.value === 'new';
      if (newBox) { newBox.hidden = !isNew; }
      if (isNew) {
        var nn = document.getElementById('nc_name');
        if (nn) { nn.focus(); }
      }
      var map = { customer_name: 'data-name', customer_contact: 'data-contact', customer_email: 'data-email', customer_address: 'data-address', currency: 'data-currency' };
      for (var id in map) {
        if (!Object.prototype.hasOwnProperty.call(map, id)) { continue; }
        var el = document.getElementById(id);
        if (!el) { continue; }
        var prev = lastOpt && lastOpt.value && lastOpt.value !== 'new' ? (lastOpt.getAttribute(map[id]) || '') : '';
        if (opt && opt.value && !isNew && (el.value === '' || el.value === prev)) {
          el.value = opt.getAttribute(map[id]) || el.value;
        } else if (isNew && id !== 'currency' && el.value === prev) {
          el.value = '';
        }
      }
      lastOpt = opt;
      if (subMsg) { subMsg.hidden = true; }
      recalc();
    });
  }

  recalc();
})();
