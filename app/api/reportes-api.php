<?php
declare(strict_types=1);

/**
 * Endpoint de reportes y exportacion a Excel.
 *
 * Dos acciones no devuelven JSON: 'descargar' entrega el XLSX. Por eso las
 * cabeceras JSON se fijan despues de resolverlas.
 */

require_once __DIR__ . '/../views/inc/session_start.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/_comun.php';

use App\Core\HttpException;
use app\controllers\reporteController;

exigirSesion();

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cuerpo = cuerpoPeticion();

if ($metodo === 'GET' && $accion === 'descargar') {
    try {
        $archivo = reporteController::descargarController((string) ($_GET['uuid'] ?? ''));
    } catch (HttpException $e) {
        header('Content-Type: application/json; charset=utf-8');
        responder([
            'code'    => $e->statusCode(),
            'status'  => 'error',
            'title'   => 'Descarga no disponible',
            'message' => $e->getMessage(),
            'data'    => null,
        ]);
    }
    // El archivo se elimina del servidor inmediatamente tras enviarlo.
    enviarArchivo(
        $archivo['path'],
        $archivo['file_name'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        true
    );
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

if ($metodo === 'GET') {
    switch ($accion) {
        case 'opciones':
        case '':
            responder(reporteController::opcionesController());

        case 'seleccion':
            responder(reporteController::seleccionController($_GET));

        case 'historial':
            responder(reporteController::historialController($_GET));

        default:
            responder(accionInvalida($accion));
    }
}

if ($metodo === 'POST') {
    exigirCsrf();

    switch ($accion) {
        case 'generar':
            responder(reporteController::generarController($cuerpo));

        default:
            responder(accionInvalida($accion));
    }
}

responder(metodoNoPermitido($metodo));
