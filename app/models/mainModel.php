<?php
declare(strict_types=1);

namespace app\models;

use App\Core\Config;
use App\Core\Logger;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;
use mysqli_stmt;
use RuntimeException;
use Throwable;

/**
 * Clase base de acceso a datos (patron de Porcify Manager).
 *
 * Conserva la firma de la referencia —conectar(), ejecutarConsulta(),
 * ejecutarInsert(), ejecutarConsultaAfectadas(), limpiarDatos()— para que el
 * codigo se lea igual en los dos proyectos.
 *
 * DOS DIFERENCIAS DELIBERADAS, y son las que hacen viable esta arquitectura
 * en un sistema que guarda contrasenas:
 *
 *   1. TODA consulta con datos va por sentencia preparada. La referencia
 *      concatena las variables en la cadena SQL; aqui los valores viajan
 *      separados de la sentencia, de modo que no pueden alterarla. Se admiten
 *      marcadores posicionales (?) y con nombre (:nombre), estos ultimos
 *      traducidos a posicionales antes de preparar.
 *
 *   2. La conexion se abre UNA vez por peticion, no una por consulta. Sin
 *      esto las transacciones son imposibles, y este sistema las necesita
 *      para que rotar una contrasena sea atomico.
 */
class mainModel
{
    private static ?mysqli $enlace = null;
    private static int $profundidadTransaccion = 0;

    // -----------------------------------------------------------------
    //  Conexion
    // -----------------------------------------------------------------

    /**
     * Abre (o reutiliza) la conexion con la base de datos.
     *
     * A diferencia de la referencia, las credenciales NO estan escritas aqui:
     * se leen de la configuracion, que a su vez las toma del .env. Un volcado
     * del repositorio no debe bastar para entrar en la base.
     */
    public static function conectar(): mysqli
    {
        if (self::$enlace instanceof mysqli) {
            return self::$enlace;
        }

        $cfg = Config::get('database');
        if (!is_array($cfg)) {
            throw new RuntimeException('Configuracion de base de datos no disponible.');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $enlace = !empty($cfg['socket'])
                ? new mysqli($cfg['host'], $cfg['username'], (string) $cfg['password'], $cfg['database'], (int) $cfg['port'], $cfg['socket'])
                : new mysqli($cfg['host'], $cfg['username'], (string) $cfg['password'], $cfg['database'], (int) $cfg['port']);
        } catch (Throwable $e) {
            // El mensaje del driver puede incluir usuario y host: no se propaga.
            Logger::critical('No fue posible conectar con la base de datos', ['error' => $e->getMessage()]);
            throw new RuntimeException('No fue posible conectar con la base de datos.');
        }

        $enlace->set_charset($cfg['charset'] ?? 'utf8mb4');
        $enlace->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");

        self::$enlace = $enlace;
        return $enlace;
    }

    /** Cierra la conexion. Solo se usa en procesos de larga duracion. */
    public static function desconectar(): void
    {
        if (self::$enlace instanceof mysqli) {
            @self::$enlace->close();
        }
        self::$enlace = null;
        self::$profundidadTransaccion = 0;
    }

    // -----------------------------------------------------------------
    //  Ejecucion
    // -----------------------------------------------------------------

    /**
     * Ejecuta una consulta.
     *
     * Sin parametros se ejecuta directamente (sentencias DDL, TRUNCATE...).
     * Con parametros se prepara SIEMPRE: es la unica via por la que entra un
     * dato de usuario a la base.
     *
     * @param array<string|int,mixed> $params
     */
    public static function ejecutarConsulta(string $sql, array $params = []): mysqli_result|bool
    {
        if ($params === []) {
            return self::consultaDirecta($sql);
        }

        $stmt      = self::preparar($sql, $params);
        $resultado = $stmt->get_result();
        $stmt->close();

        return $resultado === false ? true : $resultado;
    }

    /**
     * Ejecuta una insercion y devuelve el identificador generado.
     *
     * @param array<string|int,mixed> $params
     */
    public static function ejecutarInsert(string $sql, array $params = []): int
    {
        if ($params === []) {
            self::consultaDirecta($sql);
            return (int) self::conectar()->insert_id;
        }
        $stmt = self::preparar($sql, $params);
        $id   = (int) self::conectar()->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Ejecuta una sentencia y devuelve el numero de filas afectadas.
     *
     * @param array<string|int,mixed> $params
     */
    public static function ejecutarConsultaAfectadas(string $sql, array $params = []): int
    {
        if ($params === []) {
            self::consultaDirecta($sql);
            return (int) self::conectar()->affected_rows;
        }
        $stmt      = self::preparar($sql, $params);
        $afectadas = $stmt->affected_rows;
        $stmt->close();
        return (int) $afectadas;
    }

    // -----------------------------------------------------------------
    //  Lectura
    // -----------------------------------------------------------------

    /**
     * @param array<string|int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function obtenerFilas(string $sql, array $params = []): array
    {
        $resultado = self::ejecutarConsulta($sql, $params);
        if (!$resultado instanceof mysqli_result) {
            return [];
        }
        $filas = $resultado->fetch_all(MYSQLI_ASSOC);
        $resultado->free();
        return $filas;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function obtenerFila(string $sql, array $params = []): ?array
    {
        $resultado = self::ejecutarConsulta($sql, $params);
        if (!$resultado instanceof mysqli_result) {
            return null;
        }
        $fila = $resultado->fetch_assoc();
        $resultado->free();
        return $fila === null ? null : $fila;
    }

    /** @param array<string|int,mixed> $params */
    public static function obtenerValor(string $sql, array $params = []): mixed
    {
        $fila = self::obtenerFila($sql, $params);
        if ($fila === null) {
            return null;
        }
        $valores = array_values($fila);
        return $valores[0] ?? null;
    }

    // -----------------------------------------------------------------
    //  Transacciones
    // -----------------------------------------------------------------

    /**
     * Ejecuta el bloque dentro de una transaccion.
     *
     * Admite anidamiento: solo la llamada mas externa confirma o revierte.
     * Rotar una contrasena escribe en tres tablas y debe ser atomico.
     */
    public static function transaccion(callable $bloque): mixed
    {
        $enlace = self::conectar();
        $raiz   = self::$profundidadTransaccion === 0;

        if ($raiz) {
            $enlace->begin_transaction();
        }
        self::$profundidadTransaccion++;

        try {
            $resultado = $bloque();
            self::$profundidadTransaccion--;
            if ($raiz) {
                $enlace->commit();
            }
            return $resultado;
        } catch (Throwable $e) {
            self::$profundidadTransaccion--;
            if ($raiz) {
                @$enlace->rollback();
            }
            throw $e;
        }
    }

    // -----------------------------------------------------------------
    //  Saneamiento (convencion de Porcify Manager)
    // -----------------------------------------------------------------

    /**
     * Limpia una cadena de entrada.
     *
     * En la referencia esta funcion es la UNICA defensa frente a la inyeccion
     * SQL, y por eso lleva una lista negra de palabras. Aqui la defensa son
     * las sentencias preparadas, de modo que esto se limita a lo que de
     * verdad le corresponde: recortar y quitar bytes de control.
     */
    public static function limpiarDatos(string $dato): string
    {
        $dato = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $dato) ?? '';
        return trim($dato);
    }

    /** Comprueba una cadena contra un patron con nombre. */
    public static function verificarDatos(string $filtro, string $cadena): bool
    {
        $patrones = [
            'entero'     => '/^\d{1,18}$/',
            'decimal'    => '/^\d{1,15}(\.\d{1,4})?$/',
            'texto'      => '/^[\p{L}\p{N}\s.,;:_\-()#\/]{1,500}$/u',
            'usuario'    => '/^[a-zA-Z0-9._\-]{4,60}$/',
            'cedula'     => '/^[A-Za-z0-9.\-]{5,30}$/',
            'correo'     => '/^[^@\s]+@[^@\s]+\.[a-zA-Z]{2,}$/',
            'fecha'      => '/^\d{4}-\d{2}-\d{2}$/',
            'uuid'       => '/^[a-f0-9\-]{36}$/',
            'hex64'      => '/^[a-f0-9]{64}$/',
        ];
        $patron = $patrones[$filtro] ?? null;
        return $patron !== null && preg_match($patron, $cadena) === 1;
    }

    // -----------------------------------------------------------------
    //  Internos
    // -----------------------------------------------------------------

    private static function consultaDirecta(string $sql): mysqli_result|bool
    {
        try {
            return self::conectar()->query($sql);
        } catch (mysqli_sql_exception $e) {
            self::registrarFallo($sql, $e);
            throw $e;
        }
    }

    /**
     * Prepara y ejecuta. Devuelve la sentencia ya ejecutada.
     *
     * @param array<string|int,mixed> $params
     */
    private static function preparar(string $sql, array $params): mysqli_stmt
    {
        [$sql, $valores] = self::traducirMarcadores($sql, $params);

        try {
            $stmt = self::conectar()->prepare($sql);
        } catch (mysqli_sql_exception $e) {
            self::registrarFallo($sql, $e);
            throw $e;
        }

        if ($valores !== []) {
            $tipos       = '';
            $referencias = [];
            foreach ($valores as $indice => $valor) {
                $tipos .= match (true) {
                    is_int($valor), is_bool($valor) => 'i',
                    is_float($valor)                => 'd',
                    default                         => 's',
                };
                // bind_param exige referencias, incluso para los nulos.
                $referencias[$indice] = is_bool($valor) ? (int) $valor : $valor;
            }
            $stmt->bind_param($tipos, ...$referencias);
        }

        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            self::registrarFallo($sql, $e);
            $stmt->close();
            throw $e;
        }

        return $stmt;
    }

    /**
     * Traduce marcadores con nombre a posicionales.
     *
     * mysqli solo entiende "?". Los repositorios usan ":nombre" porque hace
     * las consultas legibles, y un mismo nombre puede aparecer varias veces.
     *
     * @param array<string|int,mixed> $params
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function traducirMarcadores(string $sql, array $params): array
    {
        if (array_is_list($params)) {
            return [$sql, array_values($params)];
        }

        $normalizados = [];
        foreach ($params as $clave => $valor) {
            $normalizados[ltrim((string) $clave, ':')] = $valor;
        }

        $ordenados = [];
        $sql = (string) preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            static function (array $m) use ($normalizados, &$ordenados): string {
                $nombre = $m[1];
                if (!array_key_exists($nombre, $normalizados)) {
                    return $m[0];   // no es un marcador nuestro
                }
                $ordenados[] = $normalizados[$nombre];
                return '?';
            },
            $sql
        );

        return [$sql, $ordenados];
    }

    private static function registrarFallo(string $sql, Throwable $e): void
    {
        // Se registra la sentencia (sin valores) para poder diagnosticar en
        // servidor; al cliente jamas se le devuelve este detalle.
        Logger::error('Fallo al ejecutar una consulta', [
            'sql'   => preg_replace('/\s+/', ' ', $sql),
            'error' => $e->getMessage(),
        ]);
    }
}
