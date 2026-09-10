<?php
declare(strict_types=1);

namespace app\controllers;

use app\models\viewsModel;

/** Controlador de vistas (patron de Porcify Manager). */
class viewsController extends viewsModel
{
    /**
     * Resuelve que vista atiende la direccion pedida.
     *
     * @return array<string,mixed>
     */
    public static function obtenerVistasControlador(string $ruta): array
    {
        return self::obtenerVistasModelo($ruta);
    }
}
