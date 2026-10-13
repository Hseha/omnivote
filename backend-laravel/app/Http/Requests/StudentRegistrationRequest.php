<?php

namespace App\Http\Requests;

use App\Support\PasswordPolicy;
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
        return [
            'student_id' => ['required', 'string', 'max:64', Rule::exists('registrar_imports', 'student_id'), Rule::unique('users', 'student_id')],
            // Gate 1 of the M-5 fix: a student_id in the eligibility feed is
            // public class-list data, so on its own it must not be enough to
            // claim the identity. The registrar-issued activation code is
            // verified in RegistrationController against the stored hash.
            'activation_code' => ['required', 'string', 'max:16'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => PasswordPolicy::confirmedRules(),
        ];
    }
}
