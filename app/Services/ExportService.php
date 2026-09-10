<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use app\models\exportacionModel;

/**
 * Envoltorio de transicion sobre `app\models\exportacionModel`.
 *
 * La logica de negocio vive ya en el modelo, junto al SQL, segun la
 * convencion de Porcify Manager. Esta clase reenvia las llamadas mientras
 * queden consumidores que la reciben por inyeccion.
 */
final class ExportService
{
    /**
     * Constructor de transicion: acepta las dependencias que aun inyecta
     * el contenedor, sin usarlas. La logica vive en el modelo estatico.
     */
    public function __construct(mixed ...$dependencias)
    {
    }

    /** @param array<int,mixed> $argumentos */
    public function __call(string $metodo, array $argumentos): mixed
    {
        return exportacionModel::$metodo(...$argumentos);
    }
}