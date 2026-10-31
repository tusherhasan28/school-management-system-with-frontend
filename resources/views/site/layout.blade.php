@php
    /** @var \App\Modules\Website\Services\SiteContext $site */
    $s = $site->settings;
    $title = isset($seo['title']) && $seo['title'] ? $seo['title'].' · '.$site->name() : $site->name().($s->local('tagline') ? ' · '.$s->local('tagline') : '');
    $description = $seo['description'] ?? $s->local('seo_description');
    $image = isset($seo['image']) && $seo['image'] ? \App\Modules\Website\Services\WebsiteMedia::url($seo['image']) : (\App\Modules\Website\Services\WebsiteMedia::url($s->hero_image_path) ?? $site->logoUrl());
    $other = $site->lang === 'bn' ? 'en' : 'bn';
    $preview = ! $s->is_published;
@endphp
<!DOCTYPE html>
<html lang="{{ $site->lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @if ($description)<meta name="description" content="{{ \Illuminate\Support\Str::limit($description, 300) }}">@endif
    @if ($preview || ($seo['noindex'] ?? false))<meta name="robots" content="noindex, nofollow">@endif
    <link rel="canonical" href="{{ $site->canonical() }}">
    <link rel="alternate" hreflang="{{ $site->lang }}" href="{{ $site->canonical() }}">
    <link rel="alternate" hreflang="{{ $other }}" href="{{ $site->canonical($other) }}">
    <link rel="alternate" hreflang="x-default" href="{{ $site->canonical($s->default_language) }}">
    <meta property="og:site_name" content="{{ $site->name() }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:type" content="{{ $seo['type'] ?? 'website' }}">
    <meta property="og:url" content="{{ $site->canonical() }}">
    <meta property="og:locale" content="{{ $site->lang === 'bn' ? 'bn_BD' : 'en_GB' }}">
    @if ($description)<meta property="og:description" content="{{ \Illuminate\Support\Str::limit($description, 300) }}">@endif
    @if ($image)<meta property="og:image" content="{{ $image }}">@endif
    <meta name="theme-color" content="{{ $s->theme_color }}">
    @if ($site->logoUrl())<link rel="icon" href="{{ $site->logoUrl() }}">@else<link rel="icon" href="/favicon.ico" sizes="any">@endif
    <script type="application/ld+json">{!! json_encode(array_filter([
        '@context' => 'https://schema.org', '@type' => 'School', 'name' => $site->school->name, 'alternateName' => $site->school->name_bn,
        'url' => url('/'), 'telephone' => $site->school->phone, 'email' => $site->school->email, 'logo' => $site->logoUrl(),
        'address' => $site->school->address ? ['@type' => 'PostalAddress', 'streetAddress' => $site->school->address, 'addressLocality' => $site->school->district, 'addressCountry' => 'BD'] : null,
    ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @vite('resources/css/app.css')
    <style>:root { --site: {{ preg_match('/^#[0-9a-fA-F]{6}$/', $s->theme_color) ? $s->theme_color : '#0f766e' }}; }</style>
</head>
<body class="min-h-screen bg-white font-sans text-zinc-800 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2">{{ __('Skip to content') }}</a>
    @if ($preview)
        <div class="bg-amber-100 px-4 py-2 text-center text-sm text-amber-900">{{ __('Preview: this website is not published yet. Only website managers can see it.') }}</div>
    @endif

    @include('site.partials.header-'.(in_array($s->template, \App\Modules\Website\Models\WebsiteSetting::TEMPLATES, true) ? $s->template : 'classic'))

    <main id="main">
        @yield('content')
    </main>

    @include('site.partials.footer')
</body>
</html>
