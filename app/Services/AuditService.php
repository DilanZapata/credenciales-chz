<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use app\models\auditoriaModel;

/**
 * Envoltorio de transicion sobre `app\models\auditoriaModel`.
 *
 * La auditoria vive ahora en el modelo, con metodos estaticos, segun la
 * convencion de Porcify Manager. Esta clase se conserva mientras queden
 * consumidores que la reciben por inyeccion.
 */
final class AuditService
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
    public const CATEGORY_MANAGED = 'category.managed';
    public const ORG_MANAGED = 'org.managed';
    public const ROLE_MANAGED = 'role.managed';
    public const ACCESS_DENIED = 'security.access_denied';

    /**
     * Constructor de transicion: acepta las dependencias que aun inyecta
     * el contenedor, sin usarlas. La logica vive en el modelo estatico.
     */
    public function __construct(mixed ...$dependencias)
    {
    }

    public function log(
        string $accion,
        ?string $tipoEntidad = null,
        int|string|null $idEntidad = null,
        ?string $etiqueta = null,
        string $resultado = "success",
        array $detalles = [],
        string $severidad = "info",
        ?int $usuarioForzado = null,
        ?string $nombreForzado = null,
        ?string $cedulaForzada = null
    ): void {
        auditoriaModel::registrar($accion, $tipoEntidad, $idEntidad, $etiqueta, $resultado,
            $detalles, $severidad, $usuarioForzado, $nombreForzado, $cedulaForzada);
    }

    public function logSecretAccess(
        int $idCredencial,
        string $tipoAcceso,
        ?int $idSecreto = null,
        string $campo = "password",
        ?int $versionSecreto = null,
        string $resultado = "success",
        ?string $motivo = null,
        ?int $idReporte = null
    ): void {
        auditoriaModel::registrarAccesoSecreto($idCredencial, $tipoAcceso, $idSecreto, $campo,
            $versionSecreto, $resultado, $motivo, $idReporte);
    }

    public function securityEvent(
        string $tipo,
        string $titulo,
        string $mensaje = "",
        string $severidad = "medium",
        ?int $idUsuario = null,
        array $detalles = []
    ): void {
        auditoriaModel::eventoSeguridad($tipo, $titulo, $mensaje, $severidad, $idUsuario, $detalles);
    }

    public function logLoginAttempt(string $identificador, ?int $idUsuario, string $resultado, ?string $motivo = null): void
    {
        auditoriaModel::registrarIntentoAcceso($identificador, $idUsuario, $resultado, $motivo);
    }

    public function credentialHistory(int $idCredencial, string $accion, array $datos = []): void
    {
        auditoriaModel::historialCredencial($idCredencial, $accion, $datos);
    }
}