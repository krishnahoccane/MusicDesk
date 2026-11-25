<?php

namespace LabelDesk\Addons\PdlDistribution\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PdlDeliveryEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['level', 'message', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(PdlDelivery::class, 'pdl_delivery_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
