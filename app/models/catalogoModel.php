<?php
declare(strict_types=1);

namespace app\models;


/** Catalogos: categorias, empresas, sedes y departamentos. */
class catalogoModel extends mainModel
{
    // ------------------------------ Categorias -------------------------
    public static function categories(bool $onlyActive = true): array
    {
        return self::obtenerFilas(
            'SELECT c.*, (SELECT COUNT(*) FROM systems s WHERE s.category_id = c.id) AS system_count
               FROM categories c ' . ($onlyActive ? 'WHERE c.is_active = 1 ' : '') . '
              ORDER BY c.sort_order, c.name'
        );
    }

    public static function findCategory(int $id): ?array
    {
        return self::obtenerFila('SELECT * FROM categories WHERE id = ?', [$id]);
    }

    public static function createCategory(array $data): int
    {
        return self::ejecutarInsert(
            'INSERT INTO categories (name, slug, description, color, icon, sort_order, is_active)
             VALUES (?,?,?,?,?,?,1)',
            [
                $data['name'], self::slug($data['name']), $data['description'] ?? null,
                $data['color'] ?? '#64748b', $data['icon'] ?? 'folder', (int) ($data['sort_order'] ?? 0),
            ]
        );
    }

    public static function updateCategory(int $id, array $data): void
    {
        self::ejecutarConsultaAfectadas(
            'UPDATE categories SET name = ?, slug = ?, description = ?, color = ?, icon = ?, sort_order = ?, is_active = ?
              WHERE id = ?',
            [
                $data['name'], self::slug($data['name']), $data['description'] ?? null,
                $data['color'] ?? '#64748b', $data['icon'] ?? 'folder',
                (int) ($data['sort_order'] ?? 0), (int) ($data['is_active'] ?? 1), $id,
            ]
        );
    }

    public static function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = strtr($slug, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-') ?: 'categoria';
    }

    // ------------------------------ Empresas ---------------------------
    public static function companies(bool $onlyActive = true): array
    {
        return self::obtenerFilas(
            'SELECT * FROM companies ' . ($onlyActive ? 'WHERE is_active = 1 ' : '') . 'ORDER BY name'
        );
    }

    public static function createCompany(array $data): int
    {
        return self::ejecutarInsert(
            'INSERT INTO companies (name, legal_name, tax_id) VALUES (?,?,?)',
            [$data['name'], $data['legal_name'] ?? null, $data['tax_id'] ?? null]
        );
    }

    public static function updateCompany(int $id, array $data): void
    {
        self::ejecutarConsultaAfectadas(
            'UPDATE companies SET name = ?, legal_name = ?, tax_id = ?, is_active = ? WHERE id = ?',
            [$data['name'], $data['legal_name'] ?? null, $data['tax_id'] ?? null, (int) ($data['is_active'] ?? 1), $id]
        );
    }

    // ------------------------------ Sedes ------------------------------
    public static function locations(?int $companyId = null, bool $onlyActive = true): array
    {
        $where  = [];
        $params = [];
        if ($companyId !== null) {
            $where[]              = 'l.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($onlyActive) {
            $where[] = 'l.is_active = 1';
        }
        $sql = 'SELECT l.*, c.name AS company_name FROM locations l JOIN companies c ON c.id = l.company_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return self::obtenerFilas($sql . ' ORDER BY c.name, l.name', $params);
    }

    public static function createLocation(array $data): int
    {
        return self::ejecutarInsert(
            'INSERT INTO locations (company_id, name, address, city, country) VALUES (?,?,?,?,?)',
            [(int) $data['company_id'], $data['name'], $data['address'] ?? null, $data['city'] ?? null, $data['country'] ?? null]
        );
    }

    public static function updateLocation(int $id, array $data): void
    {
        self::ejecutarConsultaAfectadas(
            'UPDATE locations SET company_id = ?, name = ?, address = ?, city = ?, country = ?, is_active = ? WHERE id = ?',
            [
                (int) $data['company_id'], $data['name'], $data['address'] ?? null, $data['city'] ?? null,
                $data['country'] ?? null, (int) ($data['is_active'] ?? 1), $id,
            ]
        );
    }

    // --------------------------- Departamentos -------------------------
    public static function departments(?int $companyId = null, bool $onlyActive = true): array
    {
        $where  = [];
        $params = [];
        if ($companyId !== null) {
            $where[]              = 'd.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($onlyActive) {
            $where[] = 'd.is_active = 1';
        }
        $sql = 'SELECT d.*, c.name AS company_name, l.name AS location_name
                  FROM departments d
                  JOIN companies c ON c.id = d.company_id
                  LEFT JOIN locations l ON l.id = d.location_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return self::obtenerFilas($sql . ' ORDER BY c.name, d.name', $params);
    }

    public static function createDepartment(array $data): int
    {
        return self::ejecutarInsert(
            'INSERT INTO departments (company_id, location_id, name, code) VALUES (?,?,?,?)',
            [(int) $data['company_id'], $data['location_id'] ?? null, $data['name'], $data['code'] ?? null]
        );
    }

    public static function updateDepartment(int $id, array $data): void
    {
        self::ejecutarConsultaAfectadas(
            'UPDATE departments SET company_id = ?, location_id = ?, name = ?, code = ?, is_active = ? WHERE id = ?',
            [
                (int) $data['company_id'], $data['location_id'] ?? null, $data['name'],
                $data['code'] ?? null, (int) ($data['is_active'] ?? 1), $id,
            ]
        );
    }

    // ------------------------------ Roles ------------------------------
    public static function roles(): array
    {
        return self::obtenerFilas(
            'SELECT r.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS permission_count
               FROM roles r ORDER BY r.level DESC'
        );
    }

    public static function permissions(): array
    {
        return self::obtenerFilas('SELECT * FROM permissions ORDER BY group_name, code');
    }

    /** @return array<int,string> */
    public static function rolePermissionCodes(int $roleId): array
    {
        return array_column(self::obtenerFilas(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
            [$roleId]
        ), 'code');
    }

    /** @param array<int,string> $codes */
    public static function setRolePermissions(int $roleId, array $codes): void
    {
        self::transaccion(function () use ($roleId, $codes): void {
            self::ejecutarConsultaAfectadas('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
            foreach (array_unique($codes) as $code) {
                $permissionId = self::obtenerValor('SELECT id FROM permissions WHERE code = ?', [$code]);
                if ($permissionId !== null) {
                    self::ejecutarConsultaAfectadas('INSERT INTO role_permissions (role_id, permission_id) VALUES (?,?)',
                        [$roleId, (int) $permissionId]);
                }
            }
        });
    }

    public static function findRole(int $id): ?array
    {
        return self::obtenerFila('SELECT * FROM roles WHERE id = ?', [$id]);
    }

    public static function createRole(array $data): int
    {
        return self::ejecutarInsert(
            'INSERT INTO roles (code, name, description, level, is_system, requires_mfa) VALUES (?,?,?,?,0,?)',
            [
                strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', (string) $data['code']) ?? ''),
                $data['name'], $data['description'] ?? null,
                (int) ($data['level'] ?? 10), (int) ($data['requires_mfa'] ?? 0),
            ]
        );
    }

    public static function updateRole(int $id, array $data): void
    {
        self::ejecutarConsultaAfectadas(
            'UPDATE roles SET name = ?, description = ?, level = ?, requires_mfa = ?, is_active = ? WHERE id = ?',
            [
                $data['name'], $data['description'] ?? null, (int) ($data['level'] ?? 10),
                (int) ($data['requires_mfa'] ?? 0), (int) ($data['is_active'] ?? 1), $id,
            ]
        );
    }
}
