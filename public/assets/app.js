// Business type and modules on the add/edit page. External file because the CSP forbids inline script.
(function () {
  var sel = document.getElementById('business_type');
  var box = document.getElementById('modules');
  if (!sel || !box) { return; }
  var boxes = box.querySelectorAll('input[type=checkbox]');

  // Choosing a type ticks that type's usual modules. Custom leaves the ticks as they are.
  sel.addEventListener('change', function () {
    var opt = sel.options[sel.selectedIndex];
    if (!opt || opt.value === 'custom') { return; }
    var want = (opt.getAttribute('data-modules') || '').split(',');
    for (var i = 0; i < boxes.length; i++) {
      var v = boxes[i].value;
      // The Assistant is an add-on, never part of a type: leave it as the owner set it.
      if (v === 'ai_assistant') { continue; }
      boxes[i].checked = want.indexOf(v) !== -1;
    }
  });

  // Changing a box by hand makes it a custom setup.
  box.addEventListener('change', function (ev) {
    if (!ev.target || ev.target.type !== 'checkbox') { return; }
    if (ev.target.value === 'ai_assistant') { return; }
    for (var i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value === 'custom') { sel.selectedIndex = i; break; }
    }
  });
})();
