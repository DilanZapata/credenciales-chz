<?php
declare(strict_types=1);

/**
 * Consola de administracion del sistema.
 *
 *   php bin/console.php key:generate      Genera la clave maestra en .env
 *   php bin/console.php install           Crea la base de datos, el esquema y el superadministrador
 *   php bin/console.php migrate           Aplica el esquema y los datos de referencia
 *   php bin/console.php seed:demo         Carga datos de ejemplo (solo entornos de prueba)
 *   php bin/console.php user:create       Crea un usuario de forma interactiva
 *   php bin/console.php alerts:run        Evalua y despacha las alertas
 *   php bin/console.php maintenance       Purga exportaciones, sesiones y limitadores vencidos
 *   php bin/console.php key:rotate        Rota la version del llavero y re-cifra los secretos
 *   php bin/console.php doctor            Diagnostico de la instalacion
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Esta herramienta solo puede ejecutarse desde la linea de comandos.\n");
}

$root = dirname(__DIR__);

use App\Core\Config;
use App\Core\Env;
use App\Support\Migrator;
use app\models\alertaModel;
use app\models\autenticacionModel;
use app\models\cifradoModel;
use app\models\correoConfigModel;
use app\models\correoModel;
use app\models\exportacionModel;
use app\models\generadorModel;
use app\models\limitadorModel;
use app\models\mainModel;
use app\models\sesionModel;
use app\models\usuarioModel;

function out(string $message = ''): void   { echo $message . PHP_EOL; }
function ok(string $message): void         { echo "\033[32m✔\033[0m " . $message . PHP_EOL; }
function warn(string $message): void       { echo "\033[33m!\033[0m " . $message . PHP_EOL; }
function fail(string $message): void       { echo "\033[31m✘\033[0m " . $message . PHP_EOL; }
function title(string $message): void      { echo PHP_EOL . "\033[1m" . $message . "\033[0m" . PHP_EOL . str_repeat('-', 62) . PHP_EOL; }

function ask(string $question, string $default = '', bool $hidden = false): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    echo $question . $suffix . ': ';
    if ($hidden && DIRECTORY_SEPARATOR !== '\\') {
        @shell_exec('stty -echo');
        $answer = trim((string) fgets(STDIN));
        @shell_exec('stty echo');
        echo PHP_EOL;
    } else {
        $answer = trim((string) fgets(STDIN));
    }
    return $answer === '' ? $default : $answer;
}

$command = $argv[1] ?? 'help';

// --------------------------------------------------------------------
//  key:generate  (no requiere base de datos)
// --------------------------------------------------------------------
if ($command === 'key:generate') {
    $envPath = $root . '/.env';
    $master  = base64_encode(random_bytes(32));
    $pepper  = base64_encode(random_bytes(32));

    if (!is_file($envPath)) {
        $example = $root . '/.env.example';
        if (is_file($example)) {
            copy($example, $envPath);
        } else {
            file_put_contents($envPath, "APP_ENV=production\n");
        }
    }
    $contents = (string) file_get_contents($envPath);

    foreach (['APP_MASTER_KEY' => $master, 'APP_PEPPER' => $pepper] as $key => $value) {
        if (preg_match('/^' . $key . '=(.*)$/m', $contents, $m) === 1) {
            if (trim($m[1]) !== '') {
                warn($key . ' ya tiene valor. NO se sobrescribe: rotarla invalidaria los secretos existentes.');
                continue;
            }
            $contents = preg_replace('/^' . $key . '=.*$/m', $key . '=' . $value, $contents) ?? $contents;
        } else {
            $contents .= PHP_EOL . $key . '=' . $value;
        }
        ok($key . ' generada.');
    }
    file_put_contents($envPath, $contents);
    chmod($envPath, 0600);
    ok('.env actualizado con permisos 0600.');
    out();
    warn('GUARDE UNA COPIA SEGURA DE APP_MASTER_KEY.');
    warn('Sin esa clave los secretos cifrados NO se pueden recuperar. Nadie, ni el desarrollador.');
    exit(0);
}

// Arranque comun: autocarga, entorno y configuracion. La consola no
// necesita contexto de peticion ni cabeceras HTTP.
require_once $root . '/autoload.php';
require_once $root . '/app/Support/helpers.php';

Env::load($root . '/.env');
Config::loadDir($root . '/config');
date_default_timezone_set((string) Config::get('app.timezone', 'America/Bogota'));
mb_internal_encoding('UTF-8');
\App\Core\Logger::setDirectory((string) Config::get('paths.logs'));
\App\Core\View::setPath((string) Config::get('paths.views'));

// --------------------------------------------------------------------
//  install / migrate
// --------------------------------------------------------------------
if ($command === 'install' || $command === 'migrate') {
    title('Instalacion del Sistema de Gestion de Credenciales');

    if (!Env::has('APP_MASTER_KEY')) {
        fail('Falta APP_MASTER_KEY. Ejecute primero: php bin/console.php key:generate');
        exit(1);
    }

    $cfg      = Config::get('database');
    $database = (string) $cfg['database'];

    // Conexion sin base de datos seleccionada para poder crearla.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $raiz = !empty($cfg['socket'])
            ? new mysqli($cfg['host'], $cfg['username'], (string) $cfg['password'], null, (int) $cfg['port'], $cfg['socket'])
            : new mysqli($cfg['host'], $cfg['username'], (string) $cfg['password'], null, (int) $cfg['port']);
    } catch (Throwable $e) {
        fail('No fue posible conectar con MySQL/MariaDB. Revise config/database.php y el .env.');
        exit(1);
    }

    $raiz->query(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        str_replace('`', '', $database)
    ));
    $raiz->close();
    ok('Base de datos `' . $database . '` disponible.');

    $migrator = new Migrator($root . '/database/migrations');

    $drift = $migrator->drifted();
    if ($drift !== []) {
        warn('Migraciones ya aplicadas que fueron modificadas despues: ' . implode(', ', $drift));
        warn('Los entornos pueden haber divergido. Cree una migracion nueva en lugar de editar una existente.');
    }

    $pending = $migrator->pending();
    if ($pending === []) {
        ok('La base de datos ya esta al dia (sin migraciones pendientes).');
    } else {
        out('Migraciones pendientes: ' . count($pending));
        try {
            $migrator->run(static function (string $file, int $statements): void {
                ok($file . '  (' . $statements . ' sentencias)');
            });
        } catch (RuntimeException $e) {
            fail($e->getMessage());
            exit(1);
        }
    }

    $version = cifradoModel::versionClaveActiva();
    ok('Llavero de cifrado activo, version ' . $version . '.');

    if ($command === 'migrate') {
        ok('Migracion completada.');
        exit(0);
    }

    // ---------------- Superadministrador ----------------
    $existing = (int) mainModel::obtenerValor('SELECT COUNT(*) FROM users');
    if ($existing > 0) {
        warn('Ya existen usuarios. No se crea el superadministrador.');
        ok('Instalacion completada.');
        exit(0);
    }

    title('Creacion del superadministrador');
    $nationalId = ask('Cedula / documento', '1000000000');
    $firstName  = ask('Nombres', 'Administrador');
    $lastName   = ask('Apellidos', 'del Sistema');
    $email      = ask('Correo corporativo', 'admin@empresa.local');
    $username   = ask('Nombre de usuario', 'admin');
    $company    = ask('Nombre de la empresa', (string) Config::get('app.organization', 'Mi Empresa'));

    $password  = generadorModel::generate(['length' => 20, 'exclude_ambiguous' => true]);

    $companyId = mainModel::ejecutarInsert('INSERT INTO companies (name) VALUES (?)', [$company]);
    $hash      = cifradoModel::hashContrasena($password);

    $userId = mainModel::ejecutarInsert(
        'INSERT INTO users (national_id, username, email, first_name, last_name, company_id,
                            password_hash, password_algo, password_changed_at, must_change_password, status, mfa_enforced)
         VALUES (?,?,?,?,?,?,?,?,NOW(),1,"active",1)',
        [$nationalId, strtolower($username), strtolower($email), $firstName, $lastName, $companyId, $hash['hash'], $hash['algo']]
    );
    $roleId = (int) mainModel::obtenerValor("SELECT id FROM roles WHERE code = 'SUPERADMIN'");
    mainModel::ejecutarConsultaAfectadas('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [$userId, $roleId]);

    mainModel::ejecutarInsert(
        'INSERT INTO audit_logs (user_id, actor_national_id, actor_name, action, entity_type, entity_id,
                                 entity_label, result, severity, ip_address, details)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [$userId, $nationalId, $firstName . ' ' . $lastName, 'system.installed', 'system', null,
         'Instalacion inicial', 'success', 'critical', '127.0.0.1',
         json_encode(['superadministrador' => $username], JSON_UNESCAPED_UNICODE)]
    );

    out();
    ok('Instalacion completada.');
    out();
    out('  Usuario:    ' . strtolower($username));
    out('  Contrasena: ' . $password);
    out();
    warn('Anote la contrasena AHORA: no vuelve a mostrarse y debera cambiarla al ingresar.');
    warn('El superadministrador tiene MFA obligatorio: configurelo en su primer acceso.');
    exit(0);
}

// --------------------------------------------------------------------
//  Resto de comandos (requieren instalacion previa)
// --------------------------------------------------------------------

switch ($command) {

    case 'alerts:run':
        $count  = alertaModel::dispatch();
        ok($count . ' tipo(s) de alerta despachados.');
        break;

    case 'maintenance':
        title('Mantenimiento');
        ok(exportacionModel::purgeExpiredFiles() . ' archivo(s) de exportacion purgados.');
        ok(sesionModel::purgarExpiradas() . ' sesion(es) marcadas como expiradas.');
        ok(limitadorModel::purgarVencidos() . ' cubo(s) de limitacion liberados.');
        mainModel::ejecutarConsultaAfectadas('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 180 DAY)');
        mainModel::ejecutarConsultaAfectadas('DELETE FROM password_resets WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)');
        ok('Registros temporales antiguos eliminados (la auditoria se conserva integra).');
        break;

    case 'key:rotate':
        title('Rotacion del llavero de cifrado');
        warn('Se creara una nueva version de clave y se re-cifraran TODOS los secretos.');
        if (strtolower(ask('Escriba "SI" para continuar')) !== 'si') {
            out('Cancelado.');
            break;
        }
        $newVersion = cifradoModel::crearVersionClave();
        ok('Nueva version de clave: ' . $newVersion);

        $rows    = mainModel::obtenerFilas('SELECT * FROM credential_secrets ORDER BY id');
        $rotated = 0;
        $failed  = 0;
        foreach ($rows as $row) {
            $aad = cifradoModel::aad('credential', (int) $row['credential_id'], (string) $row['field'], (int) $row['version']);
            $new = cifradoModel::reenvolver($row, $aad);
            if ($new === null) { $failed++; continue; }
            mainModel::ejecutarConsultaAfectadas(
                'UPDATE credential_secrets
                    SET key_version = ?, ciphertext = ?, nonce = ?, tag = ?, wrapped_dek = ?, dek_nonce = ?, dek_tag = ?
                  WHERE id = ?',
                [$new['key_version'], $new['ciphertext'], $new['nonce'], $new['tag'],
                 $new['wrapped_dek'], $new['dek_nonce'], $new['dek_tag'], (int) $row['id']]
            );
            $rotated++;
        }
        foreach (mainModel::obtenerFilas('SELECT * FROM mfa_secrets') as $row) {
            $aad = cifradoModel::aad('user', (int) $row['user_id'], 'mfa_secret');
            $new = cifradoModel::reenvolver($row, $aad);
            if ($new === null) { $failed++; continue; }
            mainModel::ejecutarConsultaAfectadas(
                'UPDATE mfa_secrets
                    SET key_version = ?, ciphertext = ?, nonce = ?, tag = ?, wrapped_dek = ?, dek_nonce = ?, dek_tag = ?
                  WHERE user_id = ?',
                [$new['key_version'], $new['ciphertext'], $new['nonce'], $new['tag'],
                 $new['wrapped_dek'], $new['dek_nonce'], $new['dek_tag'], (int) $row['user_id']]
            );
            $rotated++;
        }
        // La contrasena del buzon SMTP es un secreto mas y tiene que
        // viajar con el resto del llavero, o se quedaria atada a una
        // version de clave antigua.
        $correo = mainModel::obtenerFila('SELECT * FROM mail_config WHERE id = 1 AND key_version IS NOT NULL');
        if ($correo !== null) {
            $new = cifradoModel::reenvolver($correo, cifradoModel::aad('mail_config', 1, 'smtp_password'));
            if ($new === null) {
                $failed++;
            } else {
                mainModel::ejecutarConsultaAfectadas(
                    'UPDATE mail_config
                        SET key_version = ?, ciphertext = ?, nonce = ?, tag = ?, wrapped_dek = ?, dek_nonce = ?, dek_tag = ?
                      WHERE id = 1',
                    [$new['key_version'], $new['ciphertext'], $new['nonce'], $new['tag'],
                     $new['wrapped_dek'], $new['dek_nonce'], $new['dek_tag']]
                );
                $rotated++;
            }
        }

        ok($rotated . ' secreto(s) re-cifrados.');
        if ($failed > 0) { fail($failed . ' secreto(s) NO pudieron re-cifrarse. Revise la clave maestra.'); }
        break;

    case 'user:create':
        title('Crear usuario');

        $nationalId = ask('Cedula');
        $username   = strtolower(ask('Usuario'));
        $email      = strtolower(ask('Correo'));
        $firstName  = ask('Nombres');
        $lastName   = ask('Apellidos');

        out('Roles disponibles:');
        foreach (mainModel::obtenerFilas('SELECT id, code, name FROM roles ORDER BY level DESC') as $role) {
            out('  ' . $role['id'] . ') ' . $role['name'] . ' (' . $role['code'] . ')');
        }
        $roleId = (int) ask('Id del rol', '4');

        $password = generadorModel::generate(['length' => 18, 'exclude_ambiguous' => true]);
        $hash     = cifradoModel::hashContrasena($password);
        $userId   = usuarioModel::crearRegistro([
            'national_id'   => $nationalId, 'username' => $username, 'email' => $email,
            'first_name'    => $firstName,  'last_name' => $lastName,
            'password_hash' => $hash['hash'], 'password_algo' => $hash['algo'],
        ]);
        mainModel::ejecutarConsultaAfectadas('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [$userId, $roleId]);

        ok('Usuario creado con id ' . $userId);
        out('  Contrasena temporal: ' . $password);
        break;

    /**
     * Primer superadministrador, sin preguntas.
     *
     * `install` y `user:create` piden los datos por teclado, lo que no
     * sirve dentro de un contenedor: el arranque no tiene a nadie
     * delante. Sin esto, un despliegue queda con el esquema creado y
     * ningun usuario, es decir, con la puerta cerrada por fuera.
     *
     * Es idempotente a proposito: si ya existe algun usuario no toca
     * nada, para que un redespliegue no clone administradores.
     */
    case 'user:bootstrap':
        $existentes = (int) mainModel::obtenerValor('SELECT COUNT(*) FROM users');
        if ($existentes > 0) {
            ok('Ya existen ' . $existentes . ' usuario(s): no se crea ninguno.');
            break;
        }

        title('Creacion del primer superadministrador');

        $dominio = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST) ?: 'local';
        $datos = [
            'national_id' => getenv('ADMIN_NATIONAL_ID') ?: '1000000000',
            'username'    => strtolower(getenv('ADMIN_USERNAME') ?: 'admin'),
            'email'       => strtolower(getenv('ADMIN_EMAIL') ?: ('admin@' . $dominio)),
            'first_name'  => getenv('ADMIN_FIRST_NAME') ?: 'Administrador',
            'last_name'   => getenv('ADMIN_LAST_NAME') ?: 'del Sistema',
        ];

        // La contrasena NUNCA se toma del entorno: quedaria escrita en el
        // panel de despliegue y en las variables del contenedor para
        // siempre. Se genera una temporal de un solo uso.
        $clave = generadorModel::generate(['length' => 20, 'exclude_ambiguous' => true]);
        $hash  = cifradoModel::hashContrasena($clave);

        $rol = (int) mainModel::obtenerValor("SELECT id FROM roles WHERE code = 'SUPERADMIN'");
        if ($rol === 0) {
            fail('No existe el rol SUPERADMIN. Aplique primero las migraciones.');
            exit(1);
        }

        $idNuevo = mainModel::ejecutarInsert(
            'INSERT INTO users (national_id, username, email, first_name, last_name,
                                password_hash, password_algo, must_change_password, status)
             VALUES (?,?,?,?,?,?,?,1,"active")',
            [$datos['national_id'], $datos['username'], $datos['email'],
             $datos['first_name'], $datos['last_name'], $hash['hash'], $hash['algo']]
        );
        mainModel::ejecutarConsultaAfectadas(
            'INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [$idNuevo, $rol]
        );

        out();
        out(str_repeat('=', 64));
        out('  PRIMER ACCESO AL SISTEMA');
        out(str_repeat('=', 64));
        out('  Usuario:    ' . $datos['username'] . '   (o la cedula ' . $datos['national_id'] . ')');
        out('  Contrasena: ' . $clave);
        out(str_repeat('=', 64));
        warn('Esta contrasena se muestra UNA sola vez y solo aparece aqui.');
        warn('Debera cambiarla al entrar. Borre este registro cuando lo haga.');
        out();
        break;

    /**
     * Restablece la contrasena de un usuario desde la consola.
     *
     * Salida de emergencia: si se pierde la contrasena temporal del primer
     * acceso, no hay otra forma de entrar. El restablecimiento por correo
     * exige un servidor de correo configurado, y en una instalacion recien
     * desplegada normalmente todavia no lo esta.
     *
     *   php bin/console.php user:reset admin
     */
    case 'user:reset':
        $identificador = $argv[2] ?? (getenv('ADMIN_USERNAME') ?: '');
        if ($identificador === '') {
            fail('Indique el usuario o la cedula:  php bin/console.php user:reset <usuario>');
            exit(1);
        }

        $fila = mainModel::obtenerFila(
            'SELECT id, username, national_id, status FROM users
             WHERE username = ? OR national_id = ? OR email = ? LIMIT 1',
            [$identificador, $identificador, $identificador]
        );
        if ($fila === null) {
            fail('No existe ningun usuario con "' . $identificador . '".');
            exit(1);
        }

        $clave = generadorModel::generate(['length' => 20, 'exclude_ambiguous' => true]);
        $hash  = cifradoModel::hashContrasena($clave);

        usuarioModel::updatePassword((int) $fila['id'], $hash['hash'], $hash['algo'], true);
        usuarioModel::clearLock((int) $fila['id']);
        // Los frenos acumulados por los intentos fallidos se liberan: si no,
        // la contrasena nueva se rechazaria igual durante varios minutos.
        mainModel::ejecutarConsultaAfectadas("DELETE FROM rate_limits WHERE bucket LIKE 'login:%'");
        // Las sesiones abiertas dejan de valer: si alguien entro con la
        // contrasena perdida, este restablecimiento lo echa fuera.
        sesionModel::revocarTodasDeUsuario((int) $fila['id'], null, 'restablecimiento desde consola');

        if ($fila['status'] !== 'active') {
            warn('El usuario esta en estado "' . $fila['status'] . '" y no podra entrar hasta reactivarlo.');
        }

        out();
        out(str_repeat('=', 64));
        out('  CONTRASENA RESTABLECIDA');
        out(str_repeat('=', 64));
        out('  Usuario:    ' . $fila['username'] . '   (o la cedula ' . $fila['national_id'] . ')');
        out('  Contrasena: ' . $clave);
        out(str_repeat('=', 64));
        warn('Se muestra UNA sola vez. Debera cambiarla al entrar.');
        out();
        break;

    /**
     * Desactiva el segundo factor de un usuario desde la consola.
     *
     * Segunda salida de emergencia. Restablecer la contrasena no basta si
     * la cuenta exige TOTP: sin el telefono ni los codigos de respaldo, el
     * acceso queda cerrado de forma definitiva. Esto lo reabre.
     *
     *   php bin/console.php user:mfa-off admin
     */
    case 'user:mfa-off':
        $identificador = $argv[2] ?? '';
        if ($identificador === '') {
            fail('Indique el usuario:  php bin/console.php user:mfa-off <usuario>');
            exit(1);
        }

        $fila = mainModel::obtenerFila(
            'SELECT id, username, mfa_enabled FROM users
             WHERE username = ? OR national_id = ? OR email = ? LIMIT 1',
            [$identificador, $identificador, $identificador]
        );
        if ($fila === null) {
            fail('No existe ningun usuario con "' . $identificador . '".');
            exit(1);
        }

        autenticacionModel::disableMfa((int) $fila['id'], (int) $fila['id']);
        sesionModel::revocarTodasDeUsuario((int) $fila['id'], null, 'segundo factor desactivado desde consola');

        ok('Segundo factor desactivado para "' . $fila['username'] . '".');
        out('  Vuelva a configurarlo desde Mi perfil en cuanto recupere el acceso.');

        // Si el rol lo exige, el portero le obligara a configurarlo de nuevo
        // en el siguiente ingreso; queda avisado para que no le sorprenda.
        if (autenticacionModel::userRequiresMfa((int) $fila['id'])) {
            warn('Su rol exige segundo factor: al entrar se le pedira configurarlo otra vez.');
            out('  Para no exigirlo, desactive "security.mfa_required_admins" en Configuracion.');
        }
        out();
        break;

    case 'doctor':
        title('Diagnostico de la instalacion');
        $checks = [
            'PHP >= 8.1'                  => version_compare(PHP_VERSION, '8.1.0', '>='),
            'Extension openssl'           => extension_loaded('openssl'),
            'AES-256-GCM disponible'      => in_array('aes-256-gcm', openssl_get_cipher_methods(), true),
            'Extension mysqli'            => extension_loaded('mysqli'),
            'Extension pdo_mysql'         => extension_loaded('pdo_mysql'),
            'Extension zip (Excel)'       => class_exists('ZipArchive'),
            'Extension mbstring'          => extension_loaded('mbstring'),
            'APP_MASTER_KEY definida'     => Env::has('APP_MASTER_KEY'),
            '.env existe y no es escribible por terceros' =>
                is_file($root . '/.env') && (fileperms($root . '/.env') & 0022) === 0,
            'storage escribible'          => is_writable($root . '/storage'),
            'exports fuera del webroot'   => !str_starts_with((string) Config::get('paths.exports'), $root . '/public'),
            'display_errors desactivado'  => ini_get('display_errors') === '0' || Config::get('app.debug') === true,
            'Conexion a la base de datos' => (function (): bool {
                try { mainModel::obtenerValor('SELECT 1'); return true; } catch (Throwable) { return false; }
            })(),
        ];
        foreach ($checks as $label => $passed) {
            $passed ? ok($label) : fail($label);
        }
        // El .env contiene la clave maestra: solo el usuario del servicio web
        // deberia poder leerlo. En XAMPP suele quedar legible por todos.
        $envPerms = is_file($root . '/.env') ? (fileperms($root . '/.env') & 0777) : 0;
        if ($envPerms !== 0600 && $envPerms !== 0400) {
            warn(sprintf('.env tiene permisos 0%o. En produccion ejecute (como root):', $envPerms));
            out('    chown <usuario-del-servidor-web> ' . $root . '/.env && chmod 400 ' . $root . '/.env');
        }

        $secrets = (int) mainModel::obtenerValor('SELECT COUNT(*) FROM credential_secrets');
        $plain   = 0;
        foreach (mainModel::obtenerFilas('SELECT ciphertext FROM credential_secrets LIMIT 200') as $row) {
            $value = is_resource($row['ciphertext']) ? stream_get_contents($row['ciphertext']) : (string) $row['ciphertext'];
            if (mb_check_encoding($value, 'UTF-8') && preg_match('/^[\x20-\x7E]{4,}$/', $value) === 1) {
                $plain++;
            }
        }
        out();
        ok($secrets . ' secreto(s) almacenados.');
        $plain === 0
            ? ok('Ningun secreto presenta aspecto de texto plano.')
            : fail($plain . ' secreto(s) podrian no estar cifrados. REVISAR DE INMEDIATO.');

        // Sin correo saliente la recuperacion de contrasena no llega a
        // nadie, y el sistema no da ninguna senal de ello: quien la pide ve
        // igualmente el mensaje de confirmacion.
        out();
        if (correoConfigModel::habilitado()) {
            $correo = correoConfigModel::obtener();
            ok('Correo saliente habilitado (' . $correo['host'] . ':' . $correo['port'] . ').');
            if ($correo['last_test_at'] !== null && (int) $correo['last_test_ok'] !== 1) {
                warn('La ultima prueba de envio fallo: ' . (string) $correo['last_test_error']);
            }
        } else {
            warn('Correo saliente DESHABILITADO: el enlace de recuperacion de contrasena no puede enviarse.');
            out('    Configurelo en Administracion > Correo y pruebelo con: php bin/console.php mail:test <correo>');
        }
        break;

    /**
     * Prueba el servidor de correo saliente desde la consola.
     *
     * Util cuando todavia no se puede entrar al panel: si el enlace de
     * recuperacion no llega, esto dice exactamente en que paso falla.
     *
     *   php bin/console.php mail:test alguien@dominio.com
     */
    case 'mail:test':
        title('Prueba del servidor de correo saliente');

        $destino = $argv[2] ?? '';
        if ($destino === '') {
            fail('Indique la direccion de destino:  php bin/console.php mail:test <correo>');
            exit(1);
        }

        $cfg = correoConfigModel::obtener();
        out('  Servidor:  ' . (($cfg['host'] ?? '') !== '' ? $cfg['host'] . ':' . $cfg['port'] : '(sin configurar)'));
        out('  Cifrado:   ' . (string) $cfg['encryption']);
        out('  Usuario:   ' . (($cfg['username'] ?? '') !== '' ? (string) $cfg['username'] : '(sin autenticacion)'));
        out('  Remitente: ' . (string) $cfg['from_email']);
        out('  Estado:    ' . (correoConfigModel::habilitado() ? 'habilitado' : 'DESHABILITADO'));
        out();

        try {
            correoModel::enviarPrueba($destino, 'consola');
            correoConfigModel::registrarPrueba(true, null);
            ok('Mensaje entregado al servidor. Revise la bandeja de ' . $destino . '.');
        } catch (Throwable $e) {
            correoConfigModel::registrarPrueba(false, $e->getMessage());
            fail('El envio fallo: ' . $e->getMessage());
            out();
            warn('Con Gmail: smtp.gmail.com, puerto 587, cifrado tls, y una contrasena');
            warn('de aplicacion generada en la cuenta de Google (no la contrasena normal).');
            exit(1);
        }
        break;

    case 'seed:demo':
        require $root . '/database/demo.php';
        break;

    case 'migrate:status':
        title('Estado de las migraciones');
        $migrator = new Migrator($root . '/database/migrations');
        $applied  = $migrator->applied();
        foreach ($migrator->available() as $m) {
            $record = $applied[$m['version']] ?? null;
            if ($record === null) {
                warn(sprintf('%-40s PENDIENTE', $m['filename']));
            } elseif ($record['checksum'] !== $m['checksum']) {
                fail(sprintf('%-40s MODIFICADA DESPUES DE APLICARSE (%s)', $m['filename'], $record['applied_at']));
            } else {
                ok(sprintf('%-40s aplicada %s', $m['filename'], $record['applied_at']));
            }
        }
        out();
        out('Pendientes: ' . count($migrator->pending()));
        break;

    case 'db:clean':
        title('Limpieza de datos operativos');
        warn('Se ELIMINARAN: empresas, sedes, departamentos, sistemas, credenciales,');
        warn('secretos, asignaciones, historial, auditoria, sesiones, notificaciones,');
        warn('exportaciones y TODOS los usuarios excepto un superadministrador.');
        out();
        out('Se CONSERVAN: roles, permisos, categorias, parametros y el llavero de cifrado.');
        out();

        $force = in_array('--force', $argv, true);
        if (!$force && strtoupper(ask('Escriba LIMPIAR para confirmar')) !== 'LIMPIAR') {
            out('Cancelado. No se modifico nada.');
            break;
        }

        // Se conserva el superadministrador de menor id; si no existe, se aborta
        // para no dejar el sistema sin ninguna cuenta de acceso.
        $keep = mainModel::obtenerFila(
            "SELECT u.id, u.username, u.national_id, u.email, u.first_name, u.last_name
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id
              WHERE r.code = 'SUPERADMIN'
              ORDER BY u.id LIMIT 1"
        );
        if ($keep === null) {
            fail('No hay ningun superadministrador. Ejecute primero: php bin/console.php install');
            break;
        }
        $keepId = (int) $keep['id'];

        // TRUNCATE provoca un commit implicito en MySQL, de modo que no puede
        // envolverse en una transaccion: se ejecuta de forma secuencial con
        // las comprobaciones de clave ajena desactivadas.
        mainModel::ejecutarConsulta('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'export_report_items', 'export_reports', 'secret_access_log', 'credential_history',
            'credential_assignments', 'credential_secrets', 'credentials', 'systems',
            'audit_logs', 'login_attempts', 'security_events', 'notifications',
            'password_resets', 'mfa_backup_codes', 'mfa_secrets', 'rate_limits',
            'departments', 'locations', 'companies',
        ] as $table) {
            mainModel::ejecutarConsulta('TRUNCATE TABLE `' . $table . '`');
        }
        mainModel::ejecutarConsultaAfectadas('DELETE FROM sessions WHERE user_id <> ?', [$keepId]);
        mainModel::ejecutarConsultaAfectadas('DELETE FROM user_permissions WHERE user_id <> ?', [$keepId]);
        mainModel::ejecutarConsultaAfectadas('DELETE FROM user_roles WHERE user_id <> ?', [$keepId]);
        mainModel::ejecutarConsultaAfectadas('DELETE FROM users WHERE id <> ?', [$keepId]);
        mainModel::ejecutarConsulta('SET FOREIGN_KEY_CHECKS = 1');

        // Contrasena temporal nueva para el superadministrador conservado.
        $plain  = generadorModel::generate(['length' => 20, 'exclude_ambiguous' => true]);
        $hash   = cifradoModel::hashContrasena($plain);
        mainModel::ejecutarConsultaAfectadas(
            'UPDATE users SET password_hash = ?, password_algo = ?, password_changed_at = NOW(),
                              must_change_password = 1, company_id = NULL, location_id = NULL,
                              department_id = NULL, failed_attempts = 0, locked_until = NULL,
                              mfa_enabled = 0
              WHERE id = ?',
            [$hash['hash'], $hash['algo'], $keepId]
        );
        mainModel::ejecutarConsultaAfectadas('UPDATE sessions SET status = ? WHERE status = ?', ['revoked', 'active']);

        out();
        ok('Base de datos limpia.');
        out();
        out('  Usuario:    ' . $keep['username']);
        out('  Contrasena: ' . $plain);
        out();
        warn('Anotela: no vuelve a mostrarse y debera cambiarla al ingresar.');
        break;

    default:
        out('Comandos disponibles:');
        out('  key:generate    Genera APP_MASTER_KEY y APP_PEPPER en .env');
        out('  install         Instalacion completa (base de datos + superadministrador)');
        out('  migrate         Aplica las migraciones pendientes');
        out('  migrate:status  Muestra que migraciones estan aplicadas y cuales faltan');
        out('  seed:demo       Carga datos de ejemplo (NO usar en produccion)');
        out('  db:clean        Borra datos operativos y deja solo el superadministrador');
        out('  user:create     Crea un usuario (interactivo)');
        out('  user:bootstrap  Crea el primer superadministrador sin preguntas (contenedores)');
        out('  user:reset      Restablece la contrasena de un usuario: user:reset <usuario>');
        out('  user:mfa-off    Desactiva el segundo factor: user:mfa-off <usuario>');
        out('  mail:test       Prueba el servidor de correo: mail:test <correo>');
        out('  alerts:run      Evalua y despacha alertas (programar en cron)');
        out('  maintenance     Purga archivos, sesiones y limitadores vencidos (cron)');
        out('  key:rotate      Rota el llavero y re-cifra los secretos');
        out('  doctor          Diagnostico de la instalacion');
        break;
}
