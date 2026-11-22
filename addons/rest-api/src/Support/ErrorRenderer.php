<?php

namespace LabelDesk\Addons\RestApi\Support;

use App\Exceptions\CatalogException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * One error format for every /api/v1 response:
 *
 *   { "error": { "code": "validation_failed", "message": "…", "details": { … } } }
 *
 * Messages of unexpected errors are never exposed (no stack traces, SQL or
 * paths); they are still reported to the application log by the handler.
 */
final class ErrorRenderer
{
    public static function applies(Request $request): bool
    {
        return $request->is('api/v1', 'api/v1/*');
    }

    public static function render(Throwable $e): JsonResponse|Response
    {
        // A response built on purpose (e.g. the rate limiter's 429): send it as it is.
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        [$status, $code, $message, $details, $headers] = match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), $e->details, $e->headers],
            $e instanceof ValidationException => [422, 'validation_failed', __('The given data was invalid.'), ['fields' => $e->errors()], []],
            $e instanceof CatalogException => [422, 'rule_violation', $e->getMessage(), [], []],
            $e instanceof AuthenticationException => [401, 'unauthenticated', __('A valid API token is required.'), [], ['WWW-Authenticate' => 'Bearer realm="LabelDesk"']],
            $e instanceof AuthorizationException => [403, 'forbidden', __('Your account is not allowed to do this.'), [], []],
            $e instanceof ModelNotFoundException => [404, 'not_found', __('The requested resource was not found.'), [], []],
            $e instanceof ThrottleRequestsException => [429, 'rate_limited', __('Too many requests. Slow down and retry later.'), [], $e->getHeaders()],
            $e instanceof HttpExceptionInterface => self::http($e),
            default => [500, 'server_error', __('Something went wrong on the server.'), [], []],
        };

        return self::response($status, $code, $message, $details, $headers);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function response(int $status, string $code, string $message, array $details = [], array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status, $headers);
    }

    /** @return array{0: int, 1: string, 2: string, 3: array<string, mixed>, 4: array<string, string>} */
    private static function http(HttpExceptionInterface $e): array
    {
        $status = $e->getStatusCode();
        [$code, $message] = match ($status) {
            401 => ['unauthenticated', __('A valid API token is required.')],
            403 => ['forbidden', __('Your account is not allowed to do this.')],
            404 => ['not_found', __('The requested resource was not found.')],
            405 => ['method_not_allowed', __('This method is not supported for this endpoint.')],
            409 => ['conflict', __('The request conflicts with the current state of the resource.')],
            413 => ['payload_too_large', __('The request body is too large.')],
            415 => ['unsupported_media_type', __('Send the request body as JSON.')],
            429 => ['rate_limited', __('Too many requests. Slow down and retry later.')],
            503 => ['unavailable', __('The service is temporarily unavailable.')],
            default => [$status >= 500 ? 'server_error' : 'http_error', $status >= 500 ? __('Something went wrong on the server.') : __('The request could not be processed.')],
        };

        // Core abort() messages for these are written for users (license, permissions).
        if (in_array($status, [403, 409], true) && $e instanceof Throwable && $e->getMessage() !== '') {
            $message = $e->getMessage();
        }

        return [$status, $code, $message, [], $e->getHeaders()];
    }
}
