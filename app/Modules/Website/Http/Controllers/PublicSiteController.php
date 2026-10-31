<?php

namespace App\Modules\Website\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Academics\Models\AcademicSession;
use App\Modules\Academics\Models\ClassSubject;
use App\Modules\Academics\Models\SchoolClass;
use App\Modules\Notices\Models\Event;
use App\Modules\Notices\Models\Notice;
use App\Modules\People\Models\StaffMember;
use App\Modules\Website\Models\GalleryAlbum;
use App\Modules\Website\Models\GalleryPhoto;
use App\Modules\Website\Models\WebsitePage;
use App\Modules\Website\Services\Markdown;
use App\Modules\Website\Services\SiteContext;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The school's public website: plain server-rendered HTML (indexable, fast on
 * slow connections, no JavaScript needed).
 *
 * PUBLIC DATA ONLY. Pages read school-level fields, website content, public
 * notices/events/albums, class and subject names, and teachers the school
 * chose to show (name and designation only). Never students or guardians.
 */
class PublicSiteController extends Controller
{
    public function home(Request $request): View
    {
        $site = $this->site($request);

        if (! $this->visible($site, $request)) {
            return $this->holding($site);
        }

        return view('site.home', [
            'site' => $site,
            'notices' => Notice::query()->visibleTo([])->newestFirst()->limit(5)->get(),
            'events' => Event::query()->where('is_public', true)->whereDate('starts_on', '>=', now($site->school->timezone)->toDateString())->orderBy('starts_on')->limit(3)->get(),
            'photos' => GalleryPhoto::query()->whereHas('album', fn ($q) => $q->where('is_public', true))->latest('id')->limit(8)->get(),
            'seo' => ['title' => null, 'description' => $site->settings->local('seo_description') ?? Markdown::excerpt($site->settings->local('about'))],
        ]);
    }

    public function about(Request $request): View
    {
        return $this->page($request, 'site.about', fn (SiteContext $site) => [
            'seo' => ['title' => __('About us'), 'description' => Markdown::excerpt($site->settings->local('about'))],
        ]);
    }

    public function academics(Request $request): View
    {
        return $this->page($request, 'site.academics', function () {
            $session = AcademicSession::query()->where('is_current', true)->first();
            $subjects = $session ? ClassSubject::query()->with('subject:id,school_id,name,name_bn')->where('academic_session_id', $session->id)->orderBy('sort_order')->get()->groupBy('class_id') : collect();

            return [
                'classes' => SchoolClass::query()->where('is_active', true)->withCount(['sections' => fn ($q) => $q->where('is_active', true)])->orderBy('level_order')->get(['id', 'school_id', 'name', 'name_bn', 'education_stage']),
                'subjects' => $subjects,
                'session' => $session?->name,
                'seo' => ['title' => __('Academics'), 'description' => null],
            ];
        });
    }

    public function teachers(Request $request): View
    {
        $site = $this->site($request);
        abort_unless($site->settings->show_teachers, 404);

        return $this->page($request, 'site.teachers', fn () => [
            // Only what a school would print in a prospectus: name and designation.
            'teachers' => StaffMember::query()->where('staff_type', 'teacher')->where('status', 'active')->where('show_on_website', true)
                ->orderBy('full_name')->get(['id', 'school_id', 'full_name', 'name_bn', 'designation']),
            'seo' => ['title' => __('Teachers'), 'description' => null],
        ]);
    }

    public function notices(Request $request): View
    {
        return $this->page($request, 'site.notices', fn () => [
            'notices' => Notice::query()->visibleTo([])->newestFirst()->paginate(15)->withQueryString(),
            'seo' => ['title' => __('Notices'), 'description' => null],
        ]);
    }

    public function notice(Request $request, int $notice): View
    {
        $item = Notice::query()->visibleTo([])->findOrFail($notice);

        return $this->page($request, 'site.notice', fn () => [
            'notice' => $item,
            'seo' => ['title' => $item->local('title'), 'description' => Markdown::excerpt($item->local('body')), 'type' => 'article'],
        ]);
    }

    public function noticeAttachment(Request $request, int $notice, TenantStorage $storage): StreamedResponse
    {
        $this->guardVisible($request);
        $item = Notice::query()->visibleTo([])->whereNotNull('attachment_path')->findOrFail($notice);
        abort_unless($storage->owns($item->attachment_path) && $storage->disk()->exists($item->attachment_path), 404);

        return $storage->disk()->download($item->attachment_path, $item->attachment_name ?? 'attachment');
    }

    public function events(Request $request): View
    {
        return $this->page($request, 'site.events', function (SiteContext $site) {
            $today = now($site->school->timezone)->toDateString();
            $public = Event::query()->where('is_public', true);

            return [
                'upcoming' => (clone $public)->where(fn ($q) => $q->whereDate('starts_on', '>=', $today)->orWhereDate('ends_on', '>=', $today))->orderBy('starts_on')->get(),
                'past' => (clone $public)->whereDate('starts_on', '<', $today)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '<', $today))->orderByDesc('starts_on')->limit(12)->get(),
                'seo' => ['title' => __('Events'), 'description' => null],
            ];
        });
    }

    public function gallery(Request $request): View
    {
        return $this->page($request, 'site.gallery', fn () => [
            'albums' => GalleryAlbum::query()->where('is_public', true)->with(['photos' => fn ($q) => $q->limit(1)])->withCount('photos')->having('photos_count', '>', 0)
                ->orderBy('sort_order')->orderByDesc('taken_on')->orderByDesc('id')->get(),
            'seo' => ['title' => __('Gallery'), 'description' => null],
        ]);
    }

    public function album(Request $request, int $album): View
    {
        $item = GalleryAlbum::query()->where('is_public', true)->with('photos')->findOrFail($album);

        return $this->page($request, 'site.album', fn () => [
            'album' => $item,
            'seo' => ['title' => $item->local('title'), 'description' => $item->description, 'image' => $item->photos->first()?->path],
        ]);
    }

    public function contact(Request $request): View
    {
        return $this->page($request, 'site.contact', fn () => ['seo' => ['title' => __('Contact'), 'description' => null]]);
    }

    public function customPage(Request $request, string $slug): View
    {
        // Website managers may preview drafts; everyone else sees published pages only.
        $page = WebsitePage::query()->when(! $request->user()?->can('website.manage'), fn ($q) => $q->published())->where('slug', $slug)->firstOrFail();

        return $this->page($request, 'site.page', fn () => [
            'page' => $page,
            'seo' => ['title' => $page->local('title'), 'description' => $page->seo_description ?? Markdown::excerpt($page->local('body'))],
        ]);
    }

    /** Website images: only files under this school's website folder. */
    public function media(string $path, TenantStorage $storage): StreamedResponse
    {
        abort_unless(str_starts_with($path, 'website/') && preg_match('#^[A-Za-z0-9/_\-]+\.(jpg|jpeg|png|webp)$#', $path), 404);

        try {
            $full = $storage->path($path);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        abort_unless($storage->disk()->exists($full), 404);

        return $storage->disk()->response($full, null, [
            'Cache-Control' => 'public, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function sitemap(Request $request): Response
    {
        $site = $this->site($request);

        if (! $this->live($site)) {
            return response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>', 200, ['Content-Type' => 'application/xml']);
        }

        $urls = collect([
            ['/', $site->settings->updated_at], ['/about', $site->settings->updated_at], ['/academics', null], ['/notices', Notice::query()->visibleTo([])->max('updated_at')],
            ['/events', Event::query()->where('is_public', true)->max('updated_at')], ['/gallery', GalleryAlbum::query()->where('is_public', true)->max('updated_at')],
            ['/contact', null], ['/admission', null],
        ]);

        if ($site->settings->show_teachers) {
            $urls->push(['/teachers', null]);
        }

        Notice::query()->visibleTo([])->get(['id', 'school_id', 'updated_at'])->each(fn ($n) => $urls->push(['/notices/'.$n->id, $n->updated_at]));
        GalleryAlbum::query()->where('is_public', true)->get(['id', 'school_id', 'updated_at'])->each(fn ($a) => $urls->push(['/gallery/'.$a->id, $a->updated_at]));
        WebsitePage::query()->published()->get(['slug', 'school_id', 'updated_at'])->each(fn ($p) => $urls->push(['/p/'.$p->slug, $p->updated_at]));

        $other = $site->settings->default_language === 'bn' ? 'en' : 'bn';

        return response()->view('site.sitemap', ['site' => $site, 'urls' => $urls, 'other' => $other], 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(Request $request): Response
    {
        $site = $this->site($request);
        $body = $this->live($site)
            ? "User-agent: *\nDisallow: /app/\nDisallow: /parent/\nDisallow: /student/\nDisallow: /teacher/\nDisallow: /settings/\nDisallow: /payments/\n\nSitemap: ".url('sitemap.xml')."\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }

    /**
     * @param  callable(SiteContext): array<string, mixed>  $data
     */
    private function page(Request $request, string $view, callable $data): View
    {
        $site = $this->site($request);

        if (! $this->visible($site, $request)) {
            return $this->holding($site);
        }

        return view($view, ['site' => $site] + $data($site));
    }

    private function site(Request $request): SiteContext
    {
        return app(SiteContext::class, ['request' => $request]);
    }

    /** Unpublished sites are visible only to staff who manage the website (preview). */
    /** Published by the school and included in its plan. */
    private function live(SiteContext $site): bool
    {
        return $site->settings->is_published && app(\App\Modules\Billing\Services\Entitlements::class)->enabled('website');
    }

    private function visible(SiteContext $site, Request $request): bool
    {
        return $this->live($site) || (bool) $request->user()?->can('website.manage');
    }

    private function guardVisible(Request $request): void
    {
        abort_unless($this->visible($this->site($request), $request), 404);
    }

    private function holding(SiteContext $site): View
    {
        return view('site.holding', ['site' => $site, 'seo' => ['title' => null, 'description' => null, 'noindex' => true]]);
    }
}
