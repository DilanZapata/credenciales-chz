<?php
declare(strict_types=1);

namespace App\Services;

use app\models\generadorModel;

/** Envoltorio de transicion sobre `app\models\generadorModel`. */
final class PasswordGeneratorService
{
    public function generate(array $opciones = []): string   { return generadorModel::generate($opciones); }
    public function strength(string $clave): int             { return generadorModel::strength($clave); }
    public function strengthLabel(int $puntaje): string      { return generadorModel::strengthLabel($puntaje); }
}
