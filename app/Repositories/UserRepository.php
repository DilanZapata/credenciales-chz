<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use app\models\usuarioModel;

/**
 * Envoltorio de transicion sobre `app\models\usuarioModel`.
 *
 * Algunos metodos de persistencia se renombraron al fusionar la logica de
 * negocio en el modelo, porque colisionaban con la operacion publica del
 * mismo nombre. El mapa traduce el nombre antiguo al nuevo mientras queden
 * consumidores que llamen por el viejo.
 */
final class UserRepository
{
    /** @var array<string,string> */
    private const ALIAS = [
        'create' => 'crearRegistro',
        'update' => 'actualizarRegistro',
        'deactivate' => 'desactivarRegistro',
        'reactivate' => 'reactivarRegistro',
        'setPermissionOverrides' => 'fijarExcepcionesPermisos',
    ];

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
        $real = self::ALIAS[$metodo] ?? $metodo;
        return usuarioModel::$real(...$argumentos);
    }
}
