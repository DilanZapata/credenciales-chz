<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  Controlador frontal (patron de Porcify Manager)
 * =====================================================================
 *
 *  El .htaccess reescribe  /credenciales/12  ->  index.php?views=credenciales/12
 *
 *  Reparto de responsabilidades:
 *
 *    index.php            dibuja las paginas (GET)
 *    despachoController   atiende los envios de formulario (POST)
 *    app/api/*-api.php    atiende las operaciones por fetch (JSON)
 *
 *  Los tres frentes comparten controladores y modelos: ninguna regla de
 *  negocio vive aqui. La referencia solo tiene los dos primeros porque
 *  todas sus operaciones van por fetch; este sistema conserva ademas el
 *  envio de formulario clasico para seguir funcionando sin JavaScript.
 */

require_once __DIR__ . '/app/views/inc/session_start.php';

use App\Core\Config;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\View;
use app\controllers\despachoController;
use app\controllers\viewsController;
use app\middlewares\accesoMiddleware;
use app\models\contextoModel;

// ---------------------------------------------------------------------
//  Direccion solicitada
// ---------------------------------------------------------------------
$url = isset($_GET['views']) && $_GET['views'] !== ''
    ? explode('/', trim((string) $_GET['views'], '/'))
    : [''];

$rutaSolicitada = contextoModel::ruta();
$metodo         = contextoModel::metodo();

$vistaInfo = viewsController::obtenerVistasControlador($rutaSolicitada);

// ---------------------------------------------------------------------
//  Envios de formulario
// ---------------------------------------------------------------------
if ($metodo === 'POST') {
    despachoController::despachar($rutaSolicitada, $_POST, $_FILES);
}

// ---------------------------------------------------------------------
//  Descargas
// ---------------------------------------------------------------------
if ($metodo === 'GET' && !$vistaInfo['encontrada']) {
    require __DIR__ . '/app/descargas.php';
}

Flash::cargarDeCookie();
Flash::expirarCookie();

// ---------------------------------------------------------------------
//  Portero: sesion, segundo factor y cambio de contrasena obligatorio
// ---------------------------------------------------------------------
$destino = accesoMiddleware::revisar($rutaSolicitada, $vistaInfo['publica']);
if ($destino !== null) {
    header('Location: ' . $destino, true, 302);
    exit;
}

// ---------------------------------------------------------------------
//  Contenido de la vista
// ---------------------------------------------------------------------
// Se dibuja ANTES de emitir el marco: asi la vista puede redirigir, fijar
// su titulo o fallar sin haber escrito ya media pagina.
extract(View::sharedData(), EXTR_SKIP);

$parametrosVista = $vistaInfo['parametros'];
$vista           = $vistaInfo['ruta'];
$pageTitle       = null;
$estadoHttp      = $vistaInfo['encontrada'] ? 200 : 404;

if (!$vistaInfo['encontrada']) {
    $errorEstado  = 404;
    $errorTitulo  = 'Pagina no encontrada';
    $errorMensaje = 'La direccion solicitada no existe.';
}

ob_start();
try {
    require __DIR__ . '/' . $vista;
} catch (HttpException $e) {
    ob_end_clean();
    $estadoHttp   = $e->statusCode();
    $errorEstado  = $estadoHttp;
    $errorTitulo  = match ($estadoHttp) {
        401, 403 => 'Acceso denegado',
        404      => 'Pagina no encontrada',
        423      => 'Confirmacion requerida',
        default  => 'No fue posible continuar',
    };
    $errorMensaje = $e->getMessage();
    ob_start();
    require __DIR__ . '/app/views/content/error-view.php';
} catch (Throwable $e) {
    ob_end_clean();
    Logger::error('Fallo al dibujar la vista ' . $vistaInfo['vista'], ['error' => $e->getMessage()]);
    $estadoHttp   = 500;
    $errorEstado  = 500;
    $errorTitulo  = 'Error del sistema';
    $errorMensaje = 'Ocurrio un error inesperado. El incidente quedo registrado.';
    ob_start();
    require __DIR__ . '/app/views/content/error-view.php';
}
$contenidoVista = (string) ob_get_clean();

// Una vista de error no debe heredar los estilos y guiones de la vista
// que fallo; se resuelve de nuevo con los suyos.
if ($estadoHttp !== 200) {
    $vistaInfo['css'] = [];
    $vistaInfo['js']  = [];
}

http_response_code($estadoHttp);

$cssFiles      = $vistaInfo['css'];
$cssFilesShare = $vistaInfo['cssCompartida'];
$jsFiles       = $vistaInfo['js'];
$jsFilesShare  = $vistaInfo['jsCompartido'];
$autenticado   = contextoModel::autenticado();
$conMenu       = $autenticado && !$vistaInfo['publica'];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php require __DIR__ . '/app/views/inc/head.php'; ?>
</head>
<body>
<?php if ($conMenu): ?>

<div class="app">
    <?php require __DIR__ . '/app/views/inc/menu-lateral.php'; ?>

    <div class="main">
        <?php require __DIR__ . '/app/views/inc/nav-menu.php'; ?>

        <main class="content" id="contenedor_pagina">
            <div id="toasts" aria-live="polite"></div>
            <?= View::render('partials/flash') ?>
            <?= $contenidoVista ?>
        </main>
    </div>
</div>

<?php require __DIR__ . '/app/views/inc/modal-reautenticacion.php'; ?>

<?php else: ?>

<div class="auth-wrap">
    <aside class="auth-aside">
        <h1><?= e(Config::get('app.name')) ?></h1>
        <p style="color:#9fb4e0;max-width:42ch">
            Inventario centralizado, cifrado y auditable de las credenciales de la empresa.
        </p>
        <ul>
            <li>Contrasenas cifradas con AES-256-GCM y claves envueltas.</li>
            <li>Cada consulta de una contrasena queda registrada: quien, cuando y desde donde.</li>
            <li>Acceso limitado a lo estrictamente autorizado para cada empleado.</li>
            <li>Verificacion en dos pasos para los perfiles administrativos.</li>
        </ul>
    </aside>
    <main class="auth-main">
        <div class="auth-card">
            <div id="toasts"></div>
            <?= View::render('partials/flash') ?>
            <?= $contenidoVista ?>
            <p class="text-small text-muted" style="text-align:center;margin-top:1rem">
                Uso exclusivo del personal autorizado. Toda actividad es registrada.
            </p>
        </div>
    </main>
</div>

<?php endif; ?>

<?php require __DIR__ . '/app/views/inc/scripts.php'; ?>
</body>
</html>
