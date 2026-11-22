<?php

namespace App\Services\Installation;

use PDO;
use PDOException;
use Throwable;

/**
 * Connects to a candidate MySQL/MariaDB database supplied in the wizard and
 * reports whether it can be used. Never writes to the database.
 */
class DatabaseInspector
{
    /**
     * @param  array{host: string, port: int|string, database: string, username: string, password: ?string, prefix: ?string}  $credentials
     * @return array{ok: bool, message: string, server?: string, version?: string, existing_tables?: int}
     */
    public function inspect(array $credentials): array
    {
        try {
            $pdo = $this->connect($credentials);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => $this->friendlyError($e)];
        } catch (Throwable) {
            return ['ok' => false, 'message' => __('Could not connect to the database server.')];
        }

        $rawVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $isMariaDb = stripos($rawVersion, 'mariadb') !== false;
        preg_match('/^(\d+\.\d+\.\d+)/', $rawVersion, $m);
        $version = $m[1] ?? '0.0.0';
        $min = (string) config($isMariaDb ? 'labeldesk.installer.min_mariadb' : 'labeldesk.installer.min_mysql');
        $server = $isMariaDb ? 'MariaDB' : 'MySQL';

        if (version_compare($version, $min, '<')) {
            return [
                'ok' => false,
                'message' => __(':server :version is not supported. LabelDesk requires :server :min or newer.', ['server' => $server, 'version' => $version, 'min' => $min]),
                'server' => $server,
                'version' => $version,
            ];
        }

        $prefix = (string) ($credentials['prefix'] ?? '');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ?');
        $stmt->execute([$credentials['database'], $this->likeEscape($prefix).'%']);
        $tables = (int) $stmt->fetchColumn();

        return [
            'ok' => true,
            'message' => __('Connected to :server :version.', ['server' => $server, 'version' => $version]),
            'server' => $server,
            'version' => $version,
            'existing_tables' => $tables,
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     */
    protected function connect(array $c): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) $c['port'], $c['database']);

        return new PDO($dsn, (string) $c['username'], (string) ($c['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    protected function likeEscape(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    /**
     * Map driver errors to messages that never echo the password.
     */
    protected function friendlyError(PDOException $e): string
    {
        $code = (int) ($e->errorInfo[1] ?? $e->getCode());

        return match ($code) {
            1045 => __('Access denied. Check the database username and password.'),
            1044 => __('The database user does not have access to this database.'),
            1049 => __('The database does not exist. Create it in your hosting control panel first.'),
            2002, 2003 => __('Could not reach the database server. Check the hostname and port.'),
            2005 => __('Unknown database host. Check the hostname.'),
            default => __('Could not connect to the database (error :code).', ['code' => $code]),
        };
    }
}
