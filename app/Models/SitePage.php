<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A page of the label website. Built-in types have fixed addresses (/about, /services,
 * /pricing, /contact); the label's own pages live under /pages/{slug}.
 *
 * content (JSON) by type:
 *   about     {intro, body}
 *   services  {intro, items: [{title, text}], outro}
 *   pricing   {intro, plans: [{name, price, period, text, features: [..], cta_label, cta_url, highlight}], note}
 *   contact   {intro, form: bool}
 *   custom    {body}
 * Text fields are Markdown (raw HTML is removed when shown).
 */
class SitePage extends Model
{
    public const TYPES = ['about', 'services', 'pricing', 'contact', 'custom'];

    /** Built-in pages: type => [address, default title]. */
    public const BUILT_IN = [
        'about' => ['about', 'About us'],
        'services' => ['services', 'Services'],
        'pricing' => ['pricing', 'Pricing'],
        'contact' => ['contact', 'Contact us'],
    ];

    protected $fillable = ['type', 'slug', 'title', 'menu_label', 'content', 'meta_description', 'is_published', 'show_in_menu', 'sort'];

    protected function casts(): array
    {
        return ['content' => 'array', 'is_published' => 'boolean', 'show_in_menu' => 'boolean', 'sort' => 'integer'];
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true);
    }

    public function url(): string
    {
        return $this->type === 'custom' ? route('site.page', $this->slug) : route('site.'.$this->type);
    }

    public function menuLabel(): string
    {
        return $this->menu_label ?: $this->title;
    }

    public function text(string $key): string
    {
        return (string) (($this->content ?? [])[$key] ?? '');
    }

    /** @return list<array<string, mixed>> */
    public function items(string $key): array
    {
        $items = ($this->content ?? [])[$key] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}
