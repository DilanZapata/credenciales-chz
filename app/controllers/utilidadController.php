<?php
declare(strict_types=1);

namespace app\controllers;

use app\models\alertaModel;
use app\models\contextoModel;
use app\models\credencialModel;
use app\models\generadorModel;
use app\models\notificacionModel;
use app\models\permisoModel;
use app\models\sistemaModel;

/** Utilidades de la interfaz: generador, busqueda, notificaciones y alertas. */
class utilidadController extends baseController
{
    /** @param array<string,mixed> $variables */
    public static function generarController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            permisoModel::exigir('credentials.create');
            $clave = generadorModel::generate([
                'length'            => self::entero($variables, 'length') ?? 20,
                'upper'             => self::booleano($variables, 'upper', true),
                'lower'             => self::booleano($variables, 'lower', true),
                'digits'            => self::booleano($variables, 'digits', true),
                'symbols'           => self::booleano($variables, 'symbols', true),
                'exclude_ambiguous' => self::booleano($variables, 'exclude_ambiguous', false),
            ]);
            $puntaje = generadorModel::strength($clave);
            // La contrasena generada no se registra en ningun log ni auditoria.
            return ['password' => $clave, 'strength' => $puntaje, 'label' => generadorModel::strengthLabel($puntaje)];
        }, 'Contrasena generada');
    }

    /** @param array<string,mixed> $variables */
    public static function fortalezaController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            $puntaje = generadorModel::strength((string) ($variables['password'] ?? ''));
            return ['strength' => $puntaje, 'label' => generadorModel::strengthLabel($puntaje)];
        }, 'Robustez evaluada');
    }

    /** @param array<string,mixed> $peticion */
    public static function buscarController(array $peticion): array
    {
        return self::responder(static function () use ($peticion): array {
            $termino = self::texto($peticion, 'q');
            if (mb_strlen($termino) < 2) {
                return ['credentials' => [], 'systems' => []];
            }
            permisoModel::exigirAlguno(['credentials.view', 'credentials.view_all']);

            $credenciales = credencialModel::paginate(['search' => $termino], 1, 10, permisoModel::alcanceCredenciales());
            $sistemas     = permisoModel::puede('systems.view')
                ? sistemaModel::paginate(['search' => $termino], 1, 5)['items']
                : [];

            return [
                'credentials' => array_map(static fn (array $f): array => [
                    'id' => (int) $f['id'], 'name' => $f['name'], 'system' => $f['system_name'],
                    'category' => $f['category_name'], 'username' => $f['username'],
                ], $credenciales['items']),
                'systems' => array_map(static fn (array $f): array => [
                    'id' => (int) $f['id'], 'name' => $f['name'], 'type' => $f['resource_type'],
                ], $sistemas),
            ];
        }, 'Resultados de busqueda');
    }

    public static function notificacionesController(): array
    {
        return self::responder(static function (): array {
            $idUsuario = (int) contextoModel::id();
            $roles     = array_map(static fn (array $r): int => (int) $r['id'], contextoModel::roles());
            return [
                'unread' => notificacionModel::unreadCount($idUsuario, $roles),
                'items'  => notificacionModel::forUser($idUsuario, $roles, false, 15),
            ];
        }, 'Notificaciones');
    }

    public static function alertasController(): array
    {
        return self::responder(static function (): array {
            permisoModel::exigir('dashboard.view');
            if (!permisoModel::puede('credentials.view_all')) {
                return ['items' => []];
            }
            return ['items' => alertaModel::evaluate()];
        }, 'Alertas');
    }
}
