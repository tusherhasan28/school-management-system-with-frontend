<?php

namespace Tests;

use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Feature tests that run on one school's host (the starter kit auth and
 * settings tests, plus module tests). $this->school is provisioned and the
 * tenant context and URL root point at it.
 */
abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = $this->provisionSchool('alpha');
        $this->onSchool($this->school);
    }
}
