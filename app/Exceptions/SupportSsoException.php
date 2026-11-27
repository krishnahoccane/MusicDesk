<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A MusicDesk support sign-in was refused. The public message is deliberately generic
 * (it never reveals which check failed); $reason is the detail for the audit log only.
 */
class SupportSsoException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?string $publicMessage = null)
    {
        parent::__construct($publicMessage ?? __('This sign-in link is not valid or has expired.'));
    }
}
