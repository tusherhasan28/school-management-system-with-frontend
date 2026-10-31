<?php

namespace App\Modules\Website\Services;

use App\Models\School;
use App\Modules\Website\Models\WebsiteMenuItem;
use App\Modules\Website\Models\WebsiteSetting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Everything the public website layout needs, for the current school and
 * language. Only school-level public fields are exposed here.
 *
 * Language: the school's default language at the plain URL, the other one
 * with ?lang=xx. Links built with url() keep the visitor's language.
 */
class SiteContext
{
    public readonly School $school;

    public readonly WebsiteSetting $settings;

    public readonly string $lang;

    public function __construct(TenantContext $tenant, Request $request)
    {
        $this->school = $tenant->require();
        $this->settings = WebsiteSetting::current();
        $requested = (string) $request->query('lang', '');
        $this->lang = in_array($requested, ['bn', 'en'], true) ? $requested : $this->settings->default_language;
        app()->setLocale($this->lang);
    }

    public function name(): string
    {
        return $this->lang === 'bn' && $this->school->name_bn ? $this->school->name_bn : $this->school->name;
    }

    /** A site URL in the visitor's language. */
    public function url(string $path = '/', ?string $lang = null): string
    {
        $lang ??= $this->lang;
        $base = rtrim(url($path === '/' ? '' : ltrim($path, '/')), '/') ?: url('/');

        return $lang === $this->settings->default_language ? $base : $base.'?lang='.$lang;
    }

    /** Canonical URL of the current page in a language (no other query string). */
    public function canonical(?string $lang = null): string
    {
        return $this->url('/'.ltrim(request()->path(), '/'), $lang);
    }

    public function builtinUrl(string $builtin): string
    {
        return match ($builtin) {
            'home' => $this->url('/'),
            'admission' => $this->url('/admission'),
            default => $this->url('/'.$builtin),
        };
    }

    /** @return list<array{label: string, url: string, active: bool}> */
    public function menu(string $location): array
    {
        $items = WebsiteMenuItem::query()->with('page')->where('location', $location)->where('is_visible', true)->orderBy('sort_order')->orderBy('id')->get();

        if ($items->isEmpty() && $location === 'header') {
            return $this->defaultMenu();
        }

        $current = '/'.ltrim(request()->path(), '/');

        return $items->filter(fn (WebsiteMenuItem $i) => $i->link_type !== 'page' || $i->page?->status === 'published')
            ->map(function (WebsiteMenuItem $i) use ($current) {
                $url = match ($i->link_type) {
                    'builtin' => $this->builtinUrl((string) $i->builtin),
                    'page' => $this->url('/p/'.$i->page->slug),
                    default => (string) $i->url,
                };
                $path = parse_url($url, PHP_URL_PATH) ?: '/';

                return ['label' => (string) $i->local('label'), 'url' => $url, 'active' => $i->link_type !== 'url' && $path === $current];
            })->values()->all();
    }

    /** @return list<array{label: string, url: string, active: bool}> */
    private function defaultMenu(): array
    {
        $current = '/'.ltrim(request()->path(), '/');
        $labels = ['home' => ['Home', 'প্রচ্ছদ'], 'about' => ['About', 'পরিচিতি'], 'academics' => ['Academics', 'একাডেমিক'], 'notices' => ['Notices', 'নোটিশ'],
            'gallery' => ['Gallery', 'গ্যালারি'], 'admission' => ['Admission', 'ভর্তি'], 'contact' => ['Contact', 'যোগাযোগ']];

        return collect($labels)->map(fn ($l, $key) => [
            'label' => $this->lang === 'bn' ? $l[1] : $l[0],
            'url' => $this->builtinUrl($key),
            'active' => ($key === 'home' ? '/' : '/'.$key) === $current,
        ])->values()->all();
    }

    public function logoUrl(): ?string
    {
        return WebsiteMedia::url($this->settings->logo_path);
    }
}
