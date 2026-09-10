<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use app\models\limitadorModel;

/** Envoltorio de transicion sobre `app\models\limitadorModel`. */
final class RateLimiter
{
    /**
     * Constructor de transicion: acepta las dependencias que aun inyecta
     * el contenedor, sin usarlas. La logica vive en el modelo estatico.
     */
    public function __construct(mixed ...$dependencias)
    {
    }

    public function attempt(string $cubo, int $max, int $ventana): bool { return limitadorModel::intentar($cubo, $max, $ventana); }
    public function remaining(string $cubo, int $max): int              { return limitadorModel::restantes($cubo, $max); }
    public function retryAfter(string $cubo): int                       { return limitadorModel::reintentarEn($cubo); }
    public function clear(string $cubo): void                           { limitadorModel::limpiar($cubo); }
    public function purgeExpired(): int                                 { return limitadorModel::purgarVencidos(); }
}
