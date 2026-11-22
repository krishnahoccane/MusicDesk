<?php

namespace LabelDesk\Addons\RestApi\Support;

use RuntimeException;

/** An API error with a stable machine-readable code; rendered by ErrorRenderer. */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function unauthenticated(?string $message = null): self
    {
        return new self(401, 'unauthenticated', $message ?? __('A valid API token is required.'), [], ['WWW-Authenticate' => 'Bearer realm="LabelDesk"']);
    }

    public static function forbidden(string $code, string $message, array $details = []): self
    {
        return new self(403, $code, $message, $details);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', __('The requested resource was not found.'));
    }
}
