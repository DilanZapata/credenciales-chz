<?php
declare(strict_types=1);

namespace app\controllers;

use app\models\auditoriaConsultaModel;
use app\models\contextoModel;
use app\models\permisoModel;

/** Consulta de la bitacora de auditoria y de los eventos de seguridad. */
class auditoriaController extends baseController
{
    private const RESULTADOS = ['success', 'failure', 'denied'];
    private const SEVERIDADES = ['info', 'notice', 'warning', 'critical'];

    public static function listarController(array $peticion): array
    {
        return self::responder(static function () use ($peticion): array {
            permisoModel::exigir('audit.view');
            [$pagina, $porPagina] = self::paginacion($peticion, 50);
            return auditoriaConsultaModel::paginate(self::filtros($peticion), $pagina, $porPagina);
        }, 'Auditoria');
    }

    public static function accionesController(): array
    {
        return self::responder(static function (): array {
            permisoModel::exigir('audit.view');
            return ['items' => auditoriaConsultaModel::distinctActions()];
        }, 'Acciones registradas');
    }

    public static function eventosController(array $peticion): array
    {
        return self::responder(static function () use ($peticion): array {
            permisoModel::exigir('security.events.view');
            return ['items' => auditoriaConsultaModel::securityEvents([
                'status'   => self::texto($peticion, 'status') ?: null,
                'severity' => in_array(self::texto($peticion, 'severity'), self::SEVERIDADES, true) ? self::texto($peticion, 'severity') : null,
            ], 200)];
        }, 'Eventos de seguridad');
    }

    public static function resolverEventoController(int $id): array
    {
        return self::responder(static function () use ($id): array {
            permisoModel::exigir('security.events.view');
            auditoriaConsultaModel::resolveSecurityEvent($id, (int) contextoModel::id());
            return ['id' => $id];
        }, 'Evento resuelto');
    }

    /** @return array<string,mixed> */
    private static function filtros(array $peticion): array
    {
        return array_filter([
            'user_id'      => self::entero($peticion, 'user_id'),
            'national_id'  => self::texto($peticion, 'national_id'),
            'action'       => self::texto($peticion, 'action'),
            'action_group' => self::texto($peticion, 'action_group'),
            'entity_type'  => self::texto($peticion, 'entity_type'),
            'entity_id'    => self::texto($peticion, 'entity_id'),
            'result'       => in_array(self::texto($peticion, 'result'), self::RESULTADOS, true) ? self::texto($peticion, 'result') : null,
            'severity'     => in_array(self::texto($peticion, 'severity'), self::SEVERIDADES, true) ? self::texto($peticion, 'severity') : null,
            'ip'           => self::texto($peticion, 'ip'),
            'date_from'    => self::texto($peticion, 'date_from'),
            'date_to'      => self::texto($peticion, 'date_to'),
            'search'       => self::texto($peticion, 'q'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== 0);
    }
}
