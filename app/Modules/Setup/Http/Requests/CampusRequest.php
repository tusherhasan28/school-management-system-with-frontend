<?php

namespace App\Modules\Setup\Http\Requests;

use App\Modules\Setup\Models\Campus;
use App\Support\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

class CampusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campus = $this->route('campus');

        return $campus instanceof Campus
            ? $this->user()->can('update', $campus)
            : $this->user()->can('create', Campus::class);
    }

    /**
     * school_id is deliberately absent: it comes from the tenant context.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $campus = $this->route('campus');

        return [
            'name' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40', 'alpha_dash', TenantRule::unique('campuses', 'code')->ignore($campus?->getKey())],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{6,30}$/'],
            'is_main' => ['boolean'],
        ];
    }
}
