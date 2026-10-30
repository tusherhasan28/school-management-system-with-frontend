<?php

namespace App\Services\Tenancy;

use App\Enums\SchoolStatus;
use App\Models\School;
use App\Models\SchoolRegistration;
use App\Notifications\SchoolRegistrationReceived;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Public registration → platform review → provisioned school (trial) with the
 * contact person invited as School Owner.
 */
class SchoolRegistrationService
{
    public function __construct(
        private readonly SchoolProvisioner $provisioner,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): SchoolRegistration
    {
        $registration = SchoolRegistration::create($data);

        Notification::route('mail', $registration->email)
            ->notify(new SchoolRegistrationReceived($registration->institution_name));

        return $registration;
    }

    public function slugIsAvailable(string $slug): bool
    {
        try {
            $this->provisioner->assertValidSlug($slug);
        } catch (InvalidArgumentException) {
            return false;
        }

        return ! SchoolRegistration::query()
            ->where('requested_slug', $slug)
            ->where('status', 'pending')
            ->exists();
    }

    public function approve(SchoolRegistration $registration, ?string $slug = null): School
    {
        if ($registration->status !== 'pending') {
            throw ValidationException::withMessages(['status' => __('school.invalid_transition', ['from' => $registration->status, 'to' => 'approved'])]);
        }

        $slug ??= $registration->requested_slug;

        return DB::transaction(function () use ($registration, $slug) {
            try {
                $school = $this->provisioner->provision([
                    'slug' => $slug,
                    'name' => $registration->institution_name,
                    'name_bn' => $registration->institution_name_bn,
                    'institution_type' => $registration->institution_type,
                    'email' => $registration->email,
                    'phone' => $registration->phone,
                    'locale' => 'bn',
                ], [
                    'name' => $registration->contact_name,
                    'email' => $registration->email,
                ], SchoolStatus::Trial);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['slug' => $e->getMessage()]);
            }

            $school->forceFill([
                'district' => $registration->district,
                'address' => $registration->address,
            ])->save();

            $registration->forceFill([
                'status' => 'approved',
                'school_id' => $school->id,
                'reviewed_by' => auth('platform')->id(),
                'reviewed_at' => now(),
            ])->save();

            $this->audit->platform('registration.approved', $registration, $school->id, ['slug' => $slug]);

            return $school;
        });
    }

    public function reject(SchoolRegistration $registration, string $reason): void
    {
        if ($registration->status !== 'pending') {
            throw ValidationException::withMessages(['status' => __('school.invalid_transition', ['from' => $registration->status, 'to' => 'rejected'])]);
        }

        $registration->forceFill([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => auth('platform')->id(),
            'reviewed_at' => now(),
        ])->save();

        $this->audit->platform('registration.rejected', $registration, metadata: ['reason' => $reason]);
    }
}
