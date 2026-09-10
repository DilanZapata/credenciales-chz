<?php
declare(strict_types=1);

/** Endpoint de utilidades: generador, busqueda, notificaciones y alertas. */

require_once __DIR__ . '/../views/inc/session_start.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/_comun.php';

use app\controllers\utilidadController;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

exigirSesion();

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cuerpo = cuerpoPeticion();

if ($metodo === 'GET') {
    switch ($accion) {
        case 'buscar':         responder(utilidadController::buscarController($_GET));
        case 'notificaciones': responder(utilidadController::notificacionesController());
        case 'alertas':        responder(utilidadController::alertasController());
        default:               responder(accionInvalida($accion));
    }
}

if ($metodo === 'POST') {
    exigirCsrf();
    switch ($accion) {
        case 'generar':   responder(utilidadController::generarController($cuerpo));
        case 'fortaleza': responder(utilidadController::fortalezaController($cuerpo));
        default:          responder(accionInvalida($accion));
    }
}

responder(metodoNoPermitido($metodo));
