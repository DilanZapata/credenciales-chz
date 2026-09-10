<?php
declare(strict_types=1);

/**
 * Endpoint de control de sesiones activas y cierre remoto.
 */

require_once __DIR__ . '/../views/inc/session_start.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/_comun.php';

use app\controllers\sesionController;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

exigirSesion();

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cuerpo = cuerpoPeticion();
$id     = (int) ($_GET['id'] ?? $cuerpo['id'] ?? 0);

// El identificador de sesion es una cadena hexadecimal, no un entero.
$idSesion = (string) ($_GET['sid'] ?? $cuerpo['sid'] ?? '');

if ($metodo === 'GET') {
    switch ($accion) {
        case 'listar':
        case '':
            responder(sesionController::listarController($_GET));

        default:
            responder(accionInvalida($accion));
    }
}

if ($metodo === 'POST') {
    exigirCsrf();

    switch ($accion) {
        case 'revocar':
            responder(sesionController::revocarController($idSesion, $cuerpo));

        case 'revocar-usuario':
            exigirId($id);
            responder(sesionController::revocarUsuarioController($id));

        default:
            responder(accionInvalida($accion));
    }
}

responder(metodoNoPermitido($metodo));
