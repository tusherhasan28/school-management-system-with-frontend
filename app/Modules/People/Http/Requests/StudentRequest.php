<?php

namespace App\Modules\People\Http\Requests;

use App\Modules\People\Models\Student;
use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (with guardians + enrollment) or update a student. Every referenced id
 * is validated against the current school.
 */
class StudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $student = $this->route('student');

        return $student instanceof Student
            ? $this->user()->can('update', $student)
            : $this->user()->can('create', Student::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $student = $this->route('student');
        $creating = ! $student instanceof Student;

        $rules = [
            'admission_no' => [$creating ? 'nullable' : 'prohibited', 'string', 'max:60', 'alpha_dash', TenantRule::unique('students', 'admission_no')],
            'full_name' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1950-01-01'],
            'gender' => ['required', Rule::in(['male', 'female', 'other', 'not_specified'])],
            'blood_group' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'religion' => ['nullable', 'string', 'max:60'],
            'nationality' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:190'],
            'birth_registration_no' => ['nullable', 'digits_between:10,20'],
            'present_address' => ['nullable', 'string', 'max:1000'],
            'permanent_address' => ['nullable', 'string', 'max:1000'],
            'admission_date' => ['nullable', 'date'],
        ];

        if ($creating) {
            $rules += [
                'academic_session_id' => ['required', 'integer', TenantRule::exists('academic_sessions')],
                'class_id' => ['required', 'integer', TenantRule::exists('classes')],
                'section_id' => ['nullable', 'integer', TenantRule::exists('sections')->where('class_id', $this->integer('class_id'))],
                'roll_no' => ['nullable', 'string', 'max:40'],
                'guardians' => ['required', 'array', 'min:1', 'max:4'],
                'guardians.*.full_name' => ['required', 'string', 'max:160'],
                'guardians.*.name_bn' => ['nullable', 'string', 'max:160'],
                'guardians.*.phone' => ['required', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
                'guardians.*.email' => ['nullable', 'email', 'max:190'],
                'guardians.*.occupation' => ['nullable', 'string', 'max:120'],
                'guardians.*.relationship' => ['required', Rule::in(['father', 'mother', 'guardian', 'brother', 'sister', 'uncle', 'aunt', 'grandparent', 'other'])],
                'guardians.*.is_primary' => ['boolean'],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['guardians.*.phone' => __('guardian phone'), 'guardians.*.full_name' => __('guardian name')];
    }
}
