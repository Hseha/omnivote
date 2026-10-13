<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCandidateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:pending,approved,rejected,withdrawn'],
            // Optional committee note shown to the applicant on the My Candidacy
            // screen. Only meaningful on rejection; cleared on approval so a
            // stale reason never survives a status flip.
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
