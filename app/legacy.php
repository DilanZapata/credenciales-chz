<?php
declare(strict_types=1);

/**
 * Puente hacia el enrutador anterior.
 *
 * Atiende lo que todavia no vive en la arquitectura de Porcify:
 *
 *   - las escrituras por formulario clasico (POST a /credenciales/12,
 *     /usuarios, /salir, /admin/…), que responden con 302 y mensaje;
 *   - /api/v1/*, la API con la que ya habla el cliente actual;
 *   - las descargas (/reportes/descargar/…, /importar/plantilla).
 *
 * Esta pieza es transitoria: desaparece cuando los formularios pasen a
 * hablar con app/api/*-api.php. Mientras tanto convive con index.php sin
 * duplicar logica, porque ambos comparten modelos y controladores.
 */

$raiz = dirname(__DIR__);

/** @var \App\Core\Container $container */
$container = require $raiz . '/app/bootstrap.php';
/** @var \App\Core\Router $router */
$router = require $raiz . '/app/routes.php';

$request = \App\Core\Request::capture();
\App\Core\Flash::load($request);

$kernel   = new \App\Core\Kernel($container, $router);
$response = $kernel->handle($request);

\App\Core\Flash::applyTo($response)->send();
