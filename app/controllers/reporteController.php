<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\HttpException;
use app\models\catalogoModel;
use app\models\credencialModel;
use app\models\exportacionModel;
use app\models\permisoModel;
use app\models\sistemaModel;
use app\models\usuarioModel;

/** Reportes y exportacion a Excel. */
class reporteController extends baseController
{
    public static function opcionesController(): array
    {
        return self::responder(static function (): array {
            permisoModel::exigir('reports.view');
            return [
                'categories'        => catalogoModel::categories(),
                'systems'           => sistemaModel::selectList(),
                'companies'         => catalogoModel::companies(),
                'locations'         => catalogoModel::locations(),
                'departments'       => catalogoModel::departments(),
                'users'             => usuarioModel::activeSelectList(),
                'can_export_secrets' => permisoModel::puede('export.credentials.secrets'),
                'can_export_history' => permisoModel::puede('export.history'),
                'can_export_audit'   => permisoModel::puede('audit.export'),
            ];
        }, 'Opciones de reportes');
    }

    /** Seleccion manual de credenciales para exportar. */
    public static function seleccionController(array $peticion): array
    {
        return self::responder(static function () use ($peticion): array {
            permisoModel::exigir('reports.view');
            $filtros = array_filter([
                'search'      => self::texto($peticion, 'q'),
                'category_id' => self::entero($peticion, 'category_id'),
                'system_id'   => self::entero($peticion, 'system_id'),
                'company_id'  => self::entero($peticion, 'company_id'),
            ], static fn ($v) => $v !== null && $v !== '' && $v !== 0);

            $resultado = credencialModel::paginate($filtros, 1, 200, permisoModel::alcanceCredenciales());
            return [
                'items' => array_map(static fn (array $fila): array => [
                    'id'       => (int) $fila['id'],
                    'name'     => $fila['name'],
                    'system'   => $fila['system_name'],
                    'category' => $fila['category_name'],
                    'username' => $fila['username'],
                ], $resultado['items']),
                'total' => $resultado['total'],
            ];
        }, 'Credenciales disponibles');
    }

    public static function generarController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            $resultado = exportacionModel::generate([
                'type'            => self::texto($variables, 'type') ?: 'inventory',
                'include_secrets' => self::booleano($variables, 'include_secrets'),
                'confirm'         => self::booleano($variables, 'confirm'),
                'ids'             => self::listaEnteros($variables, 'ids'),
                'filters'         => array_filter([
                    'search'              => self::texto($variables, 'q'),
                    'category_id'         => self::entero($variables, 'category_id'),
                    'system_id'           => self::entero($variables, 'system_id'),
                    'company_id'          => self::entero($variables, 'company_id'),
                    'location_id'         => self::entero($variables, 'location_id'),
                    'department_id'       => self::entero($variables, 'department_id'),
                    'owner_user_id'       => self::entero($variables, 'owner_user_id'),
                    'assigned_user_id'    => self::entero($variables, 'assigned_user_id'),
                    'status'              => self::texto($variables, 'status'),
                    'resource_type'       => self::texto($variables, 'resource_type'),
                    'expired'             => self::booleano($variables, 'expired') ? 1 : null,
                    'expiring_days'       => self::entero($variables, 'expiring_days'),
                    'never_rotated'       => self::booleano($variables, 'never_rotated') ? 1 : null,
                    'without_owner'       => self::booleano($variables, 'without_owner') ? 1 : null,
                    'without_assignments' => self::booleano($variables, 'without_assignments') ? 1 : null,
                    'date_from'           => self::texto($variables, 'date_from'),
                    'date_to'             => self::texto($variables, 'date_to'),
                    'action'              => self::texto($variables, 'action'),
                    'user_id'             => self::entero($variables, 'user_id'),
                    'result'              => self::texto($variables, 'result'),
                ], static fn ($v) => $v !== null && $v !== '' && $v !== 0),
            ]);

            return [
                'uuid'             => $resultado['uuid'],
                'record_count'     => $resultado['record_count'],
                'included_secrets' => $resultado['included_secrets'],
                'download_url'     => '/reportes/descargar/' . $resultado['uuid'],
            ];
        }, 'Reporte generado', 201);
    }

    /**
     * Devuelve la ruta del archivo listo para enviar.
     *
     * No emite el archivo: el endpoint decide como entregarlo, igual que en
     * Porcify los controladores no escriben en la salida.
     *
     * @return array{path:string,file_name:string}
     */
    public static function descargarController(string $uuid): array
    {
        if (preg_match('/^[a-f0-9\-]{36}$/', $uuid) !== 1) {
            throw HttpException::badRequest('Identificador de reporte invalido.');
        }
        return exportacionModel::download($uuid);
    }

    /** Bitacora de exportaciones. */
    public static function historialController(array $peticion): array
    {
        return self::responder(static function () use ($peticion): array {
            permisoModel::exigirAlguno(['audit.view', 'reports.view']);
            [$pagina, $porPagina] = self::paginacion($peticion, 30);
            $filtros = array_filter([
                'user_id'          => self::entero($peticion, 'user_id'),
                'report_type'      => self::texto($peticion, 'report_type'),
                'included_secrets' => self::texto($peticion, 'included_secrets'),
                'date_from'        => self::texto($peticion, 'date_from'),
                'date_to'          => self::texto($peticion, 'date_to'),
            ], static fn ($v) => $v !== null && $v !== '');
            return exportacionModel::paginate($filtros, $pagina, $porPagina);
        }, 'Historial de exportaciones');
    }
}
