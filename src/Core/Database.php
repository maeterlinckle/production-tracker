<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $host = Config::get('database.host');
            $port = Config::get('database.port');
            $db = Config::get('database.database');
            $charset = Config::get('database.charset');

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

            self::$connection = new PDO(
                $dsn,
                (string) Config::get('database.username'),
                (string) Config::get('database.password'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        }

        return self::$connection;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute(self::bindable($params));

        return $statement;
    }

    /**
     * Booleans, as something MariaDB will accept.
     *
     * PDOStatement::execute() binds every value it is handed as a string, and
     * emulation is off, so the driver sends exactly that. `true` becomes '1',
     * which a TINYINT column takes happily — and `false` becomes the empty
     * string, which under STRICT_TRANS_TABLES is error 1366, "Incorrect integer
     * value: ''".
     *
     * So a bound `true` worked and a bound `false` threw, which is the worst
     * shape a bug can have: the failing half is the half nobody tests. Recording
     * a *failed* login attempt was the one call site that passed a raw bool, and
     * it had been throwing since the first install — every wrong password
     * produced a 500 instead of "check your details", and because the attempt
     * was never recorded, the lockout could never trigger either.
     *
     * Fixed here rather than at the call site. Every other place that writes a
     * boolean column already spells out `? 1 : 0`, which is the tell: if the
     * data layer needs each caller to remember something, one of them will not.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function bindable(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $params[$key] = $value ? 1 : 0;
            }
        }

        return $params;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** A single value from the first column of the first row, or null. */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** Run a statement whose result is only "how many rows did that touch". */
    public static function run(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::query($sql, $params);

        return (int) self::connection()->lastInsertId();
    }

    /**
     * Run a callback inside a transaction, joining one that is already open.
     *
     * PDO has no nested transactions, so a model method that opens its own
     * cannot be called from inside another one — and several of them need to
     * be. Checking free-issue material in records a receipt, records what was
     * rejected, raises the return note and moves the parts on, and either all
     * of that happened or none of it did.
     *
     * Joining rather than starting a second is safe because the outer call owns
     * the commit: an exception raised in here propagates out to it and rolls
     * the whole thing back.
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();

        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
