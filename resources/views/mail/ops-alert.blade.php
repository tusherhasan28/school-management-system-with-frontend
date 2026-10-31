{{ config('app.name') }} — {{ config('app.url') }} — {{ now()->toIso8601String() }}

@foreach ($failing as $name => $result)
FAILING  {{ $name }}: {{ $result['detail'] }}
@endforeach
@foreach ($recovered as $name)
RECOVERED  {{ $name }}
@endforeach

Run `php artisan ops:health` on the server for the current state.
See docs/operations.md for what each check means and how to respond.
