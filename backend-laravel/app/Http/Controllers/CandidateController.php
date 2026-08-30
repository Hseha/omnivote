<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\CandidateApplicationRequest;
use App\Models\Candidate;
use Illuminate\Support\Str;

class CandidateController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function store(Request $request)
    {
        $validated = $request->validate((new CandidateApplicationRequest())->rules());

        $sanitized = strip_tags($validated['platform_statement'], '<p><br><strong><em><ul><ol><li>');

        $candidate = Candidate::create([
            'user_id' => $request->user()->id,
            'position_id' => $validated['position_id'] ?? null,
            'slogan' => $validated['slogan'] ?? null,
            'platform_statement' => $sanitized,
            'approval_status' => 'pending',
        ]);

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('candidates', 'public');
            $candidate->photo_path = $path;
            $candidate->save();
        }

        return response()->json(['candidate' => $candidate], 201);
    }

    public function show($id)
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function update(Request $request, $id)
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }

    public function destroy($id)
    {
        return response()->json(['message' => 'Not implemented'], 501);
    }
}
