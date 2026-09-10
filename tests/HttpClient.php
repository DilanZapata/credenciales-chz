<?php
declare(strict_types=1);

namespace Tests;

use RuntimeException;

/**
 * Cliente HTTP real para la bateria de pruebas.
 *
 * Sustituye al cliente en proceso que invocaba el Kernel directamente. Ese
 * enfoque deja de ser viable con la arquitectura de Porcify: no hay Kernel
 * ni contenedor que invocar, sino dos puntos de entrada servidos por Apache.
 *
 * Al hablar por HTTP contra el servidor web, la MISMA bateria de pruebas
 * sirve para las dos arquitecturas. Es lo que permite migrar modulo a modulo
 * sabiendo en cada paso si algo se rompio.
 *
 * Mantiene deliberadamente la interfaz del cliente anterior —get, post,
 * getJson, postJson, request, login, csrfToken— para que los 209 asertos no
 * tengan que reescribirse.
 */
final class HttpClient
{
    /** @var array<string,string> */
    private array $cookies = [];
    private string $baseUrl;
    private string $ip;
    private string $userAgent;

    public string $lastBody = '';
    public int $lastStatus = 0;
    /** @var array<string,string> */
    public array $lastHeaders = [];

    public function __construct(
        string $ip = '203.0.113.10',
        string $userAgent = 'PruebasSCGCA/2.0 (Chrome/120 Windows NT 10)',
        ?string $baseUrl = null
    ) {
        $this->ip        = $ip;
        $this->userAgent = $userAgent;
        $this->baseUrl   = rtrim($baseUrl ?? self::baseUrlPorDefecto(), '/');
    }

    public static function baseUrlPorDefecto(): string
    {
        $desdeEntorno = getenv('TEST_BASE_URL');
        if (is_string($desdeEntorno) && $desdeEntorno !== '') {
            return $desdeEntorno;
        }
        return 'http://localhost/credencial';
    }

    public function reset(): void
    {
        $this->cookies = [];
    }

    public function cookie(string $nombre): ?string
    {
        return $this->cookies[$nombre] ?? null;
    }

    public function setCookie(string $nombre, string $valor): void
    {
        $this->cookies[$nombre] = $valor;
    }

    /**
     * Token CSRF vigente.
     *
     * Se obtiene de la fila de sesion en base de datos a partir de la cookie,
     * igual que hacia el cliente anterior. Para formularios publicos usa la
     * cookie de doble envio.
     */
    public function csrfToken(): string
    {
        $token = $this->cookies['scgca_session'] ?? null;
        if ($token !== null) {
            $fila = \app\models\mainModel::obtenerFila(
                'SELECT csrf_token FROM sessions WHERE id = ?',
                [hash('sha256', $token)]
            );
            if ($fila !== null) {
                return (string) $fila['csrf_token'];
            }
        }

        $invitado = $this->cookies['scgca_csrf'] ?? null;
        if ($invitado === null) {
            // Se pide el formulario de acceso para que el servidor emita la cookie.
            $this->get('/entrar');
            $invitado = $this->cookies['scgca_csrf'] ?? bin2hex(random_bytes(32));
        }
        return $invitado;
    }

    /**
     * @param array<string,mixed> $datos
     * @return array{status:int,body:string,headers:array<string,string>,json:?array,file:?string}
     */
    public function request(string $metodo, string $ruta, array $datos = [], bool $json = false, bool $conCsrf = true): array
    {
        $metodo = strtoupper($metodo);
        $query  = [];
        $cuerpo = [];

        $partes = explode('?', $ruta, 2);
        $ruta   = $partes[0];
        if (isset($partes[1])) {
            parse_str($partes[1], $query);
        }

        if ($metodo === 'GET') {
            $query = array_merge($query, $datos);
        } else {
            $cuerpo = $datos;
            if ($conCsrf && !$json) {
                $cuerpo['_csrf'] = $this->csrfToken();
            }
        }

        $url = $this->baseUrl . $ruta;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $cabeceras = [
            'User-Agent: ' . $this->userAgent,
            // Un navegador pidiendo una pagina NO anuncia application/json.
            // Anunciarlo hace que la aplicacion responda 401 en JSON en vez de
            // redirigir al formulario, que es lo que hace con una persona.
            'Accept: ' . ($json ? 'application/json' : 'text/html,application/xhtml+xml'),
            'Origin: ' . $this->origen(),
            // Apache no permite falsear REMOTE_ADDR; la aplicacion solo confia
            // en esta cabecera si APP_TRUST_PROXY esta activo, que es el caso
            // en la instalacion local de pruebas.
            'X-Forwarded-For: ' . $this->ip,
        ];

        $opciones = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,   // las pruebas comprueban los 302
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_ENCODING       => '',
        ];

        if ($this->cookies !== []) {
            $pares = [];
            foreach ($this->cookies as $nombre => $valor) {
                $pares[] = $nombre . '=' . $valor;
            }
            $opciones[CURLOPT_COOKIE] = implode('; ', $pares);
        }

        if ($metodo !== 'GET') {
            $verboReal = in_array($metodo, ['PUT', 'PATCH', 'DELETE'], true) ? 'POST' : $metodo;
            $opciones[CURLOPT_CUSTOMREQUEST] = $verboReal;

            if ($json) {
                $cabeceras[] = 'Content-Type: application/json';
                if ($conCsrf) {
                    $cabeceras[] = 'X-CSRF-Token: ' . $this->csrfToken();
                }
                $opciones[CURLOPT_POSTFIELDS] = (string) json_encode($cuerpo);
            } else {
                if (in_array($metodo, ['PUT', 'PATCH', 'DELETE'], true)) {
                    $cuerpo['_method'] = $metodo;
                }
                $opciones[CURLOPT_POSTFIELDS] = http_build_query($cuerpo);
            }
        }

        $opciones[CURLOPT_HTTPHEADER] = $cabeceras;

        $ch = curl_init();
        curl_setopt_array($ch, $opciones);
        $respuesta = curl_exec($ch);

        if ($respuesta === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Fallo la peticion a ' . $url . ': ' . $error);
        }

        $estado       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tamCabeceras = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $bloqueCabeceras = substr((string) $respuesta, 0, $tamCabeceras);
        $cuerpoTexto     = substr((string) $respuesta, $tamCabeceras);

        $cabecerasResp = $this->parsearCabeceras($bloqueCabeceras);
        $this->absorberCookies($bloqueCabeceras);

        $this->lastStatus  = $estado;
        $this->lastBody    = $cuerpoTexto;
        $this->lastHeaders = $cabecerasResp;

        $decodificado = null;
        if (str_contains($cabecerasResp['Content-Type'] ?? '', 'application/json')) {
            $tmp          = json_decode($cuerpoTexto, true);
            $decodificado = is_array($tmp) ? $tmp : null;
        }

        return [
            'status'  => $estado,
            'body'    => $cuerpoTexto,
            'headers' => $cabecerasResp,
            'json'    => $decodificado,
            'file'    => null,
        ];
    }

    public function get(string $ruta, array $query = []): array
    {
        return $this->request('GET', $ruta, $query);
    }

    public function post(string $ruta, array $datos = [], bool $conCsrf = true): array
    {
        return $this->request('POST', $ruta, $datos, false, $conCsrf);
    }

    public function getJson(string $ruta, array $query = []): array
    {
        return $this->request('GET', $ruta, $query, false);
    }

    public function postJson(string $ruta, array $datos = [], bool $conCsrf = true): array
    {
        return $this->request('POST', $ruta, $datos, true, $conCsrf);
    }

    /** Inicia sesion y deja la cookie lista para las peticiones siguientes. */
    public function login(string $identificador, string $clave): array
    {
        $this->get('/entrar');
        return $this->post('/entrar', ['identifier' => $identificador, 'password' => $clave]);
    }

    // -----------------------------------------------------------------

    private function origen(): string
    {
        $partes = parse_url($this->baseUrl);
        $origen = ($partes['scheme'] ?? 'http') . '://' . ($partes['host'] ?? 'localhost');
        if (isset($partes['port'])) {
            $origen .= ':' . $partes['port'];
        }
        return $origen;
    }

    /** @return array<string,string> */
    private function parsearCabeceras(string $bloque): array
    {
        $cabeceras = [];
        foreach (preg_split('/\r?\n/', $bloque) ?: [] as $linea) {
            $pos = strpos($linea, ':');
            if ($pos === false) {
                continue;
            }
            $cabeceras[trim(substr($linea, 0, $pos))] = trim(substr($linea, $pos + 1));
        }
        return $cabeceras;
    }

    private function absorberCookies(string $bloque): void
    {
        if (preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;]*)/mi', $bloque, $coincidencias, PREG_SET_ORDER) === 0) {
            return;
        }
        foreach ($coincidencias as $c) {
            $nombre = trim($c[1]);
            $valor  = trim($c[2]);
            if ($valor === '' || $valor === 'deleted') {
                unset($this->cookies[$nombre]);
                continue;
            }
            $this->cookies[$nombre] = $valor;
        }
    }
}
