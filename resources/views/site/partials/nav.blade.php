{{-- $variant: light | dark --}}
<nav aria-label="{{ __('Main') }}" class="{{ $class ?? '' }}">
    <ul class="flex flex-col gap-1 lg:flex-row lg:items-center lg:gap-0.5">
        @foreach ($site->menu('header') as $item)
            <li>
                <a href="{{ $item['url'] }}" @if ($item['active']) aria-current="page" @endif
                   class="block rounded-md px-3 py-2 text-[15px] font-medium transition {{ ($variant ?? 'light') === 'dark' ? 'text-white/90 hover:bg-white/10 aria-[current=page]:bg-white/15 aria-[current=page]:text-white' : 'text-zinc-700 hover:bg-zinc-100 hover:text-[var(--site)] aria-[current=page]:text-[var(--site)]' }}">{{ $item['label'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
