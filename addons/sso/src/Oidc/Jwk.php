<?php

namespace LabelDesk\Addons\Sso\Oidc;

/**
 * Turns public JSON Web Keys (RFC 7517) into PEM keys OpenSSL can verify with,
 * and JOSE ECDSA signatures into DER. Hand-written DER, no libraries.
 */
final class Jwk
{
    /** @var array<string, array{oid: string, size: int}> */
    public const CURVES = [
        'P-256' => ['oid' => '06082a8648ce3d030107', 'size' => 32],
        'P-384' => ['oid' => '06052b81040022', 'size' => 48],
        'P-521' => ['oid' => '06052b81040023', 'size' => 66],
    ];

    private const RSA_ALGORITHM = '300d06092a864886f70d0101010500'; // SEQUENCE { rsaEncryption, NULL }

    private const EC_PUBLIC_KEY = '06072a8648ce3d0201';

    /** @param  array<string, mixed>  $jwk */
    public static function toPem(array $jwk): string
    {
        $der = match ($jwk['kty'] ?? null) {
            'RSA' => self::rsa($jwk),
            'EC' => self::ec($jwk),
            default => throw new SsoException(__('The identity provider uses an unsupported key type.'), 'unsupported_key'),
        };

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** @param  array<string, mixed>  $jwk */
    private static function rsa(array $jwk): string
    {
        $n = self::b64((string) ($jwk['n'] ?? ''));
        $e = self::b64((string) ($jwk['e'] ?? ''));
        if (strlen($n) < 256 || $e === '') { // at least 2048-bit keys
            throw new SsoException(__('The identity provider uses an RSA key that is too short.'), 'weak_key');
        }
        $key = self::seq(self::int($n).self::int($e));

        return self::seq(hex2bin(self::RSA_ALGORITHM).self::bitString($key));
    }

    /** @param  array<string, mixed>  $jwk */
    private static function ec(array $jwk): string
    {
        $curve = self::CURVES[$jwk['crv'] ?? ''] ?? null;
        $x = self::b64((string) ($jwk['x'] ?? ''));
        $y = self::b64((string) ($jwk['y'] ?? ''));
        if ($curve === null || strlen($x) !== $curve['size'] || strlen($y) !== $curve['size']) {
            throw new SsoException(__('The identity provider uses an unsupported key type.'), 'unsupported_key');
        }
        $algorithm = self::seq(hex2bin(self::EC_PUBLIC_KEY).hex2bin($curve['oid']));

        return self::seq($algorithm.self::bitString("\x04".$x.$y));
    }

    /** JOSE ECDSA signature (r || s) → DER SEQUENCE { INTEGER r, INTEGER s }. */
    public static function ecdsaToDer(string $signature, int $size): string
    {
        if (strlen($signature) !== 2 * $size) {
            throw new SsoException(__('The sign-in token signature is not valid.'), 'bad_signature');
        }

        return self::seq(self::int(substr($signature, 0, $size)).self::int(substr($signature, $size)));
    }

    public static function b64(string $value): string
    {
        if ($value === '' || ! preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return '';
        }
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }

    private static function int(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) > 0x7F) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::length(strlen($bytes)).$bytes;
    }

    private static function seq(string $content): string
    {
        return "\x30".self::length(strlen($content)).$content;
    }

    private static function bitString(string $content): string
    {
        return "\x03".self::length(strlen($content) + 1)."\x00".$content;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
