<?php

namespace App\Models;

use App\Enums\SystemRole;
use App\Enums\ThemePreference;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Mass-assignable attributes. Security-sensitive columns (is_active,
     * last_login_*) are deliberately excluded and set explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'theme',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'theme' => ThemePreference::class,
        ];
    }

    /**
     * Initials used for the avatar placeholder.
     */
    protected function initials(): Attribute
    {
        return Attribute::get(function (): string {
            $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];
            $initials = collect($parts)->filter()->take(2)
                ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
                ->implode('');

            return $initials !== '' ? $initials : 'U';
        });
    }

    /** @var list<int>|null */
    protected ?array $linkedArtistIds = null;

    /**
     * Artist profiles an artist account may see and work on.
     */
    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class)->withTimestamps();
    }

    /**
     * An artist account (role "Artist") only sees its own artists' data.
     */
    public function isArtistAccount(): bool
    {
        return $this->hasRole(SystemRole::Artist->value);
    }

    /**
     * @return list<int>
     */
    public function linkedArtistIds(): array
    {
        return $this->linkedArtistIds ??= DB::table('artist_user')
            ->where('user_id', $this->id)->pluck('artist_id')->map(fn ($id) => (int) $id)->all();
    }

    public function forgetLinkedArtists(): void
    {
        $this->linkedArtistIds = null;
    }

    public function recordLogin(?string $ip): void
    {
        $this->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->saveQuietly();
    }
}
