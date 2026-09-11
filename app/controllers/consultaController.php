<?php
declare(strict_types=1);

namespace app\controllers;

use app\models\consultaModel;

/** Consulta rapida de accesos: la unica pantalla que responde sin sesion. */
class consultaController extends baseController
{
    public static function identificarController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            return ['user_id' => consultaModel::identificar(
                self::texto($variables, 'identifier'),
                (string) ($variables['password'] ?? '')
            )];
        }, 'Consulta de accesos');
    }

    public static function accesosController(int $idUsuario): array
    {
        return self::responder(
            static fn (): array => consultaModel::accesosDe($idUsuario),
            'Accesos del empleado'
        );
    }

    public static function revelarController(int $idUsuario, int $idCredencial): array
    {
        return self::responder(
            static fn (): array => consultaModel::revelar($idUsuario, $idCredencial),
            'Contrasena revelada'
        );
    }
}
