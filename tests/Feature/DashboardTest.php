<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users are dispatched to their role-specific dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Route "dashboard" adalah dispatcher yang redirect ke dashboard per role.
    // User tanpa role di-fallback ke dashboard executive.
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('dashboard.executive'));
});
