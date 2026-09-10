<?php
declare(strict_types=1);

namespace app\controllers;

use App\Core\Config;
use app\models\autenticacionModel;
use app\models\configuracionModel;
use app\models\contextoModel;
use app\models\sesionModel;

/**
 * Controlador de acceso: ingreso, segundo factor, reautenticacion y salida.
 *
 * La cedula identifica al empleado pero NUNCA autentica por si sola: la
 * contrasena es siempre obligatoria.
 */
class loginController extends baseController
{
    /** @param array<string,mixed> $variables */
    public static function ingresarController(array $variables): array
    {
        $r = self::responder(static function () use ($variables): array {
            $identificador = self::texto($variables, 'identifier');
            $clave         = (string) ($variables['password'] ?? '');

            if ($identificador === '' || $clave === '') {
                return ['status' => 'error', 'message' => 'Debe indicar su usuario o cedula y su contrasena.'];
            }

            return autenticacionModel::attemptLogin(
                $identificador,
                $clave,
                contextoModel::ip(),
                contextoModel::agente(),
                contextoModel::dispositivo()
            );
        }, 'Acceso');

        // El resultado del intento viaja dentro de data; el codigo HTTP debe
        // reflejarlo para que el cliente no tenga que mirar dos sitios.
        if (($r['data']['status'] ?? '') === 'error') {
            $r['code']    = 401;
            $r['status']  = 'error';
            $r['title']   = 'Acceso denegado';
            $r['message'] = (string) ($r['data']['message'] ?? 'No fue posible iniciar sesion.');
        }
        return $r;
    }

    /** @param array<string,mixed> $variables */
    public static function verificarMfaController(array $sesion, array $variables): array
    {
        $r = self::responder(
            static fn (): array => autenticacionModel::verifyMfa($sesion, self::texto($variables, 'code')),
            'Verificacion en dos pasos'
        );
        if (($r['data']['status'] ?? '') === 'error') {
            $r['code']    = 401;
            $r['status']  = 'error';
            $r['title']   = 'Codigo invalido';
            $r['message'] = (string) ($r['data']['message'] ?? 'El codigo de verificacion no es valido.');
        }
        return $r;
    }

    /** @param array<string,mixed> $variables */
    public static function reautenticarController(array $variables): array
    {
        return self::responder(static function () use ($variables): array {
            $ok = autenticacionModel::reauthenticate(
                (int) contextoModel::id(),
                (string) ($variables['password'] ?? ''),
                self::texto($variables, 'code') ?: null
            );
            if (!$ok) {
                // Mensaje uniforme: no distingue entre clave y codigo erroneos.
                throw new \App\Core\HttpException(401, 'No fue posible confirmar su identidad.');
            }
            $marca = sesionModel::marcarReautenticado((string) contextoModel::idSesion());
            contextoModel::marcarReautenticado($marca);
            $minutos = configuracionModel::entero('security.reauth_minutes', 10);
            return ['ok' => true, 'minutes' => $minutos, 'valid_until' => date('c', time() + ($minutos * 60))];
        }, 'Identidad confirmada');
    }

    public static function estadoSesionController(): array
    {
        return self::responder(static function (): array {
            $sesion      = contextoModel::sesion() ?? [];
            $inactividad = configuracionModel::entero('security.session_idle_minutes', 30);
            $ultima      = isset($sesion['last_activity_at']) ? strtotime((string) $sesion['last_activity_at']) : time();
            return [
                'authenticated' => contextoModel::autenticado(),
                'user' => [
                    'id'          => contextoModel::id(),
                    'name'        => contextoModel::nombreCompleto(),
                    'national_id' => contextoModel::cedula(),
                    'roles'       => contextoModel::codigosRol(),
                ],
                'expires_in_seconds' => max(0, ($ultima + $inactividad * 60) - time()),
                'reauth_valid'       => contextoModel::reautenticadoHace(
                    configuracionModel::entero('security.reauth_minutes', 10)
                ),
            ];
        }, 'Estado de la sesion');
    }

    public static function salirController(): array
    {
        return self::responder(static function (): array {
            $idSesion = contextoModel::idSesion();
            if ($idSesion !== null) {
                autenticacionModel::logout($idSesion, contextoModel::id());
            }
            return ['ok' => true];
        }, 'Sesion cerrada');
    }
}
