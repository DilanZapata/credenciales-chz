<?php
declare(strict_types=1);

namespace app\models;

use App\Core\Config;
use RuntimeException;

/**
 * Plantillas de los correos del sistema.
 *
 * El catalogo de tipos y las variables que admite cada uno se declaran
 * aqui, en codigo. La base de datos guarda solo el contenido que escribe
 * quien administra. Asi, una variable nueva llega con el codigo que sabe
 * rellenarla, y nadie puede inventarse en la pantalla una variable que el
 * sistema no sepa sustituir.
 *
 * REGLA: ninguna variable transporta secretos. Lo que se ofrece aqui es
 * exactamente lo que puede salir por correo; el cortafuegos de
 * correoModel sigue revisando el resultado ya renderizado.
 */
class plantillaCorreoModel extends mainModel
{
    /**
     * Tipos de correo, sus variables y el contenido por defecto.
     *
     * 'crudas' son las variables cuyo valor genera el propio sistema como
     * HTML (una tabla de alertas, por ejemplo) y por tanto no se escapan.
     * El resto se escapan siempre.
     *
     * @var array<string,array<string,mixed>>
     */
    private const CATALOGO = [
        'password_reset' => [
            'nombre'      => 'Restablecimiento de contrasena',
            'descripcion' => 'Se envia cuando alguien pide recuperar su acceso desde la pantalla de entrada.',
            'variables'   => [
                'app'     => 'Nombre del sistema',
                'nombre'  => 'Nombre de pila de quien lo pidio',
                'usuario' => 'Nombre de usuario de la cuenta',
                'enlace'  => 'Enlace de un solo uso (obligatorio)',
                'minutos' => 'Minutos que el enlace sigue siendo valido',
                'fecha'   => 'Fecha y hora de la solicitud',
            ],
            'crudas'       => [],
            'obligatorias' => ['enlace'],
        ],
        'alert_digest' => [
            'nombre'      => 'Resumen de alertas',
            'descripcion' => 'Resumen diario de credenciales vencidas, accesos anomalos y demas avisos.',
            'variables'   => [
                'app'     => 'Nombre del sistema',
                'total'   => 'Cuantas alertas trae el resumen',
                'alertas' => 'Listado de alertas ya compuesto',
                'fecha'   => 'Fecha y hora del resumen',
            ],
            'crudas'       => ['alertas'],
            'obligatorias' => ['alertas'],
        ],
    ];

    /** @return array<int,string> */
    public static function codigos(): array
    {
        return array_keys(self::CATALOGO);
    }

    public static function existe(string $codigo): bool
    {
        return isset(self::CATALOGO[$codigo]);
    }

    /**
     * Ficha completa de un tipo: catalogo + lo guardado (o lo de fabrica).
     *
     * @return array<string,mixed>|null
     */
    public static function obtener(string $codigo): ?array
    {
        if (!self::existe($codigo)) {
            return null;
        }
        $ficha = self::CATALOGO[$codigo];
        $fila  = self::obtenerFila(
            'SELECT id, code, subject, body_html, body_text, enabled, updated_by, updated_at
               FROM mail_templates WHERE code = ?',
            [$codigo]
        );
        $porDefecto = self::porDefecto($codigo);

        return [
            'code'         => $codigo,
            'nombre'       => $ficha['nombre'],
            'descripcion'  => $ficha['descripcion'],
            'variables'    => $ficha['variables'],
            'crudas'       => $ficha['crudas'],
            'obligatorias' => $ficha['obligatorias'],
            'personalizada'=> $fila !== null,
            'id'           => $fila !== null ? (int) $fila['id'] : null,
            'enabled'      => $fila === null ? false : (bool) $fila['enabled'],
            'subject'      => $fila !== null ? (string) $fila['subject']   : $porDefecto['subject'],
            'body_html'    => $fila !== null ? (string) ($fila['body_html'] ?? '') : $porDefecto['body_html'],
            'body_text'    => $fila !== null ? (string) $fila['body_text'] : $porDefecto['body_text'],
            'updated_at'   => $fila['updated_at'] ?? null,
            'por_defecto'  => $porDefecto,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function todas(): array
    {
        $salida = [];
        foreach (self::codigos() as $codigo) {
            $salida[] = self::obtener($codigo);
        }
        return $salida;
    }

    /**
     * Guarda (o crea) la plantilla de un tipo.
     *
     * @return int identificador de la fila
     */
    public static function guardar(string $codigo, string $asunto, string $html, string $texto,
                                   bool $activa, ?int $actor): int
    {
        $existente = self::obtenerValor('SELECT id FROM mail_templates WHERE code = ?', [$codigo]);

        if ($existente === null) {
            return self::ejecutarInsert(
                'INSERT INTO mail_templates (code, subject, body_html, body_text, enabled, updated_by)
                 VALUES (?,?,?,?,?,?)',
                [$codigo, $asunto, $html === '' ? null : $html, $texto, $activa ? 1 : 0, $actor]
            );
        }

        self::ejecutarConsultaAfectadas(
            'UPDATE mail_templates
                SET subject = ?, body_html = ?, body_text = ?, enabled = ?, updated_by = ?
              WHERE code = ?',
            [$asunto, $html === '' ? null : $html, $texto, $activa ? 1 : 0, $actor, $codigo]
        );
        return (int) $existente;
    }

    /** Devuelve el tipo a su contenido de fabrica. */
    public static function restaurar(string $codigo): void
    {
        self::ejecutarConsultaAfectadas('DELETE FROM mail_templates WHERE code = ?', [$codigo]);
    }

    // -----------------------------------------------------------------
    //  Composicion
    // -----------------------------------------------------------------

    /**
     * Compone un mensaje.
     *
     * Si el tipo no tiene plantilla propia, o la tiene apagada, se usa el
     * contenido de fabrica: una plantilla a medio escribir no puede dejar
     * al sistema sin poder avisar de nada.
     *
     * @param array<string,string> $valores
     * @return array{subject:string,text:string,html:string|null}
     */
    public static function componer(string $codigo, array $valores): array
    {
        $ficha = self::obtener($codigo);
        if ($ficha === null) {
            throw new RuntimeException('Tipo de correo desconocido: ' . $codigo);
        }

        $usar = $ficha['enabled'] ? $ficha : ['subject' => $ficha['por_defecto']['subject'],
                                              'body_html' => $ficha['por_defecto']['body_html'],
                                              'body_text' => $ficha['por_defecto']['body_text']];

        $crudas = self::CATALOGO[$codigo]['crudas'];
        $html   = (string) $usar['body_html'];

        return [
            // El asunto es una cabecera: nunca lleva HTML.
            'subject' => self::sustituir((string) $usar['subject'], $valores, $crudas, false),
            'text'    => self::sustituir((string) $usar['body_text'], $valores, $crudas, false),
            'html'    => $html === '' ? null : self::sustituir($html, $valores, $crudas, true),
        ];
    }

    /**
     * Reemplaza {{variable}} por su valor.
     *
     * En HTML todo valor se escapa, salvo las variables declaradas como
     * crudas, que las compone el propio sistema. Una variable sin valor se
     * sustituye por cadena vacia en vez de dejar el {{hueco}} a la vista.
     *
     * @param array<string,string> $valores
     * @param array<int,string>    $crudas
     */
    private static function sustituir(string $plantilla, array $valores, array $crudas, bool $enHtml): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]{1,40})\s*\}\}/i',
            static function (array $m) use ($valores, $crudas, $enHtml): string {
                $clave = strtolower($m[1]);
                $valor = (string) ($valores[$clave] ?? '');
                if (!$enHtml || in_array($clave, $crudas, true)) {
                    return $valor;
                }
                return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $plantilla
        );
    }

    /**
     * Variables usadas en un texto que el tipo no reconoce.
     *
     * @return array<int,string>
     */
    public static function variablesDesconocidas(string $codigo, string ...$textos): array
    {
        $conocidas = array_keys(self::CATALOGO[$codigo]['variables'] ?? []);
        $usadas    = [];
        foreach ($textos as $texto) {
            if (preg_match_all('/\{\{\s*([a-z_]{1,40})\s*\}\}/i', $texto, $m) > 0) {
                foreach ($m[1] as $nombre) {
                    $usadas[] = strtolower($nombre);
                }
            }
        }
        return array_values(array_unique(array_diff($usadas, $conocidas)));
    }

    /**
     * Variables obligatorias que la plantilla se dejo fuera.
     *
     * Un correo de restablecimiento sin {{enlace}} no sirve de nada: el
     * destinatario recibe un mensaje que no le deja hacer nada.
     *
     * @return array<int,string>
     */
    public static function obligatoriasAusentes(string $codigo, string ...$textos): array
    {
        $faltan = [];
        foreach (self::CATALOGO[$codigo]['obligatorias'] ?? [] as $obligatoria) {
            $presente = false;
            foreach ($textos as $texto) {
                if ($texto !== '' && preg_match('/\{\{\s*' . preg_quote($obligatoria, '/') . '\s*\}\}/i', $texto) === 1) {
                    $presente = true;
                    break;
                }
            }
            if (!$presente) {
                $faltan[] = $obligatoria;
            }
        }
        return $faltan;
    }

    /**
     * Valores de muestra para la vista previa.
     *
     * @return array<string,string>
     */
    public static function ejemplo(string $codigo): array
    {
        $app = (string) Config::get('app.name', 'Gestion de Credenciales');
        $url = (string) Config::get('app.url', 'https://credenciales.empresa.local');

        return match ($codigo) {
            'password_reset' => [
                'app'     => $app,
                'nombre'  => 'Maria',
                'usuario' => 'maria.gomez',
                'enlace'  => $url . '/restablecer/ejemplo-de-enlace-de-un-solo-uso',
                'minutos' => '30',
                'fecha'   => date('d/m/Y H:i'),
            ],
            'alert_digest' => [
                'app'     => $app,
                'total'   => '3',
                'alertas' => self::alertasDeEjemplo(),
                'fecha'   => date('d/m/Y H:i'),
            ],
            default => [],
        };
    }

    private static function alertasDeEjemplo(): string
    {
        return self::alertasComoHtml([
            ['severity' => 'high',   'title' => 'Credenciales por vencer',  'message' => '2 credenciales vencen en los proximos 15 dias.'],
            ['severity' => 'medium', 'title' => 'Credencial sin responsable', 'message' => '1 credencial no tiene a nadie asignado.'],
            ['severity' => 'low',    'title' => 'Intentos fallidos',        'message' => '12 intentos fallidos en la ultima hora.'],
        ]);
    }

    /**
     * Compone el listado de alertas como HTML.
     *
     * Los estilos van en linea porque los clientes de correo descartan las
     * hojas de estilo.
     *
     * @param array<int,array<string,mixed>> $alertas
     */
    public static function alertasComoHtml(array $alertas): string
    {
        $colores = ['critical' => '#b42318', 'high' => '#b54708', 'medium' => '#854708', 'low' => '#475467'];
        $filas   = '';
        foreach ($alertas as $alerta) {
            $sev   = strtolower((string) ($alerta['severity'] ?? 'low'));
            $color = $colores[$sev] ?? '#475467';
            $filas .= sprintf(
                '<tr><td style="padding:10px 0;border-bottom:1px solid #eaecf0">'
                . '<span style="display:inline-block;font:600 11px/1.6 Arial,sans-serif;color:%s;'
                . 'text-transform:uppercase;letter-spacing:.04em">%s</span><br>'
                . '<strong style="font:600 15px/1.5 Arial,sans-serif;color:#101828">%s</strong><br>'
                . '<span style="font:14px/1.5 Arial,sans-serif;color:#475467">%s</span></td></tr>',
                $color,
                htmlspecialchars($sev, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) ($alerta['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) ($alerta['message'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }
        return '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">' . $filas . '</table>';
    }

    /**
     * Listado de alertas en texto plano.
     *
     * @param array<int,array<string,mixed>> $alertas
     */
    public static function alertasComoTexto(array $alertas): string
    {
        $salida = '';
        foreach ($alertas as $alerta) {
            $salida .= sprintf("[%s] %s\n    %s\n\n",
                strtoupper((string) ($alerta['severity'] ?? '')),
                (string) ($alerta['title'] ?? ''),
                (string) ($alerta['message'] ?? ''));
        }
        return rtrim($salida);
    }

    // -----------------------------------------------------------------
    //  Contenido de fabrica
    // -----------------------------------------------------------------

    /** @return array{subject:string,body_html:string,body_text:string} */
    public static function porDefecto(string $codigo): array
    {
        return match ($codigo) {
            'password_reset' => [
                'subject'   => '[{{app}}] Restablecimiento de contrasena',
                'body_text' => "Hola {{nombre}}:\n\n"
                             . "Recibimos una solicitud para restablecer su contrasena de acceso a {{app}}.\n\n"
                             . "Abra el siguiente enlace (valido por {{minutos}} minutos y de un solo uso):\n"
                             . "{{enlace}}\n\n"
                             . "Si usted no realizo esta solicitud, ignore este mensaje y avise al administrador.\n\n"
                             . "Por seguridad, este sistema nunca envia contrasenas por correo electronico.",
                'body_html' => self::marco(
                    '{{app}}',
                    '<p style="margin:0 0 16px">Hola <strong>{{nombre}}</strong>:</p>'
                    . '<p style="margin:0 0 16px">Recibimos una solicitud para restablecer su contrasena de acceso.</p>'
                    . '<p style="margin:0 0 24px"><a href="{{enlace}}" style="display:inline-block;padding:12px 22px;'
                    . 'background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600">'
                    . 'Restablecer mi contrasena</a></p>'
                    . '<p style="margin:0 0 16px;color:#475467">El enlace es de un solo uso y caduca en '
                    . '{{minutos}} minutos. Si el boton no funciona, copie esta direccion:<br>'
                    . '<span style="word-break:break-all">{{enlace}}</span></p>'
                    . '<p style="margin:0;color:#475467">Si usted no realizo esta solicitud, ignore este mensaje '
                    . 'y avise al administrador.</p>',
                    'Por seguridad, este sistema nunca envia contrasenas por correo electronico.'
                ),
            ],
            'alert_digest' => [
                'subject'   => '[{{app}}] Alertas de seguridad',
                'body_text' => "Resumen de alertas de {{app}} - {{fecha}}\n\n"
                             . "{{alertas}}\n\n"
                             . "Ingrese al sistema para revisar el detalle.",
                'body_html' => self::marco(
                    '{{app}}',
                    '<p style="margin:0 0 8px"><strong>Resumen de alertas</strong></p>'
                    . '<p style="margin:0 0 20px;color:#475467">{{total}} aviso(s) · {{fecha}}</p>'
                    . '{{alertas}}'
                    . '<p style="margin:24px 0 0;color:#475467">Ingrese al sistema para revisar el detalle.</p>',
                    'Mensaje automatico. No responda a este correo.'
                ),
            ],
            default => ['subject' => '', 'body_html' => '', 'body_text' => ''],
        };
    }

    /**
     * Envoltura comun de los correos de fabrica.
     *
     * Tabla de una columna con estilos en linea: es lo unico que dibujan
     * igual Gmail, Outlook y el resto.
     */
    private static function marco(string $titulo, string $contenido, string $pie): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
             . 'style="background:#f2f4f7;padding:24px 0">'
             . '<tr><td align="center">'
             . '<table role="presentation" cellpadding="0" cellspacing="0" width="600" '
             . 'style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden">'
             . '<tr><td style="padding:20px 32px;background:#101828">'
             . '<span style="font:600 16px/1.5 Arial,sans-serif;color:#ffffff">' . $titulo . '</span>'
             . '</td></tr>'
             . '<tr><td style="padding:32px;font:15px/1.6 Arial,sans-serif;color:#101828">'
             . $contenido
             . '</td></tr>'
             . '<tr><td style="padding:16px 32px;background:#f9fafb;font:12px/1.5 Arial,sans-serif;color:#667085">'
             . $pie
             . '</td></tr>'
             . '</table></td></tr></table>';
    }
}
