<?php
declare(strict_types=1);

/**
 * Controlador frontal (patron de Porcify Manager).
 *
 * El .htaccess reescribe  /credenciales/12  ->  index.php?views=credenciales/12
 *
 * Durante la migracion arquitectonica este archivo delega todavia en el
 * Kernel actual, de modo que el comportamiento es identico al de antes. Las
 * fases siguientes lo iran vaciando hasta dejar el ensamblado de vistas que
 * usa la referencia (head.php + nav-menu.php + menu-lateral.php + la vista
 * + scripts.php).
 */

require_once __DIR__ . '/autoload.php';

/** @var \App\Core\Container $container */
$container = require __DIR__ . '/app/bootstrap.php';
/** @var \App\Core\Router $router */
$router = require __DIR__ . '/app/routes.php';

$request = \App\Core\Request::capture();
\App\Core\Flash::load($request);

$kernel   = new \App\Core\Kernel($container, $router);
$response = $kernel->handle($request);

\App\Core\Flash::applyTo($response)->send();
