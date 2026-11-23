<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sub-label (imprint) under the main label. The main label itself is the
 * installation (Settings → Catalogue → label name); parent_id nests labels.
 */
class Label extends Model
{
    use SoftDeletes;

    protected $fillable = ['parent_id', 'name', 'code', 'contact_name', 'email', 'website', 'country', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('email', 'like', $like));
    }

    /** "Parent › Child" for selects. */
    public function path(): string
    {
        $names = [$this->name];
        $parent = $this->parent;
        $guard = 0;
        while ($parent && $guard++ < 10) {
            array_unshift($names, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' › ', $names);
    }

    /** Ids of this label and every label below it (to prevent cycles). */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        for ($depth = 0; $frontier !== [] && $depth < 10; $depth++) {
            $frontier = self::query()->withTrashed()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return array_values(array_unique($ids));
    }
}
