<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum LinkPlatform: string
{
    use HasOptions;

    case Spotify = 'spotify';
    case AppleMusic = 'apple_music';
    case YouTube = 'youtube';
    case SoundCloud = 'soundcloud';
    case Bandcamp = 'bandcamp';
    case Deezer = 'deezer';
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case Facebook = 'facebook';
    case X = 'x';
    case Website = 'website';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spotify => 'Spotify',
            self::AppleMusic => 'Apple Music',
            self::YouTube => 'YouTube',
            self::SoundCloud => 'SoundCloud',
            self::Bandcamp => 'Bandcamp',
            self::Deezer => 'Deezer',
            self::Instagram => 'Instagram',
            self::TikTok => 'TikTok',
            self::Facebook => 'Facebook',
            self::X => 'X (Twitter)',
            self::Website => __('Website'),
            self::Other => __('Other'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::YouTube => 'youtube',
            self::Instagram => 'instagram',
            self::Facebook => 'facebook',
            self::X => 'twitter',
            self::SoundCloud => 'cloud',
            self::Website, self::Other => 'globe',
            default => 'music-2',
        };
    }

    /**
     * Expected host names (suffix match). Null = any host.
     *
     * @return list<string>|null
     */
    public function hosts(): ?array
    {
        return match ($this) {
            self::Spotify => ['spotify.com', 'spotify.link'],
            self::AppleMusic => ['music.apple.com', 'itunes.apple.com'],
            self::YouTube => ['youtube.com', 'youtu.be'],
            self::SoundCloud => ['soundcloud.com'],
            self::Bandcamp => ['bandcamp.com'],
            self::Deezer => ['deezer.com', 'deezer.page.link'],
            self::Instagram => ['instagram.com'],
            self::TikTok => ['tiktok.com'],
            self::Facebook => ['facebook.com', 'fb.com'],
            self::X => ['x.com', 'twitter.com'],
            self::Website, self::Other => null,
        };
    }

    public function accepts(string $url): bool
    {
        $hosts = $this->hosts();
        if ($hosts === null) {
            return true;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach ($hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
