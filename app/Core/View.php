<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Motor de plantillas basado en PHP plano.
 *
 * index.php compone la pagina incluyendo los archivos de app/views/inc y
 * la vista; esta clase queda para los fragmentos reutilizables que se
 * dibujan desde dentro de una vista (avisos, paginacion) y para compartir
 * datos entre todos ellos.
 *
 * Regla de la capa de presentacion: TODA interpolacion de datos pasa por
 * e() (htmlspecialchars con ENT_QUOTES). Nunca se imprime entrada de usuario
 * sin escapar (mitigacion de XSS reflejado y almacenado).
 */
final class View
{
    private static string $path = '';
    /** @var array<string,mixed> */
    private static array $shared = [];

    public static function setPath(string $path): void
    {
        self::$path = rtrim($path, '/');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @return array<string,mixed> */
    public static function sharedData(): array
    {
        return self::$shared;
    }

    public static function render(string $template, array $data = []): string
    {
        $file = self::$path . '/' . str_replace(['..', '\\'], '', $template) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Plantilla no encontrada: ' . $template);
        }
        $vars = array_merge(self::$shared, $data);
        extract($vars, EXTR_SKIP);
        ob_start();
        /** @psalm-suppress UnresolvableInclude */
        require $file;
        return (string) ob_get_clean();
    }
}
