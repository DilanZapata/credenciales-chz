<?php
declare(strict_types=1);

namespace app\models;

use App\Core\HttpException;

/**
 * Modelo de dominio (patron de Porcify Manager: la logica de negocio vive
 * en el modelo, junto al acceso a datos).
 */
class importacionModel extends mainModel
{

    // =================================================================
    //  Logica de negocio (fusionada desde ImportService.php)
    // =================================================================

    private const COLUMNS = [
        'sistema', 'categoria', 'tipo_recurso', 'url', 'ip', 'puerto', 'servidor', 'plataforma',
        'proveedor', 'empresa', 'sede', 'departamento', 'credencial', 'usuario', 'correo',
        'contrasena', 'dominio', 'usuario_admin', 'metodo_autenticacion', 'correo_recuperacion',
        'telefono_recuperacion', 'usuario_recuperacion', 'responsable_cedula', 'vencimiento',
        'rotacion_dias', 'observaciones',
    ];

    private const REQUIRED = ['sistema', 'credencial', 'contrasena'];


    /** @return array<int,string> */
    public static function templateColumns(): array
    {
        return self::COLUMNS;
    }

    /**
     * Lee y valida un CSV sin escribir nada. Devuelve el informe completo.
     *
     * @return array{rows:array<int,array<string,mixed>>,valid:int,invalid:int,duplicates:int,errors:array<int,string>}
     */
    public static function preview(string $csvContent): array
    {
        permisoModel::exigir('import.credentials');

        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];
        if (count($lines) < 2) {
            throw HttpException::badRequest('El archivo no contiene datos.');
        }
        if (count($lines) > 2001) {
            throw HttpException::badRequest('El archivo supera el maximo de 2000 filas por importacion.');
        }

        $delimiter = self::detectDelimiter($lines[0]);
        $header    = array_map(
            static fn (string $h): string => strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $h) ?? '')),
            str_getcsv($lines[0], $delimiter)
        );

        $missing = array_diff(self::REQUIRED, $header);
        if ($missing !== []) {
            throw HttpException::badRequest('Faltan columnas obligatorias: ' . implode(', ', $missing));
        }

        $rows       = [];
        $errors     = [];
        $valid      = 0;
        $invalid    = 0;
        $duplicates = 0;
        $seen       = [];

        for ($i = 1, $total = count($lines); $i < $total; $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            $values = str_getcsv($line, $delimiter);
            $row    = [];
            foreach ($header as $index => $column) {
                $row[$column] = isset($values[$index]) ? trim((string) $values[$index]) : '';
            }

            $rowErrors = self::validateRow($row);
            $key       = mb_strtolower($row['sistema'] . '|' . $row['credencial'] . '|' . ($row['usuario'] ?? ''));

            $isDuplicateInFile = isset($seen[$key]);
            $seen[$key]        = true;
            $existsInDb        = self::existsInDatabase($row);

            if ($isDuplicateInFile) {
                $rowErrors[] = 'Fila duplicada dentro del archivo.';
            }
            if ($existsInDb) {
                $rowErrors[] = 'Ya existe una credencial con ese sistema, nombre y usuario.';
            }

            $status = $rowErrors === [] ? 'ok' : (($isDuplicateInFile || $existsInDb) ? 'duplicate' : 'error');
            if ($status === 'ok') {
                $valid++;
            } elseif ($status === 'duplicate') {
                $duplicates++;
            } else {
                $invalid++;
                $errors[] = 'Fila ' . ($i + 1) . ': ' . implode(' ', $rowErrors);
            }

            // La contrasena NO viaja a la vista previa: solo su robustez.
            $preview = $row;
            $preview['contrasena']         = str_repeat('*', min(12, max(4, mb_strlen($row['contrasena']))));
            $preview['_line']              = $i + 1;
            $preview['_status']            = $status;
            $preview['_errors']            = $rowErrors;
            $preview['_password_length']   = mb_strlen($row['contrasena']);

            $rows[] = $preview;
        }

        return [
            'rows'       => $rows,
            'valid'      => $valid,
            'invalid'    => $invalid,
            'duplicates' => $duplicates,
            'errors'     => $errors,
            'delimiter'  => $delimiter,
        ];
    }

    /**
     * Ejecuta la importacion de las filas validas.
     *
     * @return array{created:int,skipped:int,errors:array<int,string>}
     */
    public static function execute(string $csvContent): array
    {
        permisoModel::exigir('import.credentials');
        permisoModel::exigir('credentials.create');
        permisoModel::exigirReautenticacion('secret');

        $lines     = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];
        $delimiter = self::detectDelimiter($lines[0] ?? '');
        $header    = array_map(
            static fn (string $h): string => strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $h) ?? '')),
            str_getcsv($lines[0] ?? '', $delimiter)
        );

        $created = 0;
        $skipped = 0;
        $errors  = [];
        $seen    = [];

        for ($i = 1, $total = count($lines); $i < $total; $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            $values = str_getcsv($line, $delimiter);
            $row    = [];
            foreach ($header as $index => $column) {
                $row[$column] = isset($values[$index]) ? trim((string) $values[$index]) : '';
            }

            $key = mb_strtolower(($row['sistema'] ?? '') . '|' . ($row['credencial'] ?? '') . '|' . ($row['usuario'] ?? ''));
            if (isset($seen[$key]) || self::validateRow($row) !== [] || self::existsInDatabase($row)) {
                $skipped++;
                continue;
            }
            $seen[$key] = true;

            try {
                $systemId = self::resolveSystem($row);
                credencialModel::crearRegistro([
                    'system_id'            => $systemId,
                    'name'                 => $row['credencial'],
                    'environment'          => 'production',
                    'username'             => $row['usuario'] ?: null,
                    'email'                => $row['correo'] ?: null,
                    'domain'               => $row['dominio'] ?? null ?: null,
                    'admin_username'       => $row['usuario_admin'] ?? null ?: null,
                    'auth_method'          => $row['metodo_autenticacion'] ?? null ?: null,
                    'recovery_email'       => $row['correo_recuperacion'] ?? null ?: null,
                    'recovery_phone'       => $row['telefono_recuperacion'] ?? null ?: null,
                    'recovery_username'    => $row['usuario_recuperacion'] ?? null ?: null,
                    'observations'         => $row['observaciones'] ?? null ?: null,
                    'owner_user_id'        => self::resolveOwner($row['responsable_cedula'] ?? ''),
                    'status'               => 'active',
                    'expires_at'           => self::normalizeDate($row['vencimiento'] ?? ''),
                    'rotation_period_days' => ctype_digit((string) ($row['rotacion_dias'] ?? '')) ? (int) $row['rotacion_dias'] : null,
                ], $row['contrasena']);
                $created++;
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = 'Fila ' . ($i + 1) . ': no se pudo importar.';
            }
        }

        auditoriaModel::registrar(auditoriaModel::IMPORT_EXECUTED, 'import', null, 'Importacion de credenciales',
            'success', ['creadas' => $created, 'omitidas' => $skipped], 'warning');

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** Plantilla CSV de ejemplo para el administrador. */
    public static function templateCsv(): string
    {
        $rows = [
            implode(',', self::COLUMNS),
            'Sistema Contable,Sistemas,application,https://contable.empresa.com,,,,SAP,Proveedor S.A.,'
            . 'Mi Empresa,Bogota,Contabilidad,Usuario contabilidad,contabilidad,contabilidad@empresa.com,'
            . 'CAMBIAR-ESTA-CLAVE,empresa.local,,password,recuperacion@empresa.com,3001234567,,123456789,2027-01-31,90,Ejemplo',
        ];
        return implode("\n", $rows) . "\n";
    }

    // -----------------------------------------------------------------

    /** @return array<int,string> */
    private static function validateRow(array $row): array
    {
        $errors = [];
        foreach (self::REQUIRED as $column) {
            if (($row[$column] ?? '') === '') {
                $errors[] = "La columna '{$column}' es obligatoria.";
            }
        }
        if (mb_strlen($row['credencial'] ?? '') > 180) {
            $errors[] = 'El nombre de la credencial es demasiado largo.';
        }
        if (mb_strlen($row['contrasena'] ?? '') > 1024) {
            $errors[] = 'La contrasena excede el maximo permitido.';
        }
        if (($row['correo'] ?? '') !== '' && filter_var($row['correo'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'El correo no es valido.';
        }
        if (($row['correo_recuperacion'] ?? '') !== '' && filter_var($row['correo_recuperacion'], FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'El correo de recuperacion no es valido.';
        }
        if (($row['url'] ?? '') !== '' && filter_var($row['url'], FILTER_VALIDATE_URL) === false) {
            $errors[] = 'La URL no es valida.';
        }
        if (($row['ip'] ?? '') !== '' && filter_var($row['ip'], FILTER_VALIDATE_IP) === false) {
            $errors[] = 'La direccion IP no es valida.';
        }
        if (($row['vencimiento'] ?? '') !== '' && self::normalizeDate($row['vencimiento']) === null) {
            $errors[] = 'La fecha de vencimiento debe tener formato AAAA-MM-DD.';
        }
        return $errors;
    }

    private static function existsInDatabase(array $row): bool
    {
        return (int) self::obtenerValor(
            'SELECT COUNT(*) FROM credentials c JOIN systems s ON s.id = c.system_id
              WHERE s.name = ? AND c.name = ? AND COALESCE(c.username, "") = ? AND c.deleted_at IS NULL',
            [$row['sistema'] ?? '', $row['credencial'] ?? '', $row['usuario'] ?? '']
        ) > 0;
    }

    private static function resolveSystem(array $row): int
    {
        $existing = self::obtenerFila('SELECT id FROM systems WHERE name = ? LIMIT 1', [$row['sistema']]);
        if ($existing !== null) {
            return (int) $existing['id'];
        }
        return sistemaModel::create([
            'name'          => $row['sistema'],
            'category_id'   => self::resolveCategory($row['categoria'] ?? ''),
            'resource_type' => self::normalizeResourceType($row['tipo_recurso'] ?? ''),
            'company_id'    => self::resolveByName('companies', $row['empresa'] ?? ''),
            'location_id'   => self::resolveByName('locations', $row['sede'] ?? ''),
            'department_id' => self::resolveByName('departments', $row['departamento'] ?? ''),
            'url'           => $row['url'] ?: null,
            'ip_address'    => $row['ip'] ?: null,
            'port'          => ctype_digit((string) ($row['puerto'] ?? '')) ? (int) $row['puerto'] : null,
            'hostname'      => $row['servidor'] ?: null,
            'platform'      => $row['plataforma'] ?: null,
            'provider'      => $row['proveedor'] ?: null,
            'status'        => 'active',
        ]);
    }

    private static function resolveCategory(string $name): ?int
    {
        if ($name === '') {
            return null;
        }
        $row = self::obtenerFila('SELECT id FROM categories WHERE name = ? OR slug = ? LIMIT 1',
            [$name, catalogoModel::slug($name)]);
        return $row !== null ? (int) $row['id'] : null;
    }

    private static function resolveByName(string $table, string $name): ?int
    {
        if ($name === '' || !in_array($table, ['companies', 'locations', 'departments'], true)) {
            return null;
        }
        $row = self::obtenerFila("SELECT id FROM {$table} WHERE name = ? LIMIT 1", [$name]);
        return $row !== null ? (int) $row['id'] : null;
    }

    private static function resolveOwner(string $nationalId): ?int
    {
        if ($nationalId === '') {
            return null;
        }
        $row = self::obtenerFila('SELECT id FROM users WHERE national_id = ? LIMIT 1', [$nationalId]);
        return $row !== null ? (int) $row['id'] : null;
    }

    private static function normalizeResourceType(string $value): string
    {
        $allowed = ['web', 'application', 'software', 'computer', 'server', 'email', 'network',
                    'cloud', 'social', 'banking', 'license', 'database', 'other'];
        $value   = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : 'other';
    }

    private static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function detectDelimiter(string $headerLine): string
    {
        $candidates = [',' => substr_count($headerLine, ','), ';' => substr_count($headerLine, ';'), "\t" => substr_count($headerLine, "\t")];
        arsort($candidates);
        return (string) array_key_first($candidates);
    }
}
