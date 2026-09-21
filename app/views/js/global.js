/* =====================================================================
   Sistema Corporativo de Gestion de Credenciales - nucleo del cliente

   Se carga en todas las vistas y publica en window.App lo que el resto
   de los archivos necesita: peticiones al API, avisos, reautenticacion
   y los comportamientos declarativos comunes (modales, pestanas, menu).

   Sin dependencias externas y compatible con la CSP estricta: no hay
   codigo en linea ni manejadores onclick en el HTML.
   ===================================================================== */
(function () {
  'use strict';

  var CFG  = window.SCGCA || {};
  var BASE = CFG.basePath || '';
  var CSRF = CFG.csrf || '';

  // Endpoints del sistema (un archivo por modulo, como en la referencia).
  var API = {
    login:       '/app/api/login-api.php',
    secretos:    '/app/api/secretos-api.php',
    utilidades:  '/app/api/utilidades-api.php',
    credenciales:'/app/api/credenciales-api.php',
    reportes:    '/app/api/reportes-api.php'
  };

  // ---------------------------------------------------------------
  //  Utilidades
  // ---------------------------------------------------------------
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

  function api(path, options) {
    options = options || {};
    var headers = { 'Accept': 'application/json', 'X-CSRF-Token': CSRF };
    if (options.body !== undefined) { headers['Content-Type'] = 'application/json'; }
    return fetch(BASE + path, {
      method: options.method || 'GET',
      headers: headers,
      credentials: 'same-origin',
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) {
          // El fallo de CSRF llega como 403 con el marcador "csrf": el 419
          // no es estandar y Apache lo convierte en 500.
          var mensaje = data.csrf
            ? 'La sesion del formulario expiro. Recargue la pagina.'
            : (data.message || data.error || 'Error en la solicitud.');
          var err = new Error(mensaje);
          err.status = res.status;
          err.csrf = !!data.csrf;
          err.reauth = !!data.reauth_required;
          err.data = data;
          throw err;
        }
        // Los endpoints responden {code,status,title,message,data}; a quien
        // llama solo le interesan los datos.
        return (data && typeof data === 'object' && 'status' in data && 'data' in data)
          ? data.data
          : data;
      });
    });
  }

  function toast(message, kind) {
    var box = $('#toasts');
    if (!box) { return; }
    var el = document.createElement('div');
    el.className = 'alert alert--' + (kind || 'info');
    el.setAttribute('role', 'status');
    el.textContent = message;
    box.appendChild(el);
    setTimeout(function () { el.remove(); }, 6000);
  }

  // ---------------------------------------------------------------
  //  Reautenticacion (step-up) antes de operaciones sensibles
  // ---------------------------------------------------------------
  function requireReauth(message) {
    return new Promise(function (resolve, reject) {
      var modal = $('#reauth-modal');
      if (!modal) { reject(new Error('No disponible')); return; }
      var msgEl = $('#reauth-message', modal);
      if (msgEl && message) { msgEl.textContent = message; }
      var passInput = $('#reauth-password', modal);
      var codeInput = $('#reauth-code', modal);
      var errEl = $('#reauth-error', modal);
      errEl.textContent = '';
      passInput.value = '';
      if (codeInput) { codeInput.value = ''; }
      modal.hidden = false;
      passInput.focus();

      function cleanup() {
        modal.hidden = true;
        passInput.value = '';
        if (codeInput) { codeInput.value = ''; }
        $('#reauth-confirm', modal).removeEventListener('click', onConfirm);
        $('#reauth-cancel', modal).removeEventListener('click', onCancel);
      }
      function onConfirm() {
        var btn = $('#reauth-confirm', modal);
        btn.classList.add('is-busy');
        api(API.login + '?accion=reauth', {
          method: 'POST',
          body: { password: passInput.value, code: codeInput ? codeInput.value : '' }
        }).then(function () {
          btn.classList.remove('is-busy');
          cleanup();
          resolve(true);
        }).catch(function (err) {
          btn.classList.remove('is-busy');
          errEl.textContent = err.message || 'No fue posible confirmar su identidad.';
          passInput.select();
        });
      }
      function onCancel() { cleanup(); reject(new Error('cancelado')); }

      $('#reauth-confirm', modal).addEventListener('click', onConfirm);
      $('#reauth-cancel', modal).addEventListener('click', onCancel);
    });
  }

  /** Ejecuta una llamada y, si el backend exige step-up, lo resuelve y reintenta. */
  function withReauth(fn, message) {
    return fn().catch(function (err) {
      if (err.status === 423 || err.reauth) {
        return requireReauth(message).then(fn);
      }
      throw err;
    });
  }

  // ---------------------------------------------------------------
  //  Comportamientos declarativos comunes
  // ---------------------------------------------------------------
  document.addEventListener('click', function (event) {
    // Apertura y cierre de modales declarativos
    var opener = event.target.closest('[data-modal-open]');
    if (opener) {
      event.preventDefault();
      var m = document.getElementById(opener.dataset.modalOpen);
      if (m) {
        m.hidden = false;
        var first = m.querySelector('input,select,textarea');
        if (first) { first.focus(); }
      }
      return;
    }
    var closer = event.target.closest('[data-modal-close]');
    if (closer) {
      event.preventDefault();
      var mc = closer.closest('.modal-backdrop');
      if (mc) { mc.hidden = true; }
      return;
    }

    // Menu lateral en movil
    if (event.target.closest('[data-menu-toggle]')) {
      event.preventDefault();
      var sb = $('.sidebar');
      if (sb) { sb.classList.toggle('is-open'); }
      return;
    }

    // Pestanas locales
    var tab = event.target.closest('[data-tab]');
    if (tab) {
      event.preventDefault();
      var group = tab.closest('[data-tabs]');
      $$('[data-tab]', group).forEach(function (t) { t.classList.remove('is-active'); });
      tab.classList.add('is-active');
      $$('[data-tab-panel]', group.parentNode).forEach(function (panel) {
        panel.hidden = panel.dataset.tabPanel !== tab.dataset.tab;
      });
      return;
    }

    // Mostrar/ocultar campos de contrasena en formularios
    var btn = event.target.closest('[data-toggle-field]');
    if (btn) {
      event.preventDefault();
      var input = document.getElementById(btn.dataset.toggleField);
      if (!input) { return; }
      input.type = input.type === 'password' ? 'text' : 'password';
      btn.textContent = input.type === 'password' ? 'Mostrar' : 'Ocultar';
    }
  });

  // Confirmacion antes de acciones destructivas
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.dataset.confirm;
    if (message && !window.confirm(message)) {
      event.preventDefault();
      return;
    }
    var submit = form.querySelector('[type=submit]');
    if (submit && !form.dataset.noBusy) { submit.classList.add('is-busy'); }
  });

  // ---------------------------------------------------------------
  //  Aviso de expiracion de sesion
  // ---------------------------------------------------------------
  function initSessionWatch() {
    if (!CFG.authenticated) { return; }
    var warned = false;
    setInterval(function () {
      api(API.login + '?accion=estado').then(function (data) {
        if (!data.authenticated) { window.location.href = BASE + '/entrar'; return; }
        if (data.expires_in_seconds < 120 && !warned) {
          warned = true;
          toast('Su sesion se cerrara por inactividad en menos de 2 minutos.', 'warn');
        }
        if (data.expires_in_seconds > 180) { warned = false; }
      }).catch(function (err) {
        if (err.status === 401) { window.location.href = BASE + '/entrar'; }
      });
    }, 60000);
  }

  /**
   * Confirmacion de acciones destructivas.
   *
   * El atributo data-confirm ya se usaba en ocho pantallas (archivar un
   * sistema, revocar accesos, cerrar sesiones ajenas, restablecer
   * contrasenas) pero no habia nada que lo leyera: esos botones actuaban
   * sin preguntar.
   */
  function initConfirm() {
    document.addEventListener('submit', function (e) {
      var origen = e.target.closest('[data-confirm]');
      if (!origen) { return; }
      if (!window.confirm(origen.dataset.confirm)) { e.preventDefault(); }
    });

  }

  document.addEventListener('DOMContentLoaded', initConfirm);
  document.addEventListener('DOMContentLoaded', initSessionWatch);

  // Lo que consumen las hojas de cada vista.
  window.App = {
    base: BASE,
    api_: API,
    $: $, $$: $$,
    api: api,
    toast: toast,
    requireReauth: requireReauth,
    withReauth: withReauth
  };
})();
