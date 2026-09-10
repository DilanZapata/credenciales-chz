<?php
declare(strict_types=1);

namespace App\Services;

use app\models\contextoModel;

/**
 * Envoltorio de transicion sobre `app\models\contextoModel`.
 *
 * El contexto de seguridad vive ahora en el modelo, con metodos estaticos,
 * segun la convencion de Porcify Manager. Esta clase se conserva mientras
 * queden consumidores que la reciben por inyeccion.
 */
final class AuthContext
{
    public function setRequestInfo(string $ip, string $agente, string $dispositivo, string $metodo, string $ruta): void
    {
        contextoModel::fijarDatosPeticion($ip, $agente, $dispositivo, $metodo, $ruta);
    }

    public function authenticate(array $usuario, array $sesion, array $permisos, array $roles): void
    {
        contextoModel::autenticar($usuario, $sesion, $permisos, $roles);
    }

    public function forget(): void            { contextoModel::olvidar(); }
    public function check(): bool             { return contextoModel::autenticado(); }
    public function user(): ?array            { return contextoModel::usuario(); }
    public function id(): ?int                { return contextoModel::id(); }
    public function nationalId(): ?string     { return contextoModel::cedula(); }
    public function fullName(): string        { return contextoModel::nombreCompleto(); }
    public function session(): ?array         { return contextoModel::sesion(); }
    public function sessionId(): ?string      { return contextoModel::idSesion(); }
    public function csrfToken(): ?string      { return contextoModel::tokenCsrf(); }
    public function can(string $p): bool      { return contextoModel::puede($p); }
    public function permissions(): array      { return contextoModel::permisos(); }
    public function roles(): array            { return contextoModel::roles(); }
    public function roleCodes(): array        { return contextoModel::codigosRol(); }
    public function hasRole(string $c): bool  { return contextoModel::tieneRol($c); }
    public function isSuperAdmin(): bool      { return contextoModel::esSuperadministrador(); }
    public function level(): int              { return contextoModel::nivel(); }
    public function ip(): string              { return contextoModel::ip(); }
    public function userAgent(): string       { return contextoModel::agente(); }
    public function device(): string          { return contextoModel::dispositivo(); }
    public function route(): string           { return contextoModel::ruta(); }
    public function method(): string          { return contextoModel::metodo(); }

    public function canAny(string ...$permisos): bool
    {
        return contextoModel::puedeAlguno(...$permisos);
    }

    public function reauthenticatedWithin(int $minutos): bool
    {
        return contextoModel::reautenticadoHace($minutos);
    }

    public function markReauthenticated(string $marca): void
    {
        contextoModel::marcarReautenticado($marca);
    }
}
