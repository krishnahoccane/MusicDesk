<?php

namespace LabelDesk\Addons\Sso\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An OpenID Connect identity provider configured by the administrator.
 *
 * @property string $issuer
 * @property string $client_id
 * @property string $client_secret
 * @property list<string>|null $allowed_domains
 */
class SsoProvider extends Model
{
    public const PRESETS = [
        'entra' => 'Microsoft Entra ID',
        'google' => 'Google Workspace',
        'okta' => 'Okta',
        'oidc' => 'Other OpenID Connect provider',
    ];

    public const GOOGLE_ISSUER = 'https://accounts.google.com';

    protected $table = 'sso_providers';

    protected $guarded = ['id'];

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'allowed_domains' => 'array',
            'enabled' => 'boolean',
            'jit_enabled' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function identities(): HasMany
    {
        return $this->hasMany(SsoIdentity::class, 'sso_provider_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function discoveryUrl(): string
    {
        return rtrim($this->issuer, '/').'/.well-known/openid-configuration';
    }

    /** @return list<string> */
    public function scopeList(): array
    {
        $scopes = preg_split('/\s+/', trim((string) $this->scopes)) ?: [];

        return array_values(array_unique(array_merge(['openid'], array_filter($scopes))));
    }

    /** @return list<string> */
    public function domains(): array
    {
        return array_values(array_filter(array_map(fn ($d) => Str::lower(trim((string) $d)), (array) $this->allowed_domains)));
    }

    public function allowsEmail(string $email): bool
    {
        $domains = $this->domains();
        if ($domains === []) {
            return true;
        }
        $domain = Str::lower(Str::afterLast($email, '@'));

        return in_array($domain, $domains, true);
    }

    public function buttonLabel(): string
    {
        return __('Sign in with :name', ['name' => $this->name]);
    }

    public function icon(): string
    {
        return match ($this->preset) {
            'entra' => 'building-2',
            'google' => 'globe',
            'okta' => 'shield-check',
            default => 'key-round',
        };
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'provider', 32, '');
        $slug = $base;
        $i = 2;
        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
