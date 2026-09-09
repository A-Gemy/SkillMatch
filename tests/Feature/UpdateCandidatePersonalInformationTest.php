<?php

use App\Models\User;

test('authenticated user can update personal information', function () {
    $user = User::factory()->create([
        'name' => 'Old Name',
    ]);

    $user->candidateProfile()->create([
        'bio' => 'Old bio',
        'current_job_title' => 'Backend Developer',
        'years_of_experience' => 1,
        'city' => 'Mansoura',
        'country' => 'Egypt',
    ]);

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/candidate/profile/personal-information', [
            'name' => 'Ahmed Gamal',
            'bio' => 'Laravel backend developer',
            'current_job_title' => 'Junior Backend Developer',
            'years_of_experience' => 2,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'linkedin_url' => 'https://linkedin.com/in/ahmed',
            'github_url' => 'https://github.com/ahmed',
            'portfolio_url' => 'https://example.com',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Ahmed Gamal')
        ->assertJsonPath('data.bio', 'Laravel backend developer')
        ->assertJsonPath('data.current_job_title', 'Junior Backend Developer')
        ->assertJsonPath('data.years_of_experience', 2)
        ->assertJsonPath('data.location.city', 'Cairo');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Ahmed Gamal',
    ]);

    $this->assertDatabaseHas('candidate_profiles', [
        'user_id' => $user->id,
        'bio' => 'Laravel backend developer',
        'city' => 'Cairo',
    ]);
});

test('partial update does not overwrite fields that were not sent', function () {
    $user = User::factory()->create();

    $user->candidateProfile()->create([
        'bio' => 'Keep this bio',
        'current_job_title' => 'Backend Developer',
        'years_of_experience' => 3,
        'city' => 'Mansoura',
        'country' => 'Egypt',
    ]);

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/candidate/profile/personal-information', [
            'city' => 'Cairo',
        ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.location.city', 'Cairo')
        ->assertJsonPath('data.bio', 'Keep this bio')
        ->assertJsonPath('data.current_job_title', 'Backend Developer')
        ->assertJsonPath('data.years_of_experience', 3)
        ->assertJsonPath('data.location.country', 'Egypt');
});

test('candidate profile is created when updating personal information if it does not exist', function () {
    $user = User::factory()->create();

    expect($user->candidateProfile)->toBeNull();

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/candidate/profile/personal-information', [
            'bio' => 'New candidate profile',
            'city' => 'Mansoura',
            'country' => 'Egypt',
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.bio', 'New candidate profile')
        ->assertJsonPath('data.location.city', 'Mansoura');

    $this->assertDatabaseHas('candidate_profiles', [
        'user_id' => $user->id,
        'bio' => 'New candidate profile',
    ]);
});

test('personal information validation rejects invalid data', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/candidate/profile/personal-information', [
            'years_of_experience' => 101,
            'linkedin_url' => 'not-a-url',
        ]);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'years_of_experience',
            'linkedin_url',
        ]);
});

test('unauthenticated user cannot update personal information', function () {
    $response = $this->patchJson(
        '/api/candidate/profile/personal-information',
        [
            'city' => 'Cairo',
        ]
    );

    $response->assertUnauthorized();
});

test('personal information update requires at least one field', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patchJson('/api/candidate/profile/personal-information', []);

    $response
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'personal_information',
        ]);

    $this->assertDatabaseMissing('candidate_profiles', [
        'user_id' => $user->id,
    ]);
});
