<?php
declare(strict_types=1);

/**
 * Arranque compartido de la peticion (patron de Porcify Manager, que lo
 * incluye al principio de index.php y de cada app/api/*-api.php).
 *
 * Hace tres cosas:
 *   1. Carga el autoload, el entorno y la configuracion.
 *   2. Resuelve la sesion a partir de la cookie y publica el contexto de
 *      seguridad en `contextoModel`.
 *   3. Aplica las cabeceras de seguridad comunes.
 *
 * NO decide autorizacion: eso corresponde a cada endpoint y a cada vista,
 * igual que en la referencia. Solo deja el contexto listo.
 *
 * A diferencia de Porcify, no se abre una sesion nativa de PHP: el estado
 * vive en la tabla `sessions`, lo que permite cerrarla remotamente y
 * aplicarle caducidad doble.
 */

if (defined('SCGCA_ARRANCADO')) {
    return;
}
define('SCGCA_ARRANCADO', true);

$raizProyecto = dirname(__DIR__, 3);

require_once $raizProyecto . '/autoload.php';
require_once $raizProyecto . '/app/Support/helpers.php';

use App\Core\Config;
use App\Core\Env;
use App\Core\Logger;
use App\Core\View;
use app\models\contextoModel;
use app\models\sesionModel;
use app\models\usuarioModel;

Env::load($raizProyecto . '/.env');
Config::loadDir($raizProyecto . '/config');

date_default_timezone_set((string) Config::get('app.timezone', 'America/Bogota'));
mb_internal_encoding('UTF-8');

Logger::setDirectory((string) Config::get('paths.logs'));
View::setPath((string) Config::get('paths.views'));

$modoDepuracion = (bool) Config::get('app.debug', false);
ini_set('display_errors', $modoDepuracion ? '1' : '0');
ini_set('display_startup_errors', $modoDepuracion ? '1' : '0');
error_reporting(E_ALL);

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    // Las sesiones nativas de PHP no se usan: el sistema gestiona las suyas.
    ini_set('session.use_cookies', '0');
    header_remove('X-Powered-By');

    // -------------------- Cabeceras de seguridad --------------------
    //
    // La CSP es estricta: sin 'unsafe-inline' para scripts (se usa un
    // nonce por peticion), sin origenes externos y con form-action
    // limitada al propio sitio. Asi un XSS no puede ejecutar codigo.
    //
    // Para hojas de estilo si se admite el atributo style en linea,
    // necesario para valores dinamicos (barras de progreso, colores de
    // categoria); inyectar CSS tiene un impacto muy inferior.
    $nonceCsp = base64_encode(random_bytes(16));
    View::share('cspNonce', $nonceCsp);

    foreach ((array) Config::get('security.headers', []) as $nombre => $valor) {
        header((string) $nombre . ': ' . (string) $valor);
    }

    header('Content-Security-Policy: ' . implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'nonce-" . $nonceCsp . "'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data:",
        "font-src 'self'",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ]));

    if (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: ' . (string) Config::get('security.hsts'));
    }

    // Ninguna pagina del sistema debe quedar en cache del navegador:
    // podria mostrar datos de otro usuario tras cerrar sesion.
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
}

// -------------------------------------------------------------------------
//  Contexto de la peticion
// -------------------------------------------------------------------------
$ipCliente = (static function (): string {
    if ((bool) Config::get('app.trust_proxy', false)) {
        $reenviada = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($reenviada !== '') {
            $primera = trim(explode(',', (string) $reenviada)[0]);
            if (filter_var($primera, FILTER_VALIDATE_IP) !== false) {
                return $primera;
            }
        }
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
})();

$agenteCliente = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

$dispositivoCliente = (static function (string $ua): string {
    if ($ua === '') {
        return 'Desconocido';
    }
    $so = 'Otro';
    foreach ([
        'Windows NT 10' => 'Windows 10/11', 'Windows NT' => 'Windows', 'Android' => 'Android',
        'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Mac OS X' => 'macOS', 'Linux' => 'Linux',
    ] as $aguja => $etiqueta) {
        if (str_contains($ua, $aguja)) { $so = $etiqueta; break; }
    }
    $navegador = 'Navegador';
    foreach ([
        'Edg/' => 'Edge', 'OPR/' => 'Opera', 'Chrome/' => 'Chrome',
        'Firefox/' => 'Firefox', 'Safari/' => 'Safari',
    ] as $aguja => $etiqueta) {
        if (str_contains($ua, $aguja)) { $navegador = $etiqueta; break; }
    }
    return $navegador . ' / ' . $so;
})($agenteCliente);

$rutaSolicitada = (static function (): string {
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $ruta = parse_url($uri, PHP_URL_PATH) ?: '/';
    // El prefijo se retira SOLO en un limite de segmento. Comparar por
    // prefijo a secas convertia /credenciales en /es cuando la aplicacion
    // vive bajo /credencial, y la peticion acababa en una vista que no era.
    $base = (string) Config::get('app.base_path', '');
    if ($base !== '' && ($ruta === $base || str_starts_with($ruta, $base . '/'))) {
        $ruta = substr($ruta, strlen($base));
    }
    $ruta = '/' . trim($ruta, '/');
    return $ruta === '/' ? '/' : rtrim($ruta, '/');
})();

contextoModel::fijarDatosPeticion(
    $ipCliente,
    $agenteCliente,
    $dispositivoCliente,
    strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $rutaSolicitada
);

// -------------------------------------------------------------------------
//  Resolucion de la sesion
// -------------------------------------------------------------------------
$tokenSesion = $_COOKIE[sesionModel::COOKIE] ?? null;
if (is_string($tokenSesion) && $tokenSesion !== '') {
    $sesionActual = sesionModel::resolver($tokenSesion);
    if ($sesionActual !== null) {
        $usuarioActual = usuarioModel::find((int) $sesionActual['user_id']);
        if ($usuarioActual !== null && $usuarioActual['status'] === 'active') {
            sesionModel::tocar((string) $sesionActual['id']);
            contextoModel::autenticar(
                $usuarioActual,
                $sesionActual,
                usuarioModel::effectivePermissions((int) $usuarioActual['id']),
                usuarioModel::rolesOf((int) $usuarioActual['id'])
            );
            View::share('csrf', (string) $sesionActual['csrf_token']);
            View::share('currentPath', $rutaSolicitada);
        } else {
            // La baja de un usuario surte efecto de inmediato.
            sesionModel::revocar((string) $sesionActual['id'], null, 'usuario no activo');
        }
    }
}
