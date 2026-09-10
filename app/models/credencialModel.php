<?php
declare(strict_types=1);

namespace app\models;

use App\Core\HttpException;
use App\Core\ValidationException;
use DateTimeImmutable;


/**
 * Persistencia de credenciales.
 *
 * REGLA DE ORO (art. 45): ninguna consulta de esta clase devuelve secretos
 * junto con los metadatos. Los sobres cifrados solo se obtienen mediante
 * los metodos explicitos currentSecret()/secretVersion(), invocados
 * unicamente por CredentialService tras verificar permisos y step-up.
 *
 * Alcance de datos: cuando se recibe $scopeUserId, el SQL exige una
 * asignacion activa. El filtrado ocurre en la base de datos, no en PHP,
 * de modo que un IDOR no puede sortearse manipulando identificadores.
 */
class credencialModel extends mainModel
{
    private const BASE_COLUMNS = 'c.id, c.system_id, c.name, c.environment, c.username, c.email, c.domain,
        c.admin_username, c.auth_method, c.has_security_questions, c.observations, c.owner_user_id,
        c.status, c.password_changed_at, c.rotation_period_days, c.next_rotation_at, c.expires_at,
        c.created_at, c.updated_at, c.created_by, c.updated_by, c.deleted_at';

    private const RECOVERY_COLUMNS = 'c.recovery_email, c.recovery_phone, c.recovery_username, c.recovery_notes';

    /** Columnas por las que se permite ordenar (lista blanca anti-inyeccion). */
    private const SORTABLE = [
        'name'        => 'c.name',
        'system'      => 's.name',
        'category'    => 'cat.name',
        'status'      => 'c.status',
        'updated_at'  => 'c.updated_at',
        'created_at'  => 'c.created_at',
        'expires_at'  => 'c.expires_at',
        'rotation'    => 'c.next_rotation_at',
    ];

    // -----------------------------------------------------------------
    //  Lectura de metadatos
    // -----------------------------------------------------------------

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id, ?int $scopeUserId = null, bool $includeRecovery = false, bool $includeDeleted = false): ?array
    {
        $columns = self::BASE_COLUMNS . ($includeRecovery ? ', ' . self::RECOVERY_COLUMNS : '');
        $params  = ['id' => $id];
        $scope   = '';
        if ($scopeUserId !== null) {
            $scope = ' AND EXISTS (SELECT 1 FROM credential_assignments ca
                                    WHERE ca.credential_id = c.id AND ca.user_id = :scope_user
                                      AND ca.is_active = 1 AND ca.revoked_at IS NULL
                                      AND (ca.expires_at IS NULL OR ca.expires_at > NOW()))';
            $params['scope_user'] = $scopeUserId;
        }
        $deleted = $includeDeleted ? '' : ' AND c.deleted_at IS NULL';

        return self::obtenerFila(
            'SELECT ' . $columns . ',
                    s.name AS system_name, s.display_name AS system_display_name, s.url, s.ip_address,
                    s.port, s.hostname, s.platform, s.provider, s.resource_type, s.criticality,
                    s.description AS system_description, s.status AS system_status,
                    cat.id AS category_id, cat.name AS category_name, cat.color AS category_color,
                    co.id AS company_id, co.name AS company_name,
                    lo.id AS location_id, lo.name AS location_name,
                    de.id AS department_id, de.name AS department_name,
                    ow.first_name AS owner_first_name, ow.last_name AS owner_last_name, ow.national_id AS owner_national_id,
                    cb.username AS created_by_username, ub.username AS updated_by_username,
                    (SELECT COUNT(*) FROM credential_assignments ca2
                      WHERE ca2.credential_id = c.id AND ca2.is_active = 1) AS assignment_count,
                    (SELECT MAX(version) FROM credential_secrets cs
                      WHERE cs.credential_id = c.id AND cs.field = "password") AS secret_version,
                    (SELECT COUNT(*) FROM credential_secrets cs2
                      WHERE cs2.credential_id = c.id AND cs2.field = "password") AS secret_count
               FROM credentials c
               JOIN systems s      ON s.id = c.system_id
               LEFT JOIN categories  cat ON cat.id = s.category_id
               LEFT JOIN companies   co  ON co.id = s.company_id
               LEFT JOIN locations   lo  ON lo.id = s.location_id
               LEFT JOIN departments de  ON de.id = s.department_id
               LEFT JOIN users ow ON ow.id = c.owner_user_id
               LEFT JOIN users cb ON cb.id = c.created_by
               LEFT JOIN users ub ON ub.id = c.updated_by
              WHERE c.id = :id' . $deleted . $scope,
            $params
        );
    }

    /**
     * Listado paginado con filtros y busqueda global (art. 12).
     *
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function paginate(array $filters, int $page, int $perPage, ?int $scopeUserId = null): array
    {
        [$whereSql, $params, $joins] = self::buildFilters($filters, $scopeUserId);

        $total = (int) self::obtenerValor(
            'SELECT COUNT(DISTINCT c.id)
               FROM credentials c
               JOIN systems s ON s.id = c.system_id
               LEFT JOIN categories cat ON cat.id = s.category_id
               ' . $joins . '
              WHERE ' . $whereSql,
            $params
        );

        $sortKey   = (string) ($filters['sort'] ?? 'name');
        $sortCol   = self::SORTABLE[$sortKey] ?? self::SORTABLE['name'];
        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $offset    = max(0, ($page - 1) * $perPage);

        $items = self::obtenerFilas(
            'SELECT ' . self::BASE_COLUMNS . ',
                    s.name AS system_name, s.url, s.resource_type, s.criticality,
                    cat.name AS category_name, cat.color AS category_color,
                    co.name AS company_name, lo.name AS location_name, de.name AS department_name,
                    CONCAT_WS(" ", ow.first_name, ow.last_name) AS owner_name,
                    (SELECT COUNT(*) FROM credential_assignments ca2
                      WHERE ca2.credential_id = c.id AND ca2.is_active = 1) AS assignment_count
               FROM credentials c
               JOIN systems s ON s.id = c.system_id
               LEFT JOIN categories  cat ON cat.id = s.category_id
               LEFT JOIN companies   co  ON co.id = s.company_id
               LEFT JOIN locations   lo  ON lo.id = s.location_id
               LEFT JOIN departments de  ON de.id = s.department_id
               LEFT JOIN users ow ON ow.id = c.owner_user_id
               ' . $joins . '
              WHERE ' . $whereSql . '
              GROUP BY c.id
              ORDER BY ' . $sortCol . ' ' . $direction . '
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Identificadores que cumplen un filtro (para exportaciones masivas).
     *
     * @return array<int,int>
     */
    public static function idsMatching(array $filters, ?int $scopeUserId = null, int $limit = 5000): array
    {
        [$whereSql, $params, $joins] = self::buildFilters($filters, $scopeUserId);
        $rows = self::obtenerFilas(
            'SELECT DISTINCT c.id
               FROM credentials c
               JOIN systems s ON s.id = c.system_id
               LEFT JOIN categories cat ON cat.id = s.category_id
               ' . $joins . '
              WHERE ' . $whereSql . '
              ORDER BY c.id
              LIMIT ' . (int) $limit,
            $params
        );
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Filas completas para reportes. Incluye datos de recuperacion solo si
     * se solicita explicitamente y el llamador ya verifico el permiso.
     *
     * @param array<int,int> $ids
     * @return array<int,array<string,mixed>>
     */
    public static function findManyForReport(array $ids, bool $includeRecovery, ?int $scopeUserId = null): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params       = array_map('intval', $ids);
        $scope        = '';
        if ($scopeUserId !== null) {
            $scope    = ' AND EXISTS (SELECT 1 FROM credential_assignments ca
                                       WHERE ca.credential_id = c.id AND ca.user_id = ?
                                         AND ca.is_active = 1)';
            $params[] = $scopeUserId;
        }
        $columns = self::BASE_COLUMNS . ($includeRecovery ? ', ' . self::RECOVERY_COLUMNS : '');

        return self::obtenerFilas(
            'SELECT ' . $columns . ',
                    s.name AS system_name, s.display_name AS system_display_name, s.description AS system_description,
                    s.url, s.ip_address, s.port, s.hostname, s.platform, s.provider, s.resource_type,
                    cat.name AS category_name, co.name AS company_name, lo.name AS location_name,
                    de.name AS department_name,
                    CONCAT_WS(" ", ow.first_name, ow.last_name) AS owner_name
               FROM credentials c
               JOIN systems s ON s.id = c.system_id
               LEFT JOIN categories  cat ON cat.id = s.category_id
               LEFT JOIN companies   co  ON co.id = s.company_id
               LEFT JOIN locations   lo  ON lo.id = s.location_id
               LEFT JOIN departments de  ON de.id = s.department_id
               LEFT JOIN users ow ON ow.id = c.owner_user_id
              WHERE c.id IN (' . $placeholders . ') AND c.deleted_at IS NULL' . $scope . '
              ORDER BY s.name, c.name',
            $params
        );
    }

    /**
     * @return array{0:string,1:array<string,mixed>,2:string}
     */
    private static function buildFilters(array $filters, ?int $scopeUserId): array
    {
        $where  = [];
        $params = [];
        $joins  = '';

        $where[] = ($filters['include_deleted'] ?? false) ? '1=1' : 'c.deleted_at IS NULL';

        if ($scopeUserId !== null) {
            $joins .= ' JOIN credential_assignments ca
                          ON ca.credential_id = c.id
                         AND ca.user_id = :scope_user
                         AND ca.is_active = 1
                         AND ca.revoked_at IS NULL
                         AND (ca.expires_at IS NULL OR ca.expires_at > NOW()) ';
            $params['scope_user'] = $scopeUserId;
            // Un consultor no ve credenciales dadas de baja.
            $where[] = "c.status = 'active'";
        }

        if (!empty($filters['search'])) {
            $where[] = '(c.name LIKE :q OR c.username LIKE :q OR c.email LIKE :q OR c.domain LIKE :q
                         OR s.name LIKE :q OR s.display_name LIKE :q OR s.url LIKE :q OR s.hostname LIKE :q
                         OR s.provider LIKE :q OR cat.name LIKE :q)';
            $params['q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']) . '%';
        }
        if (!empty($filters['category_id'])) {
            $where[] = 's.category_id = :category_id';
            $params['category_id'] = (int) $filters['category_id'];
        }
        if (!empty($filters['system_id'])) {
            $where[] = 'c.system_id = :system_id';
            $params['system_id'] = (int) $filters['system_id'];
        }
        if (!empty($filters['company_id'])) {
            $where[] = 's.company_id = :company_id';
            $params['company_id'] = (int) $filters['company_id'];
        }
        if (!empty($filters['location_id'])) {
            $where[] = 's.location_id = :location_id';
            $params['location_id'] = (int) $filters['location_id'];
        }
        if (!empty($filters['department_id'])) {
            $where[] = 's.department_id = :department_id';
            $params['department_id'] = (int) $filters['department_id'];
        }
        if (!empty($filters['owner_user_id'])) {
            $where[] = 'c.owner_user_id = :owner_user_id';
            $params['owner_user_id'] = (int) $filters['owner_user_id'];
        }
        if (!empty($filters['resource_type'])) {
            $where[] = 's.resource_type = :resource_type';
            $params['resource_type'] = (string) $filters['resource_type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'c.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['assigned_user_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM credential_assignments caf
                                 WHERE caf.credential_id = c.id AND caf.user_id = :assigned_user_id
                                   AND caf.is_active = 1)';
            $params['assigned_user_id'] = (int) $filters['assigned_user_id'];
        }
        if (!empty($filters['ids']) && is_array($filters['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $filters['ids'])));
            if ($ids !== []) {
                $names = [];
                foreach ($ids as $i => $id) {
                    $key         = 'id_' . $i;
                    $names[]     = ':' . $key;
                    $params[$key] = $id;
                }
                $where[] = 'c.id IN (' . implode(',', $names) . ')';
            } else {
                $where[] = '1=0';
            }
        }
        if (!empty($filters['expiring_days'])) {
            $where[] = 'c.expires_at IS NOT NULL AND c.expires_at BETWEEN CURDATE()
                        AND DATE_ADD(CURDATE(), INTERVAL :expiring_days DAY)';
            $params['expiring_days'] = (int) $filters['expiring_days'];
        }
        if (!empty($filters['expired'])) {
            $where[] = '((c.expires_at IS NOT NULL AND c.expires_at < CURDATE())
                         OR (c.next_rotation_at IS NOT NULL AND c.next_rotation_at < CURDATE()))';
        }
        if (!empty($filters['never_rotated'])) {
            $where[] = 'c.password_changed_at IS NULL';
        }
        if (!empty($filters['without_owner'])) {
            $where[] = 'c.owner_user_id IS NULL';
        }
        if (!empty($filters['without_assignments'])) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM credential_assignments cae
                                     WHERE cae.credential_id = c.id AND cae.is_active = 1)';
        }

        return [implode(' AND ', $where), $params, $joins];
    }

    // -----------------------------------------------------------------
    //  Escritura de metadatos
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $data */
    public static function crearRegistro(array $data): int
    {
        return self::ejecutarInsert(
            'INSERT INTO credentials
               (system_id, name, environment, username, email, domain, admin_username, auth_method,
                recovery_email, recovery_phone, recovery_username, recovery_notes, has_security_questions,
                observations, owner_user_id, status, rotation_period_days, next_rotation_at, expires_at, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                (int) $data['system_id'], $data['name'], $data['environment'] ?? 'production',
                $data['username'] ?? null, $data['email'] ?? null, $data['domain'] ?? null,
                $data['admin_username'] ?? null, $data['auth_method'] ?? null,
                $data['recovery_email'] ?? null, $data['recovery_phone'] ?? null,
                $data['recovery_username'] ?? null, $data['recovery_notes'] ?? null,
                (int) ($data['has_security_questions'] ?? 0),
                $data['observations'] ?? null, $data['owner_user_id'] ?? null,
                $data['status'] ?? 'active', $data['rotation_period_days'] ?? null,
                $data['next_rotation_at'] ?? null, $data['expires_at'] ?? null,
                $data['created_by'] ?? null,
            ]
        );
    }

    /** @param array<string,mixed> $data */
    public static function actualizarRegistro(int $id, array $data, ?int $updatedBy): void
    {
        $allowed = [
            'system_id', 'name', 'environment', 'username', 'email', 'domain', 'admin_username',
            'auth_method', 'recovery_email', 'recovery_phone', 'recovery_username', 'recovery_notes',
            'has_security_questions', 'observations', 'owner_user_id', 'status',
            'rotation_period_days', 'next_rotation_at', 'expires_at',
        ];
        $sets   = [];
        $params = [];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $data)) {
                $sets[]   = "{$column} = ?";
                $params[] = $data[$column];
            }
        }
        if ($sets === []) {
            return;
        }
        $sets[]   = 'updated_by = ?';
        $params[] = $updatedBy;
        $params[] = $id;
        self::ejecutarConsultaAfectadas('UPDATE credentials SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public static function softDelete(int $id, ?int $userId): void
    {
        self::ejecutarConsultaAfectadas(
            "UPDATE credentials SET deleted_at = NOW(), status = 'archived', updated_by = ? WHERE id = ?",
            [$userId, $id]
        );
        self::ejecutarConsultaAfectadas(
            "UPDATE credential_assignments
                SET is_active = 0, revoked_at = NOW(), revoked_by = ?, revoke_reason = 'credencial dada de baja'
              WHERE credential_id = ? AND is_active = 1",
            [$userId, $id]
        );
    }

    public static function restaurarRegistro(int $id, ?int $userId): void
    {
        self::ejecutarConsultaAfectadas(
            "UPDATE credentials SET deleted_at = NULL, status = 'active', updated_by = ? WHERE id = ?",
            [$userId, $id]
        );
    }

    public static function markRotated(int $id, ?int $rotationDays, ?int $userId): void
    {
        if ($rotationDays !== null && $rotationDays > 0) {
            self::ejecutarConsultaAfectadas(
                'UPDATE credentials
                    SET password_changed_at = NOW(),
                        next_rotation_at = DATE_ADD(CURDATE(), INTERVAL ? DAY),
                        updated_by = ?
                  WHERE id = ?',
                [$rotationDays, $userId, $id]
            );
            return;
        }
        self::ejecutarConsultaAfectadas(
            'UPDATE credentials SET password_changed_at = NOW(), updated_by = ? WHERE id = ?',
            [$userId, $id]
        );
    }

    // -----------------------------------------------------------------
    //  Secretos (sobres cifrados) - acceso explicito y controlado
    // -----------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function currentSecret(int $credentialId, string $field = 'password'): ?array
    {
        return self::obtenerFila(
            'SELECT * FROM credential_secrets
              WHERE credential_id = ? AND field = ? AND is_current = 1
              ORDER BY version DESC LIMIT 1',
            [$credentialId, $field]
        );
    }

    /** @return array<string,mixed>|null */
    public static function secretVersion(int $credentialId, int $version, string $field = 'password'): ?array
    {
        return self::obtenerFila(
            'SELECT * FROM credential_secrets WHERE credential_id = ? AND field = ? AND version = ?',
            [$credentialId, $field, $version]
        );
    }

    /**
     * Metadatos del historial de secretos. NO devuelve el criptograma:
     * solo fechas, autor, motivo y robustez (art. 32).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function secretHistoryMeta(int $credentialId, string $field = 'password'): array
    {
        return self::obtenerFilas(
            'SELECT cs.id, cs.version, cs.is_current, cs.change_reason, cs.created_at, cs.retired_at,
                    cs.secret_length, cs.strength_score, cs.key_version,
                    CONCAT_WS(" ", u.first_name, u.last_name) AS changed_by_name,
                    u.national_id AS changed_by_national_id
               FROM credential_secrets cs
               LEFT JOIN users u ON u.id = cs.created_by
              WHERE cs.credential_id = ? AND cs.field = ?
              ORDER BY cs.version DESC',
            [$credentialId, $field]
        );
    }

    /**
     * Inserta una nueva version del secreto y retira la anterior.
     *
     * @param array<string,mixed> $envelope
     */
    public static function storeSecret(
        int $credentialId,
        array $envelope,
        string $field,
        ?string $label,
        int $length,
        int $strength,
        string $fingerprint,
        ?string $reason,
        ?int $userId
    ): int {
        return self::transaccion(function () use (
            $credentialId, $envelope, $field, $label, $length, $strength, $fingerprint, $reason, $userId
        ): int {
            $version = (int) (self::obtenerValor(
                'SELECT COALESCE(MAX(version), 0) FROM credential_secrets WHERE credential_id = ? AND field = ?',
                [$credentialId, $field]
            ) ?? 0) + 1;

            self::ejecutarConsultaAfectadas(
                'UPDATE credential_secrets SET is_current = 0, retired_at = NOW()
                  WHERE credential_id = ? AND field = ? AND is_current = 1',
                [$credentialId, $field]
            );

            self::ejecutarInsert(
                'INSERT INTO credential_secrets
                   (credential_id, field, label, version, is_current, algo, key_version,
                    ciphertext, nonce, tag, wrapped_dek, dek_nonce, dek_tag,
                    secret_length, strength_score, fingerprint, change_reason, created_by)
                 VALUES (?,?,?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $credentialId, $field, $label, $version, $envelope['algo'], $envelope['key_version'],
                    $envelope['ciphertext'], $envelope['nonce'], $envelope['tag'],
                    $envelope['wrapped_dek'], $envelope['dek_nonce'], $envelope['dek_tag'],
                    $length, $strength, $fingerprint, $reason, $userId,
                ]
            );
            return $version;
        });
    }

    /** Contrasenas reutilizadas en varias credenciales (alerta de seguridad). */
    public static function reusedSecrets(): array
    {
        return self::obtenerFilas(
            'SELECT cs.fingerprint, COUNT(DISTINCT cs.credential_id) AS total,
                    GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR " | ") AS credentials
               FROM credential_secrets cs
               JOIN credentials c ON c.id = cs.credential_id AND c.deleted_at IS NULL
              WHERE cs.is_current = 1 AND cs.field = "password" AND cs.fingerprint IS NOT NULL
              GROUP BY cs.fingerprint
             HAVING total > 1
              ORDER BY total DESC
              LIMIT 50'
        );
    }

    // -----------------------------------------------------------------
    //  Historial funcional
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public static function consultarHistorial(array $filters, int $limit = 200): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['credential_id'])) {
            $where[] = 'h.credential_id = :credential_id';
            $params['credential_id'] = (int) $filters['credential_id'];
        }
        if (!empty($filters['credential_ids']) && is_array($filters['credential_ids'])) {
            $ids   = array_values(array_filter(array_map('intval', $filters['credential_ids'])));
            $names = [];
            foreach ($ids as $i => $id) {
                $names[] = ':hid_' . $i;
                $params['hid_' . $i] = $id;
            }
            $where[] = $names === [] ? '1=0' : 'h.credential_id IN (' . implode(',', $names) . ')';
        }
        if (!empty($filters['system_id'])) {
            $where[] = 'c.system_id = :system_id';
            $params['system_id'] = (int) $filters['system_id'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'h.performed_by = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'h.action = :action';
            $params['action'] = (string) $filters['action'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'h.performed_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'h.performed_at <= :date_to';
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        return self::obtenerFilas(
            'SELECT h.*, c.name AS credential_name, s.name AS system_name,
                    CONCAT_WS(" ", u.first_name, u.last_name) AS performed_by_name,
                    u.national_id AS performed_by_national_id
               FROM credential_history h
               JOIN credentials c ON c.id = h.credential_id
               JOIN systems s     ON s.id = c.system_id
               LEFT JOIN users u  ON u.id = h.performed_by
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY h.performed_at DESC
              LIMIT ' . (int) $limit,
            $params
        );
    }

    /** Quien consulto/copio/exporto el secreto de una credencial. */
    public static function secretAccessHistory(int $credentialId, int $limit = 100): array
    {
        return self::obtenerFilas(
            'SELECT sal.*, CONCAT_WS(" ", u.first_name, u.last_name) AS user_name, u.username
               FROM secret_access_log sal
               LEFT JOIN users u ON u.id = sal.user_id
              WHERE sal.credential_id = ?
              ORDER BY sal.occurred_at DESC
              LIMIT ' . (int) $limit,
            [$credentialId]
        );
    }

    // =================================================================
    //  Logica de negocio (fusionada desde CredentialService.php)
    // =================================================================


    // -----------------------------------------------------------------
    //  Lectura
    // -----------------------------------------------------------------

    /** @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int} */
    public static function list(array $filters, int $page = 1, int $perPage = 25): array
    {
        permisoModel::exigirAlguno(['credentials.view', 'credentials.view_all']);
        $perPage = max(5, min(100, $perPage));
        $page    = max(1, $page);

        $result = credencialModel::paginate($filters, $page, $perPage, permisoModel::alcanceCredenciales());

        return [
            'items'    => array_map([self::class, 'presentListItem'], $result['items']),
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($result['total'] / $perPage),
        ];
    }

    /**
     * Ficha completa SIN secretos. Registra la consulta en auditoria.
     *
     * @return array<string,mixed>
     */
    public static function show(int $id, bool $audit = true): array
    {
        permisoModel::exigirAlguno(['credentials.view', 'credentials.view_all'], 'credential', $id);

        $canSeeRecovery = permisoModel::puede('credentials.recovery.view');
        $credential     = credencialModel::find($id, permisoModel::alcanceCredenciales(), $canSeeRecovery);

        if ($credential === null) {
            // Se responde 404 tanto si no existe como si no esta autorizado:
            // no se revela la existencia de recursos ajenos.
            auditoriaModel::registrar(auditoriaModel::ACCESS_DENIED, 'credential', $id, null, 'denied',
                ['motivo' => 'credencial inexistente o fuera del alcance'], 'warning');
            throw HttpException::notFound('La credencial solicitada no existe o no tiene acceso a ella.');
        }

        if ($audit) {
            auditoriaModel::registrar(auditoriaModel::CREDENTIAL_VIEWED, 'credential', $id, (string) $credential['name']);
        }

        return self::presentDetail($credential, $canSeeRecovery);
    }

    /** Historial de cambios de una credencial (art. 32). */
    public static function history(int $id): array
    {
        self::show($id, false);
        permisoModel::exigirAlguno(['history.view', 'credentials.view_all'], 'credential', $id);

        return [
            'secret_versions' => credencialModel::secretHistoryMeta($id),
            'changes'         => credencialModel::consultarHistorial(['credential_id' => $id], 300),
            'secret_access'   => permisoModel::puede('audit.view')
                ? credencialModel::secretAccessHistory($id, 100)
                : [],
        ];
    }

    public static function assignments(int $id): array
    {
        self::show($id, false);
        permisoModel::exigirAlguno(['credentials.assign', 'credentials.view_all'], 'credential', $id);
        return asignacionModel::forCredential($id);
    }

    // -----------------------------------------------------------------
    //  Acceso al secreto: la operacion mas sensible del sistema
    // -----------------------------------------------------------------

    /**
     * Revela un secreto en claro.
     *
     * @param string $accessType 'view' | 'copy' | 'history_view'
     * @return array{secret:string,version:int,field:string,generated_at:string}
     */
    public static function revealSecret(int $id, string $field = 'password', string $accessType = 'view', ?int $version = null): array
    {
        $field = in_array($field, ['password', 'pin', 'access_code'], true) ? $field : 'password';

        // 1) Permiso funcional
        $permission = match ($accessType) {
            'copy'         => 'credentials.secret.copy',
            'history_view' => 'credentials.secret.history',
            default        => 'credentials.secret.view',
        };
        permisoModel::exigir($permission, 'credential', $id);

        // 2) Alcance de datos: debe existir la credencial dentro de su ambito
        $scopeUserId = permisoModel::alcanceCredenciales();
        $credential  = credencialModel::find($id, $scopeUserId);
        if ($credential === null) {
            auditoriaModel::registrarAccesoSecreto($id, $accessType, null, $field, null, 'denied', 'fuera del alcance');
            throw HttpException::notFound('La credencial solicitada no existe o no tiene acceso a ella.');
        }

        // 3) Permisos finos de la asignacion (un consultor puede tener
        //    acceso de lectura pero no de copia sobre una credencial dada)
        if ($scopeUserId !== null) {
            $assignment = asignacionModel::find($id, $scopeUserId);
            $flag       = $accessType === 'copy' ? 'can_copy_secret' : 'can_view_secret';
            if ($assignment === null || (int) $assignment['is_active'] !== 1 || (int) $assignment[$flag] !== 1) {
                auditoriaModel::registrarAccesoSecreto($id, $accessType, null, $field, null, 'denied', 'asignacion sin permiso');
                throw HttpException::forbidden('Su asignacion no permite esta operacion sobre la credencial.');
            }
        }

        // 4) Freno a la extraccion masiva de secretos
        $bucket = 'secret:' . (contextoModel::id() ?? 0);
        if (!limitadorModel::intentar($bucket, 60, 300)) {
            auditoriaModel::registrarAccesoSecreto($id, $accessType, null, $field, null, 'denied', 'limite de frecuencia');
            auditoriaModel::eventoSeguridad(
                'secret_flood',
                'Consulta masiva de secretos',
                sprintf('El usuario %s supero el limite de revelados en 5 minutos.', contextoModel::nombreCompleto()),
                'high'
            );
            throw HttpException::tooManyRequests('Ha superado el limite de consultas de contrasenas. Intente mas tarde.');
        }

        // 5) Reautenticacion reciente (step-up)
        permisoModel::exigirReautenticacion('secret');

        // 6) Recuperacion del sobre cifrado
        $row = $version !== null
            ? credencialModel::secretVersion($id, $version, $field)
            : credencialModel::currentSecret($id, $field);

        if ($row === null) {
            throw HttpException::notFound('La credencial no tiene un secreto registrado para ese campo.');
        }
        if ($version !== null && (int) $row['is_current'] !== 1) {
            // Un secreto historico exige el permiso especifico (art. 32).
            permisoModel::exigir('credentials.secret.history', 'credential', $id);
        }

        $aad   = cifradoModel::aad('credential', $id, $field, (int) $row['version']);
        $plain = cifradoModel::descifrar($row, $aad);

        if ($plain === null) {
            auditoriaModel::registrarAccesoSecreto($id, $accessType, (int) $row['id'], $field, (int) $row['version'], 'denied', 'fallo de descifrado');
            auditoriaModel::eventoSeguridad(
                'decryption_failure',
                'Fallo de descifrado de un secreto',
                'La autenticacion criptografica del registro fallo. Posible manipulacion de datos o clave incorrecta.',
                'critical'
            );
            throw new HttpException(500, 'No fue posible descifrar el secreto. Contacte al administrador.');
        }

        // 7) Trazabilidad: quien, cuando, que, desde donde
        auditoriaModel::registrarAccesoSecreto($id, $accessType, (int) $row['id'], $field, (int) $row['version']);
        auditoriaModel::registrar(
            match ($accessType) {
                'copy'         => auditoriaModel::SECRET_COPIED,
                'history_view' => auditoriaModel::SECRET_HISTORY_VIEW,
                default        => auditoriaModel::SECRET_VIEWED,
            },
            'credential',
            $id,
            (string) $credential['name'],
            'success',
            ['campo' => $field, 'version' => (int) $row['version']],
            'notice'
        );

        return [
            'secret'       => $plain,
            'version'      => (int) $row['version'],
            'field'        => $field,
            'is_current'   => (bool) $row['is_current'],
            'generated_at' => (string) $row['created_at'],
        ];
    }

    /** Informacion de recuperacion (art. 5): permiso propio y auditoria propia. */
    public static function recoveryInfo(int $id): array
    {
        permisoModel::exigir('credentials.recovery.view', 'credential', $id);
        $scopeUserId = permisoModel::alcanceCredenciales();
        $credential  = credencialModel::find($id, $scopeUserId, true);
        if ($credential === null) {
            throw HttpException::notFound('La credencial solicitada no existe o no tiene acceso a ella.');
        }
        if ($scopeUserId !== null) {
            $assignment = asignacionModel::find($id, $scopeUserId);
            if ($assignment === null || (int) $assignment['can_view_recovery'] !== 1) {
                throw HttpException::forbidden('Su asignacion no incluye la informacion de recuperacion.');
            }
        }
        permisoModel::exigirReautenticacion('secret');
        auditoriaModel::registrar(auditoriaModel::RECOVERY_VIEWED, 'credential', $id, (string) $credential['name'],
            'success', [], 'notice');
        auditoriaModel::registrarAccesoSecreto($id, 'view', null, 'recovery_answer');

        return [
            'recovery_email'    => $credential['recovery_email'],
            'recovery_phone'    => $credential['recovery_phone'],
            'recovery_username' => $credential['recovery_username'],
            'recovery_notes'    => $credential['recovery_notes'],
        ];
    }

    // -----------------------------------------------------------------
    //  Escritura
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $data */
    public static function create(array $data, string $secret): int
    {
        permisoModel::exigir('credentials.create');

        if (trim($secret) === '') {
            throw new ValidationException(['password' => 'Debe indicar la contrasena de la credencial.']);
        }
        if (mb_strlen($secret) > 1024) {
            throw new ValidationException(['password' => 'El secreto no puede superar 1024 caracteres.']);
        }

        $rotationDays = $data['rotation_period_days'] ?? configuracionModel::entero('credentials.default_rotation_days', 90);
        $data['rotation_period_days'] = $rotationDays > 0 ? $rotationDays : null;
        $data['next_rotation_at']     = $rotationDays > 0
            ? (new \DateTimeImmutable())->modify("+{$rotationDays} days")->format('Y-m-d')
            : null;
        $data['created_by'] = contextoModel::id();

        $id = self::transaccion(function () use ($data, $secret): int {
            $id = credencialModel::crearRegistro($data);
            self::persistSecret($id, $secret, 'password', null, 'registro inicial');
            credencialModel::markRotated($id, $data['rotation_period_days'], contextoModel::id());
            return $id;
        });

        auditoriaModel::registrar(auditoriaModel::CREDENTIAL_CREATED, 'credential', $id, (string) $data['name'],
            'success', ['sistema_id' => $data['system_id']], 'notice');
        auditoriaModel::historialCredencial($id, 'created', [
            'reason'         => 'Alta de la credencial',
            'new_status'     => $data['status'] ?? 'active',
            'new_expires_at' => $data['expires_at'] ?? null,
        ]);

        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        permisoModel::exigir('credentials.update', 'credential', $id);
        $before = credencialModel::find($id, null, true);
        if ($before === null) {
            throw HttpException::notFound('La credencial no existe.');
        }

        credencialModel::actualizarRegistro($id, $data, contextoModel::id());

        // Diferencia campo a campo, sin registrar nunca valores sensibles.
        $tracked = ['name', 'username', 'email', 'domain', 'admin_username', 'auth_method', 'environment',
                    'owner_user_id', 'status', 'expires_at', 'rotation_period_days', 'system_id'];
        foreach ($tracked as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $old = $before[$field] ?? null;
            $new = $data[$field];
            if ((string) $old === (string) $new) {
                continue;
            }
            auditoriaModel::historialCredencial($id, $field === 'status' ? 'status_changed' : 'updated', [
                'field'          => $field,
                'old_value'      => $old === null ? null : (string) $old,
                'new_value'      => $new === null ? null : (string) $new,
                'old_status'     => $field === 'status' ? (string) $old : null,
                'new_status'     => $field === 'status' ? (string) $new : null,
                'old_expires_at' => $field === 'expires_at' ? $old : null,
                'new_expires_at' => $field === 'expires_at' ? $new : null,
                'reason'         => $data['change_reason'] ?? null,
            ]);
        }

        // Los datos de recuperacion se auditan sin volcar su contenido.
        foreach (['recovery_email', 'recovery_phone', 'recovery_username', 'recovery_notes'] as $field) {
            if (array_key_exists($field, $data) && (string) ($before[$field] ?? '') !== (string) $data[$field]) {
                auditoriaModel::historialCredencial($id, 'updated', [
                    'field'  => $field,
                    'reason' => 'Actualizacion de datos de recuperacion',
                ]);
            }
        }

        auditoriaModel::registrar(auditoriaModel::CREDENTIAL_UPDATED, 'credential', $id, (string) $before['name'],
            'success', ['campos' => array_keys($data)], 'notice');
    }

    /**
     * Rotacion de la contrasena (art. 16).
     * El secreto anterior no se pierde: pasa a ser una version historica cifrada.
     */
    public static function rotatePassword(int $id, string $newSecret, ?string $reason, ?int $rotationDays = null, string $field = 'password'): int
    {
        permisoModel::exigir('credentials.rotate', 'credential', $id);
        permisoModel::exigirReautenticacion('secret');

        $credential = credencialModel::find($id, null);
        if ($credential === null) {
            throw HttpException::notFound('La credencial no existe.');
        }
        if (trim($newSecret) === '') {
            throw new ValidationException(['password' => 'Debe indicar la nueva contrasena.']);
        }
        if (mb_strlen($newSecret) > 1024) {
            throw new ValidationException(['password' => 'El secreto no puede superar 1024 caracteres.']);
        }

        // No se admite repetir el secreto vigente (comparacion por huella,
        // sin necesidad de descifrar nada).
        $current = credencialModel::currentSecret($id, $field);
        if ($current !== null && $current['fingerprint'] !== null
            && hash_equals((string) $current['fingerprint'], cifradoModel::huella($newSecret))) {
            throw new ValidationException(['password' => 'La nueva contrasena debe ser distinta de la actual.']);
        }

        $days = $rotationDays ?? ($credential['rotation_period_days'] !== null
            ? (int) $credential['rotation_period_days']
            : configuracionModel::entero('credentials.default_rotation_days', 90));

        $version = self::transaccion(function () use ($id, $newSecret, $field, $reason, $days): int {
            $version = self::persistSecret($id, $newSecret, $field, null, $reason);
            credencialModel::markRotated($id, $days > 0 ? $days : null, contextoModel::id());
            return $version;
        });

        auditoriaModel::registrar(auditoriaModel::CREDENTIAL_ROTATED, 'credential', $id, (string) $credential['name'],
            'success', ['campo' => $field, 'version' => $version, 'motivo' => $reason], 'notice');
        auditoriaModel::historialCredencial($id, 'password_rotated', [
            'secret_version' => $version,
            'field'          => $field,
            'reason'         => $reason ?? 'Rotacion de contrasena',
            'metadata'       => ['proxima_rotacion_dias' => $days],
        ]);

        return $version;
    }

    public static function delete(int $id, string $reason): void
    {
        permisoModel::exigir('credentials.delete', 'credential', $id);
        $credential = credencialModel::find($id, null);
        if ($credential === null) {
            throw HttpException::notFound('La credencial no existe.');
        }
        // Baja LOGICA: el historial y la auditoria se conservan intactos.
        credencialModel::softDelete($id, contextoModel::id());
        auditoriaModel::registrar(auditoriaModel::CREDENTIAL_DELETED, 'credential', $id, (string) $credential['name'],
            'success', ['motivo' => $reason], 'warning');
        auditoriaModel::historialCredencial($id, 'deleted', [
            'reason'     => $reason,
            'old_status' => (string) $credential['status'],
            'new_status' => 'archived',
        ]);
    }

    public static function restore(int $id): void
    {
        permisoModel::exigir('credentials.delete', 'credential', $id);
        $credential = credencialModel::find($id, null, false, true);
        if ($credential === null) {
            throw HttpException::notFound('La credencial no existe.');
        }
        credencialModel::restaurarRegistro($id, contextoModel::id());
        auditoriaModel::registrar(auditoriaModel::CREDENTIAL_RESTORED, 'credential', $id, (string) $credential['name'],
            'success', [], 'notice');
        auditoriaModel::historialCredencial($id, 'restored', ['reason' => 'Reactivacion de la credencial']);
    }

    // -----------------------------------------------------------------
    //  Asignaciones
    // -----------------------------------------------------------------

    public static function assign(int $credentialId, int $userId, array $options): void
    {
        permisoModel::exigir('credentials.assign', 'credential', $credentialId);

        $credential = credencialModel::find($credentialId, null);
        if ($credential === null) {
            throw HttpException::notFound('La credencial no existe.');
        }
        $user = usuarioModel::find($userId);
        if ($user === null) {
            throw HttpException::notFound('El usuario no existe.');
        }
        if ($user['status'] !== 'active') {
            throw new ValidationException(['user_id' => 'No se pueden asignar credenciales a un usuario inactivo.']);
        }

        asignacionModel::grant($credentialId, $userId, $options, (int) contextoModel::id());

        auditoriaModel::registrar(auditoriaModel::CREDENTIAL_ASSIGNED, 'credential', $credentialId,
            (string) $credential['name'], 'success', [
                'usuario'      => $user['username'],
                'cedula'       => $user['national_id'],
                'ver_secreto'  => (bool) ($options['can_view_secret'] ?? true),
                'copiar'       => (bool) ($options['can_copy_secret'] ?? true),
                'recuperacion' => (bool) ($options['can_view_recovery'] ?? false),
            ], 'notice');
        auditoriaModel::historialCredencial($credentialId, 'assigned', [
            'field'     => 'assignment',
            'new_value' => $user['national_id'] . ' - ' . $user['first_name'] . ' ' . $user['last_name'],
            'reason'    => $options['reason'] ?? 'Asignacion de acceso',
        ]);
    }

    public static function revoke(int $credentialId, int $userId, string $reason): void
    {
        permisoModel::exigir('credentials.revoke', 'credential', $credentialId);

        $credential = credencialModel::find($credentialId, null);
        if ($credential === null) {
            throw HttpException::notFound('La credencial no existe.');
        }
        $user = usuarioModel::find($userId);
        asignacionModel::revoke($credentialId, $userId, (int) contextoModel::id(), $reason);

        auditoriaModel::registrar(auditoriaModel::CREDENTIAL_REVOKED, 'credential', $credentialId,
            (string) $credential['name'], 'success',
            ['usuario' => $user['username'] ?? $userId, 'motivo' => $reason], 'warning');
        auditoriaModel::historialCredencial($credentialId, 'revoked', [
            'field'     => 'assignment',
            'old_value' => ($user['national_id'] ?? '') . ' - ' . trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'reason'    => $reason,
        ]);
    }

    // -----------------------------------------------------------------
    //  Internos
    // -----------------------------------------------------------------

    private static function persistSecret(int $credentialId, string $secret, string $field, ?string $label, ?string $reason): int
    {
        $nextVersion = 1 + (int) (self::obtenerValor(
            'SELECT COALESCE(MAX(version), 0) FROM credential_secrets WHERE credential_id = ? AND field = ?',
            [$credentialId, $field]
        ) ?? 0);

        $envelope = cifradoModel::cifrar($secret, cifradoModel::aad('credential', $credentialId, $field, $nextVersion));

        return credencialModel::storeSecret(
            $credentialId,
            $envelope,
            $field,
            $label,
            mb_strlen($secret),
            generadorModel::strength($secret),
            cifradoModel::huella($secret),
            $reason,
            contextoModel::id()
        );
    }

    /**
     * Proyeccion de un elemento de listado. Nunca incluye secretos ni
     * datos de recuperacion.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function presentListItem(array $row): array
    {
        return [
            'id'                => (int) $row['id'],
            'name'              => $row['name'],
            'system_id'         => (int) $row['system_id'],
            'system_name'       => $row['system_name'],
            'category_name'     => $row['category_name'],
            'category_color'    => $row['category_color'],
            'resource_type'     => $row['resource_type'],
            'username'          => $row['username'],
            'email'             => $row['email'],
            'url'               => $row['url'],
            'environment'       => $row['environment'],
            'status'            => $row['status'],
            'owner_name'        => $row['owner_name'] ?: null,
            'company_name'      => $row['company_name'],
            'location_name'     => $row['location_name'],
            'department_name'   => $row['department_name'],
            'assignment_count'  => (int) $row['assignment_count'],
            'password_changed_at' => $row['password_changed_at'],
            'next_rotation_at'  => $row['next_rotation_at'],
            'expires_at'        => $row['expires_at'],
            'updated_at'        => $row['updated_at'],
            'rotation_state'    => self::rotationState($row),
            // Marcador explicito: la API nunca devuelve el secreto en un listado.
            'has_secret'        => true,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function presentDetail(array $row, bool $includeRecovery): array
    {
        $detail = [
            'id'                     => (int) $row['id'],
            'name'                   => $row['name'],
            'environment'            => $row['environment'],
            'status'                 => $row['status'],
            'username'               => $row['username'],
            'email'                  => $row['email'],
            'domain'                 => $row['domain'],
            'admin_username'         => $row['admin_username'],
            'auth_method'            => $row['auth_method'],
            'observations'           => $row['observations'],
            'has_security_questions' => (bool) $row['has_security_questions'],
            'owner_user_id'          => $row['owner_user_id'] !== null ? (int) $row['owner_user_id'] : null,
            'owner_name'             => trim(($row['owner_first_name'] ?? '') . ' ' . ($row['owner_last_name'] ?? '')) ?: null,
            'owner_national_id'      => $row['owner_national_id'] ?? null,
            'created_at'             => $row['created_at'],
            'updated_at'             => $row['updated_at'],
            'password_changed_at'    => $row['password_changed_at'],
            'rotation_period_days'   => $row['rotation_period_days'] !== null ? (int) $row['rotation_period_days'] : null,
            'next_rotation_at'       => $row['next_rotation_at'],
            'expires_at'             => $row['expires_at'],
            'created_by_username'    => $row['created_by_username'],
            'updated_by_username'    => $row['updated_by_username'],
            'assignment_count'       => (int) $row['assignment_count'],
            'secret_version'         => $row['secret_version'] !== null ? (int) $row['secret_version'] : null,
            'secret_count'           => (int) $row['secret_count'],
            'rotation_state'         => self::rotationState($row),
            'system' => [
                'id'            => (int) $row['system_id'],
                'name'          => $row['system_name'],
                'display_name'  => $row['system_display_name'],
                'description'   => $row['system_description'],
                'url'           => $row['url'],
                'ip_address'    => $row['ip_address'],
                'port'          => $row['port'] !== null ? (int) $row['port'] : null,
                'hostname'      => $row['hostname'],
                'platform'      => $row['platform'],
                'provider'      => $row['provider'],
                'resource_type' => $row['resource_type'],
                'criticality'   => $row['criticality'],
                'status'        => $row['system_status'],
            ],
            'category' => [
                'id'    => $row['category_id'] !== null ? (int) $row['category_id'] : null,
                'name'  => $row['category_name'],
                'color' => $row['category_color'],
            ],
            'organization' => [
                'company'    => $row['company_name'],
                'location'   => $row['location_name'],
                'department' => $row['department_name'],
            ],
            // La informacion de recuperacion se entrega SOLO tras el
            // endpoint especifico; aqui unicamente se indica si existe.
            'has_recovery_info' => ($row['recovery_email'] ?? null) !== null
                                || ($row['recovery_phone'] ?? null) !== null
                                || ($row['recovery_username'] ?? null) !== null,
        ];

        if ($includeRecovery) {
            $detail['recovery_available'] = true;
        }

        return $detail;
    }

    /** @param array<string,mixed> $row */
    private static function rotationState(array $row): string
    {
        $today  = new \DateTimeImmutable('today');
        $expiry = $row['expires_at'] ?? null;
        if ($expiry !== null && new \DateTimeImmutable((string) $expiry) < $today) {
            return 'expired';
        }
        $rotation = $row['next_rotation_at'] ?? null;
        if ($rotation !== null) {
            $date = new \DateTimeImmutable((string) $rotation);
            if ($date < $today) {
                return 'rotation_due';
            }
            $warning = configuracionModel::entero('alerts.expiry_warning_days', 15);
            if ($date <= $today->modify("+{$warning} days")) {
                return 'rotation_soon';
            }
        }
        if (($row['password_changed_at'] ?? null) === null) {
            return 'never_rotated';
        }
        return 'ok';
    }

}
