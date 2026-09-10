<?php
declare(strict_types=1);

namespace app\models;

use App\Core\Env;
use RuntimeException;

/**
 * =====================================================================
 *  NUCLEO CRIPTOGRAFICO
 * =====================================================================
 *
 *  Modelo de cifrado de sobre en tres niveles:
 *
 *    CLAVE MAESTRA  (32 bytes, vive en .env, fuera del arbol servido)
 *          |  HKDF-SHA256(maestra, sal = encryption_keys.salt,
 *          |              info = "SCGCA:KEK:v{version}")
 *          v
 *    KEK    (clave de cifrado de claves, por version del llavero)
 *          |  AES-256-GCM(KEK) -> DEK envuelta
 *          v
 *    DEK    (clave de datos, UNICA POR SECRETO)
 *          |  AES-256-GCM(DEK, nonce, AAD)
 *          v
 *    CRIPTOGRAMA  ->  tabla credential_secrets
 *
 *  Consecuencias:
 *   - Un volcado de la base NO revela ningun secreto: sin la clave maestra
 *     el material es indescifrable.
 *   - Una DEK por secreto: comprometer una no compromete las demas.
 *   - El AAD ata el criptograma a (credencial, campo, version). Mover una
 *     fila de una credencial a otra invalida la autenticacion GCM.
 *
 *  Se usa cifrado REVERSIBLE porque el sistema debe poder mostrar la
 *  contrasena a quien esta autorizado. Las contrasenas de acceso AL PROPIO
 *  SISTEMA, en cambio, se guardan como hash irreversible.
 */
class cifradoModel extends mainModel
{
    private const CIFRADO     = 'aes-256-gcm';
    private const BYTES_TAG   = 16;
    private const BYTES_NONCE = 12;
    private const BYTES_CLAVE = 32;
    private const HASH_HKDF   = 'sha256';

    private static ?string $claveMaestra = null;
    /** @var array<int,string> */
    private static array $cacheKek = [];
    private static ?int $versionActiva = null;

    // -----------------------------------------------------------------
    //  Material de clave
    // -----------------------------------------------------------------

    private static function claveMaestra(): string
    {
        if (self::$claveMaestra !== null) {
            return self::$claveMaestra;
        }
        $bruta = (string) Env::get('APP_MASTER_KEY', '');
        if ($bruta === '') {
            throw new RuntimeException(
                'APP_MASTER_KEY no esta configurada. Ejecute: php bin/console.php key:generate'
            );
        }
        $decodificada = base64_decode($bruta, true);
        if ($decodificada === false || strlen($decodificada) !== self::BYTES_CLAVE) {
            throw new RuntimeException('APP_MASTER_KEY invalida: se esperan 32 bytes en base64.');
        }
        self::$claveMaestra = $decodificada;
        return $decodificada;
    }

    /** Pimienta del servidor para el prehash de las contrasenas de acceso. */
    private static function pimienta(): string
    {
        $bruta = (string) Env::get('APP_PEPPER', '');
        if ($bruta === '') {
            return hash_hkdf(self::HASH_HKDF, self::claveMaestra(), 32, 'SCGCA:PEPPER:v1');
        }
        $decodificada = base64_decode($bruta, true);
        return $decodificada !== false && $decodificada !== '' ? $decodificada : $bruta;
    }

    public static function versionClaveActiva(): int
    {
        if (self::$versionActiva !== null) {
            return self::$versionActiva;
        }
        $fila = self::obtenerFila(
            'SELECT version FROM encryption_keys WHERE status = ? ORDER BY version DESC LIMIT 1',
            ['active']
        );
        if ($fila === null) {
            self::$versionActiva = self::crearVersionClave();
            return self::$versionActiva;
        }
        self::$versionActiva = (int) $fila['version'];
        return self::$versionActiva;
    }

    /** Crea una version nueva en el llavero y la marca como activa. */
    public static function crearVersionClave(): int
    {
        return self::transaccion(static function (): int {
            $max      = (int) (self::obtenerValor('SELECT COALESCE(MAX(version), 0) FROM encryption_keys') ?? 0);
            $siguiente = $max + 1;
            self::ejecutarConsultaAfectadas("UPDATE encryption_keys SET status = 'retired', retired_at = NOW() WHERE status = 'active'");
            self::ejecutarInsert(
                'INSERT INTO encryption_keys (version, salt, algo, status) VALUES (?, ?, ?, ?)',
                [$siguiente, random_bytes(32), self::CIFRADO, 'active']
            );
            self::$versionActiva = $siguiente;
            self::$cacheKek      = [];
            return $siguiente;
        });
    }

    private static function kek(int $version): string
    {
        if (isset(self::$cacheKek[$version])) {
            return self::$cacheKek[$version];
        }
        $fila = self::obtenerFila('SELECT salt FROM encryption_keys WHERE version = ?', [$version]);
        if ($fila === null) {
            throw new RuntimeException('Version de clave desconocida: ' . $version);
        }
        $kek = hash_hkdf(self::HASH_HKDF, self::claveMaestra(), self::BYTES_CLAVE,
                         'SCGCA:KEK:v' . $version, (string) $fila['salt']);
        self::$cacheKek[$version] = $kek;
        return $kek;
    }

    // -----------------------------------------------------------------
    //  Cifrado y descifrado
    // -----------------------------------------------------------------

    /**
     * Cifra un secreto y devuelve el sobre completo listo para persistir.
     *
     * @return array{key_version:int,algo:string,ciphertext:string,nonce:string,tag:string,wrapped_dek:string,dek_nonce:string,dek_tag:string}
     */
    public static function cifrar(string $plano, string $aad = ''): array
    {
        if ($plano === '') {
            throw new RuntimeException('No se puede cifrar un valor vacio.');
        }
        $version = self::versionClaveActiva();
        $kek     = self::kek($version);

        $dek    = random_bytes(self::BYTES_CLAVE);
        $nonce  = random_bytes(self::BYTES_NONCE);
        $tag    = '';
        $cifrado = openssl_encrypt($plano, self::CIFRADO, $dek, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::BYTES_TAG);
        if ($cifrado === false) {
            throw new RuntimeException('Fallo el cifrado del secreto.');
        }

        $nonceDek = random_bytes(self::BYTES_NONCE);
        $tagDek   = '';
        $envuelta = openssl_encrypt($dek, self::CIFRADO, $kek, OPENSSL_RAW_DATA, $nonceDek, $tagDek,
                                    'SCGCA:DEK:v' . $version, self::BYTES_TAG);
        if ($envuelta === false) {
            throw new RuntimeException('Fallo el envoltorio de la clave de datos.');
        }

        self::borrar($dek);

        return [
            'key_version' => $version,
            'algo'        => self::CIFRADO,
            'ciphertext'  => $cifrado,
            'nonce'       => $nonce,
            'tag'         => $tag,
            'wrapped_dek' => $envuelta,
            'dek_nonce'   => $nonceDek,
            'dek_tag'     => $tagDek,
        ];
    }

    /**
     * Descifra un sobre. Devuelve null si la autenticacion GCM falla: dato
     * manipulado, AAD incorrecto o clave equivocada.
     *
     * @param array<string,mixed> $sobre
     */
    public static function descifrar(array $sobre, string $aad = ''): ?string
    {
        $version = (int) ($sobre['key_version'] ?? 0);
        if ($version <= 0) {
            return null;
        }
        $kek = self::kek($version);

        $dek = openssl_decrypt(
            self::bin($sobre['wrapped_dek'] ?? ''), self::CIFRADO, $kek, OPENSSL_RAW_DATA,
            self::bin($sobre['dek_nonce'] ?? ''), self::bin($sobre['dek_tag'] ?? ''), 'SCGCA:DEK:v' . $version
        );
        if ($dek === false) {
            return null;
        }

        $plano = openssl_decrypt(
            self::bin($sobre['ciphertext'] ?? ''), self::CIFRADO, $dek, OPENSSL_RAW_DATA,
            self::bin($sobre['nonce'] ?? ''), self::bin($sobre['tag'] ?? ''), $aad
        );
        self::borrar($dek);

        return $plano === false ? null : $plano;
    }

    /** Re-cifra un sobre con la version de clave activa (rotacion del llavero). */
    public static function reenvolver(array $sobre, string $aad): ?array
    {
        $plano = self::descifrar($sobre, $aad);
        if ($plano === null) {
            return null;
        }
        $nuevo = self::cifrar($plano, $aad);
        self::borrar($plano);
        return $nuevo;
    }

    /** AAD canonico: ata el criptograma a su ubicacion logica exacta. */
    public static function aad(string $entidad, int|string $id, string $campo, int $version = 1): string
    {
        return sprintf('SCGCA|%s|%s|%s|v%d', $entidad, (string) $id, $campo, $version);
    }

    /**
     * Huella determinista de un secreto (HMAC con clave derivada).
     * Permite detectar contrasenas repetidas SIN descifrar nada y sin poder
     * invertir el valor.
     */
    public static function huella(string $plano): string
    {
        $clave = hash_hkdf(self::HASH_HKDF, self::claveMaestra(), 32, 'SCGCA:FINGERPRINT:v1');
        return hash_hmac('sha256', $plano, $clave);
    }

    // -----------------------------------------------------------------
    //  Contrasenas de acceso al sistema (hash irreversible)
    // -----------------------------------------------------------------

    /**
     * Construccion: bcrypt( base64( HMAC-SHA256(clave, pimienta) ) )
     *  - el prehash evita el truncamiento a 72 bytes de bcrypt;
     *  - la pimienta vive en .env: un volcado de `users` no basta para
     *    atacar los hashes por fuerza bruta;
     *  - si el binario dispone de Argon2id, se usa preferentemente.
     *
     * @return array{hash:string,algo:string}
     */
    public static function hashContrasena(string $clave): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $hash = password_hash(self::prehash($clave), PASSWORD_ARGON2ID,
                                  ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2]);
            $algo = 'argon2id-hmac';
        } else {
            $hash = password_hash(self::prehash($clave), PASSWORD_BCRYPT, ['cost' => 12]);
            $algo = 'bcrypt-hmac';
        }
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('No fue posible generar el hash de la contrasena.');
        }
        return ['hash' => $hash, 'algo' => $algo];
    }

    public static function verificarContrasena(string $clave, string $hash): bool
    {
        return password_verify(self::prehash($clave), $hash);
    }

    public static function requiereRehash(string $hash): bool
    {
        return defined('PASSWORD_ARGON2ID')
            ? password_needs_rehash($hash, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2])
            : password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    private static function prehash(string $clave): string
    {
        return base64_encode(hash_hmac('sha256', $clave, self::pimienta(), true));
    }

    // -----------------------------------------------------------------
    //  Utilidades
    // -----------------------------------------------------------------

    public static function tokenAleatorio(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function hashToken(string $token): string
    {
        // SHA-256 basta: el token ya tiene 256 bits de entropia.
        return hash('sha256', $token);
    }

    public static function iguales(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }

    /** Convierte flujos de la base (BLOB) a cadena binaria. */
    private static function bin(mixed $valor): string
    {
        if (is_resource($valor)) {
            return (string) stream_get_contents($valor);
        }
        return (string) $valor;
    }

    /** Sobrescribe una variable en memoria (mitigacion best-effort). */
    private static function borrar(string &$valor): void
    {
        if (function_exists('sodium_memzero')) {
            /** @psalm-suppress UndefinedFunction */
            sodium_memzero($valor);
            return;
        }
        $largo = strlen($valor);
        $valor = $largo > 0 ? str_repeat("\0", $largo) : '';
        unset($valor);
    }
}
