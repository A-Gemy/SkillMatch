<?php

use App\Models\PhoneOtp;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('services.whatsapp', [
        'url' => 'https://whatsbots.net/api/qr/rest/send_message',
        'token' => 'test-only-token',
        'from' => '201012345678',
    ]);
    Http::preventStrayRequests();
});

test('the existing Fortify forgot-password route serves the recovery page with password rules', function () {
    config()->set('inertia.ssr.enabled', false);

    $this->get(route('password.request'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/forgot-password')
            ->where('passwordRules', fn ($rules) => is_string($rules)));
});

/** @param array<string, mixed> $attributes */
function passwordResetOtp(User $user, array $attributes = []): PhoneOtp
{
    return PhoneOtp::create([
        'user_id' => $user->id,
        'purpose' => PhoneOtp::PASSWORD_RESET,
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(5),
        'attempts' => 0,
        'last_sent_at' => now()->subMinute(),
        ...$attributes,
    ]);
}

/** @return array<string, string> */
function newPhonePassword(string $phone = '+201098765432', string $token = ''): array
{
    return [
        'phone' => $phone,
        'reset_token' => $token ?: str_repeat('r', 64),
        'password' => 'Changed-Password-928!',
        'password_confirmation' => 'Changed-Password-928!',
    ];
}

test('WhatsApp recovery verifies an OTP then consumes a separate token without logging in', function (string $phone, string $canonical) {
    $this->freezeSecond();
    $user = User::factory()->create(['phone' => $canonical]);
    $registrationOtp = passwordResetOtp($user, ['purpose' => PhoneOtp::PHONE_VERIFICATION]);
    $emailToken = Password::broker(config('fortify.passwords'))->createToken($user);
    Event::fake([PasswordReset::class]);
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response(['success' => true])]);

    $this->postJson('/api/auth/forgot-password', ['phone' => $phone])->assertOk()
        ->assertJsonMissingPath('otp')->assertJsonMissingPath('debug_otp')->assertJsonMissingPath('reset_token');

    $otp = PhoneOtp::where('purpose', PhoneOtp::PASSWORD_RESET)->sole();
    $code = substr(Http::recorded()->sole()[0]['text'], -6);
    expect(Hash::check($code, $otp->code_hash))->toBeTrue();
    expect($otp->expires_at->equalTo(now()->addMinutes(5)))->toBeTrue();
    expect($otp->last_sent_at->equalTo(now()))->toBeTrue();
    expect($otp->attempts)->toBe(0);
    Http::assertSent(fn (Request $request) => $request['to'] === ltrim($canonical, '+') && $request['enableLog'] === false);

    $verified = $this->postJson('/api/auth/forgot-password/verify-otp', ['phone' => $phone, 'otp' => $code])
        ->assertOk()->assertJsonPath('expires_in', 600)->assertHeader('Cache-Control', 'no-store, private');
    $token = $verified->json('reset_token');
    expect($token)->toHaveLength(64)->not->toBe($code);
    expect(Hash::check($token, $otp->fresh()->reset_token_hash))->toBeTrue();
    expect($otp->fresh()->toArray())->not->toHaveKeys(['code_hash', 'reset_token_hash']);
    expect($otp->fresh()->reset_token_expires_at->equalTo(now()->addMinutes(10)))->toBeTrue();
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    $this->assertGuest();

    $this->postJson('/api/auth/reset-password', newPhonePassword($phone, $token))->assertOk();

    expect(Hash::check('Changed-Password-928!', $user->fresh()->password))->toBeTrue();
    expect(Hash::check('password', $user->fresh()->password))->toBeFalse();
    expect(Password::broker(config('fortify.passwords'))->tokenExists($user, $emailToken))->toBeFalse();
    expect($user->fresh()->phone_verified_at)->toBeNull();
    $this->assertModelMissing($otp);
    $this->assertModelExists($registrationOtp);
    $this->assertGuest();
    Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event) => $event->user->is($user));

    $this->postJson('/api/auth/reset-password', newPhonePassword($phone, $token))->assertUnprocessable();
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Changed-Password-928!'])
        ->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
})->with([
    'Egypt' => ['01098765432', '+201098765432'],
    'Saudi Arabia' => ['0501234567', '+966501234567'],
]);

test('unknown phones receive a generic 422 validation response without sending a code', function () {
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response(['success' => true])]);

    $this->postJson('/api/auth/forgot-password', ['phone' => '+201098765432'])
        ->assertUnprocessable()->assertJsonValidationErrors('phone')
        ->assertJsonPath('message', 'Unable to send a reset code. Check the phone number and try again.');

    $this->assertDatabaseCount('phone_otps', 0);
    Http::assertNothingSent();
});

test('the migration keeps legacy OTP rows available for registration verification', function () {
    $migration = require database_path('migrations/2026_09_07_151702_add_password_reset_state_to_phone_otps_table.php');
    $migration->down();
    $user = User::factory()->create(['phone' => '+201098765432']);
    $otp = PhoneOtp::create([
        'user_id' => $user->id,
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(5),
        'attempts' => 0,
    ]);

    $migration->up();

    expect($otp->fresh()->purpose)->toBe(PhoneOtp::PHONE_VERIFICATION);
    $this->postJson('/api/auth/verify-otp', ['phone' => $user->phone, 'otp' => '123456'])->assertOk();
    expect($user->fresh()->phone_verified_at)->not->toBeNull();
    $this->assertModelMissing($otp);
});

test('invalid phones receive 422 without sending a code', function (mixed $phone) {
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response(['success' => true])]);

    $this->postJson('/api/auth/forgot-password', ['phone' => $phone])->assertUnprocessable()->assertJsonValidationErrors('phone');

    $this->assertDatabaseCount('phone_otps', 0);
    Http::assertNothingSent();
})->with([null, ['array'], 'not-a-phone', '+9660501234567', str_repeat('1', 65)]);

test('a successful resend replaces reset OTP and token state while preserving registration OTPs', function () {
    $user = User::factory()->create(['phone' => '+201098765432']);
    $registrationOtp = passwordResetOtp($user, ['purpose' => PhoneOtp::PHONE_VERIFICATION]);
    $previous = passwordResetOtp($user, ['reset_token_hash' => Hash::make(str_repeat('r', 64)), 'reset_token_expires_at' => now()->addMinutes(10)]);
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response(['success' => true])]);

    $this->postJson('/api/auth/forgot-password', ['phone' => '01098765432'])->assertOk();

    $this->assertModelMissing($previous);
    $this->assertModelExists($registrationOtp);
    $replacement = PhoneOtp::where('purpose', PhoneOtp::PASSWORD_RESET)->sole();
    expect($replacement->reset_token_hash)->toBeNull();
    expect($replacement->attempts)->toBe(0);
    Http::assertSentCount(1);
    $this->postJson('/api/auth/reset-password', newPhonePassword())->assertUnprocessable();
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('failed delivery returns 503 and restores previous reset state', function (string $failure) {
    $user = User::factory()->create(['phone' => '+201098765432']);
    $previous = passwordResetOtp($user);
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => match ($failure) {
        'connection' => Http::failedConnection(),
        'rejection' => Http::response(['success' => false], 200),
        default => Http::response(['success' => false], 500),
    }]);

    $this->postJson('/api/auth/forgot-password', ['phone' => $user->phone])->assertServiceUnavailable()
        ->assertExactJson(['message' => 'Unable to send the WhatsApp code. Please try again shortly.']);

    $this->assertModelExists($previous);
    $this->assertDatabaseCount('phone_otps', 1);
    Http::assertSentCount(1);
})->with(['connection', 'rejection', 'server']);

test('resending within one minute returns 429 even with different phone formatting', function () {
    $user = User::factory()->create(['phone' => '+201098765432']);
    $previous = passwordResetOtp($user, ['last_sent_at' => now()]);
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response(['success' => true])]);

    $this->postJson('/api/auth/forgot-password', ['phone' => '010 9876 5432'])->assertTooManyRequests()->assertHeader('Retry-After');

    $this->assertModelExists($previous);
    Http::assertNothingSent();
});

test('OTP requests are limited per normalized phone across IP addresses', function () {
    User::factory()->create(['phone' => '+201098765432']);
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response(['success' => true])]);

    foreach (['+20 10 9876 5432', '01098765432', '00201098765432'] as $index => $phone) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($index + 1)])
            ->postJson('/api/auth/forgot-password', ['phone' => $phone])->assertOk();
        $this->travel(61)->seconds();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
        ->postJson('/api/auth/forgot-password', ['phone' => '+201098765432'])->assertTooManyRequests();

    Http::assertSentCount(3);
});

test('OTP request IP throttling limits enumeration across different phones', function () {
    Http::fake(['whatsbots.net/api/qr/rest/send_message' => Http::response(['success' => true])]);
    foreach (range(10, 19) as $suffix) {
        $this->postJson('/api/auth/forgot-password', ['phone' => '+2010987654'.$suffix])->assertUnprocessable();
    }

    $this->postJson('/api/auth/forgot-password', ['phone' => '+201098765420'])->assertTooManyRequests();

    Http::assertNothingSent();
});

test('invalid OTP attempts persist and a correct code cannot bypass five failures', function () {
    $user = User::factory()->create(['phone' => '+201098765432']);
    $otp = passwordResetOtp($user);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/auth/forgot-password/verify-otp', ['phone' => $user->phone, 'otp' => '654321'])
            ->assertUnprocessable()->assertJsonValidationErrors('otp');
        expect($otp->fresh()->attempts)->toBe($attempt);
    }
    $this->postJson('/api/auth/forgot-password/verify-otp', ['phone' => $user->phone, 'otp' => '123456'])->assertTooManyRequests();

    expect($otp->fresh()->reset_token_hash)->toBeNull();
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('missing expired consumed or wrong-purpose OTP cannot authorize a reset', function (string $state) {
    $this->freezeSecond();
    $user = User::factory()->create(['phone' => '+201098765432']);
    if ($state !== 'missing') {
        passwordResetOtp($user, match ($state) {
            'expired' => ['expires_at' => now()],
            'consumed' => ['reset_token_hash' => Hash::make(str_repeat('r', 64)), 'reset_token_expires_at' => now()->addMinutes(10)],
            default => ['purpose' => PhoneOtp::PHONE_VERIFICATION],
        });
    }

    $this->postJson('/api/auth/forgot-password/verify-otp', ['phone' => $user->phone, 'otp' => '123456'])
        ->assertUnprocessable()->assertJsonValidationErrors('otp')->assertJsonMissingPath('reset_token');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
})->with(['missing', 'expired', 'consumed', 'registration']);

test('reset tokens must be verified unexpired and bound to the same phone', function (string $state) {
    $this->freezeSecond();
    $user = User::factory()->create(['phone' => '+201098765432']);
    $otherUser = User::factory()->create(['phone' => '+966501234567']);
    passwordResetOtp($state === 'other phone' ? $otherUser : $user, match ($state) {
        'unverified' => [],
        'expired' => ['reset_token_hash' => Hash::make(str_repeat('r', 64)), 'reset_token_expires_at' => now()],
        'wrong purpose' => ['purpose' => PhoneOtp::PHONE_VERIFICATION, 'reset_token_hash' => Hash::make(str_repeat('r', 64)), 'reset_token_expires_at' => now()->addMinutes(10)],
        default => ['reset_token_hash' => Hash::make(str_repeat('r', 64)), 'reset_token_expires_at' => now()->addMinutes(10)],
    });

    $this->postJson('/api/auth/reset-password', newPhonePassword($user->phone, $state === 'invalid' ? str_repeat('x', 64) : ''))
        ->assertUnprocessable()->assertJsonValidationErrors('reset_token');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    expect(Hash::check('password', $otherUser->fresh()->password))->toBeTrue();
    $this->assertDatabaseCount('phone_otps', 1);
    $this->assertGuest();
})->with(['unverified', 'expired', 'other phone', 'invalid', 'wrong purpose']);

test('password validation rejects weak or unconfirmed passwords without consuming the reset token', function (array $changes) {
    $user = User::factory()->create(['phone' => '+201098765432']);
    $otp = passwordResetOtp($user, ['reset_token_hash' => Hash::make(str_repeat('r', 64)), 'reset_token_expires_at' => now()->addMinutes(10)]);

    $this->postJson('/api/auth/reset-password', [...newPhonePassword(), ...$changes])
        ->assertUnprocessable()->assertJsonValidationErrors('password');

    $this->assertModelExists($otp);
    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
})->with([
    [['password' => 'short', 'password_confirmation' => 'short']],
    [['password_confirmation' => 'different']],
]);

test('password reset OTPs cannot verify registration phones', function () {
    $user = User::factory()->create(['phone' => '+201098765432']);
    $otp = passwordResetOtp($user);

    $this->postJson('/api/auth/verify-otp', ['phone' => $user->phone, 'otp' => '123456'])->assertNotFound();

    expect($user->fresh()->phone_verified_at)->toBeNull();
    $this->assertModelExists($otp);
});

test('registration verification still selects its OTP when a newer reset OTP exists', function () {
    $user = User::factory()->create(['phone' => '+201098765432']);
    $registration = passwordResetOtp($user, ['purpose' => PhoneOtp::PHONE_VERIFICATION]);
    $reset = passwordResetOtp($user, ['code_hash' => Hash::make('654321'), 'created_at' => now()->addSecond()]);

    $this->postJson('/api/auth/verify-otp', ['phone' => $user->phone, 'otp' => '123456'])->assertOk();

    expect($user->fresh()->phone_verified_at)->not->toBeNull();
    $this->assertModelMissing($registration);
    $this->assertModelExists($reset);
});
