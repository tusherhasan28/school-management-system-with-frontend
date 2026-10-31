<?php

namespace Tests\Feature\Exports;

use App\Modules\Exports\Models\DataExport;
use App\Support\Tenancy\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\TenantTestCase;
use ZipArchive;

/** A school can take all of its records with it (retention policy). */
class SchoolDataExportTest extends TenantTestCase
{
    use CreatesAcademicStructure;

    public function test_owner_exports_all_data_as_csv_zip_without_id_numbers(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'sync']);
        $s = $this->academicStructure();
        $this->makeStudent($s['session'], $s['class6'], $s['sectionA'], ['full_name' => 'Ayesha Rahman', 'name_bn' => 'আয়েশা', 'birth_registration_no' => '20141234567890123'], '01711111111');
        $this->makeStudent($s['session'], $s['class6'], $s['sectionA'], ['full_name' => '=HYPERLINK("http://evil")'], '01722222222');
        $owner = $this->ownerOf($this->school);

        $this->actingAs($owner)->post(route('data-exports.store'))->assertSessionHasNoErrors();
        $export = DataExport::query()->sole();
        $this->assertSame('ready', $export->status);
        $this->assertSame(2, $export->counts['students']);
        $this->assertSame(2, $export->counts['guardians']);

        $response = $this->actingAs($owner)->get(route('data-exports.download', $export));
        $response->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));
        $students = $zip->getFromName('students.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $students);
        $this->assertStringContainsString('আয়েশা', $students);
        $this->assertStringNotContainsString('20141234567890123', $students);
        $this->assertStringContainsString("'=HYPERLINK", $students); // formula neutralised
        $this->assertNotFalse($zip->getFromName('enrollments.csv'));
        $this->assertNotFalse($zip->getFromName('invoices.csv'));
        $zip->close();

        // Expired exports cannot be downloaded; another school's export is unreachable.
        $this->travel(8)->days();
        $this->actingAs($owner)->get(route('data-exports.download', $export))->assertNotFound();
    }

    public function test_only_school_admins_export_and_only_their_own_school(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'sync']);
        $this->actingAs($this->userWithRole('Principal'))->post(route('data-exports.store'))->assertForbidden();

        $beta = $this->provisionSchool('beta');
        $betaExport = $this->inSchool($beta, function () {
            $e = DataExport::create([]);
            $path = app(TenantStorage::class)->path('exports/x.zip');
            app(TenantStorage::class)->disk()->put($path, 'zip');
            $e->forceFill(['status' => 'ready', 'path' => $path, 'expires_at' => now()->addDay()])->save();

            return $e;
        });
        $this->onSchool($this->school);
        $this->actingAs($this->ownerOf($this->school))->get(route('data-exports.download', $betaExport->id))->assertNotFound();
    }
}
