<?php

namespace LabelDesk\Addons\PdlDistribution\Services;

use RuntimeException;

/** A release cannot be queued for PDL; $problems lists what to fix. */
class DeliveryException extends RuntimeException
{
    /** @param  list<string>  $problems */
    public function __construct(string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }
}
