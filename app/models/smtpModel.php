<?php
declare(strict_types=1);

namespace app\models;

use App\Core\Config;
use RuntimeException;

/**
 * Cliente SMTP minimo.
 *
 * El sistema no usa Composer en ejecucion, asi que aqui esta lo justo para
 * entregar un correo a un servidor autenticado: EHLO, STARTTLS o TLS
 * implicito, AUTH LOGIN/PLAIN y DATA. No pretende cubrir el protocolo
 * entero; cubre lo que hace falta para hablar con Gmail, Microsoft 365 o
 * un relay corporativo.
 *
 * Diferencia con lo que habia antes: mail() delegaba en un agente local
 * que en el contenedor no existe, devolvia false y nadie se enteraba.
 * Aqui cada fallo tiene un mensaje concreto del servidor, que es lo que
 * la pantalla de configuracion muestra al probar el envio.
 */
class smtpModel
{
    /** Respuestas que el protocolo considera correctas en cada paso. */
    private const OK       = [250, 251];
    private const OK_SALUDO = [220];
    private const OK_AUTH  = [235];
    private const OK_DATOS = [354];

    /** @var resource|null */
    private $socket = null;

    private string $ultimaRespuesta = '';

    /**
     * @param array{host:string,port:int,encryption:string,username:string,
     *              password:string,timeout:int} $config
     */
    public function __construct(private array $config)
    {
    }

    /**
     * Entrega un mensaje ya construido.
     *
     * @param array<int,string> $destinatarios
     * @throws RuntimeException con el motivo exacto del rechazo
     */
    public function enviar(string $remitente, array $destinatarios, string $cabeceras, string $cuerpo): void
    {
        if ($destinatarios === []) {
            throw new RuntimeException('No hay destinatarios.');
        }

        try {
            $this->conectar();
            $this->saludar();
            $this->autenticar();

            $this->ordenar('MAIL FROM:<' . $remitente . '>', self::OK);
            foreach ($destinatarios as $destino) {
                $this->ordenar('RCPT TO:<' . $destino . '>', self::OK);
            }

            $this->ordenar('DATA', self::OK_DATOS);
            // Un punto al principio de linea termina el mensaje: se duplica.
            $mensaje = $cabeceras . "\r\n\r\n" . $cuerpo;
            $mensaje = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $mensaje))) ?? $mensaje;
            $this->escribir($mensaje . "\r\n.");
            $this->leerEsperando(self::OK);

            $this->ordenar('QUIT', [221]);
        } finally {
            $this->cerrar();
        }
    }

    private function conectar(): void
    {
        $host = (string) $this->config['host'];
        $modo = (string) $this->config['encryption'];
        if ($host === '') {
            throw new RuntimeException('No hay servidor SMTP configurado.');
        }

        // ssl = TLS implicito desde el primer byte (puerto 465).
        // tls = conexion en claro y STARTTLS despues del EHLO (puerto 587).
        $destino = ($modo === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . (int) $this->config['port'];
        $tiempo  = max(3, (int) $this->config['timeout']);

        $contexto = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);

        $errno = 0;
        $error = '';
        $socket = @stream_socket_client($destino, $errno, $error, $tiempo,
            STREAM_CLIENT_CONNECT, $contexto);

        if ($socket === false) {
            throw new RuntimeException(sprintf(
                'No fue posible conectar con %s:%d (%s).',
                $host, (int) $this->config['port'], $error !== '' ? $error : 'error ' . $errno
            ));
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $tiempo);
        $this->leerEsperando(self::OK_SALUDO);
    }

    private function saludar(): void
    {
        $nombre = $this->nombreCliente();
        $this->ordenar('EHLO ' . $nombre, self::OK);

        if ((string) $this->config['encryption'] === 'tls') {
            $this->ordenar('STARTTLS', self::OK_SALUDO);

            $ok = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );
            if ($ok !== true) {
                throw new RuntimeException(
                    'El servidor acepto STARTTLS pero no fue posible establecer el cifrado. '
                    . 'Revise el certificado del servidor o pruebe el puerto 465 con cifrado SSL.'
                );
            }
            // Tras cifrar hay que volver a presentarse.
            $this->ordenar('EHLO ' . $nombre, self::OK);
        }
    }

    private function autenticar(): void
    {
        $usuario = (string) $this->config['username'];
        $clave   = (string) $this->config['password'];
        if ($usuario === '') {
            return; // Relay interno sin autenticacion.
        }

        try {
            $this->ordenar('AUTH LOGIN', [334]);
            $this->ordenar(base64_encode($usuario), [334]);
            $this->ordenar(base64_encode($clave), self::OK_AUTH);
        } catch (RuntimeException $e) {
            // Algunos servidores solo ofrecen PLAIN.
            if (!str_contains($this->ultimaRespuesta, '504') && !str_contains($this->ultimaRespuesta, '502')) {
                throw new RuntimeException($this->mensajeDeAutenticacion($e->getMessage()));
            }
            $this->ordenar(
                'AUTH PLAIN ' . base64_encode("\0" . $usuario . "\0" . $clave),
                self::OK_AUTH
            );
        }
    }

    /**
     * El nombre que se anuncia en el EHLO. Debe parecer un nombre de
     * maquina: algunos servidores rechazan un EHLO vacio o con espacios.
     */
    private function nombreCliente(): string
    {
        $host = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : (gethostname() ?: 'localhost');
        return preg_match('/^[A-Za-z0-9.\-]+$/', $host) === 1 ? $host : 'localhost';
    }

    /** @param array<int,int> $esperados */
    private function ordenar(string $orden, array $esperados): void
    {
        $this->escribir($orden);
        $this->leerEsperando($esperados);
    }

    private function escribir(string $linea): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('La conexion con el servidor de correo se perdio.');
        }
        if (@fwrite($this->socket, $linea . "\r\n") === false) {
            throw new RuntimeException('No fue posible escribir en la conexion con el servidor de correo.');
        }
    }

    /**
     * Lee una respuesta completa (varias lineas si el codigo lleva guion).
     *
     * @param array<int,int> $esperados
     */
    private function leerEsperando(array $esperados): string
    {
        if ($this->socket === null) {
            throw new RuntimeException('La conexion con el servidor de correo se perdio.');
        }

        $respuesta = '';
        while (true) {
            $linea = @fgets($this->socket, 515);
            if ($linea === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new RuntimeException(($meta['timed_out'] ?? false)
                    ? 'El servidor de correo no respondio a tiempo.'
                    : 'El servidor de correo cerro la conexion.');
            }
            $respuesta .= $linea;
            // "250-" continua, "250 " termina.
            if (strlen($linea) < 4 || $linea[3] !== '-') {
                break;
            }
        }

        $this->ultimaRespuesta = trim($respuesta);
        $codigo = (int) substr($respuesta, 0, 3);
        if (!in_array($codigo, $esperados, true)) {
            throw new RuntimeException($this->ultimaRespuesta);
        }
        return $respuesta;
    }

    /** Traduce los rechazos de autenticacion mas frecuentes. */
    private function mensajeDeAutenticacion(string $bruto): string
    {
        if (str_contains($bruto, '535') || str_contains($bruto, '534')) {
            return 'El servidor rechazo el usuario o la contrasena (' . $bruto . '). '
                 . 'Con Gmail debe usarse una contrasena de aplicacion, no la del correo.';
        }
        return $bruto;
    }

    private function cerrar(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Comprueba la conexion sin entregar ningun mensaje.
     *
     * @param array{host:string,port:int,encryption:string,username:string,
     *              password:string,timeout:int} $config
     */
    public static function probarConexion(array $config): void
    {
        $cliente = new self($config);
        try {
            $cliente->conectar();
            $cliente->saludar();
            $cliente->autenticar();
            $cliente->ordenar('QUIT', [221]);
        } finally {
            $cliente->cerrar();
        }
    }
}
