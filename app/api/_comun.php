<?php
declare(strict_types=1);

/**
 * Utilidades compartidas por los endpoints de app/api/.
 *
 * Porcify Manager repite estas comprobaciones en cada archivo. Aqui se
 * centralizan por una razon concreta: en la referencia esa repeticion
 * produjo modulos que validan permisos por accion y otros que solo miran si
 * hay sesion. Con una sola implementacion no puede divergir.
 */

use app\middlewares\accesoMiddleware;
use app\models\auditoriaModel;
use app\models\contextoModel;
use app\models\sesionModel;

/** Emite la respuesta JSON y termina. */
function responder(array $respuesta): never
{
    http_response_code((int) ($respuesta['code'] ?? 200));
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Cuerpo de la peticion: admite JSON y formulario. */
function cuerpoPeticion(): array
{
    $tipo = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($tipo, 'application/json')) {
        $crudo      = (string) file_get_contents('php://input');
        $decodificado = $crudo !== '' ? json_decode($crudo, true) : null;
        return is_array($decodificado) ? $decodificado : [];
    }
    return $_POST;
}

/** Exige una sesion valida. */
function exigirSesion(): void
{
    if (contextoModel::autenticado()) {
        return;
    }
    responder([
        'code'    => 401,
        'status'  => 'error',
        'title'   => 'Sesion requerida',
        'message' => 'Debe iniciar sesion.',
        'data'    => null,
    ]);
}

/**
 * Exige el token anti-CSRF en toda escritura.
 *
 * La referencia no tiene esta proteccion. Aqui se conserva porque los
 * endpoints tambien se invocan desde la interfaz con cookie de sesion, y
 * sin token un sitio externo podria disparar acciones en nombre del usuario.
 *
 * Se aplica tambien al ingreso: sin ella, un sitio externo puede autenticar
 * a la victima en una cuenta ajena y observar lo que haga a partir de ahi.
 */
function exigirCsrf(): void
{
    $esperado = accesoMiddleware::tokenCsrfEsperado();
    $recibido = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? (cuerpoPeticion()['_csrf'] ?? ''));

    if ($esperado !== null && is_string($recibido) && $recibido !== '' && hash_equals($esperado, $recibido)) {
        return;
    }

    auditoriaModel::registrar('security.csrf_failed', null, null, contextoModel::ruta(), 'denied',
        ['metodo' => $_SERVER['REQUEST_METHOD'] ?? ''], 'critical');

    responder([
        'code'    => 403,
        'status'  => 'error',
        'title'   => 'Sesion expirada',
        'message' => 'La sesion del formulario expiro. Recargue la pagina e intente nuevamente.',
        'csrf'    => true,
        'data'    => null,
    ]);
}

function exigirId(int $id): void
{
    if ($id > 0) {
        return;
    }
    responder([
        'code'    => 400,
        'status'  => 'error',
        'title'   => 'Solicitud invalida',
        'message' => 'Debe indicar el identificador del registro.',
        'data'    => null,
    ]);
}

function accionInvalida(string $accion): array
{
    return [
        'code'    => 400,
        'status'  => 'error',
        'title'   => 'Accion no reconocida',
        'message' => $accion === '' ? 'Debe indicar una accion.' : 'La accion solicitada no existe.',
        'data'    => null,
    ];
}

function metodoNoPermitido(string $metodo): array
{
    return [
        'code'    => 405,
        'status'  => 'error',
        'title'   => 'Metodo no permitido',
        'message' => 'El metodo ' . $metodo . ' no esta permitido en este endpoint.',
        'data'    => null,
    ];
}

/**
 * Entrega un archivo generado y termina.
 *
 * Los reportes pueden contener contrasenas en claro, asi que se envian sin
 * cache y el archivo se borra del disco en cuanto sale.
 */
function enviarArchivo(string $ruta, string $nombre, string $mime, bool $eliminar = true): never
{
    $nombre = substr(preg_replace('/[^A-Za-z0-9._\- ]/', '_', $nombre) ?? 'archivo', 0, 120);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($ruta));
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');

    readfile($ruta);
    if ($eliminar) {
        @unlink($ruta);
    }
    exit;
}

/** Entrega contenido generado en memoria (por ejemplo una plantilla CSV). */
function enviarContenido(string $contenido, string $nombre, string $mime): never
{
    $nombre = substr(preg_replace('/[^A-Za-z0-9._\- ]/', '_', $nombre) ?? 'archivo', 0, 120);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) strlen($contenido));
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');

    echo $contenido;
    exit;
}
