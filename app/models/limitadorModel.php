<?php
declare(strict_types=1);

namespace app\models;


/**
 * Limitador de frecuencia por ventana fija, persistido en base de datos
 * (funciona con multiples procesos de Apache/PHP-FPM, a diferencia de
 * una solucion en memoria por proceso).
 *
 * Se aplica a: login, reautenticacion, revelado de secretos, exportacion,
 * busqueda y API en general.
 */
class limitadorModel extends mainModel
{
    /**
     * Consume un intento del cubo. Devuelve false si se agoto la cuota.
     */
    public static function intentar(string $cubo, int $maxIntentos, int $segundosVentana): bool
    {
        $clave = substr(hash('sha256', $cubo), 0, 64);
        return self::transaccion(static function () use ($clave, $maxIntentos, $segundosVentana): bool {
            $fila = self::obtenerFila(
                'SELECT hits, window_start, expires_at FROM rate_limits WHERE bucket = ? FOR UPDATE',
                [$clave]
            );
            $ahora = time();
            if ($fila === null || strtotime((string) $fila['expires_at']) <= $ahora) {
                self::ejecutarConsultaAfectadas(
                    'REPLACE INTO rate_limits (bucket, hits, window_start, expires_at) VALUES (?, 1, ?, ?)',
                    [$clave, date('Y-m-d H:i:s', $ahora), date('Y-m-d H:i:s', $ahora + $segundosVentana)]
                );
                return true;
            }
            $golpes = (int) $fila['hits'];
            if ($golpes >= $maxIntentos) {
                return false;
            }
            self::ejecutarConsultaAfectadas('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$clave]);
            return true;
        });
    }

    public static function restantes(string $cubo, int $maxIntentos): int
    {
        $clave = substr(hash('sha256', $cubo), 0, 64);
        $fila = self::obtenerFila('SELECT hits, expires_at FROM rate_limits WHERE bucket = ?', [$clave]);
        if ($fila === null || strtotime((string) $fila['expires_at']) <= time()) {
            return $maxIntentos;
        }
        return max(0, $maxIntentos - (int) $fila['hits']);
    }

    public static function reintentarEn(string $cubo): int
    {
        $clave = substr(hash('sha256', $cubo), 0, 64);
        $fila = self::obtenerFila('SELECT expires_at FROM rate_limits WHERE bucket = ?', [$clave]);
        if ($fila === null) {
            return 0;
        }
        return max(0, strtotime((string) $fila['expires_at']) - time());
    }

    public static function limpiar(string $cubo): void
    {
        self::ejecutarConsultaAfectadas('DELETE FROM rate_limits WHERE bucket = ?', [substr(hash('sha256', $cubo), 0, 64)]);
    }

    public static function purgarVencidos(): int
    {
        return self::ejecutarConsultaAfectadas('DELETE FROM rate_limits WHERE expires_at < NOW()');
    }
}
