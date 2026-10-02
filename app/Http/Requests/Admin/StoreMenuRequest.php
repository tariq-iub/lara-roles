<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreMenuRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:150', 'alpha_dash',
                Rule::unique('menus', 'slug')->whereNull('deleted_at'),
            ],
            'parent_id' => [
                'nullable', 'integer', 'exists:menus,id',
                Rule::notIn([$this->route('menu')?->id]), // cannot be own parent (update)
            ],
            'route_name' => ['nullable', 'string', 'max:191', 'regex:/^[a-z0-9_\-]+(\.[a-z0-9_\-]+)*(\.\*)?$/i'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
            'is_visible' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (blank($this->input('slug'))) {
            $this->merge(['slug' => Str::slug((string) $this->input('name'))]);
        }

        $this->merge([
            'route_name' => filled($this->input('route_name')) ? trim((string) $this->input('route_name')) : null,
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Cycle prevention: a menu may not be placed under one of its own descendants.
            $parentId = $this->input('parent_id');
            $menu = $this->route('menu');

            if ($parentId && $menu) {
                $parent = \App\Models\Menu::find($parentId);

                if ($parent && ($parent->id === $menu->id || $parent->isDescendantOf($menu->id))) {
                    $validator->errors()->add(
                        'parent_id',
                        'A menu cannot be its own parent or a descendant of itself (cycle detected).'
                    );
                }
            }
        });
    }
}
