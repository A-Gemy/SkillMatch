<?php

use App\Models\PhoneOtp;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('services.whatsapp', [
        'url' => 'https://whatsbots.net/api/qr/rest/send_message',
        'token' => 'test-only-token',
        'from' => '201012345678',
    ]);
    Http::preventStrayRequests();
});

function otpRegistrationPayload(string $phone = '+20 10 9876 5432'): array
{
    return [
        'name' => 'OTP Test User',
        'email' => 'otp@example.com',
        'phone' => $phone,
        'password' => 'password',
        'password_confirmation' => 'password',
    ];
}

test('API registration sends a hashed expiring OTP to a normalized mobile number', function (string $phone, string $to) {
    $this->app->instance('env', 'production');
    $this->freezeSecond();
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response(['success' => true])]);

    $response = $this->postJson('/api/auth/register', otpRegistrationPayload($phone));

    $response->assertCreated()->assertJsonMissingPath('debug_otp')
        ->assertJsonPath('user.phone', '+'.$to)->assertJsonPath('user.phone_verified_at', null);
    $this->assertGuest();
    $this->assertDatabaseHas('users', ['email' => 'otp@example.com', 'phone' => '+'.$to]);
    $phoneOtp = PhoneOtp::sole();
    expect($phoneOtp->expires_at->equalTo(now()->addMinutes(5)))->toBeTrue();
    expect($phoneOtp->attempts)->toBe(0);
    Http::assertSent(function (Request $request) use ($to, $phoneOtp) {
        $otp = substr($request['text'], -6);

        return $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/json')
            && $request['messageType'] === 'text'
            && $request['requestType'] === 'POST'
            && $request['token'] === 'test-only-token'
            && $request['from'] === '201012345678'
            && $request['to'] === $to
            && preg_match('/^[1-9][0-9]{5}$/', $otp)
            && Hash::check($otp, $phoneOtp->code_hash);
    });
    Http::assertSentCount(1);
})->with([
    ['+20 10 9876 5432', '201098765432'],
    ['00201098765432', '201098765432'],
    ['01098765432', '201098765432'],
    ['201098765432', '201098765432'],
    ['+966 (50) 123-4567', '966501234567'],
    ['00966501234567', '966501234567'],
    ['0501234567', '966501234567'],
    ['966501234567', '966501234567'],
]);

test('the delivered OTP verifies the phone and the user can then log in', function () {
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response(['success' => true])]);
    $this->postJson('/api/auth/register', otpRegistrationPayload())->assertCreated();
    $otp = substr(Http::recorded()->sole()[0]['text'], -6);

    $this->postJson('/api/auth/verify-otp', [
        'phone' => '01098765432',
        'otp' => $otp,
    ])->assertOk()->assertJsonPath('message', 'Phone verified successfully.');

    expect(User::sole()->phone_verified_at)->not->toBeNull();
    $this->assertDatabaseCount('phone_otps', 0);
    $this->assertGuest();
    $this->post(route('login.store'), [
        'email' => 'otp@example.com',
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticated();
    Http::assertSentCount(1);
});

test('delivery failure returns 503 and rolls back registration so it can be retried', function (mixed $body, int $status) {
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response($body, $status)]);

    $this->postJson('/api/auth/register', otpRegistrationPayload())
        ->assertServiceUnavailable()->assertExactJson([
            'message' => 'Unable to send the WhatsApp verification code. Please try registering again shortly.',
        ]);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('phone_otps', 0);
    Http::assertSentCount(1);
})->with([
    'application failure at HTTP 200' => [['success' => false, 'message' => 'Device disconnected'], 200],
    'HTTP failure despite success body' => [['success' => true], 500],
    'malformed body' => ['<html>Gateway error</html>', 200],
    'missing success flag' => [['message' => 'Unknown response'], 200],
    'redirect' => ['', 302],
]);

test('connection failure returns 503 without leaving an unusable account', function () {
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::failedConnection()]);

    $this->postJson('/api/auth/register', otpRegistrationPayload())->assertServiceUnavailable();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('phone_otps', 0);
    Http::assertSentCount(1);
});

test('missing WhatsBots configuration returns 503 before any external request', function (string $setting) {
    config()->set('services.whatsapp.'.$setting, '');
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response(['success' => true])]);

    $this->postJson('/api/auth/register', otpRegistrationPayload())->assertServiceUnavailable();

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('phone_otps', 0);
    Http::assertNothingSent();
})->with(['url', 'token', 'from']);

test('invalid mobile numbers return 422 without creating a user or sending a message', function (mixed $phone) {
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response(['success' => true])]);

    $this->postJson('/api/auth/register', [...otpRegistrationPayload(), 'phone' => $phone])
        ->assertUnprocessable()->assertJsonValidationErrors('phone');

    $this->assertDatabaseCount('users', 0);
    Http::assertNothingSent();
})->with(['+2001098765432', '+9660501234567', '+20abc1098765432', '123', ['not a string'], null]);

test('equivalent formatting cannot register an existing canonical phone again', function () {
    User::factory()->create(['phone' => '+201098765432']);
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response(['success' => true])]);

    $this->postJson('/api/auth/register', otpRegistrationPayload('01098765432'))
        ->assertUnprocessable()->assertJsonValidationErrors('phone');

    $this->assertDatabaseCount('users', 1);
    Http::assertNothingSent();
});

test('verification preserves expiration and invalid attempt limits', function (bool $expired, int $attempts, string $code, int $status, int $expectedAttempts) {
    $this->freezeTime();
    $user = User::factory()->create(['phone' => '+201098765432']);
    $phoneOtp = PhoneOtp::create([
        'user_id' => $user->id,
        'code_hash' => Hash::make('123456'),
        'expires_at' => $expired ? now()->subSecond() : now()->addMinutes(5),
        'attempts' => $attempts,
    ]);

    $this->postJson('/api/auth/verify-otp', [
        'phone' => '01098765432',
        'otp' => $code,
    ])->assertStatus($status);

    expect($user->fresh()->phone_verified_at)->toBeNull();
    expect($phoneOtp->fresh()->attempts)->toBe($expectedAttempts);
})->with([
    'expired' => [true, 0, '123456', 422, 0],
    'attempt limit' => [false, 5, '123456', 429, 5],
    'wrong code' => [false, 0, '654321', 422, 1],
]);

test('delivery logs retain provider diagnostics without tokens passwords phones or OTPs', function () {
    $entries = [];
    Log::listen(function (MessageLogged $event) use (&$entries) {
        $entries[] = [$event->message, $event->context];
    });
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response([
        'success' => true,
        'message' => 'Accepted 123456 for 201098765432 using test-only-token',
        'request' => ['token' => 'test-only-token', 'text' => '123456', 'password' => 'hidden-password'],
        'value' => 123456,
    ])]);

    app(WhatsAppService::class)->sendOtp('+201098765432', 123456);

    $logs = json_encode($entries);
    expect($logs)->toContain('WhatsBots sendOtp entered', 'WhatsBots request', 'WhatsBots response', 'Accepted')
        ->not->toContain('test-only-token', 'hidden-password', '123456', '201098765432', '201012345678');
    Http::assertSentCount(1);
});

test('a previously issued OTP can still verify an existing formatted phone', function () {
    $user = User::factory()->create(['phone' => '+20 10 9876 5432']);
    PhoneOtp::create([
        'user_id' => $user->id,
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(5),
        'attempts' => 0,
    ]);

    $this->postJson('/api/auth/verify-otp', ['phone' => '+20 10 9876 5432', 'otp' => '123456'])
        ->assertOk();

    expect($user->fresh()->phone_verified_at)->not->toBeNull();
    $this->assertDatabaseCount('phone_otps', 0);
});
