<?php
declare(strict_types=1);

namespace app\models;

/**
 * Modelo de dominio (patron de Porcify Manager: la logica de negocio vive
 * en el modelo, junto al acceso a datos).
 */
class alertaModel extends mainModel
{

    // =================================================================
    //  Logica de negocio (fusionada desde AlertService.php)
    // =================================================================


    /**
     * Calcula las alertas vigentes sin escribir nada (para el tablero).
     *
     * @return array<int,array{type:string,severity:string,title:string,message:string,count:int,link:?string}>
     */
    public static function evaluate(): array
    {
        $warningDays = max(1, configuracionModel::entero('alerts.expiry_warning_days', 15));
        $alerts      = [];

        $expired = (int) self::obtenerValor(
            "SELECT COUNT(*) FROM credentials
              WHERE deleted_at IS NULL AND status = 'active'
                AND expires_at IS NOT NULL AND expires_at < CURDATE()"
        );
        if ($expired > 0) {
            $alerts[] = self::alert('credential_expired', 'critical', 'Credenciales vencidas',
                "Existen {$expired} credenciales cuya fecha de vencimiento ya paso.", $expired, '/credenciales?expired=1');
        }

        $expiring = (int) self::obtenerValor(
            "SELECT COUNT(*) FROM credentials
              WHERE deleted_at IS NULL AND status = 'active'
                AND expires_at IS NOT NULL
                AND expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)",
            [$warningDays]
        );
        if ($expiring > 0) {
            $alerts[] = self::alert('credential_expiring', 'warning', 'Credenciales proximas a vencer',
                "{$expiring} credenciales vencen en los proximos {$warningDays} dias.", $expiring,
                '/credenciales?expiring_days=' . $warningDays);
        }

        $rotationDue = (int) self::obtenerValor(
            "SELECT COUNT(*) FROM credentials
              WHERE deleted_at IS NULL AND status = 'active'
                AND next_rotation_at IS NOT NULL AND next_rotation_at < CURDATE()"
        );
        if ($rotationDue > 0) {
            $alerts[] = self::alert('rotation_due', 'warning', 'Contrasenas que requieren actualizacion',
                "{$rotationDue} credenciales superaron su periodo de rotacion.", $rotationDue, '/credenciales?expired=1');
        }

        $neverRotated = (int) self::obtenerValor(
            "SELECT COUNT(*) FROM credentials
              WHERE deleted_at IS NULL AND status = 'active' AND password_changed_at IS NULL"
        );
        if ($neverRotated > 0) {
            $alerts[] = self::alert('never_rotated', 'info', 'Credenciales nunca actualizadas',
                "{$neverRotated} credenciales no registran ningun cambio de contrasena.", $neverRotated,
                '/credenciales?never_rotated=1');
        }

        $withoutOwner = (int) self::obtenerValor(
            "SELECT COUNT(*) FROM credentials WHERE deleted_at IS NULL AND status = 'active' AND owner_user_id IS NULL"
        );
        if ($withoutOwner > 0) {
            $alerts[] = self::alert('without_owner', 'warning', 'Credenciales sin responsable',
                "{$withoutOwner} credenciales activas no tienen un responsable asignado.", $withoutOwner,
                '/credenciales?without_owner=1');
        }

        $withoutAssignments = (int) self::obtenerValor(
            "SELECT COUNT(*) FROM credentials c
              WHERE c.deleted_at IS NULL AND c.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM credential_assignments ca
                                 WHERE ca.credential_id = c.id AND ca.is_active = 1)"
        );
        if ($withoutAssignments > 0) {
            $alerts[] = self::alert('without_assignments', 'info', 'Credenciales sin usuarios asignados',
                "{$withoutAssignments} credenciales activas no estan asignadas a ningun usuario.", $withoutAssignments,
                '/credenciales?without_assignments=1');
        }

        $ghosts = usuarioModel::inactiveWithActiveAssignments();
        if ($ghosts !== []) {
            $alerts[] = self::alert('inactive_with_access', 'critical', 'Usuarios inactivos con accesos vigentes',
                count($ghosts) . ' usuarios desactivados conservan asignaciones activas.', count($ghosts), '/usuarios?status=inactive');
        }

        $threshold    = max(1, configuracionModel::entero('alerts.failed_login_threshold', 10));
        $failedLogins = (int) self::obtenerValor(
            "SELECT COUNT(*) FROM login_attempts
              WHERE result IN ('failed','locked','mfa_failed') AND attempted_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        if ($failedLogins >= $threshold) {
            $alerts[] = self::alert('failed_logins', 'critical', 'Multiples intentos de acceso fallidos',
                "Se registraron {$failedLogins} intentos fallidos en la ultima hora.", $failedLogins, '/auditoria?result=failure');
        }

        $reused = credencialModel::reusedSecrets();
        if ($reused !== []) {
            $alerts[] = self::alert('reused_password', 'warning', 'Contrasenas reutilizadas',
                'Se detectaron ' . count($reused) . ' contrasenas usadas en mas de una credencial.', count($reused), '/credenciales');
        }

        $openEvents = (int) self::obtenerValor("SELECT COUNT(*) FROM security_events WHERE status = 'open'");
        if ($openEvents > 0) {
            $alerts[] = self::alert('security_events', 'warning', 'Eventos de seguridad sin atender',
                "{$openEvents} eventos de seguridad permanecen abiertos.", $openEvents, '/seguridad/eventos');
        }

        return $alerts;
    }

    /**
     * Persiste las alertas como notificaciones dirigidas a los roles
     * administrativos y, si esta habilitado, las envia por correo.
     */
    public static function dispatch(): int
    {
        $alerts   = self::evaluate();
        $roleIds  = array_column(
            self::obtenerFilas("SELECT id FROM roles WHERE code IN ('SUPERADMIN','ADMIN')"),
            'id'
        );
        $today = date('Y-m-d');
        $sent  = 0;

        foreach ($alerts as $alert) {
            foreach ($roleIds as $roleId) {
                notificacionModel::push([
                    'role_id'    => (int) $roleId,
                    'type'       => $alert['type'],
                    'severity'   => $alert['severity'] === 'critical' ? 'critical'
                                    : ($alert['severity'] === 'warning' ? 'warning' : 'info'),
                    'title'      => $alert['title'],
                    'message'    => $alert['message'],
                    'link'       => $alert['link'],
                    'dedupe_key' => $alert['type'] . ':' . $roleId . ':' . $today,
                ]);
            }
            $sent++;
        }

        if ($sent > 0 && correoModel::enabled()) {
            correoModel::sendAlertDigest($alerts);
        }

        return $sent;
    }

    private static function alert(string $type, string $severity, string $title, string $message, int $count, ?string $link): array
    {
        return compact('type', 'severity', 'title', 'message', 'count', 'link');
    }
}
