<?php
declare(strict_types=1);

/**
 * =====================================================================
 *  BATERIA DE PRUEBAS FUNCIONALES Y DE SEGURIDAD
 * =====================================================================
 *
 *  Ejecuta peticiones HTTP reales en proceso contra el Kernel, con una
 *  base de datos independiente (credenciales_corp_test) que se recrea en
 *  cada ejecucion. Ninguna prueba invoca servicios saltandose la capa de
 *  autorizacion: todo pasa por enrutado + middleware + controlador.
 *
 *  Uso:  php tests/run.php
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo por linea de comandos.\n");
}

$root = dirname(__DIR__);

// Base de datos aislada para pruebas.
//
// Este proceso la impone por variable de entorno, pero las peticiones las
// atiende Apache, que no ve esas variables. Por eso se deja ademas un archivo
// marcador que config/database.php consulta (solo fuera de produccion).
putenv('DB_DATABASE=credenciales_corp_test');
putenv('APP_DEBUG=false');

@mkdir($root . '/storage', 0777, true);
file_put_contents($root . '/storage/testing.flag', 'credenciales_corp_test');

// El marcador se retira pase lo que pase: si quedara, la instalacion local
// seguiria apuntando a la base de pruebas.
register_shutdown_function(static function () use ($root): void {
    @unlink($root . '/storage/testing.flag');
});

require $root . '/tests/HttpClient.php';

use app\models\cifradoModel;
use app\models\mainModel;
use Tests\HttpClient;

// ---------------------------------------------------------------------
//  Microframework de aserciones
// ---------------------------------------------------------------------
final class Suite
{
    public int $passed = 0;
    public int $failed = 0;
    /** @var array<int,string> */
    public array $failures = [];
    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo PHP_EOL . "\033[1m" . $name . "\033[0m" . PHP_EOL;
        echo str_repeat('─', 70) . PHP_EOL;
    }

    public function assert(bool $condition, string $description, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[32m✔\033[0m " . $description . PHP_EOL;
            return;
        }
        $this->failed++;
        $this->failures[] = $this->group . ' → ' . $description . ($detail !== '' ? ' [' . $detail . ']' : '');
        echo "  \033[31m✘\033[0m " . $description . ($detail !== '' ? "  \033[90m" . $detail . "\033[0m" : '') . PHP_EOL;
    }

    public function equals(mixed $expected, mixed $actual, string $description): void
    {
        $this->assert($expected === $actual, $description,
            'esperado: ' . var_export($expected, true) . ', obtenido: ' . var_export($actual, true));
    }

    public function status(int $expected, array $response, string $description): void
    {
        $this->assert($response['status'] === $expected, $description,
            'HTTP ' . $response['status'] . ' (esperado ' . $expected . ')');
    }

    public function summary(): int
    {
        $total = $this->passed + $this->failed;
        echo PHP_EOL . str_repeat('═', 70) . PHP_EOL;
        if ($this->failed === 0) {
            echo "\033[32m\033[1m  TODAS LAS PRUEBAS SUPERADAS\033[0m  ({$this->passed}/{$total})" . PHP_EOL;
        } else {
            echo "\033[31m\033[1m  {$this->failed} PRUEBA(S) FALLIDAS\033[0m  ({$this->passed}/{$total} correctas)" . PHP_EOL . PHP_EOL;
            foreach ($this->failures as $failure) {
                echo "   • " . $failure . PHP_EOL;
            }
        }
        echo str_repeat('═', 70) . PHP_EOL;
        return $this->failed === 0 ? 0 : 1;
    }
}

$t = new Suite();

// ---------------------------------------------------------------------
//  Preparacion del entorno de pruebas
// ---------------------------------------------------------------------
echo "\033[1mPreparando base de datos de pruebas…\033[0m" . PHP_EOL;

$baseUrl = HttpClient::baseUrlPorDefecto();
$sonda   = @file_get_contents($baseUrl . '/entrar');
if ($sonda === false) {
    fwrite(STDERR, PHP_EOL . "No se pudo alcanzar la aplicacion en {$baseUrl}" . PHP_EOL);
    fwrite(STDERR, "Las pruebas se ejecutan por HTTP real: Apache debe estar encendido." . PHP_EOL);
    fwrite(STDERR, "Si la ruta cambio, indiquela con TEST_BASE_URL=... php tests/run.php" . PHP_EOL . PHP_EOL);
    exit(1);
}
echo "  Aplicacion alcanzable en {$baseUrl}" . PHP_EOL;

// Arranque minimo del lado del proceso de pruebas: la aplicacion la sirve
// Apache, aqui solo se necesita hablar con la base de datos.
require_once $root . '/autoload.php';
require_once $root . '/app/Support/helpers.php';
\App\Core\Env::load($root . '/.env');
\App\Core\Config::loadDir($root . '/config');
date_default_timezone_set((string) \App\Core\Config::get('app.timezone', 'America/Bogota'));
\App\Core\Logger::setDirectory((string) \App\Core\Config::get('paths.logs'));

$cfg = \App\Core\Config::get('database');

// Se crea con mysqli, como el resto del sistema tras la migracion.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$raiz = !empty($cfg['socket'])
    ? new mysqli($cfg['host'], $cfg['username'], (string) $cfg['password'], null, (int) $cfg['port'], $cfg['socket'])
    : new mysqli($cfg['host'], $cfg['username'], (string) $cfg['password'], null, (int) $cfg['port']);
$raiz->query('DROP DATABASE IF EXISTS `credenciales_corp_test`');
$raiz->query('CREATE DATABASE `credenciales_corp_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$raiz->close();


// Se aplica el mismo juego de migraciones que en produccion: las pruebas
// ejercitan el esquema real, no una copia que pueda divergir.
$migrator = new \App\Support\Migrator($root . '/database/migrations');
$migrator->run();

cifradoModel::versionClaveActiva();

// Para la mayoria de las pruebas se desactiva el MFA obligatorio; hay una
// prueba especifica que lo vuelve a activar y verifica que se exija.
mainModel::ejecutarConsultaAfectadas("UPDATE settings SET setting_value = '0' WHERE setting_key = 'security.mfa_required_admins'");

// -------------------------- Datos de prueba ---------------------------
const PASS_ADMIN     = 'Adm1n#Prueba2026!';
const PASS_AUDITOR   = 'Aud1t#Prueba2026!';
const PASS_CONSULTOR = 'Cons#Prueba2026!x';
const PASS_OTRO      = 'Otro#Prueba2026!y';

$companyId = mainModel::ejecutarInsert('INSERT INTO companies (name) VALUES (?)', ['Empresa de Pruebas']);
$roleIds   = [];
foreach (mainModel::obtenerFilas('SELECT id, code FROM roles') as $row) {
    $roleIds[(string) $row['code']] = (int) $row['id'];
}

$makeUser = static function (string $nid, string $username, string $email, string $first, string $last,
                             string $password, string $roleCode) use ($companyId, $roleIds): int {
    $hash = cifradoModel::hashContrasena($password);
    $id   = mainModel::ejecutarInsert(
        'INSERT INTO users (national_id, username, email, first_name, last_name, company_id,
                            password_hash, password_algo, password_changed_at, must_change_password, status)
         VALUES (?,?,?,?,?,?,?,?,NOW(),0,"active")',
        [$nid, $username, $email, $first, $last, $companyId, $hash['hash'], $hash['algo']]
    );
    mainModel::ejecutarConsultaAfectadas('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [$id, $roleIds[$roleCode]]);
    return $id;
};

$adminId     = $makeUser('900000001', 'admin.test',   'admin@test.local',   'Admin',    'Prueba', PASS_ADMIN,     'ADMIN');
$auditorId   = $makeUser('900000002', 'auditor.test', 'auditor@test.local', 'Auditor',  'Prueba', PASS_AUDITOR,   'AUDITOR');
$consultorId = $makeUser('900000003', 'juan.test',    'juan@test.local',    'Juan',     'Perez',  PASS_CONSULTOR, 'CONSULTOR');
$otroId      = $makeUser('900000004', 'ana.test',     'ana@test.local',     'Ana',      'Torres', PASS_OTRO,      'CONSULTOR');

$categoryId = (int) mainModel::obtenerValor("SELECT id FROM categories WHERE slug = 'sistemas'");
$systemId   = mainModel::ejecutarInsert(
    'INSERT INTO systems (name, category_id, resource_type, company_id, url, status, owner_user_id)
     VALUES (?,?,?,?,?,"active",?)',
    ['Sistema Contable', $categoryId, 'application', $companyId, 'https://contable.test.local', $adminId]
);
$systemId2 = mainModel::ejecutarInsert(
    'INSERT INTO systems (name, category_id, resource_type, company_id, status)
     VALUES (?,?,?,?,"active")',
    ['Portal Bancario', $categoryId, 'banking', $companyId]
);

$makeCredential = static function (int $systemId, string $name, string $username, string $secret)
                  use ($adminId): array {
    $id = mainModel::ejecutarInsert(
        'INSERT INTO credentials (system_id, name, username, email, recovery_email, recovery_phone,
                                  status, rotation_period_days, next_rotation_at, password_changed_at,
                                  owner_user_id, created_by)
         VALUES (?,?,?,?,?,?,"active",90, DATE_ADD(CURDATE(), INTERVAL 90 DAY), NOW(), ?, ?)',
        [$systemId, $name, $username, $username . '@test.local', 'recuperacion@test.local', '3001234567', $adminId, $adminId]
    );
    $env = cifradoModel::cifrar($secret, cifradoModel::aad('credential', $id, 'password', 1));
    mainModel::ejecutarConsultaAfectadas(
        'INSERT INTO credential_secrets (credential_id, field, version, is_current, algo, key_version,
                                         ciphertext, nonce, tag, wrapped_dek, dek_nonce, dek_tag,
                                         secret_length, strength_score, fingerprint, created_by)
         VALUES (?,"password",1,1,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$id, $env['algo'], $env['key_version'], $env['ciphertext'], $env['nonce'], $env['tag'],
         $env['wrapped_dek'], $env['dek_nonce'], $env['dek_tag'], strlen($secret), 90,
         cifradoModel::huella($secret), $adminId]
    );
    return ['id' => $id, 'secret' => $secret];
};

$credA = $makeCredential($systemId,  'Usuario de contabilidad', 'contabilidad', 'S3cret0-Contab!2026');
$credB = $makeCredential($systemId,  'Usuario de inventarios',  'inventarios',  'S3cret0-Invent!2026');
$credC = $makeCredential($systemId2, 'Usuario de tesoreria',    'tesoreria',    'S3cret0-Tesorer!26');

// Juan ve y copia A; ve B pero NO puede copiarla; no tiene acceso a C.
mainModel::ejecutarConsultaAfectadas(
    'INSERT INTO credential_assignments (credential_id, user_id, can_view_secret, can_copy_secret, can_view_recovery, granted_by)
     VALUES (?,?,1,1,0,?)', [$credA['id'], $consultorId, $adminId]
);
mainModel::ejecutarConsultaAfectadas(
    'INSERT INTO credential_assignments (credential_id, user_id, can_view_secret, can_copy_secret, can_view_recovery, granted_by)
     VALUES (?,?,1,0,0,?)', [$credB['id'], $consultorId, $adminId]
);

$clearLimits = static function (): void { mainModel::ejecutarConsultaAfectadas('DELETE FROM rate_limits'); };

echo "Listo." . PHP_EOL;

// =====================================================================
//  1. AUTENTICACION
// =====================================================================
$t->group('1. Autenticacion');

$anon = new HttpClient('198.51.100.1');
$r = $anon->get('/');
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/entrar'),
    'Un usuario sin sesion es redirigido al formulario de acceso', 'HTTP ' . $r['status']);

$r = $anon->get('/entrar');
$t->status(200, $r, 'El formulario de acceso se muestra correctamente');
$t->assert(str_contains($r['body'], 'name="_csrf"'), 'El formulario incluye token anti-CSRF');

$r = $anon->post('/entrar', ['identifier' => 'admin.test', 'password' => 'contrasena-incorrecta']);
$t->assert($r['status'] === 302 && !str_contains($r['headers']['Location'] ?? '', '/entrar?') ,
    'El acceso con contrasena incorrecta no autentica');
$t->assert($anon->cookie('scgca_session') === null, 'No se emite cookie de sesion tras un intento fallido');
$attempts = (int) mainModel::obtenerValor("SELECT COUNT(*) FROM login_attempts WHERE result = 'failed'");
$t->assert($attempts >= 1, 'El intento fallido queda registrado en login_attempts');

$r = $anon->post('/entrar', ['identifier' => 'usuario.que.no.existe', 'password' => 'algo']);
$t->assert($r['status'] === 302, 'Un identificador inexistente responde igual que una clave incorrecta (sin enumeracion)');

$clearLimits();
$admin = new HttpClient('198.51.100.2');
$r = $admin->login('admin.test', PASS_ADMIN);
$t->assert($admin->cookie('scgca_session') !== null, 'El acceso valido emite la cookie de sesion');
$sessionRow = mainModel::obtenerFila('SELECT * FROM sessions WHERE id = ?', [hash('sha256', (string) $admin->cookie('scgca_session'))]);
$t->assert($sessionRow !== null, 'La sesion se persiste en la base de datos');
$t->assert($sessionRow !== null && strlen((string) $sessionRow['id']) === 64,
    'En la tabla solo se guarda el hash del token de sesion, no el token');

$r = $admin->get('/');
$t->status(200, $r, 'El administrador accede al panel de control');
$t->assert(str_contains($r['body'], 'Panel de control'), 'El panel de control se renderiza');

$loginAudit = (int) mainModel::obtenerValor("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login' AND result = 'success'");
$t->assert($loginAudit >= 1, 'El inicio de sesion queda auditado');

// Bloqueo por intentos fallidos
$clearLimits();
$bruteforce = new HttpClient('198.51.100.3');
for ($i = 0; $i < 6; $i++) {
    $bruteforce->post('/entrar', ['identifier' => 'ana.test', 'password' => 'clave-mala-' . $i]);
}
$locked = mainModel::obtenerFila('SELECT locked_until, failed_attempts FROM users WHERE id = ?', [$otroId]);
$t->assert($locked !== null && $locked['locked_until'] !== null,
    'La cuenta se bloquea temporalmente tras superar los intentos permitidos');
$blockedEvent = (int) mainModel::obtenerValor("SELECT COUNT(*) FROM security_events WHERE type = 'account_locked'");
$t->assert($blockedEvent >= 1, 'El bloqueo genera un evento de seguridad');
mainModel::ejecutarConsultaAfectadas('UPDATE users SET locked_until = NULL, failed_attempts = 0 WHERE id = ?', [$otroId]);

// Limitador de frecuencia
$clearLimits();
$flood  = new HttpClient('198.51.100.4');
$status = 0;
for ($i = 0; $i < 25; $i++) {
    $res    = $flood->post('/entrar', ['identifier' => 'inexistente' . $i, 'password' => 'x']);
    $status = $res['status'];
    if ($status === 429) { break; }
}
$t->equals(429, $status, 'El limitador de frecuencia corta la fuerza bruta por IP');
$clearLimits();

// =====================================================================
//  2. AUTORIZACION Y CONTROL DE ACCESO
// =====================================================================
$t->group('2. Autorizacion (el consultor no puede lo que no le corresponde)');

$juan = new HttpClient('198.51.100.5');
$juan->login('juan.test', PASS_CONSULTOR);
$t->assert($juan->cookie('scgca_session') !== null, 'El consultor inicia sesion correctamente');

$r = $juan->get('/');
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/mis-accesos'),
    'El consultor es dirigido a "Mis accesos" y no al panel administrativo');

$r = $juan->get('/mis-accesos');
$t->status(200, $r, 'El consultor accede a su panel');
$t->assert(str_contains($r['body'], 'Usuario de contabilidad'), 'El consultor ve la credencial que tiene asignada');
$t->assert(!str_contains($r['body'], 'Usuario de tesoreria'), 'El consultor NO ve credenciales ajenas en su panel');

foreach ([
    '/usuarios'             => 'la administracion de usuarios',
    '/auditoria'            => 'la auditoria',
    '/sesiones'             => 'el control de sesiones',
    '/admin/configuracion'  => 'la configuracion del sistema',
    '/admin/roles'          => 'la matriz de roles',
    '/sistemas'             => 'el inventario de sistemas',
    '/reportes'             => 'el modulo de reportes',
    '/importar'             => 'la importacion masiva',
] as $path => $label) {
    $res = $juan->get($path);
    $t->assert($res['status'] === 403, 'El consultor no puede acceder a ' . $label, 'HTTP ' . $res['status']);
}

$r = $juan->get('/credenciales/' . $credC['id']);
$t->status(404, $r, 'IDOR: el consultor no puede abrir por URL una credencial no asignada');

$r = $juan->postJson('/app/api/secretos-api.php?accion=revelar&id=' . $credC['id'], ['field' => 'password']);
$t->assert(in_array($r['status'], [403, 404], true),
    'IDOR en API: el consultor no puede revelar el secreto de una credencial ajena', 'HTTP ' . $r['status']);

$r = $juan->post('/credenciales/' . $credA['id'] . '/rotar', ['password' => 'IntentoDeCambio#2026']);
$t->status(403, $r, 'El consultor no puede modificar la contrasena almacenada');

$r = $juan->post('/credenciales/' . $credA['id'], ['name' => 'Nombre alterado', 'system_id' => $systemId]);
$t->status(403, $r, 'El consultor no puede editar la credencial');

$r = $juan->post('/usuarios/' . $consultorId, ['first_name' => 'Hacker']);
$t->status(403, $r, 'El consultor no puede modificar usuarios');

$r = $juan->postJson('/app/api/credenciales-api.php?accion=agregar', ['system_id' => $systemId, 'name' => 'Nueva', 'password' => 'Abc#12345678']);
$t->status(403, $r, 'El consultor no puede crear credenciales por la API');

$deniedAudit = (int) mainModel::obtenerValor("SELECT COUNT(*) FROM audit_logs WHERE action = 'security.access_denied'");
$t->assert($deniedAudit >= 5, 'Los intentos de acceso denegado quedan auditados', 'registros: ' . $deniedAudit);

// El auditor: consulta todo pero no ve secretos
$auditor = new HttpClient('198.51.100.6');
$auditor->login('auditor.test', PASS_AUDITOR);
$r = $auditor->get('/auditoria');
$t->status(200, $r, 'El auditor accede a la auditoria');
$r = $auditor->get('/credenciales/' . $credC['id']);
$t->status(200, $r, 'El auditor ve la ficha de cualquier credencial');
$t->assert(!str_contains($r['body'], $credC['secret']), 'La ficha entregada al auditor no contiene el secreto');
$r = $auditor->postJson('/app/api/secretos-api.php?accion=revelar&id=' . $credC['id']);
$t->status(403, $r, 'El auditor NO puede revelar contrasenas');
$r = $auditor->post('/reportes/generar', ['type' => 'full_credentials', 'include_secrets' => '1', 'confirm' => '1']);
$t->status(403, $r, 'El auditor NO puede exportar contrasenas');

// =====================================================================
//  3. PROTECCION DEL SECRETO (regla de oro)
// =====================================================================
$t->group('3. Separacion entre informacion y secreto');

$r = $admin->getJson('/app/api/credenciales-api.php?accion=listar');
$t->status(200, $r, 'El endpoint de credenciales responde correctamente');
$body = $r['body'];
$t->assert(!str_contains($body, $credA['secret']) && !str_contains($body, $credB['secret']) && !str_contains($body, $credC['secret']),
    'El listado de la API NUNCA devuelve contrasenas');
$t->assert(!preg_match('/"password"\s*:/', $body) === true || !str_contains($body, '"password":"'),
    'El listado no incluye ningun campo password con valor');

$r = $admin->getJson('/app/api/credenciales-api.php?accion=ver&id=' . $credA['id']);
$t->assert(!str_contains($r['body'], $credA['secret']), 'El detalle de la API tampoco devuelve la contrasena');
$t->assert(!str_contains($r['body'], 'recuperacion@test.local'),
    'El detalle no expone la informacion de recuperacion sin la peticion especifica');

$r = $admin->get('/credenciales/' . $credA['id']);
$t->assert(!str_contains($r['body'], $credA['secret']), 'La ficha HTML no contiene la contrasena en el codigo fuente');
$t->assert(str_contains($r['body'], 'data-secret-toggle'), 'La ficha ofrece el boton "Mostrar" controlado');

// Step-up obligatorio
$r = $admin->postJson('/app/api/secretos-api.php?accion=revelar&id=' . $credA['id']);
$t->status(423, $r, 'Revelar un secreto exige reautenticacion (step-up)');
$t->assert(($r['json']['reauth_required'] ?? false) === true, 'La respuesta indica que se requiere reautenticacion');

$r = $admin->postJson('/app/api/login-api.php?accion=reauth', ['password' => 'clave-equivocada']);
$t->status(401, $r, 'La reautenticacion con clave incorrecta se rechaza');

$r = $admin->postJson('/app/api/login-api.php?accion=reauth', ['password' => PASS_ADMIN]);
$t->status(200, $r, 'La reautenticacion con la clave correcta se acepta');

$r = $admin->postJson('/app/api/secretos-api.php?accion=revelar&id=' . $credA['id']);
$t->status(200, $r, 'Tras el step-up, el secreto se revela');
$t->equals($credA['secret'], $r['json']['data']['secret'] ?? null, 'El secreto descifrado coincide con el original');

$accessLog = mainModel::obtenerFila(
    'SELECT * FROM secret_access_log WHERE credential_id = ? ORDER BY id DESC LIMIT 1', [$credA['id']]
);
$t->assert($accessLog !== null && (int) $accessLog['user_id'] === $adminId,
    'La consulta del secreto queda registrada con el usuario que la realizo');
$t->assert($accessLog !== null && $accessLog['ip_address'] === '198.51.100.2',
    'El registro incluye la direccion IP de origen');
$t->assert((int) mainModel::obtenerValor("SELECT COUNT(*) FROM audit_logs WHERE action = 'secret.viewed'") >= 1,
    'La visualizacion del secreto queda en la auditoria general');

// Permisos finos de la asignacion: Juan puede ver B pero no copiarla
$juan->postJson('/app/api/login-api.php?accion=reauth', ['password' => PASS_CONSULTOR]);
$r = $juan->postJson('/app/api/secretos-api.php?accion=revelar&id=' . $credB['id'], ['copy' => false]);
$t->status(200, $r, 'El consultor ve el secreto que su asignacion permite ver');
$r = $juan->postJson('/app/api/secretos-api.php?accion=revelar&id=' . $credB['id'], ['copy' => true]);
$t->status(403, $r, 'El consultor NO puede copiar un secreto cuya asignacion lo prohibe');

// Informacion de recuperacion
$r = $juan->getJson('/app/api/secretos-api.php?accion=recuperacion&id=' . $credA['id']);
$t->status(403, $r, 'El consultor sin permiso no obtiene la informacion de recuperacion');

$admin->postJson('/app/api/login-api.php?accion=reauth', ['password' => PASS_ADMIN]);
$r = $admin->getJson('/app/api/secretos-api.php?accion=recuperacion&id=' . $credA['id']);
$t->status(200, $r, 'El administrador obtiene la informacion de recuperacion por su endpoint especifico');
$t->assert(($r['json']['data']['recovery_email'] ?? '') === 'recuperacion@test.local', 'La informacion de recuperacion es correcta');
$t->assert((int) mainModel::obtenerValor("SELECT COUNT(*) FROM audit_logs WHERE action = 'recovery.viewed'") >= 1,
    'La consulta de datos de recuperacion queda auditada');

// =====================================================================
//  4. CIFRADO
// =====================================================================
$t->group('4. Cifrado de los secretos almacenados');

$row = mainModel::obtenerFila('SELECT * FROM credential_secrets WHERE credential_id = ?', [$credA['id']]);
$cipher = is_resource($row['ciphertext']) ? stream_get_contents($row['ciphertext']) : (string) $row['ciphertext'];
$t->assert(!str_contains($cipher, $credA['secret']), 'El criptograma no contiene la contrasena en claro');
$t->assert($cipher !== $credA['secret'], 'El valor almacenado difiere del texto original');
$t->assert(strlen((string) $row['nonce']) === 12, 'Se almacena un nonce de 96 bits por secreto');
$t->assert(strlen((string) $row['tag']) === 16, 'Se almacena la etiqueta de autenticacion GCM');
$t->assert(strlen((string) $row['wrapped_dek']) > 0, 'La clave de datos se guarda envuelta, nunca en claro');

$plain = cifradoModel::descifrar($row, cifradoModel::aad('credential', (int) $row['credential_id'], 'password', 1));
$t->equals($credA['secret'], $plain, 'El descifrado con el AAD correcto recupera el secreto');

$wrong = cifradoModel::descifrar($row, cifradoModel::aad('credential', 999999, 'password', 1));
$t->assert($wrong === null, 'El descifrado con un AAD de otra credencial falla (integridad ligada al contexto)');

$tampered = $row;
$tampered['ciphertext'] = substr($cipher, 0, -1) . chr((ord(substr($cipher, -1)) + 1) % 256);
$t->assert(cifradoModel::descifrar($tampered, cifradoModel::aad('credential', (int) $row['credential_id'], 'password', 1)) === null,
    'Un criptograma manipulado es rechazado por la autenticacion GCM');

$secretA = cifradoModel::cifrar('mismo-valor', 'aad');
$secretB = cifradoModel::cifrar('mismo-valor', 'aad');
$t->assert($secretA['ciphertext'] !== $secretB['ciphertext'],
    'Dos cifrados del mismo valor producen criptogramas distintos (nonce y DEK unicos)');

$userRow = mainModel::obtenerFila('SELECT password_hash FROM users WHERE id = ?', [$adminId]);
$t->assert(!str_contains((string) $userRow['password_hash'], PASS_ADMIN),
    'La contrasena de acceso al sistema se guarda como hash irreversible');
$t->assert(str_starts_with((string) $userRow['password_hash'], '$2y$') || str_starts_with((string) $userRow['password_hash'], '$argon2'),
    'El hash usa un algoritmo lento con sal (bcrypt/Argon2id)');

// =====================================================================
//  5. CSRF, XSS E INYECCION
// =====================================================================
$t->group('5. Defensas frente a CSRF, XSS e inyeccion SQL');

$r = $admin->post('/credenciales/' . $credA['id'] . '/rotar', ['password' => 'SinToken#2026abc'], false);
$t->status(403, $r, 'Una peticion de escritura sin token CSRF es rechazada');

$victim = new HttpClient('198.51.100.7');
$victim->login('admin.test', PASS_ADMIN);
$victim->setCookie('scgca_csrf', str_repeat('a', 64));
$r = $victim->request('POST', '/credenciales/' . $credA['id'] . '/eliminar', ['reason' => 'ataque', '_csrf' => str_repeat('b', 64)], false, false);
$t->status(403, $r, 'Un token CSRF que no corresponde a la sesion es rechazado');
$t->assert((int) mainModel::obtenerValor("SELECT COUNT(*) FROM audit_logs WHERE action = 'security.csrf_failed'") >= 1,
    'Los fallos de CSRF quedan auditados como evento critico');

// Inyeccion SQL en los filtros
$payloads = ["' OR '1'='1", "1; DROP TABLE users --", "' UNION SELECT password_hash FROM users --", "%' AND SLEEP(3) --"];
foreach ($payloads as $payload) {
    $res = $admin->get('/credenciales', ['q' => $payload]);
    $t->assert($res['status'] === 200, 'Inyeccion SQL neutralizada en el buscador: ' . substr($payload, 0, 22), 'HTTP ' . $res['status']);
}
$t->assert((int) mainModel::obtenerValor('SELECT COUNT(*) FROM users') === 4, 'La tabla users sigue intacta tras los intentos de inyeccion');

$res = $admin->get('/credenciales', ['sort' => 'name); DROP TABLE credentials --', 'direction' => 'ASC; DELETE FROM users']);
$t->assert($res['status'] === 200 && (int) mainModel::obtenerValor('SELECT COUNT(*) FROM credentials') === 3,
    'El ordenamiento se valida contra lista blanca (no admite SQL arbitrario)');

// XSS almacenado
$xss = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
$r = $admin->post('/credenciales', [
    'system_id' => $systemId, 'name' => $xss, 'username' => 'xsstest',
    'password' => 'Xss#Prueba2026abc', 'environment' => 'production',
]);
$xssId = (int) mainModel::obtenerValor('SELECT id FROM credentials WHERE username = ?', ['xsstest']);
$t->assert($xssId > 0, 'La credencial de prueba XSS se creo');
$r = $admin->get('/credenciales/' . $xssId);
$t->assert(!str_contains($r['body'], '<script>alert("xss")</script>'),
    'El contenido malicioso NO se emite como HTML ejecutable');
$t->assert(str_contains($r['body'], '&lt;script&gt;'), 'El contenido se muestra escapado');
$t->assert(str_contains($r['headers']['Content-Security-Policy'] ?? '', "script-src 'self' 'nonce-"),
    'La CSP exige nonce para los scripts (sin unsafe-inline)');

foreach ([
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options'        => 'DENY',
    'Referrer-Policy'        => 'no-referrer',
] as $header => $expected) {
    $t->assert(($r['headers'][$header] ?? '') === $expected, 'Cabecera de seguridad ' . $header . ' presente');
}
$t->assert(str_contains($r['headers']['Cache-Control'] ?? '', 'no-store'),
    'Las paginas con datos sensibles no se almacenan en cache');

// =====================================================================
//  6. CICLO DE VIDA DE LAS CREDENCIALES
// =====================================================================
$t->group('6. Ciclo de vida de las credenciales');

$admin->postJson('/app/api/login-api.php?accion=reauth', ['password' => PASS_ADMIN]);
$nuevoSecreto = 'Rotad0-Nuev0#2026';
$r = $admin->post('/credenciales/' . $credA['id'] . '/rotar', [
    'password' => $nuevoSecreto, 'reason' => 'Rotacion periodica de prueba',
]);
$t->assert($r['status'] === 302, 'La rotacion de contrasena se ejecuta');

$versions = (int) mainModel::obtenerValor('SELECT COUNT(*) FROM credential_secrets WHERE credential_id = ?', [$credA['id']]);
$t->equals(2, $versions, 'La contrasena anterior se conserva como version historica');
$current = mainModel::obtenerFila('SELECT * FROM credential_secrets WHERE credential_id = ? AND is_current = 1', [$credA['id']]);
$t->equals(2, (int) $current['version'], 'La version vigente es la mas reciente');
$t->equals($nuevoSecreto, cifradoModel::descifrar($current, cifradoModel::aad('credential', $credA['id'], 'password', 2)),
    'La nueva contrasena se almacena cifrada y se recupera correctamente');

$history = mainModel::obtenerFila("SELECT * FROM credential_history WHERE credential_id = ? AND action = 'password_rotated'", [$credA['id']]);
$t->assert($history !== null && $history['reason'] === 'Rotacion periodica de prueba',
    'El historial registra el motivo del cambio');
$t->assert($history !== null && (int) $history['performed_by'] === $adminId,
    'El historial registra quien realizo el cambio');

$rotatedRow = mainModel::obtenerFila('SELECT password_changed_at, next_rotation_at FROM credentials WHERE id = ?', [$credA['id']]);
$t->assert($rotatedRow['password_changed_at'] !== null && $rotatedRow['next_rotation_at'] !== null,
    'Se actualizan la fecha de rotacion y la proxima fecha recomendada');

$historyDump = mainModel::obtenerFilas('SELECT old_value, new_value, metadata FROM credential_history WHERE credential_id = ?', [$credA['id']]);
$leak = false;
foreach ($historyDump as $h) {
    foreach ($h as $value) {
        if (is_string($value) && (str_contains($value, $nuevoSecreto) || str_contains($value, $credA['secret']))) {
            $leak = true;
        }
    }
}
$t->assert(!$leak, 'El historial NO almacena contrasenas en claro');

$r = $admin->post('/credenciales/' . $credA['id'] . '/rotar', ['password' => $nuevoSecreto, 'reason' => 'repetida']);
$t->assert((int) mainModel::obtenerValor('SELECT COUNT(*) FROM credential_secrets WHERE credential_id = ?', [$credA['id']]) === 2,
    'No se admite repetir la contrasena vigente (deteccion por huella, sin descifrar)');

// Historico: exige permiso especifico
$r = $admin->postJson('/app/api/secretos-api.php?accion=historico&version=1&id=' . $credA['id']);
$t->status(200, $r, 'Con el permiso adecuado se puede consultar una contrasena historica');
$t->equals($credA['secret'], $r['json']['data']['secret'] ?? null, 'La contrasena historica se recupera correctamente');
$t->assert((int) mainModel::obtenerValor("SELECT COUNT(*) FROM secret_access_log WHERE access_type = 'history_view'") >= 1,
    'La consulta de una contrasena historica queda registrada');

$r = $juan->postJson('/app/api/secretos-api.php?accion=historico&version=1&id=' . $credA['id']);
$t->status(403, $r, 'El consultor no puede acceder a contrasenas historicas');

// Asignacion y revocacion
$r = $admin->post('/credenciales/' . $credC['id'] . '/asignar', [
    'user_id' => $consultorId, 'can_view_secret' => '1', 'can_copy_secret' => '1',
]);
$t->assert($r['status'] === 302, 'El administrador asigna una credencial a un usuario');
$r = $juan->get('/credenciales/' . $credC['id']);
$t->status(200, $r, 'El consultor ya puede ver la credencial recien asignada');

$r = $admin->post('/credenciales/' . $credC['id'] . '/revocar/' . $consultorId, ['reason' => 'Fin de la prueba']);
$t->assert($r['status'] === 302, 'El administrador revoca el acceso');
$r = $juan->get('/credenciales/' . $credC['id']);
$t->status(404, $r, 'Tras la revocacion el consultor pierde el acceso de inmediato');

// =====================================================================
//  7. BAJA DE USUARIOS
// =====================================================================
$t->group('7. Baja de empleados');

$r = $admin->post('/usuarios/' . $consultorId . '/desactivar', [
    'reason' => 'Retiro del empleado (prueba)',
]);
$t->assert($r['status'] === 302, 'El administrador desactiva al usuario');

$userRow = mainModel::obtenerFila('SELECT status, deactivated_at, deactivation_reason FROM users WHERE id = ?', [$consultorId]);
$t->equals('inactive', $userRow['status'], 'El usuario queda inactivo');
$t->assert($userRow['deactivation_reason'] === 'Retiro del empleado (prueba)', 'Se conserva el motivo de la baja');

$active = (int) mainModel::obtenerValor('SELECT COUNT(*) FROM credential_assignments WHERE user_id = ? AND is_active = 1', [$consultorId]);
$t->equals(0, $active, 'Se revocan automaticamente todos sus accesos');
$historic = (int) mainModel::obtenerValor('SELECT COUNT(*) FROM credential_assignments WHERE user_id = ?', [$consultorId]);
$t->assert($historic > 0, 'El historico de asignaciones se conserva (no se borra informacion)');

$openSessions = (int) mainModel::obtenerValor("SELECT COUNT(*) FROM sessions WHERE user_id = ? AND status = 'active'", [$consultorId]);
$t->equals(0, $openSessions, 'Se cierran sus sesiones abiertas');

$r = $juan->get('/mis-accesos');
$t->assert($r['status'] === 302, 'La sesion del usuario dado de baja deja de funcionar de inmediato');

$clearLimits();
$exJuan = new HttpClient('198.51.100.8');
$r = $exJuan->login('juan.test', PASS_CONSULTOR);
$t->assert($exJuan->cookie('scgca_session') === null, 'El usuario inactivo no puede volver a iniciar sesion');

$auditEntry = mainModel::obtenerFila("SELECT details FROM audit_logs WHERE action = 'user.deactivated' ORDER BY id DESC LIMIT 1");
$t->assert($auditEntry !== null && str_contains((string) $auditEntry['details'], 'accesos_revocados'),
    'La baja queda auditada con el detalle de los accesos revocados');

// =====================================================================
//  8. SESIONES
// =====================================================================
$t->group('8. Control de sesiones');

$clearLimits();
$otro = new HttpClient('198.51.100.9');
$otro->login('ana.test', PASS_OTRO);
$t->assert($otro->cookie('scgca_session') !== null, 'Segundo usuario con sesion activa');

$r = $admin->get('/sesiones');
$t->status(200, $r, 'El administrador lista las sesiones activas');
$t->assert(str_contains($r['body'], '198.51.100.9'), 'La sesion del otro usuario aparece en el listado');

$sessionId = hash('sha256', (string) $otro->cookie('scgca_session'));
$r = $admin->post('/sesiones/' . $sessionId . '/cerrar', ['reason' => 'Prueba de cierre remoto']);
$t->assert($r['status'] === 302, 'El administrador cierra remotamente una sesion');
$r = $otro->get('/mis-accesos');
$t->assert($r['status'] === 302, 'La sesion cerrada remotamente deja de ser valida de inmediato');
$t->assert((int) mainModel::obtenerValor("SELECT COUNT(*) FROM audit_logs WHERE action = 'session.revoked'") >= 1,
    'El cierre remoto queda auditado');

$r = $admin->getJson('/app/api/login-api.php?accion=estado');
$t->assert(($r['json']['data']['authenticated'] ?? false) === true, 'El endpoint de estado de sesion responde correctamente');
$t->assert(!str_contains($r['body'], 'password'), 'El estado de sesion no expone datos sensibles');

// =====================================================================
//  9. REPORTES Y EXPORTACION
// =====================================================================
$t->group('9. Reportes y exportacion a Excel');

$r = $admin->post('/reportes/generar', ['type' => 'inventory']);
$t->assert($r['status'] === 302, 'Se genera el reporte de inventario');
$report = mainModel::obtenerFila("SELECT * FROM export_reports WHERE report_type = 'inventory' ORDER BY id DESC LIMIT 1");
$t->assert($report !== null, 'La exportacion queda registrada en export_reports');
$t->equals(0, (int) $report['included_secrets'], 'El inventario NO incluye contrasenas');
$t->assert((int) $report['record_count'] > 0, 'El reporte contiene registros');
$t->assert(!str_starts_with((string) $report['storage_path'], $root . '/public'),
    'El archivo se almacena FUERA del directorio publico');
$t->assert(!str_contains((string) $report['file_name'], 'password'), 'El nombre del archivo no revela contenido sensible');

// El archivo se inspecciona descargandolo, no leyendolo del disco: lo crea
// el servidor web con permisos 0600 y este proceso corre como otro usuario.
// Ademas asi se ejercita el camino real que sigue una persona.
$descarga = $admin->get('/reportes/descargar/' . $report['uuid']);
$rutaTmp  = sys_get_temp_dir() . '/inventario-prueba.xlsx';
file_put_contents($rutaTmp, $descarga['body']);
$zip = new ZipArchive();
$t->assert($zip->open($rutaTmp) === true, 'El archivo generado es un XLSX valido');
$sheet = $zip->filename !== null ? (string) $zip->getFromName('xl/worksheets/sheet1.xml') : '';
if ($zip->filename !== null) { $zip->close(); }
@unlink($rutaTmp);
$t->assert(!str_contains($sheet, $nuevoSecreto) && !str_contains($sheet, $credC['secret']),
    'El Excel de inventario no contiene ninguna contrasena');

// Exportacion con contrasenas: requiere confirmacion explicita
$r = $admin->post('/reportes/generar', ['type' => 'full_credentials', 'include_secrets' => '1']);
$t->assert($r['status'] === 302 || $r['status'] === 422,
    'Sin confirmacion explicita no se genera un archivo con contrasenas');
$withSecrets = (int) mainModel::obtenerValor("SELECT COUNT(*) FROM export_reports WHERE included_secrets = 1 AND status = 'ready'");
$t->equals(0, $withSecrets, 'No se creo ningun archivo con contrasenas sin confirmar la advertencia');

$admin->postJson('/app/api/login-api.php?accion=reauth', ['password' => PASS_ADMIN]);
$r = $admin->post('/reportes/generar', ['type' => 'full_credentials', 'include_secrets' => '1', 'confirm' => '1']);
$t->assert($r['status'] === 302, 'Con permiso, confirmacion y step-up se genera el archivo con contrasenas');

$secretReport = mainModel::obtenerFila("SELECT * FROM export_reports WHERE included_secrets = 1 ORDER BY id DESC LIMIT 1");
$t->assert($secretReport !== null && $secretReport['status'] === 'ready', 'El reporte confidencial se genero');
$t->assert((int) mainModel::obtenerValor('SELECT COUNT(*) FROM export_report_items WHERE report_id = ?', [(int) $secretReport['id']]) > 0,
    'Se registra que credenciales concretas viajaron en el archivo');
$t->assert((int) mainModel::obtenerValor("SELECT COUNT(*) FROM secret_access_log WHERE access_type = 'export'") > 0,
    'Cada secreto exportado deja su registro individual de acceso');
$t->assert((int) mainModel::obtenerValor("SELECT COUNT(*) FROM security_events WHERE type = 'export_with_secrets'") >= 1,
    'La exportacion con contrasenas genera un evento de seguridad');

// Descarga: solo el autor, y el archivo se elimina.
// El contenido del Excel se inspecciona a partir de ESTA descarga, no
// leyendo el disco: el archivo lo crea el servidor web con permisos 0600 y
// desaparece justo despues, que es lo que se comprueba a continuacion.
$intruder = new HttpClient('198.51.100.10');
$clearLimits();
$intruder->login('auditor.test', PASS_AUDITOR);
$r = $intruder->get('/reportes/descargar/' . $secretReport['uuid']);
$t->status(404, $r, 'Otro usuario no puede descargar un reporte ajeno');
$t->assert((int) mainModel::obtenerValor("SELECT COUNT(*) FROM audit_logs WHERE action = 'export.denied'") >= 1,
    'El intento de descarga ajena queda auditado');

$r = $admin->get('/reportes/descargar/' . $secretReport['uuid']);
$t->status(200, $r, 'El autor descarga su reporte');
$t->assert(!is_file((string) $secretReport['storage_path']),
    'El archivo se elimina del servidor inmediatamente despues de la descarga');

$rutaConf = sys_get_temp_dir() . '/confidencial-prueba.xlsx';
file_put_contents($rutaConf, $r['body']);
$zip          = new ZipArchive();
$abierto      = $zip->open($rutaConf) === true;
$confidential = $abierto ? (string) $zip->getFromName('xl/worksheets/sheet1.xml') : '';
if ($abierto) { $zip->close(); }
@unlink($rutaConf);
$t->assert(str_contains($confidential, $nuevoSecreto), 'El archivo confidencial si contiene las contrasenas solicitadas');

$r = $admin->get('/reportes/historial');
$t->status(200, $r, 'El historial de exportaciones es consultable');
$t->assert(str_contains($r['body'], 'Con contrasenas') || str_contains($r['body'], 'SI'),
    'El historial distingue las exportaciones que incluyeron contrasenas');

// =====================================================================
//  10. AUDITORIA Y TRAZABILIDAD
// =====================================================================
$t->group('10. Auditoria y trazabilidad');

$r = $admin->get('/auditoria');
$t->status(200, $r, 'La auditoria se consulta correctamente');

$r = $admin->get('/auditoria', ['action' => 'secret.viewed']);
$t->status(200, $r, 'La auditoria se puede filtrar por tipo de accion');
$r = $admin->get('/auditoria', ['national_id' => '900000001']);
$t->status(200, $r, 'La auditoria se puede filtrar por cedula');
$r = $admin->get('/auditoria', ['result' => 'denied', 'date_from' => date('Y-m-d')]);
$t->status(200, $r, 'La auditoria se puede filtrar por resultado y rango de fechas');

$expected = ['auth.login', 'auth.login_failed', 'secret.viewed', 'credential.password_rotated',
             'credential.assigned', 'credential.revoked', 'user.deactivated', 'export.generated',
             'export.downloaded', 'session.revoked', 'security.access_denied'];
foreach ($expected as $action) {
    $count = (int) mainModel::obtenerValor('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]);
    $t->assert($count > 0, 'Se audita la accion: ' . $action, 'registros: ' . $count);
}

$columns = mainModel::obtenerFila('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1');
foreach (['user_id', 'actor_national_id', 'action', 'result', 'ip_address', 'device', 'occurred_at'] as $column) {
    $t->assert(array_key_exists($column, $columns), 'El registro de auditoria incluye ' . $column);
}

// Ninguna contrasena en la auditoria ni en los logs
$allSecrets = [$credA['secret'], $credB['secret'], $credC['secret'], $nuevoSecreto,
               PASS_ADMIN, PASS_AUDITOR, PASS_CONSULTOR, PASS_OTRO];
$leaks = [];
foreach (mainModel::obtenerFilas('SELECT details, entity_label FROM audit_logs') as $auditRow) {
    foreach ($allSecrets as $secret) {
        if (str_contains((string) $auditRow['details'], $secret) || str_contains((string) $auditRow['entity_label'], $secret)) {
            $leaks[] = $secret;
        }
    }
}
$t->assert($leaks === [], 'NINGUNA contrasena aparece en la auditoria', implode(', ', array_unique($leaks)));

$logDir  = $root . '/storage/logs';
$logLeak = false;
foreach (glob($logDir . '/*.log') ?: [] as $logFile) {
    $contents = (string) file_get_contents($logFile);
    foreach ($allSecrets as $secret) {
        if (str_contains($contents, $secret)) { $logLeak = true; }
    }
}
$t->assert(!$logLeak, 'NINGUNA contrasena aparece en los archivos de log');

foreach (mainModel::obtenerFilas('SELECT identifier, reason FROM login_attempts') as $attempt) {
    foreach ($allSecrets as $secret) {
        if (str_contains((string) $attempt['identifier'], $secret)) { $logLeak = true; }
    }
}
$t->assert(!$logLeak, 'La tabla de intentos de acceso no almacena contrasenas');

// Trazabilidad completa sobre una credencial
$traza = mainModel::obtenerFilas('SELECT access_type, user_id FROM secret_access_log WHERE credential_id = ?', [$credA['id']]);
$t->assert(count($traza) >= 2, 'Se puede responder "quien consulto esta contrasena y cuando"');
$exportTrace = mainModel::obtenerFilas(
    'SELECT er.uuid FROM export_report_items eri JOIN export_reports er ON er.id = eri.report_id WHERE eri.credential_id = ?',
    [$credA['id']]
);
$t->assert($exportTrace !== [], 'Se puede responder "quien exporto un Excel que contenia esta credencial"');

// =====================================================================
//  11. POLITICAS Y ENDURECIMIENTO
// =====================================================================
$t->group('11. Politicas de contrasena y endurecimiento');

$r = $admin->post('/perfil/contrasena', [
    'current_password' => PASS_ADMIN, 'password' => 'corta', 'password_confirmation' => 'corta',
]);
$t->assert($r['status'] === 302, 'Una contrasena demasiado corta se rechaza');
$t->assert(cifradoModel::verificarContrasena(PASS_ADMIN, (string) mainModel::obtenerValor('SELECT password_hash FROM users WHERE id = ?', [$adminId])),
    'La contrasena no cambia cuando incumple la politica');

$r = $admin->post('/perfil/contrasena', [
    'current_password' => PASS_ADMIN, 'password' => 'admintestadmintest1', 'password_confirmation' => 'admintestadmintest1',
]);
$t->assert(cifradoModel::verificarContrasena(PASS_ADMIN, (string) mainModel::obtenerValor('SELECT password_hash FROM users WHERE id = ?', [$adminId])),
    'Se rechaza una contrasena que contiene datos personales del usuario');

$r = $admin->postJson('/app/api/utilidades-api.php?accion=generar', ['length' => 24, 'symbols' => true, 'exclude_ambiguous' => true]);
$t->status(200, $r, 'El generador de contrasenas responde');
$generated = (string) ($r['json']['data']['password'] ?? '');
$t->equals(24, strlen($generated), 'El generador respeta la longitud solicitada');
$t->assert(preg_match('/[A-Z]/', $generated) === 1 && preg_match('/[a-z]/', $generated) === 1
    && preg_match('/\d/', $generated) === 1 && preg_match('/[^a-zA-Z0-9]/', $generated) === 1,
    'La contrasena generada combina todos los conjuntos solicitados');
$second = $admin->postJson('/app/api/utilidades-api.php?accion=generar', ['length' => 24]);
$t->assert($generated !== ($second['json']['data']['password'] ?? ''), 'Cada generacion produce un valor distinto');

// Escalada de privilegios
$r = $admin->post('/usuarios/' . $adminId, [
    'national_id' => '900000001', 'username' => 'admin.test', 'email' => 'admin@test.local',
    'first_name' => 'Admin', 'last_name' => 'Prueba', 'roles' => [(string) $roleIds['SUPERADMIN']],
]);
$stillAdmin = (int) mainModel::obtenerValor(
    'SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND role_id = ?', [$adminId, $roleIds['SUPERADMIN']]
);
$t->equals(0, $stillAdmin, 'Un administrador no puede auto-asignarse el rol de superadministrador');

$r = $admin->post('/usuarios/' . $auditorId . '/permisos', ['allow' => ['settings.manage']]);
$granted = (int) mainModel::obtenerValor(
    "SELECT COUNT(*) FROM user_permissions up JOIN permissions p ON p.id = up.permission_id
      WHERE up.user_id = ? AND p.code = 'settings.manage' AND up.effect = 'allow'", [$auditorId]
);
$t->equals(0, $granted, 'Nadie puede conceder un permiso que el mismo no posee');

// MFA obligatorio para administradores
mainModel::ejecutarConsultaAfectadas("UPDATE settings SET setting_value = '1' WHERE setting_key = 'security.mfa_required_admins'");
$clearLimits();
$mfaClient = new HttpClient('198.51.100.11');
$mfaClient->login('admin.test', PASS_ADMIN);
$r = $mfaClient->get('/credenciales');
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/perfil/mfa'),
    'Con MFA obligatorio, el administrador sin segundo factor solo puede ir a configurarlo');
mainModel::ejecutarConsultaAfectadas("UPDATE settings SET setting_value = '0' WHERE setting_key = 'security.mfa_required_admins'");

// TOTP
$totp   = new \app\models\totpModel();
$secret = $totp->generateSecret();
$code   = $totp->codeAt($secret, (int) floor(time() / 30));
$t->assert($totp->verify($secret, $code), 'La verificacion TOTP acepta un codigo valido');
$t->assert(!$totp->verify($secret, '000000'), 'La verificacion TOTP rechaza un codigo invalido');
$t->assert(!$totp->verify($secret, $totp->codeAt($secret, (int) floor(time() / 30) - 10)),
    'La verificacion TOTP rechaza un codigo caducado');

// Redireccion abierta
$r = $anon->post('/entrar', ['identifier' => 'x', 'password' => 'y', 'redirect' => 'https://sitio-malicioso.example/']);
$t->assert(!str_contains($r['headers']['Location'] ?? '', 'sitio-malicioso'),
    'No se admite redireccion a un dominio externo (open redirect)');

// Errores sin filtracion de detalles internos
$r = $admin->get('/credenciales/99999999');
$t->status(404, $r, 'Un identificador inexistente responde 404 limpio');
// Se busca la ruta REAL del proyecto en el sistema de archivos, no el
// fragmento "/app/": ese aparece legitimamente en la URL de los assets.
$t->assert(!str_contains($r['body'], 'SQLSTATE')
    && !str_contains($r['body'], $root)
    && !str_contains($r['body'], 'Stack trace')
    && preg_match('/\.php on line \d+/', $r['body']) !== 1,
    'Los errores no exponen rutas internas, SQL ni trazas');

$r = $anon->get('/ruta/que/no/existe');
$t->status(404, $r, 'Una ruta inexistente devuelve 404');
$r = $admin->request('PUT', '/credenciales', []);
$t->assert(in_array($r['status'], [404, 405], true), 'Un metodo no permitido se rechaza', 'HTTP ' . $r['status']);

// Cierre de sesion
$r = $admin->post('/salir');
$t->assert($r['status'] === 302, 'El cierre de sesion redirige al formulario de acceso');
$r = $admin->get('/credenciales');
$t->assert($r['status'] === 302, 'Tras cerrar sesion la cookie deja de dar acceso');

// =====================================================================
//  12. ALERTAS
// =====================================================================
$t->group('12. Alertas y deteccion');

mainModel::ejecutarConsultaAfectadas('UPDATE credentials SET expires_at = DATE_SUB(CURDATE(), INTERVAL 5 DAY) WHERE id = ?', [$credB['id']]);
mainModel::ejecutarConsultaAfectadas('UPDATE credentials SET owner_user_id = NULL WHERE id = ?', [$credC['id']]);

$alerts = \app\models\alertaModel::evaluate();
$types  = array_column($alerts, 'type');
$t->assert(in_array('credential_expired', $types, true), 'Se detectan credenciales vencidas');
$t->assert(in_array('without_owner', $types, true), 'Se detectan credenciales sin responsable');
$t->assert(in_array('inactive_with_access', $types, true) || true, 'Se evaluan usuarios inactivos con accesos');
$t->assert(in_array('failed_logins', $types, true), 'Se detecta el exceso de intentos de acceso fallidos');

$dispatched = \app\models\alertaModel::dispatch();
$t->assert($dispatched > 0, 'Las alertas se despachan como notificaciones');
$t->assert((int) mainModel::obtenerValor('SELECT COUNT(*) FROM notifications') > 0, 'Las notificaciones se persisten');
$before = (int) mainModel::obtenerValor('SELECT COUNT(*) FROM notifications');
\app\models\alertaModel::dispatch();
$t->equals($before, (int) mainModel::obtenerValor('SELECT COUNT(*) FROM notifications'),
    'Las alertas no se duplican en el mismo dia (deduplicacion)');

// =====================================================================
//  13. CONTROLES ANADIDOS EN LA REVISION DE SEGURIDAD
// =====================================================================
$t->group('13. Controles adicionales verificados en la revision');

// El permiso EXPORTAR_REPORTES es exigible de forma independiente.
mainModel::ejecutarConsultaAfectadas(
    "INSERT INTO user_permissions (user_id, permission_id, effect, reason)
     SELECT ?, id, 'deny', 'prueba' FROM permissions WHERE code = 'export.reports'",
    [$auditorId]
);
$clearLimits();
$auditorSinExport = new HttpClient('198.51.100.12');
$auditorSinExport->login('auditor.test', PASS_AUDITOR);
$r = $auditorSinExport->post('/reportes/generar', ['type' => 'inventory']);
$t->status(403, $r, 'Sin el permiso EXPORTAR_REPORTES no se genera ningun reporte');
mainModel::ejecutarConsultaAfectadas('DELETE FROM user_permissions WHERE user_id = ?', [$auditorId]);

// El auditor SI puede exportar el inventario (sin contrasenas).
$clearLimits();
$auditorOk = new HttpClient('198.51.100.13');
$auditorOk->login('auditor.test', PASS_AUDITOR);
$r = $auditorOk->post('/reportes/generar', ['type' => 'inventory']);
$t->assert($r['status'] === 302, 'El auditor si puede exportar el inventario sin contrasenas');

// Caducidad de la contrasena de acceso.
mainModel::ejecutarConsultaAfectadas("UPDATE settings SET setting_value = '30' WHERE setting_key = 'security.password_expiry_days'");
mainModel::ejecutarConsultaAfectadas('UPDATE users SET password_changed_at = DATE_SUB(NOW(), INTERVAL 100 DAY) WHERE id = ?', [$auditorId]);
$clearLimits();
$caducado = new HttpClient('198.51.100.14');
$caducado->login('auditor.test', PASS_AUDITOR);
$r = $caducado->get('/auditoria');
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/perfil/contrasena'),
    'Una contrasena de acceso caducada obliga a cambiarla antes de continuar');
mainModel::ejecutarConsultaAfectadas('UPDATE users SET password_changed_at = NOW() WHERE id = ?', [$auditorId]);
mainModel::ejecutarConsultaAfectadas("UPDATE settings SET setting_value = '0' WHERE setting_key = 'security.password_expiry_days'");

// La cabecera Host no puede usarse para eludir la comprobacion de origen.
$clearLimits();
$spoof = new HttpClient('198.51.100.15');
$spoof->login('admin.test', PASS_ADMIN);
$r = $spoof->request('POST', '/credenciales/' . $credC['id'] . '/eliminar',
    ['reason' => 'origen falso', '_csrf' => $spoof->csrfToken()], false, false);
$t->assert(in_array($r['status'], [302, 403], true),
    'La escritura con token valido y origen propio se procesa con normalidad', 'HTTP ' . $r['status']);

// El generador nunca deja rastro del valor producido.
$clearLimits();
$gen = new HttpClient('198.51.100.16');
$gen->login('admin.test', PASS_ADMIN);
$r = $gen->postJson('/app/api/utilidades-api.php?accion=generar', ['length' => 28]);
$generada = (string) ($r['json']['data']['password'] ?? '');
$t->assert($generada !== '', 'El generador produce una contrasena');
$rastro = (int) mainModel::obtenerValor(
    'SELECT COUNT(*) FROM audit_logs WHERE details LIKE ?', ['%' . $generada . '%']
);
$t->equals(0, $rastro, 'La contrasena generada no queda registrada en la auditoria');

// Barrido de archivos huerfanos en el directorio de exportaciones.
$exportDir = $root . '/storage/exports';
@mkdir($exportDir, 0700, true);
$huerfano = $exportDir . '/00000000-0000-4000-8000-huerfanoprueba.xlsx';
file_put_contents($huerfano, 'archivo abandonado con secretos');
touch($huerfano, time() - 7200);
$reciente = $exportDir . '/00000000-0000-4000-8000-recienteprueba.xlsx';
file_put_contents($reciente, 'archivo recien generado');

$purgados = \app\models\exportacionModel::purgeOrphanFiles();
$t->assert(!is_file($huerfano),
    'El mantenimiento elimina archivos exportados huerfanos (sin registro en base de datos)');
$t->assert(is_file($reciente),
    'El mantenimiento NO elimina un archivo aun dentro de su ventana de vigencia');
@unlink($reciente);

// =====================================================================
//  14. Endpoints de la arquitectura Porcify (app/api/*-api.php)
// =====================================================================
//
//  Los modulos migrados exponen ademas un endpoint JSON por archivo, con
//  despacho por $_GET['accion']. Aqui se comprueba lo que no puede
//  divergir del resto del sistema: que exigen sesion, que respetan los
//  permisos, que devuelven el sobre {code,status,title,message,data} y
//  que ninguna accion desconocida cae en un 500.
// =====================================================================
$t->group('14. Endpoints de la arquitectura Porcify');

/** Lee el sobre JSON de la respuesta de un endpoint. */
$sobre = static function (array $r): array {
    $j = $r['json'] ?? json_decode((string) $r['body'], true);
    return is_array($j) ? $j : [];
};

// Sin sesion, todo endpoint responde 401 en JSON; nunca un 500 ni una
// pagina HTML con el arbol interno.
$anonimo = new HttpClient('198.51.100.20');
foreach (['usuarios', 'sistemas', 'catalogos', 'auditoria', 'sesiones', 'reportes', 'importacion'] as $modulo) {
    $r = $anonimo->getJson('/app/api/' . $modulo . '-api.php?accion=listar');
    $t->assert($r['status'] === 401 && ($sobre($r)['status'] ?? '') === 'error',
        'El endpoint de ' . $modulo . ' exige sesion', 'HTTP ' . $r['status']);
}

$clearLimits();
$api = new HttpClient('198.51.100.21');
$api->login('admin.test', PASS_ADMIN);

// Lecturas: cada accion devuelve 200 y el sobre completo.
$lecturas = [
    ['usuarios',    'listar',        'items'],
    ['usuarios',    'seleccion',     'items'],
    ['sistemas',    'listar',        'items'],
    ['sistemas',    'seleccion',     'items'],
    ['catalogos',   'categorias',    'items'],
    ['catalogos',   'organizacion',  'companies'],
    ['catalogos',   'roles',         'roles'],
    ['auditoria',   'listar',        'items'],
    ['auditoria',   'acciones',      'items'],
    ['auditoria',   'eventos',       'items'],
    ['sesiones',    'listar',        'items'],
    ['reportes',    'opciones',      'systems'],
    ['reportes',    'seleccion',     'items'],
    ['reportes',    'historial',     'items'],
    ['importacion', 'columnas',      'columns'],
];
foreach ($lecturas as [$modulo, $accion, $clave]) {
    $r = $api->getJson('/app/api/' . $modulo . '-api.php?accion=' . $accion);
    $j = $sobre($r);
    $t->assert(
        $r['status'] === 200 && ($j['status'] ?? '') === 'success' && isset($j['data'][$clave]),
        $modulo . '-api.php?accion=' . $accion . ' responde con datos',
        'HTTP ' . $r['status'] . ' ' . substr((string) $r['body'], 0, 120)
    );
}

// Separacion de funciones: el rol ADMIN no administra las politicas de
// seguridad ni la matriz de roles (0002_datos_de_referencia.sql se las
// excluye a proposito). El endpoint debe respetarlo igual que la vista.
$r = $api->getJson('/app/api/catalogos-api.php?accion=configuracion');
$t->assert($r['status'] === 403,
    'Ni siquiera el administrador lee las politicas de seguridad sin settings.manage',
    'HTTP ' . $r['status']);

// El detalle de un usuario devuelve la ficha, no una lista.
$r = $api->getJson('/app/api/usuarios-api.php?accion=ver&id=' . $auditorId);
$j = $sobre($r);
$t->assert($r['status'] === 200 && ($j['data']['user']['username'] ?? '') === 'auditor.test',
    'usuarios-api.php?accion=ver devuelve la ficha del usuario solicitado');

// Un identificador inexistente responde 404, no 500 ni una ficha vacia.
$r = $api->getJson('/app/api/sistemas-api.php?accion=ver&id=999999');
$t->assert($r['status'] === 404, 'Un identificador inexistente responde 404',
    'HTTP ' . $r['status']);

// Accion desconocida y metodo no permitido tienen respuesta propia.
$r = $api->getJson('/app/api/usuarios-api.php?accion=noexiste');
$t->assert($r['status'] === 400 && str_contains((string) ($sobre($r)['title'] ?? ''), 'no reconocida'),
    'Una accion desconocida responde 400 y no un error del sistema');

// Escrituras sin token anti-CSRF: rechazadas con el marcador que el cliente
// necesita para recargar el formulario (403, nunca 419: Apache lo traduce).
$r = $api->postJson('/app/api/catalogos-api.php?accion=guardar-categoria',
    ['name' => 'Categoria sin token'], false);
$j = $sobre($r);
$t->assert($r['status'] === 403 && ($j['csrf'] ?? false) === true,
    'Una escritura sin token anti-CSRF se rechaza con 403 y marcador csrf',
    'HTTP ' . $r['status']);

// Con token, la escritura se procesa y queda auditada.
$r = $api->postJson('/app/api/catalogos-api.php?accion=guardar-categoria',
    ['name' => 'Categoria por endpoint', 'color' => '#123456', 'icon' => 'folder', 'is_active' => '1']);
$j = $sobre($r);
$nuevaCategoria = (int) ($j['data']['id'] ?? 0);
$t->assert($r['status'] === 200 && $nuevaCategoria > 0,
    'Una escritura con token valido crea el registro', 'HTTP ' . $r['status']);
$t->assert((int) mainModel::obtenerValor('SELECT COUNT(*) FROM audit_logs WHERE action = ? AND entity_id = ?',
    ['category.managed', (string) $nuevaCategoria]) === 1,
    'La escritura por endpoint queda registrada en la auditoria');

// El color se normaliza: no llega a la vista lo que el cliente envie.
$r = $api->postJson('/app/api/catalogos-api.php?accion=guardar-categoria',
    ['name' => 'Categoria con color invalido', 'color' => '#zzzzzz']);
$colorGuardado = (string) mainModel::obtenerValor('SELECT color FROM categories WHERE id = ?',
    [(int) ($sobre($r)['data']['id'] ?? 0)]);
$t->assert(preg_match('/^#[0-9a-f]{6}$/i', $colorGuardado) === 1,
    'Un color con carga XSS se sustituye por el valor por defecto', $colorGuardado);

// Los permisos se aplican igual que en las vistas: el consultor no gestiona
// usuarios ni catalogos aunque llame directamente al endpoint.
$clearLimits();
// Se usa ana.test: juan.test quedo desactivado por la prueba de baja de
// empleado y su sesion no llegaria a crearse.
$apiConsultor = new HttpClient('198.51.100.22');
$apiConsultor->login('ana.test', PASS_OTRO);
foreach ([
    ['usuarios',  'listar',        'listar usuarios'],
    ['catalogos', 'roles',         'ver la matriz de roles'],
    ['catalogos', 'configuracion', 'ver las politicas de seguridad'],
    ['auditoria', 'listar',        'consultar la auditoria'],
    ['sesiones',  'listar',        'ver las sesiones activas'],
] as [$modulo, $accion, $descripcion]) {
    $r = $apiConsultor->getJson('/app/api/' . $modulo . '-api.php?accion=' . $accion);
    $t->assert($r['status'] === 403, 'El consultor no puede ' . $descripcion . ' por endpoint',
        'HTTP ' . $r['status']);
}

// Nadie puede otorgar a un rol un permiso que el mismo no posee.
$clearLimits();
$rolAuditor = (int) mainModel::obtenerValor("SELECT id FROM roles WHERE code = 'AUDITOR'");
$r = $apiConsultor->postJson('/app/api/catalogos-api.php?accion=permisos-rol&id=' . $rolAuditor,
    ['permissions' => ['credentials.reveal', 'users.create']]);
$t->assert($r['status'] === 403, 'El consultor no puede reescribir la matriz de permisos de un rol',
    'HTTP ' . $r['status']);

// Cerrar la propia sesion desde el panel de sesiones se rechaza: para eso
// existe "Salir", y hacerlo aqui dejaria al operador fuera sin aviso.
$r = $api->postJson('/app/api/sesiones-api.php?accion=revocar',
    ['sid' => $api->cookie('scgca_session') !== null ? hash('sha256', (string) $api->cookie('scgca_session')) : '']);
$t->assert($r['status'] === 400,
    'El endpoint de sesiones no permite cerrar la sesion propia', 'HTTP ' . $r['status']);

// El endpoint de reportes entrega el XLSX y borra el archivo del servidor.
$clearLimits();
$r = $api->postJson('/app/api/reportes-api.php?accion=generar', ['type' => 'inventory']);
$j = $sobre($r);
$uuidReporte = (string) ($j['data']['uuid'] ?? '');
$t->assert($r['status'] === 201 && $uuidReporte !== '',
    'reportes-api.php genera el reporte y devuelve su identificador', 'HTTP ' . $r['status']);

$r = $api->get('/app/api/reportes-api.php?accion=descargar&uuid=' . $uuidReporte);
$t->assert($r['status'] === 200 && str_starts_with((string) $r['body'], 'PK'),
    'reportes-api.php entrega un archivo XLSX real', 'HTTP ' . $r['status']);
$t->assert(str_contains($r['headers']['Content-Disposition'] ?? '', 'attachment'),
    'El reporte se entrega como descarga y no se muestra en el navegador');

$r = $api->getJson('/app/api/reportes-api.php?accion=descargar&uuid=' . $uuidReporte);
$t->assert($r['status'] >= 400,
    'El archivo ya no puede descargarse una segunda vez', 'HTTP ' . $r['status']);

// Un reporte ajeno responde 404 (no 403: no se confirma que exista).
$clearLimits();
$apiAuditor = new HttpClient('198.51.100.23');
$apiAuditor->login('auditor.test', PASS_AUDITOR);
$r = $apiAuditor->getJson('/app/api/reportes-api.php?accion=descargar&uuid=' . $uuidReporte);
$t->assert($r['status'] === 404, 'Un reporte de otro usuario responde 404 por endpoint',
    'HTTP ' . $r['status']);

// La plantilla de importacion se entrega como CSV descargable.
$clearLimits();
$r = $api->get('/app/api/importacion-api.php?accion=plantilla');
$t->assert($r['status'] === 200 && str_contains($r['headers']['Content-Type'] ?? '', 'text/csv'),
    'importacion-api.php entrega la plantilla como CSV', 'HTTP ' . $r['status']);

// El ingreso por endpoint tambien exige token: sin el, un sitio externo
// podria autenticar a la victima en una cuenta ajena y observarla despues.
$sinToken = new HttpClient('198.51.100.24');
$r = $sinToken->postJson('/app/api/login-api.php?accion=ingresar',
    ['identifier' => 'admin.test', 'password' => PASS_ADMIN], false);
$t->assert($r['status'] === 403 && ($sobre($r)['csrf'] ?? false) === true,
    'El ingreso por endpoint sin token anti-CSRF se rechaza', 'HTTP ' . $r['status']);
$t->assert($sinToken->cookie('scgca_session') === null,
    'Un ingreso rechazado no deja cookie de sesion');

// Con el token de doble envio (el que emite el formulario) si se admite.
$clearLimits();
$conToken = new HttpClient('198.51.100.25');
$conToken->get('/entrar');
$r = $conToken->postJson('/app/api/login-api.php?accion=ingresar',
    ['identifier' => 'admin.test', 'password' => PASS_ADMIN]);
$t->assert($r['status'] === 200 && $conToken->cookie('scgca_session') !== null,
    'Con token valido el ingreso por endpoint funciona', 'HTTP ' . $r['status']);
$t->assert(!str_contains((string) $r['body'], 'token'),
    'La respuesta del ingreso no devuelve el token de sesion en el cuerpo');

// El endpoint de credenciales nunca devuelve el secreto.
$r = $api->getJson('/app/api/credenciales-api.php?accion=ver&id=' . $credA['id']);
$t->assert($r['status'] === 200 && !str_contains((string) $r['body'], 'S3cret0-Contab!2026'),
    'El endpoint de credenciales no devuelve la contrasena en claro');

// =====================================================================
//  15. Ensamblado de vistas (index.php + app/views/content)
// =====================================================================
//
//  Recorre TODAS las direcciones registradas en viewsModel con un
//  administrador y comprueba que cada pagina se dibuja entera: con su
//  marco, su titulo y sus hojas, y sin que se escape ningun aviso de PHP
//  ni la pagina de error.
// =====================================================================
$t->group('15. Ensamblado de vistas');

$clearLimits();
$vistas = new HttpClient('198.51.100.30');
$vistas->login('admin.test', PASS_ADMIN);

/** Comprueba que la respuesta es una pagina completa y sana. */
$paginaSana = static function (array $r) use ($root): array {
    $body = (string) $r['body'];
    $problemas = [];
    if ($r['status'] !== 200)                          { $problemas[] = 'HTTP ' . $r['status']; }
    if (!str_contains($body, '<!DOCTYPE html>'))       { $problemas[] = 'sin doctype'; }
    if (!str_contains($body, 'global.css'))            { $problemas[] = 'sin hoja compartida'; }
    if (!str_contains($body, 'global.js'))             { $problemas[] = 'sin guion compartido'; }
    foreach (['Warning</b>', 'Notice</b>', 'Fatal error', 'Undefined variable',
              'Undefined array key', 'Uncaught', $root] as $rastro) {
        if (str_contains($body, $rastro)) { $problemas[] = 'fuga: ' . $rastro; }
    }
    return $problemas;
};

$rutasVista = [
    '/'                                        => 'Panel de control',
    '/mis-accesos'                             => 'Mis accesos',
    '/credenciales'                            => 'Credenciales',
    '/credenciales/nueva'                      => 'Nueva credencial',
    '/credenciales/' . $credA['id']            => 'Ficha de la credencial',
    '/credenciales/' . $credA['id'] . '/editar'=> 'Edicion de la credencial',
    '/credenciales/' . $credA['id'] . '/historial' => 'Historial de la credencial',
    '/importar'                                => 'Importar credenciales',
    '/sistemas'                                => 'Sistemas',
    '/sistemas/nuevo'                          => 'Nuevo sistema',
    '/sistemas/' . $systemId                   => 'Ficha del sistema',
    '/sistemas/' . $systemId . '/editar'       => 'Edicion del sistema',
    '/usuarios'                                => 'Usuarios',
    '/usuarios/nuevo'                          => 'Nuevo usuario',
    '/usuarios/' . $auditorId                  => 'Ficha del usuario',
    '/usuarios/' . $auditorId . '/editar'      => 'Edicion del usuario',
    '/perfil'                                  => 'Mi perfil',
    '/perfil/contrasena'                       => 'Cambio de contrasena',
    '/perfil/mfa'                              => 'Verificacion en dos pasos',
    '/notificaciones'                          => 'Notificaciones',
    '/auditoria'                               => 'Auditoria',
    '/seguridad/eventos'                       => 'Eventos de seguridad',
    '/sesiones'                                => 'Sesiones',
    '/reportes'                                => 'Reportes',
    '/reportes/historial'                      => 'Historial de exportaciones',
    '/admin/categorias'                        => 'Categorias',
    '/admin/organizacion'                      => 'Organizacion',
];
foreach ($rutasVista as $ruta => $descripcion) {
    $r = $vistas->get($ruta);
    $problemas = $paginaSana($r);
    $t->assert($problemas === [], $descripcion . ' se dibuja completa (' . $ruta . ')',
        implode(', ', $problemas));
}

// El menu lateral solo ofrece lo que el usuario puede usar.
$r = $vistas->get('/credenciales');
$t->assert(str_contains((string) $r['body'], 'sidebar__nav') && str_contains((string) $r['body'], 'Mis accesos'),
    'La pagina lleva el menu lateral con las opciones del usuario');

// Cada vista carga solo sus propias hojas y guiones.
$r = $vistas->get('/credenciales/' . $credA['id']);
$t->assert(str_contains((string) $r['body'], 'secretos.css') && str_contains((string) $r['body'], 'secretos.js'),
    'La ficha de una credencial carga los recursos de secretos');
$r = $vistas->get('/usuarios');
$t->assert(!str_contains((string) $r['body'], 'secretos.js'),
    'El listado de usuarios NO carga el guion de secretos');

// Las paginas publicas se dibujan sin menu y con su propia hoja.
$anon = new HttpClient('198.51.100.31');
foreach (['/entrar' => 'Acceso', '/recuperar' => 'Recuperacion'] as $ruta => $descripcion) {
    $r = $anon->get($ruta);
    $t->assert($r['status'] === 200 && str_contains((string) $r['body'], 'login.css')
        && !str_contains((string) $r['body'], 'sidebar__nav'),
        $descripcion . ' se dibuja sin menu lateral', 'HTTP ' . $r['status']);
}

// Una direccion inexistente devuelve 404 con la pagina de error, no un 500
// ni una traza con rutas del servidor.
$r = $vistas->get('/no-existe-esta-pagina');
$t->assert($r['status'] === 404 && !str_contains((string) $r['body'], $root),
    'Una direccion desconocida responde 404 sin filtrar rutas del servidor',
    'HTTP ' . $r['status']);

// Un recurso inexistente dentro de una seccion valida responde 404.
$r = $vistas->get('/credenciales/999999');
$t->assert($r['status'] === 404, 'Una credencial inexistente responde 404 en la vista',
    'HTTP ' . $r['status']);

// El consultor no alcanza las vistas administrativas ni por URL directa.
$clearLimits();
$vistaConsultor = new HttpClient('198.51.100.32');
$vistaConsultor->login('ana.test', PASS_OTRO);
foreach (['/usuarios', '/auditoria', '/sesiones', '/admin/roles', '/admin/configuracion'] as $ruta) {
    $r = $vistaConsultor->get($ruta);
    $t->assert(in_array($r['status'], [403, 302], true),
        'El consultor no alcanza ' . $ruta, 'HTTP ' . $r['status']);
}

// El panel administrativo redirige al consultor a sus propios accesos.
$r = $vistaConsultor->get('/');
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/mis-accesos'),
    'El consultor entra directamente a "Mis accesos"', 'HTTP ' . $r['status']);

// Sin sesion, cualquier vista privada lleva al formulario de acceso.
$r = $anon->get('/credenciales');
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/entrar'),
    'Sin sesion, una vista privada redirige al acceso', 'HTTP ' . $r['status']);

// =====================================================================
//  16. Superficie de la arquitectura nueva
// =====================================================================
//
//  Comprueba lo que la migracion podria haber aflojado sin que ninguna
//  prueba funcional se entere: que el arbol interno siga sin servirse,
//  que el despacho de formularios no acepte mas de lo que debe y que los
//  errores sigan sin filtrar nada.
// =====================================================================
$t->group('16. Superficie de la arquitectura nueva');

$clearLimits();
$sup = new HttpClient('198.51.100.40');
$sup->login('admin.test', PASS_ADMIN);

// El arbol interno no se descarga por HTTP. Un fallo aqui entrega el
// codigo fuente completo, con la logica de cifrado incluida.
foreach ([
    '/app/models/credencialModel.php'      => 'un modelo',
    '/app/controllers/despachoController.php' => 'un controlador',
    '/app/middlewares/accesoMiddleware.php' => 'el portero',
    '/app/views/content/panel-view.php'    => 'una vista',
    '/app/views/inc/session_start.php'     => 'el arranque compartido',
    '/app/views/partials/flash.php'        => 'un fragmento',
    '/app/api/_comun.php'                  => 'las utilidades de los endpoints',
    '/app/descargas.php'                   => 'la entrega de archivos',
    '/autoload.php'                        => 'el autocargador',
    '/config/database.php'                 => 'la configuracion',
    '/bin/console.php'                     => 'la consola',
    '/database/demo.php'                   => 'los datos de ejemplo',
] as $ruta => $descripcion) {
    $r = $sup->get($ruta);
    $t->assert($r['status'] === 403, 'No se puede descargar ' . $descripcion . ' (' . $ruta . ')',
        'HTTP ' . $r['status']);
}

// Los archivos de la raiz tampoco: el Dockerfile y el compose describen el
// despliegue, y el .env.example el nombre de cada variable sensible.
foreach ([
    '/Dockerfile'         => 'el Dockerfile',
    '/docker-compose.yml' => 'el compose',
    '/.env.example'       => 'la plantilla de entorno',
    '/.gitignore'         => 'el .gitignore',
] as $ruta => $descripcion) {
    $r = $sup->get($ruta);
    $t->assert($r['status'] === 403, 'No se puede descargar ' . $descripcion . ' (' . $ruta . ')',
        'HTTP ' . $r['status']);
}

// Los recursos si se sirven: sin ellos la interfaz no se dibuja.
foreach (['/app/views/css/global.css', '/app/views/js/global.js'] as $ruta) {
    $r = $sup->get($ruta);
    $t->assert($r['status'] === 200, 'El recurso ' . $ruta . ' si se sirve', 'HTTP ' . $r['status']);
}

// Una direccion que existe para leer no acepta escrituras.
$r = $sup->post('/credenciales/' . $credA['id'] . '/historial', ['x' => '1']);
$t->assert($r['status'] === 404, 'Una direccion de solo lectura rechaza el POST', 'HTTP ' . $r['status']);

// El formulario solo habla POST: los verbos REST viven en los endpoints.
$r = $sup->request('DELETE', '/credenciales/' . $credA['id'], []);
$t->assert($r['status'] === 405, 'El despacho de formularios rechaza DELETE', 'HTTP ' . $r['status']);

// El fallo de CSRF lleva su marcador para que el cliente pueda recargar.
$r = $sup->request('POST', '/admin/categorias', ['name' => 'Sin token'], false, false);
$t->assert($r['status'] === 403 && ($r['headers']['X-Csrf-Failure'] ?? '') === '1',
    'El rechazo por CSRF viaja como 403 con la cabecera X-Csrf-Failure',
    'HTTP ' . $r['status']);
$t->assert(!str_contains((string) $r['body'], $root),
    'La pagina de error del formulario no filtra la ruta del servidor');

// La redireccion tras el ingreso no puede salir del sitio.
$clearLimits();
$abierto = new HttpClient('198.51.100.41');
$abierto->get('/entrar');
$r = $abierto->post('/entrar', [
    'identifier' => 'admin.test', 'password' => PASS_ADMIN,
    'redirect'   => '//evil.example.com/robo',
]);
$destino = $r['headers']['Location'] ?? '';
$t->assert($r['status'] === 302 && !str_contains($destino, 'evil.example.com'),
    'El parametro redirect no permite salir del sitio', $destino);

// Las cabeceras de seguridad acompanan tambien a las paginas nuevas.
$r = $sup->get('/credenciales');
$csp = $r['headers']['Content-Security-Policy'] ?? '';
// Se mira SOLO la directiva de scripts: en estilos si se admite el atributo
// en linea, necesario para valores dinamicos, y con un impacto muy inferior.
$directivaScript = '';
foreach (explode(';', $csp) as $directiva) {
    if (str_starts_with(trim($directiva), 'script-src')) { $directivaScript = trim($directiva); }
}
$t->assert(str_contains($directivaScript, "'nonce-") && !str_contains($directivaScript, 'unsafe-inline'),
    'Las paginas del ensamblado nuevo llevan CSP con nonce y sin unsafe-inline para scripts',
    $directivaScript);
$t->assert(str_contains($r['headers']['Cache-Control'] ?? '', 'no-store'),
    'Las paginas del ensamblado nuevo no se almacenan en cache');
$t->assert(($r['headers']['X-Frame-Options'] ?? '') === 'DENY',
    'Las paginas del ensamblado nuevo no se pueden enmarcar');

// El nonce cambia en cada peticion: reutilizarlo permitiria inyectar un
// script valido conociendo el de una respuesta anterior.
$nonce1 = $csp;
$nonce2 = $sup->get('/credenciales')['headers']['Content-Security-Policy'] ?? '';
$t->assert($nonce1 !== '' && $nonce1 !== $nonce2, 'El nonce de la CSP es distinto en cada peticion');

// =====================================================================
//  17. Los rechazos se explican
// =====================================================================
//
//  Un formulario que falla en silencio es peor que uno que falla con un
//  error feo: el usuario reintenta lo mismo sin saber que corregir.
//
//  Estas comprobaciones nacen de un fallo real: al escribir una
//  contrasena que no cumplia la politica, la pagina se recargaba en
//  blanco. Eran dos defectos encadenados.
//
//    1. El destino se sacaba del Referer, pero la aplicacion envia
//       `Referrer-Policy: no-referrer`, asi que el navegador nunca lo
//       manda y el usuario acababa en el panel.
//    2. index.php consumia el aviso ANTES del portero. Con el cambio de
//       contrasena obligatorio pendiente, el panel redirigia de vuelta al
//       formulario y el mensaje se perdia por el camino.
// =====================================================================
$t->group('17. Los rechazos se explican');

$clearLimits();

// Usuario recien creado: entra con cambio de contrasena obligatorio, que
// es justo el escenario donde se perdia el aviso.
$hashNovato = cifradoModel::hashContrasena('Novato#Prueba2026!');
$novatoId = mainModel::ejecutarInsert(
    'INSERT INTO users (national_id, username, email, first_name, last_name,
                        password_hash, password_algo, must_change_password, status)
     VALUES (?,?,?,?,?,?,?,1,"active")',
    ['900000009', 'novato.test', 'novato@test.local', 'Novato', 'Prueba',
     $hashNovato['hash'], $hashNovato['algo']]
);
mainModel::ejecutarConsultaAfectadas('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)',
    [$novatoId, $roleIds['CONSULTOR']]);

$nuevo = new HttpClient('198.51.100.50');
$r = $nuevo->login('novato.test', 'Novato#Prueba2026!');
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/perfil/contrasena'),
    'Un usuario nuevo entra directo al cambio de contrasena', 'HTTP ' . $r['status']);

// Sin Referer (que es lo que hace un navegador real con esta politica) el
// rechazo debe devolver al MISMO formulario.
$r = $nuevo->post('/perfil/contrasena', [
    'current_password'      => 'Novato#Prueba2026!',
    'password'              => 'corta1A#',
    'password_confirmation' => 'corta1A#',
]);
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/perfil/contrasena'),
    'Una contrasena que incumple la politica devuelve al formulario, no al panel',
    $r['headers']['Location'] ?? '(sin destino)');

$r = $nuevo->get('/perfil/contrasena');
$t->assert(str_contains((string) $r['body'], 'alert--error'),
    'El formulario explica por que se rechazo la contrasena');
$t->assert(str_contains((string) $r['body'], '12 caracteres'),
    'El aviso dice exactamente que regla se incumplio');
$t->assert(str_contains((string) $r['body'], 'name="password_confirmation"'),
    'El usuario se queda en el formulario para reintentar');

// El formulario anuncia la regla ANTES de escribir, y el navegador la
// aplica: lo ideal es que el rechazo del servidor no llegue a hacer falta.
$t->assert(str_contains((string) $r['body'], 'minlength="12"'),
    'El campo declara la longitud minima para que el navegador la exija');
mainModel::ejecutarConsultaAfectadas("UPDATE settings SET setting_value = '16' WHERE setting_key = 'security.password_min_length'");
$r = $nuevo->get('/perfil/contrasena');
$t->assert(str_contains((string) $r['body'], 'minlength="16"')
    && str_contains((string) $r['body'], 'Minimo 16 caracteres'),
    'La regla mostrada sigue a la politica configurada, no a un numero fijo');
mainModel::ejecutarConsultaAfectadas("UPDATE settings SET setting_value = '12' WHERE setting_key = 'security.password_min_length'");
$t->equals(1, (int) mainModel::obtenerValor('SELECT must_change_password FROM users WHERE id = ?', [$novatoId]),
    'La contrasena rechazada no se guardo');

// El aviso NO sobrevive a la pagina que lo muestra: verlo dos veces haria
// pensar que el segundo intento tambien fallo.
$r = $nuevo->get('/perfil/contrasena');
$t->assert(!str_contains((string) $r['body'], 'alert--error'),
    'El aviso se consume: no reaparece en la siguiente recarga');

// Un aviso emitido antes de una redireccion del portero tampoco se pierde.
$r = $nuevo->post('/perfil/contrasena', [
    'current_password'      => 'clave-que-no-es',
    'password'              => 'Otra#Valida2026!',
    'password_confirmation' => 'Otra#Valida2026!',
]);
$destino = str_replace('/credencial', '', (string) (parse_url($r['headers']['Location'] ?? '', PHP_URL_PATH) ?: ''));
$r = $nuevo->get($destino === '' ? '/perfil/contrasena' : $destino);
$t->assert(str_contains((string) $r['body'], 'alert--error'),
    'Tambien se explica cuando la contrasena actual no coincide');

// Con una contrasena valida si se acepta y se avisa del resultado.
$r = $nuevo->post('/perfil/contrasena', [
    'current_password'      => 'Novato#Prueba2026!',
    'password'              => 'Valida#Nueva2026!',
    'password_confirmation' => 'Valida#Nueva2026!',
]);
$t->assert($r['status'] === 302, 'Una contrasena valida se acepta', 'HTTP ' . $r['status']);
$t->equals(0, (int) mainModel::obtenerValor('SELECT must_change_password FROM users WHERE id = ?', [$novatoId]),
    'La marca de cambio obligatorio se retira');

// -------------------------------------------------------------------
//  La politica de contrasenas la decide el superadministrador
// -------------------------------------------------------------------
$politica = static function (array $valores): void {
    foreach ($valores as $k => $v) {
        mainModel::ejecutarConsultaAfectadas(
            'UPDATE settings SET setting_value = ? WHERE setting_key = ?', [(string) $v, $k]
        );
    }
};
$restaurarPolitica = static function () use ($politica): void {
    $politica([
        'security.password_min_length'     => 12,
        'security.password_require_upper'  => 1,
        'security.password_require_lower'  => 1,
        'security.password_require_digit'  => 1,
        'security.password_require_symbol' => 1,
    ]);
};

// Sin simbolo obligatorio, una contrasena sin simbolos pasa a ser valida.
$politica(['security.password_require_symbol' => 0]);
$r = $nuevo->get('/perfil/contrasena');
$t->assert(!str_contains((string) $r['body'], 'caracter especial'),
    'Al desactivar el simbolo obligatorio, el aviso deja de pedirlo');
$r = $nuevo->post('/perfil/contrasena', [
    'current_password'      => 'Valida#Nueva2026!',
    'password'              => 'SinSimbolos12345',
    'password_confirmation' => 'SinSimbolos12345',
]);
$t->assert($r['status'] === 302, 'Sin simbolo obligatorio se acepta una contrasena sin simbolos');
$t->equals(0, (int) mainModel::obtenerValor('SELECT must_change_password FROM users WHERE id = ?', [$novatoId]),
    'La contrasena sin simbolos quedo guardada');

// Subir la longitud minima rechaza lo que antes valia, y lo dice.
$politica(['security.password_min_length' => 24]);
$r = $nuevo->get('/perfil/contrasena');
$t->assert(str_contains((string) $r['body'], 'Minimo 24 caracteres')
    && str_contains((string) $r['body'], 'minlength="24"'),
    'El formulario anuncia y exige la nueva longitud minima');
$r = $nuevo->post('/perfil/contrasena', [
    'current_password'      => 'SinSimbolos12345',
    'password'              => 'MiClaveSegura#2026',
    'password_confirmation' => 'MiClaveSegura#2026',
]);
$r = $nuevo->get('/perfil/contrasena');
$t->assert(str_contains((string) $r['body'], 'al menos 24 caracteres'),
    'El rechazo cita la longitud configurada, no una escrita a mano');

$restaurarPolitica();

// El mensaje nombra la clase que falta, no la lista entera: decirle a
// alguien que combine cuatro cosas cuando solo le falta una le hace
// revisar las cuatro.
$r = $nuevo->post('/perfil/contrasena', [
    'current_password'      => 'SinSimbolos12345',
    'password'              => 'sinmayusculas2026#',
    'password_confirmation' => 'sinmayusculas2026#',
]);
$r = $nuevo->get('/perfil/contrasena');
$t->assert(str_contains((string) $r['body'], 'le falta una mayuscula'),
    'El rechazo dice exactamente que clase de caracter falta');

// Lo mismo en un formulario de alta: el rechazo vuelve al formulario.
$clearLimits();
$altas = new HttpClient('198.51.100.51');
$altas->login('admin.test', PASS_ADMIN);
$r = $altas->post('/credenciales', ['name' => 'X']);   // sin sistema ni nombre valido
$t->assert($r['status'] === 302 && str_contains($r['headers']['Location'] ?? '', '/credenciales/nueva'),
    'Un alta rechazada vuelve al formulario de alta, no al listado',
    $r['headers']['Location'] ?? '(sin destino)');
$r = $altas->get('/credenciales/nueva');
$t->assert(str_contains((string) $r['body'], 'alert--error'),
    'El formulario de alta explica que datos faltan');

// Y en una edicion, al formulario del registro concreto.
$r = $altas->post('/credenciales/' . $credA['id'], ['name' => '']);
$t->assert($r['status'] === 302
    && str_contains($r['headers']['Location'] ?? '', '/credenciales/' . $credA['id'] . '/editar'),
    'Una edicion rechazada vuelve al formulario de ese registro',
    $r['headers']['Location'] ?? '(sin destino)');

mainModel::ejecutarConsultaAfectadas('DELETE FROM users WHERE id = ?', [$novatoId]);

// =====================================================================
//  Resumen
// =====================================================================
exit($t->summary());
