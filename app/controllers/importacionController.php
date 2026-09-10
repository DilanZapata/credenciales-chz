<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\HttpException;
use app\models\importacionModel;
use app\models\permisoModel;

/** Importacion masiva de credenciales desde CSV. */
class importacionController extends baseController
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public static function columnasController(): array
    {
        return self::responder(static function (): array {
            permisoModel::exigir('import.credentials');
            return ['columns' => importacionModel::templateColumns()];
        }, 'Plantilla de importacion');
    }

    /** Contenido del CSV de ejemplo; el endpoint decide como entregarlo. */
    public static function plantillaController(): string
    {
        permisoModel::exigir('import.credentials');
        return importacionModel::templateCsv();
    }

    public static function previsualizarController(array $archivos): array
    {
        return self::responder(static function () use ($archivos): array {
            permisoModel::exigir('import.credentials');
            $contenido = self::leerArchivo($archivos);
            return [
                'preview' => importacionModel::preview($contenido),
                // El contenido vuelve al cliente para confirmar sin dejar el
                // archivo en el servidor: trae contrasenas en claro.
                'payload' => base64_encode($contenido),
            ];
        }, 'Vista previa de la importacion');
    }

    public static function ejecutarController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            permisoModel::exigir('import.credentials');
            $codificado = (string) ($variables['payload'] ?? '');
            $contenido  = base64_decode($codificado, true);
            if ($contenido === false || $contenido === '') {
                throw HttpException::badRequest('No hay datos para importar. Vuelva a cargar el archivo.');
            }
            if (strlen($contenido) > self::MAX_BYTES) {
                throw HttpException::badRequest('El archivo supera el tamano permitido.');
            }
            return importacionModel::execute($contenido);
        }, 'Importacion finalizada');
    }

    private static function leerArchivo(array $archivos): string
    {
        $archivo = $archivos['archivo'] ?? null;
        if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw HttpException::badRequest('Debe seleccionar un archivo CSV valido.');
        }
        if ((int) ($archivo['size'] ?? 0) > self::MAX_BYTES) {
            throw HttpException::badRequest('El archivo supera los 2 MB permitidos.');
        }
        $tmp = (string) ($archivo['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw HttpException::badRequest('La carga del archivo no es valida.');
        }
        $extension = strtolower(pathinfo((string) ($archivo['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'], true)) {
            throw HttpException::badRequest('Solo se admiten archivos CSV. Exporte su Excel a CSV primero.');
        }

        $contenido = (string) file_get_contents($tmp);
        // El temporal contiene contrasenas en claro: se elimina de inmediato.
        @unlink($tmp);

        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $convertido = @iconv('ISO-8859-1', 'UTF-8//TRANSLIT', $contenido);
            $contenido  = $convertido !== false ? $convertido : $contenido;
        }
        return $contenido;
    }
}
