<?php
declare(strict_types=1);

use App\Core\Config;

if (!function_exists('e')) {
    /**
     * Escape para HTML. TODA salida de datos en las vistas pasa por aqui.
     * Es la barrera principal contra XSS almacenado y reflejado.
     */
    function e(mixed $value): string
    {
        if ($value === null || is_bool($value) || is_array($value)) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        return ((string) Config::get('app.base_path', '')) . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * Ruta publica de un asset.
     *
     * Viven en app/views/{css,js,img}, como en Porcify Manager. La regla de
     * reescritura no toca las rutas con extension, de modo que el servidor
     * los entrega directamente.
     */
    function asset(string $path): string
    {
        return url('app/views/' . ltrim($path, '/'));
    }
}

if (!function_exists('puede')) {
    /**
     * Permisos del usuario de la peticion, para las vistas.
     *
     * Ocultar un boton NO es el control de acceso: el controlador y el
     * modelo vuelven a comprobar el permiso. Esto solo evita ofrecer lo
     * que la persona no va a poder hacer.
     */
    function puede(string $permiso): bool
    {
        return \app\models\contextoModel::puede($permiso);
    }
}

if (!function_exists('puedeAlguno')) {
    function puedeAlguno(string ...$permisos): bool
    {
        return \app\models\contextoModel::puedeAlguno(...$permisos);
    }
}

if (!function_exists('usuarioActual')) {
    /** @return array<string,mixed> */
    function usuarioActual(): array
    {
        return \app\models\contextoModel::usuario() ?? [];
    }
}

if (!function_exists('respuestaVista')) {
    /**
     * Extrae los datos de la respuesta de un controlador para una vista.
     *
     * Los controladores devuelven el sobre {code,status,title,message,data}
     * que consumen los endpoints JSON. Una vista solo quiere los datos, y
     * un fallo debe interrumpirla: se convierte de nuevo en excepcion para
     * que index.php dibuje la pagina de error con el codigo correcto.
     *
     * @param array<string,mixed> $respuesta
     */
    function respuestaVista(array $respuesta): mixed
    {
        if (($respuesta['status'] ?? '') === 'success') {
            return $respuesta['data'];
        }
        throw new \App\Core\HttpException(
            (int) ($respuesta['code'] ?? 500),
            (string) ($respuesta['message'] ?? 'No fue posible completar la operacion.')
        );
    }
}

if (!function_exists('tiposDeRecurso')) {
    /**
     * Tipos de recurso admitidos para un sistema.
     *
     * @return array<int,string>
     */
    function tiposDeRecurso(): array
    {
        return ['web', 'application', 'software', 'computer', 'server', 'email', 'network',
                'cloud', 'social', 'banking', 'license', 'database', 'other'];
    }
}

if (!function_exists('redirigir')) {
    /**
     * Envia al navegador a otra direccion del sistema y termina.
     *
     * Solo admite rutas internas: una redireccion abierta serviria para
     * llevar a un usuario autenticado a un sitio ajeno con aspecto propio.
     */
    function redirigir(string $ruta): never
    {
        $limpia = str_replace(["\r", "\n", "\0"], '', $ruta);
        if (!str_starts_with($limpia, '/') || str_starts_with($limpia, '//')) {
            $limpia = '/';
        }
        header('Location: ' . url($limpia), true, 302);
        exit;
    }
}

if (!function_exists('assetVersionado')) {
    /**
     * URL de un recurso propio con la marca de tiempo del archivo.
     *
     * Sin esto el navegador puede seguir usando una version antigua de un
     * CSS o un JS despues de un despliegue. Al cambiar la URL en cada
     * cambio del archivo, esta obligado a pedirlo de nuevo.
     */
    function assetVersionado(string $path): string
    {
        $relativa = ltrim($path, '/');
        $archivo  = \App\Core\Config::get('paths.views') . '/' . $relativa;
        $version  = is_file($archivo) ? (string) filemtime($archivo) : '1';
        return asset($relativa) . '?v=' . $version;
    }
}

if (!function_exists('active')) {
    /** Marca el elemento de navegacion correspondiente a la ruta actual. */
    function active(string $prefix, string $current, string $class = 'is-active'): string
    {
        if ($prefix === '/') {
            return $current === '/' ? $class : '';
        }
        return str_starts_with($current, $prefix) ? $class : '';
    }
}

if (!function_exists('fecha')) {
    function fecha(mixed $value, bool $withTime = false): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return '—';
        }
        return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
    }
}

if (!function_exists('desde')) {
    /** Tiempo relativo legible ("hace 5 minutos"). */
    function desde(mixed $value): string
    {
        $timestamp = $value === null ? false : strtotime((string) $value);
        if ($timestamp === false) {
            return '—';
        }
        $diff = time() - $timestamp;
        if ($diff < 0)      { return 'en el futuro'; }
        if ($diff < 60)     { return 'hace instantes'; }
        if ($diff < 3600)   { return 'hace ' . (int) ($diff / 60) . ' min'; }
        if ($diff < 86400)  { return 'hace ' . (int) ($diff / 3600) . ' h'; }
        if ($diff < 2592000){ return 'hace ' . (int) ($diff / 86400) . ' d'; }
        return date('d/m/Y', $timestamp);
    }
}

if (!function_exists('estadoCredencial')) {
    /** @return array{label:string,class:string} */
    function estadoCredencial(string $status): array
    {
        return match ($status) {
            'active'   => ['label' => 'Activa',    'class' => 'ok'],
            'inactive' => ['label' => 'Inactiva',  'class' => 'muted'],
            'expired'  => ['label' => 'Vencida',   'class' => 'danger'],
            'revoked'  => ['label' => 'Revocada',  'class' => 'danger'],
            'archived' => ['label' => 'Archivada', 'class' => 'muted'],
            default    => ['label' => $status,     'class' => 'muted'],
        };
    }
}

if (!function_exists('estadoRotacion')) {
    /** @return array{label:string,class:string} */
    function estadoRotacion(string $state): array
    {
        return match ($state) {
            'expired'       => ['label' => 'Vencida',            'class' => 'danger'],
            'rotation_due'  => ['label' => 'Rotacion pendiente', 'class' => 'danger'],
            'rotation_soon' => ['label' => 'Rota pronto',        'class' => 'warn'],
            'never_rotated' => ['label' => 'Nunca rotada',       'class' => 'warn'],
            default         => ['label' => 'Al dia',             'class' => 'ok'],
        };
    }
}

if (!function_exists('tipoRecurso')) {
    function tipoRecurso(?string $type): string
    {
        return match ($type) {
            'web'         => 'Sitio web',
            'application' => 'Aplicacion',
            'software'    => 'Software',
            'computer'    => 'Computador',
            'server'      => 'Servidor',
            'email'       => 'Correo',
            'network'     => 'Red',
            'cloud'       => 'Nube',
            'social'      => 'Red social',
            'banking'     => 'Banca',
            'license'     => 'Licencia',
            'database'    => 'Base de datos',
            default       => 'Otro',
        };
    }
}

if (!function_exists('accionAuditoria')) {
    function accionAuditoria(string $action): string
    {
        static $map = [
            'auth.login'                     => 'Inicio de sesion',
            'auth.logout'                    => 'Cierre de sesion',
            'auth.login_failed'              => 'Intento fallido',
            'auth.login_blocked'             => 'Acceso bloqueado',
            'auth.mfa_challenge'             => 'Solicitud de MFA',
            'auth.mfa_failed'                => 'MFA incorrecto',
            'auth.mfa_enabled'               => 'MFA activado',
            'auth.mfa_disabled'              => 'MFA desactivado',
            'auth.reauth'                    => 'Reautenticacion',
            'auth.reauth_failed'             => 'Reautenticacion fallida',
            'auth.password_changed'          => 'Cambio de contrasena propia',
            'auth.password_reset_requested'  => 'Solicitud de recuperacion',
            'auth.password_reset_completed'  => 'Recuperacion completada',
            'user.created'                   => 'Usuario creado',
            'user.updated'                   => 'Usuario modificado',
            'user.deactivated'               => 'Usuario desactivado',
            'user.reactivated'               => 'Usuario reactivado',
            'user.roles_changed'             => 'Cambio de roles',
            'user.permissions_changed'       => 'Cambio de permisos',
            'user.password_reset'            => 'Contrasena restablecida',
            'system.created'                 => 'Sistema creado',
            'system.updated'                 => 'Sistema modificado',
            'system.deleted'                 => 'Sistema archivado',
            'credential.created'             => 'Credencial creada',
            'credential.updated'             => 'Credencial modificada',
            'credential.viewed'              => 'Consulta de credencial',
            'credential.password_rotated'    => 'Cambio de contrasena',
            'credential.deleted'             => 'Credencial dada de baja',
            'credential.restored'            => 'Credencial reactivada',
            'credential.assigned'            => 'Acceso asignado',
            'credential.revoked'             => 'Acceso revocado',
            'secret.viewed'                  => 'Contrasena visualizada',
            'secret.copied'                  => 'Contrasena copiada',
            'secret.history_viewed'          => 'Contrasena historica vista',
            'recovery.viewed'                => 'Datos de recuperacion vistos',
            'export.generated'               => 'Reporte generado',
            'export.downloaded'              => 'Reporte descargado',
            'export.denied'                  => 'Exportacion denegada',
            'import.executed'                => 'Importacion ejecutada',
            'session.revoked'                => 'Sesion cerrada remotamente',
            'settings.updated'               => 'Configuracion modificada',
            'category.managed'               => 'Categoria gestionada',
            'org.managed'                    => 'Estructura organizacional',
            'role.managed'                   => 'Rol gestionado',
            'security.access_denied'         => 'Acceso denegado',
            'security.csrf_failed'           => 'Token CSRF invalido',
            'security.csrf_origin_failed'    => 'Origen no valido',
        ];
        return $map[$action] ?? $action;
    }
}

if (!function_exists('iniciales')) {
    function iniciales(?string $first, ?string $last): string
    {
        return strtoupper(mb_substr((string) $first, 0, 1) . mb_substr((string) $last, 0, 1)) ?: '?';
    }
}

if (!function_exists('queryString')) {
    /** Reconstruye la cadena de consulta cambiando algunos parametros. */
    function queryString(array $overrides = [], array $base = []): string
    {
        $params = array_merge($base, $overrides);
        $params = array_filter($params, static fn ($v) => $v !== null && $v !== '' && $v !== []);
        return $params === [] ? '' : '?' . http_build_query($params);
    }
}
