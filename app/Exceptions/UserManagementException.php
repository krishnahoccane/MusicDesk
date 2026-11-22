<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A user or role change that would break a safety rule (for example removing
 * the last Super Admin or exceeding the license user allowance). The message
 * is safe to show to the administrator.
 */
class UserManagementException extends RuntimeException
{
    public static function seatLimit(int $limit): self
    {
        return new self(__('Your license allows :limit active users. Deactivate a user or upgrade your plan on MusicDesk.tech to add more.', ['limit' => $limit]));
    }
}
