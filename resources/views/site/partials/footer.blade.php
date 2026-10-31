<footer class="mt-16 bg-zinc-900 text-sm text-zinc-300">
    <div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 sm:grid-cols-3">
        <div>
            <p class="text-base font-semibold text-white">{{ $site->name() }}</p>
            @if ($site->settings->local('tagline'))<p class="mt-1">{{ $site->settings->local('tagline') }}</p>@endif
            @if ($site->settings->eiin)<p class="mt-1">{{ __('EIIN') }}: {{ $site->settings->eiin }}</p>@endif
        </div>
        <div>
            <p class="font-semibold text-white">{{ __('Contact') }}</p>
            <address class="mt-2 space-y-1 not-italic">
                @if ($site->school->address)<p>{{ $site->school->address }}</p>@endif
                @if ($site->school->phone)<p><a href="tel:{{ $site->school->phone }}" class="hover:text-white">{{ $site->school->phone }}</a></p>@endif
                @if ($site->school->email)<p><a href="mailto:{{ $site->school->email }}" class="hover:text-white">{{ $site->school->email }}</a></p>@endif
                @if ($site->settings->local('office_hours'))<p>{{ $site->settings->local('office_hours') }}</p>@endif
            </address>
        </div>
        <div>
            <p class="font-semibold text-white">{{ __('Links') }}</p>
            <ul class="mt-2 space-y-1">
                @foreach ($site->menu('footer') as $item)
                    <li><a href="{{ $item['url'] }}" class="hover:text-white">{{ $item['label'] }}</a></li>
                @endforeach
                <li><a href="{{ $site->url('/admission') }}" class="hover:text-white">{{ __('Apply for admission') }}</a></li>
                <li><a href="{{ url('login') }}" class="hover:text-white">{{ __('Portal login') }}</a></li>
                @if ($site->settings->facebook_url)<li><a href="{{ $site->settings->facebook_url }}" rel="noopener" class="hover:text-white">Facebook</a></li>@endif
                @if ($site->settings->youtube_url)<li><a href="{{ $site->settings->youtube_url }}" rel="noopener" class="hover:text-white">YouTube</a></li>@endif
            </ul>
        </div>
    </div>
    <p class="border-t border-white/10 py-4 text-center text-xs text-zinc-500">© {{ now()->year }} {{ $site->name() }}</p>
</footer>
