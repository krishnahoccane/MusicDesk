{{-- Shown on every page while a MusicDesk support session is active (SupportSession middleware). --}}
@isset($musicdeskSupport)
    <div class="ld-ribbon ld-ribbon--support" role="status">
        <x-icon name="life-buoy" size="16" />
        <span>
            <strong>{{ __('MusicDesk support session') }}</strong> &middot;
            {{ __('Signed in by :by for support.', ['by' => $musicdeskSupport['by'] ?? __('MusicDesk support')]) }}
            {{ __('Sign out when you are done.') }}
        </span>
        <form method="POST" action="{{ route('logout') }}" class="ms-auto">
            @csrf
            <button type="submit" class="ld-ribbon__link" style="background:none;border:0;cursor:pointer">{{ __('Sign out') }} &rarr;</button>
        </form>
    </div>
@endisset
