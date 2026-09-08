<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PhonePasswordResetRequest;
use App\Models\PhoneOtp;
use App\Models\User;
use App\Services\WhatsAppDeliveryException;
use App\Services\WhatsAppService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PhonePasswordResetController extends Controller
{
    use PasswordValidationRules;

    public function store(PhonePasswordResetRequest $request, WhatsAppService $whatsAppService): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $whatsAppService): JsonResponse {
                $user = User::where('phone', $request->validated('phone'))->lockForUpdate()->first();

                if (! $user) {
                    return $this->invalid('phone', 'Unable to send a reset code. Check the phone number and try again.');
                }

                $previousOtp = $this->resetOtp($user);

                if ($previousOtp?->last_sent_at?->greaterThan(now()->subMinute())) {
                    return response()->json(['message' => 'Please wait one minute before requesting another code.'], 429)
                        ->header('Retry-After', '60');
                }

                PhoneOtp::where('user_id', $user->id)->where('purpose', PhoneOtp::PASSWORD_RESET)->delete();
                $otp = random_int(100000, 999999);
                PhoneOtp::create([
                    'user_id' => $user->id,
                    'purpose' => PhoneOtp::PASSWORD_RESET,
                    'code_hash' => Hash::make((string) $otp),
                    'expires_at' => now()->addMinutes(5),
                    'attempts' => 0,
                    'last_sent_at' => now(),
                ]);

                $whatsAppService->sendOtp($user->phone, $otp);

                return response()->json(['message' => 'A password reset code has been sent to your WhatsApp.']);
            });
        } catch (WhatsAppDeliveryException) {
            return response()->json(['message' => 'Unable to send the WhatsApp code. Please try again shortly.'], 503);
        }
    }

    public function verifyOtp(PhonePasswordResetRequest $request): JsonResponse
    {
        $validated = $request->validate(['otp' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);

        return DB::transaction(function () use ($request, $validated): JsonResponse {
            $user = User::where('phone', $request->validated('phone'))->lockForUpdate()->first();
            $phoneOtp = $user ? $this->resetOtp($user) : null;

            if (! $phoneOtp || $phoneOtp->reset_token_hash || now()->greaterThanOrEqualTo($phoneOtp->expires_at)) {
                return $this->invalid('otp', 'The code is invalid or expired. Request a new code.');
            }

            if ($phoneOtp->attempts >= 5) {
                return response()->json(['message' => 'Too many invalid attempts. Request a new code.'], 429);
            }

            if (! Hash::check($validated['otp'], $phoneOtp->code_hash)) {
                $phoneOtp->increment('attempts');

                return $this->invalid('otp', 'The code is invalid or expired. Request a new code.');
            }

            $resetToken = Str::random(64);
            $phoneOtp->update([
                'reset_token_hash' => Hash::make($resetToken),
                'reset_token_expires_at' => now()->addMinutes(10),
            ]);

            return response()->json([
                'message' => 'Code verified. You may now reset your password.',
                'reset_token' => $resetToken,
                'expires_in' => 600,
            ])->header('Cache-Control', 'no-store');
        });
    }

    public function resetPassword(PhonePasswordResetRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'reset_token' => ['required', 'string', 'size:64'],
            'password' => $this->passwordRules(),
        ]);

        return DB::transaction(function () use ($request, $validated): JsonResponse {
            $user = User::where('phone', $request->validated('phone'))->lockForUpdate()->first();
            $phoneOtp = $user ? $this->resetOtp($user) : null;

            if (! $phoneOtp?->reset_token_hash || ! $phoneOtp->reset_token_expires_at
                || now()->greaterThanOrEqualTo($phoneOtp->reset_token_expires_at)
                || ! Hash::check($validated['reset_token'], $phoneOtp->reset_token_hash)) {
                return $this->invalid('reset_token', 'The password reset session is invalid or expired. Request a new code.');
            }

            $user->password = Hash::make($validated['password']);
            $user->setRememberToken(Str::random(60));
            $user->save();

            PhoneOtp::where('user_id', $user->id)->where('purpose', PhoneOtp::PASSWORD_RESET)->delete();
            Password::broker(config('fortify.passwords'))->deleteToken($user);
            event(new PasswordReset($user));

            return response()->json(['message' => 'Password reset successfully. Please log in.']);
        });
    }

    private function resetOtp(User $user): ?PhoneOtp
    {
        return PhoneOtp::where('user_id', $user->id)
            ->where('purpose', PhoneOtp::PASSWORD_RESET)
            ->latest('id')->lockForUpdate()->first();
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
