<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dedicated request for syncing a user's roles via the UI/API.
 * Validates every submitted role id exists — never trusts browser IDs.
 */
class SyncUserRolesRequest extends FormRequest
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
            'roles' => ['present', 'array'],
            'roles.*' => ['integer', 'distinct', 'exists:roles,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'roles.*.exists' => 'One or more selected roles do not exist.',
            'roles.*.distinct' => 'Duplicate role selections are not allowed.',
        ];
    }
}
