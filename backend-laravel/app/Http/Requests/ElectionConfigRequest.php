<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ElectionConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'phase' => ['nullable', 'in:registration,voting_open,voting_closed'],
            'registration_opens_at' => ['nullable', 'date'],
            'voting_opens_at' => ['nullable', 'date'],
            'voting_closes_at' => ['nullable', 'date', 'after_or_equal:registration_opens_at'],
            'positions' => ['nullable', 'array'],
            'positions.*.slug' => ['required_with:positions', 'string'],
            'positions.*.seat_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'positions.*.active' => ['nullable', 'boolean'],
        ];
    }
}