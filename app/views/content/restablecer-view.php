<?php
/** Restablecimiento de la contrasena con un enlace de un solo uso. */

use app\middlewares\accesoMiddleware;
use app\models\autenticacionModel;

$pageTitle = 'Restablecer contrasena';
// Las reglas vienen de la politica vigente, igual que en el cambio desde
// el perfil: las dos pantallas no pueden anunciar cosas distintas.
$politica = autenticacionModel::politicaContrasena();
$csrf  = accesoMiddleware::csrfInvitado();
$token = (string) ($parametrosVista[0] ?? '');
?>
<div class="card">
  <div class="card__body">
    <h1 style="margin-bottom:.15rem">Nueva contrasena</h1>
    <p class="text-muted text-small">
      <?= e(autenticacionModel::descripcionPolitica($politica)) ?>
    </p>

    <form method="post" action="<?= e(url('/restablecer')) ?>" class="stack">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="token" value="<?= e($token) ?>">

      <div class="field">
        <label for="password">Nueva contrasena</label>
        <input type="password" id="password" name="password" required autocomplete="new-password"
               minlength="<?= (int) $politica['min'] ?>" maxlength="<?= (int) $politica['max'] ?>">
        <div class="bar" data-strength-for="password"><span style="width:0"></span></div>
        <span class="hint" data-strength-label></span>
      </div>

      <div class="field">
        <label for="password_confirmation">Confirmar contrasena</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required
               autocomplete="new-password" maxlength="<?= (int) $politica['max'] ?>">
      </div>

      <button class="btn btn--primary btn--block" type="submit">Guardar contrasena</button>
    </form>
  </div>
</div>
