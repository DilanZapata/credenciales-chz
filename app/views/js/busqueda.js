/* =====================================================================
   Busqueda global de la barra superior.

   Solo devuelve informacion de la credencial, nunca su contrasena, y
   respeta el alcance del usuario: el servidor filtra, no el navegador.
   ===================================================================== */
(function () {
  'use strict';

  var App = window.App;
  var $ = App.$;

  document.addEventListener('DOMContentLoaded', function () {
    var input = $('#global-search');
    if (!input) { return; }
    var results = $('#global-search-results');
    var timeout = null;

    input.addEventListener('input', function () {
      clearTimeout(timeout);
      var term = input.value.trim();
      if (term.length < 2) { results.hidden = true; results.innerHTML = ''; return; }
      timeout = setTimeout(function () {
        App.api('/api/v1/buscar?q=' + encodeURIComponent(term)).then(function (data) {
          results.innerHTML = '';
          var total = 0;
          (data.credentials || []).forEach(function (item) {
            total++;
            var a = document.createElement('a');
            a.href = App.base + '/credenciales/' + item.id;
            a.textContent = item.name;
            var small = document.createElement('small');
            small.textContent = (item.system || '') + (item.username ? ' · ' + item.username : '');
            a.appendChild(small);
            results.appendChild(a);
          });
          (data.systems || []).forEach(function (item) {
            total++;
            var a = document.createElement('a');
            a.href = App.base + '/sistemas/' + item.id;
            a.textContent = item.name;
            var small = document.createElement('small');
            small.textContent = 'Sistema';
            a.appendChild(small);
            results.appendChild(a);
          });
          if (total === 0) {
            var none = document.createElement('a');
            none.textContent = 'Sin resultados';
            none.href = '#';
            results.appendChild(none);
          }
          results.hidden = false;
        }).catch(function () { results.hidden = true; });
      }, 250);
    });

    document.addEventListener('click', function (e) {
      if (!input.contains(e.target) && !results.contains(e.target)) { results.hidden = true; }
    });
  });
})();
