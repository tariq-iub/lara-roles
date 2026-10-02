<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateRoleRequest extends FormRequest
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
        $roleId = $this->route('role')->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($roleId)],
            'slug' => [
                'required', 'string', 'max:100', 'alpha_dash',
                Rule::unique('roles', 'slug')->ignore($roleId)->whereNull('deleted_at'),
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
            $this->merge(['slug' => Str::slug((string) $this->input('name'))]);
        }

        // Protect the reserved super-admin slug from being assigned to other roles.
        $currentSlug = $this->route('role')->slug;

        if ($this->input('slug') === Role::SUPER_ADMIN_SLUG && $currentSlug !== Role::SUPER_ADMIN_SLUG) {
            throw ValidationException::withMessages([
                'slug' => 'The super-admin slug is reserved and cannot be reassigned.',
            ]);
        }
    }
}
