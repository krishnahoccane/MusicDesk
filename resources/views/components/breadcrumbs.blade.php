@props(['items' => []])
{{--
    Breadcrumbs. Items: [['label' => 'Artists', 'url' => route('artists.index')], ['label' => 'Edit']]
    The final item is rendered as the current page.
--}}
@if (count($items))
    <nav aria-label="{{ __('Breadcrumb') }}" {{ $attributes }}>
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="{{ route('dashboard') }}" aria-label="{{ __('Dashboard') }}"><x-icon name="house" size="14" /></a>
            </li>
            @foreach ($items as $item)
                @if ($loop->last || empty($item['url']))
                    <li class="breadcrumb-item active" @if ($loop->last) aria-current="page" @endif>{{ $item['label'] }}</li>
                @else
                    <li class="breadcrumb-item"><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>
                @endif
            @endforeach
        </ol>
    </nav>
@endif
