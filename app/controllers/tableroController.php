<?php
declare(strict_types=1);

namespace app\controllers;

use app\models\alertaModel;
use app\models\asignacionModel;
use app\models\auditoriaConsultaModel;
use app\models\configuracionModel;
use app\models\contextoModel;
use app\models\permisoModel;
use app\models\sesionModel;
use app\models\tableroModel;
use app\models\usuarioModel;

/** Tableros: panel administrativo completo y panel del consultor. */
class tableroController extends baseController
{
    public static function panelController(): array
    {
        return self::responder(static function (): array {
            permisoModel::exigir('dashboard.view');
            $diasAviso = configuracionModel::entero('alerts.expiry_warning_days', 15);
            $veAuditoria = permisoModel::puede('audit.view');

            return [
                'credential_stats' => tableroModel::credentialCounters($diasAviso),
                'user_stats'       => usuarioModel::counters(),
                'system_stats'     => tableroModel::systemCounters(),
                'by_category'      => tableroModel::credentialsByCategory(),
                'expiring'         => tableroModel::expiringCredentials(max(30, $diasAviso), 12),
                'recent_changes'   => tableroModel::recentCredentialChanges(8),
                'recent_secrets'   => $veAuditoria ? auditoriaConsultaModel::recentSecretAccess(8) : [],
                'recent_exports'   => $veAuditoria ? tableroModel::recentExports(6) : [],
                'recent_logins'    => $veAuditoria ? auditoriaConsultaModel::recentLoginAttempts(8) : [],
                'alerts'           => alertaModel::evaluate(),
                'security_events'  => permisoModel::puede('security.events.view')
                                        ? auditoriaConsultaModel::securityEvents(['status' => 'open'], 6) : [],
                'top_users'        => asignacionModel::topUsersByAccess(6),
                'active_sessions'  => sesionModel::contarActivas(),
                'failed_logins_24h'=> auditoriaConsultaModel::failedLoginsSince(date('Y-m-d H:i:s', time() - 86400)),
                'access_series'    => tableroModel::secretAccessSeries(14),
            ];
        }, 'Panel de control');
    }

    /** Panel del consultor: sencillo y centrado en "mis accesos". */
    public static function misAccesosController(array $peticion): array
    {
        return self::responder(static function () use ($peticion): array {
            permisoModel::exigir('credentials.view');
            $items = asignacionModel::forUser((int) contextoModel::id(), true);

            // El filtro se aplica sobre los accesos que ya son suyos, asi que
            // no puede usarse para descubrir credenciales ajenas.
            $busqueda = self::texto($peticion, 'q');
            if ($busqueda !== '') {
                $aguja = mb_strtolower($busqueda);
                $items = array_values(array_filter($items, static function (array $fila) use ($aguja): bool {
                    return str_contains(mb_strtolower((string) $fila['system_name']), $aguja)
                        || str_contains(mb_strtolower((string) $fila['credential_name']), $aguja)
                        || str_contains(mb_strtolower((string) ($fila['username'] ?? '')), $aguja)
                        || str_contains(mb_strtolower((string) ($fila['category_name'] ?? '')), $aguja);
                }));
            }

            return ['items' => $items, 'search' => $busqueda];
        }, 'Mis accesos');
    }
}
