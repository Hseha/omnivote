<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RegistrationController extends Controller
{
    public function register(\App\Http\Requests\StudentRegistrationRequest $request)
    {
        if (! env('STUDENT_REGISTRATION_OPEN', false)) {
            return response()->json(['message' => 'Registration closed'], 403);
        }

        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'student_id' => $validated['student_id'],
            'password' => Hash::make($validated['password']),
            'role' => 'student',
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json(['token' => $token, 'student' => $user], 201);
    }
}
