<?php

namespace App\Http\Requests;

use App\Models\Party;
use Illuminate\Foundation\Http\FormRequest;

class CandidateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalizes the party to the canonical name so `asle`/`ASLE ` all map to
     * the ASLE seat, and an empty submission becomes null ("No party"). The
     * cap check in CandidateController::store matches case-insensitively, but
     * storing the canonical name keeps every downstream grouping clean.
     */
    public function prepareForValidation(): void
    {
        $party = trim((string) $this->input('party_name', ''));

        if ($party === '') {
            $this->merge(['party_name' => null]);

            return;
        }

        $canonical = Party::query()
            ->whereRaw('UPPER(name) = ?', [strtoupper($party)])
            ->value('name');

        $this->merge(['party_name' => $canonical ?? $party]);
    }

    public function rules(): array
    {
        return [
            'position_id' => ['required', 'integer', 'exists:positions,id'],
            'slogan' => ['nullable', 'string', 'max:255'],
            'party_name' => ['nullable', 'string', 'max:255', 'exists:parties,name'],
            'platform_statement' => ['required', 'string', 'max:5000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'certify' => ['required', 'accepted'],
        ];
    }
}
