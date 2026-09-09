<?php

use App\Models\User;

test('authenticated user can get candidate profile', function () {
    $user = User::factory()->create();

    $user->candidateProfile()->create([
        'bio' => 'Backend developer',
        'current_job_title' => 'Junior Backend Developer',
        'years_of_experience' => 1,
        'city' => 'Mansoura',
        'country' => 'Egypt',
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/candidate/profile');

    $response
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.name', $user->name)
        ->assertJsonPath('data.user.email', $user->email)
        ->assertJsonPath('data.user.phone', $user->phone)
        ->assertJsonPath('data.bio', 'Backend developer')
        ->assertJsonPath('data.current_job_title', 'Junior Backend Developer')
        ->assertJsonPath('data.location.city', 'Mansoura')
        ->assertJsonPath('data.location.country', 'Egypt');
});

test('authenticated user gets not found when candidate profile does not exist', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->getJson('/api/candidate/profile');

    $response
        ->assertNotFound()
        ->assertJson([
            'message' => 'Candidate profile not found.',
        ]);
});

test('unauthenticated user cannot get candidate profile', function () {
    $response = $this->getJson('/api/candidate/profile');

    $response->assertUnauthorized();
});
