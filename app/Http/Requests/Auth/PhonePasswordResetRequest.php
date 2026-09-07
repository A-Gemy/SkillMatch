<?php

namespace App\Http\Requests\Auth;

use App\Services\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PhonePasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['phone' => ['required', 'string', 'max:32']];
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
