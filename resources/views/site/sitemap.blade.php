{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($urls as [$path, $updated])
    <url>
        <loc>{{ $site->url($path, $site->settings->default_language) }}</loc>
        <xhtml:link rel="alternate" hreflang="{{ $site->settings->default_language }}" href="{{ $site->url($path, $site->settings->default_language) }}"/>
        <xhtml:link rel="alternate" hreflang="{{ $other }}" href="{{ $site->url($path, $other) }}"/>
        @if ($updated)<lastmod>{{ \Illuminate\Support\Carbon::parse($updated)->toAtomString() }}</lastmod>@endif
    </url>
@endforeach
</urlset>
