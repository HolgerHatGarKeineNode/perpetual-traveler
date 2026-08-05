<?php

use App\Models\User;
use Livewire\Volt\Volt;

// saveDays reloads events scoped to currentYear; a newly added day in the
// viewed year must come back in the events list so the calendar re-renders it.
test('saveDays returns the newly added day for the current year', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('calendar')
        ->set('currentYear', 2026)
        ->call('saveDays', ['2026-07-10'], 'DE')
        ->assertSet('events', function ($events) {
            return collect($events)->pluck('start')->contains('2026-07-10');
        });
});
