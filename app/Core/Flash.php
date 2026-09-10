<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Mensajes efimeros entre peticiones.
 *
 * Se apoyan en una cookie firmada de vida muy corta en lugar de la sesion
 * nativa de PHP (que esta deshabilitada). El contenido es texto de interfaz;
 * nunca se colocan secretos aqui.
 *
 * El flujo es siempre el mismo: quien escribe llama a set() y guarda con
 * guardarCookie() antes de redirigir; quien dibuja la pagina llama a
 * cargarDeCookie() y expirarCookie() para que el aviso no se repita.
 */
final class Flash
{
    private const COOKIE = 'scgca_flash';
    /** @var array<string,mixed> */
    private static array $data    = [];
    private static array $pending = [];
    private static bool $loaded   = false;


    /**
     * Carga los mensajes directamente de $_COOKIE.
     *
     * En la arquitectura de Porcify la peticion son las superglobales: no
     * hay objeto Request del que sacarla.
     */
    public static function cargarDeCookie(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $raw = $_COOKIE[self::COOKIE] ?? '';
        if (!is_string($raw) || $raw === '') {
            return;
        }
        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) {
            return;
        }
        [$payload, $signature] = $parts;
        if (!hash_equals(hash_hmac('sha256', $payload, self::key()), $signature)) {
            return;
        }
        $decoded = json_decode((string) base64_decode($payload, true), true);
        if (is_array($decoded)) {
            self::$data = $decoded;
        }
    }

    /**
     * Retira la cookie una vez consumidos los mensajes.
     *
     * Sin esto el mismo aviso reaparece en cada recarga.
     */
    public static function expirarCookie(): void
    {
        if (self::$data === [] || headers_sent()) {
            return;
        }
        $base = (string) Config::get('app.base_path', '');
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => ($base === '' ? '/' : $base . '/'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public static function set(string $key, mixed $value): void
    {
        self::$pending[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset(self::$data[$key]);
    }

    /**
     * Escribe la cookie con los mensajes pendientes.
     *
     * La usan el despacho de formularios y la entrega de archivos, que
     * redirigen con header() directamente.
     */
    public static function guardarCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        $base = (string) Config::get('app.base_path', '');
        $ruta = $base === '' ? '/' : $base . '/';

        if (self::$pending === []) {
            self::expirarCookie();
            return;
        }
        $payload   = base64_encode((string) json_encode(self::$pending, JSON_UNESCAPED_UNICODE));
        $signature = hash_hmac('sha256', $payload, self::key());
        setcookie(self::COOKIE, $payload . '.' . $signature, [
            'expires'  => time() + 120,
            'path'     => $ruta,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }


    private static function key(): string
    {
        $master = (string) Env::get('APP_MASTER_KEY', 'flash-fallback-key');
        return hash_hmac('sha256', 'SCGCA:FLASH', $master);
    }
}
