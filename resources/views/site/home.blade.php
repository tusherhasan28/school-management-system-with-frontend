@extends('site.layout')

@section('content')
@php
    $s = $site->settings;
    $hero = \App\Modules\Website\Services\WebsiteMedia::url($s->hero_image_path);
    $principalPhoto = \App\Modules\Website\Services\WebsiteMedia::url($s->principal_photo_path);
@endphp

<section class="relative isolate overflow-hidden bg-zinc-900">
    @if ($hero)
        <img src="{{ $hero }}" alt="" class="absolute inset-0 -z-10 size-full object-cover opacity-45" fetchpriority="high">
    @else
        <div class="absolute inset-0 -z-10 bg-gradient-to-br from-[var(--site)] to-zinc-900"></div>
    @endif
    <div class="mx-auto max-w-6xl px-4 py-20 sm:py-28">
        <p class="text-sm font-medium tracking-wide text-white/80 uppercase">{{ $site->settings->local('tagline') }}</p>
        <h1 class="mt-3 max-w-3xl text-4xl leading-tight font-bold text-white sm:text-5xl">{{ $s->local('hero_title') ?? $site->name() }}</h1>
        @if ($s->local('hero_text'))<p class="mt-4 max-w-2xl text-lg text-white/85">{{ $s->local('hero_text') }}</p>@endif
        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ $site->url('/admission') }}" class="rounded-lg bg-white px-5 py-3 font-semibold text-[var(--site)] shadow hover:bg-zinc-100">{{ __('Apply for admission') }}</a>
            <a href="{{ $site->url('/about') }}" class="rounded-lg border border-white/50 px-5 py-3 font-semibold text-white hover:bg-white/10">{{ __('About us') }}</a>
        </div>
    </div>
</section>

<div class="mx-auto grid max-w-6xl gap-10 px-4 py-14 lg:grid-cols-3">
    <section class="lg:col-span-2" aria-labelledby="notices-h">
        <div class="flex items-end justify-between">
            <h2 id="notices-h" class="text-2xl font-bold text-zinc-900">{{ __('Latest notices') }}</h2>
            <a href="{{ $site->url('/notices') }}" class="text-sm font-medium text-[var(--site)] hover:underline">{{ __('All notices') }} →</a>
        </div>
        @if ($notices->isEmpty())
            <p class="mt-4 rounded-xl border border-dashed border-zinc-300 p-6 text-center text-zinc-500">{{ __('No notices yet.') }}</p>
        @else
            <ul class="mt-4 grid gap-3">
                @foreach ($notices as $notice) @include('site.partials.notice-item') @endforeach
            </ul>
        @endif
    </section>

    <aside class="grid content-start gap-6">
        @if ($s->local('principal_message'))
            <section class="rounded-2xl bg-zinc-50 p-5">
                <h2 class="text-lg font-bold text-zinc-900">{{ __("Principal's message") }}</h2>
                <div class="mt-3 flex items-center gap-3">
                    @if ($principalPhoto)<img src="{{ $principalPhoto }}" alt="" class="size-14 rounded-full object-cover" loading="lazy">@endif
                    <p class="font-medium">{{ $s->local('principal_name') }}</p>
                </div>
                <p class="mt-3 line-clamp-5 text-sm text-zinc-600">{{ \App\Modules\Website\Services\Markdown::excerpt($s->local('principal_message'), 360) }}</p>
                <a href="{{ $site->url('/about') }}#principal" class="mt-2 inline-block text-sm font-medium text-[var(--site)] hover:underline">{{ __('Read more') }} →</a>
            </section>
        @endif
        <section>
            <div class="flex items-end justify-between">
                <h2 class="text-lg font-bold text-zinc-900">{{ __('Upcoming events') }}</h2>
                <a href="{{ $site->url('/events') }}" class="text-sm font-medium text-[var(--site)] hover:underline">{{ __('All') }} →</a>
            </div>
            <ul class="mt-3 grid gap-2">
                @forelse ($events as $event)
                    <li class="rounded-xl border border-zinc-200 p-3">
                        <p class="text-sm font-medium text-[var(--site)]">{{ $event->starts_on->locale($site->lang)->translatedFormat('j F Y') }}</p>
                        <p class="font-semibold">{{ $event->local('title') }}</p>
                        @if ($event->location)<p class="text-sm text-zinc-500">{{ $event->location }}</p>@endif
                    </li>
                @empty
                    <li class="text-sm text-zinc-500">{{ __('No upcoming events.') }}</li>
                @endforelse
            </ul>
        </section>
    </aside>
</div>

@if ($photos->isNotEmpty())
    <section class="bg-zinc-50 py-14" aria-labelledby="gallery-h">
        <div class="mx-auto max-w-6xl px-4">
            <div class="flex items-end justify-between">
                <h2 id="gallery-h" class="text-2xl font-bold text-zinc-900">{{ __('Gallery') }}</h2>
                <a href="{{ $site->url('/gallery') }}" class="text-sm font-medium text-[var(--site)] hover:underline">{{ __('View gallery') }} →</a>
            </div>
            <ul class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($photos as $photo)
                    <li><a href="{{ $site->url('/gallery/'.$photo->gallery_album_id) }}"><img src="{{ \App\Modules\Website\Services\WebsiteMedia::url($photo->thumb_path) }}" alt="{{ $photo->local('caption') ?? '' }}" class="aspect-[4/3] w-full rounded-xl object-cover transition hover:opacity-90" loading="lazy"></a></li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

<section class="mx-auto max-w-6xl px-4 pt-14">
    <div class="flex flex-col items-start justify-between gap-4 rounded-2xl bg-[var(--site)] px-6 py-8 text-white sm:flex-row sm:items-center">
        <div>
            <h2 class="text-2xl font-bold">{{ __('Admissions are open') }}</h2>
            <p class="mt-1 text-white/85">{{ __('Apply online in a few minutes. The office will contact you.') }}</p>
        </div>
        <a href="{{ $site->url('/admission') }}" class="rounded-lg bg-white px-5 py-3 font-semibold text-[var(--site)]">{{ __('Apply now') }}</a>
    </div>
</section>
@endsection
