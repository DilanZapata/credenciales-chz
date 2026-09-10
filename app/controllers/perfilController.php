<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\Config;
use app\models\asignacionModel;
use app\models\autenticacionModel;
use app\models\contextoModel;
use app\models\notificacionModel;
use app\models\sesionModel;

/** Perfil del usuario: sus accesos, su contrasena, su MFA y sus avisos. */
class perfilController extends baseController
{
    public static function fichaController(): array
    {
        return self::responder(static function (): array {
            $id = (int) contextoModel::id();
            return [
                'user'            => contextoModel::usuario(),
                'roles'           => contextoModel::roles(),
                'permissions'     => contextoModel::permisos(),
                'assignments'     => asignacionModel::forUser($id, true),
                'sessions'        => sesionModel::listar(['user_id' => $id, 'status' => 'active'], 20),
                'current_session' => contextoModel::idSesion(),
                'backup_codes'    => autenticacionModel::remainingBackupCodes($id),
            ];
        }, 'Mi perfil');
    }

    public static function cambiarContrasenaController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            autenticacionModel::changeOwnPassword(
                (int) contextoModel::id(),
                (string) ($variables['current_password'] ?? ''),
                (string) ($variables['password'] ?? ''),
                (string) ($variables['password_confirmation'] ?? '')
            );
            return ['sessions_closed' => true];
        }, 'Contrasena actualizada');
    }

    public static function mfaController(): array
    {
        return self::responder(static function (): array {
            $id      = (int) contextoModel::id();
            $usuario = contextoModel::usuario() ?? [];
            return [
                'enabled'      => (bool) ($usuario['mfa_enabled'] ?? false),
                'required'     => autenticacionModel::userRequiresMfa($id, (bool) ($usuario['mfa_enforced'] ?? false)),
                'backup_codes' => autenticacionModel::remainingBackupCodes($id),
                'enrollment'   => null,
            ];
        }, 'Verificacion en dos pasos');
    }

    public static function iniciarMfaController(): array
    {
        return self::responder(static function (): array {
            $id      = (int) contextoModel::id();
            $usuario = contextoModel::usuario() ?? [];
            return [
                'enabled'      => false,
                'required'     => true,
                'backup_codes' => 0,
                'enrollment'   => autenticacionModel::beginMfaEnrollment(
                    $id,
                    (string) ($usuario['email'] ?? 'usuario'),
                    (string) Config::get('app.short_name', 'Credenciales')
                ),
            ];
        }, 'Alta de la verificacion en dos pasos');
    }

    public static function confirmarMfaController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            // Los codigos de respaldo se devuelven UNA sola vez: no se pueden
            // volver a consultar, solo regenerar.
            return ['backup_codes' => autenticacionModel::confirmMfaEnrollment(
                (int) contextoModel::id(),
                (string) ($variables['code'] ?? '')
            )];
        }, 'Verificacion en dos pasos activada');
    }

    public static function desactivarMfaController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            $id      = (int) contextoModel::id();
            $usuario = contextoModel::usuario() ?? [];
            if (!autenticacionModel::reauthenticate($id, (string) ($variables['password'] ?? ''))) {
                throw \App\Core\HttpException::forbidden('La contrasena no es correcta.');
            }
            if (autenticacionModel::userRequiresMfa($id, (bool) ($usuario['mfa_enforced'] ?? false))) {
                throw \App\Core\HttpException::forbidden('Su rol exige verificacion en dos pasos; no es posible desactivarla.');
            }
            autenticacionModel::disableMfa($id, $id);
            return ['enabled' => false];
        }, 'Verificacion en dos pasos desactivada');
    }

    public static function notificacionesController(): array
    {
        return self::responder(static function (): array {
            return ['items' => notificacionModel::forUser(
                (int) contextoModel::id(),
                self::idsDeRol(),
                false,
                100
            )];
        }, 'Notificaciones');
    }

    public static function marcarLeidaController(int $id): array
    {
        return self::responder(static function () use ($id): array {
            notificacionModel::markRead($id, (int) contextoModel::id(), self::idsDeRol());
            return ['id' => $id];
        }, 'Notificacion marcada como leida');
    }

    /** @return array<int,int> */
    private static function idsDeRol(): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], contextoModel::roles());
    }
}
