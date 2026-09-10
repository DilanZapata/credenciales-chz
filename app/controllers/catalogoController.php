<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\HttpException;
use App\Core\Validator;
use app\models\auditoriaModel;
use app\models\catalogoModel;
use app\models\configuracionModel;
use app\models\contextoModel;
use app\models\permisoModel;

/** Catalogos: categorias, organizacion, roles y politicas de seguridad. */
class catalogoController extends baseController
{
    // -------------------------- Categorias ---------------------------

    public static function categoriasController(array $peticion): array
    {
        return self::responder(static function () use ($peticion): array {
            permisoModel::exigirAlguno(['categories.manage', 'credentials.view', 'systems.view']);
            $todas = self::booleano($peticion, 'all') && permisoModel::puede('categories.manage');
            return ['items' => catalogoModel::categories(!$todas)];
        }, 'Categorias');
    }

    public static function guardarCategoriaController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            permisoModel::exigir('categories.manage');
            $datos = Validator::make($variables)
                ->string('name', 'El nombre', 2, 120)
                ->string('description', 'La descripcion', 0, 255, false)
                ->string('color', 'El color', 0, 9, false)
                ->string('icon', 'El icono', 0, 40, false)
                ->integer('sort_order', 'El orden', 0, 999, false)
                ->bool('is_active')
                ->validated();

            // Color e icono llegan a atributos HTML; se restringen a un formato
            // conocido en vez de confiar en el escape de la vista.
            $datos['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($datos['color'] ?? '')) === 1 ? $datos['color'] : '#64748b';
            $datos['icon']  = preg_match('/^[a-z\-]{1,40}$/', (string) ($datos['icon'] ?? '')) === 1 ? $datos['icon'] : 'folder';

            $id = self::entero($variables, 'id');
            if ($id !== null && $id > 0) {
                catalogoModel::updateCategory($id, $datos);
                $accion = 'actualizada';
            } else {
                $id     = catalogoModel::createCategory($datos);
                $accion = 'creada';
            }
            auditoriaModel::registrar(auditoriaModel::CATEGORY_MANAGED, 'category', $id, (string) $datos['name'],
                'success', ['accion' => $accion], 'notice');
            return ['id' => $id, 'accion' => $accion];
        }, 'Categoria guardada');
    }

    // ------------------------- Organizacion --------------------------

    public static function organizacionController(array $peticion): array
    {
        return self::responder(static function () use ($peticion): array {
            permisoModel::exigirAlguno(['org.manage', 'users.view', 'systems.view', 'credentials.view']);
            $todas = self::booleano($peticion, 'all') && permisoModel::puede('org.manage');
            return [
                'companies'   => catalogoModel::companies(!$todas),
                'locations'   => catalogoModel::locations(self::entero($peticion, 'company_id'), !$todas),
                'departments' => catalogoModel::departments(self::entero($peticion, 'company_id'), !$todas),
            ];
        }, 'Estructura organizacional');
    }

    public static function guardarEmpresaController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            permisoModel::exigir('org.manage');
            $datos = Validator::make($variables)
                ->string('name', 'El nombre', 2, 150)
                ->string('legal_name', 'La razon social', 0, 200, false)
                ->string('tax_id', 'El NIT', 0, 50, false)
                ->bool('is_active')
                ->validated();
            $id = self::entero($variables, 'id');
            if ($id !== null && $id > 0) {
                catalogoModel::updateCompany($id, $datos);
            } else {
                $id = catalogoModel::createCompany($datos);
            }
            auditoriaModel::registrar(auditoriaModel::ORG_MANAGED, 'company', $id, (string) $datos['name'], 'success', [], 'notice');
            return ['id' => $id];
        }, 'Empresa guardada');
    }

    public static function guardarSedeController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            permisoModel::exigir('org.manage');
            $datos = Validator::make($variables)
                ->integer('company_id', 'La empresa', 1)
                ->string('name', 'El nombre', 2, 150)
                ->string('address', 'La direccion', 0, 255, false)
                ->string('city', 'La ciudad', 0, 100, false)
                ->string('country', 'El pais', 0, 100, false)
                ->bool('is_active')
                ->validated();
            $id = self::entero($variables, 'id');
            if ($id !== null && $id > 0) {
                catalogoModel::updateLocation($id, $datos);
            } else {
                $id = catalogoModel::createLocation($datos);
            }
            auditoriaModel::registrar(auditoriaModel::ORG_MANAGED, 'location', $id, (string) $datos['name'], 'success', [], 'notice');
            return ['id' => $id];
        }, 'Sede guardada');
    }

    public static function guardarDepartamentoController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            permisoModel::exigir('org.manage');
            $datos = Validator::make($variables)
                ->integer('company_id', 'La empresa', 1)
                ->integer('location_id', 'La sede', 1, null, false)
                ->string('name', 'El nombre', 2, 150)
                ->string('code', 'El codigo', 0, 40, false)
                ->bool('is_active')
                ->validated();
            $id = self::entero($variables, 'id');
            if ($id !== null && $id > 0) {
                catalogoModel::updateDepartment($id, $datos);
            } else {
                $id = catalogoModel::createDepartment($datos);
            }
            auditoriaModel::registrar(auditoriaModel::ORG_MANAGED, 'department', $id, (string) $datos['name'], 'success', [], 'notice');
            return ['id' => $id];
        }, 'Departamento guardado');
    }

    // ---------------------------- Roles ------------------------------

    public static function rolesController(): array
    {
        return self::responder(static function (): array {
            permisoModel::exigir('roles.view');
            $roles  = catalogoModel::roles();
            $matriz = [];
            foreach ($roles as $rol) {
                $matriz[(int) $rol['id']] = catalogoModel::rolePermissionCodes((int) $rol['id']);
            }
            return [
                'roles'       => $roles,
                'permissions' => catalogoModel::permissions(),
                'matrix'      => $matriz,
                'can_manage'  => permisoModel::puede('roles.manage'),
            ];
        }, 'Roles y permisos');
    }

    public static function guardarRolController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            permisoModel::exigir('roles.manage');
            $datos = Validator::make($variables)
                ->string('name', 'El nombre', 2, 80)
                ->string('code', 'El codigo', 2, 40, false)
                ->string('description', 'La descripcion', 0, 255, false)
                ->integer('level', 'El nivel', 1, 99)
                ->bool('requires_mfa')
                ->bool('is_active')
                ->validated();

            // Nadie puede crear un rol de nivel igual o superior al suyo.
            if (!contextoModel::esSuperadministrador() && (int) $datos['level'] >= contextoModel::nivel()) {
                throw HttpException::forbidden('No puede crear roles de nivel igual o superior al suyo.');
            }

            $id = self::entero($variables, 'id');
            if ($id !== null && $id > 0) {
                if (catalogoModel::findRole($id) === null) {
                    throw HttpException::notFound('El rol no existe.');
                }
                catalogoModel::updateRole($id, $datos);
            } else {
                if (($datos['code'] ?? '') === '') {
                    throw HttpException::badRequest('Debe indicar el codigo del rol.');
                }
                $id = catalogoModel::createRole($datos);
            }
            auditoriaModel::registrar(auditoriaModel::ROLE_MANAGED, 'role', $id, (string) $datos['name'], 'success', [], 'warning');
            return ['id' => $id];
        }, 'Rol guardado');
    }

    public static function permisosRolController(int $idRol, array $variables): array
    {
        return self::responder(static function () use ($idRol, $variables): array {
            permisoModel::exigir('roles.manage');
            $rol = catalogoModel::findRole($idRol);
            if ($rol === null) {
                throw HttpException::notFound('El rol no existe.');
            }
            $codigos = self::lista($variables, 'permissions');
            if (!contextoModel::esSuperadministrador()) {
                if ((int) $rol['level'] >= contextoModel::nivel()) {
                    throw HttpException::forbidden('No puede modificar un rol de nivel igual o superior al suyo.');
                }
                // Ni conceder a un rol permisos que uno mismo no posee.
                foreach ($codigos as $codigo) {
                    if (!contextoModel::puede($codigo)) {
                        throw HttpException::forbidden('No puede otorgar el permiso: ' . $codigo);
                    }
                }
            }

            $antes = catalogoModel::rolePermissionCodes($idRol);
            catalogoModel::setRolePermissions($idRol, $codigos);
            $despues = catalogoModel::rolePermissionCodes($idRol);

            auditoriaModel::registrar(auditoriaModel::ROLE_MANAGED, 'role', $idRol, (string) $rol['name'], 'success', [
                'otorgados' => array_values(array_diff($despues, $antes)),
                'retirados' => array_values(array_diff($antes, $despues)),
            ], 'critical');
            return ['id' => $idRol, 'permissions' => $despues];
        }, 'Matriz de permisos actualizada');
    }

    // ------------------------- Configuracion --------------------------

    public static function configuracionController(): array
    {
        return self::responder(static function (): array {
            permisoModel::exigir('settings.manage');
            $agrupados = [];
            foreach (configuracionModel::agrupados() as $fila) {
                $agrupados[(string) $fila['group_name']][] = $fila;
            }
            return ['groups' => $agrupados];
        }, 'Configuracion');
    }

    public static function guardarConfiguracionController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            permisoModel::exigir('settings.manage');
            permisoModel::exigirReautenticacion('secret');

            $cambios = [];
            foreach (configuracionModel::agrupados() as $fila) {
                $clave = (string) $fila['setting_key'];
                if (!array_key_exists($clave, $variables)) {
                    // Las casillas no marcadas no llegan en la peticion.
                    if ($fila['value_type'] === 'bool' && (string) $fila['setting_value'] !== '0') {
                        configuracionModel::fijar($clave, '0', contextoModel::id());
                        $cambios[$clave] = '0';
                    }
                    continue;
                }
                $crudo = (string) ($variables[$clave] ?? '');
                $valor = match ((string) $fila['value_type']) {
                    'int'  => (string) max(0, (int) $crudo),
                    'bool' => in_array(strtolower($crudo), ['1', 'true', 'on', 'yes', 'si'], true) ? '1' : '0',
                    default => mb_substr($crudo, 0, 500),
                };
                if ($valor !== (string) $fila['setting_value']) {
                    configuracionModel::fijar($clave, $valor, contextoModel::id());
                    $cambios[$clave] = $valor;
                }
            }

            if ($cambios !== []) {
                auditoriaModel::registrar(auditoriaModel::SETTINGS_UPDATED, 'settings', null,
                    'Politicas de seguridad', 'success', ['cambios' => $cambios], 'critical');
            }
            return ['changed' => array_keys($cambios)];
        }, 'Configuracion actualizada');
    }
}
