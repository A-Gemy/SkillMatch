<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\PhoneOnboardingController;

Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
});

Route::middleware('auth')->group(function () {
    Route::get('/auth/phone', [PhoneOnboardingController::class, 'show'])->name('phone.onboarding');
    Route::post('/auth/phone', [PhoneOnboardingController::class, 'store'])->middleware('throttle:3,60')->name('phone.send');
});

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
