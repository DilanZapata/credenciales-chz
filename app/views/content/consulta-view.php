<?php
/**
 * Consulta rapida de accesos.
 *
 * Pantalla publica y deliberadamente escueta: el empleado se identifica y
 * ve en que sistemas tiene cuenta. Nada mas.
 *
 * El resultado NO se guarda en ningun sitio: viaja en un mensaje de un solo
 * uso desde el envio del formulario, de modo que recargar la pagina vuelve
 * a pedir la identificacion. Es lo correcto para una pantalla que puede
 * quedarse abierta en un computador compartido.
 */

use App\Core\Flash;
use app\controllers\consultaController;
use app\models\consultaModel;

$pageTitle = 'Consultar mis accesos';

if (!consultaModel::habilitada()) {
    // No se dice "esta desactivada": para quien no deberia conocerla, la
    // direccion simplemente no existe.
    throw new \App\Core\HttpException(404, 'La direccion solicitada no existe.');
}

$csrf          = \app\middlewares\accesoMiddleware::csrfInvitado();
$exigeClave    = consultaModel::exigeContrasena();
$puedeVerClave = consultaModel::muestraSecretos();

// A quien se identifico, si la consulta sigue vigente.
$idConsultado = consultaModel::ticket();
$datos        = null;
$revelado     = Flash::get('consulta_revelado');

if ($idConsultado !== null) {
    $respuesta = consultaController::accesosController($idConsultado);
    $datos = ($respuesta['status'] ?? '') === 'success' ? $respuesta['data'] : null;

    // Se renueva mientras la persona siga usando la pantalla; al dejarla
    // quieta vence sola.
    if ($datos !== null) {
        consultaModel::emitirTicket($idConsultado);
    } else {
        consultaModel::cerrarTicket();
    }
}
?>

<?php if ($datos === null): ?>

  <div class="card">
    <div class="card__body">
      <h1 style="margin-bottom:.15rem">Consultar mis accesos</h1>
      <p class="text-muted text-small">
        <?= $exigeClave
            ? 'Identifiquese para ver en que sistemas tiene cuenta.'
            : 'Indique su cedula, usuario o correo para ver en que sistemas tiene cuenta.' ?>
      </p>

      <form method="post" action="<?= e(url('/consulta')) ?>" class="stack">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="field">
          <label for="identifier">Cedula, usuario o correo</label>
          <input type="text" id="identifier" name="identifier" required autofocus
                 autocomplete="username" maxlength="190">
        </div>

        <?php if ($exigeClave): ?>
        <div class="field">
          <label for="password">Contrasena</label>
          <input type="password" id="password" name="password" required
                 autocomplete="current-password" maxlength="200">
        </div>
        <?php endif; ?>

        <button class="btn btn--primary btn--block" type="submit">Consultar</button>
      </form>

      <p class="text-small text-muted" style="text-align:center;margin-top:1rem">
        <a href="<?= e(url('/entrar')) ?>">Entrar al sistema completo</a>
      </p>
    </div>
  </div>

<?php else: ?>

  <?php $items = $datos['items']; ?>

  <div class="card">
    <div class="card__head">
      <div>
        <h1 style="margin:0;font-size:1.15rem">
          Accesos de <?= e($datos['user']['first_name'] . ' ' . $datos['user']['last_name']) ?>
        </h1>
        <span class="text-small text-muted"><?= count($items) ?> acceso(s) vigentes</span>
      </div>
      <div class="spacer"></div>
      <form method="post" action="<?= e(url('/consulta/salir')) ?>" data-no-busy="1">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn--sm" type="submit">Salir</button>
      </form>
    </div>

    <?php if ($revelado !== null && is_array($revelado)): ?>
    <div class="card__body" style="padding-bottom:0">
      <div class="alert alert--warn">
        <div style="width:100%">
          <strong><?= e((string) $revelado['credential']) ?></strong>
          <?php if (($revelado['system'] ?? '') !== ''): ?>
            <span class="text-small text-muted">· <?= e((string) $revelado['system']) ?></span>
          <?php endif; ?>
          <div class="secret mt-1">
            <span class="secret__value mono"><?= e((string) $revelado['secret']) ?></span>
          </div>
          <div class="hint mt-1">
            Esta consulta quedo registrada. Cierre esta pagina cuando termine.
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($items === []): ?>
      <div class="empty">
        <strong>Sin accesos asignados</strong>
        No tiene ninguna credencial asignada en este momento.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr>
            <th>Sistema</th><th>Credencial</th><th>Usuario</th>
            <?php if ($puedeVerClave): ?><th class="nowrap"></th><?php endif; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($items as $fila): ?>
            <tr>
              <td>
                <?= e((string) $fila['system_name']) ?>
                <?php if (!empty($fila['category_name'])): ?>
                  <div class="text-small text-muted"><?= e((string) $fila['category_name']) ?></div>
                <?php endif; ?>
              </td>
              <td><?= e((string) $fila['credential_name']) ?></td>
              <td class="mono"><?= e((string) ($fila['username'] ?? '—')) ?></td>
              <?php if ($puedeVerClave): ?>
              <td class="nowrap">
                <?php if ((int) $fila['can_view_secret'] === 1): ?>
                  <form method="post" action="<?= e(url('/consulta/revelar')) ?>" data-no-busy="1">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="credential_id" value="<?= (int) $fila['credential_id'] ?>">
                    <button class="btn btn--sm" type="submit">Ver contrasena</button>
                  </form>
                <?php else: ?>
                  <span class="badge muted">Sin permiso</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="card__foot">
      <span class="text-small text-muted">
        Esta consulta quedo registrada en la auditoria y caduca sola a los
        <?= (int) consultaModel::minutos() ?> minutos. Pulse "Salir" al terminar.
      </span>
    </div>
  </div>

<?php endif; ?>
