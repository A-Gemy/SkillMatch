<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CandidateProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
            ],

            'bio' => $this->bio,
            'current_job_title' => $this->current_job_title,
            'years_of_experience' => $this->years_of_experience,

            'location' => [
                'city' => $this->city,
                'country' => $this->country,
            ],

            'profile_photo_path' => $this->profile_photo_path,

            'links' => [
                'linkedin' => $this->linkedin_url,
                'github' => $this->github_url,
                'portfolio' => $this->portfolio_url,
            ],

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
