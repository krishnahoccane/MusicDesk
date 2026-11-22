<?php

namespace LabelDesk\Addons\RestApi\Support;

use App\Models\User;

/**
 * Token abilities (scopes). A request needs the token ability AND the
 * owner's own permission (policies), so a token can never do more than its
 * owner can do in the app. Write implies read.
 */
final class Abilities
{
    /**
     * resource => label, license feature, and the app permissions that make
     * read / write useful for a user (write needs any one of them).
     */
    public const RESOURCES = [
        'artists' => ['label' => 'Artists', 'feature' => 'artists',
            'read' => ['artists.view'], 'write' => ['artists.create', 'artists.update', 'artists.delete']],
        'releases' => ['label' => 'Releases', 'feature' => 'releases',
            'read' => ['releases.view'], 'write' => ['releases.create', 'releases.update', 'releases.delete', 'releases.submit', 'releases.review']],
        'tracks' => ['label' => 'Tracks', 'feature' => 'tracks',
            'read' => ['tracks.view'], 'write' => ['tracks.create', 'tracks.update', 'tracks.delete']],
        'contributors' => ['label' => 'Contributors', 'feature' => 'releases',
            'read' => ['contributors.view'], 'write' => ['contributors.manage']],
    ];

    /** Whether the user's role could use this level on this resource at all. */
    public static function userCan(User $user, string $resource, string $level): bool
    {
        foreach (self::RESOURCES[$resource][$level] ?? [] as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> every ability name, e.g. "artists:read" */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::RESOURCES) as $resource) {
            $out[] = $resource.':read';
            $out[] = $resource.':write';
        }

        return $out;
    }

    /**
     * Valid, de-duplicated abilities in canonical order; ":write" adds ":read".
     *
     * @param  iterable<mixed>  $abilities
     * @return list<string>
     */
    public static function normalize(iterable $abilities): array
    {
        $valid = self::all();
        $wanted = [];
        foreach ($abilities as $ability) {
            if (! is_string($ability) || ! in_array($ability, $valid, true)) {
                continue;
            }
            $wanted[$ability] = true;
            if (str_ends_with($ability, ':write')) {
                $wanted[substr($ability, 0, -strlen(':write')).':read'] = true;
            }
        }

        return array_values(array_filter($valid, fn (string $a) => isset($wanted[$a])));
    }

    public static function label(string $ability): string
    {
        [$resource, $level] = array_pad(explode(':', $ability, 2), 2, '');
        $name = self::RESOURCES[$resource]['label'] ?? $resource;

        return __($name).' · '.($level === 'write' ? __('write') : __('read'));
    }
}
