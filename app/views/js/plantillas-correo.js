/* =====================================================================
   Editor de plantillas de correo.

   Sustituye las variables por datos de ejemplo y dibuja el resultado
   segun se escribe. El reemplazo repite la regla del servidor
   (plantillaCorreoModel::sustituir): mismo patron {{variable}}, mismo
   escapado, y las variables "crudas" —las que compone el sistema— sin
   escapar. Si las dos reglas divergieran, la vista previa mentiria.

   El HTML se dibuja dentro de un iframe con sandbox vacio: lo que se
   pega en el editor no puede ejecutar nada en el panel.
   ===================================================================== */
(function () {
  'use strict';

  var App = window.App;
  var $ = App.$;

  function init() {
    var form = $('[data-plantilla]');
    if (!form) { return; }

    var ejemplo = leerJson(form.dataset.ejemplo, {});
    var crudas  = leerJson(form.dataset.crudas, []);

    var marco   = $('[data-vista-previa]', form);
    var asunto  = $('[data-vista-asunto]', form);
    var campos  = {
      subject:   $('[data-campo=subject]', form),
      body_html: $('[data-campo=body_html]', form),
      body_text: $('[data-campo=body_text]', form)
    };

    function leerJson(texto, porDefecto) {
      try { return JSON.parse(texto || ''); } catch (e) { return porDefecto; }
    }

    function escapar(valor) {
      return String(valor)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function sustituir(plantilla, enHtml) {
      return String(plantilla).replace(/\{\{\s*([a-z_]{1,40})\s*\}\}/gi, function (_, nombre) {
        var clave = nombre.toLowerCase();
        var valor = Object.prototype.hasOwnProperty.call(ejemplo, clave) ? ejemplo[clave] : '';
        if (!enHtml || crudas.indexOf(clave) !== -1) { return valor; }
        return escapar(valor);
      });
    }

    function pintar() {
      if (asunto && campos.subject) {
        asunto.textContent = sustituir(campos.subject.value, false);
      }
      if (!marco) { return; }

      var html = campos.body_html ? campos.body_html.value.trim() : '';
      if (html !== '') {
        marco.srcdoc = sustituir(html, true);
        return;
      }

      // Sin HTML sale solo el texto plano: se muestra tal cual lo veria
      // quien reciba el mensaje, con los saltos de linea respetados.
      var texto = campos.body_text ? sustituir(campos.body_text.value, false) : '';
      marco.srcdoc = '<pre style="margin:0;padding:24px;white-space:pre-wrap;word-break:break-word;'
                   + 'font:14px/1.6 ui-monospace,Menlo,Consolas,monospace;color:#101828">'
                   + escapar(texto) + '</pre>';
    }

    Object.keys(campos).forEach(function (clave) {
      if (campos[clave]) { campos[clave].addEventListener('input', pintar); }
    });

    // Insertar una variable donde este el cursor del ultimo campo tocado.
    var ultimo = campos.body_html || campos.body_text;
    Object.keys(campos).forEach(function (clave) {
      if (campos[clave]) {
        campos[clave].addEventListener('focus', function () { ultimo = campos[clave]; });
      }
    });

    form.addEventListener('click', function (e) {
      var boton = e.target.closest('[data-variable]');
      if (!boton || !ultimo) { return; }
      e.preventDefault();
      insertar(ultimo, '{{' + boton.dataset.variable + '}}');
      pintar();
    });

    function insertar(campo, texto) {
      var inicio = campo.selectionStart;
      var fin    = campo.selectionEnd;
      if (typeof inicio !== 'number') {
        campo.value += texto;
      } else {
        campo.value = campo.value.slice(0, inicio) + texto + campo.value.slice(fin);
        campo.selectionStart = campo.selectionEnd = inicio + texto.length;
      }
      campo.focus();
    }

    pintar();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
