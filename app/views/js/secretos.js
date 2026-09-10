/* =====================================================================
   Revelado y copia de contrasenas.

   Solo se carga en las vistas que muestran secretos. Cada revelado y
   cada copia pasan por el API, que los registra en la auditoria: el
   valor NUNCA llega en el HTML de la pagina.
   ===================================================================== */
(function () {
  'use strict';

  var App = window.App;
  var $ = App.$, $$ = App.$$;
  var timers = {};

  function hideSecret(box) {
    var value = $('.secret__value', box);
    var timer = $('.secret__timer', box);
    value.textContent = '••••••••••••';
    value.classList.add('is-hidden');
    if (timer) { timer.textContent = ''; }
    var toggle = $('[data-secret-toggle]', box);
    if (toggle) { toggle.textContent = 'Mostrar'; }
    box.dataset.revealed = '0';
    if (timers[box.dataset.credential]) {
      clearInterval(timers[box.dataset.credential]);
      delete timers[box.dataset.credential];
    }
  }

  function showSecret(box, secret, ttl) {
    var value = $('.secret__value', box);
    var timer = $('.secret__timer', box);
    value.textContent = secret;
    value.classList.remove('is-hidden');
    box.dataset.revealed = '1';
    var toggle = $('[data-secret-toggle]', box);
    if (toggle) { toggle.textContent = 'Ocultar'; }

    var left = ttl || 30;
    if (timer) { timer.textContent = 'se oculta en ' + left + 's'; }
    var key = box.dataset.credential;
    if (timers[key]) { clearInterval(timers[key]); }
    timers[key] = setInterval(function () {
      left -= 1;
      if (left <= 0) { hideSecret(box); return; }
      if (timer) { timer.textContent = 'se oculta en ' + left + 's'; }
    }, 1000);
  }

  function fetchSecret(box, copy) {
    var id = box.dataset.credential;
    var field = box.dataset.field || 'password';
    var version = box.dataset.version || '';
    var path = version
      ? App.api_.secretos + '?accion=historico&id=' + id + '&version=' + version
      : App.api_.secretos + '?accion=revelar&id=' + id;
    return App.withReauth(function () {
      return App.api(path, { method: 'POST', body: { field: field, copy: !!copy } });
    }, 'Confirme su contrasena para revelar este secreto.');
  }

  function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    return new Promise(function (resolve, reject) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); resolve(); }
      catch (e) { reject(e); }
      finally { ta.remove(); }
    });
  }

  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-secret-toggle]');
    if (toggle) {
      event.preventDefault();
      var box = toggle.closest('[data-credential]');
      if (box.dataset.revealed === '1') { hideSecret(box); return; }
      toggle.classList.add('is-busy');
      fetchSecret(box, false).then(function (data) {
        toggle.classList.remove('is-busy');
        showSecret(box, data.secret, data.ttl);
      }).catch(function (err) {
        toggle.classList.remove('is-busy');
        if (err.message !== 'cancelado') { App.toast(err.message || 'No fue posible mostrar la contrasena.', 'error'); }
      });
      return;
    }

    var copyBtn = event.target.closest('[data-secret-copy]');
    if (copyBtn) {
      event.preventDefault();
      var cbox = copyBtn.closest('[data-credential]');
      copyBtn.classList.add('is-busy');
      fetchSecret(cbox, true).then(function (data) {
        return copyToClipboard(data.secret).then(function () {
          copyBtn.classList.remove('is-busy');
          App.toast('Contrasena copiada. El acceso quedo registrado en la auditoria.', 'ok');
          // El portapapeles se limpia pasados 45 segundos.
          setTimeout(function () {
            copyToClipboard(' ').catch(function () {});
          }, 45000);
        });
      }).catch(function (err) {
        copyBtn.classList.remove('is-busy');
        if (err.message !== 'cancelado') { App.toast(err.message || 'No fue posible copiar la contrasena.', 'error'); }
      });
      return;
    }

    // Informacion de recuperacion
    var recBtn = event.target.closest('[data-recovery]');
    if (recBtn) {
      event.preventDefault();
      var rid = recBtn.dataset.recovery;
      App.withReauth(function () {
        return App.api(App.api_.secretos + '?accion=recuperacion&id=' + rid);
      },
        'Confirme su contrasena para ver la informacion de recuperacion.')
        .then(function (data) {
          var target = $('#recovery-info');
          if (!target) { return; }
          target.hidden = false;
          target.innerHTML = '';
          var dl = document.createElement('dl');
          dl.className = 'dl';
          [['Correo de recuperacion', data.recovery_email],
           ['Telefono de recuperacion', data.recovery_phone],
           ['Usuario de recuperacion', data.recovery_username],
           ['Informacion adicional', data.recovery_notes]].forEach(function (pair) {
            var dt = document.createElement('dt'); dt.textContent = pair[0];
            var dd = document.createElement('dd'); dd.textContent = pair[1] || '—';
            dl.appendChild(dt); dl.appendChild(dd);
          });
          target.appendChild(dl);
          recBtn.remove();
        })
        .catch(function (err) {
          if (err.message !== 'cancelado') { App.toast(err.message || 'No autorizado.', 'error'); }
        });
    }
  });

  // Cierra secretos visibles si la pestana pierde el foco.
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      $$('[data-credential]').forEach(function (box) {
        if (box.dataset.revealed === '1') { hideSecret(box); }
      });
    }
  });
})();
