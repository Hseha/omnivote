<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/admin/candidates — admin-created candidacy (React Candidates
 * screen "Add Candidate"). Links an existing registered student (by their
 * student ID) to an active ballot position; the row lands in `pending` so
 * the normal review flow still applies.
 */
class StoreAdminCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Permissions are role→permission config lists (see config/permissions.php
        // + EnsurePermission middleware); the Gate mirrors nothing here, so check
        // the same list so an under-authorized caller gets a clean 403.
        $user = $this->user();

        if (! $user) {
            return false;
        }

        $permissions = config('permissions.roles.'.$user->role, []);

        return in_array($user->role, config('permissions.panel_roles', []), true)
            && in_array('candidates.review', $permissions, true);
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'string', 'exists:users,student_id'],
            'position_id' => ['required', 'integer', 'exists:positions,id'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'slogan' => ['nullable', 'string', 'max:255'],
            'platform_statement' => ['nullable', 'string', 'max:5000'],
        ];
    }
}