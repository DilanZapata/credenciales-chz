<?php
/**
 * Pagina de error autonoma.
 *
 * La usa el despacho de formularios y la entrega de archivos, que
 * rechazan la peticion antes de tener el marco de la aplicacion montado.
 *
 * @var int    $errorEstado
 * @var string $errorTitulo
 * @var string $errorMensaje
 */
$nonce = $cspNonce ?? '';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($errorTitulo . ' · ' . \App\Core\Config::get('app.short_name')) ?></title>
<link rel="stylesheet" href="<?= e(assetVersionado('css/global.css')) ?>">
<link rel="stylesheet" href="<?= e(assetVersionado('css/login.css')) ?>">
</head>
<body>
<main class="auth-main" style="min-height:100vh">
  <div class="auth-card">
    <div class="card">
      <div class="card__body" style="text-align:center;padding:2.5rem 1.5rem">
        <div style="font-size:2.6rem;font-weight:700;color:var(--text-3);line-height:1"><?= e((string) $errorEstado) ?></div>
        <h1 style="margin-top:.6rem"><?= e($errorTitulo) ?></h1>
        <p class="text-muted"><?= e($errorMensaje) ?></p>
        <div class="row" style="justify-content:center;margin-top:1rem">
          <a class="btn btn--primary" href="<?= e(url('/')) ?>">Volver al inicio</a>
          <a class="btn" href="<?= e(url('/entrar')) ?>">Iniciar sesion</a>
        </div>
      </div>
    </div>
  </div>
</main>
</body>
</html>
