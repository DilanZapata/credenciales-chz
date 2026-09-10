<?php
declare(strict_types=1);

namespace app\models;

/**
 * Gestion de sesiones, respaldada en base de datos.
 *
 * Porcify Manager combina $_SESSION, un JWT y una columna `usuario.token`
 * para poder revocarlo. Aqui se conserva un unico mecanismo —la tabla
 * `sessions`— porque este sistema necesita tres cosas que aquella mezcla no
 * da de forma consistente:
 *
 *   - listar y CERRAR REMOTAMENTE sesiones de otros usuarios;
 *   - caducidad doble (inactividad y absoluta);
 *   - marca de reautenticacion (step-up) por sesion.
 *
 * El token viaja en una cookie HttpOnly + SameSite=Strict. En la tabla solo
 * se guarda su SHA-256: robar la base no permite suplantar una sesion.
 */
class sesionModel extends mainModel
{
    public const COOKIE = 'scgca_session';

    /**
     * Crea una sesion. Devuelve el token en claro (solo existe aqui).
     *
     * @return array{token:string,session:array<string,mixed>}
     */
    public static function crear(int $idUsuario, string $ip, string $agente, string $dispositivo, bool $mfaPendiente): array
    {
        $token  = cifradoModel::tokenAleatorio(32);
        $id     = cifradoModel::hashToken($token);
        $csrf   = cifradoModel::tokenAleatorio(32);
        $horas  = max(1, configuracionModel::entero('security.session_absolute_hours', 8));

        self::ejecutarConsultaAfectadas(
            'INSERT INTO sessions
               (id, user_id, csrf_token, ip_address, user_agent, device, pending_mfa, absolute_expires_at)
             VALUES (?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? HOUR))',
            [$id, $idUsuario, $csrf, $ip, $agente, $dispositivo, $mfaPendiente ? 1 : 0, $horas]
        );

        return ['token' => $token, 'session' => self::porId($id) ?? []];
    }

    /**
     * Rota el identificador de sesion. Se invoca tras superar el MFA:
     * neutraliza la fijacion de sesion.
     *
     * @return array{token:string,session:array<string,mixed>}
     */
    public static function rotar(string $idActual): array
    {
        $token = cifradoModel::tokenAleatorio(32);
        $nuevo = cifradoModel::hashToken($token);
        self::ejecutarConsultaAfectadas('UPDATE sessions SET id = ? WHERE id = ?', [$nuevo, $idActual]);
        return ['token' => $token, 'session' => self::porId($nuevo) ?? []];
    }

    /** @return array<string,mixed>|null */
    public static function porId(string $id): ?array
    {
        return self::obtenerFila('SELECT * FROM sessions WHERE id = ?', [$id]);
    }

    /**
     * Resuelve una sesion desde el token de la cookie aplicando las
     * politicas de caducidad. Devuelve null si no es utilizable.
     *
     * @return array<string,mixed>|null
     */
    public static function resolver(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $id     = cifradoModel::hashToken($token);
        $sesion = self::porId($id);
        if ($sesion === null || $sesion['status'] !== 'active') {
            return null;
        }
        if (strtotime((string) $sesion['absolute_expires_at']) <= time()) {
            self::expirar($id);
            return null;
        }
        $inactividad = max(1, configuracionModel::entero('security.session_idle_minutes', 30));
        if (strtotime((string) $sesion['last_activity_at']) + ($inactividad * 60) <= time()) {
            self::expirar($id);
            return null;
        }
        return $sesion;
    }

    public static function tocar(string $id): void
    {
        self::ejecutarConsultaAfectadas('UPDATE sessions SET last_activity_at = NOW() WHERE id = ?', [$id]);
    }

    public static function marcarMfaVerificado(string $id): void
    {
        self::ejecutarConsultaAfectadas(
            'UPDATE sessions SET mfa_verified = 1, pending_mfa = 0, reauth_at = NOW() WHERE id = ?', [$id]
        );
    }

    public static function marcarReautenticado(string $id): string
    {
        self::ejecutarConsultaAfectadas('UPDATE sessions SET reauth_at = NOW() WHERE id = ?', [$id]);
        return (string) self::obtenerValor('SELECT reauth_at FROM sessions WHERE id = ?', [$id]);
    }

    public static function expirar(string $id): void
    {
        self::ejecutarConsultaAfectadas("UPDATE sessions SET status = 'expired' WHERE id = ? AND status = 'active'", [$id]);
    }

    public static function revocar(string $id, ?int $porUsuario, string $motivo): void
    {
        self::ejecutarConsultaAfectadas(
            "UPDATE sessions SET status = 'revoked', revoked_at = NOW(), revoked_by = ?, revoke_reason = ?
              WHERE id = ? AND status = 'active'",
            [$porUsuario, mb_substr($motivo, 0, 120), $id]
        );
    }

    public static function revocarTodasDeUsuario(int $idUsuario, ?int $porUsuario, string $motivo, ?string $excepto = null): int
    {
        $sql    = "UPDATE sessions SET status = 'revoked', revoked_at = NOW(), revoked_by = ?, revoke_reason = ?
                    WHERE user_id = ? AND status = 'active'";
        $params = [$porUsuario, mb_substr($motivo, 0, 120), $idUsuario];
        if ($excepto !== null) {
            $sql     .= ' AND id <> ?';
            $params[] = $excepto;
        }
        return self::ejecutarConsultaAfectadas($sql, $params);
    }

    /**
     * @param array<string,mixed> $filtros
     * @return array<int,array<string,mixed>>
     */
    public static function listar(array $filtros = [], int $limite = 100): array
    {
        $where  = ['1=1'];
        $params = [];
        if (!empty($filtros['status'])) {
            $where[]          = 's.status = :status';
            $params['status'] = $filtros['status'];
        }
        if (!empty($filtros['user_id'])) {
            $where[]           = 's.user_id = :user_id';
            $params['user_id'] = (int) $filtros['user_id'];
        }
        if (!empty($filtros['search'])) {
            $where[]     = '(u.first_name LIKE :q OR u.last_name LIKE :q OR u.username LIKE :q OR u.national_id LIKE :q OR s.ip_address LIKE :q)';
            $params['q'] = '%' . $filtros['search'] . '%';
        }
        return self::obtenerFilas(
            'SELECT s.id, s.user_id, s.ip_address, s.device, s.user_agent, s.status, s.mfa_verified,
                    s.created_at, s.last_activity_at, s.absolute_expires_at, s.revoked_at, s.revoke_reason,
                    u.first_name, u.last_name, u.username, u.national_id
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY (s.status = "active") DESC, s.last_activity_at DESC
              LIMIT ' . (int) $limite,
            $params
        );
    }

    public static function contarActivas(): int
    {
        $inactividad = max(1, configuracionModel::entero('security.session_idle_minutes', 30));
        return (int) self::obtenerValor(
            "SELECT COUNT(*) FROM sessions
              WHERE status = 'active' AND absolute_expires_at > NOW()
                AND last_activity_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$inactividad]
        );
    }

    /** Mantenimiento: marca como expiradas las sesiones vencidas. */
    public static function purgarExpiradas(): int
    {
        $inactividad = max(1, configuracionModel::entero('security.session_idle_minutes', 30));
        return self::ejecutarConsultaAfectadas(
            "UPDATE sessions SET status = 'expired'
              WHERE status = 'active'
                AND (absolute_expires_at <= NOW() OR last_activity_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE))",
            [$inactividad]
        );
    }
}
