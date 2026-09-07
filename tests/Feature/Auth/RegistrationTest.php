<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone' => '+20 10 1234 5678',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'phone' => '+20 10 1234 5678',
    ]);
});

test('registration rejects invalid phone numbers', function (mixed $phone, string $message) {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone' => $phone,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors(['phone' => $message]);
    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
})->with([
    'empty' => ['', 'The phone field is required.'],
    'missing' => [null, 'The phone field is required.'],
    'not a string' => [201012345678, 'The phone field must be a string.'],
    'too long' => [str_repeat('1', 256), 'The phone field must not be greater than 255 characters.'],
]);

test('registration rejects a phone number already in use', function () {
    User::factory()->create(['phone' => '+20 10 1234 5678']);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'phone' => '+20 10 1234 5678',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors(['phone' => 'The phone has already been taken.']);
    $this->assertGuest();
    $this->assertDatabaseCount('users', 1);
});
