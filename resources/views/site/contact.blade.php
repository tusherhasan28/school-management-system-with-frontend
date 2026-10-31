@extends('site.layout')

@section('content')
@include('site.partials.page-title', ['title' => __('Contact')])
@php $map = $site->settings->map_url && str_starts_with($site->settings->map_url, 'https://www.google.com/maps/embed') ? $site->settings->map_url : null; @endphp
<div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 lg:grid-cols-2">
    <dl class="grid content-start gap-5">
        @if ($site->school->address)<div><dt class="text-sm font-medium text-zinc-500">{{ __('Address') }}</dt><dd class="text-lg">{{ $site->school->address }}</dd></div>@endif
        @if ($site->school->phone)<div><dt class="text-sm font-medium text-zinc-500">{{ __('Phone') }}</dt><dd class="text-lg"><a href="tel:{{ $site->school->phone }}" class="text-[var(--site)] hover:underline">{{ $site->school->phone }}</a></dd></div>@endif
        @if ($site->school->email)<div><dt class="text-sm font-medium text-zinc-500">{{ __('Email') }}</dt><dd class="text-lg"><a href="mailto:{{ $site->school->email }}" class="text-[var(--site)] hover:underline">{{ $site->school->email }}</a></dd></div>@endif
        @if ($site->settings->local('office_hours'))<div><dt class="text-sm font-medium text-zinc-500">{{ __('Office hours') }}</dt><dd class="text-lg">{{ $site->settings->local('office_hours') }}</dd></div>@endif
        @if ($site->settings->eiin)<div><dt class="text-sm font-medium text-zinc-500">{{ __('EIIN') }}</dt><dd class="text-lg">{{ $site->settings->eiin }}</dd></div>@endif
    </dl>
    @if ($map)
        <iframe src="{{ $map }}" title="{{ __('Map') }}" class="h-80 w-full rounded-2xl border-0" loading="lazy" referrerpolicy="no-referrer-when-downgrade" sandbox="allow-scripts allow-same-origin allow-popups"></iframe>
    @endif
</div>
@endsection
