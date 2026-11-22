<?php

namespace LabelDesk\Addons\RestApi\Services;

use App\Exceptions\CatalogException;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use Carbon\CarbonInterface;
use LabelDesk\Addons\RestApi\Models\ApiToken;
use LabelDesk\Addons\RestApi\Support\Abilities;

/**
 * Creates, finds and revokes personal access tokens.
 *
 * Token format: "ldk_" + 48 random base62 characters (about 285 bits). Only
 * the SHA-256 hash is stored and looked up, so the plain token never reaches
 * the database, the activity log or the server logs.
 */
class ApiTokenService
{
    public const PREFIX = 'ldk_';

    public const RANDOM_LENGTH = 48;

    public function __construct(protected ActivityLogger $activity) {}

    /**
     * @param  list<string>  $abilities
     * @return array{0: ApiToken, 1: string} the stored token and the plain token (shown once)
     */
    public function create(User $owner, string $name, array $abilities, ?CarbonInterface $expiresAt): array
    {
        $abilities = Abilities::normalize($abilities);
        if ($abilities === []) {
            throw new CatalogException(__('Choose at least one ability.'));
        }

        $max = (int) config('rest-api.max_tokens_per_user', 25);
        if (ApiToken::query()->where('user_id', $owner->id)->usable()->count() >= $max) {
            throw new CatalogException(__('You already have :count active tokens. Revoke one you no longer use first.', ['count' => $max]));
        }

        $plain = self::PREFIX.self::random(self::RANDOM_LENGTH);

        $token = new ApiToken;
        $token->forceFill([
            'user_id' => $owner->id,
            'name' => trim($name),
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => self::hash($plain),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ])->save();

        $this->activity->log('api_token.created', __('Created API token ":name"', ['name' => $token->name]), null, [
            'id' => $token->id,
            'abilities' => $abilities,
            'expires_at' => $expiresAt?->toIso8601String(),
        ], $owner);

        return [$token, $plain];
    }

    public function revoke(User $actor, ApiToken $token): void
    {
        if ($token->isRevoked()) {
            return;
        }

        $token->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();

        $description = $token->user_id === $actor->id
            ? __('Revoked API token ":name"', ['name' => $token->name])
            : __('Revoked API token ":name" of :user', ['name' => $token->name, 'user' => $token->user?->name ?? '—']);

        $this->activity->log('api_token.revoked', $description, null, ['id' => $token->id, 'owner_id' => $token->user_id], $actor);
    }

    /** The usable token for a plain value, or null. */
    public function find(string $plain): ?ApiToken
    {
        if (! str_starts_with($plain, self::PREFIX) || strlen($plain) !== strlen(self::PREFIX) + self::RANDOM_LENGTH) {
            return null;
        }

        $hash = self::hash($plain);
        $token = ApiToken::query()->where('token_hash', $hash)->first();

        return $token !== null && hash_equals($token->token_hash, $hash) && $token->isUsable() ? $token : null;
    }

    /** Records use at most once a minute per token and address, to keep writes off every request. */
    public function touch(ApiToken $token, ?string $ip): void
    {
        if ($token->last_used_at !== null && $token->last_used_at->gt(now()->subMinute()) && $token->last_used_ip === $ip) {
            return;
        }

        ApiToken::query()->whereKey($token->id)->update(['last_used_at' => now(), 'last_used_ip' => $ip]);
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    protected static function random(int $length): string
    {
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, 61)];
        }

        return $out;
    }
}
