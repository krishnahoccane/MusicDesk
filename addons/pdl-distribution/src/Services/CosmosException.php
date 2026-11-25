<?php

namespace LabelDesk\Addons\PdlDistribution\Services;

use RuntimeException;

/**
 * An error from COSMOS (or from reaching it). The message is safe to show:
 * it is PDL's own message or a description of the failure, never a token,
 * password or presigned URL.
 *
 * $retryable: worth trying again later without changes (network errors,
 * timeouts, HTTP 429/5xx, COSMOS "System Error"). Everything else needs the
 * label to change something first.
 */
class CosmosException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly ?int $pdlCode = null, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
