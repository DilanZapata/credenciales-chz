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
use app\models\plantillaCorreoModel;
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

    // ------------------------- Plantillas ----------------------------

    public static function plantillasController(): array
    {
        return self::responder(static function (): array {
            permisoModel::exigir('settings.manage');
            return ['items' => plantillaCorreoModel::todas()];
        }, 'Plantillas de correo');
    }

    public static function plantillaController(string $codigo): array
    {
        return self::responder(static function () use ($codigo): array {
            permisoModel::exigir('settings.manage');
            $ficha = plantillaCorreoModel::obtener($codigo);
            if ($ficha === null) {
                throw HttpException::notFound('No existe ese tipo de correo.');
            }
            return [
                'plantilla' => $ficha,
                'ejemplo'   => plantillaCorreoModel::ejemplo($codigo),
                'muestra'   => plantillaCorreoModel::componer($codigo, plantillaCorreoModel::ejemplo($codigo)),
            ];
        }, 'Plantilla de correo');
    }

    /** @param array<string,mixed> $variables */
    public static function guardarPlantillaController(string $codigo, array $variables): array
    {
        return self::responder(static function () use ($codigo, $variables): array {
            permisoModel::exigir('settings.manage');
            permisoModel::exigirReautenticacion('secret');

            if (!plantillaCorreoModel::existe($codigo)) {
                throw HttpException::notFound('No existe ese tipo de correo.');
            }

            // Volver al contenido de fabrica es borrar la fila, no guardar
            // una copia de lo que trae el codigo: asi la plantilla sigue
            // heredando las mejoras de versiones futuras.
            if (self::booleano($variables, 'restaurar')) {
                plantillaCorreoModel::restaurar($codigo);
                auditoriaModel::registrar(auditoriaModel::SETTINGS_UPDATED, 'mail_template', $codigo,
                    'Plantilla restaurada', 'success', ['plantilla' => $codigo], 'notice');
                return ['restaurada' => true];
            }

            $datos = Validator::make($variables)
                ->string('subject', 'El asunto', 3, 255)
                ->text('body_text', 'El cuerpo en texto plano', 20000, true)
                ->text('body_html', 'El cuerpo en HTML', 100000, false)
                ->bool('enabled')
                ->validated();

            $asunto = (string) $datos['subject'];
            $texto  = (string) $datos['body_text'];
            $html   = (string) ($datos['body_html'] ?? '');

            $errores = [];

            // Una variable mal escrita saldria tal cual al destinatario.
            $desconocidas = plantillaCorreoModel::variablesDesconocidas($codigo, $asunto, $texto, $html);
            if ($desconocidas !== []) {
                $errores['body_html'] = 'Estas variables no existen para este tipo de correo: {{'
                    . implode('}}, {{', $desconocidas) . '}}.';
            }

            // Sin el enlace, el correo de restablecimiento no sirve de nada.
            $faltan = plantillaCorreoModel::obligatoriasAusentes($codigo, $texto, $html);
            if ($faltan !== []) {
                $errores['body_text'] = 'Falta la variable obligatoria {{' . implode('}}, {{', $faltan) . '}}.';
            }

            if ($errores !== []) {
                throw new ValidationException($errores);
            }

            $id = plantillaCorreoModel::guardar($codigo, $asunto, $html, $texto,
                (bool) $datos['enabled'], contextoModel::id());

            auditoriaModel::registrar(auditoriaModel::SETTINGS_UPDATED, 'mail_template', $codigo,
                'Plantilla de correo', 'success',
                ['plantilla' => $codigo, 'activa' => (bool) $datos['enabled'], 'con_html' => $html !== ''],
                'notice');

            return ['id' => $id, 'restaurada' => false];
        }, 'Plantilla guardada');
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
