@extends('site.layout')

@section('content')
@include('site.partials.page-title', ['title' => __('Notices')])
<div class="mx-auto max-w-4xl px-4 py-10">
    @if ($notices->isEmpty())
        <p class="text-zinc-500">{{ __('No notices yet.') }}</p>
    @else
        <ul class="grid gap-3">@foreach ($notices as $notice) @include('site.partials.notice-item') @endforeach</ul>
        <div class="mt-6">{{ $notices->links() }}</div>
    @endif
</div>
@endsection
