<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use app\models\cifradoModel;

/**
 * Envoltorio de transicion.
 *
 * La logica criptografica vive ahora en `app\models\cifradoModel`, siguiendo
 * la convencion de Porcify Manager. Esta clase se conserva mientras quedan
 * consumidores que la reciben por inyeccion; desaparece cuando el ultimo de
 * ellos pase a llamar al modelo directamente.
 */
final class CryptoService
{
    /**
     * Constructor de transicion: acepta las dependencias que aun inyecta
     * el contenedor, sin usarlas. La logica vive en el modelo estatico.
     */
    public function __construct(mixed ...$dependencias)
    {
    }

    public function activeKeyVersion(): int                       { return cifradoModel::versionClaveActiva(); }
    public function createKeyVersion(): int                       { return cifradoModel::crearVersionClave(); }
    public function encrypt(string $p, string $aad = ''): array   { return cifradoModel::cifrar($p, $aad); }
    public function decrypt(array $s, string $aad = ''): ?string  { return cifradoModel::descifrar($s, $aad); }
    public function rewrap(array $s, string $aad): ?array         { return cifradoModel::reenvolver($s, $aad); }
    public function fingerprint(string $p): string                { return cifradoModel::huella($p); }
    public function hashPassword(string $c): array                { return cifradoModel::hashContrasena($c); }
    public function verifyPassword(string $c, string $h): bool    { return cifradoModel::verificarContrasena($c, $h); }
    public function needsRehash(string $h): bool                  { return cifradoModel::requiereRehash($h); }
    public function randomToken(int $b = 32): string              { return cifradoModel::tokenAleatorio($b); }
    public function hashToken(string $t): string                  { return cifradoModel::hashToken($t); }
    public function equals(string $a, string $b): bool            { return cifradoModel::iguales($a, $b); }

    public function aad(string $entidad, int|string $id, string $campo, int $version = 1): string
    {
        return cifradoModel::aad($entidad, $id, $campo, $version);
    }
}
