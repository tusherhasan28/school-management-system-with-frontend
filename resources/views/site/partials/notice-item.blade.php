<li>
    <a href="{{ $site->url('/notices/'.$notice->id) }}" class="group flex gap-4 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-[var(--site)]/40 hover:shadow-sm">
        <time datetime="{{ $notice->publishedOn()->toDateString() }}" class="flex w-14 shrink-0 flex-col items-center rounded-lg bg-[var(--site)]/10 py-1.5 text-[var(--site)]">
            <span class="text-xl leading-none font-bold">{{ $notice->publishedOn()->timezone($site->school->timezone)->locale($site->lang)->translatedFormat('d') }}</span>
            <span class="text-xs">{{ $notice->publishedOn()->timezone($site->school->timezone)->locale($site->lang)->translatedFormat('M') }}</span>
        </time>
        <span class="min-w-0">
            <span class="block font-semibold text-zinc-900 group-hover:text-[var(--site)]">@if ($notice->is_pinned)<span class="mr-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">{{ __('Important') }}</span>@endif{{ $notice->local('title') }}</span>
            <span class="mt-1 line-clamp-2 block text-sm text-zinc-600">{{ \App\Modules\Website\Services\Markdown::excerpt($notice->local('body'), 140) }}</span>
        </span>
    </a>
</li>
