/* Disponibilidad en vivo: al elegir la fecha se piden las franjas (mesa) o las
   habitaciones (hotel) a /wp-json/reservas/v1/disponibilidad. Sin JavaScript el
   formulario igual se valida en el servidor. */
(function () {
  'use strict';
  if (!window.RHR || !window.fetch) return;

  function option(value, label, disabled) {
    var o = document.createElement('option');
    o.value = value;
    o.textContent = label;
    if (disabled) o.disabled = true;
    return o;
  }

  function fill(select, items, emptyLabel, placeholder) {
    var keep = select.value || select.getAttribute('data-old') || '';
    select.innerHTML = '';
    if (!items.length) {
      select.appendChild(option('', emptyLabel));
      return;
    }
    select.appendChild(option('', placeholder));
    items.forEach(function (it) { select.appendChild(option(it.value, it.label, it.disabled)); });
    if (keep && select.querySelector('option[value="' + keep + '"]:not([disabled])')) select.value = keep;
  }

  function get(params) {
    var url = RHR.api + (RHR.api.indexOf('?') === -1 ? '?' : '&') + new URLSearchParams(params).toString();
    return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  function money(n) {
    return RHR.moneda + Number(n).toLocaleString(undefined, { maximumFractionDigits: 2 });
  }

  document.querySelectorAll('.rhr').forEach(function (box) {
    var form = box.querySelector('form');
    if (box.getAttribute('data-tipo') === 'restaurante') {
      var fecha = form.querySelector('[name=fecha]');
      var hora = form.querySelector('[name=hora]');
      var personas = form.querySelector('[name=personas]');
      var update = function () {
        if (!fecha.value) return;
        get({ tipo: 'restaurante', fecha: fecha.value }).then(function (d) {
          var need = parseInt(personas.value, 10) || 1;
          var items = Object.keys(d.franjas || {}).map(function (h) {
            var libres = d.franjas[h];
            return {
              value: h,
              label: h + (libres < need ? ' · ' + RHR.txt.completo : ' · ' + libres + ' ' + RHR.txt.libres),
              disabled: libres < need,
            };
          });
          fill(hora, items, RHR.txt.sinLugar, RHR.txt.elige);
        });
      };
      fecha.addEventListener('change', update);
      personas.addEventListener('change', update);
      update();
    } else {
      var checkin = form.querySelector('[name=checkin]');
      var checkout = form.querySelector('[name=checkout]');
      var hab = form.querySelector('[name=habitacion]');
      var total = form.querySelector('.rhr-total');
      var updateHotel = function () {
        if (checkin.value) checkout.min = checkin.value;
        total.textContent = '';
        if (!checkin.value || !checkout.value) return;
        get({ tipo: 'hotel', checkin: checkin.value, checkout: checkout.value }).then(function (d) {
          var habs = d.habitaciones || {};
          var items = Object.keys(habs).map(function (k) {
            var h = habs[k];
            return {
              value: k,
              label: h.nombre + (h.total > 0 ? ' · ' + money(h.total) : '') + (h.libres ? '' : ' · ' + RHR.txt.completo),
              disabled: !h.libres,
            };
          });
          fill(hab, items, RHR.txt.sinHab, RHR.txt.eligeHab);
          var show = function () {
            var h = habs[hab.value];
            total.textContent = h && h.total > 0 ? d.noches + ' ' + RHR.txt.noches + ' · ' + money(h.total) : '';
          };
          hab.onchange = show;
          show();
        });
      };
      checkin.addEventListener('change', updateHotel);
      checkout.addEventListener('change', updateHotel);
      updateHotel();
    }
  });
})();
