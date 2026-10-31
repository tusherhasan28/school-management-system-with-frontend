<section class="border-b border-zinc-200 bg-zinc-50">
    <div class="mx-auto max-w-6xl px-4 py-10">
        <p class="text-sm font-medium text-[var(--site)]">{{ $site->name() }}</p>
        <h1 class="mt-1 text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl">{{ $title }}</h1>
        @isset($subtitle)<p class="mt-2 max-w-2xl text-zinc-600">{{ $subtitle }}</p>@endisset
    </div>
</section>
