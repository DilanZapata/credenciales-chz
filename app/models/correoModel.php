<?php
declare(strict_types=1);

namespace app\models;

use App\Core\Config;
use App\Core\Logger;
use RuntimeException;
use Throwable;

/**
 * Envio de notificaciones por correo.
 *
 * Politica: el correo NUNCA transporta contrasenas ni secretos. Solo
 * avisos y enlaces de un solo uso.
 *
 * El transporte es el cliente SMTP propio (smtpModel). Antes era mail(),
 * que delega en un agente local: en el contenedor no hay ninguno, asi que
 * devolvia false y el correo de recuperacion no salia nunca sin dejar
 * rastro. Ahora todo fallo queda en el log tecnico con el motivo que dio
 * el servidor.
 */
class correoModel extends mainModel
{
    /**
     * Separador de las partes de un mensaje multipart.
     *
     * Se genera por mensaje y no es una constante: si el cuerpo HTML
     * llegara a contener el separador, el correo se partiria en pedazos.
     * Con las plantillas editables ese HTML lo escribe una persona.
     */
    private static string $frontera = '';

    public static function enabled(): bool
    {
        return correoConfigModel::habilitado();
    }

    /**
     * Entrega un mensaje.
     *
     * @param string|null $html cuerpo alternativo en HTML (multipart)
     */
    public static function send(string $to, string $subject, string $body, ?string $html = null): bool
    {
        try {
            self::entregar($to, $subject, $body, $html);
            return true;
        } catch (Throwable $e) {
            // Nunca se propaga: que no salga un aviso no puede tumbar la
            // operacion que lo origino (un restablecimiento, una alerta).
            Logger::error('No fue posible enviar un correo', [
                'para'   => $to,
                'asunto' => $subject,
                'motivo' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Igual que send(), pero deja salir el error.
     *
     * Lo usa la prueba de la pantalla de configuracion, que necesita
     * mostrar el motivo exacto del rechazo en vez de un "no se pudo".
     *
     * @throws RuntimeException
     */
    public static function entregar(string $to, string $subject, string $body, ?string $html = null): void
    {
        // Cortafuegos: si el cuerpo contiene algo con aspecto de secreto, no sale.
        if (self::looksLikeSecret($body) || ($html !== null && self::looksLikeSecret($html))) {
            Logger::critical('Se bloqueo un correo que parecia contener un secreto', ['asunto' => $subject]);
            throw new RuntimeException('El mensaje parecia contener una contrasena y no se envio.');
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('La direccion de destino no es valida.');
        }
        if (!self::enabled()) {
            Logger::info('Correo no enviado (envio deshabilitado)', ['para' => $to, 'asunto' => $subject]);
            throw new RuntimeException(
                'El envio de correo esta deshabilitado o no hay servidor configurado. '
                . 'Revise Administracion > Correo.'
            );
        }

        $config = correoConfigModel::obtener();
        $de     = (string) $config['from_email'];

        self::$frontera = 'scgca-' . bin2hex(random_bytes(12));

        $cliente = new smtpModel(correoConfigModel::paraEnvio());
        $cliente->enviar($de, [$to], self::cabeceras($to, $subject, $config, $html !== null), self::cuerpo($body, $html));
    }

    /**
     * @param array<string,mixed> $config
     */
    private static function cabeceras(string $to, string $subject, array $config, bool $conHtml): string
    {
        $de     = (string) $config['from_email'];
        $nombre = (string) ($config['from_name'] ?? '');
        $origen = $nombre !== ''
            ? self::codificar($nombre) . ' <' . $de . '>'
            : $de;

        $lineas = [
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . self::dominio($de) . '>',
            'From: ' . self::sanitizeHeader($origen),
            'To: ' . self::sanitizeHeader($to),
            'Subject: ' . self::codificar($subject),
            'MIME-Version: 1.0',
        ];

        $responder = (string) ($config['reply_to'] ?? '');
        if ($responder !== '') {
            $lineas[] = 'Reply-To: ' . self::sanitizeHeader($responder);
        }

        $lineas[] = $conHtml
            ? 'Content-Type: multipart/alternative; boundary="' . self::$frontera . '"'
            : 'Content-Type: text/plain; charset=UTF-8';
        if (!$conHtml) {
            $lineas[] = 'Content-Transfer-Encoding: 8bit';
        }
        $lineas[] = 'X-Mailer: SCGCA';
        $lineas[] = 'Auto-Submitted: auto-generated';

        return implode("\r\n", $lineas);
    }


    private static function cuerpo(string $texto, ?string $html): string
    {
        $plano = self::ajustarAncho($texto);
        if ($html === null) {
            return $plano;
        }

        $f = '--' . self::$frontera;
        return implode("\r\n", [
            'Este mensaje esta en formato MIME.',
            '',
            $f,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $plano,
            '',
            $f,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $html,
            '',
            $f . '--',
        ]);
    }

    /**
     * Ajusta el texto a 78 columnas respetando los saltos que ya trae.
     *
     * wordwrap() sobre el texto entero no sirve: no reinicia la cuenta en
     * los saltos existentes, de modo que partia por la mitad lineas que ya
     * eran cortas ("Fecha: 21/09/2026" y la hora en la linea siguiente).
     */
    private static function ajustarAncho(string $texto): string
    {
        $lineas = array_map(
            static fn (string $linea): string => wordwrap($linea, 78, "\n", false),
            explode("\n", str_replace("\r\n", "\n", $texto))
        );
        return implode("\n", $lineas);
    }

    public static function sendPasswordResetLink(string $to, string $name, string $link): bool
    {
        $app  = (string) Config::get('app.name', 'Gestion de Credenciales');
        $body = "Hola {$name}:\n\n"
              . "Recibimos una solicitud para restablecer su contrasena de acceso a {$app}.\n\n"
              . "Abra el siguiente enlace (valido por 30 minutos y de un solo uso):\n{$link}\n\n"
              . "Si usted no realizo esta solicitud, ignore este mensaje y avise al administrador.\n\n"
              . "Por seguridad, este sistema nunca envia contrasenas por correo electronico.\n";
        return self::send($to, "[{$app}] Restablecimiento de contrasena", $body);
    }

    /** @param array<int,array<string,mixed>> $alerts */
    public static function sendAlertDigest(array $alerts): void
    {
        if ($alerts === []) {
            return;
        }
        $app  = (string) Config::get('app.name', 'Gestion de Credenciales');
        $body = "Resumen de alertas de {$app} - " . date('d/m/Y H:i') . "\n\n";
        foreach ($alerts as $alert) {
            $body .= sprintf("[%s] %s\n    %s\n\n", strtoupper((string) $alert['severity']), $alert['title'], $alert['message']);
        }
        $body .= "Ingrese al sistema para revisar el detalle.\n";
        self::send((string) correoConfigModel::obtener()['from_email'], "[{$app}] Alertas de seguridad", $body);
    }

    /**
     * Mensaje de prueba de la pantalla de configuracion.
     *
     * @throws RuntimeException con el motivo exacto si el servidor rechaza
     */
    public static function enviarPrueba(string $to, string $quien): void
    {
        $app  = (string) Config::get('app.name', 'Gestion de Credenciales');
        $body = "Esto es una prueba de envio de {$app}.\n\n"
              . "Si esta leyendo este mensaje, el servidor de correo saliente esta bien configurado "
              . "y los avisos del sistema (restablecimiento de acceso, alertas) ya pueden salir.\n\n"
              . "Solicitada por: {$quien}\n"
              . "Fecha: " . date('d/m/Y H:i:s') . "\n";
        self::entregar($to, "[{$app}] Prueba de configuracion de correo", $body);
    }

    private static function dominio(string $correo): string
    {
        $partes = explode('@', $correo);
        return count($partes) === 2 && $partes[1] !== '' ? $partes[1] : 'localhost';
    }

    /** RFC 2047 para acentos en asuntos y nombres. */
    private static function codificar(string $valor): string
    {
        $limpio = self::sanitizeHeader($valor);
        return preg_match('/[\x80-\xFF]/', $limpio) === 1
            ? '=?UTF-8?B?' . base64_encode($limpio) . '?='
            : $limpio;
    }

    private static function sanitizeHeader(string $value): string
    {
        return str_replace(["\r", "\n", "\0", '%0a', '%0d'], '', $value);
    }

    private static function looksLikeSecret(string $body): bool
    {
        return (bool) preg_match('/\b(contrasena|password|clave|secret)\s*[:=]\s*\S+/i', $body);
    }
}
