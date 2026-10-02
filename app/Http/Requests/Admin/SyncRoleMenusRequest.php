<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Dedicated request for syncing a role's menu assignments.
 * The special "all" flag is intentionally NOT accepted — only concrete,
 * validated menu ids may be attached to a role.
 */
class SyncRoleMenusRequest extends FormRequest
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
            'menus' => ['present', 'array'],
            'menus.*' => ['integer', 'distinct', 'exists:menus,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'menus.*.exists' => 'One or more selected menus do not exist.',
            'menus.*.distinct' => 'Duplicate menu selections are not allowed.',
        ];
    }
}
