<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
            'slug' => [
                'nullable', 'string', 'max:100',
                'alpha_dash',
                Rule::unique('roles', 'slug')->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'menus' => ['sometimes', 'array'],
            'menus.*' => ['integer', 'exists:menus,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (blank($this->input('slug'))) {
            $this->merge([
                'slug' => Str::slug((string) $this->input('name')),
            ]);
        }

        // The super-admin slug is reserved and cannot be created via UI.
        if ($this->input('slug') === Role::SUPER_ADMIN_SLUG) {
            $this->merge(['slug' => '']);
        }
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'This slug is already taken or reserved.',
        ];
    }
}
