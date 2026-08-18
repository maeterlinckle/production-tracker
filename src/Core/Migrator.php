<?php

declare(strict_types=1);

namespace App\Core;

final class Migrator
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? Config::get('app.root') . '/database/migrations';
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        Database::connection()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration   VARCHAR(191) NOT NULL,
                batch       INT UNSIGNED NOT NULL DEFAULT 1,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migrations_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function available(): array
    {
        $files = glob($this->directory . '/*.sql') ?: [];
        $files = array_map('basename', $files);
        sort($files, SORT_STRING);

        return $files;
    }

    public function applied(): array
    {
        return array_column(Database::all('SELECT migration FROM migrations ORDER BY id'), 'migration');
    }

    public function pending(): array
    {
        return array_values(array_diff($this->available(), $this->applied()));
    }

    public function run(?callable $onFile = null): array
    {
        $pending = $this->pending();
        if ($pending === []) {
            return [];
        }

        $row = Database::one('SELECT MAX(batch) AS max_batch FROM migrations');
        $batch = ((int) ($row['max_batch'] ?? 0)) + 1;

        $applied = [];
        foreach ($pending as $file) {
            $sql = (string) file_get_contents($this->directory . '/' . $file);
            $statements = self::splitStatements($sql);

            try {
                foreach ($statements as $statement) {
                    if (trim($statement) === '') {
                        continue;
                    }
                    Database::connection()->exec($statement);
                }
            } catch (\PDOException $e) {
                throw new \RuntimeException("Migration {$file} failed: " . $e->getMessage(), 0, $e);
            }

            Database::insert('INSERT INTO migrations (migration, batch) VALUES (:migration, :batch)', [
                'migration' => $file,
                'batch' => $batch,
            ]);

            $applied[] = $file;
            if ($onFile !== null) {
                $onFile($file, count($statements));
            }
        }

        return $applied;
    }

    /** Splits raw SQL into statements, respecting quotes and comments. */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                $current .= $char;
                if ($char === "\n") {
                    $inLineComment = false;
                }
                continue;
            }

            if ($inBlockComment) {
                $current .= $char;
                if ($char === '*' && $next === '/') {
                    $current .= $next;
                    $i++;
                    $inBlockComment = false;
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    $current .= $char;
                    continue;
                }
                if ($char === '#') {
                    $inLineComment = true;
                    $current .= $char;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $current .= $char;
                    continue;
                }
            }

            if ($char === "'" && !$inDouble && !$inBacktick) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle && !$inBacktick) {
                $inDouble = !$inDouble;
            } elseif ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            }

            if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $statements[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return $statements;
    }
}
