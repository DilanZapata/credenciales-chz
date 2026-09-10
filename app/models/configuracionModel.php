<?php
declare(strict_types=1);

namespace app\models;

/**
 * Parametros administrables del sistema (tabla `settings`).
 *
 * Se cachean por peticion: una pantalla consulta la misma politica muchas
 * veces y no tiene sentido volver a la base cada vez.
 */
class configuracionModel extends mainModel
{
    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /** @return array<string,mixed> */
    public static function todos(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $items = [];
        foreach (self::obtenerFilas('SELECT setting_key, setting_value, value_type FROM settings') as $fila) {
            $items[$fila['setting_key']] = self::convertir($fila['setting_value'], (string) $fila['value_type']);
        }
        self::$cache = $items;
        return $items;
    }

    public static function obtener(string $clave, mixed $porDefecto = null): mixed
    {
        $todos = self::todos();
        return array_key_exists($clave, $todos) && $todos[$clave] !== null ? $todos[$clave] : $porDefecto;
    }

    public static function entero(string $clave, int $porDefecto = 0): int
    {
        $v = self::obtener($clave, $porDefecto);
        return is_numeric($v) ? (int) $v : $porDefecto;
    }

    public static function booleano(string $clave, bool $porDefecto = false): bool
    {
        return (bool) self::obtener($clave, $porDefecto);
    }

    /** @return array<int,array<string,mixed>> */
    public static function agrupados(): array
    {
        return self::obtenerFilas(
            'SELECT setting_key, setting_value, value_type, group_name, label, description
               FROM settings ORDER BY group_name, setting_key'
        );
    }

    public static function fijar(string $clave, string $valor, ?int $idUsuario = null): void
    {
        self::ejecutarConsultaAfectadas(
            'UPDATE settings SET setting_value = ?, updated_by = ?, updated_at = NOW() WHERE setting_key = ?',
            [$valor, $idUsuario, $clave]
        );
        self::$cache = null;
    }

    /** Invalida la cache: necesario cuando otra peticion cambio un parametro. */
    public static function limpiarCache(): void
    {
        self::$cache = null;
    }

    private static function convertir(?string $valor, string $tipo): mixed
    {
        if ($valor === null) {
            return null;
        }
        return match ($tipo) {
            'int'   => (int) $valor,
            'bool'  => in_array(strtolower($valor), ['1', 'true', 'on', 'yes'], true),
            'json'  => json_decode($valor, true),
            default => $valor,
        };
    }
}
