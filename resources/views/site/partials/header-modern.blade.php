{{-- Modern: one light sticky bar with the brand on the left and the menu on the right. --}}
<header class="sticky top-0 z-30 border-b border-zinc-200/80 bg-white/90 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
        @include('site.partials.brand')
        <div class="hidden items-center gap-2 lg:flex">
            @include('site.partials.nav', ['variant' => 'light'])
            <span class="text-zinc-600">@include('site.partials.lang-switch')</span>
        </div>
        <details class="group relative lg:hidden">
            <summary class="flex size-10 cursor-pointer list-none items-center justify-center rounded-md border border-zinc-200 text-xl" aria-label="{{ __('Menu') }}">☰</summary>
            <div class="absolute right-0 mt-2 w-64 rounded-xl border border-zinc-200 bg-white p-2 shadow-lg">
                @include('site.partials.nav', ['variant' => 'light'])
                <div class="mt-2 border-t border-zinc-100 px-3 pt-2 text-zinc-600">@include('site.partials.lang-switch')</div>
            </div>
        </details>
    </div>
</header>
