<?php

namespace App\Services\Installation;

use Illuminate\Support\Facades\DB;

/**
 * Points the running application at the database chosen in the wizard,
 * in memory only (the .env file is written after a successful install).
 */
class DatabaseConfigurator
{
    /**
     * @param  array{host: string, port: int|string, database: string, username: string, password: ?string, prefix: ?string}  $credentials
     * @return string Connection name to migrate
     */
    public function apply(array $credentials): string
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $credentials['host'],
            'database.connections.mysql.port' => (string) $credentials['port'],
            'database.connections.mysql.database' => $credentials['database'],
            'database.connections.mysql.username' => $credentials['username'],
            'database.connections.mysql.password' => (string) ($credentials['password'] ?? ''),
            'database.connections.mysql.prefix' => (string) ($credentials['prefix'] ?? ''),
        ]);

        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        return 'mysql';
    }
}
