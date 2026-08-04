<?php

namespace App\Http\Requests\FilterPreset;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFilterPresetRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('task_filter_presets')->where('user_id', $this->user()->id),
            ],
            'filters' => ['required', 'array'],
            'filters.team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'filters.status' => ['nullable', Rule::in(['pending', 'in_progress', 'completed', 'cancelled'])],
            'filters.priority' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'filters.assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
