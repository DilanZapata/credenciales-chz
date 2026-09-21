<?php
declare(strict_types=1);

namespace app\models;

/**
 * Configuracion del servidor de correo saliente (tabla `mail_config`).
 *
 * La contrasena del buzon es un secreto con los mismos derechos que
 * cualquier otro del sistema: se guarda en un sobre AES-256-GCM y NUNCA
 * vuelve a la pantalla. Quien administra puede cambiarla, no leerla.
 */
class correoConfigModel extends mainModel
{
    /** Ata el criptograma a su ubicacion exacta. */
    private const AAD_CAMPO = 'smtp_password';

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /**
     * Configuracion vigente, SIN la contrasena.
     *
     * @return array<string,mixed>
     */
    public static function obtener(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $fila = self::obtenerFila(
            'SELECT id, enabled, host, port, encryption, username, from_email, from_name,
                    reply_to, timeout, key_version, last_test_at, last_test_ok, last_test_error,
                    updated_by, updated_at
               FROM mail_config WHERE id = 1'
        );

        // Una instalacion recien migrada siempre tiene la fila; si no la
        // tiene, se responde con los valores por defecto en vez de fallar.
        self::$cache = $fila ?? [
            'id' => 1, 'enabled' => 0, 'host' => null, 'port' => 587,
            'encryption' => 'tls', 'username' => null,
            'from_email' => 'no-reply@empresa.local', 'from_name' => null,
            'reply_to' => null, 'timeout' => 10, 'key_version' => null,
            'last_test_at' => null, 'last_test_ok' => null, 'last_test_error' => null,
            'updated_by' => null, 'updated_at' => null,
        ];
        return self::$cache;
    }

    public static function habilitado(): bool
    {
        $c = self::obtener();
        return (bool) $c['enabled'] && (string) ($c['host'] ?? '') !== '';
    }

    /** Hay una contrasena guardada (sin revelar cual). */
    public static function tieneContrasena(): bool
    {
        return self::obtener()['key_version'] !== null;
    }

    /**
     * Configuracion lista para el cliente SMTP, con la contrasena descifrada.
     *
     * @return array{host:string,port:int,encryption:string,username:string,
     *               password:string,timeout:int}
     */
    public static function paraEnvio(?string $contrasenaEnClaro = null): array
    {
        $c = self::obtener();
        return [
            'host'       => (string) ($c['host'] ?? ''),
            'port'       => (int) ($c['port'] ?? 587),
            'encryption' => (string) ($c['encryption'] ?? 'tls'),
            'username'   => (string) ($c['username'] ?? ''),
            'password'   => $contrasenaEnClaro ?? (self::contrasena() ?? ''),
            'timeout'    => (int) ($c['timeout'] ?? 10),
        ];
    }

    /** Descifra la contrasena del buzon. Solo la usa el envio. */
    private static function contrasena(): ?string
    {
        $fila = self::obtenerFila(
            'SELECT key_version, algo, ciphertext, nonce, tag, wrapped_dek, dek_nonce, dek_tag
               FROM mail_config WHERE id = 1 AND key_version IS NOT NULL'
        );
        if ($fila === null) {
            return null;
        }
        return cifradoModel::descifrar($fila, cifradoModel::aad('mail_config', 1, self::AAD_CAMPO));
    }

    /**
     * Guarda la configuracion.
     *
     * @param array<string,mixed> $datos
     * @param string|null $contrasena  null = conservar la que ya hubiera;
     *                                 ''   = borrarla (relay sin autenticacion)
     */
    public static function guardar(array $datos, ?string $contrasena, ?int $actor): void
    {
        self::ejecutarConsultaAfectadas('INSERT IGNORE INTO mail_config (id) VALUES (1)');

        self::ejecutarConsultaAfectadas(
            'UPDATE mail_config
                SET enabled = ?, host = ?, port = ?, encryption = ?, username = ?,
                    from_email = ?, from_name = ?, reply_to = ?, timeout = ?, updated_by = ?
              WHERE id = 1',
            [
                (int) $datos['enabled'], $datos['host'], (int) $datos['port'],
                $datos['encryption'], $datos['username'], $datos['from_email'],
                $datos['from_name'], $datos['reply_to'], (int) $datos['timeout'], $actor,
            ]
        );

        if ($contrasena !== null) {
            self::fijarContrasena($contrasena);
        }

        self::$cache = null;
    }

    /** '' borra la contrasena guardada. */
    private static function fijarContrasena(string $contrasena): void
    {
        if ($contrasena === '') {
            self::ejecutarConsultaAfectadas(
                'UPDATE mail_config
                    SET key_version = NULL, ciphertext = NULL, nonce = NULL, tag = NULL,
                        wrapped_dek = NULL, dek_nonce = NULL, dek_tag = NULL
                  WHERE id = 1'
            );
            return;
        }

        $sobre = cifradoModel::cifrar($contrasena, cifradoModel::aad('mail_config', 1, self::AAD_CAMPO));
        self::ejecutarConsultaAfectadas(
            'UPDATE mail_config
                SET algo = ?, key_version = ?, ciphertext = ?, nonce = ?, tag = ?,
                    wrapped_dek = ?, dek_nonce = ?, dek_tag = ?
              WHERE id = 1',
            [
                $sobre['algo'], $sobre['key_version'], $sobre['ciphertext'], $sobre['nonce'],
                $sobre['tag'], $sobre['wrapped_dek'], $sobre['dek_nonce'], $sobre['dek_tag'],
            ]
        );
    }

    /** Deja constancia del resultado de la ultima prueba de envio. */
    public static function registrarPrueba(bool $exito, ?string $error): void
    {
        self::ejecutarConsultaAfectadas(
            'UPDATE mail_config
                SET last_test_at = NOW(), last_test_ok = ?, last_test_error = ?
              WHERE id = 1',
            [$exito ? 1 : 0, $error === null ? null : mb_substr($error, 0, 500)]
        );
        self::$cache = null;
    }
}
