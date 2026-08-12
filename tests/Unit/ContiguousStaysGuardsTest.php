<?php

use App\Support\ContiguousStays;

/*
 | Three guards named in P9 Welle A of
 | docs/plans/2026-08-10T2204-kalender-ux-tageseingabe.md, point 4: mutations
 | of App\Support\ContiguousStays that the reviewer measured survive the
 | existing suite. Each one was verified red against its named mutation
 | (throw -> return 0; the strcmp tie-break removed from the usort comparator)
 | before this file existed — see the test-engineer's delivery notes for the
 | exact patch and restore.
 |
 | No Laravel app boot needed and no DB: ContiguousStays::intersectingYear()
 | takes a plain iterable, so these are pure-function tests against
 | hand-constructed input, plain PHPUnit\Framework\TestCase (Pest's default for
 | tests/Unit — this project's tests/Pest.php only binds
 | Illuminate\Foundation\Testing\TestCase to Feature and Browser).
 */

// GUARD 1 — ordinal() must THROW on an unusable day value, not silently
// return 0. A silent 0 folds the bad row into 1970-01-01, which would shorten
// or corrupt whatever run happens to sit there instead of surfacing the bad
// data. The dateTime column can hold a string ContiguousStays's own pattern
// rejects (see the '?? null IS REACHABLE' comment in calendar.blade.php), so
// this is not a hypothetical input.
test('a day value that is not a calendar day throws rather than silently becoming day zero', function () {
    expect(fn () => ContiguousStays::intersectingYear([
        ['title' => '🇩🇪 Germany', 'day' => 'not-a-calendar-day'],
    ], 2026))->toThrow(InvalidArgumentException::class);
});

// The positive control: a well-formed day of the same shape does not throw,
// so guard 1 is pinned against an actual parsing failure and not against
// intersectingYear() rejecting all input.
test('a well-formed day value does not throw', function () {
    $out = ContiguousStays::intersectingYear([
        ['title' => '🇩🇪 Germany', 'day' => '2026-03-14'],
    ], 2026);

    expect($out)->toBe([
        '🇩🇪 Germany' => [[
            'days' => 1,
            'from' => '2026-03-14',
            'to' => '2026-03-14',
            'days_in_year' => 1,
            'spans_years' => false,
        ]],
    ]);
});

/*
 | GUARD 2 — the usort tie-break `?: strcmp($a[1], $b[1])`. Reachable today
 | only through hand-built input like this (the unique index added in this
 | same phase, 2026_08_13_..._add_unique_index_to_events_user_id_day.php,
 | means a real database row can no longer produce two titles on the same
 | day) — which is exactly why this is a pure-function test of the class and
 | not a Feature test through two Event rows.
 |
 | THE SHAPE OF THE PROBE: three rows fed in an order that only agrees with
 | the tie-break's OWN ordering by accident if the tie-break is absent. Two
 | titles claim 14.03.2026 — PT first in the input, DE second — and DE alone
 | claims 15.03.2026.
 |
 | WITH the tie-break, the (ordinal, title) sort places DE-14 before PT-14
 | regardless of input order (title 'DE' < 'PT' by strcmp), so the run order
 | becomes DE(14) -> PT(14) -> DE(15): PT sits BETWEEN the two DE rows, which
 | is exactly what keeps them from merging. Germany therefore ends up with TWO
 | separate one-day runs, not one two-day run.
 |
 | WITHOUT it, PHP's usort is stable (guaranteed since PHP 8.0), so two rows
 | with the same ordinal keep the RELATIVE ORDER THEY WERE FED IN. Fed as
 | PT-14, DE-14, DE-15, the rows stay in that order (already non-decreasing by
 | ordinal alone), so DE-14 and DE-15 sit next to each other and — same
 | title, ordinal 15 <= 14 + 1 — MERGE into one two-day run. That merged run
 | is the observable difference this test is built to catch: it means the
 | mutant claims Germany was here for 15.03. when the tie-break's answer says
 | the 15th belongs to a run of its own, cut off from the 14th by Portugal in
 | between.
 */
test('two titles on the same day resolve by a title tie-break, not input order', function () {
    $out = ContiguousStays::intersectingYear([
        ['title' => '🇵🇹 Portugal', 'day' => '2026-03-14'],
        ['title' => '🇩🇪 Germany', 'day' => '2026-03-14'],
        ['title' => '🇩🇪 Germany', 'day' => '2026-03-15'],
    ], 2026);

    expect($out['🇵🇹 Portugal'])->toBe([[
        'days' => 1,
        'from' => '2026-03-14',
        'to' => '2026-03-14',
        'days_in_year' => 1,
        'spans_years' => false,
    ]]);

    // The core of the guard: TWO one-day runs, not one two-day run.
    expect($out['🇩🇪 Germany'])->toHaveCount(2)
        ->and($out['🇩🇪 Germany'])->toBe([
            ['days' => 1, 'from' => '2026-03-14', 'to' => '2026-03-14', 'days_in_year' => 1, 'spans_years' => false],
            ['days' => 1, 'from' => '2026-03-15', 'to' => '2026-03-15', 'days_in_year' => 1, 'spans_years' => false],
        ]);
});
