<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use app\models\sistemaModel;

/**
 * Envoltorio de transicion sobre `app\models\sistemaModel`.
 *
 * Toda la logica y el SQL viven ya en el modelo, con metodos estaticos,
 * segun la convencion de Porcify Manager. Esta clase reenvia las llamadas
 * mientras queden consumidores que la reciben por inyeccion; desaparece
 * cuando el ultimo de ellos pase a llamar al modelo directamente.
 */
final class SystemRepository
{
    public function __construct(private Database $db)
    {
    }

    /** @param array<int,mixed> $argumentos */
    public function __call(string $metodo, array $argumentos): mixed
    {
        return sistemaModel::$metodo(...$argumentos);
    }
}