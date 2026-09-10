<?php
declare(strict_types=1);

/**
 * Entrega de archivos generados.
 *
 * Son GET que no dibujan una pagina: la plantilla de importacion y los
 * reportes en Excel. Si la direccion no es ninguna de las dos, se dibuja
 * el error 404 con el marco habitual.
 *
 * @var array<string,mixed> $vistaInfo
 * @var string $rutaSolicitada
 */

use app\controllers\importacionController;
use app\controllers\reporteController;
use App\Core\Config;
use App\Core\HttpException;
use app\models\contextoModel;

/** Emite el archivo y termina. */
$entregar = static function (string $ruta, string $nombre, string $mime, bool $eliminar): never {
    $nombre = substr(preg_replace('/[^A-Za-z0-9._\- ]/', '_', $nombre) ?? 'archivo', 0, 120);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($ruta));
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($ruta);
    if ($eliminar) {
        @unlink($ruta);
    }
    exit;
};

$esDescarga = str_starts_with($rutaSolicitada, '/reportes/descargar/')
    || $rutaSolicitada === '/importar/plantilla';

// Un archivo generado no es publico: exige sesion igual que la pagina que
// lo produjo. Sin ella se vuelve al formulario de acceso.
if ($esDescarga && !contextoModel::autenticado()) {
    header('Location: ' . (string) Config::get('app.base_path', '')
        . '/entrar?redirect=' . rawurlencode($rutaSolicitada), true, 302);
    exit;
}

try {
    if (str_starts_with($rutaSolicitada, '/reportes/descargar/')) {
        $archivo = reporteController::descargarController(substr($rutaSolicitada, strlen('/reportes/descargar/')));
        // El archivo se borra del servidor en cuanto sale: puede contener
        // contrasenas en claro y no debe quedar residente.
        $entregar(
            $archivo['path'],
            $archivo['file_name'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            true
        );
    }

    if ($rutaSolicitada === '/importar/plantilla') {
        $csv = importacionController::plantillaController();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Length: ' . (string) strlen($csv));
        header('Content-Disposition: attachment; filename="plantilla-credenciales.csv"');
        header('X-Content-Type-Options: nosniff');
        echo $csv;
        exit;
    }
} catch (HttpException $e) {
    // El archivo no se entrega y el motivo se muestra con su propio codigo:
    // un reporte ajeno responde 404, igual que si no existiera.
    http_response_code($e->statusCode());
    $errorEstado  = $e->statusCode();
    $errorTitulo  = match ($e->statusCode()) {
        401, 403 => 'Acceso denegado',
        404      => 'No encontrado',
        409      => 'Operacion no disponible',
        default  => 'Error del sistema',
    };
    $errorMensaje = $e->getMessage();
    extract(\App\Core\View::sharedData(), EXTR_SKIP);
    require __DIR__ . '/views/inc/error-minimo.php';
    exit;
}
