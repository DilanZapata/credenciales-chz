<?php
/** Servidor de correo saliente (SMTP). */

use app\controllers\correoController;
use app\models\contextoModel;

$pageTitle = 'Servidor de correo saliente';
$datos = respuestaVista(correoController::verController());

$cfg         = $datos['config'];
$tieneClave  = (bool) $datos['tiene_clave'];
$cifrado     = (string) ($cfg['encryption'] ?? 'tls');
$activo      = (bool) $cfg['enabled'];
$ultimaOk    = $cfg['last_test_ok'];
?>
<div class="page-head">
  <div class="page-head__text">
    <h1>Servidor de correo saliente</h1>
    <p>Sin esto configurado el sistema no puede enviar el enlace de restablecimiento de contrasena
       ni los resumenes de alertas. Los cambios exigen reautenticacion y quedan en auditoria.</p>
  </div>
</div>

<?php if (!$activo): ?>
<div class="alert alert--warn">
  <strong>El envio de correo esta apagado.</strong>
  Mientras siga asi, quien pida recuperar su contrasena vera el mensaje de confirmacion pero no
  recibira nada. La salida de emergencia es <code>php bin/console.php user:reset &lt;usuario&gt;</code>.
</div>
<?php endif; ?>

<?php if ($cfg['last_test_at'] !== null): ?>
<div class="alert <?= $ultimaOk ? 'alert--ok' : 'alert--error' ?>">
  <strong>Ultima prueba: <?= $ultimaOk ? 'correcta' : 'fallida' ?></strong>
  (<?= e(fecha($cfg['last_test_at'], true)) ?>)
  <?php if (!$ultimaOk && $cfg['last_test_error'] !== null): ?>
    <div class="mono text-small" style="margin-top:.4rem"><?= e((string) $cfg['last_test_error']) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/correo')) ?>" autocomplete="off">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

  <div class="card">
    <div class="card__head"><h2>Conexion</h2></div>
    <div class="card__body">
      <div class="form-grid">

        <div class="field full">
          <label class="check">
            <input type="checkbox" name="enabled" value="1" <?= $activo ? 'checked' : '' ?>>
            <span><strong>Habilitar el envio de correo</strong>
              <div class="hint">Con esto apagado no sale ningun mensaje del sistema.</div>
            </span>
          </label>
        </div>

        <div class="field">
          <label for="host">Servidor SMTP</label>
          <input type="text" id="host" name="host" maxlength="255" placeholder="smtp.gmail.com"
                 value="<?= e((string) ($cfg['host'] ?? '')) ?>">
          <span class="hint">Para Gmail: <span class="mono">smtp.gmail.com</span></span>
        </div>

        <div class="field">
          <label for="port">Puerto</label>
          <input type="number" id="port" name="port" min="1" max="65535"
                 value="<?= (int) ($cfg['port'] ?? 587) ?>">
          <span class="hint">587 con STARTTLS, 465 con SSL.</span>
        </div>

        <div class="field">
          <label for="encryption">Cifrado</label>
          <select id="encryption" name="encryption">
            <option value="tls"  <?= $cifrado === 'tls'  ? 'selected' : '' ?>>STARTTLS (puerto 587)</option>
            <option value="ssl"  <?= $cifrado === 'ssl'  ? 'selected' : '' ?>>SSL/TLS implicito (puerto 465)</option>
            <option value="none" <?= $cifrado === 'none' ? 'selected' : '' ?>>Sin cifrado (solo relay interno)</option>
          </select>
          <span class="hint">Sin cifrado, la contrasena del buzon viaja en claro por la red.</span>
        </div>

        <div class="field">
          <label for="timeout">Espera maxima (segundos)</label>
          <input type="number" id="timeout" name="timeout" min="3" max="120"
                 value="<?= (int) ($cfg['timeout'] ?? 10) ?>">
          <span class="hint">Tiempo antes de dar por perdida la conexion.</span>
        </div>

        <div class="field">
          <label for="username">Usuario</label>
          <input type="text" id="username" name="username" maxlength="255" autocomplete="off"
                 placeholder="cuenta@gmail.com" value="<?= e((string) ($cfg['username'] ?? '')) ?>">
          <span class="hint">Dejelo vacio solo si el relay no pide autenticacion.</span>
        </div>

        <div class="field">
          <label for="password">Contrasena del buzon</label>
          <input type="password" id="password" name="password" autocomplete="new-password"
                 placeholder="<?= $tieneClave ? 'Guardada — escriba solo si desea cambiarla' : 'Sin definir' ?>">
          <span class="hint">
            <?php if ($tieneClave): ?>
              Hay una contrasena guardada y cifrada. Se conserva si deja el campo vacio.
            <?php else: ?>
              Se guarda cifrada. El sistema no la vuelve a mostrar nunca.
            <?php endif; ?>
          </span>
        </div>

        <?php if ($tieneClave): ?>
        <div class="field full">
          <label class="check">
            <input type="checkbox" name="password_clear" value="1">
            <span>Borrar la contrasena guardada
              <div class="hint">Marque solo si el servidor pasa a no exigir autenticacion.</div>
            </span>
          </label>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Remitente</h2></div>
    <div class="card__body">
      <div class="form-grid">

        <div class="field">
          <label for="from_email">Direccion del remitente</label>
          <input type="email" id="from_email" name="from_email" maxlength="255" required
                 value="<?= e((string) ($cfg['from_email'] ?? '')) ?>">
          <span class="hint">Gmail solo permite enviar desde la propia cuenta o un alias verificado.</span>
        </div>

        <div class="field">
          <label for="from_name">Nombre visible</label>
          <input type="text" id="from_name" name="from_name" maxlength="120"
                 placeholder="Gestion de Credenciales"
                 value="<?= e((string) ($cfg['from_name'] ?? '')) ?>">
          <span class="hint">Lo que ve el destinatario junto a la direccion.</span>
        </div>

        <div class="field">
          <label for="reply_to">Responder a</label>
          <input type="email" id="reply_to" name="reply_to" maxlength="255"
                 value="<?= e((string) ($cfg['reply_to'] ?? '')) ?>">
          <span class="hint">Opcional. Buzon que recibe las respuestas.</span>
        </div>

      </div>
    </div>
    <div class="card__foot row row--end">
      <button class="btn btn--primary" type="submit">Guardar configuracion</button>
    </div>
  </div>
</form>

<div class="card">
  <div class="card__head"><h2>Probar el envio</h2></div>
  <div class="card__body">
    <p class="muted">Guarde primero los cambios. La prueba usa la configuracion almacenada y, si el
       servidor rechaza el mensaje, muestra su respuesta literal.</p>
    <form method="post" action="<?= e(url('/admin/correo/prueba')) ?>" class="form-grid">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <div class="field">
        <label for="to">Enviar un correo de prueba a</label>
        <input type="email" id="to" name="to" maxlength="255" required
               value="<?= e((string) (contextoModel::usuario()['email'] ?? '')) ?>">
      </div>
      <div class="field" style="align-self:end">
        <button class="btn" type="submit">Enviar prueba</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card__head"><h2>Gmail: como obtener la contrasena</h2></div>
  <div class="card__body">
    <p class="muted">Google no admite la contrasena normal de la cuenta en SMTP. Hay que activar la
       verificacion en dos pasos y generar una <strong>contrasena de aplicacion</strong> de 16
       caracteres en la seguridad de la cuenta de Google; esa es la que va en el campo de arriba.
       Servidor <span class="mono">smtp.gmail.com</span>, puerto <span class="mono">587</span> con
       STARTTLS. Una cuenta gratuita tiene un limite aproximado de 500 mensajes al dia.</p>
  </div>
</div>
