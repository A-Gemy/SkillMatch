<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CandidateProfileResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CandidateProfileController extends Controller
{
    public function show(Request $request)
    {
        $profile = $request->user()->candidateProfile;

        if (!$profile) {
            return response()->json([
                'message' => 'Candidate profile not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $profile->load('user');

        return new CandidateProfileResource($profile);
    }
}
