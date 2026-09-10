<?php
declare(strict_types=1);

/**
 * Autocarga manual de clases (patron de Porcify Manager).
 *
 * Mapea el espacio de nombres directamente a la ruta de archivo:
 *
 *     app\models\credencialModel  ->  app/models/credencialModel.php
 *
 * No es PSR-4 de Composer: el proyecto no usa Composer en ejecucion.
 *
 * Se conserva el mapeo del espacio de nombres App\ en mayuscula mientras
 * dure la migracion, para que ambos convivan sin romperse.
 */
spl_autoload_register(static function (string $clase): void {

    // Convencion de Porcify:  app\models\xModel  ->  app/models/xModel.php
    $archivo = __DIR__ . '/' . str_replace('\\', '/', $clase) . '.php';
    if (is_file($archivo)) {
        require_once $archivo;
        return;
    }

    // Convencion actual (en retirada):  App\Core\Kernel  ->  app/Core/Kernel.php
    if (str_starts_with($clase, 'App\\')) {
        $relativa = str_replace('\\', '/', substr($clase, 4));
        $archivo  = __DIR__ . '/app/' . $relativa . '.php';
        if (is_file($archivo)) {
            require_once $archivo;
        }
    }
});
