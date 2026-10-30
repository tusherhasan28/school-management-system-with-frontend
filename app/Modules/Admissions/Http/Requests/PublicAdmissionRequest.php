<?php

namespace App\Modules\Admissions\Http\Requests;

use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Public, unauthenticated admission form. Rate limited by route middleware;
 * a honeypot field ("website") silently drops bots.
 */
class PublicAdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'applied_class_id' => ['required', 'integer', TenantRule::exists('classes')->where('is_active', true)],
            'applicant_name' => ['required', 'string', 'max:160'],
            'applicant_name_bn' => ['nullable', 'string', 'max:160'],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1990-01-01'],
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'guardian_name' => ['required', 'string', 'max:160'],
            'guardian_relationship' => ['required', Rule::in(['father', 'mother', 'guardian', 'other'])],
            'guardian_phone' => ['required', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            'guardian_email' => ['nullable', 'email', 'max:190'],
            'address' => ['required', 'string', 'max:1000'],
            'previous_school' => ['nullable', 'string', 'max:190'],
            'father_name' => ['nullable', 'string', 'max:160'],
            'mother_name' => ['nullable', 'string', 'max:160'],
            'religion' => ['nullable', 'string', 'max:60'],
            'blood_group' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'website' => ['prohibited'], // honeypot
        ];
    }
}
