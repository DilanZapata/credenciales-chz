<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Fallo de verificacion anti-CSRF.
 *
 * Usa 403, no 419. El 419 ("Page Expired") es una convencion de Laravel, no
 * un codigo del estandar HTTP: Apache no lo reconoce y lo sustituye por un
 * 500, de modo que el usuario veria "Error del sistema" en lugar del aviso
 * correcto y el cliente JavaScript no podria distinguir el caso.
 *
 * Para que el cliente si pueda distinguirlo, la respuesta JSON incluye
 * "csrf": true y se emite la cabecera X-Csrf-Failure.
 */
final class CsrfException extends HttpException
{
    public function __construct(string $message = 'La sesion del formulario expiro. Recargue la pagina e intente nuevamente.')
    {
        parent::__construct(403, $message, ['csrf' => true]);
    }
}
