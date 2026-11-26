<?php

namespace App\Exceptions;

/**
 * A free-trial limit was reached (users, artists, releases or tracks). Extends CatalogException
 * so every catalogue form and importer shows it like other "cannot save" messages.
 */
class TrialLimitException extends CatalogException
{
    public function __construct(string $message, public readonly string $buyUrl)
    {
        parent::__construct($message);
    }
}
