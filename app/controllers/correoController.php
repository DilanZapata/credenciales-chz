<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use app\models\auditoriaModel;
use app\models\correoConfigModel;
use app\models\correoModel;
use app\models\contextoModel;
use app\models\limitadorModel;
use app\models\permisoModel;
use Throwable;

/**
 * Servidor de correo saliente.
 *
 * La contrasena del buzon entra pero no sale: la pantalla solo sabe si
 * hay una guardada, nunca cual es.
 */
class correoController extends baseController
{
    public static function verController(): array
    {
        return self::responder(static function (): array {
            permisoModel::exigir('settings.manage');
            return [
                'config'          => correoConfigModel::obtener(),
                'tiene_clave'     => correoConfigModel::tieneContrasena(),
            ];
        }, 'Configuracion de correo');
    }

    /** @param array<string,mixed> $variables */
    public static function guardarController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            permisoModel::exigir('settings.manage');
            permisoModel::exigirReautenticacion('secret');

            $datos = Validator::make($variables)
                ->string('host', 'El servidor', 0, 255, false)
                ->integer('port', 'El puerto', 1, 65535, false)
                ->in('encryption', 'El cifrado', ['none', 'tls', 'ssl'], false)
                ->string('username', 'El usuario', 0, 255, false)
                ->email('from_email', 'El remitente')
                ->string('from_name', 'El nombre del remitente', 0, 120, false)
                ->email('reply_to', 'La direccion de respuesta', false)
                ->integer('timeout', 'La espera maxima', 3, 120, false)
                ->bool('enabled')
                ->validated();

            $activo = (bool) ($datos['enabled'] ?? false);
            $host   = trim((string) ($datos['host'] ?? ''));

            // Activar sin servidor dejaria el sistema creyendo que envia.
            if ($activo && $host === '') {
                throw new ValidationException(
                    ['host' => 'Indique el servidor SMTP antes de habilitar el envio.']
                );
            }

            $antes = correoConfigModel::obtener();

            correoConfigModel::guardar([
                'enabled'    => $activo,
                'host'       => $host !== '' ? $host : null,
                'port'       => $datos['port'] ?? 587,
                'encryption' => $datos['encryption'] ?? 'tls',
                'username'   => ($datos['username'] ?? '') !== '' ? $datos['username'] : null,
                'from_email' => $datos['from_email'],
                'from_name'  => ($datos['from_name'] ?? '') !== '' ? $datos['from_name'] : null,
                'reply_to'   => ($datos['reply_to'] ?? '') !== '' ? $datos['reply_to'] : null,
                'timeout'    => $datos['timeout'] ?? 10,
            ], self::contrasenaEnviada($variables), contextoModel::id());

            // La contrasena no entra en la auditoria ni para decir que cambio
            // de valor: solo que se toco.
            auditoriaModel::registrar(
                auditoriaModel::SETTINGS_UPDATED, 'mail_config', 1, 'Servidor de correo saliente',
                'success',
                [
                    'host'           => $host,
                    'puerto'         => $datos['port'] ?? 587,
                    'cifrado'        => $datos['encryption'] ?? 'tls',
                    'usuario'        => $datos['username'] ?? '',
                    'remitente'      => $datos['from_email'],
                    'habilitado'     => $activo,
                    'habilitado_antes' => (bool) $antes['enabled'],
                    'clave_cambiada' => self::contrasenaEnviada($variables) !== null,
                ],
                'critical'
            );

            return ['enabled' => $activo];
        }, 'Configuracion de correo actualizada');
    }

    /**
     * Envia un mensaje de prueba y devuelve el motivo exacto si falla.
     *
     * @param array<string,mixed> $variables
     */
    public static function probarController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            permisoModel::exigir('settings.manage');

            // Un boton que abre conexiones salientes no puede pulsarse en bucle.
            if (!limitadorModel::intentar('mailtest:' . contextoModel::id(), 5, 300)) {
                throw HttpException::tooManyRequests(
                    'Demasiadas pruebas seguidas. Espere unos minutos.'
                );
            }

            $datos = Validator::make($variables)
                ->email('to', 'La direccion de prueba')
                ->validated();

            $quien = contextoModel::autenticado() ? contextoModel::nombreCompleto() : 'consola';

            try {
                correoModel::enviarPrueba((string) $datos['to'], $quien);
            } catch (Throwable $e) {
                correoConfigModel::registrarPrueba(false, $e->getMessage());
                auditoriaModel::registrar(auditoriaModel::SETTINGS_UPDATED, 'mail_config', 1,
                    'Prueba de correo', 'failure', ['destino' => $datos['to']], 'notice');
                return ['ok' => false, 'error' => $e->getMessage()];
            }

            correoConfigModel::registrarPrueba(true, null);
            auditoriaModel::registrar(auditoriaModel::SETTINGS_UPDATED, 'mail_config', 1,
                'Prueba de correo', 'success', ['destino' => $datos['to']], 'info');

            return ['ok' => true, 'error' => null];
        }, 'Prueba de correo');
    }

    /**
     * Lee la contrasena del formulario.
     *
     * null   = el campo llego vacio: se conserva la que hubiera.
     * ''     = se pidio borrarla explicitamente.
     *
     * @param array<string,mixed> $variables
     */
    private static function contrasenaEnviada(array $variables): ?string
    {
        if (self::booleano($variables, 'password_clear')) {
            return '';
        }
        $valor = $variables['password'] ?? '';
        $valor = is_scalar($valor) ? (string) $valor : '';
        // Sin limpiarDatos() ni trim: una contrasena puede llevar espacios
        // al final y son parte de ella.
        return $valor === '' ? null : $valor;
    }
}
