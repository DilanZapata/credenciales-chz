<?php
declare(strict_types=1);

/**
 * Endpoint de usuarios (patron de Porcify Manager: un archivo por modulo, despacho por $_GET['accion']).
 */

require_once __DIR__ . '/../views/inc/session_start.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/_comun.php';

use app\controllers\usuarioController;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

exigirSesion();

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cuerpo = cuerpoPeticion();
$id     = (int) ($_GET['id'] ?? $cuerpo['id'] ?? 0);

if ($metodo === 'GET') {
    switch ($accion) {
        case 'listar':
        case '':
            responder(usuarioController::listarController($_GET));

        case 'ver':
            exigirId($id);
            responder(usuarioController::verController($id));

        case 'seleccion':
            responder(usuarioController::seleccionController());

        default:
            responder(accionInvalida($accion));
    }
}

if ($metodo === 'POST') {
    exigirCsrf();

    switch ($accion) {
        case 'agregar':
            responder(usuarioController::agregarController($cuerpo));

        case 'actualizar':
            exigirId($id);
            responder(usuarioController::actualizarController($id, $cuerpo));

        case 'permisos':
            exigirId($id);
            responder(usuarioController::permisosController($id, $cuerpo));

        case 'desactivar':
            exigirId($id);
            responder(usuarioController::desactivarController($id, $cuerpo));

        case 'reactivar':
            exigirId($id);
            responder(usuarioController::reactivarController($id));

        case 'restablecer':
            exigirId($id);
            responder(usuarioController::restablecerController($id));

        default:
            responder(accionInvalida($accion));
    }
}

responder(metodoNoPermitido($metodo));
