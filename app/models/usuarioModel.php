<?php
declare(strict_types=1);

namespace app\models;

use App\Core\HttpException;
use App\Core\ValidationException;


/**
 * Persistencia de usuarios, roles efectivos y permisos efectivos.
 *
 * Nota de seguridad: los SELECT nunca proyectan `password_hash` salvo en
 * el metodo explicito findAuthRecord(), usado unicamente por AuthService.
 */
class usuarioModel extends mainModel
{
    private const PUBLIC_COLUMNS = 'u.id, u.national_id, u.employee_code, u.username, u.email,
        u.first_name, u.last_name, u.phone, u.position, u.company_id, u.location_id, u.department_id,
        u.status, u.mfa_enabled, u.mfa_enforced, u.must_change_password, u.password_changed_at,
        u.failed_attempts, u.locked_until, u.last_login_at, u.last_login_ip, u.notes,
        u.created_at, u.updated_at, u.deactivated_at, u.deactivation_reason';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return self::obtenerFila(
            'SELECT ' . self::PUBLIC_COLUMNS . ',
                    c.name AS company_name, l.name AS location_name, d.name AS department_name
               FROM users u
               LEFT JOIN companies   c ON c.id = u.company_id
               LEFT JOIN locations   l ON l.id = u.location_id
               LEFT JOIN departments d ON d.id = u.department_id
              WHERE u.id = ?',
            [$id]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findByNationalId(string $nationalId): ?array
    {
        return self::obtenerFila('SELECT ' . self::PUBLIC_COLUMNS . ' FROM users u WHERE u.national_id = ?', [$nationalId]);
    }

    /**
     * Registro completo para autenticacion (incluye el hash).
     * Acepta usuario, correo o cedula como identificador.
     *
     * @return array<string,mixed>|null
     */
    public static function findAuthRecord(string $identifier): ?array
    {
        return self::obtenerFila(
            'SELECT u.*, c.name AS company_name
               FROM users u
               LEFT JOIN companies c ON c.id = u.company_id
              WHERE u.username = :id OR u.email = :id OR u.national_id = :id
              LIMIT 1',
            ['id' => $identifier]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findAuthRecordById(int $id): ?array
    {
        return self::obtenerFila('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function existsField(string $field, string $value, ?int $exceptId = null): bool
    {
        $allowed = ['username', 'email', 'national_id', 'employee_code'];
        if (!in_array($field, $allowed, true)) {
            return false;
        }
        $sql    = "SELECT COUNT(*) FROM users WHERE {$field} = ?";
        $params = [$value];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return (int) self::obtenerValor($sql, $params) > 0;
    }

    /** @param array<string,mixed> $data */
    public static function crearRegistro(array $data): int
    {
        return self::ejecutarInsert(
            'INSERT INTO users
               (national_id, employee_code, username, email, first_name, last_name, phone, position,
                company_id, location_id, department_id, password_hash, password_algo, password_changed_at,
                must_change_password, status, mfa_enforced, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?)',
            [
                $data['national_id'], $data['employee_code'] ?? null, $data['username'], $data['email'],
                $data['first_name'], $data['last_name'], $data['phone'] ?? null, $data['position'] ?? null,
                $data['company_id'] ?? null, $data['location_id'] ?? null, $data['department_id'] ?? null,
                $data['password_hash'], $data['password_algo'],
                (int) ($data['must_change_password'] ?? 1), $data['status'] ?? 'active',
                (int) ($data['mfa_enforced'] ?? 0), $data['notes'] ?? null, $data['created_by'] ?? null,
            ]
        );
    }

    /** @param array<string,mixed> $data */
    public static function actualizarRegistro(int $id, array $data, ?int $updatedBy = null): void
    {
        $allowed = [
            'national_id', 'employee_code', 'username', 'email', 'first_name', 'last_name',
            'phone', 'position', 'company_id', 'location_id', 'department_id', 'status',
            'mfa_enforced', 'notes',
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
        self::ejecutarConsultaAfectadas('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    public static function updatePassword(int $id, string $hash, string $algo, bool $mustChange = false): void
    {
        self::ejecutarConsultaAfectadas(
            'UPDATE users
                SET password_hash = ?, password_algo = ?, password_changed_at = NOW(),
                    must_change_password = ?, failed_attempts = 0, locked_until = NULL
              WHERE id = ?',
            [$hash, $algo, $mustChange ? 1 : 0, $id]
        );
    }

    public static function registerSuccessfulLogin(int $id, string $ip): void
    {
        self::ejecutarConsultaAfectadas(
            'UPDATE users SET last_login_at = NOW(), last_login_ip = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?',
            [$ip, $id]
        );
    }

    public static function incrementFailedAttempts(int $id, int $maxAttempts, int $lockMinutes): int
    {
        self::ejecutarConsultaAfectadas('UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?', [$id]);
        $attempts = (int) self::obtenerValor('SELECT failed_attempts FROM users WHERE id = ?', [$id]);
        if ($attempts >= $maxAttempts) {
            self::ejecutarConsultaAfectadas(
                'UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?',
                [$lockMinutes, $id]
            );
        }
        return $attempts;
    }

    public static function clearLock(int $id): void
    {
        self::ejecutarConsultaAfectadas('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$id]);
    }

    public static function desactivarRegistro(int $id, int $by, string $reason): void
    {
        self::ejecutarConsultaAfectadas(
            "UPDATE users
                SET status = 'inactive', deactivated_at = NOW(), deactivated_by = ?, deactivation_reason = ?
              WHERE id = ?",
            [$by, mb_substr($reason, 0, 255), $id]
        );
    }

    public static function reactivarRegistro(int $id, int $by): void
    {
        self::ejecutarConsultaAfectadas(
            "UPDATE users
                SET status = 'active', deactivated_at = NULL, deactivated_by = NULL,
                    deactivation_reason = NULL, failed_attempts = 0, locked_until = NULL, updated_by = ?
              WHERE id = ?",
            [$by, $id]
        );
    }

    public static function setMfaEnabled(int $id, bool $enabled): void
    {
        self::ejecutarConsultaAfectadas('UPDATE users SET mfa_enabled = ? WHERE id = ?', [$enabled ? 1 : 0, $id]);
    }

    // ---------------------------------------------------------------
    //  Roles y permisos
    // ---------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public static function rolesOf(int $userId): array
    {
        return self::obtenerFilas(
            'SELECT r.id, r.code, r.name, r.level, r.requires_mfa
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id = ? AND r.is_active = 1
              ORDER BY r.level DESC',
            [$userId]
        );
    }

    /**
     * Permisos EFECTIVOS: union de los permisos de sus roles mas las
     * concesiones individuales, menos las denegaciones individuales.
     * La denegacion siempre prevalece (principio de minimo privilegio).
     *
     * @return array<int,string>
     */
    public static function effectivePermissions(int $userId): array
    {
        $rows = self::obtenerFilas(
            "SELECT p.code
               FROM user_roles ur
               JOIN roles r            ON r.id = ur.role_id AND r.is_active = 1
               JOIN role_permissions rp ON rp.role_id = r.id
               JOIN permissions p       ON p.id = rp.permission_id
              WHERE ur.user_id = :uid
              UNION
             SELECT p.code
               FROM user_permissions up
               JOIN permissions p ON p.id = up.permission_id
              WHERE up.user_id = :uid AND up.effect = 'allow'",
            ['uid' => $userId]
        );
        $granted = array_column($rows, 'code');

        $denied = array_column(self::obtenerFilas(
            "SELECT p.code FROM user_permissions up
               JOIN permissions p ON p.id = up.permission_id
              WHERE up.user_id = ? AND up.effect = 'deny'",
            [$userId]
        ), 'code');

        return array_values(array_diff($granted, $denied));
    }

    /** @param array<int,int> $roleIds */
    public static function setRoles(int $userId, array $roleIds, int $assignedBy): void
    {
        self::transaccion(function () use ($userId, $roleIds, $assignedBy): void {
            self::ejecutarConsultaAfectadas('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
            foreach (array_unique($roleIds) as $roleId) {
                self::ejecutarConsultaAfectadas(
                    'INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?,?,?)',
                    [$userId, (int) $roleId, $assignedBy]
                );
            }
        });
    }

    /** @param array<string,string> $overrides code => allow|deny */
    public static function fijarExcepcionesPermisos(int $userId, array $overrides, int $assignedBy): void
    {
        self::transaccion(function () use ($userId, $overrides, $assignedBy): void {
            self::ejecutarConsultaAfectadas('DELETE FROM user_permissions WHERE user_id = ?', [$userId]);
            foreach ($overrides as $code => $effect) {
                if (!in_array($effect, ['allow', 'deny'], true)) {
                    continue;
                }
                $permissionId = self::obtenerValor('SELECT id FROM permissions WHERE code = ?', [$code]);
                if ($permissionId === null) {
                    continue;
                }
                self::ejecutarConsultaAfectadas(
                    'INSERT INTO user_permissions (user_id, permission_id, effect, assigned_by) VALUES (?,?,?,?)',
                    [$userId, (int) $permissionId, $effect, $assignedBy]
                );
            }
        });
    }

    /** @return array<string,string> */
    public static function permissionOverrides(int $userId): array
    {
        $rows = self::obtenerFilas(
            'SELECT p.code, up.effect FROM user_permissions up
               JOIN permissions p ON p.id = up.permission_id
              WHERE up.user_id = ?',
            [$userId]
        );
        return array_column($rows, 'effect', 'code');
    }

    // ---------------------------------------------------------------
    //  Listados
    // ---------------------------------------------------------------

    /**
     * @param array<string,mixed> $filters
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function paginate(array $filters, int $page, int $perPage): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[]          = '(u.first_name LIKE :q OR u.last_name LIKE :q OR u.username LIKE :q
                                  OR u.email LIKE :q OR u.national_id LIKE :q OR u.employee_code LIKE :q)';
            $params['q']      = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['status'])) {
            $where[]            = 'u.status = :status';
            $params['status']   = $filters['status'];
        }
        if (!empty($filters['role_id'])) {
            $where[]            = 'EXISTS (SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id AND ur.role_id = :role_id)';
            $params['role_id']  = (int) $filters['role_id'];
        }
        if (!empty($filters['company_id'])) {
            $where[]              = 'u.company_id = :company_id';
            $params['company_id'] = (int) $filters['company_id'];
        }
        if (!empty($filters['department_id'])) {
            $where[]                 = 'u.department_id = :department_id';
            $params['department_id'] = (int) $filters['department_id'];
        }

        $whereSql = implode(' AND ', $where);
        $total    = (int) self::obtenerValor("SELECT COUNT(*) FROM users u WHERE {$whereSql}", $params);

        $offset = max(0, ($page - 1) * $perPage);
        $items  = self::obtenerFilas(
            'SELECT ' . self::PUBLIC_COLUMNS . ',
                    c.name AS company_name, d.name AS department_name,
                    (SELECT GROUP_CONCAT(r.name ORDER BY r.level DESC SEPARATOR ", ")
                       FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                      WHERE ur.user_id = u.id) AS role_names,
                    (SELECT COUNT(*) FROM credential_assignments ca
                      WHERE ca.user_id = u.id AND ca.is_active = 1) AS assigned_credentials
               FROM users u
               LEFT JOIN companies   c ON c.id = u.company_id
               LEFT JOIN departments d ON d.id = u.department_id
              WHERE ' . $whereSql . '
              ORDER BY u.status ASC, u.last_name ASC, u.first_name ASC
              LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<int,array<string,mixed>> */
    public static function activeSelectList(): array
    {
        return self::obtenerFilas(
            "SELECT id, national_id, CONCAT(first_name, ' ', last_name, ' (', national_id, ')') AS label
               FROM users WHERE status = 'active' ORDER BY first_name, last_name"
        );
    }

    /** @return array<string,int> */
    public static function counters(): array
    {
        $row = self::obtenerFila(
            "SELECT COUNT(*) AS total,
                    SUM(status = 'active')   AS active,
                    SUM(status <> 'active')  AS inactive,
                    SUM(mfa_enabled = 1)     AS with_mfa,
                    SUM(locked_until IS NOT NULL AND locked_until > NOW()) AS locked
               FROM users"
        ) ?? [];
        return [
            'total'    => (int) ($row['total'] ?? 0),
            'active'   => (int) ($row['active'] ?? 0),
            'inactive' => (int) ($row['inactive'] ?? 0),
            'with_mfa' => (int) ($row['with_mfa'] ?? 0),
            'locked'   => (int) ($row['locked'] ?? 0),
        ];
    }

    /** Usuarios desactivados que conservan asignaciones activas (alerta art. 15). */
    public static function inactiveWithActiveAssignments(): array
    {
        return self::obtenerFilas(
            "SELECT u.id, u.national_id, u.first_name, u.last_name, u.status,
                    COUNT(ca.id) AS active_assignments
               FROM users u
               JOIN credential_assignments ca ON ca.user_id = u.id AND ca.is_active = 1
              WHERE u.status <> 'active'
              GROUP BY u.id, u.national_id, u.first_name, u.last_name, u.status
              ORDER BY active_assignments DESC"
        );
    }

    // =================================================================
    //  Logica de negocio (fusionada desde UserService.php)
    // =================================================================


    public static function list(array $filters, int $page, int $perPage): array
    {
        permisoModel::exigir('users.view');
        $perPage = max(5, min(100, $perPage));
        $result  = usuarioModel::paginate($filters, max(1, $page), $perPage);
        return [
            'items'    => $result['items'],
            'total'    => $result['total'],
            'page'     => max(1, $page),
            'per_page' => $perPage,
            'pages'    => (int) ceil($result['total'] / $perPage),
        ];
    }

    public static function show(int $id): array
    {
        permisoModel::exigir('users.view', 'user', $id);
        $user = usuarioModel::find($id);
        if ($user === null) {
            throw HttpException::notFound('El usuario no existe.');
        }
        return [
            'user'        => $user,
            'roles'       => usuarioModel::rolesOf($id),
            'permissions' => usuarioModel::effectivePermissions($id),
            'overrides'   => usuarioModel::permissionOverrides($id),
            'assignments' => asignacionModel::forUser($id, false),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{id:int,temporary_password:string}
     */
    public static function create(array $data, array $roleIds, ?string $password = null): array
    {
        permisoModel::exigir('users.create');
        self::assertUnique($data);
        self::assertAssignableRoles($roleIds);

        // Si no se indica contrasena se genera una temporal robusta que el
        // usuario debera cambiar en su primer ingreso.
        $temporary = $password === null || $password === '';
        $plain     = $temporary
            ? generadorModel::generate(['length' => 16, 'exclude_ambiguous' => true])
            : $password;

        if (!$temporary) {
            $this->auth->validatePasswordPolicy($plain, $data);
        }

        $hash = cifradoModel::hashContrasena($plain);

        $id = self::transaccion(function () use ($data, $hash, $roleIds, $temporary): int {
            $id = usuarioModel::crearRegistro(array_merge($data, [
                'password_hash'        => $hash['hash'],
                'password_algo'        => $hash['algo'],
                'must_change_password' => 1,
                'created_by'           => contextoModel::id(),
            ]));
            usuarioModel::setRoles($id, $roleIds, (int) contextoModel::id());
            return $id;
        });
        unset($temporary);

        auditoriaModel::registrar(auditoriaModel::USER_CREATED, 'user', $id,
            $data['first_name'] . ' ' . $data['last_name'], 'success',
            ['cedula' => $data['national_id'], 'usuario' => $data['username'], 'roles' => $roleIds], 'notice');

        return ['id' => $id, 'temporary_password' => $plain];
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data, ?array $roleIds = null): void
    {
        permisoModel::exigir('users.update', 'user', $id);
        $target = usuarioModel::find($id);
        if ($target === null) {
            throw HttpException::notFound('El usuario no existe.');
        }
        permisoModel::exigirGestionUsuario(self::levelOf($id));
        self::assertUnique($data, $id);

        usuarioModel::actualizarRegistro($id, $data, contextoModel::id());

        if ($roleIds !== null) {
            permisoModel::exigir('users.assign_roles', 'user', $id);
            self::assertAssignableRoles($roleIds);
            $before = array_column(usuarioModel::rolesOf($id), 'code');
            usuarioModel::setRoles($id, $roleIds, (int) contextoModel::id());
            $after  = array_column(usuarioModel::rolesOf($id), 'code');
            if ($before !== $after) {
                auditoriaModel::registrar(auditoriaModel::USER_ROLES_CHANGED, 'user', $id,
                    $target['first_name'] . ' ' . $target['last_name'], 'success',
                    ['antes' => $before, 'despues' => $after], 'warning');
                // Cambiar privilegios invalida las sesiones abiertas del usuario.
                sesionModel::revocarTodasDeUsuario($id, contextoModel::id(), 'cambio de roles');
            }
        }

        auditoriaModel::registrar(auditoriaModel::USER_UPDATED, 'user', $id,
            $target['first_name'] . ' ' . $target['last_name'], 'success',
            ['campos' => array_keys($data)], 'notice');
    }

    /** @param array<string,string> $overrides */
    public static function setPermissionOverrides(int $id, array $overrides): void
    {
        permisoModel::exigir('users.assign_roles', 'user', $id);
        permisoModel::exigirGestionUsuario(self::levelOf($id));

        // Nadie puede concederse a si mismo un permiso que no posee.
        foreach ($overrides as $code => $effect) {
            if ($effect === 'allow' && !$this->context->can($code) && !contextoModel::esSuperadministrador()) {
                throw HttpException::forbidden('No puede otorgar un permiso que usted no posee: ' . $code);
            }
        }

        usuarioModel::fijarExcepcionesPermisos($id, $overrides, (int) contextoModel::id());
        sesionModel::revocarTodasDeUsuario($id, contextoModel::id(), 'cambio de permisos');
        auditoriaModel::registrar(auditoriaModel::USER_PERMS_CHANGED, 'user', $id, null, 'success',
            ['excepciones' => $overrides], 'warning');
    }

    /**
     * Baja de un empleado (art. 19): bloquea el acceso, revoca asignaciones
     * y CONSERVA todo el historial y la auditoria.
     */
    public static function deactivate(int $id, string $reason, ?int $reassignToUserId = null): array
    {
        permisoModel::exigir('users.deactivate', 'user', $id);
        $target = usuarioModel::find($id);
        if ($target === null) {
            throw HttpException::notFound('El usuario no existe.');
        }
        permisoModel::exigirGestionUsuario(self::levelOf($id), $id);

        $previousAssignments = asignacionModel::forUser($id, true);

        $result = self::transaccion(function () use ($id, $reason, $reassignToUserId): array {
            usuarioModel::desactivarRegistro($id, (int) contextoModel::id(), $reason);
            $revoked = asignacionModel::revokeAllForUser($id, (int) contextoModel::id(), 'baja del usuario: ' . $reason);
            $reassigned = 0;
            if ($reassignToUserId !== null) {
                $reassigned = asignacionModel::reassign($id, $reassignToUserId, (int) contextoModel::id());
            }
            return ['revoked' => $revoked, 'reassigned' => $reassigned];
        });

        $closed = sesionModel::revocarTodasDeUsuario($id, contextoModel::id(), 'usuario desactivado');

        auditoriaModel::registrar(auditoriaModel::USER_DEACTIVATED, 'user', $id,
            $target['first_name'] . ' ' . $target['last_name'], 'success', [
                'motivo'               => $reason,
                'accesos_revocados'    => $result['revoked'],
                'accesos_reasignados'  => $result['reassigned'],
                'sesiones_cerradas'    => $closed,
                'credenciales_previas' => array_map(
                    static fn (array $a): string => (string) $a['system_name'] . ' / ' . (string) $a['credential_name'],
                    $previousAssignments
                ),
            ], 'warning');

        return array_merge($result, ['sessions_closed' => $closed, 'previous' => $previousAssignments]);
    }

    public static function reactivate(int $id): void
    {
        permisoModel::exigir('users.deactivate', 'user', $id);
        $target = usuarioModel::find($id);
        if ($target === null) {
            throw HttpException::notFound('El usuario no existe.');
        }
        permisoModel::exigirGestionUsuario(self::levelOf($id));
        usuarioModel::reactivarRegistro($id, (int) contextoModel::id());
        auditoriaModel::registrar(auditoriaModel::USER_REACTIVATED, 'user', $id,
            $target['first_name'] . ' ' . $target['last_name'], 'success', [], 'notice');
    }

    /** Restablecimiento administrativo: entrega una clave temporal de un solo uso. */
    public static function resetPassword(int $id): string
    {
        permisoModel::exigir('users.reset_password', 'user', $id);
        $target = usuarioModel::find($id);
        if ($target === null) {
            throw HttpException::notFound('El usuario no existe.');
        }
        permisoModel::exigirGestionUsuario(self::levelOf($id), $id);
        permisoModel::exigirReautenticacion('secret');

        $plain = generadorModel::generate(['length' => 16, 'exclude_ambiguous' => true]);
        $hash  = cifradoModel::hashContrasena($plain);
        usuarioModel::updatePassword($id, $hash['hash'], $hash['algo'], true);
        sesionModel::revocarTodasDeUsuario($id, contextoModel::id(), 'restablecimiento administrativo');

        auditoriaModel::registrar(auditoriaModel::USER_PASSWORD_RESET, 'user', $id,
            $target['first_name'] . ' ' . $target['last_name'], 'success', [], 'warning');

        return $plain;
    }

    public static function assignmentsOf(int $id): array
    {
        permisoModel::exigirAlguno(['users.view', 'credentials.assign'], 'user', $id);
        return asignacionModel::forUser($id, false);
    }

    // -----------------------------------------------------------------

    private static function levelOf(int $userId): int
    {
        $roles = usuarioModel::rolesOf($userId);
        $max   = 0;
        foreach ($roles as $role) {
            $max = max($max, (int) $role['level']);
        }
        return $max;
    }

    /** Nadie puede otorgar un rol de nivel igual o superior al suyo. */
    private static function assertAssignableRoles(array $roleIds): void
    {
        if (contextoModel::esSuperadministrador()) {
            return;
        }
        $level = contextoModel::nivel();
        foreach ($roleIds as $roleId) {
            $roleLevel = (int) self::obtenerValor('SELECT level FROM roles WHERE id = ?', [(int) $roleId]);
            if ($roleLevel >= $level) {
                throw HttpException::forbidden('No puede asignar un rol de nivel igual o superior al suyo.');
            }
        }
    }

    private static function assertUnique(array $data, ?int $exceptId = null): void
    {
        $errors = [];
        foreach (['national_id' => 'La cedula', 'username' => 'El nombre de usuario', 'email' => 'El correo'] as $field => $label) {
            if (!empty($data[$field]) && usuarioModel::existsField($field, (string) $data[$field], $exceptId)) {
                $errors[$field] = $label . ' ya esta registrado para otro usuario.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }
}
