<?php
declare(strict_types=1);

namespace App\Services;

use app\models\correoModel;

/** Envoltorio de transicion sobre `app\models\correoModel`. */
final class MailService
{
    /**
     * Constructor de transicion: acepta las dependencias que aun inyecta
     * el contenedor, sin usarlas. La logica vive en el modelo estatico.
     */
    public function __construct(mixed ...$dependencias)
    {
    }

    public function enabled(): bool                                      { return correoModel::enabled(); }
    public function send(string $a, string $asunto, string $cuerpo): bool { return correoModel::send($a, $asunto, $cuerpo); }
    public function sendAlertDigest(array $alertas): void                { correoModel::sendAlertDigest($alertas); }

    public function sendPasswordResetLink(string $a, string $nombre, string $enlace): bool
    {
        return correoModel::sendPasswordResetLink($a, $nombre, $enlace);
    }
}
