<?php
declare(strict_types=1);

namespace app\models;

/**
 * Registro de vistas (patron de Porcify Manager).
 *
 * Declara, en un unico sitio, que archivo atiende cada direccion y que
 * hojas de estilo y guiones necesita. La referencia indexa por un solo
 * segmento de URL; aqui la clave es la ruta completa con los
 * identificadores normalizados a {id}, porque este sistema tiene recursos
 * anidados (/credenciales/12/historial) y aplanarlos cambiaria las
 * direcciones que ya usa la gente.
 */
class viewsModel
{
    /** Hojas presentes en todas las vistas. */
    private const CSS_COMPARTIDA = [
        'global.css',
        'sidebar.css',
        'topbar.css',
        'tables.css',
        'modals.css',
    ];

    /** Guiones presentes en todas las vistas. */
    private const JS_COMPARTIDO = [
        'global.js',
    ];

    /** @var array<string,array<int,string>> */
    private const CSS_POR_VISTA = [
        'entrar'                => ['login.css'],
        'recuperar'             => ['login.css'],
        'restablecer'           => ['login.css'],
        'mfa'                   => ['login.css'],
        'credenciales-detalle'  => ['secretos.css'],
        'credenciales-historial'=> ['secretos.css'],
        'mis-accesos'           => ['secretos.css'],
        'perfil-mfa'            => ['login.css'],
        'error'                 => [],
    ];

    /** @var array<string,array<int,string>> */
    private const JS_POR_VISTA = [
        'panel'                  => ['busqueda.js'],
        'mis-accesos'            => ['secretos.js', 'busqueda.js'],
        'credenciales'           => ['busqueda.js'],
        'credenciales-detalle'   => ['secretos.js', 'busqueda.js'],
        'credenciales-historial' => ['secretos.js', 'busqueda.js'],
        'credenciales-formulario'=> ['generador.js', 'busqueda.js'],
        'importar'               => ['busqueda.js'],
        'sistemas'               => ['busqueda.js'],
        'sistemas-detalle'       => ['busqueda.js'],
        'sistemas-formulario'    => ['busqueda.js'],
        'usuarios'               => ['busqueda.js'],
        'usuarios-detalle'       => ['busqueda.js'],
        'usuarios-formulario'    => ['busqueda.js'],
        'perfil'                 => ['busqueda.js'],
        'perfil-contrasena'      => ['generador.js', 'busqueda.js'],
        'perfil-mfa'             => ['busqueda.js'],
        'notificaciones'         => ['busqueda.js'],
        'auditoria'              => ['busqueda.js'],
        'seguridad-eventos'      => ['busqueda.js'],
        'sesiones'               => ['busqueda.js'],
        'reportes'               => ['reportes.js', 'busqueda.js'],
        'reportes-historial'     => ['busqueda.js'],
        'admin-categorias'       => ['busqueda.js'],
        'admin-organizacion'     => ['busqueda.js'],
        'admin-roles'            => ['busqueda.js'],
        'admin-configuracion'    => ['busqueda.js'],
    ];

    /**
     * Direcciones publicas: no exigen sesion y se dibujan sin el menu.
     *
     * @var array<string,string>
     */
    private const VISTAS_PUBLICAS = [
        '/entrar'             => 'entrar',
        '/recuperar'          => 'recuperar',
        '/restablecer/{token}'=> 'restablecer',
        '/mfa'                => 'mfa',
    ];

    /**
     * Direcciones de la aplicacion: exigen sesion y llevan el menu.
     *
     * @var array<string,string>
     */
    private const VISTAS_PRIVADAS = [
        '/'                              => 'panel',
        '/mis-accesos'                   => 'mis-accesos',

        '/credenciales'                  => 'credenciales',
        '/credenciales/nueva'            => 'credenciales-formulario',
        '/credenciales/{id}'             => 'credenciales-detalle',
        '/credenciales/{id}/editar'      => 'credenciales-formulario',
        '/credenciales/{id}/historial'   => 'credenciales-historial',
        '/importar'                      => 'importar',

        '/sistemas'                      => 'sistemas',
        '/sistemas/nuevo'                => 'sistemas-formulario',
        '/sistemas/{id}'                 => 'sistemas-detalle',
        '/sistemas/{id}/editar'          => 'sistemas-formulario',

        '/usuarios'                      => 'usuarios',
        '/usuarios/nuevo'                => 'usuarios-formulario',
        '/usuarios/{id}'                 => 'usuarios-detalle',
        '/usuarios/{id}/editar'          => 'usuarios-formulario',

        '/perfil'                        => 'perfil',
        '/perfil/contrasena'             => 'perfil-contrasena',
        '/perfil/mfa'                    => 'perfil-mfa',
        '/notificaciones'                => 'notificaciones',

        '/auditoria'                     => 'auditoria',
        '/seguridad/eventos'             => 'seguridad-eventos',
        '/sesiones'                      => 'sesiones',

        '/reportes'                      => 'reportes',
        '/reportes/historial'            => 'reportes-historial',

        '/admin/categorias'              => 'admin-categorias',
        '/admin/organizacion'            => 'admin-organizacion',
        '/admin/roles'                   => 'admin-roles',
        '/admin/configuracion'           => 'admin-configuracion',
    ];

    /**
     * Resuelve la ruta solicitada.
     *
     * @return array{
     *   vista:string, ruta:string, publica:bool, encontrada:bool,
     *   parametros:array<int,string>, css:array<int,string>, js:array<int,string>,
     *   cssCompartida:array<int,string>, jsCompartido:array<int,string>
     * }
     */
    protected static function obtenerVistasModelo(string $rutaSolicitada): array
    {
        [$patron, $parametros] = self::normalizar($rutaSolicitada);

        $publica = isset(self::VISTAS_PUBLICAS[$patron]);
        $vista   = self::VISTAS_PUBLICAS[$patron] ?? (self::VISTAS_PRIVADAS[$patron] ?? null);

        $encontrada = $vista !== null;
        if (!$encontrada) {
            // Una direccion desconocida no revela si existe o no: se dibuja
            // el error dentro del marco habitual, con sesion si la hay.
            $vista   = 'error';
            $publica = !contextoModel::autenticado();
        }

        return [
            'vista'         => $vista,
            'ruta'          => 'app/views/content/' . $vista . '-view.php',
            'publica'       => $publica,
            'encontrada'    => $encontrada,
            'parametros'    => $parametros,
            'css'           => self::CSS_POR_VISTA[$vista] ?? [],
            'js'            => self::JS_POR_VISTA[$vista] ?? [],
            'cssCompartida' => self::CSS_COMPARTIDA,
            'jsCompartido'  => self::JS_COMPARTIDO,
        ];
    }

    /**
     * Convierte /credenciales/12/historial en /credenciales/{id}/historial y
     * devuelve aparte los valores encontrados.
     *
     * @return array{0:string,1:array<int,string>}
     */
    private static function normalizar(string $ruta): array
    {
        $ruta = '/' . trim($ruta, '/');
        if ($ruta === '/') {
            return ['/', []];
        }

        $parametros = [];
        $segmentos  = array_map(static function (string $segmento) use (&$parametros): string {
            if ($segmento !== '' && ctype_digit($segmento)) {
                $parametros[] = $segmento;
                return '{id}';
            }
            // Los identificadores hexadecimales largos (sesiones, tokens de
            // restablecimiento) tampoco forman parte del patron.
            if (preg_match('/^[a-f0-9]{32,64}$/', $segmento) === 1) {
                $parametros[] = $segmento;
                return '{token}';
            }
            return $segmento;
        }, explode('/', ltrim($ruta, '/')));

        return ['/' . implode('/', $segmentos), $parametros];
    }
}
