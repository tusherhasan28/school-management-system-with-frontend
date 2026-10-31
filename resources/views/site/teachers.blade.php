@extends('site.layout')

@section('content')
@include('site.partials.page-title', ['title' => __('Teachers')])
<div class="mx-auto max-w-6xl px-4 py-10">
    @if ($teachers->isEmpty())
        <p class="text-zinc-500">{{ __('Information coming soon.') }}</p>
    @else
        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($teachers as $teacher)
                @php $name = $site->lang === 'bn' && $teacher->name_bn ? $teacher->name_bn : $teacher->full_name; @endphp
                <li class="flex items-center gap-3 rounded-xl border border-zinc-200 p-4">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-[var(--site)]/10 font-bold text-[var(--site)]">{{ mb_substr($name, 0, 1) }}</span>
                    <span><span class="block font-semibold">{{ $name }}</span>@if ($teacher->designation)<span class="block text-sm text-zinc-500">{{ $teacher->designation }}</span>@endif</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
