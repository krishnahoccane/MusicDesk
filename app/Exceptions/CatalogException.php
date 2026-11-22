<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A catalog change that breaks a business rule (locked release, missing
 * primary artist, invalid workflow step …). The message is user-safe.
 */
class CatalogException extends RuntimeException {}
