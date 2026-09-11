<?php
declare(strict_types=1);

namespace app\models;

use App\Core\Logger;
use Throwable;

/**
 * Auditoria del sistema.
 *
 * Tres registros complementarios:
 *   - audit_logs        : todo evento relevante (quien, que, cuando, resultado)
 *   - secret_access_log : acceso especifico a secretos (ver/copiar/exportar)
 *   - security_events   : anomalias que exigen atencion del administrador
 *
 * GARANTIA: el contexto pasa por Logger::redact antes de serializarse, de
 * modo que ninguna contrasena puede terminar en la auditoria.
 *
 * En Porcify Manager el equivalente es `auditoriaModel`, con el mismo papel
 * de registro transversal invocado desde los modelos de cada modulo.
 */
class auditoriaModel extends mainModel
{
    public const LOGIN = 'auth.login';
    public const LOGOUT = 'auth.logout';
    public const LOGIN_FAILED = 'auth.login_failed';
    public const LOGIN_BLOCKED = 'auth.login_blocked';
    public const MFA_CHALLENGE = 'auth.mfa_challenge';
    public const MFA_FAILED = 'auth.mfa_failed';
    public const MFA_ENABLED = 'auth.mfa_enabled';
    public const MFA_DISABLED = 'auth.mfa_disabled';
    public const REAUTH = 'auth.reauth';
    public const REAUTH_FAILED = 'auth.reauth_failed';
    public const PASSWORD_CHANGED = 'auth.password_changed';
    public const PASSWORD_RESET_REQ = 'auth.password_reset_requested';
    public const PASSWORD_RESET_DONE = 'auth.password_reset_completed';
    public const USER_CREATED = 'user.created';
    public const USER_UPDATED = 'user.updated';
    public const USER_DEACTIVATED = 'user.deactivated';
    public const USER_REACTIVATED = 'user.reactivated';
    public const USER_ROLES_CHANGED = 'user.roles_changed';
    public const USER_PERMS_CHANGED = 'user.permissions_changed';
    public const USER_PASSWORD_RESET = 'user.password_reset';
    public const SYSTEM_CREATED = 'system.created';
    public const SYSTEM_UPDATED = 'system.updated';
    public const SYSTEM_DELETED = 'system.deleted';
    public const CREDENTIAL_CREATED = 'credential.created';
    public const CREDENTIAL_UPDATED = 'credential.updated';
    public const CREDENTIAL_VIEWED = 'credential.viewed';
    public const CREDENTIAL_ROTATED = 'credential.password_rotated';
    public const CREDENTIAL_DELETED = 'credential.deleted';
    public const CREDENTIAL_RESTORED = 'credential.restored';
    public const CREDENTIAL_ASSIGNED = 'credential.assigned';
    public const CREDENTIAL_REVOKED = 'credential.revoked';
    public const SECRET_VIEWED = 'secret.viewed';
    public const SECRET_COPIED = 'secret.copied';
    public const SECRET_HISTORY_VIEW = 'secret.history_viewed';
    public const RECOVERY_VIEWED = 'recovery.viewed';
    public const EXPORT_GENERATED = 'export.generated';
    public const EXPORT_DOWNLOADED = 'export.downloaded';
    public const EXPORT_DENIED = 'export.denied';
    public const IMPORT_EXECUTED = 'import.executed';
    public const SESSION_REVOKED = 'session.revoked';
    public const SETTINGS_UPDATED = 'settings.updated';
    public const QUICK_LOOKUP = 'access.quick_lookup';
    public const QUICK_LOOKUP_DENIED = 'access.quick_lookup_denied';
    public const CATEGORY_MANAGED = 'category.managed';
    public const ORG_MANAGED = 'org.managed';
    public const ROLE_MANAGED = 'role.managed';
    public const ACCESS_DENIED = 'security.access_denied';

    /**
     * Registra un evento de auditoria.
     *
     * @param array<string,mixed> $detalles
     */
    public static function registrar(
        string $accion,
        ?string $tipoEntidad = null,
        int|string|null $idEntidad = null,
        ?string $etiqueta = null,
        string $resultado = 'success',
        array $detalles = [],
        string $severidad = 'info',
        ?int $usuarioForzado = null,
        ?string $nombreForzado = null,
        ?string $cedulaForzada = null
    ): void {
        try {
            $seguros = Logger::redact($detalles);
            self::ejecutarInsert(
                'INSERT INTO audit_logs
                   (user_id, actor_national_id, actor_name, action, entity_type, entity_id, entity_label,
                    result, severity, ip_address, user_agent, device, session_id, http_method, route, details)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $usuarioForzado ?? contextoModel::id(),
                    $cedulaForzada ?? contextoModel::cedula(),
                    $nombreForzado ?? (contextoModel::autenticado() ? contextoModel::nombreCompleto() : null),
                    $accion,
                    $tipoEntidad,
                    $idEntidad !== null ? mb_substr((string) $idEntidad, 0, 64) : null,
                    $etiqueta !== null ? mb_substr($etiqueta, 0, 255) : null,
                    $resultado,
                    $severidad,
                    contextoModel::ip(),
                    contextoModel::agente(),
                    contextoModel::dispositivo(),
                    contextoModel::idSesion(),
                    contextoModel::metodo(),
                    mb_substr(contextoModel::ruta(), 0, 255),
                    $seguros === [] ? null : json_encode($seguros, JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (Throwable $e) {
            // La auditoria nunca debe tumbar la operacion, pero si dejar rastro.
            Logger::error('No fue posible escribir en auditoria', ['action' => $accion, 'error' => $e->getMessage()]);
        }
    }

    /** Registro dedicado de acceso a un secreto. */
    public static function registrarAccesoSecreto(
        int $idCredencial,
        string $tipoAcceso,
        ?int $idSecreto = null,
        string $campo = 'password',
        ?int $versionSecreto = null,
        string $resultado = 'success',
        ?string $motivo = null,
        ?int $idReporte = null,
        ?int $usuarioForzado = null,
        ?string $cedulaForzada = null
    ): void {
        try {
            self::ejecutarInsert(
                'INSERT INTO secret_access_log
                   (credential_id, secret_id, secret_field, secret_version, user_id, actor_national_id,
                    access_type, result, reason, report_id, ip_address, user_agent, session_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $idCredencial, $idSecreto, $campo, $versionSecreto,
                    // La consulta rapida no abre sesion: el acceso se atribuye
                    // igualmente a la persona, para que el rastro no quede huerfano.
                    $usuarioForzado ?? contextoModel::id(),
                    $cedulaForzada ?? contextoModel::cedula(),
                    $tipoAcceso, $resultado, $motivo, $idReporte,
                    contextoModel::ip(), contextoModel::agente(), contextoModel::idSesion(),
                ]
            );
        } catch (Throwable $e) {
            Logger::error('No fue posible registrar el acceso al secreto', ['error' => $e->getMessage()]);
        }
    }

    /** Evento de seguridad para el tablero del administrador. */
    public static function eventoSeguridad(
        string $tipo,
        string $titulo,
        string $mensaje = '',
        string $severidad = 'medium',
        ?int $idUsuario = null,
        array $detalles = []
    ): void {
        try {
            self::ejecutarInsert(
                'INSERT INTO security_events (type, severity, user_id, ip_address, title, message, details)
                 VALUES (?,?,?,?,?,?,?)',
                [
                    $tipo,
                    $severidad,
                    $idUsuario ?? contextoModel::id(),
                    contextoModel::ip(),
                    mb_substr($titulo, 0, 180),
                    mb_substr($mensaje, 0, 500),
                    $detalles === [] ? null : json_encode(Logger::redact($detalles), JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (Throwable $e) {
            Logger::error('No fue posible registrar el evento de seguridad', ['error' => $e->getMessage()]);
        }
    }

    /** Intento de acceso. Nunca almacena la contrasena. */
    public static function registrarIntentoAcceso(string $identificador, ?int $idUsuario, string $resultado, ?string $motivo = null): void
    {
        self::ejecutarInsert(
            'INSERT INTO login_attempts (identifier, user_id, ip_address, user_agent, result, reason)
             VALUES (?,?,?,?,?,?)',
            [
                mb_substr($identificador, 0, 190),
                $idUsuario,
                contextoModel::ip(),
                contextoModel::agente(),
                $resultado,
                $motivo,
            ]
        );
    }

    /** Traza de cambios sobre una credencial (historial funcional). */
    public static function historialCredencial(int $idCredencial, string $accion, array $datos = []): void
    {
        self::ejecutarInsert(
            'INSERT INTO credential_history
               (credential_id, secret_version, action, field_changed, old_value, new_value,
                old_status, new_status, old_expires_at, new_expires_at, reason, performed_by, ip_address, metadata)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $idCredencial,
                $datos['secret_version'] ?? null,
                $accion,
                $datos['field'] ?? null,
                isset($datos['old_value']) ? mb_substr((string) $datos['old_value'], 0, 500) : null,
                isset($datos['new_value']) ? mb_substr((string) $datos['new_value'], 0, 500) : null,
                $datos['old_status'] ?? null,
                $datos['new_status'] ?? null,
                $datos['old_expires_at'] ?? null,
                $datos['new_expires_at'] ?? null,
                isset($datos['reason']) ? mb_substr((string) $datos['reason'], 0, 255) : null,
                contextoModel::id(),
                contextoModel::ip(),
                isset($datos['metadata']) ? json_encode(Logger::redact($datos['metadata']), JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    }
}
