<?php
declare(strict_types=1);

/**
 * Endpoint de auditoria y eventos de seguridad. Solo lectura salvo el marcado de eventos como resueltos.
 */

require_once __DIR__ . '/../views/inc/session_start.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/_comun.php';

use app\controllers\auditoriaController;

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
            responder(auditoriaController::listarController($_GET));

        case 'acciones':
            responder(auditoriaController::accionesController());

        case 'eventos':
            responder(auditoriaController::eventosController($_GET));

        default:
            responder(accionInvalida($accion));
    }
}

if ($metodo === 'POST') {
    exigirCsrf();

    switch ($accion) {
        case 'resolver':
            exigirId($id);
            responder(auditoriaController::resolverEventoController($id));

        default:
            responder(accionInvalida($accion));
    }
}

responder(metodoNoPermitido($metodo));
