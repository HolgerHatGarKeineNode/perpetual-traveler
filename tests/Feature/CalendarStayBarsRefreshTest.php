<?php

use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Volt\Volt;

/*
 | THE FOUR NET GAPS FROM P4, closed here (P9 Welle A point 4 of
 | docs/plans/2026-08-10T2204-kalender-ux-tageseingabe.md). Measured by the
 | reviewer via mutation probe: the production code at all four call sites of
 | $refreshEventBars is already correct, but CalendarStayBarsTest.php never
 | calls saveDays()/deleteDays() and never reads eventBars right after mount()
 | alone (every case there ends in an explicit ->set('currentYear', …), which
 | runs the updated() hook and masks a broken mount() ordering) — so a mutant
 | at any of the four call sites survives the existing suite unnoticed:
 |
 |   1. $refreshEventBars() removed from saveDays()      (calendar.blade.php)
 |   2. $refreshEventBars() removed from deleteDays()     (calendar.blade.php)
 |   3. the call in mount() moved BEFORE $this->events is (re)loaded
 |   4. the call in the updated('currentYear') hook moved BEFORE its
 |      $this->events reload
 |
 | Each test below was verified red against its named mutation before this
 | file existed — see the test-engineer's delivery notes for the exact patch
 | and restore, all done with mutprobe (byte-identical afterwards).
 |
 | WHY THIS FILE AND NOT CalendarStayBarsTest.php: that file pins the
 | PROJECTION'S shape (grouping, the one +1, which runs intersect the year) —
 | its own header says it deliberately never calls saveDays()/deleteDays().
 | This file pins a different property entirely: that the four places which
 | ARE allowed to change $this->events also refresh $eventBars from it, in the
 | right order. Mixing the two specifications into one file would make a
 | future change to either read as touching both.
 */

// MUTANT 1 — saveDays() must refresh eventBars, not just events. Mounted with
// no prior data (eventBars start empty), then one day is saved through the
// real write path. If the refresh call were removed, eventBars would still be
// empty after the save even though the day was written.
test('saveDays refreshes the event bars, not just the day-wise events', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Volt::test('calendar')->set('currentYear', 2026);
    expect($component->get('eventBars'))->toBe([]);

    $component->call('saveDays', ['2026-07-01', '2026-07-02'], 'DE');

    $bars = $component->get('eventBars');
    expect($bars)->toHaveCount(1)
        ->and($bars[0])->toBe([
            'title' => '🇩🇪 Germany',
            'country' => 'DE',
            'start' => '2026-07-01',
            'end' => '2026-07-03',
        ]);
});

// MUTANT 2 — the mirror image on the delete path. A bar exists before the
// call (seeded directly through the database and picked up on mount), then
// every one of its days is cleared through deleteDays(). If the refresh call
// were removed, the now-orphaned bar would still be reported to the client
// after its underlying rows are gone.
test('deleteDays refreshes the event bars, not just the day-wise events', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Event::factory()->create(['user_id' => $user->id, 'day' => '2026-08-10', 'country' => 'pt']);
    Event::factory()->create(['user_id' => $user->id, 'day' => '2026-08-11', 'country' => 'pt']);

    $component = Volt::test('calendar')->set('currentYear', 2026);
    expect($component->get('eventBars'))->toHaveCount(1);

    $component->call('deleteDays', ['2026-08-10', '2026-08-11']);

    expect($component->get('eventBars'))->toBe([]);
});

/*
 | MUTANT 3 — mount() must load $this->events BEFORE calling
 | $refreshEventBars(), because that function reads $this->events to spell
 | each bar's country code. Read straight after Volt::test('calendar') with NO
 | subsequent ->set('currentYear', …): every case in CalendarStayBarsTest.php
 | chains a ->set() after construction, and that fires the updated() hook,
 | which reloads $this->events correctly and would silently repair a broken
 | mount() ordering before the property is ever read. This is deliberately
 | the one case in this file that stops at mount().
 */
test('mount loads events before deriving the bars, so the first paint carries a country', function () {
    test()->travelTo(CarbonImmutable::parse('2026-06-01'));

    $user = User::factory()->create();
    $this->actingAs($user);
    Event::factory()->create(['user_id' => $user->id, 'day' => '2026-03-14', 'country' => 'de']);

    // currentYear is left at its default (null -> now()->year, i.e. 2026 under
    // the travelTo above), so this is mount() and mount() alone.
    $component = Volt::test('calendar');

    $bars = $component->get('eventBars');
    expect($bars)->toHaveCount(1);

    $de = collect($bars)->firstWhere('title', '🇩🇪 Germany');
    expect($de)->not->toBeNull()
        ->and($de['country'])->toBe('DE');
});

/*
 | MUTANT 4 — the updated('currentYear') hook must reload $this->events BEFORE
 | calling $refreshEventBars(), for the same reason as mount(). Mounted on one
 | year (2025, via travelTo so mount()'s own default lands there) with a DE
 | stay, then switched to a different year (2026) that has a DIFFERENT
 | country's stay and no DE at all. If the hook's refresh ran on the STALL
 | $this->events (still 2025's DE entries), the 2026 Portugal bar's country
 | would not be found in that stale map and would come back null instead of
 | 'PT' — the map is one calendar year behind the bar it is asked to spell.
 */
test('switching the year reloads events before deriving the bars', function () {
    test()->travelTo(CarbonImmutable::parse('2025-06-01'));

    $user = User::factory()->create();
    $this->actingAs($user);
    Event::factory()->create(['user_id' => $user->id, 'day' => '2025-03-14', 'country' => 'de']);
    Event::factory()->create(['user_id' => $user->id, 'day' => '2026-04-01', 'country' => 'pt']);

    // mount() lands on 2025 (now()->year under the travelTo above).
    $component = Volt::test('calendar');
    expect(collect($component->get('eventBars'))->pluck('title')->all())->toBe(['🇩🇪 Germany']);

    // The switch that exercises the updated('currentYear') hook.
    $component->set('currentYear', 2026);

    $bars = $component->get('eventBars');
    $pt = collect($bars)->firstWhere('title', '🇵🇹 Portugal');

    expect($pt)->not->toBeNull()
        ->and($pt['country'])->toBe('PT');
});
