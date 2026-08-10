<?php

use App\Models\Event;
use App\Models\User;
use Livewire\Volt\Volt;

// P3 puts a "before -> after" readout on the country chips and a docket of
// what the selected days currently hold. Both figures are computed
// SERVER-side (the docblock above the modal in calendar.blade.php says so
// explicitly) even though the arrow itself is drawn by Alpine — so the
// number underneath the arrow is fully pinnable from a Livewire test. These
// tests pin the write-time semantics that number depends on, not the DOM
// arrow itself.

// saveDays() writes via Event::firstOrNew(['user_id', 'day']) and then
// overwrites ->country unconditionally (calendar.blade.php, $saveDays). A
// day that already belongs to the target country is therefore a no-op row,
// not a new row — the country's day count can only grow by the days it did
// NOT already own.

// Point 1 + Point 2 share one fixture: 12 days stamped in one saveDays()
// call, of which 3 already belong to "pt" and 2 already belong to "de"; the
// remaining 7 have no event yet. Target country is "pt".
//
//   pt before: 3   -> after: 3 + 9 = 12   (+9, not +12 — point 1)
//   de before: 2   -> after: 0            (-2 — point 2, the country loses them)
//   total events in the year: 5 -> 12     (+7, exactly the days that had no
//                                           country before — point 2)
test('saveDays overwrites only the days a country does not already hold', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // 3 days already PT.
    foreach (['2026-02-01', '2026-02-02', '2026-02-03'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'pt']);
    }
    // 2 days already DE.
    foreach (['2026-02-04', '2026-02-05'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'de']);
    }
    // 7 days with no event yet.
    $freeDays = ['2026-02-06', '2026-02-07', '2026-02-08', '2026-02-09', '2026-02-10', '2026-02-11', '2026-02-12'];

    $allTwelve = [
        '2026-02-01', '2026-02-02', '2026-02-03', // already pt
        '2026-02-04', '2026-02-05',                 // already de
        ...$freeDays,                                // free
    ];
    expect($allTwelve)->toHaveCount(12);

    $countPt = fn () => Event::query()->where('user_id', $user->id)->where('country', 'pt')->count();
    $countDe = fn () => Event::query()->where('user_id', $user->id)->where('country', 'de')->count();
    $countAll = fn () => Event::query()->where('user_id', $user->id)->count();

    expect($countPt())->toBe(3)
        ->and($countDe())->toBe(2)
        ->and($countAll())->toBe(5);

    Volt::test('calendar')
        ->set('currentYear', 2026)
        ->call('saveDays', $allTwelve, 'PT');

    // Point 1: pt grew by 9 (the 9 days it did not already hold), not by 12.
    expect($countPt())->toBe(12);

    // Point 2: de lost exactly the 2 days that moved to pt, and no other row
    // was created or destroyed for de.
    expect($countDe())->toBe(0);

    // Point 2 (second half): the total row count for the user grew by
    // exactly 7 — the number of previously country-less days. If the
    // overwrite rule were broken (e.g. new rows created instead of reused
    // ones), the total would be 12 + 5 = 17, not 12.
    expect($countAll())->toBe(12);
});

// Same overwrite rule, read back through the component's own `events` state
// (the array the modal and the stats tab both render from) instead of
// through raw Eloquent counts — so a regression that corrupts the mapping
// between DB rows and `$this->events` (not just the DB write itself) is
// caught too.
test('saveDays leaves the year total exactly one country deeper per previously country-less day', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['2026-05-01', '2026-05-02', '2026-05-03'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'pt']);
    }
    foreach (['2026-05-04', '2026-05-05'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'de']);
    }
    $freeDays = ['2026-05-06', '2026-05-07', '2026-05-08', '2026-05-09', '2026-05-10', '2026-05-11', '2026-05-12'];
    $allTwelve = ['2026-05-01', '2026-05-02', '2026-05-03', '2026-05-04', '2026-05-05', ...$freeDays];

    $component = Volt::test('calendar')->set('currentYear', 2026);
    expect(collect($component->get('events'))->where('country', 'PT')->count())->toBe(3)
        ->and(collect($component->get('events'))->where('country', 'DE')->count())->toBe(2)
        ->and(collect($component->get('events')))->toHaveCount(5);

    $component->call('saveDays', $allTwelve, 'PT');

    $events = collect($component->get('events'));
    expect($events->where('country', 'PT')->count())->toBe(12)
        ->and($events->where('country', 'DE')->count())->toBe(0)
        ->and($events)->toHaveCount(12);
});

// Point 3: the "before" figure the modal docket prints for a country
// ($ptrYearDays, keyed by ISO code) and the "days total" figure the stats
// tab prints for the same country (keyed by title) must be the SAME number,
// because both are collect($this->events)->groupBy(...)->map->count() over
// the identical array, only relabelled. This is deliberately not a
// self-referential "docket equals stats" comparison alone — each figure is
// also pinned against the fixture count, so a bug that moves BOTH numbers by
// the same wrong amount (e.g. both counting all years instead of the
// current one) would still be caught.
test('the modal docket year figure and the stats tab day total agree for the same country', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'de']);
    }
    foreach (['2026-03-01', '2026-03-02', '2026-03-03'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'pt']);
    }

    $html = Volt::test('calendar')->set('currentYear', 2026)->html();

    // Docket figure: "(<n> in 2026)" inside the <span wire:key="dock_XX">
    // block for that country's ISO code.
    preg_match('/wire:key="dock_DE"[\s\S]*?\((\d+) in 2026\)/', $html, $deDocket);
    preg_match('/wire:key="dock_PT"[\s\S]*?\((\d+) in 2026\)/', $html, $ptDocket);

    // Stats tab figure: the "days total" number under a stamp whose <dd>
    // text is the country's title (emoji + name).
    preg_match(
        '/leading-snug">[^<]*Germany<\/dd>[\s\S]*?leading-none text-navy-900 dark:text-navy-50">(\d+)</',
        $html,
        $deStats
    );
    preg_match(
        '/leading-snug">[^<]*Portugal<\/dd>[\s\S]*?leading-none text-navy-900 dark:text-navy-50">(\d+)</',
        $html,
        $ptStats
    );

    expect($deDocket)->toHaveCount(2)->and($ptDocket)->toHaveCount(2)
        ->and($deStats)->toHaveCount(2)->and($ptStats)->toHaveCount(2);

    // Pinned against the fixture, not just against each other.
    expect((int) $deDocket[1])->toBe(5)
        ->and((int) $ptDocket[1])->toBe(3);

    // And the two independently-styled renderings agree.
    expect((int) $deDocket[1])->toBe((int) $deStats[1])
        ->and((int) $ptDocket[1])->toBe((int) $ptStats[1]);
});

// Point 4a: saveDays() calls refreshRecentCountries(), so stamping a country
// that has never appeared before makes it show up as a chip in the SAME
// request — no reload needed.
test('saveDays adds a brand-new country to the recently-used chips', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Event::factory()->create(['user_id' => $user->id, 'day' => '2026-04-01', 'country' => 'de']);

    $component = Volt::test('calendar')->set('currentYear', 2026);
    expect($component->get('selectedCountries'))->toBe(['DE']);

    $component->call('saveDays', ['2026-04-10'], 'FR');

    expect($component->get('selectedCountries'))->toContain('FR');
});

// Point 4b, the actual behaviour change of this phase: before P3 the chip
// list was built once in mount() and never touched again, so switching
// currentYear left last year's countries pinned as chips forever. Now every
// updated('currentYear') call re-derives selectedCountries from the events
// of the NEW year, so a country that only exists in the old year must drop
// out, and a country that only exists in the new year must appear.
test('switching currentYear replaces the recently-used chips with the new year\'s countries', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Event::factory()->create(['user_id' => $user->id, 'day' => '2025-06-01', 'country' => 'de']);
    Event::factory()->create(['user_id' => $user->id, 'day' => '2026-06-01', 'country' => 'fr']);

    $component = Volt::test('calendar')->set('currentYear', 2025);
    expect($component->get('selectedCountries'))->toBe(['DE']);

    $component->set('currentYear', 2026);

    expect($component->get('selectedCountries'))
        ->toBe(['FR'])
        ->not->toContain('DE');
});

// Point 4c, the fourth call site: deleteDays() must also refresh the chip
// list. Deleting the only day a country has must drop that country's chip
// in the same request.
test('deleteDays drops a country from the recently-used chips once its last day is gone', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Event::factory()->create(['user_id' => $user->id, 'day' => '2026-07-01', 'country' => 'de']);
    Event::factory()->create(['user_id' => $user->id, 'day' => '2026-07-02', 'country' => 'pt']);

    $component = Volt::test('calendar')->set('currentYear', 2026);
    expect($component->get('selectedCountries'))->toEqualCanonicalizing(['DE', 'PT']);

    $component->call('deleteDays', ['2026-07-01']);

    expect($component->get('selectedCountries'))
        ->toBe(['PT'])
        ->not->toContain('DE');
});

// Point 5: a range that crosses the year boundary is written in full to the
// database (saveDays takes whatever day list it is handed, with no year
// filter on the write), but the component's `events` state — the array
// every year-scoped figure in this phase is built from — only ever holds
// the days of the currently displayed year. That asymmetry is exactly what
// the "N days fall outside YEAR" line in the docket depends on: this proves
// the days really are outside the array, not merely outside the visible
// range.
test('saveDays across a year boundary writes every day but events stays scoped to the displayed year', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $spanningDays = ['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02'];

    $component = Volt::test('calendar')
        ->set('currentYear', 2026)
        ->call('saveDays', $spanningDays, 'PT');

    // All four days were actually written to the database — the write path
    // has no year guard.
    expect(Event::query()->where('user_id', $user->id)->whereIn('day', $spanningDays)->count())->toBe(4);

    // But the component's year-scoped events array only carries the two
    // days that fall inside 2026 — the 2027 days are invisible to it, which
    // is precisely what a "2 days fall outside 2026" line would need to be
    // true.
    $eventDays = collect($component->get('events'))->pluck('start')->all();
    expect($eventDays)->toEqualCanonicalizing(['2026-12-30', '2026-12-31'])
        ->and($eventDays)->not->toContain('2027-01-01')
        ->and($eventDays)->not->toContain('2027-01-02');
});
