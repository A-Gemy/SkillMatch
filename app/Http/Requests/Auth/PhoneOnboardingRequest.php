<?php

namespace App\Http\Requests\Auth;

use App\Services\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PhoneOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && ! $this->user()->phone_verified_at;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'max:32', Rule::unique('users', 'phone')->ignore($this->user()->id)]];
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');
        if (! is_string($phone) || strlen($phone) > 64) {
            return;
        }

        try {
            $this->merge(['phone' => PhoneNumber::normalize($phone)]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }
    }
}
