<?php
declare(strict_types=1);

namespace app\models;


/** Asignacion de credenciales a usuarios: el alcance de datos del consultor. */
class asignacionModel extends mainModel
{
    /** @return array<int,array<string,mixed>> */
    public static function forCredential(int $credentialId, bool $onlyActive = false): array
    {
        return self::obtenerFilas(
            'SELECT ca.*, u.first_name, u.last_name, u.national_id, u.username, u.email, u.status AS user_status,
                    CONCAT_WS(" ", gb.first_name, gb.last_name) AS granted_by_name,
                    CONCAT_WS(" ", rb.first_name, rb.last_name) AS revoked_by_name
               FROM credential_assignments ca
               JOIN users u ON u.id = ca.user_id
               LEFT JOIN users gb ON gb.id = ca.granted_by
               LEFT JOIN users rb ON rb.id = ca.revoked_by
              WHERE ca.credential_id = ?' . ($onlyActive ? ' AND ca.is_active = 1' : '') . '
              ORDER BY ca.is_active DESC, u.first_name, u.last_name',
            [$credentialId]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forUser(int $userId, bool $onlyActive = true): array
    {
        return self::obtenerFilas(
            'SELECT ca.*, c.name AS credential_name, c.username, c.status AS credential_status,
                    s.name AS system_name, s.url, cat.name AS category_name, cat.color AS category_color
               FROM credential_assignments ca
               JOIN credentials c ON c.id = ca.credential_id
               JOIN systems s     ON s.id = c.system_id
               LEFT JOIN categories cat ON cat.id = s.category_id
              WHERE ca.user_id = ?' . ($onlyActive ? ' AND ca.is_active = 1 AND c.deleted_at IS NULL' : '') . '
              ORDER BY s.name, c.name',
            [$userId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $credentialId, int $userId): ?array
    {
        return self::obtenerFila(
            'SELECT * FROM credential_assignments WHERE credential_id = ? AND user_id = ?',
            [$credentialId, $userId]
        );
    }

    /**
     * True si el usuario tiene una asignacion vigente sobre la credencial.
     * Es la comprobacion que impide el acceso directo por URL (IDOR).
     */
    public static function isAssigned(int $credentialId, int $userId): bool
    {
        return (int) self::obtenerValor(
            'SELECT COUNT(*) FROM credential_assignments
              WHERE credential_id = ? AND user_id = ? AND is_active = 1 AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > NOW())',
            [$credentialId, $userId]
        ) > 0;
    }

    public static function grant(int $credentialId, int $userId, array $options, int $grantedBy): void
    {
        self::ejecutarConsultaAfectadas(
            'INSERT INTO credential_assignments
               (credential_id, user_id, can_view_secret, can_copy_secret, can_view_recovery, is_active,
                granted_by, granted_at, expires_at, revoked_at, revoked_by, revoke_reason)
             VALUES (?,?,?,?,?,1,?,NOW(),?,NULL,NULL,NULL)
             ON DUPLICATE KEY UPDATE
                can_view_secret = VALUES(can_view_secret),
                can_copy_secret = VALUES(can_copy_secret),
                can_view_recovery = VALUES(can_view_recovery),
                is_active = 1, granted_by = VALUES(granted_by), granted_at = NOW(),
                expires_at = VALUES(expires_at), revoked_at = NULL, revoked_by = NULL, revoke_reason = NULL',
            [
                $credentialId, $userId,
                (int) ($options['can_view_secret'] ?? 1),
                (int) ($options['can_copy_secret'] ?? 1),
                (int) ($options['can_view_recovery'] ?? 0),
                $grantedBy,
                $options['expires_at'] ?? null,
            ]
        );
    }

    public static function revoke(int $credentialId, int $userId, int $revokedBy, string $reason): void
    {
        self::ejecutarConsultaAfectadas(
            'UPDATE credential_assignments
                SET is_active = 0, revoked_at = NOW(), revoked_by = ?, revoke_reason = ?
              WHERE credential_id = ? AND user_id = ? AND is_active = 1',
            [$revokedBy, mb_substr($reason, 0, 255), $credentialId, $userId]
        );
    }

    /** Revoca todos los accesos de un usuario (baja del empleado). */
    public static function revokeAllForUser(int $userId, int $revokedBy, string $reason): int
    {
        return self::ejecutarConsultaAfectadas(
            'UPDATE credential_assignments
                SET is_active = 0, revoked_at = NOW(), revoked_by = ?, revoke_reason = ?
              WHERE user_id = ? AND is_active = 1',
            [$revokedBy, mb_substr($reason, 0, 255), $userId]
        );
    }

    /** Reasigna a otro usuario los accesos que tenia un empleado (art. 19). */
    public static function reassign(int $fromUserId, int $toUserId, int $performedBy): int
    {
        $rows = self::obtenerFilas(
            'SELECT credential_id, can_view_secret, can_copy_secret, can_view_recovery
               FROM credential_assignments WHERE user_id = ?',
            [$fromUserId]
        );
        $count = 0;
        foreach ($rows as $row) {
            self::grant((int) $row['credential_id'], $toUserId, [
                'can_view_secret'   => (int) $row['can_view_secret'],
                'can_copy_secret'   => (int) $row['can_copy_secret'],
                'can_view_recovery' => (int) $row['can_view_recovery'],
            ], $performedBy);
            $count++;
        }
        return $count;
    }

    /** Historico: que credenciales tenia asignadas un usuario en una fecha (art. 44). */
    public static function assignmentsAtDate(int $userId, string $date): array
    {
        return self::obtenerFilas(
            'SELECT ca.credential_id, c.name AS credential_name, s.name AS system_name,
                    ca.granted_at, ca.revoked_at
               FROM credential_assignments ca
               JOIN credentials c ON c.id = ca.credential_id
               JOIN systems s ON s.id = c.system_id
              WHERE ca.user_id = :uid
                AND ca.granted_at <= :d
                AND (ca.revoked_at IS NULL OR ca.revoked_at > :d)
              ORDER BY s.name, c.name',
            ['uid' => $userId, 'd' => $date . ' 23:59:59']
        );
    }

    public static function activeCountForUser(int $userId): int
    {
        return (int) self::obtenerValor(
            'SELECT COUNT(*) FROM credential_assignments WHERE user_id = ? AND is_active = 1',
            [$userId]
        );
    }

    /** Usuarios con mas accesos otorgados (tablero art. 43). */
    public static function topUsersByAccess(int $limit = 10): array
    {
        return self::obtenerFilas(
            'SELECT u.id, u.first_name, u.last_name, u.national_id, COUNT(ca.id) AS total
               FROM credential_assignments ca
               JOIN users u ON u.id = ca.user_id
              WHERE ca.is_active = 1
              GROUP BY u.id, u.first_name, u.last_name, u.national_id
              ORDER BY total DESC
              LIMIT ' . (int) $limit
        );
    }
}
