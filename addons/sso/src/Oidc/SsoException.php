<?php

namespace LabelDesk\Addons\Sso\Oidc;

use RuntimeException;

/**
 * A sign-in or configuration failure. The message is safe to show to the
 * person signing in or to the administrator; it never contains tokens,
 * secrets or codes. $reason is a short machine code for the activity log.
 */
class SsoException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'failed')
    {
        parent::__construct($message);
    }
}
