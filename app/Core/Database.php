<?php
declare(strict_types=1);

namespace App\Core;

use app\models\mainModel;

/**
 * Adaptador de acceso a datos.
 *
 * El acceso real lo hace ahora `app\models\mainModel` sobre **mysqli**, como
 * en Porcify Manager. Esta clase conserva su interfaz durante la migracion
 * para que los repositorios sigan funcionando sin tocarlos: cada uno se ira
 * fusionando en su modelo en las fases siguientes, y entonces este adaptador
 * desaparecera.
 *
 * Toda consulta con datos sigue yendo por sentencia preparada.
 */
final class Database
{
    private static ?Database $instancia = null;

    public static function instance(?array $cfg = null): Database
    {
        if (self::$instancia === null) {
            self::$instancia = new self();
        }
        return self::$instancia;
    }

    public static function reset(): void
    {
        mainModel::desconectar();
        self::$instancia = null;
    }

    /** Conexion mysqli subyacente. */
    public function enlace(): \mysqli
    {
        return mainModel::conectar();
    }

    /**
     * Sentencia sin parametros (DDL, TRUNCATE, SET...).
     *
     * Sustituye al antiguo `pdo()->exec()`. No admite datos de usuario: para
     * eso estan los metodos parametrizados.
     */
    public function exec(string $sql): void
    {
        mainModel::ejecutarConsulta($sql);
    }

    /** @return array<int,array<string,mixed>> */
    public function select(string $sql, array $params = []): array
    {
        return mainModel::obtenerFilas($sql, $params);
    }

    /** @return array<string,mixed>|null */
    public function selectOne(string $sql, array $params = []): ?array
    {
        return mainModel::obtenerFila($sql, $params);
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        return mainModel::obtenerValor($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return mainModel::ejecutarConsultaAfectadas($sql, $params);
    }

    public function insert(string $sql, array $params = []): int
    {
        return mainModel::ejecutarInsert($sql, $params);
    }

    public function transaction(callable $callback): mixed
    {
        return mainModel::transaccion(fn () => $callback($this));
    }
}
