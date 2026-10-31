@extends('site.layout')

@section('content')
@include('site.partials.page-title', ['title' => __('Events')])
<div class="mx-auto max-w-4xl px-4 py-10">
    @foreach ([[__('Upcoming events'), $upcoming], [__('Past events'), $past]] as [$heading, $list])
        <section class="mb-10">
            <h2 class="text-xl font-bold text-zinc-900">{{ $heading }}</h2>
            <ul class="mt-4 grid gap-3">
                @forelse ($list as $event)
                    <li class="flex gap-4 rounded-xl border border-zinc-200 p-4">
                        <span class="flex w-16 shrink-0 flex-col items-center rounded-lg bg-[var(--site)]/10 py-2 text-[var(--site)]">
                            <span class="text-2xl leading-none font-bold">{{ $event->starts_on->locale($site->lang)->translatedFormat('d') }}</span>
                            <span class="text-xs">{{ $event->starts_on->locale($site->lang)->translatedFormat('M Y') }}</span>
                        </span>
                        <span>
                            <span class="block font-semibold">{{ $event->local('title') }}</span>
                            <span class="block text-sm text-zinc-500">
                                @if ($event->ends_on && ! $event->ends_on->isSameDay($event->starts_on)) {{ __('Until :date', ['date' => $event->ends_on->locale($site->lang)->translatedFormat('j F')]) }} · @endif
                                @if ($event->starts_at) {{ substr($event->starts_at, 0, 5) }} · @endif
                                {{ $event->location }}
                            </span>
                            @if ($event->local('description'))<span class="prose-site mt-2 block text-sm">{!! \App\Modules\Website\Services\Markdown::render($event->local('description')) !!}</span>@endif
                        </span>
                    </li>
                @empty
                    <li class="text-sm text-zinc-500">{{ __('Nothing here yet.') }}</li>
                @endforelse
            </ul>
        </section>
    @endforeach
</div>
@endsection
