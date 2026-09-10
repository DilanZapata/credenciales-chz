<?php
declare(strict_types=1);

namespace App\Services;

use app\models\permisoModel;

/** Envoltorio de transicion sobre `app\models\permisoModel`. */
final class AuthorizationService
{
    public function __construct(
        private AuthContext $context,
        private SettingsService $settings,
        private AuditService $audit
    ) {
    }

    public function can(string $permiso): bool                    { return permisoModel::puede($permiso); }
    public function credentialScopeUserId(): ?int                 { return permisoModel::alcanceCredenciales(); }
    public function requireStepUp(string $operacion): void        { permisoModel::exigirReautenticacion($operacion); }

    public function require(string $permiso, ?string $tipo = null, int|string|null $id = null): void
    {
        permisoModel::exigir($permiso, $tipo, $id);
    }

    public function requireAny(array $permisos, ?string $tipo = null, int|string|null $id = null): void
    {
        permisoModel::exigirAlguno($permisos, $tipo, $id);
    }

    public function canManageUser(int $nivel, ?int $id = null): bool
    {
        return permisoModel::puedeGestionarUsuario($nivel, $id);
    }

    public function requireManageUser(int $nivel, ?int $id = null): void
    {
        permisoModel::exigirGestionUsuario($nivel, $id);
    }
}
