<?php

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;
use Illuminate\View\Component;

/**
 * Renders a Lucide icon inline from resources/icons/lucide.
 *
 * Usage: <x-icon name="music" size="18" class="me-1" />
 *
 * Icons are rendered on the server so they survive Livewire DOM morphing and
 * do not require JavaScript. Icon names are strictly validated so a name can
 * never be used to read an arbitrary file.
 */
class Icon extends Component
{
    /** @var array<string, string> */
    protected static array $cache = [];

    public function __construct(
        public string $name,
        public int|string $size = 18,
        public int|string $stroke = 2,
    ) {}

    public static function paths(string $name): string
    {
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name)) {
            return '';
        }

        if (array_key_exists($name, static::$cache)) {
            return static::$cache[$name];
        }

        $file = resource_path('icons/lucide/'.$name.'.svg');

        return static::$cache[$name] = File::exists($file) ? (string) File::get($file) : '';
    }

    public function svgPaths(): string
    {
        return static::paths($this->name);
    }

    public function render(): View
    {
        return view('components.icon');
    }
}
