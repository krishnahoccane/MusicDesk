<?php

namespace App\Plugins;

/**
 * plugin.json of an add-on:
 *
 * {
 *   "slug": "bulk-upload",                 // folder name, a-z 0-9 and dashes
 *   "name": "Bulk upload",
 *   "version": "1.0.0",
 *   "addon": "bulk_upload",                // license add-on code that unlocks it
 *   "description": "…",
 *   "author": "MusicDesk",
 *   "requires": "1.0.0",                   // lowest LabelDesk version
 *   "namespace": "LabelDesk\\Addons\\BulkUpload\\",   // PSR-4 prefix for src/
 *   "provider": "LabelDesk\\Addons\\BulkUpload\\BulkUploadServiceProvider"
 * }
 */
final class PluginManifest
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(public readonly array $data) {}

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new PluginException(__('plugin.json is not valid JSON.'));
        }

        $checks = [
            'slug' => '/^[a-z0-9][a-z0-9-]{1,58}[a-z0-9]$/',
            'version' => '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/',
            'addon' => '/^[a-z0-9_]{2,40}$/',
            'namespace' => '/^LabelDesk\\\\Addons\\\\[A-Z][A-Za-z0-9]*\\\\$/',
            'provider' => '/^LabelDesk\\\\Addons\\\\[A-Z][A-Za-z0-9]*\\\\[A-Z][A-Za-z0-9\\\\]*$/',
        ];
        foreach ($checks as $key => $pattern) {
            if (! is_string($data[$key] ?? null) || ! preg_match($pattern, $data[$key])) {
                throw new PluginException(__('plugin.json has a missing or invalid ":key".', ['key' => $key]));
            }
        }
        if (! is_string($data['name'] ?? null) || trim($data['name']) === '' || mb_strlen($data['name']) > 120) {
            throw new PluginException(__('plugin.json has a missing or invalid "name".'));
        }
        if (! str_starts_with($data['provider'], $data['namespace'])) {
            throw new PluginException(__('The plugin provider must be inside the plugin namespace.'));
        }
        if (isset($data['requires']) && ! preg_match('/^\d+\.\d+\.\d+/', (string) $data['requires'])) {
            throw new PluginException(__('plugin.json has an invalid "requires" version.'));
        }

        return new self($data);
    }

    public function slug(): string
    {
        return $this->data['slug'];
    }

    public function name(): string
    {
        return trim($this->data['name']);
    }

    public function version(): string
    {
        return $this->data['version'];
    }

    public function addon(): string
    {
        return $this->data['addon'];
    }

    public function description(): ?string
    {
        return isset($this->data['description']) ? mb_substr((string) $this->data['description'], 0, 1000) : null;
    }

    public function namespace(): string
    {
        return $this->data['namespace'];
    }

    public function provider(): string
    {
        return $this->data['provider'];
    }

    public function requires(): ?string
    {
        return $this->data['requires'] ?? null;
    }

    /** Whether this LabelDesk version is new enough. */
    public function compatible(): bool
    {
        $required = $this->requires();
        if ($required === null) {
            return true;
        }
        $current = preg_replace('/-.*$/', '', (string) config('labeldesk.product.version'));

        return version_compare($current, preg_replace('/-.*$/', '', $required), '>=');
    }
}
