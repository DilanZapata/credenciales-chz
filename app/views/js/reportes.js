/* =====================================================================
   Seleccion multiple y guarda de exportacion.

   Exportar con contrasenas produce un archivo con secretos en claro:
   exige una confirmacion explicita ademas del permiso correspondiente.
   ===================================================================== */
(function () {
  'use strict';

  var App = window.App;
  var $ = App.$, $$ = App.$$;

  function initSelection() {
    var all = $('[data-select-all]');
    if (all) {
      all.addEventListener('change', function () {
        $$('[data-select-item]').forEach(function (cb) { cb.checked = all.checked; });
        updateCount();
      });
    }
    document.addEventListener('change', function (e) {
      if (e.target.matches('[data-select-item]')) { updateCount(); }
    });
    function updateCount() {
      var count = $$('[data-select-item]:checked').length;
      $$('[data-selection-count]').forEach(function (el) { el.textContent = String(count); });
    }
    updateCount();
  }

  function initExportGuard() {
    var checkbox = $('#include_secrets');
    var warning = $('#export-warning');
    var confirmBox = $('#export-confirm');
    if (!checkbox) { return; }
    function sync() {
      if (warning) { warning.hidden = !checkbox.checked; }
      if (confirmBox) { confirmBox.required = checkbox.checked; }
    }
    checkbox.addEventListener('change', sync);
    sync();

    var form = checkbox.form;
    if (form) {
      form.addEventListener('submit', function (e) {
        if (checkbox.checked && confirmBox && !confirmBox.checked) {
          e.preventDefault();
          App.toast('Debe confirmar la advertencia para generar un archivo con contrasenas.', 'warn');
        }
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    initSelection();
    initExportGuard();
  });
})();
