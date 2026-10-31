@extends('site.layout')

@section('content')
@include('site.partials.page-title', ['title' => __('Academics'), 'subtitle' => $session ? __('Classes and subjects for :session', ['session' => $session]) : null])
<div class="mx-auto max-w-6xl px-4 py-10">
    @if ($classes->isEmpty())
        <p class="text-zinc-500">{{ __('Information coming soon.') }}</p>
    @else
        <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($classes as $class)
                <li class="rounded-xl border border-zinc-200 p-5">
                    <h2 class="text-lg font-bold text-zinc-900">{{ $site->lang === 'bn' && $class->name_bn ? $class->name_bn : $class->name }}</h2>
                    <p class="text-sm text-zinc-500">{{ trans_choice(':count section|:count sections', $class->sections_count, ['count' => $class->sections_count]) }}</p>
                    @if ($subjects->has($class->id))
                        <ul class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($subjects[$class->id] as $cs)
                                <li class="rounded-full bg-[var(--site)]/10 px-2.5 py-0.5 text-sm text-[var(--site)]">{{ $site->lang === 'bn' && $cs->subject?->name_bn ? $cs->subject->name_bn : $cs->subject?->name }}</li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
