<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use app\models\configuracionModel;

/** Envoltorio de transicion sobre `app\models\configuracionModel`. */
final class SettingsService
{
    /**
     * Constructor de transicion: acepta las dependencias que aun inyecta
     * el contenedor, sin usarlas. La logica vive en el modelo estatico.
     */
    public function __construct(mixed ...$dependencias)
    {
    }

    public function all(): array                                  { return configuracionModel::todos(); }
    public function get(string $k, mixed $d = null): mixed        { return configuracionModel::obtener($k, $d); }
    public function int(string $k, int $d = 0): int               { return configuracionModel::entero($k, $d); }
    public function bool(string $k, bool $d = false): bool        { return configuracionModel::booleano($k, $d); }
    public function grouped(): array                              { return configuracionModel::agrupados(); }
    public function set(string $k, string $v, ?int $u = null): void { configuracionModel::fijar($k, $v, $u); }
}
