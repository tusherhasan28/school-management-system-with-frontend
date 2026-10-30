<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('school.unavailable_title') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: 'Hind Siliguri', 'Inter', system-ui, sans-serif; background: #f8fafc; color: #0f172a; }
        main { max-width: 32rem; padding: 2rem; text-align: center; }
        h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
        p { color: #475569; line-height: 1.6; margin: 0; }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('school.unavailable_title') }}</h1>
        <p>{{ __('school.unavailable_body') }}</p>
    </main>
</body>
</html>
