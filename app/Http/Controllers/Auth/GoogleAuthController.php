<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->configured()) {
            return $this->failure('Google sign-in is unavailable. Please try another sign-in method.');
        }

        $state = Str::random(64);
        $request->session()->put('google_oauth', [
            'state' => $state,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->pull('google_oauth');
        $state = $request->query('state');

        if (! is_array($pending) || ! is_string($state) || ! is_string($pending['state'] ?? null)
            || ! hash_equals($pending['state'], $state) || ($pending['expires_at'] ?? 0) <= now()->timestamp) {
            return $this->failure('Your Google sign-in session expired or was invalid. Please try again.');
        }

        if ($request->has('error')) {
            return $this->failure('Google sign-in was cancelled or declined. Please try again.');
        }

        $code = $request->query('code');
        if (! is_string($code) || blank($code) || strlen($code) > 4096 || ! $this->configured()) {
            return $this->failure('Google sign-in could not be completed. Please try again.');
        }

        try {
            $tokenResponse = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => config('services.google.client_id'),
                    'client_secret' => config('services.google.client_secret'),
                    'code' => $code,
                    'redirect_uri' => config('services.google.redirect'),
                    'grant_type' => 'authorization_code',
                ]);
            $accessToken = $tokenResponse->json('access_token');

            if (! $tokenResponse->successful() || ! is_string($accessToken) || blank($accessToken)) {
                throw new RuntimeException('Token exchange failed.');
            }

            $userResponse = Http::withToken($accessToken)->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->get('https://openidconnect.googleapis.com/v1/userinfo');
            $profile = $userResponse->json();

            if (! $userResponse->successful() || ! is_array($profile)) {
                throw new RuntimeException('User info request failed.');
            }

            $validator = Validator::make($profile, [
                'sub' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'name' => ['nullable', 'string', 'max:255'],
                'picture' => ['nullable', 'string', 'url:https', 'max:2048'],
            ]);
            if ($validator->fails() || ($profile['email_verified'] ?? false) !== true) {
                return $this->failure('Google must provide a verified email address to sign in.');
            }

            $profile = $validator->validated();
            $user = DB::transaction(function () use ($profile): User {
                $user = User::where('google_id', $profile['sub'])->lockForUpdate()->first();

                if (! $user) {
                    $user = User::whereRaw('LOWER(email) = ?', [Str::lower($profile['email'])])->lockForUpdate()->first();
                    if ($user?->google_id && $user->google_id !== $profile['sub']) {
                        throw new RuntimeException('Google identity conflict.');
                    }
                }

                if (! $user) {
                    $user = new User;
                    $user->name = $profile['name'] ?? $profile['email'];
                    $user->email = Str::lower($profile['email']);
                    $user->password = null;
                    $user->phone = null;
                }

                $user->google_id = $profile['sub'];
                if (! empty($profile['picture'])) {
                    $user->avatar = $profile['picture'];
                }
                if (Str::lower($user->email) === Str::lower($profile['email'])) {
                    $user->email_verified_at ??= now();
                }
                $user->save();

                return $user;
            });

            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return redirect()->route($user->phone && $user->phone_verified_at ? 'dashboard' : 'phone.onboarding')
                ->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        } catch (Throwable $exception) {
            Log::warning('Google OAuth failed', ['exception' => $exception::class]);

            return $this->failure('Google sign-in could not be completed. Please try again or use your existing login.');
        }
    }

    private function configured(): bool
    {
        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            if (! is_string(config('services.google.'.$key)) || blank(config('services.google.'.$key))) {
                return false;
            }
        }

        return true;
    }

    private function failure(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['google' => $message])
            ->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
