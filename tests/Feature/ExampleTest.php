<?php

use App\Models\User;

it('redirects guests from the root to login', function () {
    $this->get('/')->assertRedirect('/login');
});

it('redirects authenticated users from the root to the calendar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect('/calendar');
});
