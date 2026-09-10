<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\CsrfException;
use App\Core\Flash;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\ReauthRequiredException;
use App\Core\ValidationException;
use app\middlewares\accesoMiddleware;
use app\models\auditoriaModel;
use app\models\autenticacionModel;
use app\models\contextoModel;
use app\models\limitadorModel;
use app\models\correoModel;
use app\models\sesionModel;
use app\models\viewsModel;
use Throwable;

/**
 * Despacho de las escrituras que llegan por formulario clasico.
 *
 * Porcify Manager resuelve todas sus operaciones por fetch contra
 * app/api/*-api.php. Aqui se conserva ademas el envio de formulario de
 * toda la vida, por una razon concreta: el sistema funciona hoy sin
 * JavaScript y la instruccion es que el front end siga comportandose
 * igual. Un formulario que solo funciona con fetch deja de enviarse si el
 * guion no carga.
 *
 * La logica NO se duplica: cada caso llama al mismo controlador que usa
 * su endpoint JSON. Aqui solo se traduce el resultado a "mensaje +
 * redireccion", que es lo que espera un formulario.
 */
class despachoController extends baseController
{
    /** Rutas que no exigen sesion. */
    private const PUBLICAS = ['/entrar', '/recuperar', '/restablecer', '/mfa/verificar'];

    /**
     * Pagina a la que se devuelve al usuario cuando el envio se rechaza.
     *
     * NO se deduce del Referer: la aplicacion envia
     * `Referrer-Policy: no-referrer`, asi que el navegador no lo manda y
     * el usuario acababa en el panel sin entender que habia pasado. Cada
     * ruta declara aqui de donde viene su formulario.
     */
    private static string $origen = '/';

    /**
     * Atiende la peticion y termina. No devuelve nunca.
     *
     * @param array<string,mixed> $cuerpo
     * @param array<string,mixed> $archivos
     */
    public static function despachar(string $ruta, array $cuerpo, array $archivos): never
    {
        // Un formulario HTML solo sabe enviar GET y POST. El campo _method
        // que usaban las rutas REST ya no se interpreta aqui: PUT, PATCH y
        // DELETE viven en los endpoints, que si los reciben de verdad.
        $simulado = strtoupper((string) ($cuerpo['_method'] ?? 'POST'));
        if ($simulado !== 'POST') {
            self::paginaError(405, 'Metodo no permitido',
                'El metodo ' . $simulado . ' no se admite en este formulario.');
        }

        [$patron, $parametros] = viewsModel::patron($ruta);
        self::$origen = self::origenDelFormulario($patron, $parametros);

        if (!in_array($patron, self::PUBLICAS, true) && !contextoModel::autenticado()) {
            self::error('Su sesion expiro. Vuelva a iniciar sesion.');
            self::salir('/entrar');
        }

        try {
            self::limitar();
            self::exigirCsrf($ruta);
            self::ejecutar($patron, $parametros, $cuerpo, $archivos);
        } catch (CsrfException $e) {
            // Marcador explicito: Apache reescribe el 419 no estandar como
            // 500, asi que el fallo de CSRF viaja como 403 identificado.
            header('X-Csrf-Failure: 1');
            self::paginaError(403, 'Sesion expirada', $e->getMessage());
        } catch (ValidationException $e) {
            // Los datos del formulario vuelven corregibles a la pantalla
            // de origen, con el detalle de cada campo.
            Flash::set('errors', $e->errors());
            self::error(implode(' ', $e->errors()));
            self::salir(self::$origen);
        } catch (ReauthRequiredException $e) {
            self::paginaError(423, 'Confirmacion requerida', $e->getMessage());
        } catch (HttpException $e) {
            if ($e->statusCode() >= 500) {
                Logger::error('Fallo al procesar el formulario ' . $patron, ['error' => $e->getMessage()]);
            }
            self::paginaError($e->statusCode(), self::tituloError($e->statusCode()), $e->getMessage());
        } catch (Throwable $e) {
            Logger::error('Fallo al procesar el formulario ' . $patron, ['error' => $e->getMessage()]);
            self::paginaError(500, 'Error del sistema',
                'Ocurrio un error inesperado. El incidente quedo registrado.');
        }
    }

    // =================================================================
    //  Reparto por ruta
    // =================================================================

    /**
     * @param array<int,string>   $p  parametros de la ruta
     * @param array<string,mixed> $c  cuerpo de la peticion
     * @param array<string,mixed> $f  archivos subidos
     */
    private static function ejecutar(string $patron, array $p, array $c, array $f): never
    {
        $id  = (int) ($p[0] ?? 0);
        $id2 = (int) ($p[1] ?? 0);

        switch ($patron) {

            // ------------------------- Acceso -------------------------
            case '/entrar':          self::ingresar($c);
            case '/mfa/verificar':   self::verificarMfa($c);
            case '/salir':           self::cerrarSesion();
            case '/recuperar':       self::solicitarRecuperacion($c);
            case '/restablecer':     self::restablecer($c);

            // ------------------------- Perfil -------------------------
            case '/perfil/contrasena':
                self::resultado(perfilController::cambiarContrasenaController($c),
                    'Contrasena actualizada. Se cerraron sus otras sesiones.', '/');

            case '/perfil/mfa/iniciar':
                $r = perfilController::iniciarMfaController();
                if (($r['status'] ?? '') === 'success') {
                    // El secreto solo existe hasta que se confirma: viaja en
                    // un mensaje de un solo uso, no se guarda a medio hacer.
                    Flash::set('mfa_enrollment', $r['data']);
                }
                self::resultado($r, null, '/perfil/mfa');

            case '/perfil/mfa/confirmar':
                $r = perfilController::confirmarMfaController($c);
                if (($r['status'] ?? '') === 'success') {
                    // Los codigos de respaldo se muestran una unica vez.
                    Flash::set('backup_codes', $r['data']['backup_codes']);
                }
                self::resultado($r, 'Verificacion en dos pasos activada. Guarde sus codigos de respaldo.', '/perfil/mfa');

            case '/perfil/mfa/desactivar':
                self::resultado(perfilController::desactivarMfaController($c),
                    'Verificacion en dos pasos desactivada.', '/perfil/mfa');

            case '/notificaciones/{id}/leida':
                self::resultado(perfilController::marcarLeidaController($id), null, '/notificaciones');

            // ---------------------- Credenciales ----------------------
            case '/credenciales':
                $r = credencialController::agregarController($c);
                self::resultado($r, 'Credencial registrada correctamente.',
                    '/credenciales/' . (int) ($r['data']['id'] ?? 0));

            case '/credenciales/{id}':
                self::resultado(credencialController::actualizarController($id, $c),
                    'Credencial actualizada.', '/credenciales/' . $id);

            case '/credenciales/{id}/rotar':
                $r = credencialController::rotarController($id, $c);
                self::resultado($r, 'Contrasena actualizada (version ' . (int) ($r['data']['version'] ?? 0) . ').',
                    '/credenciales/' . $id);

            case '/credenciales/{id}/eliminar':
                self::resultado(credencialController::eliminarController($id, $c),
                    'Credencial dada de baja. El historial se conserva.', '/credenciales');

            case '/credenciales/{id}/restaurar':
                self::resultado(credencialController::restaurarController($id),
                    'Credencial reactivada.', '/credenciales/' . $id);

            case '/credenciales/{id}/asignar':
                self::resultado(credencialController::asignarController($id, $c),
                    'Acceso asignado.', '/credenciales/' . $id);

            case '/credenciales/{id}/revocar/{id}':
                self::resultado(credencialController::revocarController($id, $id2, $c),
                    'Acceso revocado.', '/credenciales/' . $id);

            // ------------------------ Sistemas ------------------------
            case '/sistemas':
                $r = sistemaController::agregarController($c);
                self::resultado($r, 'Sistema registrado.', '/sistemas/' . (int) ($r['data']['id'] ?? 0));

            case '/sistemas/{id}':
                self::resultado(sistemaController::actualizarController($id, $c),
                    'Sistema actualizado.', '/sistemas/' . $id);

            case '/sistemas/{id}/archivar':
                self::resultado(sistemaController::archivarController($id, $c),
                    'Sistema archivado.', '/sistemas');

            // ------------------------ Usuarios ------------------------
            case '/usuarios':
                $r = usuarioController::agregarController($c);
                if (($r['status'] ?? '') === 'success') {
                    // Se muestra una unica vez, para entregarla en persona.
                    Flash::set('temporary_password', $r['data']['temporary_password']);
                }
                self::resultado($r, 'Usuario creado.', '/usuarios/' . (int) ($r['data']['id'] ?? 0));

            case '/usuarios/{id}':
                self::resultado(usuarioController::actualizarController($id, $c),
                    'Usuario actualizado.', '/usuarios/' . $id);

            case '/usuarios/{id}/permisos':
                self::resultado(usuarioController::permisosController($id, $c),
                    'Permisos actualizados. Se cerraron las sesiones del usuario.', '/usuarios/' . $id);

            case '/usuarios/{id}/desactivar':
                $r = usuarioController::desactivarController($id, $c);
                self::resultado($r, sprintf(
                    'Usuario desactivado. Accesos revocados: %d. Reasignados: %d. Sesiones cerradas: %d.',
                    (int) ($r['data']['revoked'] ?? 0),
                    (int) ($r['data']['reassigned'] ?? 0),
                    (int) ($r['data']['sessions_closed'] ?? 0)
                ), '/usuarios/' . $id);

            case '/usuarios/{id}/reactivar':
                self::resultado(usuarioController::reactivarController($id),
                    'Usuario reactivado.', '/usuarios/' . $id);

            case '/usuarios/{id}/restablecer':
                $r = usuarioController::restablecerController($id);
                if (($r['status'] ?? '') === 'success') {
                    Flash::set('temporary_password', $r['data']['temporary_password']);
                }
                self::resultado($r, 'Contrasena restablecida.', '/usuarios/' . $id);

            // ------------------------ Seguridad -----------------------
            case '/seguridad/eventos/{id}/resolver':
                self::resultado(auditoriaController::resolverEventoController($id),
                    'Evento marcado como resuelto.', '/seguridad/eventos');

            case '/sesiones/{token}/cerrar':
                self::resultado(sesionController::revocarController((string) ($p[0] ?? ''), $c),
                    'Sesion cerrada remotamente.', '/sesiones');

            case '/sesiones/usuario/{id}/cerrar':
                $r = sesionController::revocarUsuarioController($id);
                self::resultado($r, ((int) ($r['data']['closed'] ?? 0)) . ' sesion(es) cerradas.', '/sesiones');

            // ------------------------ Reportes ------------------------
            case '/reportes/generar':  self::generarReporte($c);
            case '/reportes/seleccion': self::json(reporteController::seleccionController($c));

            // ----------------------- Importacion ----------------------
            case '/importar/previa':
                $r = importacionController::previsualizarController($f);
                if (($r['status'] ?? '') === 'success') {
                    Flash::set('import_preview', $r['data']['preview']);
                    Flash::set('import_payload', $r['data']['payload']);
                }
                self::resultado($r, null, '/importar');

            case '/importar/ejecutar':
                $r = importacionController::ejecutarController($c);
                self::resultado($r, sprintf(
                    'Importacion finalizada. Credenciales creadas: %d. Omitidas: %d.',
                    (int) ($r['data']['created'] ?? 0),
                    (int) ($r['data']['skipped'] ?? 0)
                ), '/credenciales');

            // ---------------------- Administracion --------------------
            case '/admin/categorias':
                $r = catalogoController::guardarCategoriaController($c);
                self::resultado($r, 'Categoria ' . ($r['data']['accion'] ?? 'guardada') . '.', '/admin/categorias');

            case '/admin/empresas':
                self::resultado(catalogoController::guardarEmpresaController($c),
                    'Empresa guardada.', '/admin/organizacion');

            case '/admin/sedes':
                self::resultado(catalogoController::guardarSedeController($c),
                    'Sede guardada.', '/admin/organizacion');

            case '/admin/departamentos':
                self::resultado(catalogoController::guardarDepartamentoController($c),
                    'Departamento guardado.', '/admin/organizacion');

            case '/admin/roles':
                self::resultado(catalogoController::guardarRolController($c),
                    'Rol guardado.', '/admin/roles');

            case '/admin/roles/{id}/permisos':
                self::resultado(catalogoController::permisosRolController($id, $c),
                    'Matriz de permisos actualizada.', '/admin/roles');

            case '/admin/configuracion':
                self::resultado(catalogoController::guardarConfiguracionController($c),
                    'Configuracion actualizada.', '/admin/configuracion');

            default:
                // Direccion valida para leer pero no para escribir (o
                // inexistente): no se dice cual de las dos.
                self::paginaError(404, 'No encontrado', 'La direccion solicitada no existe.');
        }
    }

    // =================================================================
    //  Casos con tratamiento propio
    // =================================================================

    /** @param array<string,mixed> $c */
    private static function ingresar(array $c): never
    {
        $r = loginController::ingresarController($c);
        $d = $r['data'] ?? [];

        // Credenciales incorrectas: se vuelve al formulario con el aviso.
        // Cualquier otro rechazo (limitador, cuenta bloqueada) se corta con
        // su propio codigo para que quede claro que no es un fallo de tecleo.
        if (($d['status'] ?? '') === 'error') {
            self::error((string) ($d['message'] ?? 'No fue posible iniciar sesion.'));
            self::salir('/entrar');
        }
        if (($r['status'] ?? '') !== 'success') {
            $codigo = (int) ($r['code'] ?? 401);
            self::paginaError($codigo, self::tituloError($codigo), (string) $r['message']);
        }

        self::fijarCookieSesion((string) $d['token']);

        if (($d['status'] ?? '') === 'mfa_setup_required') {
            Flash::set('info', 'Su rol exige verificacion en dos pasos. Configurela para continuar.');
        }

        self::salir(match ((string) $d['status']) {
            'mfa_required'       => '/mfa',
            'mfa_setup_required' => '/perfil/mfa',
            default              => ($d['must_change_password'] ?? false)
                                    ? '/perfil/contrasena'
                                    : (self::destinoSeguro((string) ($c['redirect'] ?? '')) ?: '/'),
        });
    }

    /** @param array<string,mixed> $c */
    private static function verificarMfa(array $c): never
    {
        $token  = (string) ($_COOKIE[sesionModel::COOKIE] ?? '');
        $sesion = $token !== '' ? sesionModel::resolver($token) : null;
        if ($sesion === null) {
            self::salir('/entrar');
        }

        $r = loginController::verificarMfaController($sesion, $c);
        $d = $r['data'] ?? [];

        if (($d['status'] ?? '') === 'error') {
            self::error((string) ($d['message'] ?? 'Codigo invalido.'));
            self::salir('/mfa');
        }
        if (($d['status'] ?? '') !== 'ok') {
            $codigo = (int) ($r['code'] ?? 401);
            self::paginaError($codigo, self::tituloError($codigo), (string) $r['message']);
        }

        self::fijarCookieSesion((string) $d['token']);
        self::salir('/');
    }

    private static function cerrarSesion(): never
    {
        loginController::salirController();
        self::fijarCookieSesion('', time() - 3600);
        self::salir('/entrar');
    }

    /** @param array<string,mixed> $c */
    private static function solicitarRecuperacion(array $c): never
    {
        $resultado = autenticacionModel::requestPasswordReset(
            (string) ($c['identifier'] ?? ''),
            contextoModel::ip()
        );

        if ($resultado !== null) {
            $enlace = (string) Config::get('app.url') . '/restablecer/' . $resultado['token'];
            correoModel::sendPasswordResetLink(
                (string) $resultado['user']['email'],
                (string) $resultado['user']['first_name'],
                $enlace
            );
            // En desarrollo se muestra el enlace para poder probar el flujo
            // sin servidor de correo configurado.
            if ((bool) Config::get('app.debug', false) && !correoModel::enabled()) {
                Flash::set('info', 'Enlace de recuperacion (solo modo desarrollo): ' . $enlace);
            }
        }

        // Respuesta identica exista o no la cuenta: no se filtra informacion.
        self::exito('Si el identificador corresponde a una cuenta activa, recibira un correo con las instrucciones.');
        self::salir('/entrar');
    }

    /** @param array<string,mixed> $c */
    private static function restablecer(array $c): never
    {
        $token = (string) ($c['token'] ?? '');
        try {
            autenticacionModel::completePasswordReset(
                $token,
                (string) ($c['password'] ?? ''),
                (string) ($c['password_confirmation'] ?? '')
            );
        } catch (ValidationException $e) {
            Flash::set('errors', $e->errors());
            self::error(implode(' ', $e->errors()));
            self::salir('/restablecer/' . rawurlencode($token));
        }
        self::exito('Su contrasena se actualizo correctamente. Ya puede iniciar sesion.');
        self::salir('/entrar');
    }

    /** @param array<string,mixed> $c */
    private static function generarReporte(array $c): never
    {
        $r = reporteController::generarController($c);

        if (self::quiereJson()) {
            self::json($r);
        }
        if (($r['status'] ?? '') !== 'success') {
            $codigo = (int) ($r['code'] ?? 400);
            self::paginaError($codigo, self::tituloError($codigo), (string) $r['message']);
        }

        $d = $r['data'];
        Flash::set('export_ready', $d);
        self::exito(sprintf(
            'Reporte generado con %d registro(s).%s',
            (int) $d['record_count'],
            $d['included_secrets'] ? ' ATENCION: contiene contrasenas reales.' : ''
        ));
        self::salir('/reportes/descargar/' . $d['uuid']);
    }

    // =================================================================
    //  Utilidades
    // =================================================================

    /**
     * Traduce la respuesta de un controlador a mensaje + redireccion.
     *
     * @param array<string,mixed> $r
     */
    private static function resultado(array $r, ?string $mensaje, string $destino): never
    {
        $codigo = (int) ($r['code'] ?? 400);
        if (($r['status'] ?? '') !== 'success') {
            // Un dato mal escrito vuelve al formulario; una operacion no
            // permitida se corta con su propio codigo.
            if (isset($r['errors']) && is_array($r['errors'])) {
                Flash::set('errors', $r['errors']);
                self::error((string) $r['message']);
                self::salir(self::$origen);
            }
            self::paginaError($codigo, self::tituloError($codigo), (string) $r['message']);
        }
        if ($mensaje !== null) {
            self::exito($mensaje);
        }
        self::salir($destino);
    }

    /** @param array<string,mixed> $r */
    private static function json(array $r): never
    {
        http_response_code((int) ($r['code'] ?? 200));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function salir(string $destino): never
    {
        Flash::guardarCookie();
        header('Location: ' . url($destino), true, 302);
        exit;
    }

    /** Dibuja la pagina de error con su codigo y termina. */
    private static function paginaError(int $estado, string $titulo, string $mensaje): never
    {
        Flash::guardarCookie();
        http_response_code($estado);

        $errorEstado  = $estado;
        $errorTitulo  = $titulo;
        $errorMensaje = $mensaje;
        extract(\App\Core\View::sharedData(), EXTR_SKIP);
        require dirname(__DIR__) . '/views/inc/error-minimo.php';
        exit;
    }

    private static function tituloError(int $estado): string
    {
        return match ($estado) {
            400     => 'Solicitud invalida',
            401     => 'Sesion requerida',
            403     => 'Acceso denegado',
            404     => 'No encontrado',
            405     => 'Metodo no permitido',
            409     => 'Operacion no disponible',
            422     => 'Datos invalidos',
            423     => 'Confirmacion requerida',
            429     => 'Demasiadas solicitudes',
            default => 'Error del sistema',
        };
    }

    /**
     * Limitador general de peticiones por IP y sesion.
     *
     * Es un techo amplio contra la automatizacion. Los frenos estrechos
     * (acceso, MFA, recuperacion) viven en autenticacionModel, junto a la
     * operacion que protegen, y son los que cortan la fuerza bruta.
     */
    private static function limitar(): void
    {
        $limites = (array) Config::get('security.rate_limits.global', ['max' => 600, 'window' => 60]);
        $cubo    = 'global:' . contextoModel::ip() . ':'
                 . substr((string) ($_COOKIE[sesionModel::COOKIE] ?? ''), 0, 16);

        if (!limitadorModel::intentar($cubo, (int) $limites['max'], (int) $limites['window'])) {
            throw HttpException::tooManyRequests();
        }
    }

    /**
     * Pagina de la que viene cada formulario.
     *
     * @param array<int,string> $p parametros de la ruta
     */
    private static function origenDelFormulario(string $patron, array $p): string
    {
        $id  = (int) ($p[0] ?? 0);

        return match ($patron) {
            // Altas: se vuelve al formulario vacio, con lo escrito perdido
            // pero con el motivo del rechazo a la vista.
            '/credenciales'  => '/credenciales/nueva',
            '/sistemas'      => '/sistemas/nuevo',
            '/usuarios'      => '/usuarios/nuevo',

            // Ediciones: al formulario del registro que se intentaba tocar.
            '/credenciales/{id}' => '/credenciales/' . $id . '/editar',
            '/sistemas/{id}'     => '/sistemas/' . $id . '/editar',
            '/usuarios/{id}'     => '/usuarios/' . $id . '/editar',

            // Acciones sobre un registro concreto: a su ficha.
            '/credenciales/{id}/rotar',
            '/credenciales/{id}/eliminar',
            '/credenciales/{id}/restaurar',
            '/credenciales/{id}/asignar',
            '/credenciales/{id}/revocar/{id}' => '/credenciales/' . $id,
            '/sistemas/{id}/archivar'         => '/sistemas/' . $id,
            '/usuarios/{id}/permisos',
            '/usuarios/{id}/desactivar',
            '/usuarios/{id}/reactivar',
            '/usuarios/{id}/restablecer'      => '/usuarios/' . $id,

            '/notificaciones/{id}/leida'      => '/notificaciones',
            '/seguridad/eventos/{id}/resolver'=> '/seguridad/eventos',
            '/sesiones/{token}/cerrar',
            '/sesiones/usuario/{id}/cerrar'   => '/sesiones',

            '/perfil/mfa/iniciar',
            '/perfil/mfa/confirmar',
            '/perfil/mfa/desactivar'          => '/perfil/mfa',

            '/admin/empresas',
            '/admin/sedes',
            '/admin/departamentos'            => '/admin/organizacion',
            '/admin/roles/{id}/permisos'      => '/admin/roles',

            '/importar/previa',
            '/importar/ejecutar'              => '/importar',

            '/reportes/generar',
            '/reportes/seleccion'             => '/reportes',

            // El resto envia a la misma pagina en la que vive su formulario
            // (/perfil/contrasena, /admin/categorias, /admin/roles,
            //  /admin/configuracion, /entrar, /recuperar…).
            default => $patron,
        };
    }

    private static function destinoSeguro(string $ruta): string
    {
        if ($ruta === '' || !str_starts_with($ruta, '/') || str_starts_with($ruta, '//')) {
            return '';
        }
        return preg_match('#^/[A-Za-z0-9/_\-]*$#', $ruta) === 1 ? $ruta : '';
    }

    private static function quiereJson(): bool
    {
        $acepta = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($acepta, 'application/json')
            && !str_contains($acepta, 'text/html');
    }

    private static function fijarCookieSesion(string $token, int $expira = 0): void
    {
        setcookie(sesionModel::COOKIE, $token, [
            'expires'  => $expira,
            'path'     => (string) Config::get('app.base_path', '') . '/',
            'secure'   => (bool) Config::get('session.cookie_secure', false),
            'httponly' => true,
            'samesite' => (string) Config::get('session.cookie_samesite', 'Strict'),
        ]);
    }

    private static function exito(string $mensaje): void { Flash::set('success', $mensaje); }
    private static function error(string $mensaje): void { Flash::set('error', $mensaje); }

    /**
     * Proteccion CSRF de toda escritura.
     *
     * Tres capas, igual que antes: token sincronizador ligado a la sesion,
     * comprobacion del Origin/Referer y cookie SameSite=Strict. Para los
     * formularios publicos el token viaja en su propia cookie (double
     * submit), porque todavia no hay sesion a la que ligarlo.
     */
    private static function exigirCsrf(string $ruta): void
    {
        if (in_array($ruta, (array) Config::get('security.csrf_exempt', []), true)) {
            return;
        }

        $esperado = accesoMiddleware::tokenCsrfEsperado();
        $recibido = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');

        if ($esperado === null || !is_string($recibido) || $recibido === ''
            || !hash_equals($esperado, $recibido)) {
            auditoriaModel::registrar('security.csrf_failed', null, null, $ruta, 'denied',
                ['metodo' => contextoModel::metodo()], 'critical');
            throw new CsrfException();
        }

        if (!self::origenConfiable()) {
            auditoriaModel::registrar('security.csrf_origin_failed', null, null, $ruta, 'denied',
                ['origen' => $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '')], 'critical');
            throw new CsrfException('El origen de la solicitud no es valido.');
        }
    }


    private static function origenConfiable(): bool
    {
        $origen = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');

        // Sin Origin/Referer, o con un origen opaco ("null", que envian los
        // contextos aislados y algunas configuraciones de privacidad), se
        // acepta apoyandose UNICAMENTE en el token, que ya se valido en
        // tiempo constante. Es la recomendacion de OWASP: la comprobacion de
        // origen es defensa en profundidad, no el control primario.
        if (!is_string($origen) || $origen === '' || $origen === 'null') {
            return true;
        }

        $host = parse_url($origen, PHP_URL_HOST);
        if ($host === null || $host === false) {
            // Un Origin presente pero con formato invalido si es sospechoso.
            return false;
        }
        // El host esperado sale SIEMPRE de la configuracion, nunca de la
        // cabecera Host: esa la controla el cliente y podria falsificarse.
        $esperado = parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);
        if ($esperado === null || $esperado === false || $esperado === '') {
            return true;
        }
        return strcasecmp((string) $host, explode(':', (string) $esperado)[0]) === 0;
    }
}
