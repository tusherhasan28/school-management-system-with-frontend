<?php

namespace Tests\Feature\Website;

use App\Models\User;
use App\Modules\Notices\Models\Event;
use App\Modules\Notices\Models\Notice;
use App\Modules\People\Models\StaffMember;
use App\Modules\Website\Models\GalleryAlbum;
use App\Modules\Website\Models\GalleryPhoto;
use App\Modules\Website\Models\WebsitePage;
use App\Modules\Website\Models\WebsiteSetting;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\Concerns\CreatesTeachers;
use Tests\TenantTestCase;

/**
 * The public school website: published content only, no private data,
 * crawlable HTML with canonical/hreflang/sitemap, and safe media.
 */
class PublicSiteTest extends TenantTestCase
{
    use CreatesAcademicStructure, CreatesTeachers;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = $this->ownerOf($this->school);
    }

    private function publish(array $extra = []): void
    {
        $this->actingAs($this->owner)->put(route('website.update'), $extra + [
            'template' => 'classic', 'theme_color' => '#0f766e', 'default_language' => 'en', 'is_published' => true, 'show_teachers' => false,
            'about' => 'We are a **friendly** school.', 'seo_description' => 'Alpha School in Dhaka.',
        ])->assertSessionHasNoErrors();
        auth()->logout();
    }

    /** A real JPEG with an EXIF (APP1) segment carrying a GPS-like marker. */
    private function jpegWithExif(): UploadedFile
    {
        $img = imagecreatetruecolor(300, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 20, 120, 90));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();
        $payload = "Exif\0\0".'MM'."\0*\0\0\0\x08".str_repeat("\0", 8).'GPSSECRET-23.81N-90.41E';
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
        file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

        return new UploadedFile($path, 'phone-photo.jpg', 'image/jpeg', null, true);
    }

    public function test_unpublished_site_shows_holding_page_but_managers_can_preview(): void
    {
        WebsiteSetting::current();
        $this->get('/')->assertOk()->assertSee('noindex', false)->assertDontSee('friendly');
        $this->get('/robots.txt')->assertOk()->assertSee("Disallow: /\n", false);
        $this->get('/about')->assertOk()->assertSee('noindex', false);

        $this->actingAs($this->owner)->get('/')->assertOk()->assertSee(__('Preview'));
    }

    public function test_published_site_has_seo_tags_and_sitemap(): void
    {
        $this->publish();
        Notice::create(['title' => 'Exam routine', 'body' => 'See below', 'audiences' => ['public'], 'status' => 'published']);
        WebsitePage::create(['slug' => 'library', 'title' => 'Library', 'body' => 'Open daily', 'status' => 'published']);
        WebsitePage::create(['slug' => 'secret-plan', 'title' => 'Secret plan', 'status' => 'draft']);

        $home = $this->get('/')->assertOk();
        $home->assertSee('<link rel="canonical" href="'.url('/').'"', false)
            ->assertSee('hreflang="bn"', false)
            ->assertSee('<meta name="description" content="Alpha School in Dhaka.">', false)
            ->assertSee('application/ld+json', false)
            ->assertDontSee('noindex', false)
            ->assertSee('Exam routine');

        $this->get('/about')->assertOk()->assertSee('<strong>friendly</strong>', false);
        $this->get('/p/library')->assertOk()->assertSee('Open daily');
        $this->get('/p/secret-plan')->assertNotFound();

        $sitemap = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml');
        $sitemap->assertSee(url('/p/library'), false)->assertSee(url('/notices'), false)->assertDontSee('secret-plan')->assertDontSee('/teachers');
        $this->get('/robots.txt')->assertSee('Disallow: /app/')->assertSee('Disallow: /parent/')->assertSee('Sitemap: '.url('sitemap.xml'));

        $this->get('/?lang=bn')->assertOk()->assertSee('<html lang="bn"', false)->assertSee('<link rel="canonical" href="'.url('/').'?lang=bn"', false);
    }

    public function test_no_student_guardian_or_private_staff_data_reaches_the_site(): void
    {
        $s = $this->academicStructure();
        $this->makeStudent($s['session'], $s['class6'], $s['sectionA'], ['full_name' => 'Private Student Name', 'birth_registration_no' => '20151234567890123'], '01799888777');
        [, $hidden] = $this->teacher('Teacher', 'Hidden Teacher');
        [, $shown] = $this->teacher('Teacher', 'Shown Teacher');
        $shown->forceFill(['show_on_website' => true, 'designation' => 'Senior Teacher', 'phone' => '01755555555', 'email' => 'shown@example.com'])->save();

        Notice::create(['title' => 'Staff only memo', 'body' => 'internal', 'audiences' => ['staff'], 'status' => 'published']);
        Notice::create(['title' => 'Parents only memo', 'body' => 'internal', 'audiences' => ['parents'], 'status' => 'published']);
        Notice::create(['title' => 'Draft notice', 'body' => 'x', 'audiences' => ['public'], 'status' => 'draft']);
        Notice::create(['title' => 'Future notice', 'body' => 'x', 'audiences' => ['public'], 'status' => 'published', 'publish_at' => now()->addDay()]);
        Notice::create(['title' => 'Expired notice', 'body' => 'x', 'audiences' => ['public'], 'status' => 'published', 'expires_at' => now()->subDay()]);
        $staffNotice = Notice::query()->where('title', 'Staff only memo')->sole();
        Event::create(['title' => 'Internal training', 'starts_on' => now()->addDays(3)->toDateString(), 'is_public' => false]);
        $private = GalleryAlbum::create(['title' => 'Private album', 'is_public' => false]);

        $this->publish(['show_teachers' => true]);

        $pages = ['/', '/about', '/academics', '/teachers', '/notices', '/events', '/gallery', '/contact', '/sitemap.xml'];
        $html = collect($pages)->map(fn ($p) => $this->get($p)->assertOk()->getContent())->implode("\n");

        foreach (['Private Student Name', '01799888777', '20151234567890123', 'Hidden Teacher', '01755555555', 'shown@example.com',
            'Staff only memo', 'Parents only memo', 'Draft notice', 'Future notice', 'Expired notice', 'Internal training', 'Private album'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "Public site leaked: {$secret}");
        }
        $this->assertStringContainsString('Shown Teacher', $html);
        $this->assertStringContainsString('Senior Teacher', $html);
        $this->assertStringContainsString('Class 6', $html);

        $this->get('/notices/'.$staffNotice->id)->assertNotFound();
        $this->get('/gallery/'.$private->id)->assertNotFound();
    }

    public function test_teachers_page_is_off_unless_the_school_turns_it_on(): void
    {
        $this->publish();
        $this->get('/teachers')->assertNotFound();
    }

    public function test_markdown_is_escaped_against_script_injection(): void
    {
        $this->publish(['about' => "<script>alert('x')</script>\n\n[click](javascript:alert(1)) <img src=x onerror=alert(2)>"]);
        Notice::create(['title' => '<b>Bold?</b>', 'body' => '<iframe src="https://evil.test"></iframe>', 'audiences' => ['public'], 'status' => 'published']);

        $about = $this->get('/about')->assertOk()->getContent();
        $this->assertStringNotContainsString("<script>alert('x')</script>", $about);
        $this->assertStringNotContainsString('<img src=x onerror', $about);
        $this->assertStringNotContainsString('href="javascript:', $about);

        $notice = $this->get('/notices/'.Notice::query()->sole()->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('<iframe', $notice);
        $this->assertStringNotContainsString('<b>Bold?</b>', $notice);
    }

    public function test_uploaded_images_are_re_encoded_without_exif_and_served_safely(): void
    {
        $this->publish();
        $album = GalleryAlbum::create(['title' => 'Sports day', 'is_public' => true]);
        $this->actingAs($this->owner)->post(route('website.albums.photos', $album), ['photos' => [$this->jpegWithExif()]])->assertSessionHasNoErrors();
        auth()->logout();

        $photo = GalleryPhoto::query()->sole();
        $storage = app(TenantStorage::class);
        $bytes = $storage->disk()->get($storage->path($photo->path));
        $this->assertStringStartsWith("\xFF\xD8", $bytes);
        $this->assertStringNotContainsString('GPSSECRET', $bytes);
        $this->assertStringNotContainsString('Exif', $bytes);
        $this->assertSame([300, 200], [$photo->width, $photo->height]);

        $this->get('/media/'.$photo->path)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/gallery/'.$album->id)->assertOk()->assertSee('/media/'.$photo->thumb_path, false);

        // Only this school's website images: no traversal, no other folders.
        $storage->disk()->put($storage->path('notices/private.pdf'), 'x');
        $this->get('/media/notices/private.pdf')->assertNotFound();
        $this->get('/media/website/../notices/private.pdf')->assertNotFound();
        $this->get('/media/website/..%2Fnotices%2Fprivate.pdf')->assertNotFound();
        $this->get('/media/website/missing.jpg')->assertNotFound();

        // Another school's host cannot serve this school's file.
        $beta = $this->provisionSchool('beta');
        $this->get($this->schoolUrl($beta, '/media/'.$photo->path))->assertNotFound();
    }

    public function test_non_images_are_rejected(): void
    {
        $album = GalleryAlbum::create(['title' => 'A', 'is_public' => true]);
        $fake = UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo 1;');
        $this->actingAs($this->owner)->post(route('website.albums.photos', $album), ['photos' => [$fake]])->assertSessionHasErrors('photos.0');
        $this->assertSame(0, GalleryPhoto::query()->count());
    }

    public function test_website_and_notice_admin_need_their_permissions(): void
    {
        $accountant = $this->userWithRole('Accountant');
        $this->actingAs($accountant)->get(route('website.edit'))->assertForbidden();
        $this->actingAs($accountant)->put(route('website.update'), ['template' => 'modern', 'theme_color' => '#000000', 'default_language' => 'bn'])->assertForbidden();
        $this->actingAs($accountant)->get(route('notices.index'))->assertForbidden();

        $principal = $this->userWithRole('Principal');
        $this->actingAs($principal)->get(route('website.edit'))->assertOk();
        $this->actingAs($principal)->post(route('notices.store'), ['title' => 'Holiday', 'audiences' => ['public', 'parents'], 'status' => 'published'])->assertSessionHasNoErrors();
        $this->assertSame(['public', 'parents'], Notice::query()->sole()->audiences);
    }

    public function test_settings_validation_blocks_unsafe_links(): void
    {
        $this->actingAs($this->owner)->put(route('website.update'), [
            'template' => 'retro', 'theme_color' => 'red', 'default_language' => 'fr',
            'facebook_url' => 'javascript:alert(1)', 'map_url' => 'https://evil.test/maps/embed', 'eiin' => '12ab',
        ])->assertSessionHasErrors(['template', 'theme_color', 'default_language', 'facebook_url', 'map_url', 'eiin']);
    }

    public function test_another_schools_content_never_shows(): void
    {
        $beta = $this->provisionSchool('beta');
        $this->inSchool($beta, function () {
            WebsiteSetting::current()->forceFill(['is_published' => true])->save();
            Notice::create(['title' => 'Beta only notice', 'body' => 'x', 'audiences' => ['public'], 'status' => 'published']);
            WebsitePage::create(['slug' => 'beta-page', 'title' => 'Beta page', 'status' => 'published']);
        });
        $this->publish();

        $this->get('/notices')->assertOk()->assertDontSee('Beta only notice');
        $this->get('/p/beta-page')->assertNotFound();
        $this->get($this->schoolUrl($beta, '/notices'))->assertOk()->assertSee('Beta only notice');
    }
}
