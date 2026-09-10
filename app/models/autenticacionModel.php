<?php
declare(strict_types=1);

namespace app\models;

use App\Core\HttpException;
use App\Core\ValidationException;

/**
 * Modelo de dominio (patron de Porcify Manager: la logica de negocio vive
 * en el modelo, junto al acceso a datos).
 */
class autenticacionModel extends mainModel
{

    // =================================================================
    //  Logica de negocio (fusionada desde AuthService.php)
    // =================================================================

    private const GENERIC_ERROR = 'Las credenciales proporcionadas no son validas.';


    /**
     * @return array{status:string,message?:string,session?:array,token?:string,user?:array}
     */
    public static function attemptLogin(string $identifier, string $password, string $ip, string $userAgent, string $device): array
    {
        $identifier = trim($identifier);

        // --- Freno 1: limitador de frecuencia por IP y por identificador ---
        $ipBucket   = 'login:ip:' . $ip;
        $userBucket = 'login:id:' . mb_strtolower($identifier);
        if (!limitadorModel::intentar($ipBucket, 20, 300) || !limitadorModel::intentar($userBucket, 10, 300)) {
            auditoriaModel::registrarIntentoAcceso($identifier, null, 'locked', 'rate_limited');
            auditoriaModel::registrar(
                auditoriaModel::LOGIN_BLOCKED, 'user', null, $identifier, 'denied',
                ['motivo' => 'limite de intentos por IP/identificador'], 'warning'
            );
            auditoriaModel::eventoSeguridad(
                'brute_force', 'Exceso de intentos de inicio de sesion',
                'Se bloqueo temporalmente el acceso desde ' . $ip, 'high'
            );
            throw HttpException::tooManyRequests(
                'Demasiados intentos. Espere ' . max(1, (int) ceil(limitadorModel::reintentarEn($ipBucket) / 60)) . ' minuto(s).'
            );
        }

        $record = $identifier === '' ? null : usuarioModel::findAuthRecord($identifier);

        if ($record === null) {
            // Trabajo equivalente para no filtrar la existencia de la cuenta.
            cifradoModel::verificarContrasena($password, '$2y$12$usuarioinexistenteusuarioinexistenteusuarioinexiste12345678');
            auditoriaModel::registrarIntentoAcceso($identifier, null, 'failed', 'unknown_identifier');
            return ['status' => 'error', 'message' => self::GENERIC_ERROR];
        }

        $userId = (int) $record['id'];

        // --- Cuenta bloqueada temporalmente ---
        if ($record['locked_until'] !== null && strtotime((string) $record['locked_until']) > time()) {
            auditoriaModel::registrarIntentoAcceso($identifier, $userId, 'locked', 'account_locked');
            auditoriaModel::registrar(auditoriaModel::LOGIN_BLOCKED, 'user', $userId, $record['username'], 'denied',
                ['motivo' => 'cuenta bloqueada temporalmente'], 'warning', $userId,
                $record['first_name'] . ' ' . $record['last_name'], $record['national_id']);
            $minutes = max(1, (int) ceil((strtotime((string) $record['locked_until']) - time()) / 60));
            return ['status' => 'error', 'message' => "La cuenta esta bloqueada temporalmente. Intente en {$minutes} minuto(s)."];
        }

        // --- Verificacion de la contrasena ---
        if (!cifradoModel::verificarContrasena($password, (string) $record['password_hash'])) {
            $maxAttempts = configuracionModel::entero('security.max_login_attempts', 5);
            $lockMinutes = configuracionModel::entero('security.lockout_minutes', 15);
            $attempts    = usuarioModel::incrementFailedAttempts($userId, $maxAttempts, $lockMinutes);

            auditoriaModel::registrarIntentoAcceso($identifier, $userId, 'failed', 'bad_password');
            auditoriaModel::registrar(auditoriaModel::LOGIN_FAILED, 'user', $userId, $record['username'], 'failure',
                ['intentos_fallidos' => $attempts], 'notice', $userId,
                $record['first_name'] . ' ' . $record['last_name'], $record['national_id']);

            if ($attempts >= $maxAttempts) {
                auditoriaModel::eventoSeguridad(
                    'account_locked',
                    'Cuenta bloqueada por intentos fallidos',
                    sprintf('El usuario %s supero %d intentos fallidos.', $record['username'], $maxAttempts),
                    'high',
                    $userId
                );
            }
            return ['status' => 'error', 'message' => self::GENERIC_ERROR];
        }

        // --- Estado de la cuenta (despues de validar la clave, para no filtrar estados) ---
        if ($record['status'] !== 'active') {
            auditoriaModel::registrarIntentoAcceso($identifier, $userId, 'failed', 'status_' . $record['status']);
            auditoriaModel::registrar(auditoriaModel::LOGIN_BLOCKED, 'user', $userId, $record['username'], 'denied',
                ['estado' => $record['status']], 'warning', $userId,
                $record['first_name'] . ' ' . $record['last_name'], $record['national_id']);
            return ['status' => 'error', 'message' => 'Su cuenta no se encuentra activa. Contacte al administrador.'];
        }

        // Credenciales correctas: se limpian los frenos.
        limitadorModel::limpiar($userBucket);
        usuarioModel::clearLock($userId);

        // Re-hash oportunista si cambiaron los parametros de coste.
        if (cifradoModel::requiereRehash((string) $record['password_hash'])) {
            $new = cifradoModel::hashContrasena($password);
            usuarioModel::updatePassword($userId, $new['hash'], $new['algo'], (bool) $record['must_change_password']);
        }

        // --- MFA obligatorio? ---
        $requiresMfa = self::userRequiresMfa($userId, (bool) $record['mfa_enforced']);
        $hasMfa      = (bool) $record['mfa_enabled'];
        $pendingMfa  = $hasMfa;

        $created = sesionModel::crear($userId, $ip, $userAgent, $device, $pendingMfa);
        usuarioModel::registerSuccessfulLogin($userId, $ip);
        auditoriaModel::registrarIntentoAcceso($identifier, $userId, $pendingMfa ? 'mfa_required' : 'success');

        $user = usuarioModel::find($userId) ?? [];

        if ($pendingMfa) {
            auditoriaModel::registrar(auditoriaModel::MFA_CHALLENGE, 'user', $userId, $record['username'], 'success',
                [], 'info', $userId, $record['first_name'] . ' ' . $record['last_name'], $record['national_id']);
            return [
                'status'  => 'mfa_required',
                'session' => $created['session'],
                'token'   => $created['token'],
                'user'    => $user,
            ];
        }

        auditoriaModel::registrar(auditoriaModel::LOGIN, 'user', $userId, $record['username'], 'success',
            ['dispositivo' => $device], 'info', $userId,
            $record['first_name'] . ' ' . $record['last_name'], $record['national_id']);

        return [
            'status'               => $requiresMfa && !$hasMfa ? 'mfa_setup_required' : 'ok',
            'session'              => $created['session'],
            'token'                => $created['token'],
            'user'                 => $user,
            'must_change_password' => (bool) $record['must_change_password'],
        ];
    }

    /** Un rol con requires_mfa=1 o la marca individual obligan al segundo factor. */
    public static function userRequiresMfa(int $userId, bool $enforcedFlag = false): bool
    {
        if ($enforcedFlag) {
            return true;
        }
        if (!configuracionModel::booleano('security.mfa_required_admins', true)) {
            return false;
        }
        return (int) self::obtenerValor(
            'SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = ? AND r.requires_mfa = 1',
            [$userId]
        ) > 0;
    }

    /**
     * Segundo factor. Acepta codigo TOTP o codigo de respaldo de un solo uso.
     *
     * @return array{status:string,message?:string,token?:string,session?:array}
     */
    public static function verifyMfa(array $session, string $code): array
    {
        $userId = (int) $session['user_id'];
        $bucket = 'mfa:' . $userId;
        if (!limitadorModel::intentar($bucket, 8, 300)) {
            auditoriaModel::eventoSeguridad('mfa_brute_force', 'Multiples codigos MFA incorrectos',
                'Usuario ' . $userId, 'high', $userId);
            throw HttpException::tooManyRequests('Demasiados intentos de verificacion. Espere unos minutos.');
        }

        $secret = self::mfaSecret($userId);
        $valid  = $secret !== null && totpModel::verify($secret, $code);

        if (!$valid) {
            $valid = self::consumeBackupCode($userId, $code);
        }

        if (!$valid) {
            auditoriaModel::registrarIntentoAcceso((string) $userId, $userId, 'mfa_failed');
            auditoriaModel::registrar(auditoriaModel::MFA_FAILED, 'user', $userId, null, 'failure', [], 'warning');
            return ['status' => 'error', 'message' => 'El codigo de verificacion no es valido.'];
        }

        limitadorModel::limpiar($bucket);
        sesionModel::marcarMfaVerificado((string) $session['id']);
        // Rotacion del identificador tras elevar el nivel de autenticacion.
        $rotated = sesionModel::rotar((string) $session['id']);

        // El contexto aun no esta autenticado en esta peticion: los datos del
        // actor se pasan explicitamente para que la auditoria no quede anonima.
        $record = usuarioModel::find($userId);
        auditoriaModel::registrar(
            auditoriaModel::LOGIN, 'user', $userId, $record['username'] ?? null, 'success', ['mfa' => true], 'info',
            $userId,
            $record !== null ? trim(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')) : null,
            $record['national_id'] ?? null
        );
        auditoriaModel::registrarIntentoAcceso((string) ($record['username'] ?? $userId), $userId, 'success', 'mfa_ok');

        return ['status' => 'ok', 'token' => $rotated['token'], 'session' => $rotated['session']];
    }

    /** Reautenticacion (step-up) previa a operaciones sensibles. */
    public static function reauthenticate(int $userId, string $password, ?string $mfaCode = null): bool
    {
        $bucket = 'reauth:' . $userId;
        if (!limitadorModel::intentar($bucket, 10, 300)) {
            throw HttpException::tooManyRequests('Demasiados intentos de confirmacion.');
        }
        $record = usuarioModel::findAuthRecordById($userId);
        if ($record === null || !cifradoModel::verificarContrasena($password, (string) $record['password_hash'])) {
            auditoriaModel::registrar(auditoriaModel::REAUTH_FAILED, 'user', $userId, null, 'failure', [], 'warning');
            return false;
        }
        if ((bool) $record['mfa_enabled'] && $mfaCode !== null && $mfaCode !== '') {
            $secret = self::mfaSecret($userId);
            if ($secret === null || !totpModel::verify($secret, $mfaCode)) {
                auditoriaModel::registrar(auditoriaModel::REAUTH_FAILED, 'user', $userId, null, 'failure', ['mfa' => false], 'warning');
                return false;
            }
        }
        limitadorModel::limpiar($bucket);
        auditoriaModel::registrar(auditoriaModel::REAUTH, 'user', $userId, null, 'success');
        return true;
    }

    public static function logout(string $sessionId, ?int $userId): void
    {
        sesionModel::revocar($sessionId, $userId, 'cierre de sesion del usuario');
        auditoriaModel::registrar(auditoriaModel::LOGOUT, 'session', $sessionId, null, 'success');
    }

    // -----------------------------------------------------------------
    //  Contrasenas de acceso al sistema
    // -----------------------------------------------------------------

    /** Politica de contrasenas para el acceso AL SISTEMA. */
    public static function validatePasswordPolicy(string $password, array $userData = []): void
    {
        $min    = configuracionModel::entero('security.password_min_length', 12);
        $errors = [];

        if (mb_strlen($password) < $min) {
            $errors['password'] = "La contrasena debe tener al menos {$min} caracteres.";
        } elseif (mb_strlen($password) > 200) {
            $errors['password'] = 'La contrasena no puede superar 200 caracteres.';
        } elseif (
            preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/\d/', $password) !== 1
            || preg_match('/[^a-zA-Z0-9]/', $password) !== 1
        ) {
            $errors['password'] = 'La contrasena debe combinar mayusculas, minusculas, numeros y un caracter especial.';
        }

        if ($errors === []) {
            foreach (['username', 'email', 'national_id', 'first_name', 'last_name'] as $field) {
                $value = (string) ($userData[$field] ?? '');
                if ($value !== '' && mb_strlen($value) >= 4 && stripos($password, $value) !== false) {
                    $errors['password'] = 'La contrasena no puede contener sus datos personales.';
                    break;
                }
            }
        }

        if ($errors === []) {
            $common = ['password', 'contrasena', '12345678', 'qwerty', 'admin123', 'bienvenido', 'empresa123'];
            foreach ($common as $needle) {
                if (stripos($password, $needle) !== false) {
                    $errors['password'] = 'La contrasena contiene un patron demasiado comun.';
                    break;
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    public static function changeOwnPassword(int $userId, string $current, string $new, string $confirmation): void
    {
        $record = usuarioModel::findAuthRecordById($userId);
        if ($record === null) {
            throw HttpException::unauthorized();
        }
        if (!cifradoModel::verificarContrasena($current, (string) $record['password_hash'])) {
            throw new ValidationException(['current_password' => 'La contrasena actual no es correcta.']);
        }
        if (!hash_equals($new, $confirmation)) {
            throw new ValidationException(['password_confirmation' => 'La confirmacion no coincide.']);
        }
        if (cifradoModel::verificarContrasena($new, (string) $record['password_hash'])) {
            throw new ValidationException(['password' => 'La nueva contrasena debe ser distinta de la actual.']);
        }
        self::validatePasswordPolicy($new, $record);

        $hash = cifradoModel::hashContrasena($new);
        usuarioModel::updatePassword($userId, $hash['hash'], $hash['algo'], false);
        // Cambiar la contrasena invalida el resto de sesiones del usuario.
        sesionModel::revocarTodasDeUsuario($userId, $userId, 'cambio de contrasena', contextoModel::idSesion());
        auditoriaModel::registrar(auditoriaModel::PASSWORD_CHANGED, 'user', $userId, null, 'success', [], 'notice');
    }

    // -----------------------------------------------------------------
    //  MFA: alta y baja
    // -----------------------------------------------------------------

    public static function mfaSecret(int $userId): ?string
    {
        $row = self::obtenerFila('SELECT * FROM mfa_secrets WHERE user_id = ?', [$userId]);
        if ($row === null) {
            return null;
        }
        return cifradoModel::descifrar($row, cifradoModel::aad('user', $userId, 'mfa_secret'));
    }

    /** @return array{secret:string,uri:string} */
    public static function beginMfaEnrollment(int $userId, string $account, string $issuer): array
    {
        $secret   = totpModel::generateSecret();
        $envelope = cifradoModel::cifrar($secret, cifradoModel::aad('user', $userId, 'mfa_secret'));
        self::ejecutarConsultaAfectadas(
            'REPLACE INTO mfa_secrets (user_id, key_version, ciphertext, nonce, tag, wrapped_dek, dek_nonce, dek_tag)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $userId, $envelope['key_version'], $envelope['ciphertext'], $envelope['nonce'],
                $envelope['tag'], $envelope['wrapped_dek'], $envelope['dek_nonce'], $envelope['dek_tag'],
            ]
        );
        return ['secret' => $secret, 'uri' => totpModel::provisioningUri($secret, $account, $issuer)];
    }

    /** @return array<int,string> codigos de respaldo generados */
    public static function confirmMfaEnrollment(int $userId, string $code): array
    {
        $secret = self::mfaSecret($userId);
        if ($secret === null || !totpModel::verify($secret, $code)) {
            throw new ValidationException(['code' => 'El codigo no es valido. Verifique la hora de su dispositivo.']);
        }
        $codes = totpModel::generateBackupCodes();
        self::transaccion(function () use ($userId, $codes): void {
            self::ejecutarConsultaAfectadas('DELETE FROM mfa_backup_codes WHERE user_id = ?', [$userId]);
            foreach ($codes as $code) {
                self::ejecutarConsultaAfectadas(
                    'INSERT INTO mfa_backup_codes (user_id, code_hash) VALUES (?,?)',
                    [$userId, password_hash($code, PASSWORD_BCRYPT, ['cost' => 10])]
                );
            }
            self::ejecutarConsultaAfectadas('UPDATE mfa_secrets SET confirmed_at = NOW() WHERE user_id = ?', [$userId]);
            self::ejecutarConsultaAfectadas('UPDATE users SET mfa_enabled = 1 WHERE id = ?', [$userId]);
        });
        auditoriaModel::registrar(auditoriaModel::MFA_ENABLED, 'user', $userId, null, 'success', [], 'notice');
        return $codes;
    }

    public static function disableMfa(int $userId, int $performedBy): void
    {
        self::transaccion(function () use ($userId): void {
            self::ejecutarConsultaAfectadas('DELETE FROM mfa_secrets WHERE user_id = ?', [$userId]);
            self::ejecutarConsultaAfectadas('DELETE FROM mfa_backup_codes WHERE user_id = ?', [$userId]);
            self::ejecutarConsultaAfectadas('UPDATE users SET mfa_enabled = 0 WHERE id = ?', [$userId]);
        });
        auditoriaModel::registrar(auditoriaModel::MFA_DISABLED, 'user', $userId, null, 'success',
            ['ejecutado_por' => $performedBy], 'warning');
    }

    private static function consumeBackupCode(int $userId, string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return false;
        }
        $rows = self::obtenerFilas(
            'SELECT id, code_hash FROM mfa_backup_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
        foreach ($rows as $row) {
            if (password_verify($code, (string) $row['code_hash'])) {
                self::ejecutarConsultaAfectadas('UPDATE mfa_backup_codes SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);
                auditoriaModel::eventoSeguridad('mfa_backup_used', 'Uso de codigo de respaldo MFA',
                    'Se consumio un codigo de respaldo.', 'medium', $userId);
                return true;
            }
        }
        return false;
    }

    public static function remainingBackupCodes(int $userId): int
    {
        return (int) self::obtenerValor(
            'SELECT COUNT(*) FROM mfa_backup_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        );
    }

    // -----------------------------------------------------------------
    //  Recuperacion de cuenta
    // -----------------------------------------------------------------

    /**
     * Genera un token de restablecimiento. NUNCA se envia la contrasena
     * actual: solo un enlace de un solo uso con vigencia corta.
     * La respuesta al usuario es siempre la misma exista o no la cuenta.
     */
    public static function requestPasswordReset(string $identifier, string $ip): ?array
    {
        if (!limitadorModel::intentar('pwreset:ip:' . $ip, 5, 900)) {
            throw HttpException::tooManyRequests('Demasiadas solicitudes de recuperacion.');
        }
        $record = usuarioModel::findAuthRecord($identifier);
        if ($record === null || $record['status'] !== 'active') {
            auditoriaModel::registrar(auditoriaModel::PASSWORD_RESET_REQ, 'user', null, $identifier, 'failure',
                ['motivo' => 'identificador no valido'], 'notice');
            return null;
        }
        $token = cifradoModel::tokenAleatorio(32);
        self::ejecutarConsultaAfectadas('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [(int) $record['id']]);
        self::ejecutarInsert(
            'INSERT INTO password_resets (user_id, token_hash, ip_address, expires_at)
             VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))',
            [(int) $record['id'], cifradoModel::hashToken($token), $ip]
        );
        auditoriaModel::registrar(auditoriaModel::PASSWORD_RESET_REQ, 'user', (int) $record['id'], $record['username'], 'success',
            [], 'notice', (int) $record['id'], $record['first_name'] . ' ' . $record['last_name'], $record['national_id']);

        return ['token' => $token, 'user' => $record];
    }

    public static function completePasswordReset(string $token, string $password, string $confirmation): void
    {
        $row = self::obtenerFila(
            'SELECT pr.*, u.username, u.email, u.national_id, u.first_name, u.last_name
               FROM password_resets pr JOIN users u ON u.id = pr.user_id
              WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()',
            [cifradoModel::hashToken($token)]
        );
        if ($row === null) {
            throw new ValidationException(['token' => 'El enlace de recuperacion no es valido o ya expiro.']);
        }
        if (!hash_equals($password, $confirmation)) {
            throw new ValidationException(['password_confirmation' => 'La confirmacion no coincide.']);
        }
        self::validatePasswordPolicy($password, $row);

        $userId = (int) $row['user_id'];
        $hash   = cifradoModel::hashContrasena($password);
        self::transaccion(function () use ($row, $userId, $hash): void {
            self::ejecutarConsultaAfectadas('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);
            usuarioModel::updatePassword($userId, $hash['hash'], $hash['algo'], false);
        });
        sesionModel::revocarTodasDeUsuario($userId, null, 'restablecimiento de contrasena');
        auditoriaModel::registrar(auditoriaModel::PASSWORD_RESET_DONE, 'user', $userId, $row['username'], 'success',
            [], 'notice', $userId, $row['first_name'] . ' ' . $row['last_name'], $row['national_id']);
    }
}
