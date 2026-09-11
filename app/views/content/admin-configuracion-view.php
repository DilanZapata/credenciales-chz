<?php
/** Politicas de seguridad y parametros del sistema. */

use app\controllers\catalogoController;

$pageTitle = 'Configuracion y politicas de seguridad';
$grouped = respuestaVista(catalogoController::configuracionController())['groups'];

/**
 * Los campos van dentro de ajustes[...] por una razon concreta: PHP
 * convierte los puntos en guiones bajos en los nombres de primer nivel,
 * asi que un campo llamado "security.password_min_length" llegaba al
 * servidor como "security_password_min_length" y no coincidia con ninguna
 * clave. El formulario decia "guardado" y no guardaba nada. Dentro de un
 * arreglo, la clave se conserva intacta.
 */
$campo = static fn (string $clave): string => 'ajustes[' . $clave . ']';
?>
<div class="page-head">
  <div class="page-head__text">
    <h1>Configuracion y politicas de seguridad</h1>
    <p>Los cambios en esta pantalla exigen reautenticacion y quedan registrados como evento critico.</p>
  </div>
</div>

<form method="post" action="<?= e(url('/admin/configuracion')) ?>">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

  <?php foreach ($grouped as $group => $rows): ?>
  <div class="card">
    <div class="card__head"><h2 style="text-transform:capitalize"><?= e($group) ?></h2></div>
    <div class="card__body">
      <div class="form-grid">
        <?php foreach ($rows as $row): ?>
          <?php $key = (string) $row['setting_key']; $type = (string) $row['value_type']; ?>
          <div class="field <?= $type === 'bool' ? 'full' : '' ?>">
            <?php if ($type === 'bool'): ?>
              <label class="check">
                <input type="checkbox" name="<?= e($campo($key)) ?>" value="1"
                       <?= in_array(strtolower((string) $row['setting_value']), ['1', 'true', 'on'], true) ? 'checked' : '' ?>>
                <span><strong><?= e($row['label'] ?: $key) ?></strong>
                  <div class="hint"><?= e($row['description'] ?: '') ?></div>
                  <div class="hint mono"><?= e($key) ?></div>
                </span>
              </label>
            <?php else: ?>
              <label for="s_<?= e($key) ?>"><?= e($row['label'] ?: $key) ?></label>
              <input type="<?= $type === 'int' ? 'number' : 'text' ?>" id="s_<?= e($key) ?>" name="<?= e($campo($key)) ?>"
                     value="<?= e((string) $row['setting_value']) ?>" <?= $type === 'int' ? 'min="0"' : 'maxlength="500"' ?>>
              <span class="hint"><?= e($row['description'] ?: '') ?></span>
              <span class="hint mono"><?= e($key) ?></span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="card">
    <div class="card__foot row row--end">
      <button class="btn btn--primary" type="submit">Guardar configuracion</button>
    </div>
  </div>
</form>
