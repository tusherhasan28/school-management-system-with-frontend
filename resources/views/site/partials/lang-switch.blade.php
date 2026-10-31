@php $other = $site->lang === 'bn' ? 'en' : 'bn'; @endphp
<a href="{{ $site->canonical($other) }}" hreflang="{{ $other }}" lang="{{ $other }}" class="rounded-full border border-current/30 px-2.5 py-0.5 text-xs font-medium hover:bg-black/5">{{ $other === 'bn' ? 'বাংলা' : 'English' }}</a>
