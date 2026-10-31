@extends('site.layout')

@section('content')
@include('site.partials.page-title', ['title' => __('About us')])
@php $photo = \App\Modules\Website\Services\WebsiteMedia::url($site->settings->principal_photo_path); @endphp
<div class="mx-auto max-w-4xl px-4 py-10">
    <div class="prose-site">{!! \App\Modules\Website\Services\Markdown::render($site->settings->local('about')) ?: '<p>'.e(__('Information coming soon.')).'</p>' !!}</div>

    @if ($site->settings->local('principal_message'))
        <section id="principal" class="mt-12 rounded-2xl bg-zinc-50 p-6 sm:p-8">
            <h2 class="text-2xl font-bold text-zinc-900">{{ __("Principal's message") }}</h2>
            <div class="mt-5 flex flex-col gap-6 sm:flex-row">
                @if ($photo)<img src="{{ $photo }}" alt="{{ $site->settings->local('principal_name') }}" class="size-32 shrink-0 rounded-2xl object-cover" loading="lazy">@endif
                <div>
                    <div class="prose-site">{!! \App\Modules\Website\Services\Markdown::render($site->settings->local('principal_message')) !!}</div>
                    <p class="mt-4 font-semibold">{{ $site->settings->local('principal_name') }}</p>
                </div>
            </div>
        </section>
    @endif
</div>
@endsection
