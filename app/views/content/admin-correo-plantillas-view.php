<?php
/**
 * Plantillas de los correos del sistema.
 *
 * Una sola direccion hace lista y editor: el tipo viaja en ?tipo=, porque
 * los codigos del catalogo no son numericos y el normalizador de rutas
 * solo sabe extraer numeros y tokens de la direccion.
 */

use app\controllers\correoController;

$tipo = (string) ($_GET['tipo'] ?? '');

if ($tipo === '') {
    // ---------------------------- Listado ----------------------------
    $pageTitle = 'Plantillas de correo';
    $items = respuestaVista(correoController::plantillasController())['items'];
    ?>
    <div class="page-head">
      <div class="page-head__text">
        <h1>Plantillas de correo</h1>
        <p>El texto y el diseno de cada aviso que envia el sistema. Mientras una plantilla este
           desactivada se usa el contenido de fabrica, de modo que un borrador a medias nunca deja
           al sistema sin poder avisar.</p>
      </div>
      <div class="page-actions">
        <a class="btn" href="<?= e(url('/admin/correo')) ?>">Servidor de correo</a>
      </div>
    </div>

    <div class="card">
      <div class="card__body card__body--flush">
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th>Tipo de correo</th><th>Cuando se envia</th><th class="nowrap">Estado</th><th class="nowrap">Modificada</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
              <tr>
                <td>
                  <strong><?= e($item['nombre']) ?></strong>
                  <div class="muted text-small mono"><?= e($item['code']) ?></div>
                </td>
                <td class="muted text-small"><?= e($item['descripcion']) ?></td>
                <td class="nowrap">
                  <?php if ($item['enabled']): ?>
                    <span class="badge ok">Personalizada</span>
                  <?php else: ?>
                    <span class="badge muted">De fabrica</span>
                  <?php endif; ?>
                </td>
                <td class="nowrap muted text-small">
                  <?= $item['updated_at'] !== null ? e(fecha($item['updated_at'], true)) : '—' ?>
                </td>
                <td class="nowrap">
                  <a class="btn btn--sm" href="<?= e(url('/admin/correo/plantillas?tipo=' . rawurlencode((string) $item['code']))) ?>">Editar</a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php
    return;
}

// ----------------------------- Editor --------------------------------
$datos     = respuestaVista(correoController::plantillaController($tipo));
$p         = $datos['plantilla'];
$ejemplo   = $datos['ejemplo'];
$pageTitle = 'Plantilla: ' . $p['nombre'];
?>
<div class="page-head">
  <div class="page-head__text">
    <h1><?= e($p['nombre']) ?></h1>
    <p><?= e($p['descripcion']) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(url('/admin/correo/plantillas')) ?>">Volver</a>
  </div>
</div>

<?php if (!$p['enabled']): ?>
<div class="alert alert--info">
  Ahora mismo este correo sale con el <strong>contenido de fabrica</strong>. Lo que escriba aqui no se
  usara hasta que marque <em>Usar esta plantilla</em> y guarde.
</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/correo/plantillas')) ?>"
      data-plantilla
      data-ejemplo="<?= e(json_encode($ejemplo, JSON_UNESCAPED_UNICODE)) ?>"
      data-crudas="<?= e(json_encode(array_values($p['crudas']), JSON_UNESCAPED_UNICODE)) ?>">
  <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
  <input type="hidden" name="code" value="<?= e((string) $p['code']) ?>">

  <div class="card">
    <div class="card__body">
      <div class="form-grid">

        <div class="field full">
          <label class="check">
            <input type="checkbox" name="enabled" value="1" <?= $p['enabled'] ? 'checked' : '' ?>>
            <span><strong>Usar esta plantilla</strong>
              <div class="hint">Sin marcar, el sistema envia el contenido de fabrica.</div>
            </span>
          </label>
        </div>

        <div class="field full">
          <label for="subject">Asunto</label>
          <input type="text" id="subject" name="subject" maxlength="255" required
                 value="<?= e((string) $p['subject']) ?>" data-campo="subject">
          <span class="hint">Admite variables. No lleva HTML: es una cabecera del mensaje.</span>
        </div>

      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Variables disponibles</h2></div>
    <div class="card__body">
      <p class="muted text-small">Escribalas entre llaves dobles. Pulse una para insertarla donde
         tenga el cursor. Los valores se escapan solos: nadie puede colar etiquetas a traves de un
         nombre.</p>
      <div class="row" style="flex-wrap:wrap;gap:.4rem">
        <?php foreach ($p['variables'] as $clave => $descripcion): ?>
          <button type="button" class="btn btn--sm" data-variable="<?= e((string) $clave) ?>"
                  title="<?= e((string) $descripcion) ?><?= in_array($clave, $p['obligatorias'], true) ? ' (obligatoria)' : '' ?>">
            {{<?= e((string) $clave) ?>}}<?= in_array($clave, $p['obligatorias'], true) ? ' *' : '' ?>
          </button>
        <?php endforeach; ?>
      </div>
      <p class="muted text-small" style="margin-top:.6rem">* obligatoria: sin ella el mensaje no
         sirve de nada y el formulario no deja guardar.</p>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <h2>Cuerpo en HTML</h2>
    </div>
    <div class="card__body">
      <p class="muted text-small">Pegue aqui su diseno. Los clientes de correo descartan las hojas de
         estilo: use estilos en linea y tablas. Dejelo vacio para enviar solo texto plano.</p>
      <textarea id="body_html" name="body_html" rows="18" class="mono" spellcheck="false"
                data-campo="body_html"><?= e((string) $p['body_html']) ?></textarea>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Vista previa</h2></div>
    <div class="card__body">
      <p class="muted text-small">Con datos de ejemplo, segun escribe. Las imagenes externas no se
         cargan aqui por seguridad del panel; en el correo real si.</p>
      <div style="border:1px solid var(--borde,#2a3142);border-radius:8px;overflow:hidden;background:#fff">
        <iframe data-vista-previa sandbox="" title="Vista previa del correo"
                style="width:100%;height:520px;border:0;display:block"></iframe>
      </div>
      <p class="muted text-small" style="margin-top:.6rem">Asunto:
         <strong data-vista-asunto class="mono"></strong></p>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h2>Cuerpo en texto plano</h2></div>
    <div class="card__body">
      <p class="muted text-small">Lo que ven los clientes que no muestran HTML, y lo que queda si el
         diseno no carga. Es obligatorio.</p>
      <textarea id="body_text" name="body_text" rows="12" class="mono" spellcheck="false" required
                data-campo="body_text"><?= e((string) $p['body_text']) ?></textarea>
    </div>
    <div class="card__foot row row--end">
      <button class="btn btn--primary" type="submit">Guardar plantilla</button>
    </div>
  </div>
</form>

<?php if ($p['personalizada']): ?>
<div class="card">
  <div class="card__head"><h2>Volver al contenido de fabrica</h2></div>
  <div class="card__body">
    <p class="muted">Borra lo que haya escrito y deja el tipo con el texto que trae el sistema. La
       plantilla vuelve tambien a heredar las mejoras de versiones futuras.</p>
    <form method="post" action="<?= e(url('/admin/correo/plantillas')) ?>"
          data-confirm="Se perdera el contenido de esta plantilla. Confirme.">
      <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="code" value="<?= e((string) $p['code']) ?>">
      <input type="hidden" name="restaurar" value="1">
      <button class="btn btn--danger" type="submit">Restaurar</button>
    </form>
  </div>
</div>
<?php endif; ?>
