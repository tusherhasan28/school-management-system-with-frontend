<a href="{{ $site->url('/') }}" class="flex min-w-0 items-center gap-3">
    @if ($site->logoUrl())
        <img src="{{ $site->logoUrl() }}" alt="" class="size-12 shrink-0 rounded-full bg-white object-contain p-0.5 {{ $ring ?? '' }}" width="48" height="48">
    @else
        <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-[var(--site)] text-lg font-bold text-white">{{ mb_substr($site->name(), 0, 1) }}</span>
    @endif
    <span class="min-w-0">
        <span class="block truncate text-lg leading-tight font-bold {{ $nameClass ?? 'text-zinc-900' }}">{{ $site->name() }}</span>
        @if ($site->settings->local('tagline'))<span class="block truncate text-xs {{ $tagClass ?? 'text-zinc-500' }}">{{ $site->settings->local('tagline') }}</span>@endif
    </span>
</a>
