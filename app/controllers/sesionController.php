<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\HttpException;
use app\models\auditoriaModel;
use app\models\contextoModel;
use app\models\permisoModel;
use app\models\sesionModel;

/** Control de sesiones activas y cierre remoto. */
class sesionController extends baseController
{
    public static function listarController(array $peticion): array
    {
        return self::responder(static function () use ($peticion): array {
            permisoModel::exigir('sessions.view');
            $filtros = [
                'status'  => self::texto($peticion, 'status') ?: 'active',
                'user_id' => self::entero($peticion, 'user_id'),
                'search'  => self::texto($peticion, 'q'),
            ];
            return [
                'items'      => sesionModel::listar($filtros, 200),
                'current_id' => contextoModel::idSesion(),
                'filters'    => $filtros,
            ];
        }, 'Sesiones activas');
    }

    public static function revocarController(string $id, array $variables): array
    {
        return self::responder(static function () use ($id, $variables): array {
            permisoModel::exigir('sessions.revoke');
            if (preg_match('/^[a-f0-9]{64}$/', $id) !== 1) {
                throw HttpException::badRequest('Identificador de sesion invalido.');
            }
            // Cerrar la propia sesion desde este panel deja al usuario fuera sin
            // aviso; para eso existe "Salir".
            if ($id === contextoModel::idSesion()) {
                throw HttpException::badRequest('No puede cerrar su propia sesion desde este panel; use "Salir".');
            }
            $motivo = self::texto($variables, 'reason') ?: 'cierre remoto por administrador';
            sesionModel::revocar($id, contextoModel::id(), $motivo);
            auditoriaModel::registrar(auditoriaModel::SESSION_REVOKED, 'session', $id, null, 'success',
                ['motivo' => $motivo], 'warning');
            return ['id' => $id];
        }, 'Sesion cerrada');
    }

    public static function revocarUsuarioController(int $idUsuario): array
    {
        return self::responder(static function () use ($idUsuario): array {
            permisoModel::exigir('sessions.revoke');
            $total = sesionModel::revocarTodasDeUsuario($idUsuario, contextoModel::id(), 'cierre masivo por administrador');
            auditoriaModel::registrar(auditoriaModel::SESSION_REVOKED, 'user', $idUsuario, null, 'success',
                ['sesiones_cerradas' => $total], 'warning');
            return ['closed' => $total];
        }, 'Sesiones cerradas');
    }
}
