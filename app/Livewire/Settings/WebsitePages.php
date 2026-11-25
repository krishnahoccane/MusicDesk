<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\SiteMessage;
use App\Models\SitePage;
use App\Services\Activity\ActivityLogger;
use App\Services\Website\WebsiteContent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Settings → Website → Pages: about, services, pricing and contact pages, the label's own
 * pages, and the messages sent with the contact form.
 */
class WebsitePages extends Component
{
    use EnforcesLicense;

    public ?int $editingId = null;

    public bool $creating = false;

    public string $type = 'custom';

    public string $title = '';

    public string $slug = '';

    public string $menu_label = '';

    public string $meta_description = '';

    public bool $is_published = false;

    public bool $show_in_menu = true;

    /** @var array<string, mixed> type-specific content */
    public array $content = [];

    public function mount(WebsiteContent $site): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $site->ensureBuiltInPages();
    }

    protected function guard(): void
    {
        abort_unless(auth()->user()->can('settings.manage'), 403);
        $this->ensureLicenseAllowsWrites();
    }

    // ------------------------------------------------------------ list

    public function create(): void
    {
        $this->guard();
        $this->resetEditor();
        $this->creating = true;
        $this->content = ['body' => ''];
    }

    public function edit(int $id): void
    {
        $page = SitePage::query()->findOrFail($id);
        $this->resetEditor();
        $this->editingId = $page->id;
        $this->type = $page->type;
        $this->title = $page->title;
        $this->slug = $page->slug;
        $this->menu_label = (string) $page->menu_label;
        $this->meta_description = (string) $page->meta_description;
        $this->is_published = $page->is_published;
        $this->show_in_menu = $page->show_in_menu;
        $this->content = $this->normalise($page->type, $page->content ?? []);
    }

    public function cancel(): void
    {
        $this->resetEditor();
    }

    protected function resetEditor(): void
    {
        $this->reset('editingId', 'creating', 'type', 'title', 'slug', 'menu_label', 'meta_description', 'is_published', 'show_in_menu', 'content');
        $this->resetValidation();
    }

    public function togglePublished(int $id, ActivityLogger $activity): void
    {
        $this->guard();
        $page = SitePage::query()->findOrFail($id);
        $page->forceFill(['is_published' => ! $page->is_published])->save();
        $activity->log('website.page', $page->is_published ? __('Published the page :title', ['title' => $page->title]) : __('Unpublished the page :title', ['title' => $page->title]), $page);
    }

    public function move(int $id, int $direction): void
    {
        $this->guard();
        $pages = SitePage::query()->orderBy('sort')->orderBy('id')->get()->values();
        $index = $pages->search(fn ($p) => $p->id === $id);
        $target = $index + ($direction < 0 ? -1 : 1);
        if ($index === false || ! isset($pages[$target])) {
            return;
        }
        $order = $pages->pluck('id')->all();
        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
        foreach ($order as $i => $pageId) {
            SitePage::query()->whereKey($pageId)->update(['sort' => ($i + 1) * 10]);
        }
    }

    public function delete(int $id, ActivityLogger $activity): void
    {
        $this->guard();
        $page = SitePage::query()->where('type', 'custom')->findOrFail($id);
        $page->delete();
        $activity->log('website.page', __('Deleted the page :title', ['title' => $page->title]));
        if ($this->editingId === $id) {
            $this->resetEditor();
        }
        $this->dispatch('toast', type: 'success', message: __('The page :title was deleted.', ['title' => $page->title]));
    }

    // ------------------------------------------------------------ repeaters

    public function addItem(string $list): void
    {
        abort_unless(in_array($list, ['items', 'plans'], true), 422);
        $this->content[$list] ??= [];
        $this->content[$list][] = $list === 'plans'
            ? ['name' => '', 'price' => '', 'period' => '', 'text' => '', 'features' => '', 'cta_label' => '', 'cta_url' => '', 'highlight' => false]
            : ['title' => '', 'text' => ''];
    }

    public function removeItem(string $list, int $index): void
    {
        abort_unless(in_array($list, ['items', 'plans'], true), 422);
        unset($this->content[$list][$index]);
        $this->content[$list] = array_values($this->content[$list] ?? []);
    }

    public function moveItem(string $list, int $index, int $direction): void
    {
        abort_unless(in_array($list, ['items', 'plans'], true), 422);
        $target = $index + ($direction < 0 ? -1 : 1);
        if (isset($this->content[$list][$index], $this->content[$list][$target])) {
            [$this->content[$list][$index], $this->content[$list][$target]] = [$this->content[$list][$target], $this->content[$list][$index]];
        }
    }

    // ------------------------------------------------------------ save

    public function save(ActivityLogger $activity): void
    {
        $this->guard();
        if (! $this->creating && ! $this->editingId) {
            return;
        }

        if ($this->creating || $this->type === 'custom') {
            $this->slug = Str::slug($this->slug !== '' ? $this->slug : $this->title);
        }

        $rules = [
            'title' => ['required', 'string', 'max:150'],
            'menu_label' => ['nullable', 'string', 'max:40'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'is_published' => ['boolean'],
            'show_in_menu' => ['boolean'],
            'content.intro' => ['nullable', 'string', 'max:3000'],
            'content.body' => ['nullable', 'string', 'max:50000'],
            'content.outro' => ['nullable', 'string', 'max:3000'],
            'content.note' => ['nullable', 'string', 'max:3000'],
            'content.form' => ['boolean'],
            'content.items' => ['array', 'max:24'],
            'content.items.*.title' => ['required', 'string', 'max:120'],
            'content.items.*.text' => ['nullable', 'string', 'max:2000'],
            'content.plans' => ['array', 'max:8'],
            'content.plans.*.name' => ['required', 'string', 'max:80'],
            'content.plans.*.price' => ['required', 'string', 'max:40'],
            'content.plans.*.period' => ['nullable', 'string', 'max:40'],
            'content.plans.*.text' => ['nullable', 'string', 'max:300'],
            'content.plans.*.features' => ['nullable', 'string', 'max:3000'],
            'content.plans.*.cta_label' => ['nullable', 'string', 'max:40'],
            'content.plans.*.cta_url' => ['nullable', 'string', 'max:255', 'regex:/^(https:\/\/|mailto:|\/)/i'],
            'content.plans.*.highlight' => ['boolean'],
        ];
        if ($this->creating || $this->type === 'custom') {
            $rules['slug'] = ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9\-]*$/',
                Rule::unique('site_pages', 'slug')->ignore($this->editingId)];
        }

        $this->validate($rules, [
            'content.items.*.title.required' => __('Give the service a title or remove it.'),
            'content.plans.*.name.required' => __('Give the plan a name or remove it.'),
            'content.plans.*.price.required' => __('Enter the price (e.g. “₹4,999” or “Free”).'),
            'content.plans.*.cta_url.regex' => __('Use an https:// link, a mailto: address or a page address starting with /.'),
            'slug.regex' => __('Use lower-case letters, numbers and dashes.'),
        ], ['slug' => __('address'), 'content.body' => __('text')]);

        $attributes = [
            'title' => trim($this->title),
            'menu_label' => trim($this->menu_label) ?: null,
            'meta_description' => trim($this->meta_description) ?: null,
            'is_published' => $this->is_published,
            'show_in_menu' => $this->show_in_menu,
            'content' => $this->normalise($this->creating ? 'custom' : $this->type, $this->content),
        ];

        if ($this->creating) {
            $page = SitePage::query()->create($attributes + [
                'type' => 'custom', 'slug' => $this->slug, 'sort' => (int) SitePage::query()->max('sort') + 10,
            ]);
            $activity->log('website.page', __('Created the page :title', ['title' => $page->title]), $page);
        } else {
            $page = SitePage::query()->findOrFail($this->editingId);
            $page->fill($attributes + ($page->type === 'custom' ? ['slug' => $this->slug] : []))->save();
            $activity->log('website.page', __('Updated the page :title', ['title' => $page->title]), $page);
        }

        $this->resetEditor();
        $this->dispatch('toast', type: 'success', message: $page->is_published
            ? __(':title is saved and published.', ['title' => $page->title])
            : __(':title is saved (not published yet).', ['title' => $page->title]));
    }

    /** Keep only the fields of the page type. */
    protected function normalise(string $type, array $content): array
    {
        $text = fn (string $k) => trim((string) ($content[$k] ?? ''));

        return match ($type) {
            'about' => ['intro' => $text('intro'), 'body' => $text('body')],
            'services' => ['intro' => $text('intro'), 'outro' => $text('outro'), 'items' => array_values(array_map(
                fn ($i) => ['title' => trim((string) ($i['title'] ?? '')), 'text' => trim((string) ($i['text'] ?? ''))],
                array_filter((array) ($content['items'] ?? []), 'is_array'),
            ))],
            'pricing' => ['intro' => $text('intro'), 'note' => $text('note'), 'plans' => array_values(array_map(fn ($p) => [
                'name' => trim((string) ($p['name'] ?? '')), 'price' => trim((string) ($p['price'] ?? '')),
                'period' => trim((string) ($p['period'] ?? '')), 'text' => trim((string) ($p['text'] ?? '')),
                'features' => trim((string) ($p['features'] ?? '')), 'cta_label' => trim((string) ($p['cta_label'] ?? '')),
                'cta_url' => trim((string) ($p['cta_url'] ?? '')), 'highlight' => (bool) ($p['highlight'] ?? false),
            ], array_filter((array) ($content['plans'] ?? []), 'is_array')))],
            'contact' => ['intro' => $text('intro'), 'form' => (bool) ($content['form'] ?? true)],
            default => ['body' => $text('body')],
        };
    }

    // ------------------------------------------------------------ messages

    public function markRead(int $id): void
    {
        $this->guard();
        SiteMessage::query()->whereKey($id)->update(['read_at' => now()]);
    }

    public function deleteMessage(int $id): void
    {
        $this->guard();
        SiteMessage::query()->whereKey($id)->delete();
    }

    public function render(WebsiteContent $site): View
    {
        return view('livewire.settings.website-pages', [
            'pages' => SitePage::query()->orderBy('sort')->orderBy('id')->get(),
            'messages' => SiteMessage::query()->latest()->limit(50)->get(),
            'unread' => SiteMessage::query()->whereNull('read_at')->count(),
            'enabled' => $site->enabled(),
            'contactEmail' => (string) $site->settings()['contact_email'],
        ])->title(__('Website pages'))->layoutData(['breadcrumbs' => [
            ['label' => __('Settings'), 'url' => route('settings.index')], ['label' => __('Website'), 'url' => route('settings.website')], ['label' => __('Pages')],
        ]]);
    }
}
