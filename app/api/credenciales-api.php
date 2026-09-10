<?php
declare(strict_types=1);

/**
 * Endpoint de credenciales (patron de Porcify Manager: un archivo por
 * modulo, despacho por $_GET['accion']).
 *
 * NUNCA devuelve secretos. El texto en claro se obtiene por
 * app/api/secretos-api.php, que aplica sus propios controles.
 */

require_once __DIR__ . '/../views/inc/session_start.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/_comun.php';

use app\controllers\credencialController;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

exigirSesion();

$accion  = $_GET['accion'] ?? '';
$metodo  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cuerpo  = cuerpoPeticion();
$id      = (int) ($_GET['id'] ?? $cuerpo['id'] ?? 0);

if ($metodo === 'GET') {
    switch ($accion) {
        case 'listar':
        case '':
            responder(credencialController::listarController($_GET));

        case 'ver':
            exigirId($id);
            responder(credencialController::verController($id));

        case 'historial':
            exigirId($id);
            responder(credencialController::historialController($id));

        case 'asignaciones':
            exigirId($id);
            responder(credencialController::asignacionesController($id));

        default:
            responder(accionInvalida($accion));
    }
}

if ($metodo === 'POST') {
    exigirCsrf();

    switch ($accion) {
        case 'agregar':
            responder(credencialController::agregarController($cuerpo));

        case 'actualizar':
            exigirId($id);
            responder(credencialController::actualizarController($id, $cuerpo));

        case 'rotar':
            exigirId($id);
            responder(credencialController::rotarController($id, $cuerpo));

        case 'eliminar':
            exigirId($id);
            responder(credencialController::eliminarController($id, $cuerpo));

        case 'restaurar':
            exigirId($id);
            responder(credencialController::restaurarController($id));

        case 'asignar':
            exigirId($id);
            responder(credencialController::asignarController($id, $cuerpo));

        case 'revocar':
            exigirId($id);
            responder(credencialController::revocarController($id, (int) ($cuerpo['user_id'] ?? 0), $cuerpo));

        default:
            responder(accionInvalida($accion));
    }
}

responder(metodoNoPermitido($metodo));
