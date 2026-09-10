<?php
declare(strict_types=1);

/**
 * Endpoint de acceso: ingreso, segundo factor, reautenticacion, estado de
 * la sesion y salida.
 *
 * Es el unico endpoint que admite peticiones sin sesion previa.
 */

require_once __DIR__ . '/../views/inc/session_start.php';
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/_comun.php';

use App\Core\Config;
use app\controllers\loginController;
use app\models\contextoModel;
use app\models\sesionModel;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

$accion = $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$cuerpo = cuerpoPeticion();

/** Fija la cookie de sesion con las mismas politicas que el resto del sistema. */
function fijarCookieSesion(string $token, int $expira = 0): void
{
    setcookie(sesionModel::COOKIE, $token, [
        'expires'  => $expira,
        'path'     => (string) Config::get('app.base_path', '') . '/',
        'secure'   => (bool) Config::get('session.cookie_secure', false),
        'httponly' => true,
        'samesite' => (string) Config::get('session.cookie_samesite', 'Strict'),
    ]);
}

if ($metodo === 'GET' && $accion === 'estado') {
    exigirSesion();
    responder(loginController::estadoSesionController());
}

if ($metodo === 'POST') {
    switch ($accion) {
        case 'ingresar':
            exigirCsrf();
            $r = loginController::ingresarController($cuerpo);
            $datos = $r['data'] ?? [];
            if (($datos['status'] ?? '') !== 'error' && isset($datos['token'])) {
                fijarCookieSesion((string) $datos['token']);
                // El token no se devuelve en el cuerpo: viaja solo en la cookie.
                unset($datos['token'], $datos['session']);
                $r['data'] = $datos;
            }
            responder($r);

        case 'mfa':
            exigirCsrf();
            $token  = $_COOKIE[sesionModel::COOKIE] ?? '';
            $sesion = $token !== '' ? sesionModel::resolver($token) : null;
            if ($sesion === null) {
                responder([
                    'code' => 401, 'status' => 'error', 'title' => 'Sesion requerida',
                    'message' => 'Su sesion expiro. Vuelva a iniciar sesion.', 'data' => null,
                ]);
            }
            $r = loginController::verificarMfaController($sesion, $cuerpo);
            $datos = $r['data'] ?? [];
            if (($datos['status'] ?? '') === 'ok' && isset($datos['token'])) {
                fijarCookieSesion((string) $datos['token']);
                unset($datos['token'], $datos['session']);
                $r['data'] = $datos;
            }
            responder($r);

        case 'reauth':
            exigirSesion();
            exigirCsrf();
            responder(loginController::reautenticarController($cuerpo));

        case 'salir':
            exigirSesion();
            exigirCsrf();
            $r = loginController::salirController();
            fijarCookieSesion('', time() - 3600);
            responder($r);

        default:
            responder(accionInvalida($accion));
    }
}

responder(metodoNoPermitido($metodo));
