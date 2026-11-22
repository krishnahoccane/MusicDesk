<?php

namespace App\Services\Installation;

use Predis\Client as PredisClient;
use Throwable;

/**
 * Installer step 3: checks a Redis server. Uses the phpredis extension when
 * installed, otherwise the bundled pure-PHP Predis client.
 */
class RedisInspector
{
    public static function client(): string
    {
        return extension_loaded('redis') ? 'phpredis' : 'predis';
    }

    /**
     * @param  array{host: string, port: int, password: ?string, username?: ?string, database: int, tls?: bool}  $c
     * @return array{ok: bool, message: string, version?: ?string}
     */
    public function inspect(array $c): array
    {
        try {
            $version = self::client() === 'phpredis' ? $this->viaExtension($c) : $this->viaPredis($c);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => __('Could not connect to Redis: :reason', ['reason' => $this->clean($e->getMessage())])];
        }

        return ['ok' => true, 'message' => __('Connected to Redis :version.', ['version' => $version ?? '']), 'version' => $version];
    }

    protected function viaExtension(array $c): ?string
    {
        $redis = new \Redis;
        $host = ! empty($c['tls']) ? 'tls://'.$c['host'] : $c['host'];
        $redis->connect($host, (int) $c['port'], 3.0);
        if (filled($c['password'] ?? null)) {
            filled($c['username'] ?? null) ? $redis->auth([$c['username'], $c['password']]) : $redis->auth($c['password']);
        }
        $redis->select((int) $c['database']);
        $redis->ping();
        $info = $redis->info('server');
        $redis->close();

        return $info['redis_version'] ?? null;
    }

    protected function viaPredis(array $c): ?string
    {
        $client = new PredisClient(array_filter([
            'scheme' => ! empty($c['tls']) ? 'tls' : 'tcp',
            'host' => $c['host'],
            'port' => (int) $c['port'],
            'username' => filled($c['username'] ?? null) ? $c['username'] : null,
            'password' => filled($c['password'] ?? null) ? $c['password'] : null,
            'database' => (int) $c['database'],
            'timeout' => 3.0,
        ], fn ($v) => $v !== null));

        $client->ping();
        $info = $client->info('server');
        $client->disconnect();

        return $info['Server']['redis_version'] ?? $info['redis_version'] ?? null;
    }

    protected function clean(string $message): string
    {
        return mb_substr(preg_replace('/(password|auth)\S*/i', '***', $message) ?? '', 0, 200);
    }
}
