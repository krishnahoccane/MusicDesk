<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Typed key/value application settings stored in the `settings` table.
 * Keys are addressed as "group.key", e.g. settings()->get('general.currency').
 */
class SettingsService
{
    protected const CACHE_KEY = 'labeldesk.settings';

    /** @var array<string, mixed>|null */
    protected ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value, ?string $type = null): void
    {
        [$group, $name] = $this->split($key);
        $type ??= $this->inferType($value);

        Setting::query()->updateOrCreate(
            ['group' => $group, 'key' => $name],
            ['value' => $this->serialize($value, $type), 'type' => $type],
        );

        $this->flush();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * All settings, decoded. Encrypted values are decrypted here, in memory
     * only — the cache holds the stored (encrypted) form.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $rows = $this->rows();
        if ($rows === null) {
            return []; // table not ready yet (before installation) — don't memoise
        }

        return $this->loaded = collect($rows)
            ->map(fn (array $row) => $this->unserialize($row['value'], $row['type']))
            ->all();
    }

    /**
     * @return array<string, array{value: ?string, type: string}>|null
     */
    protected function rows(): ?array
    {
        try {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }

            if (! Schema::hasTable('settings')) {
                return null;
            }

            $rows = Setting::query()->get()->mapWithKeys(fn (Setting $s) => [
                $s->group.'.'.$s->key => ['value' => $s->value, 'type' => $s->type],
            ])->all();

            Cache::forever(self::CACHE_KEY, $rows);

            return $rows;
        } catch (Throwable) {
            return null;
        }
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function split(string $key): array
    {
        return str_contains($key, '.') ? explode('.', $key, 2) : ['general', $key];
    }

    protected function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_array($value) => 'json',
            default => 'string',
        };
    }

    protected function serialize(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value),
            'encrypted' => Crypt::encryptString((string) $value),
            default => (string) $value,
        };
    }

    protected function unserialize(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'bool' => $value === '1',
            'int' => (int) $value,
            'json' => json_decode($value, true),
            'encrypted' => rescue(fn () => Crypt::decryptString($value), null, false),
            default => $value,
        };
    }
}
