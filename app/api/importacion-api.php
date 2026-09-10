<?php
declare(strict_types=1);

/**
 * Endpoint de importacion masiva de credenciales.
 *
 * 'plantilla' devuelve un CSV; el resto responde JSON. La previsualizacion
 * recibe el archivo por multipart, no por JSON.
 */

require_once __DIR__ . '/../views/inc/session_start.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/_comun.php';

use App\Core\HttpException;
use app\controllers\importacionController;

exigirSesion();

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($metodo === 'GET' && $accion === 'plantilla') {
    try {
        $csv = importacionController::plantillaController();
    } catch (HttpException $e) {
        header('Content-Type: application/json; charset=utf-8');
        responder([
            'code'    => $e->statusCode(),
            'status'  => 'error',
            'title'   => 'Operacion no permitida',
            'message' => $e->getMessage(),
            'data'    => null,
        ]);
    }
    enviarContenido($csv, 'plantilla-credenciales.csv', 'text/csv; charset=UTF-8');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

$cuerpo = cuerpoPeticion();

if ($metodo === 'GET') {
    switch ($accion) {
        case 'columnas':
        case '':
            responder(importacionController::columnasController());

        default:
            responder(accionInvalida($accion));
    }
}

if ($metodo === 'POST') {
    exigirCsrf();

    switch ($accion) {
        case 'previsualizar':
            responder(importacionController::previsualizarController($_FILES));

        case 'ejecutar':
            responder(importacionController::ejecutarController($cuerpo));

        default:
            responder(accionInvalida($accion));
    }
}

responder(metodoNoPermitido($metodo));
