<?php
declare(strict_types=1);

use App\Core\Env;

$database = (string) Env::get('DB_DATABASE', 'credenciales_corp');

/**
 * Conmutador de base de datos para la bateria de pruebas.
 *
 * Las pruebas se ejecutan por HTTP real contra el servidor web, de modo que
 * no pueden imponer la base de datos por variable de entorno: el proceso de
 * Apache no la ve. En su lugar dejan un archivo marcador con el nombre de la
 * base de pruebas, y lo borran al terminar.
 *
 * SOLO opera fuera de produccion. Con APP_ENV=production el marcador se
 * ignora por completo, de modo que este mecanismo no puede desviar una
 * instalacion real hacia otra base.
 */
if (Env::get('APP_ENV', 'production') !== 'production') {
    $marcador = dirname(__DIR__) . '/storage/testing.flag';
    if (is_file($marcador)) {
        $nombre = trim((string) file_get_contents($marcador));
        if (preg_match('/^[A-Za-z0-9_]{1,60}$/', $nombre) === 1) {
            $database = $nombre;
        }
    }
}

return [
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => (int) Env::get('DB_PORT', 3306),
    'database' => $database,
    'username' => Env::get('DB_USERNAME', 'root'),
    'password' => (string) Env::get('DB_PASSWORD', ''),
    'charset'  => 'utf8mb4',
    'socket'   => Env::get('DB_SOCKET', ''),
];
