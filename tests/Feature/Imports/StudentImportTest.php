<?php

namespace Tests\Feature\Imports;

use App\Modules\Imports\Models\DataImportJob;
use App\Modules\Imports\Services\StudentImportService;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\People\Models\StudentEnrollment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\CreatesAcademicStructure;
use Tests\TenantTestCase;

class StudentImportTest extends TenantTestCase
{
    use CreatesAcademicStructure;

    /** @var array<string, mixed> */
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['queue.default' => 'sync']);
        $this->s = $this->academicStructure();
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows): UploadedFile
    {
        $lines = [implode(',', StudentImportService::COLUMNS)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn ($v) => str_contains($v, ',') ? '"'.$v.'"' : $v, $row));
        }

        return UploadedFile::fake()->createWithContent('students.csv', "\xEF\xBB\xBF".implode("\n", $lines)."\n");
    }

    private function row(array $o = []): array
    {
        $d = ['admission_no' => '', 'full_name' => 'Kid', 'name_bn' => '', 'date_of_birth' => '2014-01-01', 'gender' => 'm', 'section' => '', 'roll_no' => '',
            'guardian_name' => 'Parent', 'guardian_phone' => '01711000000', 'guardian_relationship' => 'father', 'guardian_email' => '', 'address' => ''];

        return array_values(array_merge($d, $o));
    }

    private function upload(UploadedFile $file, array $options = []): DataImportJob
    {
        $this->actingAs($this->ownerOf($this->school))
            ->post(route('imports.store'), $options + [
                'file' => $file,
                'academic_session_id' => $this->s['session']->id,
                'class_id' => $this->s['class6']->id,
                'section_id' => $this->s['sectionA']->id,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return DataImportJob::query()->latest('id')->firstOrFail();
    }

    public function test_preview_reports_valid_duplicate_and_error_rows_without_writing_students(): void
    {
        $this->makeStudent($this->s['session'], $this->s['class6'], overrides: ['full_name' => 'Existing Kid', 'date_of_birth' => '2014-05-05']);

        $job = $this->upload($this->csv([
            $this->row(['full_name' => 'Ayesha', 'admission_no' => 'A-1', 'roll_no' => '1']),
            $this->row(['full_name' => 'Tanvir', 'section' => 'B', 'guardian_phone' => '8801722000000']),
            $this->row(['full_name' => 'Dup In File', 'admission_no' => 'A-1']),       // duplicate admission no in file
            $this->row(['full_name' => 'Existing Kid', 'date_of_birth' => '2014-05-05']), // duplicate in DB
            $this->row(['full_name' => '', 'guardian_phone' => '12345']),                 // errors
            $this->row(['full_name' => 'Wrong Section', 'section' => 'Z']),              // unknown section
        ]));

        $this->assertSame('validated', $job->status);
        $this->assertSame([6, 2, 2, 2], [$job->total_rows, $job->valid_rows, $job->duplicate_rows, $job->error_rows]);
        $this->assertSame(1, Student::query()->count(), 'Nothing imported before confirmation.');
        $this->assertNotNull($job->error_report_path);
        $this->assertStringStartsWith('schools/'.$this->school->id.'/imports/', $job->source_path);
    }

    public function test_confirm_imports_valid_rows_and_skips_the_rest(): void
    {
        $job = $this->upload($this->csv([
            $this->row(['full_name' => 'Ayesha', 'roll_no' => '1', 'name_bn' => 'আয়েশা']),
            $this->row(['full_name' => 'Nusrat', 'roll_no' => '2']),                    // same guardian → sibling
            $this->row(['full_name' => 'Tanvir', 'section' => 'B', 'guardian_name' => 'Abdul', 'guardian_phone' => '01722000000']),
            $this->row(['full_name' => '', 'guardian_phone' => 'x']),
        ]));

        $this->actingAs($this->ownerOf($this->school))->post(route('imports.confirm', $job))->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertSame(3, $job->imported_rows);
        $this->assertSame(3, Student::query()->count());
        $this->assertSame(2, Guardian::query()->count());
        $this->assertSame('আয়েশা', Student::query()->where('full_name', 'Ayesha')->value('name_bn'));
        $this->assertSame($this->s['sectionB']->id, StudentEnrollment::query()->whereHas('student', fn ($q) => $q->where('full_name', 'Tanvir'))->value('section_id'));

        // Cannot be run twice.
        $this->actingAs($this->ownerOf($this->school))->post(route('imports.confirm', $job))->assertSessionHasErrors('import');
        $this->assertSame(3, Student::query()->count());
    }

    public function test_xlsx_files_are_supported(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(StudentImportService::COLUMNS));
        $writer->addRow(Row::fromValues($this->row(['full_name' => 'Excel Kid', 'name_bn' => 'এক্সেল'])));
        $writer->close();

        $job = $this->upload(new UploadedFile($path, 'students.xlsx', null, null, true));

        $this->assertSame([1, 1], [$job->total_rows, $job->valid_rows]);
        $this->actingAs($this->ownerOf($this->school))->post(route('imports.confirm', $job));
        $this->assertSame('এক্সেল', Student::query()->where('full_name', 'Excel Kid')->value('name_bn'));
    }

    public function test_error_report_neutralises_spreadsheet_formulas_and_is_school_only(): void
    {
        $job = $this->upload($this->csv([$this->row(['full_name' => '=HYPERLINK("http://evil")', 'guardian_phone' => 'bad'])]));

        $response = $this->actingAs($this->ownerOf($this->school))->get(route('imports.errors', $job));
        $response->assertOk();
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());

        $beta = $this->provisionSchool('beta');
        $this->flushSession();
        $this->actingAs($this->ownerOf($beta))->get($this->schoolUrl($beta, '/app/imports/'.$job->id.'/errors'))->assertNotFound();
        $this->actingAs($this->ownerOf($beta))->get($this->schoolUrl($beta, '/app/imports/'.$job->id))->assertNotFound();
    }

    public function test_upload_rejects_other_schools_class_and_bad_files(): void
    {
        $beta = $this->provisionSchool('beta');
        $betaClass = $this->inSchool($beta, fn () => $this->academicStructure()['class6']);
        $this->onSchool($this->school);

        $this->actingAs($this->ownerOf($this->school))
            ->post(route('imports.store'), ['file' => $this->csv([$this->row()]), 'academic_session_id' => $this->s['session']->id, 'class_id' => $betaClass->id])
            ->assertSessionHasErrors('class_id');

        $this->actingAs($this->ownerOf($this->school))
            ->post(route('imports.store'), ['file' => UploadedFile::fake()->create('virus.exe', 10), 'academic_session_id' => $this->s['session']->id, 'class_id' => $this->s['class6']->id])
            ->assertSessionHasErrors('file');
    }

    public function test_only_import_managers_can_import(): void
    {
        $this->actingAs($this->userWithRole('Teacher'))->get(route('imports.index'))->assertForbidden();
        $this->actingAs($this->userWithRole('Principal'))->get(route('imports.template'))->assertForbidden();
        $this->actingAs($this->userWithRole('Admission Officer'))->get(route('imports.template'))->assertOk();
    }
}
