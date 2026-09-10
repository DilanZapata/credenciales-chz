<?php
/**
 * Barra superior: busqueda global, notificaciones, perfil y salida.
 *
 * @var \App\Services\AuthContext $auth
 * @var string $csrf
 */
$usuario = $auth->user() ?? [];
?>
<header class="topbar">
  <button class="btn btn--ghost menu-toggle" data-menu-toggle type="button" aria-label="Menu">☰</button>

  <?php if ($auth->canAny('credentials.view', 'credentials.view_all')): ?>
  <div class="topbar__search">
    <input type="search" id="global-search" placeholder="Buscar credencial, sistema, usuario, correo…"
           autocomplete="off" aria-label="Busqueda global">
    <div class="search-results" id="global-search-results" hidden></div>
  </div>
  <?php endif; ?>

  <div class="topbar__actions">
    <?php if ($auth->can('notifications.view')): ?>
      <a class="btn btn--ghost btn--sm" href="<?= e(url('/notificaciones')) ?>" title="Notificaciones">🔔</a>
    <?php endif; ?>
    <a class="btn btn--ghost btn--sm" href="<?= e(url('/perfil')) ?>" title="Mi perfil">
      <span class="avatar"><?= e(iniciales($usuario['first_name'] ?? '', $usuario['last_name'] ?? '')) ?></span>
    </a>
    <form method="post" action="<?= e(url('/salir')) ?>" data-no-busy="1">
      <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
      <button class="btn btn--sm" type="submit">Salir</button>
    </form>
  </div>
</header>
