<?php

namespace App\Modules\Website\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\People\Models\StaffMember;
use App\Modules\Website\Models\GalleryAlbum;
use App\Modules\Website\Models\GalleryPhoto;
use App\Modules\Website\Models\WebsiteMenuItem;
use App\Modules\Website\Models\WebsitePage;
use App\Modules\Website\Models\WebsiteSetting;
use App\Modules\Website\Services\WebsiteMedia;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Website builder: settings and images, teachers shown, pages, menus, gallery.
 * Everything requires website.manage.
 */
class WebsiteAdminController extends Controller
{
    public function __construct(private readonly WebsiteMedia $media, private readonly AuditLogger $audit) {}

    public function edit(Request $request): Response
    {
        $this->authorizeManage($request);
        $s = WebsiteSetting::current();

        return Inertia::render('app/website/settings', [
            'settings' => collect($s->toArray())->except(['id', 'school_id', 'created_at', 'updated_at'])->all() + [
                'logo_url' => WebsiteMedia::url($s->logo_path), 'hero_url' => WebsiteMedia::url($s->hero_image_path), 'principal_url' => WebsiteMedia::url($s->principal_photo_path),
            ],
            'teachers' => StaffMember::query()->where('staff_type', 'teacher')->where('status', 'active')->orderBy('full_name')->get(['id', 'school_id', 'full_name', 'designation', 'show_on_website']),
            'siteUrl' => url('/'),
            'templates' => WebsiteSetting::TEMPLATES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $rules = [
            'template' => ['required', Rule::in(WebsiteSetting::TEMPLATES)],
            'theme_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'default_language' => ['required', Rule::in(['bn', 'en'])],
            'is_published' => ['boolean'],
            'show_teachers' => ['boolean'],
            'eiin' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'facebook_url' => ['nullable', 'url:https', 'max:255'],
            'youtube_url' => ['nullable', 'url:https', 'max:255'],
            'map_url' => ['nullable', 'url:https', 'max:500', 'starts_with:https://www.google.com/maps/embed'],
        ];

        foreach (['tagline', 'hero_title', 'principal_name', 'office_hours'] as $f) {
            $rules[$f] = $rules[$f.'_bn'] = ['nullable', 'string', 'max:190'];
        }

        foreach (['hero_text', 'about', 'principal_message'] as $f) {
            $rules[$f] = $rules[$f.'_bn'] = ['nullable', 'string', 'max:20000'];
        }

        $rules['seo_description'] = $rules['seo_description_bn'] = ['nullable', 'string', 'max:320'];

        $data = $request->validate($rules);

        if (! empty($data['is_published'])) {
            app(\App\Modules\Billing\Services\Entitlements::class)->ensureEnabled('website');
        }

        $settings = WebsiteSetting::current();
        $settings->fill($data)->save();
        $this->audit->tenant('website.settings_updated', $settings, [], ['is_published' => $settings->is_published, 'template' => $settings->template]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.saved')]);

        return back();
    }

    /** Logo, hero image or principal photo. */
    public function uploadImage(Request $request, string $slot): RedirectResponse
    {
        $this->authorizeManage($request);
        $column = ['logo' => 'logo_path', 'hero' => 'hero_image_path', 'principal' => 'principal_photo_path'][$slot] ?? abort(404);
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000']]);

        $settings = WebsiteSetting::current();
        $stored = $this->media->storeImage($request->file('image'), 'site', $slot === 'hero' ? 2000 : 600, 200);
        $this->media->delete($settings->{$column}, $stored['thumb_path']);
        $settings->forceFill([$column => $stored['path']])->save();

        return back();
    }

    public function removeImage(Request $request, string $slot): RedirectResponse
    {
        $this->authorizeManage($request);
        $column = ['logo' => 'logo_path', 'hero' => 'hero_image_path', 'principal' => 'principal_photo_path'][$slot] ?? abort(404);
        $settings = WebsiteSetting::current();
        $this->media->delete($settings->{$column});
        $settings->forceFill([$column => null])->save();

        return back();
    }

    public function teachers(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $ids = $request->validate(['shown' => ['array'], 'shown.*' => ['integer']])['shown'] ?? [];
        StaffMember::query()->where('staff_type', 'teacher')->update(['show_on_website' => false]);
        StaffMember::query()->where('staff_type', 'teacher')->whereIn('id', $ids)->update(['show_on_website' => true]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.saved')]);

        return back();
    }

    // ---- Pages ----------------------------------------------------------

    public function pages(Request $request): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('app/website/pages', [
            'pages' => WebsitePage::query()->orderBy('title')->get(['id', 'slug', 'title', 'title_bn', 'status', 'updated_at']),
            'siteUrl' => url('/'),
        ]);
    }

    public function editPage(Request $request, ?WebsitePage $page = null): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('app/website/page-form', [
            'page' => $page?->only(['id', 'slug', 'title', 'title_bn', 'body', 'body_bn', 'seo_description', 'status']),
            'siteUrl' => url('/'),
        ]);
    }

    public function savePage(Request $request, ?WebsitePage $page = null): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'title_bn' => ['nullable', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', TenantRule::unique('website_pages', 'slug')->ignore($page?->id)],
            'body' => ['nullable', 'string', 'max:100000'],
            'body_bn' => ['nullable', 'string', 'max:100000'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ]);
        $data['slug'] = $data['slug'] ?: $this->uniqueSlug(Str::slug($data['title']) ?: 'page', $page?->id);
        $page ??= new WebsitePage;
        $page->fill($data + ['updated_by' => $request->user()->id]);

        if ($data['status'] === 'published' && $page->published_at === null) {
            $page->published_at = now();
        }

        $page->save();
        $this->audit->tenant('website.page_saved', $page, [], ['slug' => $page->slug, 'status' => $page->status]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.saved')]);

        return to_route('website.pages.edit', $page);
    }

    public function destroyPage(Request $request, WebsitePage $page): RedirectResponse
    {
        $this->authorizeManage($request);
        WebsiteMenuItem::query()->where('website_page_id', $page->id)->delete();
        $page->delete();
        $this->audit->tenant('website.page_deleted', null, ['slug' => $page->slug]);

        return to_route('website.pages');
    }

    // ---- Menu -----------------------------------------------------------

    public function menu(Request $request): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('app/website/menu', [
            'items' => WebsiteMenuItem::query()->with('page:id,school_id,title,status')->orderBy('location')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($i) => $i->only(['id', 'location', 'label', 'label_bn', 'link_type', 'builtin', 'website_page_id', 'url', 'sort_order', 'is_visible']) + ['page' => $i->page?->title, 'page_status' => $i->page?->status]),
            'pages' => WebsitePage::query()->orderBy('title')->get(['id', 'title', 'status']),
            'builtins' => WebsiteMenuItem::BUILTINS,
        ]);
    }

    public function storeMenuItem(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'location' => ['required', Rule::in(['header', 'footer'])],
            'label' => ['required', 'string', 'max:120'],
            'label_bn' => ['nullable', 'string', 'max:120'],
            'link_type' => ['required', Rule::in(['builtin', 'page', 'url'])],
            'builtin' => ['required_if:link_type,builtin', 'nullable', Rule::in(WebsiteMenuItem::BUILTINS)],
            'website_page_id' => ['required_if:link_type,page', 'nullable', 'integer', TenantRule::exists('website_pages')],
            'url' => ['required_if:link_type,url', 'nullable', 'url:http,https', 'max:500'],
        ]);
        $data['sort_order'] = (int) WebsiteMenuItem::query()->where('location', $data['location'])->max('sort_order') + 1;
        WebsiteMenuItem::create($data);

        return back();
    }

    public function updateMenuItem(Request $request, WebsiteMenuItem $item): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:120'],
            'label_bn' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_visible' => ['sometimes', 'boolean'],
            'move' => ['sometimes', Rule::in(['up', 'down'])],
        ]);

        if (isset($data['move'])) {
            $siblings = WebsiteMenuItem::query()->where('location', $item->location)->orderBy('sort_order')->orderBy('id')->get()->values();
            $index = $siblings->search(fn ($i) => $i->id === $item->id);
            $swap = $siblings->get($data['move'] === 'up' ? $index - 1 : $index + 1);

            if ($swap) {
                $siblings->each(fn ($i, $n) => $i->forceFill(['sort_order' => $n])->save());
                [$a, $b] = [$item->fresh(), $swap->fresh()];
                $order = $a->sort_order;
                $a->forceFill(['sort_order' => $b->sort_order])->save();
                $b->forceFill(['sort_order' => $order])->save();
            }

            unset($data['move']);
        }

        $item->fill($data)->save();

        return back();
    }

    public function destroyMenuItem(Request $request, WebsiteMenuItem $item): RedirectResponse
    {
        $this->authorizeManage($request);
        $item->delete();

        return back();
    }

    // ---- Gallery --------------------------------------------------------

    public function gallery(Request $request): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('app/website/gallery', [
            'albums' => GalleryAlbum::query()->withCount('photos')->with(['photos' => fn ($q) => $q->limit(1)])->orderBy('sort_order')->orderByDesc('id')->get()
                ->map(fn ($a) => $a->only(['id', 'title', 'title_bn', 'is_public', 'photos_count']) + ['taken_on' => $a->taken_on?->toDateString(), 'cover' => WebsiteMedia::url($a->photos->first()?->thumb_path)]),
        ]);
    }

    public function storeAlbum(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $album = GalleryAlbum::create($this->validateAlbum($request));

        return to_route('website.albums.show', $album);
    }

    public function album(Request $request, GalleryAlbum $album): Response
    {
        $this->authorizeManage($request);
        $album->load('photos');

        return Inertia::render('app/website/album', [
            'album' => $album->only(['id', 'title', 'title_bn', 'description', 'is_public']) + ['taken_on' => $album->taken_on?->toDateString()],
            'photos' => $album->photos->map(fn ($p) => $p->only(['id', 'caption', 'caption_bn']) + ['thumb' => WebsiteMedia::url($p->thumb_path)]),
            'siteUrl' => url('gallery/'.$album->id),
        ]);
    }

    public function updateAlbum(Request $request, GalleryAlbum $album): RedirectResponse
    {
        $this->authorizeManage($request);
        $album->update($this->validateAlbum($request));

        return back();
    }

    public function destroyAlbum(Request $request, GalleryAlbum $album): RedirectResponse
    {
        $this->authorizeManage($request);

        foreach ($album->photos as $photo) {
            $this->media->delete($photo->path, $photo->thumb_path);
            $photo->delete();
        }

        $album->delete();

        return to_route('website.gallery');
    }

    public function uploadPhotos(Request $request, GalleryAlbum $album): RedirectResponse
    {
        $this->authorizeManage($request);
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:20'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:max_width=10000,max_height=10000'],
        ]);
        $order = (int) $album->photos()->max('sort_order');

        foreach ($request->file('photos') as $file) {
            GalleryPhoto::create(['gallery_album_id' => $album->id, 'sort_order' => ++$order] + $this->media->storeImage($file, 'gallery/'.$album->id));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('website.photos_uploaded')]);

        return back();
    }

    public function updatePhoto(Request $request, GalleryPhoto $photo): RedirectResponse
    {
        $this->authorizeManage($request);
        $photo->update($request->validate(['caption' => ['nullable', 'string', 'max:190'], 'caption_bn' => ['nullable', 'string', 'max:190']]));

        return back();
    }

    public function destroyPhoto(Request $request, GalleryPhoto $photo): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->media->delete($photo->path, $photo->thumb_path);
        $photo->delete();

        return back();
    }

    /** @return array<string, mixed> */
    private function validateAlbum(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'title_bn' => ['nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:500'],
            'taken_on' => ['nullable', 'date'],
            'is_public' => ['boolean'],
        ]);
    }

    private function uniqueSlug(string $base, ?int $ignore): string
    {
        $slug = Str::limit($base, 100, '');
        $n = 2;

        while (WebsitePage::query()->where('slug', $slug)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            $slug = Str::limit($base, 100, '').'-'.$n++;
        }

        return $slug;
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('website.manage'), 403);
    }
}
