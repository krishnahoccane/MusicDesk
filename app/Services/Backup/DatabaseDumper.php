<?php

namespace App\Services\Backup;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes an SQL dump of the application database in pure PHP, so backups
 * work on shared hosting where mysqldump / exec() are not available.
 *
 * Supports MySQL / MariaDB (production) and SQLite (tests). Rows are read
 * with a cursor and written in batches, so memory use stays flat.
 */
class DatabaseDumper
{
    /** Tables whose rows are never dumped (structure only): transient or sensitive runtime data. */
    public const SKIP_DATA = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    protected const BATCH = 200;

    /**
     * @return array<string, int> Row count per table
     */
    public function dump(string $path): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("Database driver [{$driver}] is not supported for backups.");
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not create the database dump file.');
        }

        $mysql = $driver !== 'sqlite';
        $counts = [];

        try {
            fwrite($handle, '-- '.config('labeldesk.product.name').' '.config('labeldesk.product.version')." database backup\n");
            fwrite($handle, '-- Created '.now()->toIso8601String()." ({$driver})\n\n");
            fwrite($handle, $mysql ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n" : "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n");

            foreach ($this->tables($connection, $mysql) as $table => $create) {
                $quoted = $this->quoteName($table, $mysql);
                fwrite($handle, "-- Table {$table}\nDROP TABLE IF EXISTS {$quoted};\n{$create};\n\n");

                $counts[$table] = 0;
                if (in_array($table, self::SKIP_DATA, true)) {
                    continue;
                }

                $counts[$table] = $this->writeRows($connection, $handle, $table, $mysql);
                fwrite($handle, "\n");
            }

            fwrite($handle, $mysql ? "SET FOREIGN_KEY_CHECKS=1;\n" : "COMMIT;\nPRAGMA foreign_keys=ON;\n");
        } finally {
            fclose($handle);
        }

        return $counts;
    }

    /**
     * @return array<string, string> table => CREATE statement
     */
    protected function tables(Connection $connection, bool $mysql): array
    {
        $tables = [];

        if ($mysql) {
            foreach ($connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
                $name = array_values((array) $row)[0];
                $create = (array) $connection->selectOne('SHOW CREATE TABLE '.$this->quoteName($name, true));
                $tables[$name] = $create['Create Table'] ?? array_values($create)[1];
            }
        } else {
            foreach ($connection->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $row) {
                $tables[$row->name] = $row->sql;
            }
        }

        ksort($tables);

        return $tables;
    }

    /**
     * @param  resource  $handle
     */
    protected function writeRows(Connection $connection, $handle, string $table, bool $mysql): int
    {
        $pdo = $connection->getPdo();
        $quoted = $this->quoteName($table, $mysql);
        $count = 0;
        $batch = [];
        $columns = null;

        foreach ($connection->table($table)->cursor() as $row) {
            $row = (array) $row;
            $columns ??= '('.implode(', ', array_map(fn ($c) => $this->quoteName($c, $mysql), array_keys($row))).')';

            $batch[] = '('.implode(', ', array_map(fn ($v) => match (true) {
                $v === null => 'NULL',
                is_bool($v) => $v ? '1' : '0',
                is_int($v), is_float($v) => (string) $v,
                default => $pdo->quote((string) $v),
            }, $row)).')';
            $count++;

            if (count($batch) >= self::BATCH) {
                fwrite($handle, "INSERT INTO {$quoted} {$columns} VALUES\n".implode(",\n", $batch).";\n");
                $batch = [];
            }
        }

        if ($batch !== []) {
            fwrite($handle, "INSERT INTO {$quoted} {$columns} VALUES\n".implode(",\n", $batch).";\n");
        }

        return $count;
    }

    protected function quoteName(string $name, bool $mysql): string
    {
        return $mysql ? '`'.str_replace('`', '``', $name).'`' : '"'.str_replace('"', '""', $name).'"';
    }
}
