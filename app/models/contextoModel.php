<?php
declare(strict_types=1);

namespace app\models;

/**
 * Contexto de seguridad de la peticion en curso: quien actua, con que
 * sesion, desde donde y con que permisos efectivos.
 *
 * En Porcify Manager este papel lo cumple `mainModel::obtenerUsuarioDesdeToken()`
 * resolviendo el usuario en cada llamada. Aqui se resuelve una sola vez por
 * peticion y se conserva, para no repetir la consulta en cada comprobacion.
 *
 * Es la unica fuente de verdad de autorizacion del backend. Los endpoints y
 * los modelos preguntan aqui; nunca al cliente.
 */
class contextoModel
{
    /** @var array<string,mixed>|null */
    private static ?array $usuario = null;
    /** @var array<string,mixed>|null */
    private static ?array $sesion = null;
    /** @var array<string,bool> */
    private static array $permisos = [];
    /** @var array<int,array<string,mixed>> */
    private static array $roles = [];

    private static string $ip = '0.0.0.0';
    private static string $agente = '';
    private static string $dispositivo = '';
    private static string $ruta = '';
    private static string $metodo = '';

    public static function fijarDatosPeticion(string $ip, string $agente, string $dispositivo, string $metodo, string $ruta): void
    {
        self::$ip          = $ip;
        self::$agente      = $agente;
        self::$dispositivo = $dispositivo;
        self::$metodo      = $metodo;
        self::$ruta        = $ruta;
    }

    /**
     * @param array<string,mixed> $usuario
     * @param array<string,mixed> $sesion
     * @param array<int,string>   $permisos
     * @param array<int,array<string,mixed>> $roles
     */
    public static function autenticar(array $usuario, array $sesion, array $permisos, array $roles): void
    {
        self::$usuario  = $usuario;
        self::$sesion   = $sesion;
        self::$roles    = $roles;
        self::$permisos = array_fill_keys($permisos, true);
    }

    public static function olvidar(): void
    {
        self::$usuario  = null;
        self::$sesion   = null;
        self::$permisos = [];
        self::$roles    = [];
    }

    public static function autenticado(): bool                 { return self::$usuario !== null; }
    /** @return array<string,mixed>|null */
    public static function usuario(): ?array                   { return self::$usuario; }
    public static function id(): ?int                          { return self::$usuario !== null ? (int) self::$usuario['id'] : null; }
    public static function cedula(): ?string                   { return self::$usuario['national_id'] ?? null; }
    /** @return array<string,mixed>|null */
    public static function sesion(): ?array                    { return self::$sesion; }
    public static function idSesion(): ?string                 { return self::$sesion['id'] ?? null; }
    public static function tokenCsrf(): ?string                { return self::$sesion['csrf_token'] ?? null; }
    /** @return array<int,array<string,mixed>> */
    public static function roles(): array                      { return self::$roles; }
    /** @return array<int,string> */
    public static function permisos(): array                   { return array_keys(self::$permisos); }
    public static function ip(): string                        { return self::$ip; }
    public static function agente(): string                    { return self::$agente; }
    public static function dispositivo(): string               { return self::$dispositivo; }
    public static function ruta(): string                      { return self::$ruta; }
    public static function metodo(): string                    { return self::$metodo; }

    public static function nombreCompleto(): string
    {
        if (self::$usuario === null) {
            return 'Sistema';
        }
        return trim((self::$usuario['first_name'] ?? '') . ' ' . (self::$usuario['last_name'] ?? ''));
    }

    /**
     * Comprueba un permiso efectivo.
     *
     * Equivale a `mainModel::validarPermisos()` de la referencia, pero sobre
     * los permisos ya resueltos en base de datos, no sobre los embebidos en
     * un token: revocar un permiso surte efecto en la peticion siguiente, sin
     * esperar a que caduque nada.
     */
    public static function puede(string $permiso): bool
    {
        return isset(self::$permisos[$permiso]);
    }

    public static function puedeAlguno(string ...$permisos): bool
    {
        foreach ($permisos as $permiso) {
            if (self::puede($permiso)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,string> */
    public static function codigosRol(): array
    {
        return array_map(static fn (array $r): string => (string) $r['code'], self::$roles);
    }

    public static function tieneRol(string $codigo): bool
    {
        return in_array($codigo, self::codigosRol(), true);
    }

    public static function esSuperadministrador(): bool
    {
        return self::tieneRol('SUPERADMIN');
    }

    /** Nivel de privilegio mas alto entre sus roles. */
    public static function nivel(): int
    {
        $max = 0;
        foreach (self::$roles as $rol) {
            $max = max($max, (int) $rol['level']);
        }
        return $max;
    }

    /** True si la reautenticacion (step-up) sigue vigente. */
    public static function reautenticadoHace(int $minutos): bool
    {
        $marca = self::$sesion['reauth_at'] ?? null;
        if ($marca === null) {
            return false;
        }
        return strtotime((string) $marca) >= (time() - ($minutos * 60));
    }

    public static function marcarReautenticado(string $marca): void
    {
        if (self::$sesion !== null) {
            self::$sesion['reauth_at'] = $marca;
        }
    }
}
