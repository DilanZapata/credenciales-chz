<?php
declare(strict_types=1);

namespace app\models;

use App\Core\HttpException;
use App\Core\ReauthRequiredException;

/**
 * Punto unico de decision de autorizacion.
 *
 * Equivale a `mainModel::validarPermisos($accion, $modulo)` de Porcify
 * Manager, con dos diferencias:
 *
 *   - Los permisos se resuelven contra la base, no contra los embebidos en
 *     un token: revocar un permiso surte efecto en la peticion siguiente.
 *   - Denegar corta la peticion y deja registro, en lugar de devolver false
 *     y confiar en que el llamador lo compruebe. En la referencia ese
 *     descuido ya provoco que un `if` mal escrito dejara pasar peticiones
 *     con token invalido.
 *
 * Todo control de acceso pasa por aqui, EN EL SERVIDOR. El cliente solo
 * oculta opciones por comodidad; jamas es la barrera.
 */
class permisoModel extends mainModel
{
    public static function puede(string $permiso): bool
    {
        return contextoModel::puede($permiso);
    }

    /** Exige un permiso; registra el intento denegado y corta la peticion. */
    public static function exigir(string $permiso, ?string $tipoEntidad = null, int|string|null $idEntidad = null): void
    {
        if (contextoModel::puede($permiso)) {
            return;
        }
        self::denegar($permiso, $tipoEntidad, $idEntidad);
    }

    /** @param array<int,string> $permisos */
    public static function exigirAlguno(array $permisos, ?string $tipoEntidad = null, int|string|null $idEntidad = null): void
    {
        foreach ($permisos as $permiso) {
            if (contextoModel::puede($permiso)) {
                return;
            }
        }
        self::denegar(implode('|', $permisos), $tipoEntidad, $idEntidad);
    }

    private static function denegar(string $permiso, ?string $tipoEntidad, int|string|null $idEntidad): never
    {
        auditoriaModel::registrar(
            auditoriaModel::ACCESS_DENIED,
            $tipoEntidad,
            $idEntidad,
            $permiso,
            'denied',
            ['permiso_requerido' => $permiso, 'ruta' => contextoModel::ruta()],
            'warning'
        );
        throw HttpException::forbidden('No tiene autorizacion para realizar esta accion.');
    }

    /**
     * Step-up: exige que la reautenticacion sea reciente antes de una
     * operacion sensible (revelar, copiar o exportar secretos).
     */
    public static function exigirReautenticacion(string $operacion): void
    {
        $clave = $operacion === 'export' ? 'security.reauth_for_export' : 'security.reauth_for_secret';
        if (!configuracionModel::booleano($clave, true)) {
            return;
        }
        $minutos = max(1, configuracionModel::entero('security.reauth_minutes', 10));
        if (contextoModel::reautenticadoHace($minutos)) {
            return;
        }
        throw new ReauthRequiredException();
    }

    /**
     * Alcance de datos: null significa "sin restriccion"; un id de usuario
     * limita la consulta a lo que tenga asignado.
     */
    public static function alcanceCredenciales(): ?int
    {
        if (contextoModel::puede('credentials.view_all')) {
            return null;
        }
        return contextoModel::id();
    }

    /**
     * Nadie puede administrar a alguien de nivel igual o superior, ni
     * ejecutar sobre si mismo operaciones destructivas.
     */
    public static function puedeGestionarUsuario(int $nivelDestino, ?int $idDestino = null): bool
    {
        if (contextoModel::esSuperadministrador()) {
            return true;
        }
        if ($idDestino !== null && $idDestino === contextoModel::id()) {
            return false;
        }
        return contextoModel::nivel() > $nivelDestino;
    }

    public static function exigirGestionUsuario(int $nivelDestino, ?int $idDestino = null): void
    {
        if (self::puedeGestionarUsuario($nivelDestino, $idDestino)) {
            return;
        }
        auditoriaModel::registrar(
            auditoriaModel::ACCESS_DENIED, 'user', $idDestino, 'gestion de usuario', 'denied',
            ['motivo' => 'nivel de privilegio insuficiente'], 'warning'
        );
        throw HttpException::forbidden('No puede administrar a un usuario con igual o mayor nivel de privilegio.');
    }
}
