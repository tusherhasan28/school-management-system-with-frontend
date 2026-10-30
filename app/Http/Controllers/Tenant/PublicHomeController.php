<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public school website home. Only public, school-level fields are exposed;
 * the full website builder arrives in Milestone 6.
 */
class PublicHomeController extends Controller
{
    public function __invoke(TenantContext $tenant): Response
    {
        $school = $tenant->require();

        return Inertia::render('tenant/public/home', [
            'school' => [
                'name' => $school->name,
                'name_bn' => $school->name_bn,
                'institution_type' => $school->institution_type,
                'address' => $school->address,
                'phone' => $school->phone,
                'email' => $school->email,
            ],
        ]);
    }
}
