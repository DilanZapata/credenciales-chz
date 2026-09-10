<?php
/**
 * Guiones comunes y de la vista (patron de Porcify Manager).
 *
 * El orden importa: global.js publica window.App y el resto lo consume.
 * La referencia carga su global.js al final; aqui va primero por esa
 * dependencia.
 *
 * @var array<int,string> $jsFilesShare
 * @var array<int,string> $jsFiles
 * @var bool   $autenticado
 * @var string $csrf
 */
$nonce = $cspNonce ?? '';
?>
<script nonce="<?= e($nonce) ?>">
  window.SCGCA = {
    basePath: <?= json_encode(\App\Core\Config::get('app.base_path', ''), JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode($csrf ?? '') ?>,
    authenticated: <?= ($autenticado ?? false) ? 'true' : 'false' ?>
  };
</script>

<?php foreach (($jsFilesShare ?? []) as $js): ?>
    <!-- Inicio guiones compartidos -->
    <script nonce="<?= e($nonce) ?>" src="<?= e(assetVersionado('js/' . $js)) ?>" defer></script>
    <!-- Fin de guiones compartidos -->
<?php endforeach; ?>

<?php foreach (($jsFiles ?? []) as $js): ?>
    <!-- Inicio guiones de la vista -->
    <script nonce="<?= e($nonce) ?>" src="<?= e(assetVersionado('js/' . $js)) ?>" defer></script>
    <!-- Fin de guiones de la vista -->
<?php endforeach; ?>
