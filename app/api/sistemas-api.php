<?php
declare(strict_types=1);

/**
 * Endpoint del inventario de sistemas.
 */

require_once __DIR__ . '/../views/inc/session_start.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/_comun.php';

use app\controllers\sistemaController;

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
            responder(sistemaController::listarController($_GET));

        case 'ver':
            exigirId($id);
            responder(sistemaController::verController($id));

        case 'seleccion':
            responder(sistemaController::seleccionController());

        default:
            responder(accionInvalida($accion));
    }
}

if ($metodo === 'POST') {
    exigirCsrf();

    switch ($accion) {
        case 'agregar':
            responder(sistemaController::agregarController($cuerpo));

        case 'actualizar':
            exigirId($id);
            responder(sistemaController::actualizarController($id, $cuerpo));

        case 'archivar':
            exigirId($id);
            responder(sistemaController::archivarController($id, $cuerpo));

        default:
            responder(accionInvalida($accion));
    }
}

responder(metodoNoPermitido($metodo));
