<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCandidatePersonalInformationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],

            'bio' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'current_job_title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'years_of_experience' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],

            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],

            'linkedin_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'github_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'portfolio_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $allowedFields = [
                    'name',
                    'bio',
                    'current_job_title',
                    'years_of_experience',
                    'city',
                    'country',
                    'linkedin_url',
                    'github_url',
                    'portfolio_url',
                ];

                $hasAnyField = array_intersect_key(
                    $this->all(),
                    array_flip($allowedFields)
                ) !== [];

                if (! $hasAnyField) {
                    $validator->errors()->add(
                        'personal_information',
                        'At least one personal information field is required.'
                    );
                }
            },
        ];
    }
}
