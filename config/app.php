<?php
declare(strict_types=1);

use App\Core\Env;

/**
 * Parametros de la aplicacion.
 *
 * Las constantes siguen la convencion de Porcify Manager (APP_SESSION_NAME,
 * APP_COOKIE_TOKEN, zona horaria fijada aqui).
 *
 * DIFERENCIA DELIBERADA CON LA REFERENCIA: ningun secreto vive en este
 * archivo. La clave maestra de cifrado y la pimienta siguen en .env, que no
 * se versiona. Escribirlas aqui las publicaria en el repositorio.
 */

if (!defined('APP_SESSION_NAME')) {
    define('APP_SESSION_NAME', 'SCGCA');
}
if (!defined('APP_COOKIE_TOKEN')) {
    define('APP_COOKIE_TOKEN', 'scgca_session');
}
if (!defined('APP_COOKIE_CSRF')) {
    define('APP_COOKIE_CSRF', 'scgca_csrf');
}

date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'America/Bogota'));

return [
    'name'        => Env::get('APP_NAME', 'Sistema Corporativo de Gestion de Credenciales'),
    'short_name'  => Env::get('APP_SHORT_NAME', 'Credenciales'),
    'env'         => Env::get('APP_ENV', 'production'),
    'debug'       => (bool) Env::get('APP_DEBUG', false),
    'url'         => rtrim((string) Env::get('APP_URL', ''), '/'),
    'base_path'   => rtrim((string) Env::get('APP_BASE_PATH', ''), '/'),
    'timezone'    => Env::get('APP_TIMEZONE', 'America/Bogota'),
    'locale'      => 'es',
    'trust_proxy' => (bool) Env::get('APP_TRUST_PROXY', false),
    'organization'=> Env::get('APP_ORGANIZATION', 'Mi Empresa'),
];
