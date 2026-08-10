<?php

use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Volt\Volt;

// refreshUntrackedDays() (calendar.blade.php) is the single source for both the
// count printed above the grid and the set of days FullCalendar hatches. It is
// a protect()'ed closure inside a Volt page, not a class method, so it can only
// be reached the way the browser reaches it: through Volt::test('calendar').
//
// A day counts as "untracked" only inside the window
//   [max(pt_start, 1 Jan of the viewed year) .. min(31 Dec, yesterday)]
// and only if no Event exists for it.

function untracked(?string $ptStart, int $year, array $trackedDays = []): array
{
    $user = User::factory()->create(['pt_start' => $ptStart]);
    test()->actingAs($user);

    foreach ($trackedDays as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'pt']);
    }

    $component = Volt::test('calendar')->set('currentYear', $year);

    return [
        'days' => $component->get('untrackedDays'),
        'window' => $component->get('untrackedWindow'),
        'html' => $component->html(),
    ];
}

// Point 1 (top priority per the design-lead): the upper bound is the last day
// that is OVER, i.e. yesterday — never today. Checked in both directions with
// a pinned clock, so an off-by-one in either direction is caught:
//   - bound one day too LATE (uses today instead of yesterday) -> "today" would
//     wrongly appear in the list, the first assertion catches that.
//   - bound one day too EARLY (uses day-before-yesterday) -> "yesterday" would
//     wrongly be missing, the second assertion catches that.
test('the untracked window ends yesterday, never today', function () {
    test()->travelTo(CarbonImmutable::parse('2026-08-10 14:00:00'));

    $r = untracked('2026-08-01', 2026);

    expect($r['days'])->not->toContain('2026-08-10')
        ->and($r['days'])->toContain('2026-08-09')
        ->and(max($r['days']))->toBe('2026-08-09');
});

// Point 2a: a start date in an earlier year clamps the lower bound to 1 Jan of
// the viewed year — the pt_start day itself must not leak into the window.
test('a start date from an earlier year clamps the lower bound to 1 January', function () {
    test()->travelTo(CarbonImmutable::parse('2026-08-10'));

    $r = untracked('2024-01-01', 2026);

    expect($r['window'])->toBe(['start' => '2024-01-01', 'from' => '2026-01-01', 'to' => '2026-08-09'])
        ->and($r['days'][0])->toBe('2026-01-01')
        ->and($r['days'])->not->toContain('2024-01-01');
});

// Point 2b: a start date mid-year is the lower bound itself — days before it
// are "counted apart" and must not appear as gaps.
test('a start date within the viewed year is the lower bound itself', function () {
    test()->travelTo(CarbonImmutable::parse('2026-08-10'));

    $r = untracked('2026-05-15', 2026);

    expect($r['window'])->toBe(['start' => '2026-05-15', 'from' => '2026-05-15', 'to' => '2026-08-09'])
        ->and($r['days'][0])->toBe('2026-05-15')
        ->and($r['days'])->not->toContain('2026-05-14');
});

// Point 3: no start date at all -> nothing can be marked, and the template
// shows the "set a start date" call to action rather than a swatch/count.
test('without a start date nothing is marked and the CTA is shown', function () {
    $r = untracked(null, 2026, ['2026-03-01']);

    expect($r['days'])->toBe([])
        ->and($r['window'])->toBeNull()
        ->and($r['html'])->toContain('Set start date')
        ->and($r['html'])->not->toContain('ptr-untracked-swatch');
});

// Point 4: a year that is already over runs all the way to 31 December, not
// to "yesterday relative to now" — the min(...) must resolve to 31 Dec here.
test('a year that is already over runs to 31 December, not to yesterday', function () {
    test()->travelTo(CarbonImmutable::parse('2026-08-10'));

    $r = untracked('2025-06-01', 2025);

    expect($r['window'])->toBe(['start' => '2025-06-01', 'from' => '2025-06-01', 'to' => '2025-12-31'])
        ->and($r['days'])->toContain('2025-12-31');
});

// Point 5: a year wholly outside the trackable range (start date, wholly
// future or wholly past the start) reports a window with a null "from" — a
// DIFFERENT shape from "no start date at all" (which is a null window). The
// template branches on this distinction (the "nothing to check yet" message
// vs. the "set a start date" CTA), so the two must stay distinguishable.
test('a year outside the trackable range has a non-null window with a null "from"', function () {
    test()->travelTo(CarbonImmutable::parse('2026-08-10'));

    $r = untracked('2026-03-01', 2027);

    expect($r['days'])->toBe([])
        ->and($r['window'])->toBe(['start' => '2026-03-01', 'from' => null, 'to' => null])
        ->and($r['window'])->not->toBeNull()
        ->and(preg_replace('/\s+/', ' ', $r['html']))
        ->toContain('Nothing to check in 2027 yet.');
});

// Point 6: days that already have a country must be excluded from the
// untracked list. This is the assertion the "if (true)" mutation of the
// tracked-day guard breaks (documented below in the calibration section).
test('days that already have a country are excluded from the untracked list', function () {
    test()->travelTo(CarbonImmutable::parse('2026-08-10'));

    $r = untracked('2026-08-01', 2026, ['2026-08-03', '2026-08-05']);

    expect($r['days'])->not->toContain('2026-08-03')
        ->and($r['days'])->not->toContain('2026-08-05')
        ->and($r['days'])->toContain('2026-08-04')
        // 1..9 Aug = 9 days, minus the 2 tracked ones = 7
        ->and($r['days'])->toHaveCount(7);
});

// Point 7: a window fully covered by tracked days reports zero gaps and the
// swatch/count are not shown — only the "every day has a country" sentence.
test('a fully covered window reports zero untracked days', function () {
    test()->travelTo(CarbonImmutable::parse('2026-08-10'));

    $days = [];
    for ($d = CarbonImmutable::parse('2026-08-01'); $d->lte(CarbonImmutable::parse('2026-08-09')); $d = $d->addDay()) {
        $days[] = $d->format('Y-m-d');
    }

    $r = untracked('2026-08-01', 2026, $days);

    expect($r['days'])->toBe([])
        ->and($r['window'])->toBe(['start' => '2026-08-01', 'from' => '2026-08-01', 'to' => '2026-08-09'])
        ->and($r['html'])->not->toContain('ptr-untracked-swatch')
        ->and(preg_replace('/\s+/', ' ', $r['html']))
        ->toContain('Every day from 01.08.2026 to 09.08.2026 has a country.');
});

// Point 8: currentYear is a public Livewire property, so a client can set it
// to anything. The function clamps it into 1970..9999 before building any
// Carbon date, so the day loop can never run away — regardless of how absurd
// the client-supplied year is, the resulting window is at most one year long.
test('an absurd client-supplied year is clamped and cannot produce a runaway window', function () {
    test()->travelTo(CarbonImmutable::parse('2026-08-10'));

    foreach ([0, 99999999, -5, 500000] as $year) {
        $r = untracked('1970-01-01', $year);
        expect(count($r['days']))->toBeLessThanOrEqual(366);
    }
});

// saveDays() must refresh untrackedDays without a page reload — it is one of
// the five call sites for refreshUntrackedDays(), and the one a user actually
// exercises when stamping days in the modal. Before the save, three of the
// nine August days are untracked-free but the rest of the window is empty;
// after saveDays() stamps the remaining ones, the count must drop to zero
// live, in the same component instance.
test('saveDays refreshes the untracked count without a reload', function () {
    test()->travelTo(CarbonImmutable::parse('2026-08-10'));

    $user = User::factory()->create(['pt_start' => '2026-08-01']);
    $this->actingAs($user);

    $component = Volt::test('calendar')->set('currentYear', 2026);

    // 1..9 Aug = 9 untracked days before any save.
    expect($component->get('untrackedDays'))->toHaveCount(9);

    $component->call('saveDays', ['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07', '2026-08-08', '2026-08-09'], 'pt');

    expect($component->get('untrackedDays'))->toBe([])
        ->and($component->get('untrackedWindow'))->toBe(['start' => '2026-08-01', 'from' => '2026-08-01', 'to' => '2026-08-09'])
        ->and($component->html())->not->toContain('ptr-untracked-swatch');
});

// deleteDays() is the other of the two call sites a user actually exercises
// without a reload. Clearing a day that was tracked must make it reappear in
// untrackedDays immediately, in the same component instance.
test('deleteDays refreshes the untracked count without a reload', function () {
    test()->travelTo(CarbonImmutable::parse('2026-08-10'));

    $user = User::factory()->create(['pt_start' => '2026-08-01']);
    $this->actingAs($user);

    foreach (['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07', '2026-08-08', '2026-08-09'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'pt']);
    }

    $component = Volt::test('calendar')->set('currentYear', 2026);

    // fully covered before any delete.
    expect($component->get('untrackedDays'))->toBe([]);

    $component->call('deleteDays', ['2026-08-05']);

    expect($component->get('untrackedDays'))->toBe(['2026-08-05'])
        ->and($component->html())->toContain('ptr-untracked-swatch');
});
