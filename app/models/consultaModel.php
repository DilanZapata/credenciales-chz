<?php
declare(strict_types=1);

namespace app\models;

use App\Core\HttpException;

/**
 * Consulta rapida de accesos (/consulta).
 *
 * Pantalla publica para que un empleado vea en que sistemas tiene cuenta
 * sin recorrer el panel completo.
 *
 * Es la unica parte del sistema que puede responder sin sesion, asi que
 * concentra sus propias barreras en vez de apoyarse en permisoModel, que
 * necesita un usuario autenticado:
 *
 *   1. Debe estar habilitada (viene apagada).
 *   2. Limite de intentos por IP: sin el, la pantalla es un probador
 *      automatico de cedulas.
 *   3. Contrasena, si la politica la exige.
 *   4. Cuenta activa y no bloqueada, igual que en el acceso normal.
 *   5. Todo intento queda auditado, acierte o falle.
 *
 * NUNCA devuelve un secreto salvo que el administrador lo haya habilitado
 * de forma expresa y la asignacion de esa persona lo permita.
 */
class consultaModel extends mainModel
{
    public static function habilitada(): bool
    {
        return configuracionModel::booleano('access.quick_lookup_enabled', false);
    }

    public static function exigeContrasena(): bool
    {
        return configuracionModel::booleano('access.quick_lookup_require_password', true);
    }

    public static function muestraSecretos(): bool
    {
        return configuracionModel::booleano('access.quick_lookup_show_secrets', false);
    }

    /**
     * Resuelve al empleado a partir de lo que escribio.
     *
     * @return int Identificador del usuario
     */
    public static function identificar(string $identificador, string $clave): int
    {
        if (!self::habilitada()) {
            throw HttpException::notFound('La consulta rapida no esta disponible.');
        }

        $identificador = trim($identificador);
        if ($identificador === '') {
            throw new \App\Core\ValidationException(
                ['identifier' => 'Indique su cedula, usuario o correo.']
            );
        }

        self::frenarPorIp($identificador);

        $usuario = usuarioModel::findAuthRecord($identificador);

        // Respuesta identica exista o no la cuenta: de lo contrario la
        // pantalla confirma que cedulas estan registradas en la empresa.
        $generico = 'No encontramos accesos con esos datos.';

        if ($usuario === null) {
            self::auditarFallo($identificador, null, 'identificador inexistente');
            throw HttpException::notFound($generico);
        }

        $idUsuario = (int) $usuario['id'];

        if (($usuario['status'] ?? '') !== 'active') {
            self::auditarFallo($identificador, $idUsuario, 'cuenta no activa');
            throw HttpException::notFound($generico);
        }

        if (!empty($usuario['locked_until']) && strtotime((string) $usuario['locked_until']) > time()) {
            self::auditarFallo($identificador, $idUsuario, 'cuenta bloqueada');
            throw HttpException::notFound($generico);
        }

        if (self::exigeContrasena()) {
            if ($clave === '' || !cifradoModel::verificarContrasena($clave, (string) $usuario['password_hash'])) {
                self::auditarFallo($identificador, $idUsuario, 'contrasena incorrecta');
                // El limitador por identificador solo cuenta cuando la
                // cuenta existe: si no, seria otra forma de enumerarlas.
                limitadorModel::intentar('consulta:id:' . mb_strtolower($identificador), 5, 900);
                throw HttpException::notFound($generico);
            }
            limitadorModel::limpiar('consulta:id:' . mb_strtolower($identificador));
        }

        auditoriaModel::registrar(
            auditoriaModel::QUICK_LOOKUP, 'user', $idUsuario,
            (string) $usuario['username'], 'success',
            ['con_contrasena' => self::exigeContrasena()],
            self::exigeContrasena() ? 'info' : 'notice',
            $idUsuario, null, (string) ($usuario['national_id'] ?? null)
        );

        return $idUsuario;
    }

    /**
     * Accesos vigentes de una persona, sin secretos.
     *
     * @return array<string,mixed>
     */
    public static function accesosDe(int $idUsuario): array
    {
        $usuario = usuarioModel::find($idUsuario);
        if ($usuario === null || ($usuario['status'] ?? '') !== 'active') {
            throw HttpException::notFound('No encontramos accesos con esos datos.');
        }

        return [
            'user'  => [
                'id'         => (int) $usuario['id'],
                'first_name' => (string) $usuario['first_name'],
                'last_name'  => (string) $usuario['last_name'],
                'username'   => (string) $usuario['username'],
            ],
            'items' => asignacionModel::forUser($idUsuario, true),
        ];
    }

    /**
     * Revela UNA contrasena desde la consulta rapida.
     *
     * Solo si el administrador lo habilito y la asignacion de esa persona
     * lo permite. Deja el mismo rastro que un revelado desde el panel, para
     * que la auditoria no tenga huecos segun por donde se pidio.
     *
     * @return array{secret:string,credential:string,system:string}
     */
    public static function revelar(int $idUsuario, int $idCredencial): array
    {
        if (!self::habilitada() || !self::muestraSecretos()) {
            throw HttpException::forbidden('La consulta rapida no entrega contrasenas.');
        }

        $asignacion = asignacionModel::find($idCredencial, $idUsuario);
        if ($asignacion === null || (int) $asignacion['is_active'] !== 1
            || (int) $asignacion['can_view_secret'] !== 1) {
            auditoriaModel::registrarAccesoSecreto(
                $idCredencial, 'view', null, 'password', null, 'denied',
                'consulta rapida sin asignacion valida', null, $idUsuario
            );
            throw HttpException::notFound('No tiene ese acceso asignado.');
        }

        // Freno propio: mas estrecho que el del panel, porque aqui la
        // identidad se comprobo una sola vez y puede que sin contrasena.
        if (!limitadorModel::intentar('consulta:secreto:' . $idUsuario, 10, 300)) {
            auditoriaModel::eventoSeguridad(
                'quick_lookup_flood', 'Consulta masiva por la pantalla rapida',
                'Se supero el limite de contrasenas reveladas desde /consulta.', 'high', $idUsuario
            );
            throw HttpException::tooManyRequests('Ha superado el limite de consultas. Intente mas tarde.');
        }

        $credencial = credencialModel::find($idCredencial);
        $fila       = credencialModel::currentSecret($idCredencial);
        if ($credencial === null || $fila === null) {
            throw HttpException::notFound('La credencial no tiene contrasena registrada.');
        }

        $aad   = cifradoModel::aad('credential', $idCredencial, 'password', (int) $fila['version']);
        $plano = cifradoModel::descifrar($fila, $aad);
        if ($plano === null) {
            auditoriaModel::eventoSeguridad(
                'decryption_failure', 'Fallo de descifrado de un secreto',
                'La autenticacion criptografica del registro fallo.', 'critical', $idUsuario
            );
            throw new HttpException(500, 'No fue posible descifrar la contrasena.');
        }

        auditoriaModel::registrarAccesoSecreto(
            $idCredencial, 'view', (int) $fila['id'], 'password', (int) $fila['version'],
            'success', 'consulta rapida', null, $idUsuario
        );
        auditoriaModel::registrar(
            auditoriaModel::SECRET_VIEWED, 'credential', $idCredencial,
            (string) $credencial['name'], 'success',
            ['origen' => 'consulta rapida', 'version' => (int) $fila['version']],
            'warning', $idUsuario
        );

        return [
            'secret'     => $plano,
            'credential' => (string) $credencial['name'],
            'system'     => (string) ($credencial['system_name'] ?? ''),
        ];
    }

    // -----------------------------------------------------------------

    /**
     * Freno por procedencia.
     *
     * Sin esto la pantalla es un probador automatico de cedulas: se
     * recorren las combinaciones hasta dar con empleados reales.
     */
    private static function frenarPorIp(string $identificador): void
    {
        $maximo = max(1, configuracionModel::entero('access.quick_lookup_max_attempts', 10));

        if (!limitadorModel::intentar('consulta:ip:' . contextoModel::ip(), $maximo, 3600)) {
            self::auditarFallo($identificador, null, 'limite de intentos por IP');
            auditoriaModel::eventoSeguridad(
                'quick_lookup_abuse',
                'Uso masivo de la consulta rapida',
                'Se supero el limite de consultas desde ' . contextoModel::ip() . '.',
                'high'
            );
            throw HttpException::tooManyRequests(
                'Demasiadas consultas desde esta conexion. Intente mas tarde.'
            );
        }
    }

    private static function auditarFallo(string $identificador, ?int $idUsuario, string $motivo): void
    {
        auditoriaModel::registrar(
            auditoriaModel::QUICK_LOOKUP_DENIED, 'user', $idUsuario,
            mb_substr($identificador, 0, 60), 'denied',
            ['motivo' => $motivo], 'warning'
        );
    }
}
