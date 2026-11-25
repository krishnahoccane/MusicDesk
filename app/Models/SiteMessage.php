<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A message sent with the website's contact form (kept even if e-mail is not set up). */
class SiteMessage extends Model
{
    protected $fillable = ['name', 'email', 'subject', 'message', 'ip', 'mailed', 'read_at'];

    protected function casts(): array
    {
        return ['mailed' => 'boolean', 'read_at' => 'datetime'];
    }
}
