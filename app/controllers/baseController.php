<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\HttpException;
use App\Core\Logger;
use App\Core\ReauthRequiredException;
use App\Core\ValidationException;
use app\models\mainModel;
use Throwable;

/**
 * Base de los controladores.
 *
 * Centraliza la traduccion de excepciones al formato de respuesta comun
 * `{code, status, title, message, data}` de Porcify Manager. En la
 * referencia ese try/catch se repite en cada metodo de cada controlador;
 * aqui se escribe una sola vez para que no pueda divergir entre modulos.
 */
abstract class baseController extends mainModel
{
    /**
     * Ejecuta la accion y devuelve la respuesta ya formateada.
     *
     * @param callable():mixed $accion
     */
    protected static function responder(callable $accion, string $titulo = 'Operacion', int $codigoOk = 200): array
    {
        try {
            return [
                'code'    => $codigoOk,
                'status'  => 'success',
                'title'   => $titulo,
                'message' => $titulo . '.',
                'data'    => $accion(),
            ];
        } catch (ReauthRequiredException $e) {
            return [
                'code' => 423, 'status' => 'error', 'title' => 'Confirmacion requerida',
                'message' => $e->getMessage(), 'reauth_required' => true, 'data' => null,
            ];
        } catch (ValidationException $e) {
            return [
                'code' => 422, 'status' => 'error', 'title' => 'Datos invalidos',
                'message' => $e->getMessage(), 'errors' => $e->errors(), 'data' => null,
            ];
        } catch (HttpException $e) {
            return [
                'code' => $e->statusCode(), 'status' => 'error', 'title' => 'Operacion no permitida',
                'message' => $e->getMessage(), 'data' => null,
            ];
        } catch (Throwable $e) {
            Logger::error('Fallo en ' . static::class, ['error' => $e->getMessage()]);
            return [
                'code' => 500, 'status' => 'error', 'title' => 'Error del sistema',
                'message' => 'Ocurrio un error inesperado. El incidente quedo registrado.', 'data' => null,
            ];
        }
    }

    // -------------------- Lectura de la entrada --------------------

    protected static function texto(array $origen, string $clave, string $porDefecto = ''): string
    {
        $v = $origen[$clave] ?? $porDefecto;
        return is_scalar($v) ? mainModel::limpiarDatos((string) $v) : $porDefecto;
    }

    protected static function entero(array $origen, string $clave): ?int
    {
        $v = $origen[$clave] ?? null;
        if ($v === null || $v === '' || is_array($v)) {
            return null;
        }
        $n = filter_var($v, FILTER_VALIDATE_INT);
        return $n === false ? null : (int) $n;
    }

    protected static function booleano(array $origen, string $clave, bool $porDefecto = false): bool
    {
        if (!array_key_exists($clave, $origen)) {
            return $porDefecto;
        }
        $v = $origen[$clave];
        return in_array(strtolower((string) (is_array($v) ? '' : $v)), ['1', 'true', 'on', 'yes', 'si'], true);
    }

    /** @return array<int,string> */
    protected static function lista(array $origen, string $clave): array
    {
        $v = $origen[$clave] ?? [];
        if (!is_array($v)) {
            $v = $v === '' || $v === null ? [] : [$v];
        }
        return array_values(array_filter(array_map(
            static fn ($i) => is_scalar($i) ? trim((string) $i) : '',
            $v
        ), static fn ($i) => $i !== ''));
    }

    /** @return array<int,int> */
    protected static function listaEnteros(array $origen, string $clave): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($v) => filter_var($v, FILTER_VALIDATE_INT),
            self::lista($origen, $clave)
        ), static fn ($v) => $v !== false)));
    }

    protected static function paginacion(array $origen, int $porDefecto = 25): array
    {
        return [
            max(1, (int) ($origen['page'] ?? 1)),
            max(5, min(100, (int) ($origen['per_page'] ?? $porDefecto))),
        ];
    }
}
