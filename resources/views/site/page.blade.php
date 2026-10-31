@extends('site.layout')

@section('content')
@include('site.partials.page-title', ['title' => $page->local('title')])
<article class="mx-auto max-w-3xl px-4 py-10">
    <div class="prose-site">{!! \App\Modules\Website\Services\Markdown::render($page->local('body')) !!}</div>
</article>
@endsection
