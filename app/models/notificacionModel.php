<?php
declare(strict_types=1);

namespace app\models;


/** Notificaciones y alertas dentro del sistema. */
class notificacionModel extends mainModel
{
    public static function push(array $data): void
    {
        // dedupe_key evita repetir la misma alerta cada vez que corre el cron.
        self::ejecutarConsultaAfectadas(
            'INSERT IGNORE INTO notifications (user_id, role_id, type, severity, title, message, link, dedupe_key)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $data['user_id'] ?? null, $data['role_id'] ?? null, $data['type'],
                $data['severity'] ?? 'info', mb_substr($data['title'], 0, 180),
                isset($data['message']) ? mb_substr((string) $data['message'], 0, 500) : null,
                $data['link'] ?? null, $data['dedupe_key'] ?? null,
            ]
        );
    }

    /** @return array<int,array<string,mixed>> */
    public static function forUser(int $userId, array $roleIds, bool $onlyUnread = false, int $limit = 30): array
    {
        $roleFilter = '';
        $params     = ['uid' => $userId];
        if ($roleIds !== []) {
            $names = [];
            foreach (array_values($roleIds) as $i => $roleId) {
                $names[]             = ':r' . $i;
                $params['r' . $i]    = (int) $roleId;
            }
            $roleFilter = ' OR n.role_id IN (' . implode(',', $names) . ')';
        }
        $unread = $onlyUnread ? ' AND n.is_read = 0' : '';
        return self::obtenerFilas(
            'SELECT n.* FROM notifications n
              WHERE (n.user_id = :uid' . $roleFilter . ')' . $unread . '
              ORDER BY n.is_read ASC, n.created_at DESC
              LIMIT ' . (int) $limit,
            $params
        );
    }

    public static function unreadCount(int $userId, array $roleIds): int
    {
        $roleFilter = '';
        $params     = ['uid' => $userId];
        if ($roleIds !== []) {
            $names = [];
            foreach (array_values($roleIds) as $i => $roleId) {
                $names[]          = ':r' . $i;
                $params['r' . $i] = (int) $roleId;
            }
            $roleFilter = ' OR n.role_id IN (' . implode(',', $names) . ')';
        }
        return (int) self::obtenerValor(
            'SELECT COUNT(*) FROM notifications n WHERE (n.user_id = :uid' . $roleFilter . ') AND n.is_read = 0',
            $params
        );
    }

    public static function markRead(int $id, int $userId, array $roleIds): void
    {
        $roleFilter = '';
        $params     = ['id' => $id, 'uid' => $userId];
        if ($roleIds !== []) {
            $names = [];
            foreach (array_values($roleIds) as $i => $roleId) {
                $names[]          = ':r' . $i;
                $params['r' . $i] = (int) $roleId;
            }
            $roleFilter = ' OR n.role_id IN (' . implode(',', $names) . ')';
        }
        self::ejecutarConsultaAfectadas(
            'UPDATE notifications n SET n.is_read = 1, n.read_at = NOW()
              WHERE n.id = :id AND (n.user_id = :uid' . $roleFilter . ')',
            $params
        );
    }

    public static function markAllRead(int $userId): void
    {
        self::ejecutarConsultaAfectadas(
            'UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0',
            [$userId]
        );
    }

    public static function purgeOld(int $days = 90): int
    {
        return self::ejecutarConsultaAfectadas(
            'DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$days]
        );
    }
}
