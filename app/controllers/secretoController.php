<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\HttpException;
use App\Core\ReauthRequiredException;
use App\Core\ValidationException;
use app\models\credencialModel;
use app\models\mainModel;
use Throwable;

/**
 * Controlador de secretos.
 *
 * Es el unico que devuelve texto en claro. Deliberadamente separado del
 * controlador de credenciales: asi ninguna accion del CRUD puede acabar
 * exponiendo una contrasena por descuido.
 *
 * Las cinco barreras (permiso, alcance, permiso fino de la asignacion,
 * limite de frecuencia y reautenticacion) las aplica `credencialModel`;
 * aqui solo se traduce el resultado.
 */
class secretoController extends baseController
{
    private const CAMPOS = ['password', 'pin', 'access_code'];

    /** @param array<string,mixed> $variables */
    public static function revelarController(int $id, array $variables): array
    {
        return self::responder(static function () use ($id, $variables): array {
            $campo = (string) ($variables['field'] ?? 'password');
            $campo = in_array($campo, self::CAMPOS, true) ? $campo : 'password';
            $copiar = in_array(strtolower((string) ($variables['copy'] ?? '')), ['1', 'true', 'on', 'yes', 'si'], true);

            $r = credencialModel::revealSecret($id, $campo, $copiar ? 'copy' : 'view');
            return [
                'secret'     => $r['secret'],
                'field'      => $r['field'],
                'version'    => $r['version'],
                'is_current' => $r['is_current'],
                // Segundos que la interfaz lo mantiene visible antes de ocultarlo.
                'ttl'        => 30,
            ];
        });
    }

    /** @param array<string,mixed> $variables */
    public static function revelarHistoricoController(int $id, int $version, array $variables): array
    {
        return self::responder(static function () use ($id, $version, $variables): array {
            $campo = (string) ($variables['field'] ?? 'password');
            $campo = in_array($campo, self::CAMPOS, true) ? $campo : 'password';

            $r = credencialModel::revealSecret($id, $campo, 'history_view', $version);
            return [
                'secret'       => $r['secret'],
                'version'      => $r['version'],
                'generated_at' => $r['generated_at'],
                'ttl'          => 30,
            ];
        });
    }

    public static function recuperacionController(int $id): array
    {
        return self::responder(static fn (): array => credencialModel::recoveryInfo($id));
    }

}
