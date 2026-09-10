/* =====================================================================
   Generador criptografico y medidor de robustez.

   La contrasena se genera en el servidor (random_bytes) y la evaluacion
   de robustez no se almacena en ningun sitio.
   ===================================================================== */
(function () {
  'use strict';

  var App = window.App;
  var $ = App.$, $$ = App.$$;

  function initGenerator() {
    var panel = $('[data-generator]');
    if (!panel) { return; }
    var target = document.getElementById(panel.dataset.generator);

    function options() {
      return {
        length: parseInt($('[name=gen_length]', panel).value, 10) || 20,
        upper: $('[name=gen_upper]', panel).checked,
        lower: $('[name=gen_lower]', panel).checked,
        digits: $('[name=gen_digits]', panel).checked,
        symbols: $('[name=gen_symbols]', panel).checked,
        exclude_ambiguous: $('[name=gen_ambiguous]', panel).checked
      };
    }

    var lengthInput = $('[name=gen_length]', panel);
    var lengthOut = $('[data-generator-length]', panel);
    if (lengthInput && lengthOut) {
      lengthInput.addEventListener('input', function () { lengthOut.textContent = lengthInput.value; });
    }

    $('[data-generate]', panel).addEventListener('click', function (e) {
      e.preventDefault();
      App.api(App.api_.utilidades + '?accion=generar', { method: 'POST', body: options() }).then(function (data) {
        if (target) {
          target.value = data.password;
          target.type = 'text';
          target.dispatchEvent(new Event('input', { bubbles: true }));
        }
        var out = $('[data-generator-output]', panel);
        if (out) { out.textContent = data.label + ' (' + data.strength + '/100)'; }
      }).catch(function (err) { App.toast(err.message, 'error'); });
    });
  }

  function initStrength() {
    $$('[data-strength-for]').forEach(function (meter) {
      var input = document.getElementById(meter.dataset.strengthFor);
      if (!input) { return; }
      var bar = $('span', meter);
      var label = $('[data-strength-label]', meter.parentNode) || null;
      var timeout = null;
      input.addEventListener('input', function () {
        clearTimeout(timeout);
        var value = input.value;
        if (!value) { bar.style.width = '0%'; if (label) { label.textContent = ''; } return; }
        timeout = setTimeout(function () {
          App.api(App.api_.utilidades + '?accion=fortaleza', { method: 'POST', body: { password: value } }).then(function (data) {
            bar.style.width = data.strength + '%';
            meter.className = 'bar ' + (data.strength >= 70 ? 'ok' : (data.strength >= 40 ? 'warn' : 'danger'));
            if (label) { label.textContent = data.label; }
          }).catch(function () {});
        }, 320);
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initGenerator();
    initStrength();
  });
})();
