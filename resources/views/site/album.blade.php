@extends('site.layout')

@section('content')
@include('site.partials.page-title', ['title' => $album->local('title'), 'subtitle' => $album->description])
<div class="mx-auto max-w-6xl px-4 py-10">
    <a href="{{ $site->url('/gallery') }}" class="text-sm font-medium text-[var(--site)] hover:underline">← {{ __('Gallery') }}</a>
    <ul class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($album->photos as $photo)
            <li>
                <a href="{{ \App\Modules\Website\Services\WebsiteMedia::url($photo->path) }}" target="_blank" rel="noopener">
                    <img src="{{ \App\Modules\Website\Services\WebsiteMedia::url($photo->thumb_path) }}" alt="{{ $photo->local('caption') ?? $album->local('title') }}" width="480" height="{{ $photo->width ? (int) round(480 * $photo->height / max(1, $photo->width)) : 360 }}" class="aspect-[4/3] w-full rounded-xl object-cover" loading="lazy">
                </a>
                @if ($photo->local('caption'))<p class="mt-1 text-sm text-zinc-600">{{ $photo->local('caption') }}</p>@endif
            </li>
        @endforeach
    </ul>
</div>
@endsection
