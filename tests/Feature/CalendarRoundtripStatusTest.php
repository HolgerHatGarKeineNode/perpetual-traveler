<?php

use App\Models\User;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| /calendar answers its roundtrips at all — the 0,2s half of the same class
|--------------------------------------------------------------------------
|
| A 500 on a Livewire roundtrip is invisible from the browser's console: it is
| a resolved promise carrying a status, not an exception, not a rejection and
| not a console message. A page can answer every interaction with a 500 and
| still pass every console assertion there is.
|
| tests/Browser/CalendarConsoleTest.php latches that class where it actually
| happens, by forcing Livewire's endpoint to 500 and requiring the recorder to
| name it. This file is the cheap half: the same class, one component test per
| roundtrip the page can make, no browser and no build. It is the one that will
| tell you WHICH interaction broke, in a second, when the browser test only
| tells you that something did.
|
| The roundtrips are not guessed. They are the three the component exposes:
| $refresh (every Livewire component has it), the start-date field
| (wire:model.live.debounce="start" -> the updated() hook writes pt_start),
| and the year the grid reports back on navigation (currentYear -> the
| updated() hook re-derives events, chips, untracked days and stay bars).
*/

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('answers a bare $refresh with 200', function () {
    Volt::test('calendar')
        ->call('$refresh')
        ->assertOk();
});

it('answers the start-date roundtrip with 200', function () {
    Volt::test('calendar')
        ->set('start', '2026-01-01')
        ->assertOk();
});

it('answers the year-navigation roundtrip with 200', function () {
    Volt::test('calendar')
        ->set('currentYear', 2027)
        ->assertOk();
});

/*
| currentYear appears here a second time, and it is NOT a second case for the
| year-navigation roundtrip above. saveDays() reloads $events scoped to
| currentYear, so a component whose year is still null would answer this write
| about a different year than the one under test — the set() is the fixture,
| exactly as in tests/Feature/CalendarSaveDaysTest.php, and 2026 is the year
| the day below belongs to. The roundtrip being covered here is the WRITE pair.
*/
it('answers the two write roundtrips with 200', function () {
    Volt::test('calendar')
        ->set('currentYear', 2026)
        ->call('saveDays', ['2026-07-10'], 'DE')
        ->assertOk()
        ->call('deleteDays', ['2026-07-10'])
        ->assertOk();
});
