<?php

namespace LabelDesk\Addons\MediaStorage\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One storage destination (a Google Drive account, a Dropbox, an FTP
 * server …). Credentials and tokens are stored encrypted.
 */
class MediaStorageTarget extends Model
{
    protected $fillable = ['driver', 'name', 'enabled', 'credentials', 'account', 'folder_id', 'folder_name', 'connected_at'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'connected_at' => 'datetime',
        ];
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        $value = ($this->credentials ?? [])[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setCredentials(array $values): void
    {
        $this->credentials = array_merge($this->credentials ?? [], $values);
    }

    public function files(): HasMany
    {
        return $this->hasMany(MediaStorageFile::class, 'target_id');
    }
}
