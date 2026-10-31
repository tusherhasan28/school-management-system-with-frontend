<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Redirecting to payment…') }}</title>
    <style>body{font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;color:#334155}button{padding:.6rem 1.2rem;border-radius:.5rem;border:1px solid #cbd5e1;background:#fff;font:inherit;cursor:pointer}</style>
</head>
<body>
    {{-- The payment provider's checkout page expects a form POST. --}}
    <form id="checkout" method="POST" action="{{ $url }}">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <p>{{ __('Redirecting to payment…') }}</p>
        <noscript><button type="submit">{{ __('Continue to payment') }}</button></noscript>
    </form>
    <script nonce="{{ Vite::cspNonce() }}">document.getElementById('checkout').submit();</script>
</body>
</html>
