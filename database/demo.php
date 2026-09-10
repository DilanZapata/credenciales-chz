<?php
declare(strict_types=1);

/**
 * Datos de ejemplo para pruebas y demostracion.
 * NO EJECUTAR EN PRODUCCION: crea usuarios y credenciales ficticias.
 *
 * Se invoca desde: php bin/console.php seed:demo
 */

use app\models\cifradoModel;
use app\models\generadorModel;
use app\models\mainModel;

$crypto    = $container->get(CryptoService::class);
$generator = $container->get(PasswordGeneratorService::class);

echo PHP_EOL . "Cargando datos de demostracion..." . PHP_EOL;

$companyId = (int) (mainModel::obtenerValor('SELECT id FROM companies ORDER BY id LIMIT 1')
    ?? mainModel::ejecutarInsert('INSERT INTO companies (name) VALUES (?)', ['Mi Empresa']));

$locations = [];
foreach ([['Bogota', 'Bogota D.C.'], ['Medellin', 'Medellin']] as [$name, $city]) {
    $existing = mainModel::obtenerValor('SELECT id FROM locations WHERE company_id = ? AND name = ?', [$companyId, $name]);
    $locations[$name] = (int) ($existing ?? mainModel::ejecutarInsert(
        'INSERT INTO locations (company_id, name, city, country) VALUES (?,?,?,?)',
        [$companyId, $name, $city, 'Colombia']
    ));
}

$departments = [];
foreach (['Contabilidad', 'Tecnologia', 'Compras', 'Recursos Humanos'] as $name) {
    $existing = mainModel::obtenerValor('SELECT id FROM departments WHERE company_id = ? AND name = ?', [$companyId, $name]);
    $departments[$name] = (int) ($existing ?? mainModel::ejecutarInsert(
        'INSERT INTO departments (company_id, location_id, name) VALUES (?,?,?)',
        [$companyId, $locations['Bogota'], $name]
    ));
}

$categoryId = static function (string $slug) use ($db): ?int {
    $id = mainModel::obtenerValor('SELECT id FROM categories WHERE slug = ?', [$slug]);
    return $id === null ? null : (int) $id;
};

// ------------------------------- Usuarios ----------------------------
$roles = [];
foreach (mainModel::obtenerFilas('SELECT id, code FROM roles') as $row) {
    $roles[(string) $row['code']] = (int) $row['id'];
}

$demoUsers = [
    ['123456789', 'juan.perez',   'juan.perez@empresa.local',   'Juan',   'Perez',    'CONSULTOR', 'Auxiliar contable',  'Contabilidad'],
    ['987654321', 'maria.gomez',  'maria.gomez@empresa.local',  'Maria',  'Gomez',    'ADMIN',     'Coordinadora de TI', 'Tecnologia'],
    ['456789123', 'carlos.ruiz',  'carlos.ruiz@empresa.local',  'Carlos', 'Ruiz',     'AUDITOR',   'Auditor interno',    'Recursos Humanos'],
    ['321654987', 'ana.torres',   'ana.torres@empresa.local',   'Ana',    'Torres',   'CONSULTOR', 'Analista de compras','Compras'],
];

$createdUsers = [];
foreach ($demoUsers as [$nid, $username, $email, $first, $last, $roleCode, $position, $department]) {
    $existing = mainModel::obtenerValor('SELECT id FROM users WHERE national_id = ?', [$nid]);
    if ($existing !== null) {
        $createdUsers[$username] = (int) $existing;
        continue;
    }
    $password = generadorModel::generate(['length' => 16, 'exclude_ambiguous' => true]);
    $hash     = cifradoModel::hashContrasena($password);
    $userId   = mainModel::ejecutarInsert(
        'INSERT INTO users (national_id, username, email, first_name, last_name, position, company_id,
                            location_id, department_id, password_hash, password_algo, password_changed_at,
                            must_change_password, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),0,"active")',
        [$nid, $username, $email, $first, $last, $position, $companyId,
         $locations['Bogota'], $departments[$department], $hash['hash'], $hash['algo']]
    );
    mainModel::ejecutarConsultaAfectadas('INSERT INTO user_roles (user_id, role_id) VALUES (?,?)', [$userId, $roles[$roleCode]]);
    $createdUsers[$username] = $userId;
    echo sprintf("  Usuario %-14s (%s)  contrasena: %s\n", $username, $roleCode, $password);
}

// ------------------------------- Sistemas ----------------------------
$systems = [
    ['Sistema Contable',        'application', 'sistemas',        'https://contable.empresa.local', null, null, 'SAP Business One', 'Contabilidad', 'high'],
    ['Correo Corporativo',      'email',       'correos',         'https://mail.empresa.local',     null, null, 'Microsoft 365',    'Tecnologia',   'critical'],
    ['Sistema de Inventarios',  'application', 'sistemas',        'https://inventario.empresa.local', null, null, 'Odoo',           'Compras',      'medium'],
    ['Plataforma de Nomina',    'cloud',       'servicios-nube',  'https://nomina.proveedor.com',   null, null, 'Proveedor Nomina', 'Recursos Humanos', 'high'],
    ['Servidor de Aplicaciones','server',      'servidores',      null, '10.0.1.15', 22, 'Ubuntu Server 22.04', 'Tecnologia', 'critical'],
    ['Red Wi-Fi Corporativa',   'network',     'redes',           null, '10.0.0.1',  null, 'Ubiquiti UniFi',    'Tecnologia', 'high'],
    ['Portal Bancario',         'banking',     'bancos',          'https://empresas.banco.local',   null, null, 'Banca empresarial', 'Contabilidad', 'critical'],
    ['LinkedIn Corporativo',    'social',      'redes-sociales',  'https://www.linkedin.com',       null, null, 'LinkedIn',          'Recursos Humanos', 'low'],
];

$systemIds = [];
foreach ($systems as [$name, $type, $catSlug, $url, $ip, $port, $platform, $department, $criticality]) {
    $existing = mainModel::obtenerValor('SELECT id FROM systems WHERE name = ?', [$name]);
    if ($existing !== null) {
        $systemIds[$name] = (int) $existing;
        continue;
    }
    $systemIds[$name] = mainModel::ejecutarInsert(
        'INSERT INTO systems (name, description, category_id, resource_type, company_id, location_id,
                              department_id, url, ip_address, port, platform, owner_user_id, criticality, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,"active")',
        [$name, 'Sistema de demostracion: ' . $name, $categoryId($catSlug), $type, $companyId,
         $locations['Bogota'], $departments[$department] ?? null, $url, $ip, $port, $platform,
         $createdUsers['maria.gomez'] ?? null, $criticality]
    );
}

// ----------------------------- Credenciales --------------------------
$credentials = [
    ['Sistema Contable',        'Usuario de contabilidad',   'contabilidad',  'contabilidad@empresa.local',  90],
    ['Correo Corporativo',      'Buzon de Juan Perez',       'juan.perez',    'juan.perez@empresa.local',    180],
    ['Sistema de Inventarios',  'Operador de inventarios',   'inventarios',   'inventarios@empresa.local',   90],
    ['Plataforma de Nomina',    'Administrador de nomina',   'admin_nomina',  'nomina@empresa.local',        60],
    ['Servidor de Aplicaciones','Acceso SSH de mantenimiento','deploy',       null,                          30],
    ['Red Wi-Fi Corporativa',   'Clave de la red corporativa','EmpresaWiFi',  null,                          180],
    ['Portal Bancario',         'Usuario de tesoreria',      'tesoreria',     'tesoreria@empresa.local',     30],
    ['LinkedIn Corporativo',    'Cuenta institucional',      'empresa',       'marketing@empresa.local',     365],
];

$credentialIds = [];
foreach ($credentials as [$systemName, $name, $username, $email, $rotation]) {
    $systemId = $systemIds[$systemName];
    $existing = mainModel::obtenerValor('SELECT id FROM credentials WHERE system_id = ? AND name = ?', [$systemId, $name]);
    if ($existing !== null) {
        $credentialIds[$name] = (int) $existing;
        continue;
    }
    $credentialId = mainModel::ejecutarInsert(
        'INSERT INTO credentials (system_id, name, username, email, auth_method, recovery_email,
                                  observations, owner_user_id, status, rotation_period_days,
                                  next_rotation_at, password_changed_at, created_by)
         VALUES (?,?,?,?,?,?,?,?,"active",?, DATE_ADD(CURDATE(), INTERVAL ? DAY), NOW(), ?)',
        [$systemId, $name, $username, $email, 'Contrasena', 'recuperacion@empresa.local',
         'Credencial de demostracion.', $createdUsers['maria.gomez'] ?? null, $rotation, $rotation,
         $createdUsers['maria.gomez'] ?? null]
    );

    $secret   = generadorModel::generate(['length' => 22, 'exclude_ambiguous' => true]);
    $envelope = cifradoModel::cifrar($secret, cifradoModel::aad('credential', $credentialId, 'password', 1));
    mainModel::ejecutarConsultaAfectadas(
        'INSERT INTO credential_secrets (credential_id, field, version, is_current, algo, key_version,
                                         ciphertext, nonce, tag, wrapped_dek, dek_nonce, dek_tag,
                                         secret_length, strength_score, fingerprint, change_reason, created_by)
         VALUES (?,"password",1,1,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$credentialId, $envelope['algo'], $envelope['key_version'], $envelope['ciphertext'],
         $envelope['nonce'], $envelope['tag'], $envelope['wrapped_dek'], $envelope['dek_nonce'],
         $envelope['dek_tag'], strlen($secret), generadorModel::strength($secret),
         cifradoModel::huella($secret), 'Registro inicial de demostracion',
         $createdUsers['maria.gomez'] ?? null]
    );
    mainModel::ejecutarConsultaAfectadas(
        'INSERT INTO credential_history (credential_id, action, reason, performed_by)
         VALUES (?, "created", "Alta de la credencial (demo)", ?)',
        [$credentialId, $createdUsers['maria.gomez'] ?? null]
    );
    $credentialIds[$name] = $credentialId;
}

// --------------------------- Asignaciones ----------------------------
$assignments = [
    'juan.perez' => ['Usuario de contabilidad', 'Buzon de Juan Perez', 'Operador de inventarios'],
    'ana.torres' => ['Operador de inventarios'],
];
foreach ($assignments as $username => $names) {
    if (!isset($createdUsers[$username])) { continue; }
    foreach ($names as $name) {
        if (!isset($credentialIds[$name])) { continue; }
        mainModel::ejecutarConsultaAfectadas(
            'INSERT IGNORE INTO credential_assignments
               (credential_id, user_id, can_view_secret, can_copy_secret, can_view_recovery, granted_by)
             VALUES (?,?,1,1,0,?)',
            [$credentialIds[$name], $createdUsers[$username], $createdUsers['maria.gomez'] ?? null]
        );
    }
}

// Una credencial deliberadamente vencida para ejercitar las alertas.
if (isset($credentialIds['Cuenta institucional'])) {
    mainModel::ejecutarConsultaAfectadas(
        'UPDATE credentials SET expires_at = DATE_SUB(CURDATE(), INTERVAL 10 DAY),
                                next_rotation_at = DATE_SUB(CURDATE(), INTERVAL 5 DAY)
          WHERE id = ?',
        [$credentialIds['Cuenta institucional']]
    );
}

echo PHP_EOL . "Datos de demostracion cargados." . PHP_EOL;
echo "  " . count($systemIds) . " sistemas, " . count($credentialIds) . " credenciales." . PHP_EOL;
