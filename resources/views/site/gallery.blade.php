@extends('site.layout')

@section('content')
@include('site.partials.page-title', ['title' => __('Gallery')])
<div class="mx-auto max-w-6xl px-4 py-10">
    @if ($albums->isEmpty())
        <p class="text-zinc-500">{{ __('Nothing here yet.') }}</p>
    @else
        <ul class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($albums as $album)
                <li>
                    <a href="{{ $site->url('/gallery/'.$album->id) }}" class="group block overflow-hidden rounded-2xl border border-zinc-200">
                        <img src="{{ \App\Modules\Website\Services\WebsiteMedia::url($album->photos->first()?->thumb_path) }}" alt="" class="aspect-[4/3] w-full object-cover transition group-hover:scale-[1.02]" loading="lazy">
                        <span class="block p-4">
                            <span class="block font-semibold group-hover:text-[var(--site)]">{{ $album->local('title') }}</span>
                            <span class="text-sm text-zinc-500">{{ trans_choice(':count photo|:count photos', $album->photos_count, ['count' => $album->photos_count]) }}@if ($album->taken_on) · {{ $album->taken_on->locale($site->lang)->translatedFormat('F Y') }}@endif</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
