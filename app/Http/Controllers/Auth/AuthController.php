<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PhoneOtp;
use App\Models\User;
use App\Services\PhoneNumber;
use App\Services\WhatsAppDeliveryException;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AuthController extends Controller
{
    public function register(
        Request $request,
        WhatsAppService $whatsAppService
    ): JsonResponse {
        Log::withContext(['otp_request_id' => (string) Str::uuid()]);
        Log::info('OTP registration entered', ['action' => 'AuthController::register', 'config_cached' => app()->configurationIsCached()]);
        $this->normalizePhone($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:32', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $user = DB::transaction(function () use ($validated, $whatsAppService): User {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'phone' => $validated['phone'],
                    'password' => $validated['password'],
                ]);

                $otp = random_int(100000, 999999);

                $phoneOtp = PhoneOtp::create([
                    'user_id' => $user->id,
                    'purpose' => PhoneOtp::PHONE_VERIFICATION,
                    'code_hash' => Hash::make((string) $otp),
                    'expires_at' => now()->addMinutes(5),
                    'attempts' => 0,
                    'last_sent_at' => now(),
                ]);

                Log::info('OTP created', ['user_id' => $user->id, 'phone_otp_id' => $phoneOtp->id, 'expires_at' => $phoneOtp->expires_at->toIso8601String()]);
                Log::info('OTP delivery starting', ['user_id' => $user->id]);
                $whatsAppService->sendOtp($user->phone, $otp);

                return $user;
            });
        } catch (WhatsAppDeliveryException $exception) {
            Log::error('OTP registration delivery failed', ['exception' => $exception::class]);

            return response()->json([
                'message' => 'Unable to send the WhatsApp verification code. Please try registering again shortly.',
            ], 503);
        }

        return response()->json([
            'message' => 'User registered successfully. Phone verification required.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'phone_verified_at' => $user->phone_verified_at,
            ],
        ], 201);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $originalPhone = $request->input('phone');
        $this->normalizePhone($request);
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'otp' => ['required', 'digits:6'],
        ]);

        $user = User::where('phone', $validated['phone'])->first()
            ?? User::where('phone', $originalPhone)->first();

        if (! $user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        if ($user->phone_verified_at) {
            return response()->json([
                'message' => 'Phone already verified.',
            ], 200);
        }

        $phoneOtp = PhoneOtp::where('user_id', $user->id)
            ->where('purpose', PhoneOtp::PHONE_VERIFICATION)
            ->latest()
            ->first();

        if (! $phoneOtp) {
            return response()->json([
                'message' => 'OTP not found.',
            ], 404);
        }

        if (now()->greaterThan($phoneOtp->expires_at)) {
            return response()->json([
                'message' => 'OTP has expired.',
            ], 422);
        }

        if ($phoneOtp->attempts >= 5) {
            return response()->json([
                'message' => 'Too many invalid attempts.',
            ], 429);
        }

        if (! Hash::check($validated['otp'], $phoneOtp->code_hash)) {
            $phoneOtp->increment('attempts');

            return response()->json([
                'message' => 'Invalid OTP.',
            ], 422);
        }

        $user->phone_verified_at = now();
        $user->save();

        $phoneOtp->delete();

        return response()->json([
            'message' => 'Phone verified successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'phone_verified_at' => $user->phone_verified_at,
            ],
        ]);
    }

    private function normalizePhone(Request $request): void
    {
        if (! is_string($request->input('phone'))) {
            return;
        }

        try {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone'))]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone' => $exception->getMessage()]);
        }
    }
}
