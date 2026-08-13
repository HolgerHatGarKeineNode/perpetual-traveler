<?php

use App\Models\Event;
use App\Models\User;
use Livewire\Volt\Volt;

// The four Event::query() calls that build $this->events (mount, the
// currentYear updated() hook, saveDays, deleteDays) compared the RAW
// client-supplied currentYear as a string against the "day" dateTime column.
// refreshUntrackedDays() and $contiguousStays already clamp currentYear into
// [1970, 9999] before touching a date; these four did not, so an absurd
// currentYear (a public Livewire property — the client sets it) fed a
// differently-sized string into the comparison.
//
// SQLite has no native date type, so "day" is compared as TEXT: a 4-digit
// year like '2026-...' is lexicographically LESS than a 6-digit bound like
// '500000-...' (first character '2' < '5'), so the lower bound never matches
// real data and the query returns nothing. The same digit-width mismatch
// means it ALSO never matches data stored in the year the clamp is supposed
// to normalise absurd input to (9999) — proven below by placing the fixture
// there instead of at some arbitrary "current" year, which would be 0 both
// before and after the fix and prove nothing.
test('an absurd currentYear clamps like its siblings instead of comparing a differently-sized string', function () {
    $user = User::factory()->create();
    test()->actingAs($user);

    // The year both 500000 and 99999 clamp down to (max(1970, min(9999, ...))),
    // the same clamp already used by refreshUntrackedDays() and $contiguousStays.
    Event::factory()->create(['user_id' => $user->id, 'day' => '9999-06-15', 'country' => 'de']);

    foreach ([500000, 99999] as $absurdYear) {
        $component = Volt::test('calendar')->set('currentYear', $absurdYear);

        expect(collect($component->get('events'))->pluck('start'))
            ->toContain('9999-06-15');
    }
});

// Companion negative check on the same values: an absurd currentYear must
// NOT pull in a day from a year it does not clamp to (2026 here) — the fix
// is a clamp, not "match everything".
test('an absurd currentYear does not pull in an unrelated real-world day', function () {
    $user = User::factory()->create();
    test()->actingAs($user);

    Event::factory()->create(['user_id' => $user->id, 'day' => '2026-06-15', 'country' => 'de']);

    foreach ([500000, 99999] as $absurdYear) {
        $component = Volt::test('calendar')->set('currentYear', $absurdYear);

        expect(collect($component->get('events'))->pluck('start'))
            ->not->toContain('2026-06-15');
    }
});
