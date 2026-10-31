@extends('site.layout')

@section('content')
<article class="mx-auto max-w-3xl px-4 py-10">
    <a href="{{ $site->url('/notices') }}" class="text-sm font-medium text-[var(--site)] hover:underline">← {{ __('All notices') }}</a>
    <h1 class="mt-3 text-3xl font-bold text-zinc-900">{{ $notice->local('title') }}</h1>
    <p class="mt-2 text-sm text-zinc-500"><time datetime="{{ $notice->publishedOn()->toDateString() }}">{{ $notice->publishedOn()->timezone($site->school->timezone)->locale($site->lang)->translatedFormat('j F Y') }}</time></p>
    <div class="prose-site mt-6">{!! \App\Modules\Website\Services\Markdown::render($notice->local('body')) !!}</div>
    @if ($notice->attachment_path)
        <a href="{{ url('notices/'.$notice->id.'/attachment') }}" class="mt-6 inline-flex items-center gap-2 rounded-lg border border-zinc-300 px-4 py-2 font-medium hover:bg-zinc-50">⬇ {{ $notice->attachment_name ?? __('Attachment') }}</a>
    @endif
</article>
@endsection
