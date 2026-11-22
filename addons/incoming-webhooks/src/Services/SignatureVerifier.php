<?php

namespace LabelDesk\Addons\IncomingWebhooks\Services;

/**
 * HMAC-SHA256 request signatures with a timestamp (replay window).
 *
 *   X-Webhook-Signature: t=1763812345,v1=<hex HMAC-SHA256 of "{t}.{raw body}" with the secret>
 *
 * Several v1= values may be sent (e.g. while rotating secrets); one valid
 * value is enough. The timestamp must be within the endpoint's tolerance of
 * the server clock, in either direction.
 */
class SignatureVerifier
{
    public const HEADER = 'X-Webhook-Signature';

    public const OK = 'ok';

    public const MISSING = 'missing';

    public const MALFORMED = 'malformed';

    public const EXPIRED = 'expired';

    public const MISMATCH = 'mismatch';

    public static function sign(string $secret, string $body, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * @param  list<string>  $secrets
     */
    public function verify(?string $header, string $body, array $secrets, int $tolerance, ?int $now = null): string
    {
        $header = trim((string) $header);
        if ($header === '') {
            return self::MISSING;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't' && ctype_digit($value) && strlen($value) <= 12) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/i', $value)) {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === []) {
            return self::MALFORMED;
        }

        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return self::EXPIRED;
        }

        foreach ($secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            $expected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);
            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return self::OK;
                }
            }
        }

        return self::MISMATCH;
    }

    public static function describe(string $result): string
    {
        return match ($result) {
            self::MISSING => __('The :header header is missing.', ['header' => self::HEADER]),
            self::MALFORMED => __('The :header header is not in the format t=<timestamp>,v1=<signature>.', ['header' => self::HEADER]),
            self::EXPIRED => __('The signature timestamp is outside the allowed time window (check the sender’s clock).'),
            self::MISMATCH => __('The signature does not match. Check the signing secret.'),
            default => __('Signature valid.'),
        };
    }
}
