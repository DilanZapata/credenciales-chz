<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use app\models\sesionModel;

/** Envoltorio de transicion sobre `app\models\sesionModel`. */
final class SessionService
{
    public const COOKIE = sesionModel::COOKIE;

    public function __construct(
        private Database $db,
        private CryptoService $crypto,
        private SettingsService $settings
    ) {
    }

    public function create(int $u, string $ip, string $ag, string $dis, bool $mfa): array
    {
        return sesionModel::crear($u, $ip, $ag, $dis, $mfa);
    }

    public function rotate(string $id): array                     { return sesionModel::rotar($id); }
    public function findById(string $id): ?array                  { return sesionModel::porId($id); }
    public function resolve(string $token): ?array                { return sesionModel::resolver($token); }
    public function touch(string $id): void                       { sesionModel::tocar($id); }
    public function markMfaVerified(string $id): void             { sesionModel::marcarMfaVerificado($id); }
    public function markReauthenticated(string $id): string       { return sesionModel::marcarReautenticado($id); }
    public function expire(string $id): void                      { sesionModel::expirar($id); }
    public function activeCount(): int                            { return sesionModel::contarActivas(); }
    public function purgeExpired(): int                           { return sesionModel::purgarExpiradas(); }

    public function revoke(string $id, ?int $por, string $motivo): void
    {
        sesionModel::revocar($id, $por, $motivo);
    }

    public function revokeAllForUser(int $u, ?int $por, string $motivo, ?string $excepto = null): int
    {
        return sesionModel::revocarTodasDeUsuario($u, $por, $motivo, $excepto);
    }

    public function listSessions(array $filtros = [], int $limite = 100): array
    {
        return sesionModel::listar($filtros, $limite);
    }
}
