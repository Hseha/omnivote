<?php

namespace App\Http\Requests;

use App\Support\AppSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudentRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $settings = fn (string $key, mixed $default) => AppSettings::security($key, $default);

        $minLength = max(6, min(64, (int) $settings('passwordMinLength', 8)));

        // Defaults mirror the policy actually enforced before any admin saves
        // a Security profile: min 8 + lowercase + uppercase + number (no
        // special-character requirement). The toggles then layer on top.
        $requireSpecial = (bool) $settings('passwordRequireSpecial', false);
        $requireNumber = (bool) $settings('passwordRequireNumber', true);
        $requireUpper = (bool) $settings('passwordRequireUpper', true);

        // The base policy mirrors Settings → Security: everything a lower
        // length floor and a lowercase letter, then layers the toggled
        // complexity requirements on top.
        $password = ['required', 'confirmed', "min:{$minLength}", 'regex:/[a-z]/'];

        if ($requireUpper) {
            $password[] = 'regex:/[A-Z]/';
        }
        if ($requireNumber) {
            $password[] = 'regex:/[0-9]/';
        }
        if ($requireSpecial) {
            $password[] = 'regex:/[^a-zA-Z0-9]/';
        }

        return [
            'student_id' => ['required', 'string', 'max:64', Rule::exists('registrar_imports', 'student_id'), Rule::unique('users', 'student_id')],
            // Gate 1 of the M-5 fix: a student_id in the eligibility feed is
            // public class-list data, so on its own it must not be enough to
            // claim the identity. The registrar-issued activation code is
            // verified in RegistrationController against the stored hash.
            'activation_code' => ['required', 'string', 'max:16'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => $password,
        ];
    }
}
