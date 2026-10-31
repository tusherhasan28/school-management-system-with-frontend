<?php

namespace Tests\Feature\Ops;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesFullTenantDataset;
use Tests\TestCase;

class SchoolReadinessTest extends TestCase
{
    use CreatesFullTenantDataset, RefreshDatabase;

    public function test_new_school_is_not_ready_and_set_up_school_passes_required_checks(): void
    {
        $this->artisan('school:readiness', ['slug' => 'nope'])->assertFailed();

        $school = $this->provisionSchool('pilot');
        $this->artisan('school:readiness', ['slug' => 'pilot'])->assertFailed();

        $this->fullDataset($school);
        $this->inSchool($school, fn () => \App\Modules\Exams\Models\GradingScale::create(['name' => 'GPA', 'rules' => [], 'is_default' => true]));

        $exit = Artisan::call('school:readiness', ['slug' => 'pilot']);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('0 required item(s) missing', $output);
        // Counts only: no student or guardian names in the output.
        $this->assertStringNotContainsString('Secret pilot', $output);
    }
}
