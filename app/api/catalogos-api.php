<?php
declare(strict_types=1);

/**
 * Endpoint de catalogos: categorias, organizacion, roles y configuracion.
 */

require_once __DIR__ . '/../views/inc/session_start.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/_comun.php';

use app\controllers\catalogoController;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

exigirSesion();

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cuerpo = cuerpoPeticion();
$id     = (int) ($_GET['id'] ?? $cuerpo['id'] ?? 0);

if ($metodo === 'GET') {
    switch ($accion) {
        case 'categorias':
        case '':
            responder(catalogoController::categoriasController($_GET));

        case 'organizacion':
            responder(catalogoController::organizacionController($_GET));

        case 'roles':
            responder(catalogoController::rolesController());

        case 'configuracion':
            responder(catalogoController::configuracionController());

        default:
            responder(accionInvalida($accion));
    }
}

if ($metodo === 'POST') {
    exigirCsrf();

    switch ($accion) {
        case 'guardar-categoria':
            responder(catalogoController::guardarCategoriaController($cuerpo));

        case 'guardar-empresa':
            responder(catalogoController::guardarEmpresaController($cuerpo));

        case 'guardar-sede':
            responder(catalogoController::guardarSedeController($cuerpo));

        case 'guardar-departamento':
            responder(catalogoController::guardarDepartamentoController($cuerpo));

        case 'guardar-rol':
            responder(catalogoController::guardarRolController($cuerpo));

        case 'permisos-rol':
            exigirId($id);
            responder(catalogoController::permisosRolController($id, $cuerpo));

        case 'guardar-configuracion':
            responder(catalogoController::guardarConfiguracionController($cuerpo));

        default:
            responder(accionInvalida($accion));
    }
}

responder(metodoNoPermitido($metodo));
