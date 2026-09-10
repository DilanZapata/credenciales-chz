<?php
declare(strict_types=1);

namespace app\models;


/** Trazabilidad de exportaciones (art. 38). */
class exportacionModel extends mainModel
{
    public static function create(array $data): int
    {
        return self::ejecutarInsert(
            'INSERT INTO export_reports
               (uuid, user_id, actor_national_id, report_type, format, filters, record_count,
                included_secrets, status, ip_address, user_agent, session_id, expires_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
            [
                $data['uuid'], $data['user_id'], $data['actor_national_id'], $data['report_type'],
                $data['format'] ?? 'xlsx', json_encode($data['filters'] ?? [], JSON_UNESCAPED_UNICODE),
                (int) ($data['record_count'] ?? 0), (int) ($data['included_secrets'] ?? 0),
                $data['status'] ?? 'generating', $data['ip_address'] ?? null,
                $data['user_agent'] ?? null, $data['session_id'] ?? null,
                (int) ($data['retention_minutes'] ?? 15),
            ]
        );
    }

    public static function markReady(int $id, string $fileName, string $storagePath, string $hash, int $size, int $recordCount): void
    {
        self::ejecutarConsultaAfectadas(
            "UPDATE export_reports
                SET status = 'ready', file_name = ?, storage_path = ?, file_hash = ?, file_size = ?, record_count = ?
              WHERE id = ?",
            [$fileName, $storagePath, $hash, $size, $recordCount, $id]
        );
    }

    public static function markFailed(int $id): void
    {
        self::ejecutarConsultaAfectadas("UPDATE export_reports SET status = 'failed' WHERE id = ?", [$id]);
    }

    public static function markDownloaded(int $id): void
    {
        self::ejecutarConsultaAfectadas(
            "UPDATE export_reports
                SET status = 'downloaded', downloaded_at = NOW(), download_count = download_count + 1
              WHERE id = ?",
            [$id]
        );
    }

    public static function markPurged(int $id): void
    {
        self::ejecutarConsultaAfectadas(
            "UPDATE export_reports SET status = 'purged', purged_at = NOW(), storage_path = NULL WHERE id = ?",
            [$id]
        );
    }

    /** @param array<int,int> $credentialIds */
    public static function attachCredentials(int $reportId, array $credentialIds): void
    {
        if ($credentialIds === []) {
            return;
        }
        self::transaccion(function () use ($reportId, $credentialIds): void {
            foreach (array_chunk(array_unique($credentialIds), 200) as $chunk) {
                $values = implode(',', array_fill(0, count($chunk), '(?,?)'));
                $params = [];
                foreach ($chunk as $credentialId) {
                    $params[] = $reportId;
                    $params[] = (int) $credentialId;
                }
                self::ejecutarConsultaAfectadas('INSERT IGNORE INTO export_report_items (report_id, credential_id) VALUES ' . $values, $params);
            }
        });
    }

    public static function findByUuid(string $uuid): ?array
    {
        return self::obtenerFila('SELECT * FROM export_reports WHERE uuid = ?', [$uuid]);
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public static function paginate(array $filters, int $page, int $perPage): array
    {
        $where  = ['1=1'];
        $params = [];
        if (!empty($filters['user_id'])) {
            $where[]           = 'er.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['report_type'])) {
            $where[]               = 'er.report_type = :report_type';
            $params['report_type'] = (string) $filters['report_type'];
        }
        if (isset($filters['included_secrets']) && $filters['included_secrets'] !== '') {
            $where[]                    = 'er.included_secrets = :included_secrets';
            $params['included_secrets'] = (int) $filters['included_secrets'];
        }
        if (!empty($filters['date_from'])) {
            $where[]             = 'er.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[]           = 'er.created_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $total    = (int) self::obtenerValor('SELECT COUNT(*) FROM export_reports er WHERE ' . $whereSql, $params);
        $offset   = max(0, ($page - 1) * $perPage);

        $items = self::obtenerFilas(
            'SELECT er.*, CONCAT_WS(" ", u.first_name, u.last_name) AS user_name, u.username
               FROM export_reports er
               LEFT JOIN users u ON u.id = er.user_id
              WHERE ' . $whereSql . '
              ORDER BY er.created_at DESC
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    /** Archivos vencidos que deben borrarse del disco. */
    public static function expiredWithFiles(): array
    {
        return self::obtenerFilas(
            "SELECT id, storage_path FROM export_reports
              WHERE expires_at < NOW() AND storage_path IS NOT NULL AND status NOT IN ('purged')"
        );
    }

    /** Quien exporto una credencial concreta (art. 44). */
    public static function exportsContaining(int $credentialId, int $limit = 50): array
    {
        return self::obtenerFilas(
            'SELECT er.uuid, er.created_at, er.report_type, er.included_secrets, er.record_count,
                    CONCAT_WS(" ", u.first_name, u.last_name) AS user_name, er.actor_national_id, er.ip_address
               FROM export_report_items eri
               JOIN export_reports er ON er.id = eri.report_id
               LEFT JOIN users u ON u.id = er.user_id
              WHERE eri.credential_id = ?
              ORDER BY er.created_at DESC
              LIMIT ' . (int) $limit,
            [$credentialId]
        );
    }
}
