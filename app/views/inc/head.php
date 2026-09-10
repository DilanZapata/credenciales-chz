<?php
/**
 * Cabecera comun de todas las vistas (patron de Porcify Manager).
 *
 * Recibe de index.php:
 *   $cssFilesShare  hojas compartidas
 *   $cssFiles       hojas de la vista
 *   $pageTitle      titulo de la pagina
 *
 * @var array<int,string> $cssFilesShare
 * @var array<int,string> $cssFiles
 */
$nombreCorto = \App\Core\Config::get('app.short_name');
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="referrer" content="no-referrer">

<title><?= e(($pageTitle ?? 'Panel') . ' · ' . $nombreCorto) ?></title>

<?php foreach (($cssFilesShare ?? []) as $css): ?>
    <!-- Inicio estilos compartidos -->
    <link rel="stylesheet" href="<?= e(assetVersionado('css/' . $css)) ?>">
    <!-- Fin de estilos compartidos -->
<?php endforeach; ?>

<?php foreach (($cssFiles ?? []) as $css): ?>
    <!-- Inicio estilos de la vista -->
    <link rel="stylesheet" href="<?= e(assetVersionado('css/' . $css)) ?>">
    <!-- Fin de estilos de la vista -->
<?php endforeach; ?>

<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='13' font-size='13'>&#128274;</text></svg>">
