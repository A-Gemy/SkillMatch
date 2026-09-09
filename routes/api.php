<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PhonePasswordResetController;
use App\Http\Controllers\Api\CandidateProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/auth/forgot-password', [PhonePasswordResetController::class, 'store'])
    ->middleware('throttle:password-reset-otp');
Route::post('/auth/forgot-password/verify-otp', [PhonePasswordResetController::class, 'verifyOtp'])
    ->middleware('throttle:password-reset-verification');
Route::post('/auth/reset-password', [PhonePasswordResetController::class, 'resetPassword'])
    ->middleware('throttle:password-reset-verification');
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::get('/candidate/profile', [CandidateProfileController::class, 'show'])
    ->middleware('auth:sanctum');
