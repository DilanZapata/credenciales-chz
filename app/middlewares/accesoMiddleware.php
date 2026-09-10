<?php
declare(strict_types=1);

namespace app\middlewares;

use App\Core\Config;
use App\Core\Csrf;
use app\models\configuracionModel;
use app\models\contextoModel;
use app\models\sesionModel;

/**
 * Portero de las vistas (equivalente a app/Middleware/ de Porcify Manager).
 *
 * `session_start.php` solo resuelve QUIEN es el usuario; aqui se decide si
 * puede ver la pagina que pidio. Se ejecuta antes de incluir cualquier
 * vista y devuelve la ruta a la que hay que redirigir, o null para seguir.
 *
 * Comprobaciones en CADA peticion, no solo al iniciar sesion:
 *   - la sesion existe y sigue viva (la caducidad la aplica sesionModel);
 *   - el segundo factor pendiente solo permite completarlo;
 *   - el segundo factor obligatorio por rol se exige antes de nada mas;
 *   - la contrasena marcada para cambio o caducada bloquea el resto.
 */
class accesoMiddleware
{
    /** Vistas alcanzables durante la verificacion del segundo factor. */
    private const RUTAS_MFA_PENDIENTE = ['/mfa', '/mfa/verificar', '/salir'];

    /** Vistas alcanzables mientras el segundo factor no esta configurado. */
    private const RUTAS_ALTA_MFA = ['/perfil/mfa', '/perfil/mfa/iniciar', '/perfil/mfa/confirmar',
                                    '/salir', '/perfil/contrasena'];

    /** Vistas alcanzables mientras la contrasena deba cambiarse. */
    private const RUTAS_CAMBIO_CLAVE = ['/perfil/contrasena', '/salir', '/api/v1/perfil/contrasena'];

    /**
     * @param bool $publica La vista no exige sesion (acceso, recuperacion…).
     * @return string|null  Ruta de redireccion, o null si puede continuar.
     */
    public static function revisar(string $ruta, bool $publica): ?string
    {
        $base = (string) Config::get('app.base_path', '');

        if ($publica) {
            // Un usuario ya autenticado no vuelve al formulario de acceso.
            $sesion = contextoModel::sesion();
            if ($sesion !== null && (int) ($sesion['pending_mfa'] ?? 0) === 0 && $ruta !== '/mfa') {
                return $base . '/';
            }
            return null;
        }

        $sesion = contextoModel::sesion();
        if (!contextoModel::autenticado() || $sesion === null) {
            // La cookie huerfana se retira para no reintentar en bucle.
            setcookie(sesionModel::COOKIE, '', [
                'expires' => time() - 3600, 'path' => ($base === '' ? '/' : $base . '/'),
                'httponly' => true, 'samesite' => 'Strict',
            ]);
            return $base . '/entrar?redirect=' . rawurlencode($ruta);
        }

        if ((int) ($sesion['pending_mfa'] ?? 0) === 1 && !in_array($ruta, self::RUTAS_MFA_PENDIENTE, true)) {
            return $base . '/mfa';
        }

        $usuario = contextoModel::usuario() ?? [];

        if ((int) ($usuario['mfa_enabled'] ?? 0) === 0
            && self::mfaObligatorio($usuario)
            && !in_array($ruta, self::RUTAS_ALTA_MFA, true)) {
            return $base . '/perfil/mfa';
        }

        if (((int) ($usuario['must_change_password'] ?? 0) === 1 || self::contrasenaCaducada($usuario))
            && !in_array($ruta, self::RUTAS_CAMBIO_CLAVE, true)) {
            return $base . '/perfil/contrasena';
        }

        return null;
    }

    /**
     * Token anti-CSRF que debe traer una escritura.
     *
     * Una sola implementacion para los dos frentes (endpoints JSON y envio
     * de formulario): si divergieran, uno de los dos acabaria aceptando lo
     * que el otro rechaza.
     *
     * Tres origenes, en orden: la sesion autenticada, la sesion pendiente
     * de segundo factor y, para los formularios publicos, la cookie de
     * doble envio.
     */
    public static function tokenCsrfEsperado(): ?string
    {
        $token = contextoModel::tokenCsrf();
        if ($token !== null) {
            return $token;
        }

        $cookie = $_COOKIE[sesionModel::COOKIE] ?? null;
        if (is_string($cookie) && $cookie !== '') {
            $sesion = sesionModel::resolver($cookie);
            if ($sesion !== null) {
                return (string) $sesion['csrf_token'];
            }
        }

        $invitado = $_COOKIE[Csrf::COOKIE] ?? null;
        return is_string($invitado) && Csrf::isValidFormat($invitado) ? $invitado : null;
    }

    /**
     * Token anti-CSRF para los formularios publicos (acceso, recuperacion).
     *
     * Si ya hay sesion se usa su token. Si no, se emite uno de doble envio
     * en cookie propia: asi el formulario queda protegido sin necesidad de
     * crear una sesion para quien solo esta mirando la pantalla de acceso.
     */
    public static function csrfInvitado(): string
    {
        $sesion = contextoModel::sesion();
        if ($sesion !== null) {
            return (string) $sesion['csrf_token'];
        }

        $cookie = $_COOKIE[Csrf::COOKIE] ?? null;
        if (is_string($cookie) && Csrf::isValidFormat($cookie)) {
            return $cookie;
        }

        $token = Csrf::generate();
        if (!headers_sent()) {
            $base = (string) Config::get('app.base_path', '');
            setcookie(Csrf::COOKIE, $token, [
                'expires'  => time() + 7200,
                'path'     => ($base === '' ? '/' : $base . '/'),
                'httponly' => true,
                'samesite' => 'Strict',
                'secure'   => (bool) Config::get('session.cookie_secure', false),
            ]);
        }
        return $token;
    }

    /**
     * La contrasena de acceso caduca segun la politica configurada.
     * Un valor de 0 dias desactiva la caducidad.
     *
     * @param array<string,mixed> $usuario
     */
    private static function contrasenaCaducada(array $usuario): bool
    {
        $dias = configuracionModel::entero('security.password_expiry_days', 0);
        if ($dias <= 0) {
            return false;
        }
        $cambiada = $usuario['password_changed_at'] ?? null;
        if ($cambiada === null) {
            return true;
        }
        return strtotime((string) $cambiada) < (time() - ($dias * 86400));
    }

    /** @param array<string,mixed> $usuario */
    private static function mfaObligatorio(array $usuario): bool
    {
        if ((int) ($usuario['mfa_enforced'] ?? 0) === 1) {
            return true;
        }
        if (!configuracionModel::booleano('security.mfa_required_admins', true)) {
            return false;
        }
        foreach (contextoModel::roles() as $rol) {
            if ((int) ($rol['requires_mfa'] ?? 0) === 1) {
                return true;
            }
        }
        return false;
    }
}
