<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')
    @include('livewire.settings._website-nav')

    @unless ($enabled)
        <x-alert variant="info" class="mb-3">{{ __('The website is off. You can prepare pages now; switch the website on under “Website and theme” when you are ready.') }}</x-alert>
    @endunless

    @if ($creating || $editingId)
        {{-- ------------------------------------------------ editor --}}
        <form class="card mb-3" wire:submit="save" novalidate wire:key="page-editor-{{ $editingId ?? 'new' }}">
            <div class="card-header">
                <x-icon name="file-pen" size="18" />
                <h2 class="card-title">{{ $creating ? __('New page') : __('Edit :title', ['title' => $title]) }}</h2>
                <span class="ms-auto small text-muted">{{ $creating ? __('Your own page') : ($type === 'custom' ? __('Your own page') : __('Built-in page')) }}</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="p-title">{{ __('Title') }}</label>
                        <input id="p-title" class="form-control @error('title') is-invalid @enderror" wire:model.blur="title" maxlength="150">
                        @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="p-menu">{{ __('Menu label') }}</label>
                        <input id="p-menu" class="form-control @error('menu_label') is-invalid @enderror" wire:model="menu_label" maxlength="40" placeholder="{{ $title }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="p-slug">{{ __('Address') }}</label>
                        @if ($creating || $type === 'custom')
                            <div class="input-group">
                                <span class="input-group-text small">/pages/</span>
                                <input id="p-slug" class="form-control font-mono @error('slug') is-invalid @enderror" wire:model="slug" maxlength="80" placeholder="{{ \Illuminate\Support\Str::slug($title) }}">
                            </div>
                            @error('slug') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        @else
                            <input id="p-slug" class="form-control font-mono" value="/{{ $slug }}" disabled>
                        @endif
                    </div>
                </div>

                <hr class="my-4">

                @switch($creating ? 'custom' : $type)
                    @case('about')
                        <div class="mb-3">
                            <label class="form-label" for="c-intro">{{ __('Introduction') }}</label>
                            <textarea id="c-intro" rows="2" class="form-control" wire:model="content.intro" maxlength="3000"></textarea>
                        </div>
                        <label class="form-label" for="c-body">{{ __('Story') }}</label>
                        <textarea id="c-body" rows="12" class="form-control font-mono @error('content.body') is-invalid @enderror" wire:model="content.body"></textarea>
                        @error('content.body') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        @break

                    @case('services')
                        <div class="mb-3">
                            <label class="form-label" for="c-intro">{{ __('Introduction') }}</label>
                            <textarea id="c-intro" rows="2" class="form-control" wire:model="content.intro" maxlength="3000"></textarea>
                        </div>
                        <div class="d-flex align-items-center mb-2">
                            <h3 class="h6 mb-0">{{ __('Services') }}</h3>
                            <button type="button" class="btn btn-sm btn-light ms-auto" wire:click="addItem('items')"><x-icon name="plus" size="14" /> {{ __('Add service') }}</button>
                        </div>
                        @forelse ($content['items'] ?? [] as $i => $item)
                            <div class="border rounded-3 p-3 mb-2" wire:key="svc-{{ $i }}">
                                <div class="d-flex gap-2 mb-2">
                                    <input class="form-control @error('content.items.'.$i.'.title') is-invalid @enderror" wire:model="content.items.{{ $i }}.title" maxlength="120" placeholder="{{ __('e.g. Digital distribution') }}" aria-label="{{ __('Service title') }}">
                                    <button type="button" class="btn btn-light btn-sm" wire:click="moveItem('items', {{ $i }}, -1)" @disabled($loop->first) aria-label="{{ __('Move up') }}"><x-icon name="arrow-up" size="14" /></button>
                                    <button type="button" class="btn btn-light btn-sm" wire:click="moveItem('items', {{ $i }}, 1)" @disabled($loop->last) aria-label="{{ __('Move down') }}"><x-icon name="arrow-down" size="14" /></button>
                                    <button type="button" class="btn btn-light btn-sm text-danger" wire:click="removeItem('items', {{ $i }})" aria-label="{{ __('Remove') }}"><x-icon name="x" size="14" /></button>
                                </div>
                                @error('content.items.'.$i.'.title') <div class="small text-danger mb-2">{{ $message }}</div> @enderror
                                <textarea class="form-control" rows="3" wire:model="content.items.{{ $i }}.text" maxlength="2000" placeholder="{{ __('What you offer, in a few sentences.') }}" aria-label="{{ __('Description') }}"></textarea>
                            </div>
                        @empty
                            <p class="small text-muted">{{ __('No services yet.') }}</p>
                        @endforelse
                        <div class="mt-3">
                            <label class="form-label" for="c-outro">{{ __('Closing text') }}</label>
                            <textarea id="c-outro" rows="2" class="form-control" wire:model="content.outro" maxlength="3000"></textarea>
                        </div>
                        @break

                    @case('pricing')
                        <div class="mb-3">
                            <label class="form-label" for="c-intro">{{ __('Introduction') }}</label>
                            <textarea id="c-intro" rows="2" class="form-control" wire:model="content.intro" maxlength="3000"></textarea>
                        </div>
                        <div class="d-flex align-items-center mb-2">
                            <h3 class="h6 mb-0">{{ __('Plans') }}</h3>
                            <button type="button" class="btn btn-sm btn-light ms-auto" wire:click="addItem('plans')"><x-icon name="plus" size="14" /> {{ __('Add plan') }}</button>
                        </div>
                        <div class="row g-2">
                            @forelse ($content['plans'] ?? [] as $i => $plan)
                                <div class="col-lg-6" wire:key="plan-{{ $i }}">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="row g-2">
                                            <div class="col-7">
                                                <input class="form-control @error('content.plans.'.$i.'.name') is-invalid @enderror" wire:model="content.plans.{{ $i }}.name" maxlength="80" placeholder="{{ __('Plan name') }}" aria-label="{{ __('Plan name') }}">
                                            </div>
                                            <div class="col-5 d-flex gap-1 justify-content-end">
                                                <button type="button" class="btn btn-light btn-sm" wire:click="moveItem('plans', {{ $i }}, -1)" @disabled($loop->first) aria-label="{{ __('Move left') }}"><x-icon name="arrow-left" size="14" /></button>
                                                <button type="button" class="btn btn-light btn-sm" wire:click="moveItem('plans', {{ $i }}, 1)" @disabled($loop->last) aria-label="{{ __('Move right') }}"><x-icon name="arrow-right" size="14" /></button>
                                                <button type="button" class="btn btn-light btn-sm text-danger" wire:click="removeItem('plans', {{ $i }})" aria-label="{{ __('Remove') }}"><x-icon name="x" size="14" /></button>
                                            </div>
                                            <div class="col-6">
                                                <input class="form-control @error('content.plans.'.$i.'.price') is-invalid @enderror" wire:model="content.plans.{{ $i }}.price" maxlength="40" placeholder="{{ __('Price') }}" aria-label="{{ __('Price') }}">
                                            </div>
                                            <div class="col-6">
                                                <input class="form-control" wire:model="content.plans.{{ $i }}.period" maxlength="40" placeholder="{{ __('per release / month') }}" aria-label="{{ __('Period') }}">
                                            </div>
                                            @error('content.plans.'.$i.'.name') <div class="col-12 small text-danger">{{ $message }}</div> @enderror
                                            @error('content.plans.'.$i.'.price') <div class="col-12 small text-danger">{{ $message }}</div> @enderror
                                            <div class="col-12">
                                                <input class="form-control" wire:model="content.plans.{{ $i }}.text" maxlength="300" placeholder="{{ __('Short description') }}" aria-label="{{ __('Short description') }}">
                                            </div>
                                            <div class="col-12">
                                                <textarea class="form-control" rows="4" wire:model="content.plans.{{ $i }}.features" maxlength="3000" placeholder="{{ __('One feature per line') }}" aria-label="{{ __('Features') }}"></textarea>
                                            </div>
                                            <div class="col-5">
                                                <input class="form-control" wire:model="content.plans.{{ $i }}.cta_label" maxlength="40" placeholder="{{ __('Button text') }}" aria-label="{{ __('Button text') }}">
                                            </div>
                                            <div class="col-7">
                                                <input class="form-control @error('content.plans.'.$i.'.cta_url') is-invalid @enderror" wire:model="content.plans.{{ $i }}.cta_url" maxlength="255" placeholder="/contact" aria-label="{{ __('Button link') }}">
                                                @error('content.plans.'.$i.'.cta_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                            </div>
                                            <div class="col-12">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="plan-hl-{{ $i }}" wire:model="content.plans.{{ $i }}.highlight">
                                                    <label class="form-check-label small" for="plan-hl-{{ $i }}">{{ __('Highlight as “Most popular”') }}</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <p class="small text-muted">{{ __('No plans yet.') }}</p>
                            @endforelse
                        </div>
                        <div class="mt-3">
                            <label class="form-label" for="c-note">{{ __('Note under the plans') }}</label>
                            <textarea id="c-note" rows="2" class="form-control" wire:model="content.note" maxlength="3000" placeholder="{{ __('e.g. Prices exclude taxes.') }}"></textarea>
                        </div>
                        @break

                    @case('contact')
                        <div class="mb-3">
                            <label class="form-label" for="c-intro">{{ __('Introduction') }}</label>
                            <textarea id="c-intro" rows="3" class="form-control" wire:model="content.intro" maxlength="3000"></textarea>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="c-form" wire:model="content.form">
                            <label class="form-check-label" for="c-form">{{ __('Show a contact form') }}</label>
                        </div>
                        <div class="form-text">
                            {{ $contactEmail ? __('Messages are e-mailed to :email and kept below.', ['email' => $contactEmail]) : __('Messages are kept below. Add a public e-mail under “Website and theme” to receive them by e-mail too.') }}
                            {{ __('E-mail, phone and address come from “Website and theme”.') }}
                        </div>
                        @break

                    @default
                        <label class="form-label" for="c-body">{{ __('Text') }}</label>
                        <textarea id="c-body" rows="14" class="form-control font-mono @error('content.body') is-invalid @enderror" wire:model="content.body"></textarea>
                        @error('content.body') <div class="invalid-feedback">{{ $message }}</div> @enderror
                @endswitch

                @if (($creating ? 'custom' : $type) !== 'contact')
                    <div class="form-text">{{ __('Formatting: **bold**, *italic*, # Heading, - list item, [link](https://…). Empty lines start a new paragraph.') }}</div>
                @endif

                <hr class="my-4">

                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label" for="p-meta">{{ __('Search engine description') }}</label>
                        <input id="p-meta" class="form-control @error('meta_description') is-invalid @enderror" wire:model="meta_description" maxlength="160">
                    </div>
                    <div class="col-md-6 d-flex flex-wrap gap-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="p-pub" wire:model="is_published">
                            <label class="form-check-label" for="p-pub">{{ __('Published') }}</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="p-menu-show" wire:model="show_in_menu">
                            <label class="form-check-label" for="p-menu-show">{{ __('Show in the menu') }}</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" wire:click="cancel">{{ __('Cancel') }}</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    {{ __('Save page') }}
                </button>
            </div>
        </form>
    @endif

    {{-- ------------------------------------------------ pages --}}
    <div class="card mb-3">
        <div class="card-header">
            <x-icon name="files" size="18" />
            <h2 class="card-title">{{ __('Pages') }}</h2>
            <button type="button" class="btn btn-sm btn-primary ms-auto" wire:click="create"><x-icon name="plus" size="14" /> {{ __('New page') }}</button>
        </div>
        <ul class="ld-list">
            <li class="ld-list__item">
                <span class="flex-grow-1">
                    <span class="fw-semibold" style="color: var(--ld-heading)">{{ __('Home') }}</span>
                    <span class="d-block small text-muted">/ · {{ __('Edited under “Website and theme”.') }}</span>
                </span>
                <a href="{{ route('settings.website') }}" class="btn btn-sm btn-light">{{ __('Edit') }}</a>
            </li>
            @foreach ($pages as $page)
                <li class="ld-list__item flex-wrap" wire:key="page-{{ $page->id }}">
                    <span class="flex-grow-1 min-w-0">
                        <span class="d-flex flex-wrap align-items-center gap-2">
                            <span class="fw-semibold" style="color: var(--ld-heading)">{{ $page->title }}</span>
                            @if ($page->is_published) <x-badge variant="success">{{ __('published') }}</x-badge> @else <x-badge>{{ __('not published') }}</x-badge> @endif
                            @if ($page->is_published && ! $page->show_in_menu) <x-badge variant="info">{{ __('not in menu') }}</x-badge> @endif
                        </span>
                        <span class="d-block small text-muted font-mono">{{ $page->type === 'custom' ? '/pages/'.$page->slug : '/'.$page->slug }}</span>
                    </span>
                    <span class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-light" wire:click="move({{ $page->id }}, -1)" @disabled($loop->first) aria-label="{{ __('Move up') }}"><x-icon name="arrow-up" size="14" /></button>
                        <button type="button" class="btn btn-sm btn-light" wire:click="move({{ $page->id }}, 1)" @disabled($loop->last) aria-label="{{ __('Move down') }}"><x-icon name="arrow-down" size="14" /></button>
                        <button type="button" class="btn btn-sm btn-light" wire:click="togglePublished({{ $page->id }})">{{ $page->is_published ? __('Unpublish') : __('Publish') }}</button>
                        <button type="button" class="btn btn-sm btn-light" wire:click="edit({{ $page->id }})">{{ __('Edit') }}</button>
                        @if ($enabled && $page->is_published)
                            <a href="{{ $page->url() }}" target="_blank" rel="noopener" class="btn btn-sm btn-ghost" aria-label="{{ __('View') }}"><x-icon name="external-link" size="14" /></a>
                        @endif
                        @if ($page->type === 'custom')
                            <button type="button" class="btn btn-sm btn-ghost text-danger" wire:click="delete({{ $page->id }})"
                                    wire:confirm="{{ __('Delete the page :title?', ['title' => $page->title]) }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="14" /></button>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- ------------------------------------------------ messages --}}
    <div class="card" id="messages">
        <div class="card-header">
            <x-icon name="inbox" size="18" />
            <h2 class="card-title">{{ __('Messages') }}</h2>
            @if ($unread > 0) <span class="badge rounded-pill text-bg-danger">{{ $unread }}</span> @endif
        </div>
        @if ($messages->isEmpty())
            <x-empty-state icon="inbox" :title="__('No messages yet')" :description="__('Messages sent with the website’s contact form appear here.')" />
        @else
            <ul class="ld-list">
                @foreach ($messages as $msg)
                    <li class="ld-list__item align-items-start" wire:key="msg-{{ $msg->id }}">
                        <span class="flex-grow-1 min-w-0">
                            <span class="d-flex flex-wrap align-items-center gap-2">
                                <span @class(['fw-semibold' => ! $msg->read_at]) style="color: var(--ld-heading)">{{ $msg->name }}</span>
                                <a href="mailto:{{ $msg->email }}" class="small">{{ $msg->email }}</a>
                                <span class="small text-muted">{{ $msg->created_at->diffForHumans() }}</span>
                                @unless ($msg->mailed) <x-badge variant="warning">{{ __('not e-mailed') }}</x-badge> @endunless
                            </span>
                            @if ($msg->subject) <span class="d-block fw-semibold small mt-1">{{ $msg->subject }}</span> @endif
                            <span class="d-block small text-muted mt-1" style="white-space: pre-line">{{ $msg->message }}</span>
                        </span>
                        <span class="d-flex gap-1">
                            @unless ($msg->read_at)
                                <button type="button" class="btn btn-sm btn-light" wire:click="markRead({{ $msg->id }})">{{ __('Mark read') }}</button>
                            @endunless
                            <button type="button" class="btn btn-sm btn-ghost text-danger" wire:click="deleteMessage({{ $msg->id }})"
                                    wire:confirm="{{ __('Delete this message?') }}" aria-label="{{ __('Delete') }}"><x-icon name="trash-2" size="14" /></button>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
