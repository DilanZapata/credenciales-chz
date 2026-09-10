<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\HttpException;
use App\Core\ValidationException;
use app\models\credencialModel;
use app\models\mainModel;
use Throwable;

/**
 * Controlador de credenciales.
 *
 * Capa fina, como en Porcify Manager: valida el formato de la entrada,
 * llama al modelo y devuelve el arreglo de respuesta. La logica de negocio
 * y la decision de autorizacion viven en `credencialModel`.
 */
class credencialController extends baseController
{
    private const ESTADOS  = ['active', 'inactive', 'expired', 'revoked', 'archived'];
    private const ENTORNOS = ['production', 'staging', 'development', 'other'];

    /** @param array<string,mixed> $peticion */
    public static function listarController(array $peticion): array
    {
        return self::responder(static function () use ($peticion): array {
            $pagina   = max(1, (int) ($peticion['page'] ?? 1));
            $porPagina = max(5, min(100, (int) ($peticion['per_page'] ?? 25)));
            $filtros   = self::filtros($peticion);
            // Los filtros aplicados viajan en la respuesta: la vista repuebla
            // con ellos su formulario y el cliente sabe que se aplico.
            return credencialModel::list($filtros, $pagina, $porPagina) + ['filters' => $filtros];
        }, 'Listado de credenciales');
    }

    public static function verController(int $id): array
    {
        return self::responder(static fn (): array => credencialModel::show($id), 'Detalle de la credencial');
    }

    public static function historialController(int $id): array
    {
        return self::responder(static fn (): array => credencialModel::history($id), 'Historial de la credencial');
    }

    public static function asignacionesController(int $id): array
    {
        return self::responder(static fn (): array => ['items' => credencialModel::assignments($id)], 'Asignaciones');
    }

    /** @param array<string,mixed> $variables */
    public static function agregarController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            $datos  = self::validarFicha($variables, true);
            $secreto = (string) ($variables['password'] ?? '');
            $id     = credencialModel::create($datos, $secreto);
            return ['id' => $id];
        }, 'Credencial registrada', 201);
    }

    /** @param array<string,mixed> $variables */
    public static function actualizarController(int $id, array $variables): array
    {
        return self::responder(static function () use ($id, $variables): array {
            credencialModel::update($id, self::validarFicha($variables, false));
            return ['id' => $id];
        }, 'Credencial actualizada');
    }

    /** @param array<string,mixed> $variables */
    public static function rotarController(int $id, array $variables): array
    {
        return self::responder(static function () use ($id, $variables): array {
            $version = credencialModel::rotatePassword(
                $id,
                (string) ($variables['password'] ?? ''),
                self::texto($variables, 'reason') ?: null,
                isset($variables['rotation_period_days']) && $variables['rotation_period_days'] !== ''
                    ? (int) $variables['rotation_period_days'] : null
            );
            return ['version' => $version];
        }, 'Contrasena actualizada');
    }

    /** @param array<string,mixed> $variables */
    public static function eliminarController(int $id, array $variables): array
    {
        return self::responder(static function () use ($id, $variables): array {
            credencialModel::delete($id, self::texto($variables, 'reason') ?: 'Sin motivo indicado');
            return ['id' => $id];
        }, 'Credencial dada de baja');
    }

    public static function restaurarController(int $id): array
    {
        return self::responder(static function () use ($id): array {
            credencialModel::restore($id);
            return ['id' => $id];
        }, 'Credencial reactivada');
    }

    /** @param array<string,mixed> $variables */
    public static function asignarController(int $id, array $variables): array
    {
        return self::responder(static function () use ($id, $variables): array {
            $idUsuario = (int) ($variables['user_id'] ?? 0);
            if ($idUsuario <= 0) {
                throw new ValidationException(['user_id' => 'Debe indicar el usuario.']);
            }
            credencialModel::assign($id, $idUsuario, [
                'can_view_secret'   => self::booleano($variables, 'can_view_secret', true) ? 1 : 0,
                'can_copy_secret'   => self::booleano($variables, 'can_copy_secret', true) ? 1 : 0,
                'can_view_recovery' => self::booleano($variables, 'can_view_recovery', false) ? 1 : 0,
                'expires_at'        => self::texto($variables, 'expires_at') ?: null,
                'reason'            => self::texto($variables, 'reason') ?: null,
            ]);
            return ['credential_id' => $id, 'user_id' => $idUsuario];
        }, 'Acceso asignado');
    }

    /** @param array<string,mixed> $variables */
    public static function revocarController(int $id, int $idUsuario, array $variables): array
    {
        return self::responder(static function () use ($id, $idUsuario, $variables): array {
            credencialModel::revoke($id, $idUsuario, self::texto($variables, 'reason') ?: 'Revocacion administrativa');
            return ['credential_id' => $id, 'user_id' => $idUsuario];
        }, 'Acceso revocado');
    }

    // -----------------------------------------------------------------
    //  Auxiliares
    // -----------------------------------------------------------------


    /** @param array<string,mixed> $peticion */
    private static function filtros(array $peticion): array
    {
        $filtros = [
            'search'              => self::texto($peticion, 'q'),
            'category_id'         => self::entero($peticion, 'category_id'),
            'system_id'           => self::entero($peticion, 'system_id'),
            'company_id'          => self::entero($peticion, 'company_id'),
            'location_id'         => self::entero($peticion, 'location_id'),
            'department_id'       => self::entero($peticion, 'department_id'),
            'owner_user_id'       => self::entero($peticion, 'owner_user_id'),
            'assigned_user_id'    => self::entero($peticion, 'assigned_user_id'),
            'resource_type'       => self::texto($peticion, 'resource_type'),
            'status'              => in_array(self::texto($peticion, 'status'), self::ESTADOS, true) ? self::texto($peticion, 'status') : null,
            'expiring_days'       => self::entero($peticion, 'expiring_days'),
            'expired'             => self::booleano($peticion, 'expired') ? 1 : null,
            'never_rotated'       => self::booleano($peticion, 'never_rotated') ? 1 : null,
            'without_owner'       => self::booleano($peticion, 'without_owner') ? 1 : null,
            'without_assignments' => self::booleano($peticion, 'without_assignments') ? 1 : null,
            'sort'                => self::texto($peticion, 'sort'),
            'direction'           => self::texto($peticion, 'direction'),
        ];
        return array_filter($filtros, static fn ($v) => $v !== null && $v !== '' && $v !== 0);
    }

    /**
     * Valida la ficha. Las reglas son las mismas que aplicaba el formulario
     * web: no se relajan por cambiar de capa.
     *
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     */
    private static function validarFicha(array $variables, bool $sistemaObligatorio): array
    {
        $v = \App\Core\Validator::make($variables)
            ->integer('system_id', 'El sistema', 1, null, $sistemaObligatorio)
            ->string('name', 'El nombre de la credencial', 2, 180)
            ->in('environment', 'El entorno', self::ENTORNOS, false)
            ->string('username', 'El usuario', 0, 190, false)
            ->email('email', 'El correo electronico', false)
            ->string('domain', 'El dominio', 0, 190, false)
            ->string('admin_username', 'El usuario administrador', 0, 190, false)
            ->string('auth_method', 'El metodo de autenticacion', 0, 80, false)
            ->email('recovery_email', 'El correo de recuperacion', false)
            ->phone('recovery_phone', 'El telefono de recuperacion', false)
            ->string('recovery_username', 'El usuario de recuperacion', 0, 190, false)
            ->text('recovery_notes', 'La informacion de recuperacion', 3000, false)
            ->text('observations', 'Las observaciones', 3000, false)
            ->integer('owner_user_id', 'El responsable', 1, null, false)
            ->in('status', 'El estado', self::ESTADOS, false)
            ->integer('rotation_period_days', 'El periodo de rotacion', 0, 3650, false)
            ->date('expires_at', 'La fecha de vencimiento', false)
            ->bool('has_security_questions')
            ->validated();

        $datos = [];
        foreach ($v as $clave => $valor) {
            if (array_key_exists($clave, $variables) || $clave === 'has_security_questions') {
                $datos[$clave] = $valor;
            }
        }
        if (isset($datos['has_security_questions'])) {
            $datos['has_security_questions'] = $datos['has_security_questions'] ? 1 : 0;
        }
        foreach (['status', 'environment'] as $opcional) {
            if (array_key_exists($opcional, $datos) && $datos[$opcional] === null) {
                unset($datos[$opcional]);
            }
        }
        if (self::texto($variables, 'change_reason') !== '') {
            $datos['change_reason'] = self::texto($variables, 'change_reason');
        }
        return $datos;
    }



}
