<?php
declare(strict_types=1);

use App\Core\Env;

/**
 * Conexion en una sola variable.
 *
 * Los paneles de despliegue (Dokploy, Railway, Render...) entregan la
 * conexion como una URL:
 *
 *     mysql://usuario:clave@servidor:3306/basedatos
 *
 * Admitirla evita tener que repartir a mano cinco variables y equivocarse
 * en una. Las DB_* sueltas siguen funcionando y tienen prioridad, para no
 * romper instalaciones existentes.
 *
 * La contrasena se decodifica: un caracter como @ o / viaja en la URL
 * escapado (%40, %2F) y sin decodificar la autenticacion falla con un
 * "Access denied" que no dice por que.
 */
$url = trim((string) Env::get('DATABASE_URL', ''));
$deUrl = [];
if ($url !== '') {
    $partes = parse_url($url);
    if ($partes !== false && isset($partes['host'])) {
        $deUrl = [
            'host'     => $partes['host'],
            'port'     => (int) ($partes['port'] ?? 3306),
            'database' => ltrim((string) ($partes['path'] ?? ''), '/'),
            'username' => isset($partes['user']) ? rawurldecode($partes['user']) : null,
            'password' => isset($partes['pass']) ? rawurldecode($partes['pass']) : null,
        ];
        $deUrl = array_filter($deUrl, static fn ($v) => $v !== null && $v !== '' && $v !== 0);
    }
}

/** Valor suelto si existe; si no, el de la URL; si no, el de por defecto. */
$valor = static function (string $clave, string $campo, mixed $porDefecto) use ($deUrl): mixed {
    $suelto = Env::get($clave);
    if ($suelto !== null && $suelto !== '') {
        return $suelto;
    }
    return $deUrl[$campo] ?? $porDefecto;
};

$database = (string) $valor('DB_DATABASE', 'database', 'credenciales_corp');

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
    'host'     => $valor('DB_HOST', 'host', '127.0.0.1'),
    'port'     => (int) $valor('DB_PORT', 'port', 3306),
    'database' => $database,
    'username' => $valor('DB_USERNAME', 'username', 'root'),
    'password' => (string) $valor('DB_PASSWORD', 'password', ''),
    'charset'  => 'utf8mb4',
    'socket'   => Env::get('DB_SOCKET', ''),
];
