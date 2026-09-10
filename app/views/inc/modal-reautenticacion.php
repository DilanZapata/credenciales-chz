<?php
/**
 * Modal de reautenticacion (step-up) para operaciones sensibles.
 *
 * Se dibuja siempre en las vistas con sesion: el cliente lo abre cuando el
 * servidor responde 423 exigiendo confirmar la identidad.
 */
$usuario = usuarioActual();
?>
<div class="modal-backdrop" id="reauth-modal" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="reauth-title">
    <div class="modal__head"><h3 id="reauth-title">Confirme su identidad</h3></div>
    <div class="modal__body stack">
      <p id="reauth-message" class="text-muted mb-0">
        Por seguridad, confirme su contrasena para continuar con esta operacion.
      </p>
      <div class="field">
        <label for="reauth-password">Contrasena</label>
        <input type="password" id="reauth-password" autocomplete="current-password">
      </div>
      <?php if (!empty($usuario['mfa_enabled'])): ?>
      <div class="field">
        <label for="reauth-code">Codigo de verificacion (opcional)</label>
        <input type="text" id="reauth-code" inputmode="numeric" autocomplete="one-time-code" maxlength="8">
      </div>
      <?php endif; ?>
      <p class="err" id="reauth-error" role="alert"></p>
    </div>
    <div class="modal__foot">
      <button type="button" class="btn" id="reauth-cancel">Cancelar</button>
      <button type="button" class="btn btn--primary" id="reauth-confirm">Confirmar</button>
    </div>
  </div>
</div>
