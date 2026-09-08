<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PhoneOnboardingRequest;
use App\Models\PhoneOtp;
use App\Models\User;
use App\Services\WhatsAppDeliveryException;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class PhoneOnboardingController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()->phone && $request->user()->phone_verified_at) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/phone-onboarding', ['phone' => $request->user()->phone]);
    }

    public function store(PhoneOnboardingRequest $request, WhatsAppService $whatsAppService): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $whatsAppService): JsonResponse {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                abort_if($user->phone_verified_at, 403);
                $previous = PhoneOtp::where('user_id', $user->id)->where('purpose', PhoneOtp::PHONE_VERIFICATION)->latest('id')->first();

                if ($previous?->last_sent_at?->greaterThan(now()->subMinute())) {
                    return response()->json(['message' => 'Please wait one minute before requesting another code.'], 429);
                }

                if ($user->phone !== $request->validated('phone')) {
                    PhoneOtp::where('user_id', $user->id)->where('purpose', PhoneOtp::PASSWORD_RESET)->delete();
                }
                $user->phone = $request->validated('phone');
                $user->save();
                PhoneOtp::where('user_id', $user->id)->where('purpose', PhoneOtp::PHONE_VERIFICATION)->delete();

                $otp = random_int(100000, 999999);
                PhoneOtp::create([
                    'user_id' => $user->id,
                    'purpose' => PhoneOtp::PHONE_VERIFICATION,
                    'code_hash' => Hash::make((string) $otp),
                    'expires_at' => now()->addMinutes(5),
                    'attempts' => 0,
                    'last_sent_at' => now(),
                ]);
                $whatsAppService->sendOtp($user->phone, $otp);

                return response()->json(['message' => 'Verification code sent.', 'phone' => $user->phone]);
            });
        } catch (WhatsAppDeliveryException) {
            return response()->json(['message' => 'Unable to send the WhatsApp code. Please try again shortly.'], 503);
        }
    }
}
