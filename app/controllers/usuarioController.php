<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\Validator;
use app\models\catalogoModel;
use app\models\usuarioModel;

/** Controlador de usuarios (alta, edicion, roles, bajas y restablecimientos). */
class usuarioController extends baseController
{
    private const ESTADOS = ['active', 'inactive', 'locked', 'suspended'];

    public static function listarController(array $peticion): array
    {
        return self::responder(static function () use ($peticion): array {
            [$pagina, $porPagina] = self::paginacion($peticion);
            $filtros = array_filter([
                'search'        => self::texto($peticion, 'q'),
                'status'        => in_array(self::texto($peticion, 'status'), self::ESTADOS, true) ? self::texto($peticion, 'status') : null,
                'role_id'       => self::entero($peticion, 'role_id'),
                'company_id'    => self::entero($peticion, 'company_id'),
                'department_id' => self::entero($peticion, 'department_id'),
            ], static fn ($v) => $v !== null && $v !== '' && $v !== 0);
            return usuarioModel::list($filtros, $pagina, $porPagina) + ['filters' => $filtros];
        }, 'Listado de usuarios');
    }

    public static function verController(int $id): array
    {
        return self::responder(static fn (): array => usuarioModel::show($id), 'Detalle del usuario');
    }

    public static function agregarController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            $r = usuarioModel::create(self::validar($variables), self::listaEnteros($variables, 'roles'));
            // La contrasena temporal se devuelve UNA sola vez, para entregarla
            // por un canal seguro. No queda almacenada en claro en ningun sitio.
            return ['id' => $r['id'], 'temporary_password' => $r['temporary_password']];
        }, 'Usuario creado', 201);
    }

    public static function actualizarController(int $id, array $variables): array
    {
        return self::responder(static function () use ($id, $variables): array {
            $roles = self::listaEnteros($variables, 'roles');
            usuarioModel::update($id, self::validar($variables), $roles === [] ? null : $roles);
            return ['id' => $id];
        }, 'Usuario actualizado');
    }

    public static function permisosController(int $id, array $variables): array
    {
        return self::responder(static function () use ($id, $variables): array {
            $excepciones = [];
            foreach (self::lista($variables, 'allow') as $codigo) { $excepciones[$codigo] = 'allow'; }
            foreach (self::lista($variables, 'deny')  as $codigo) { $excepciones[$codigo] = 'deny'; }
            usuarioModel::setPermissionOverrides($id, $excepciones);
            return ['id' => $id, 'overrides' => count($excepciones)];
        }, 'Excepciones de permisos actualizadas');
    }

    public static function desactivarController(int $id, array $variables): array
    {
        return self::responder(static function () use ($id, $variables): array {
            $r = usuarioModel::deactivate(
                $id,
                self::texto($variables, 'reason') ?: 'Baja del empleado',
                self::entero($variables, 'reassign_to')
            );
            return [
                'revoked'         => $r['revoked'],
                'reassigned'      => $r['reassigned'],
                'sessions_closed' => $r['sessions_closed'],
            ];
        }, 'Usuario desactivado');
    }

    public static function reactivarController(int $id): array
    {
        return self::responder(static function () use ($id): array {
            usuarioModel::reactivate($id);
            return ['id' => $id];
        }, 'Usuario reactivado');
    }

    public static function restablecerController(int $id): array
    {
        return self::responder(
            static fn (): array => ['temporary_password' => usuarioModel::resetPassword($id)],
            'Contrasena restablecida'
        );
    }

    public static function seleccionController(): array
    {
        return self::responder(static function (): array {
            \app\models\permisoModel::exigirAlguno(['users.view', 'credentials.assign']);
            return ['items' => usuarioModel::activeSelectList()];
        }, 'Usuarios');
    }

    /** @return array<string,mixed> */
    private static function validar(array $variables): array
    {
        $v = Validator::make($variables)
            ->nationalId('national_id', 'La cedula')
            ->username('username', 'El nombre de usuario')
            ->email('email', 'El correo electronico')
            ->string('first_name', 'El nombre', 2, 80)
            ->string('last_name', 'El apellido', 2, 80)
            ->string('employee_code', 'El codigo de empleado', 0, 40, false)
            ->phone('phone', 'El telefono', false)
            ->string('position', 'El cargo', 0, 120, false)
            ->integer('company_id', 'La empresa', 1, null, false)
            ->integer('location_id', 'La sede', 1, null, false)
            ->integer('department_id', 'El departamento', 1, null, false)
            ->in('status', 'El estado', self::ESTADOS, false)
            ->text('notes', 'Las notas', 500, false)
            ->bool('mfa_enforced')
            ->validated();

        $v['mfa_enforced'] = $v['mfa_enforced'] ? 1 : 0;
        if ($v['status'] === null) {
            unset($v['status']);
        }
        return $v;
    }
}
