<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\HttpException;
use App\Core\Validator;
use app\models\auditoriaModel;
use app\models\contextoModel;
use app\models\permisoModel;
use app\models\sistemaModel;

/** Controlador del inventario de sistemas. */
class sistemaController extends baseController
{
    private const TIPOS = ['web', 'application', 'software', 'computer', 'server', 'email', 'network',
                           'cloud', 'social', 'banking', 'license', 'database', 'other'];
    private const CRITICIDAD = ['low', 'medium', 'high', 'critical'];
    private const ESTADOS    = ['active', 'inactive', 'archived'];

    public static function listarController(array $peticion): array
    {
        return self::responder(static function () use ($peticion): array {
            permisoModel::exigir('systems.view');
            [$pagina, $porPagina] = self::paginacion($peticion);
            $filtros = array_filter([
                'search'        => self::texto($peticion, 'q'),
                'category_id'   => self::entero($peticion, 'category_id'),
                'company_id'    => self::entero($peticion, 'company_id'),
                'location_id'   => self::entero($peticion, 'location_id'),
                'department_id' => self::entero($peticion, 'department_id'),
                'resource_type' => in_array(self::texto($peticion, 'resource_type'), self::TIPOS, true) ? self::texto($peticion, 'resource_type') : null,
                'status'        => in_array(self::texto($peticion, 'status'), self::ESTADOS, true) ? self::texto($peticion, 'status') : null,
                'without_owner' => self::booleano($peticion, 'without_owner') ? 1 : null,
                'sort'          => self::texto($peticion, 'sort'),
                'direction'     => self::texto($peticion, 'direction'),
            ], static fn ($v) => $v !== null && $v !== '' && $v !== 0);
            return sistemaModel::paginate($filtros, $pagina, $porPagina) + ['filters' => $filtros];
        }, 'Listado de sistemas');
    }

    public static function verController(int $id): array
    {
        return self::responder(static function () use ($id): array {
            permisoModel::exigir('systems.view');
            $sistema = sistemaModel::find($id);
            if ($sistema === null) {
                throw HttpException::notFound('El sistema no existe.');
            }
            return $sistema;
        }, 'Detalle del sistema');
    }

    public static function agregarController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            permisoModel::exigir('systems.create');
            $datos = self::validar($variables);
            $datos['created_by'] = contextoModel::id();
            $id = sistemaModel::create($datos);
            auditoriaModel::registrar(auditoriaModel::SYSTEM_CREATED, 'system', $id, (string) $datos['name'], 'success', [], 'notice');
            return ['id' => $id];
        }, 'Sistema registrado', 201);
    }

    public static function actualizarController(int $id, array $variables): array
    {
        return self::responder(static function () use ($id, $variables): array {
            permisoModel::exigir('systems.update');
            $antes = sistemaModel::find($id);
            if ($antes === null) {
                throw HttpException::notFound('El sistema no existe.');
            }
            $datos = self::validar($variables);
            sistemaModel::update($id, $datos, contextoModel::id());
            auditoriaModel::registrar(auditoriaModel::SYSTEM_UPDATED, 'system', $id, (string) $antes['name'],
                'success', ['campos' => array_keys($datos)], 'notice');
            return ['id' => $id];
        }, 'Sistema actualizado');
    }

    public static function archivarController(int $id, array $variables): array
    {
        return self::responder(static function () use ($id, $variables): array {
            permisoModel::exigir('systems.delete');
            $sistema = sistemaModel::find($id);
            if ($sistema === null) {
                throw HttpException::notFound('El sistema no existe.');
            }
            sistemaModel::archive($id, contextoModel::id());
            auditoriaModel::registrar(auditoriaModel::SYSTEM_DELETED, 'system', $id, (string) $sistema['name'],
                'success', ['motivo' => self::texto($variables, 'reason')], 'warning');
            return ['id' => $id];
        }, 'Sistema archivado');
    }

    public static function seleccionController(): array
    {
        return self::responder(static function (): array {
            permisoModel::exigirAlguno(['systems.view', 'credentials.view']);
            return ['items' => sistemaModel::selectList()];
        }, 'Sistemas');
    }

    private static function validar(array $variables): array
    {
        return Validator::make($variables)
            ->string('name', 'El nombre del sistema', 2, 180)
            ->string('display_name', 'El nombre descriptivo', 0, 180, false)
            ->string('code', 'El codigo', 0, 60, false)
            ->text('description', 'La descripcion', 3000, false)
            ->integer('category_id', 'La categoria', 1, null, false)
            ->in('resource_type', 'El tipo de recurso', self::TIPOS, false)
            ->integer('company_id', 'La empresa', 1, null, false)
            ->integer('location_id', 'La sede', 1, null, false)
            ->integer('department_id', 'El departamento', 1, null, false)
            ->url('url', 'La URL', false)
            ->ip('ip_address', 'La direccion IP', false)
            ->integer('port', 'El puerto', 1, 65535, false)
            ->string('hostname', 'El nombre del servidor', 0, 180, false)
            ->string('platform', 'La plataforma', 0, 120, false)
            ->string('provider', 'El proveedor', 0, 120, false)
            ->integer('owner_user_id', 'El responsable', 1, null, false)
            ->in('criticality', 'La criticidad', self::CRITICIDAD, false)
            ->in('status', 'El estado', self::ESTADOS, false)
            ->validated();
    }
}
