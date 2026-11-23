<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Livewire's JavaScript `$wire` object already has functions with these names. A component method with the
 * same name is never reached from the browser (`wire:submit="upload"` calls Livewire's own file upload
 * with no file and crashes), although calling it from PHP tests works.
 */
class LivewireReservedMethodNamesTest extends TestCase
{
    private const RESERVED = ['upload', 'uploadMultiple', 'removeUpload', 'cancelUpload', 'get', 'set', 'call', 'commit',
        'refresh', 'on', 'dispatch', 'dispatchTo', 'dispatchSelf', 'watch', 'entangle', 'intercept', 'js', 'parent', 'el', 'id'];

    public function test_no_component_uses_a_reserved_method_name(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            $this->phpFiles($root.'/app/Livewire'),
            $this->phpFiles($root.'/addons'),
        );

        $names = implode('|', array_map('preg_quote', self::RESERVED));
        $clashes = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (! preg_match('/class\s+(\w+)[^{]*extends\s+(?:\\\\?Livewire\\\\)?Component\b/', $source, $cls)) {
                continue;
            }
            if (preg_match_all('/public\s+(?:static\s+)?function\s+('.$names.')\s*\(/', $source, $m)) {
                foreach ($m[1] as $method) {
                    $clashes[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file).': '.$cls[1].'::'.$method;
                }
            }
        }

        $this->assertSame([], $clashes, 'Rename these Livewire methods; the browser cannot call them');
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->getExtension() === 'php' && ! str_contains($f->getPathname(), DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }
}
