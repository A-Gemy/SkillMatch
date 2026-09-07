<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGooglePhoneVerified
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->google_id && (! $user->phone || ! $user->phone_verified_at)
            && ! $request->routeIs('phone.*', 'google.*', 'logout')) {
            return redirect()->route('phone.onboarding');
        }

        return $next($request);
    }
}
