@extends('site.layout')

@section('content')
<section class="mx-auto max-w-2xl px-4 py-24 text-center">
    <h1 class="text-3xl font-bold text-zinc-900">{{ $site->name() }}</h1>
    <p class="mt-3 text-zinc-600">{{ __('Our website is being prepared. Admission applications are open.') }}</p>
    <div class="mt-8 flex flex-wrap justify-center gap-3">
        <a href="{{ $site->url('/admission') }}" class="rounded-lg bg-[var(--site)] px-5 py-3 font-semibold text-white">{{ __('Apply for admission') }}</a>
        <a href="{{ url('login') }}" class="rounded-lg border border-zinc-300 px-5 py-3 font-semibold">{{ __('Portal login') }}</a>
    </div>
</section>
@endsection
