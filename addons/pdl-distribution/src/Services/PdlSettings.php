<?php

namespace LabelDesk\Addons\PdlDistribution\Services;

use App\Services\SettingsService;

/**
 * Settings → PDL distribution, stored in the core settings table under the
 * "pdl" group. The password is stored encrypted (type "encrypted") and is
 * only decrypted in memory to sign in.
 */
class PdlSettings
{
    public const DEFAULTS = [
        'enabled' => false,
        'base_url' => Cosmos::DEFAULT_BASE_URL,
        'email' => '',
        'label_id' => '',
        'label_name' => '',
        'platforms' => 'AllPlatformWithYouTube',
        'allow_any_go_live_date' => false,
        'album_type' => 'Album',
        'album_category' => 'Album',
        'content_types' => ['single' => 'Single', 'ep' => 'Album', 'album' => 'Album', 'compilation' => 'Compilation'],
        'default_mood' => '',
        'crbt_start' => 0,
        'date_format' => 'd-m-Y',
        'genre_map' => [],
        'language_map' => [],
        'publisher' => '',
        'token_minutes' => 50,
    ];

    public const DATE_FORMATS = ['d-m-Y' => 'DD-MM-YYYY (as in PDL\'s Postman collection)', 'Y-m-d' => 'YYYY-MM-DD (as in the guide\'s field table)'];

    public function __construct(protected SettingsService $settings) {}

    public function get(string $key): mixed
    {
        $value = $this->settings->get('pdl.'.$key, self::DEFAULTS[$key] ?? null);

        return is_array(self::DEFAULTS[$key] ?? null) && ! is_array($value) ? self::DEFAULTS[$key] : $value;
    }

    /** @param  array<string, mixed>  $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->settings->set('pdl.'.$key, $value);
        }
    }

    public function setPassword(string $password): void
    {
        $this->settings->set('pdl.password', $password, 'encrypted');
    }

    public function password(): ?string
    {
        $value = $this->settings->get('pdl.password');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function hasCredentials(): bool
    {
        return filled($this->get('email')) && $this->password() !== null;
    }

    public function isConfigured(): bool
    {
        return $this->hasCredentials() && filled($this->get('label_id')) && filled($this->baseUrl());
    }

    public function isEnabled(): bool
    {
        return (bool) $this->get('enabled') && $this->isConfigured();
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->get('base_url'), '/');
    }

    public function apiUrl(string $path): string
    {
        $base = $this->baseUrl();
        if (! str_ends_with($base, Cosmos::API_PREFIX)) {
            $base .= Cosmos::API_PREFIX;
        }

        return $base.'/'.ltrim($path, '/');
    }

    /** @return array{0: string, 1: string}|null [genre, sub-genre] mapped for a LabelDesk genre id */
    public function genreFor(?int $genreId): ?array
    {
        $value = $genreId ? ($this->get('genre_map')[(string) $genreId] ?? null) : null;
        if (! is_string($value) || ! str_contains($value, '|')) {
            return null;
        }

        return explode('|', $value, 2);
    }

    /** COSMOS language for a LabelDesk language (explicit mapping, then same name, then known aliases). */
    public function languageFor(?string $code, ?string $name): ?string
    {
        if ($code === null) {
            return null;
        }
        $mapped = $this->get('language_map')[$code] ?? null;
        if (is_string($mapped) && in_array($mapped, Cosmos::LANGUAGES, true)) {
            return $mapped;
        }

        return self::autoLanguage($code, $name);
    }

    public static function autoLanguage(string $code, ?string $name): ?string
    {
        if (isset(Cosmos::LANGUAGE_ALIASES[$code])) {
            return Cosmos::LANGUAGE_ALIASES[$code];
        }
        $name = mb_strtolower(trim((string) $name));

        return in_array($name, Cosmos::LANGUAGES, true) ? $name : null;
    }
}
