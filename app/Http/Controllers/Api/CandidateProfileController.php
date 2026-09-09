<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCandidatePersonalInformationRequest;
use App\Http\Resources\CandidateProfileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CandidateProfileController extends Controller
{
    public function show(Request $request)
    {
        $profile = $request->user()->candidateProfile;

        if (! $profile) {
            return response()->json([
                'message' => 'Candidate profile not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $profile->load('user');

        return new CandidateProfileResource($profile);
    }

    public function updatePersonalInformation(
        UpdateCandidatePersonalInformationRequest $request
    ) {
        $validated = $request->validated();
        $user = $request->user();

        $profile = DB::transaction(function () use ($validated, $user) {
            if (array_key_exists('name', $validated)) {
                $user->update([
                    'name' => $validated['name'],
                ]);
            }

            $profileData = Arr::except($validated, ['name']);

            $profile = $user->candidateProfile()->firstOrCreate();

            if (! empty($profileData)) {
                $profile->update($profileData);
            }

            return $profile->load('user');
        });

        return new CandidateProfileResource($profile);
    }
}
