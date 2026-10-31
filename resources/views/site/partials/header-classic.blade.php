{{-- Classic: contact strip, centred crest and name, coloured menu bar. Common on Bangladeshi school sites. --}}
<header>
    <div class="bg-zinc-900 text-xs text-zinc-200">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-1.5">
            <span class="flex flex-wrap gap-x-4">
                @if ($site->school->phone)<a href="tel:{{ $site->school->phone }}" class="hover:text-white">☎ {{ $site->school->phone }}</a>@endif
                @if ($site->school->email)<a href="mailto:{{ $site->school->email }}" class="hover:text-white">✉ {{ $site->school->email }}</a>@endif
                @if ($site->settings->eiin)<span>{{ __('EIIN') }}: {{ $site->settings->eiin }}</span>@endif
            </span>
            <span class="flex items-center gap-3">
                <a href="{{ url('login') }}" class="hover:text-white">{{ __('Log in') }}</a>
                @include('site.partials.lang-switch')
            </span>
        </div>
    </div>
    <div class="border-b border-zinc-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-center px-4 py-5">
            @include('site.partials.brand', ['nameClass' => 'text-zinc-900 text-xl sm:text-2xl'])
        </div>
    </div>
    <div class="sticky top-0 z-30 bg-[var(--site)] shadow">
        <div class="mx-auto max-w-6xl px-4">
            <details class="group lg:hidden">
                <summary class="flex cursor-pointer list-none items-center justify-between py-3 font-medium text-white">{{ __('Menu') }}<span aria-hidden class="text-xl group-open:rotate-45">＋</span></summary>
                @include('site.partials.nav', ['variant' => 'dark', 'class' => 'pb-3'])
            </details>
            @include('site.partials.nav', ['variant' => 'dark', 'class' => 'hidden py-1.5 lg:block'])
        </div>
    </div>
</header>
