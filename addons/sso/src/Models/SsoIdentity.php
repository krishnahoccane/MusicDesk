<?php

namespace LabelDesk\Addons\Sso\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A LabelDesk user linked to a subject ("sub") at an identity provider. */
class SsoIdentity extends Model
{
    protected $table = 'sso_identities';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(SsoProvider::class, 'sso_provider_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
