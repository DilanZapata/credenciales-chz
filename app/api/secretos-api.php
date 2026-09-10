<?php
declare(strict_types=1);

/**
 * Endpoint de secretos.
 *
 * UNICO punto de la aplicacion que devuelve contrasenas en claro. Cada
 * llamada exige permiso, asignacion vigente, reautenticacion reciente y
 * queda registrada en secret_access_log y en la auditoria.
 */

require_once __DIR__ . '/../views/inc/session_start.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/_comun.php';

use app\controllers\secretoController;

header('Content-Type: application/json; charset=utf-8');
// La respuesta lleva un secreto: no debe quedar en ninguna cache.
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');

exigirSesion();

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cuerpo = cuerpoPeticion();
$id     = (int) ($_GET['id'] ?? $cuerpo['id'] ?? 0);

if ($metodo === 'GET' && $accion === 'recuperacion') {
    exigirId($id);
    responder(secretoController::recuperacionController($id));
}

if ($metodo === 'POST') {
    exigirCsrf();

    switch ($accion) {
        case 'revelar':
            exigirId($id);
            responder(secretoController::revelarController($id, $cuerpo));

        case 'historico':
            exigirId($id);
            $version = (int) ($_GET['version'] ?? $cuerpo['version'] ?? 0);
            if ($version <= 0) {
                responder([
                    'code' => 400, 'status' => 'error', 'title' => 'Solicitud invalida',
                    'message' => 'Debe indicar la version del secreto.', 'data' => null,
                ]);
            }
            responder(secretoController::revelarHistoricoController($id, $version, $cuerpo));

        default:
            responder(accionInvalida($accion));
    }
}

responder(metodoNoPermitido($metodo));
