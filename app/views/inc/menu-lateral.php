<?php
/**
 * Menu lateral de la aplicacion.
 *
 * Cada entrada se dibuja solo si el usuario tiene el permiso que la
 * respalda. Ocultarla NO es el control de acceso: la vista y el modelo
 * vuelven a comprobarlo (defensa en profundidad).
 *
 * @var string $currentPath
 */
$nombreCorto = \App\Core\Config::get('app.short_name');
$ruta        = $currentPath ?? '/';
$esAdmin     = puede('credentials.view_all');
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar__brand">
    <strong><?= e($nombreCorto) ?></strong>
    <span>Gestion de accesos</span>
  </div>

  <nav class="sidebar__nav">
    <div class="sidebar__group">
      <?php if ($esAdmin): ?>
        <a class="nav-item <?= active('/', $ruta) ?>" href="<?= e(url('/')) ?>"><span class="ico">▦</span> Panel</a>
      <?php endif; ?>
      <a class="nav-item <?= active('/mis-accesos', $ruta) ?>" href="<?= e(url('/mis-accesos')) ?>"><span class="ico">★</span> Mis accesos</a>
    </div>

    <?php if (puedeAlguno('credentials.view', 'systems.view')): ?>
    <div class="sidebar__group">
      <div class="sidebar__label">Inventario</div>
      <?php if (puede('credentials.view')): ?>
        <a class="nav-item <?= active('/credenciales', $ruta) ?>" href="<?= e(url('/credenciales')) ?>"><span class="ico">🔑</span> Credenciales</a>
      <?php endif; ?>
      <?php if (puede('systems.view')): ?>
        <a class="nav-item <?= active('/sistemas', $ruta) ?>" href="<?= e(url('/sistemas')) ?>"><span class="ico">🖥</span> Sistemas</a>
      <?php endif; ?>
      <?php if (puede('import.credentials')): ?>
        <a class="nav-item <?= active('/importar', $ruta) ?>" href="<?= e(url('/importar')) ?>"><span class="ico">⇪</span> Importar</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (puedeAlguno('users.view', 'roles.view')): ?>
    <div class="sidebar__group">
      <div class="sidebar__label">Personas</div>
      <?php if (puede('users.view')): ?>
        <a class="nav-item <?= active('/usuarios', $ruta) ?>" href="<?= e(url('/usuarios')) ?>"><span class="ico">👥</span> Usuarios</a>
      <?php endif; ?>
      <?php if (puede('roles.view')): ?>
        <a class="nav-item <?= active('/admin/roles', $ruta) ?>" href="<?= e(url('/admin/roles')) ?>"><span class="ico">⚖</span> Roles y permisos</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (puedeAlguno('audit.view', 'sessions.view', 'security.events.view', 'reports.view')): ?>
    <div class="sidebar__group">
      <div class="sidebar__label">Control</div>
      <?php if (puede('audit.view')): ?>
        <a class="nav-item <?= active('/auditoria', $ruta) ?>" href="<?= e(url('/auditoria')) ?>"><span class="ico">📋</span> Auditoria</a>
      <?php endif; ?>
      <?php if (puede('security.events.view')): ?>
        <a class="nav-item <?= active('/seguridad', $ruta) ?>" href="<?= e(url('/seguridad/eventos')) ?>"><span class="ico">⚠</span> Eventos de seguridad</a>
      <?php endif; ?>
      <?php if (puede('sessions.view')): ?>
        <a class="nav-item <?= active('/sesiones', $ruta) ?>" href="<?= e(url('/sesiones')) ?>"><span class="ico">🖧</span> Sesiones</a>
      <?php endif; ?>
      <?php if (puede('reports.view')): ?>
        <a class="nav-item <?= active('/reportes', $ruta) ?>" href="<?= e(url('/reportes')) ?>"><span class="ico">📊</span> Reportes</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (puedeAlguno('categories.manage', 'org.manage', 'settings.manage')): ?>
    <div class="sidebar__group">
      <div class="sidebar__label">Administracion</div>
      <?php if (puede('categories.manage')): ?>
        <a class="nav-item <?= active('/admin/categorias', $ruta) ?>" href="<?= e(url('/admin/categorias')) ?>"><span class="ico">🏷</span> Categorias</a>
      <?php endif; ?>
      <?php if (puede('org.manage')): ?>
        <a class="nav-item <?= active('/admin/organizacion', $ruta) ?>" href="<?= e(url('/admin/organizacion')) ?>"><span class="ico">🏢</span> Organizacion</a>
      <?php endif; ?>
      <?php if (puede('settings.manage')): ?>
        <a class="nav-item <?= active('/admin/configuracion', $ruta) ?>" href="<?= e(url('/admin/configuracion')) ?>"><span class="ico">⚙</span> Configuracion</a>
        <a class="nav-item <?= active('/admin/correo', $ruta) ?>" href="<?= e(url('/admin/correo')) ?>"><span class="ico">✉</span> Correo</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </nav>

  <div class="sidebar__footer">
    <?= e(\app\models\contextoModel::nombreCompleto()) ?><br>
    <span class="text-small"><?= e(implode(', ', array_map(static fn ($r) => $r['name'], \app\models\contextoModel::roles()))) ?></span>
  </div>
</aside>
