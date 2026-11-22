<?php

namespace App\Services\MediaStorage;

use RuntimeException;

/** A cloud storage error with a message safe to show to administrators (no tokens). */
class MediaStorageException extends RuntimeException {}
