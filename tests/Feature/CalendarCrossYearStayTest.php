<?php

use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Volt\Volt;

/*
 | THE SPECIFICATION THESE TESTS PIN (P7 of
 | docs/plans/2026-08-10T2204-kalender-ux-tageseingabe.md):
 |
 |  1. Contiguous stays ("runs") are formed over the user's WHOLE history, not
 |     over the year on screen.
 |  2. Displayed are the runs that INTERSECT the displayed year — each with its
 |     FULL length and its FULL date range. Runs wholly outside the year are not
 |     displayed at all.
 |  3. "days total", the PT split, the modal chips and the untracked days stay
 |     CALENDAR-YEAR bound and unchanged. A run of 9d on a card whose head says
 |     "5 days total" is therefore expected, not a contradiction.
 |  4. Gaps are computed between consecutive DISPLAYED runs of the same country,
 |     exactly as before (threshold 21 days -> "· tight" / text-risk).
 |
 | STATE OF THE CODE WHEN THESE TESTS WERE WRITTEN (commit 11bc357, worktree
 | clean). Deliberately phrased in the past tense: after the fix this paragraph
 | stays TRUE as a record of the defect, so a later regression cannot be waved
 | away as "known behaviour".
 |
 | All FIVE Event::query() calls in resources/views/pages/calendar.blade.php
 | filtered on one calendar year — counted, not estimated:
 | `git show 11bc357:resources/views/pages/calendar.blade.php | grep -c "Event::query()"`
 | = 5, at :147, :154, :185, :204 and :228, every one of them with the year
 | filter. (An earlier version of this comment said "four", which was the count of
 | the four $events MAPPINGS from P1 — a different set. The mappings are four
 | because the delete path at :147 queries without mapping.)
 |
 | The run detection in the @php block at :393-419 ran on that year-bound array —
 | so a stay across New Year was cut in half and its true length appeared
 | NOWHERE. Measured on 11bc357 with the fixture of `crossYearFixture()` below
 | (DE 28.12.2025-05.01.2026 = 9 days):
 |
 |   view 2025:  DE  4d  28.12.2025 -> 31.12.2025   (spec: 9d 28.12.2025 -> 05.01.2026)
 |   view 2026:  DE  5d  01.01.2026 -> 05.01.2026   (spec: 9d 28.12.2025 -> 05.01.2026)
 |
 | Second defect, same @php block, latent at the time of writing: runs were split
 | on `strtotime($a) - strtotime($b) > 86400`. With config('app.timezone') = UTC
 | that difference is always exactly 86400, but in a DST zone a backward jump
 | makes a calendar day 25 hours long, and the run tore apart. Measured on
 | 11bc357 with PHP's default timezone on Europe/Berlin (what
 | Illuminate\Foundation\Bootstrap\LoadConfiguration.php:65 does with
 | app.timezone at boot): DE 23.-28.10.2026 came out as 3d 23.10.-25.10. plus
 | 3d 26.10.-28.10. instead of one 6d run. See the last test in this file.
 |
 | WHY EVERYTHING IS ASSERTED THROUGH THE RENDERED HTML: when these tests were
 | written the run detection sat in that @php block, so there was no component
 | method to call, and whether the fixer would extract it was open. He did —
 | it is App\Support\ContiguousStays now — and not one expectation here had to
 | move, which is the point of going through Volt::test()->html(): these tests
 | pin what the page SHOWS, not where it is computed.
 */

/*
 | Extractor, built on the pattern of extractGapSpan() in CalendarStayGapTest.php
 | (render -> pull the rendered span -> assert value, colour class and the
 | "· tight" suffix together). Widened to a whole country card, because the count
 | of run entries is part of the specification, not just their contents.
 |
 | Not shared with CalendarStayGapTest.php on purpose: a global function defined
 | in one Pest file is only visible to another if that file happens to be loaded
 | first, which would make this file order-dependent. Same reason both defects
 | live in ONE new file: they are two defects of the SAME @php block.
 |
 | Kept as loose as it can be while still measuring something: the country stamp
 | is found by the country NAME in its head, run entries by their "<n>d" span
 | plus the two dates inside their <time>, gaps by the gap span.
 |
 | THE SEAM LINE came later: point 3 of the plan left the marker for a cross-year
 | run to the design-lead, so the first eight tests here could not depend on its
 | markup. It exists now — a <p class="ptr-seam"> INSIDE the run's own <li>,
 | without a <time> and without an "<n>d" span, so it does not disturb the run
 | detection above — and its text is a claim about the arithmetic, hence worth
 | asserting: "<n> of these <m> days fall in <year>", singular "1 ... falls ...".
 |
 | It is returned as 'seams', a list PARALLEL to 'runs' (one entry per run, null
 | where no seam is rendered), not as a key inside each run entry: the eight
 | existing tests compare whole run arrays with toBe(), and an extra key would
 | have forced every one of those expectations to be rewritten. Index alignment
 | is what carries "THIS run has the seam", and it holds because both lists are
 | appended in the same branch of the loop below.
 */
function extractStayCard(string $html, string $country): ?array
{
    // The empty-state blocks also carry .pt-stamp but on bg-navy-50/60, so
    // "pt-stamp bg-white" matches country stamps only. </ol> closes the
    // contiguous-stays list and thus the part of the card that matters.
    preg_match_all('/<div class="pt-stamp bg-white[\s\S]*?<\/ol>/', $html, $cards);

    foreach ($cards[0] as $card) {
        if (! preg_match('/leading-snug">\s*([^<]*?)\s*<\/dd>/', $card, $head)) {
            continue;
        }

        if (! str_contains($head[1], $country)) {
            continue;
        }

        preg_match('/leading-none text-navy-900 dark:text-navy-50">(\d+)</', $card, $total);

        $runs = [];
        $gaps = [];
        $seams = [];

        // Document order is significant: gap i sits between run i and run i+1.
        foreach (preg_split('/<li\b/', $card) as $item) {
            if (preg_match('/font-medium\s+(text-ok|text-risk)[^"]*">\s*(-?\d+) days gap( · tight)?/', $item, $gap)) {
                $gaps[] = [
                    'days' => (int) $gap[2],
                    'tight' => isset($gap[3]) && $gap[3] !== '',
                    'class' => $gap[1],
                ];

                continue;
            }

            if (preg_match('/>\s*(\d+)d\s*</', $item, $length)
                && preg_match('/<time[^>]*>([\s\S]*?)<\/time>/', $item, $time)) {
                preg_match_all('/\d{2}\.\d{2}\.\d{4}/', $time[1], $dates);

                $runs[] = [
                    'days' => (int) $length[1],
                    'from' => $dates[0][0] ?? null,
                    'to' => $dates[0] === [] ? null : end($dates[0]),
                ];

                // Tags stripped and whitespace collapsed, because the sentence is
                // broken across source lines and wraps its two figures in
                // <span class="ptr-seam-n"> — the STATEMENT is what is asserted,
                // not where the markup happens to break.
                $seams[] = preg_match('/<p[^>]*class="[^"]*ptr-seam[^"]*"[^>]*>([\s\S]*?)<\/p>/', $item, $seam)
                    ? trim(preg_replace('/\s+/', ' ', strip_tags($seam[1])))
                    : null;
            }
        }

        return [
            'title' => $head[1],
            'total_days' => isset($total[1]) ? (int) $total[1] : null,
            'runs' => $runs,
            'gaps' => $gaps,
            'seams' => $seams,
        ];
    }

    return null;
}

function stampDays(User $user, string $country, array $days): void
{
    foreach ($days as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => $country]);
    }
}

// One DE stay across New Year plus one ordinary PT stay in January, the fixture
// of the reported defect. Counted by hand: 28.-31.12.2025 = 4 days,
// 01.-05.01.2026 = 5 days, together 9 contiguous days from 28.12.2025 to
// 05.01.2026. PT: 3 days, wholly inside 2026.
function crossYearFixture(User $user): void
{
    stampDays($user, 'de', [
        '2025-12-28', '2025-12-29', '2025-12-30', '2025-12-31',
        '2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05',
    ]);
    stampDays($user, 'pt', ['2026-01-20', '2026-01-21', '2026-01-22']);
}

// Point 1/2 of the spec, seen from the EARLIER year. The 9-day stay intersects
// 2025, so it is displayed — with all 9 days and the range that reaches into
// 2026, not with the 4 days that happen to fall inside 2025. Exactly ONE entry:
// the run must not appear once per year it touches.
test('a stay across New Year is listed in full in the earlier year', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    crossYearFixture($user);

    $card = extractStayCard(Volt::test('calendar')->set('currentYear', 2025)->html(), 'Germany');

    expect($card)->not->toBeNull()
        ->and($card['runs'])->toHaveCount(1)
        ->and($card['runs'])->toBe([
            ['days' => 9, 'from' => '28.12.2025', 'to' => '05.01.2026'],
        ])
        ->and($card['gaps'])->toBe([]);
});

// Same fixture, same run, seen from the LATER year. Both views have to show the
// identical stay — "9d, 28.12.2025 -> 05.01.2026" is a property of the stay, not
// of the year it is looked at from.
test('a stay across New Year is listed in full in the later year', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    crossYearFixture($user);

    $card = extractStayCard(Volt::test('calendar')->set('currentYear', 2026)->html(), 'Germany');

    expect($card)->not->toBeNull()
        ->and($card['runs'])->toHaveCount(1)
        ->and($card['runs'])->toBe([
            ['days' => 9, 'from' => '28.12.2025', 'to' => '05.01.2026'],
        ])
        ->and($card['gaps'])->toBe([]);
});

// Point 3, the counter-weight: the year figures must NOT follow the run out of
// the year. Residency days are counted per calendar year, so the same fixture
// owes 4 days to 2025 and 5 days to 2026 — the numbers hand-counted above. This
// test was GREEN on 11bc357 and must stay green: it marks what the fix may not
// touch, as opposed to what it has to repair. The neighbouring PT card is
// checked in the same breath, as the control that an ordinary within-year stay
// keeps rendering exactly as before.
test('the year day totals stay bound to the calendar year', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    crossYearFixture($user);

    $de2025 = extractStayCard(Volt::test('calendar')->set('currentYear', 2025)->html(), 'Germany');
    expect($de2025)->not->toBeNull()
        ->and($de2025['total_days'])->toBe(4);

    $html2026 = Volt::test('calendar')->set('currentYear', 2026)->html();

    $de2026 = extractStayCard($html2026, 'Germany');
    expect($de2026)->not->toBeNull()
        ->and($de2026['total_days'])->toBe(5);

    $pt2026 = extractStayCard($html2026, 'Portugal');
    expect($pt2026)->not->toBeNull()
        ->and($pt2026['total_days'])->toBe(3)
        ->and($pt2026['runs'])->toBe([
            ['days' => 3, 'from' => '20.01.2026', 'to' => '22.01.2026'],
        ]);
});

// Point 2, second half — the guard against a fix that goes too far. Building
// runs over the whole history must not make history-only runs visible: the DE
// stay in 2024 does not touch 2026 and may not show up in the 2026 view, and
// Portugal, which exists in the database but only in 2024, may not get a card in
// 2026 at all. Also GREEN on 11bc357 (the year filter excludes them today); it
// exists to stay green.
test('a stay wholly outside the displayed year is not listed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    stampDays($user, 'de', ['2024-07-01', '2024-07-02', '2024-07-03', '2024-07-04', '2024-07-05']);
    stampDays($user, 'pt', ['2024-08-01', '2024-08-02', '2024-08-03']);
    stampDays($user, 'de', ['2026-05-01', '2026-05-02', '2026-05-03']);

    $html = Volt::test('calendar')->set('currentYear', 2026)->html();

    $de = extractStayCard($html, 'Germany');
    expect($de)->not->toBeNull()
        ->and($de['runs'])->toHaveCount(1)
        ->and($de['runs'])->toBe([
            ['days' => 3, 'from' => '01.05.2026', 'to' => '03.05.2026'],
        ])
        // No second run means no gap either — a gap rail here would be the
        // visible symptom of the 2024 run having leaked in.
        ->and($de['gaps'])->toBe([]);

    expect(extractStayCard($html, 'Portugal'))->toBeNull();
});

// "Intersects the year" is not "starts or ends in the year": a stay that
// encloses 2026 completely intersects it without either of its ends being
// inside. Hand-counted: 30.+31.12.2025 = 2 days, 2026 = 365 days (2026 is not a
// leap year), 01.+02.01.2027 = 2 days -> 369 contiguous days,
// 30.12.2025 -> 02.01.2027. The year total stays 365, the 2026 share.
test('a stay that encloses the displayed year is listed with its full length', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $rows = [];
    $day = CarbonImmutable::create(2025, 12, 30);
    $last = CarbonImmutable::create(2027, 1, 2);

    for (; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
        $rows[] = [
            'user_id' => $user->id,
            'day' => $day->format('Y-m-d'),
            'country' => 'de',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    // The hand count above, verified against the fixture actually written.
    expect($rows)->toHaveCount(369);
    Event::insert($rows);

    $card = extractStayCard(Volt::test('calendar')->set('currentYear', 2026)->html(), 'Germany');

    expect($card)->not->toBeNull()
        ->and($card['runs'])->toBe([
            ['days' => 369, 'from' => '30.12.2025', 'to' => '02.01.2027'],
        ])
        ->and($card['gaps'])->toBe([]);
});

/*
 | THE TWO GAP TESTS BELOW, AND WHAT THEY CAN AND CANNOT PROVE.
 |
 | A gap between two DISPLAYED runs can never itself span a year boundary, and
 | that follows from the spec rather than from the code: if the earlier run
 | intersects year Y, its last day is >= 01.01.Y; if the later run intersects Y,
 | its first day is <= 31.12.Y; the gap lies strictly between those two days and
 | therefore strictly inside Y. So the gap NUMBER is not what the cross-year fix
 | changes — the length and range of the run next to the boundary is.
 |
 | What these two tests pin instead, and what makes them worth their runtime:
 |   * the gap adjacent to a boundary-crossing run keeps being measured from that
 |     run's TRUE end (05.01.2026), and
 |   * the number of runs and gaps in the view stays the displayed set. The
 |     fixture of the first test deliberately contains a third DE stay in June
 |     2025 that does NOT touch 2026: a fix that renders the history-wide run
 |     list instead of the intersecting one would produce three runs and two gaps
 |     here and fail on the count.
 | The gaps are asserted BEFORE the runs so the guard is measured on the current
 | state instead of being skipped behind the failing run assertion.
 */

// Tight side of the threshold. Hand-counted: between 05.01.2026 and 20.01.2026
// lie 06.-19.01. = 14 days, and 14 < 21, so "· tight" in text-risk.
test('the gap next to a boundary-crossing stay is counted from its true end and stays tight', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Wholly in 2025 — must not be displayed in the 2026 view.
    stampDays($user, 'de', ['2025-06-01', '2025-06-02', '2025-06-03']);
    // The same nine days as crossYearFixture(), written out because the second
    // DE run below occupies the days that fixture gives to Portugal, and one day
    // may only belong to one country.
    stampDays($user, 'de', [
        '2025-12-28', '2025-12-29', '2025-12-30', '2025-12-31',
        '2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05',
    ]);
    stampDays($user, 'de', ['2026-01-20', '2026-01-21', '2026-01-22']);

    $card = extractStayCard(Volt::test('calendar')->set('currentYear', 2026)->html(), 'Germany');

    expect($card)->not->toBeNull()
        ->and($card['gaps'])->toBe([
            ['days' => 14, 'tight' => true, 'class' => 'text-risk'],
        ])
        ->and($card['runs'])->toBe([
            ['days' => 9, 'from' => '28.12.2025', 'to' => '05.01.2026'],
            ['days' => 3, 'from' => '20.01.2026', 'to' => '22.01.2026'],
        ]);
});

// Wide side of the same threshold, so it is shown to discriminate next to the
// boundary as well. Hand-counted: between 05.01.2026 and 01.03.2026 lie
// 06.-31.01. = 26 days plus 01.-28.02. = 28 days (2026 is not a leap year) = 54
// days, and 54 >= 21, so no "· tight" and text-ok.
test('a wide gap next to a boundary-crossing stay is not tight', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    stampDays($user, 'de', [
        '2025-12-28', '2025-12-29', '2025-12-30', '2025-12-31',
        '2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05',
    ]);
    stampDays($user, 'de', ['2026-03-01', '2026-03-02', '2026-03-03']);

    $card = extractStayCard(Volt::test('calendar')->set('currentYear', 2026)->html(), 'Germany');

    expect($card)->not->toBeNull()
        ->and($card['gaps'])->toBe([
            ['days' => 54, 'tight' => false, 'class' => 'text-ok'],
        ])
        ->and($card['runs'])->toBe([
            ['days' => 9, 'from' => '28.12.2025', 'to' => '05.01.2026'],
            ['days' => 3, 'from' => '01.03.2026', 'to' => '03.03.2026'],
        ]);
});

/*
 | THE DST TEST. The run detection asks whether two neighbouring days are more
 | than 86400 seconds apart. That is a statement about ELAPSED TIME, while
 | "contiguous" is a statement about the CALENDAR — and the two part company at
 | every DST backward jump, where a calendar day lasts 25 hours.
 |
 | Europe/Berlin falls back on the last Sunday in October: 25.10.2026. The stay
 | 23.-28.10.2026 is six contiguous calendar days and must be ONE run of 6d, in
 | any timezone the app is configured for.
 |
 | HOW THE ZONE IS SWITCHED, and why config() alone is not enough. Nothing in
 | this render path reads config('app.timezone'); strtotime() and Carbon::parse()
 | read PHP's default timezone, which the framework sets FROM that config value
 | once at boot (LoadConfiguration.php:65). Measured on 11bc357: with
 | config(['app.timezone' => 'Europe/Berlin']) alone the run stayed at 6d — the
 | test would have been green without ever leaving UTC, the worst possible
 | outcome. Only date_default_timezone_set('Europe/Berlin') reproduced the
 | 90000-second delta and the 3d + 3d split. Both are set here: the config value
 | because that is what a deployment changes (and a fix that parses with an
 | explicit config('app.timezone') must see the same zone), the default timezone
 | because that is what carries the behaviour today.
 |
 | ISOLATION: PHP's default timezone is process-global, so it is restored in a
 | finally before any expectation can throw. (Laravel re-bootstraps the
 | application for every test and would set it back to UTC anyway; the explicit
 | restore is what makes that a guarantee rather than a side effect.)
 */
test('a stay across a DST backward jump stays one contiguous run', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    stampDays($user, 'de', ['2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27', '2026-10-28']);

    $original = date_default_timezone_get();

    try {
        config(['app.timezone' => 'Europe/Berlin']);
        date_default_timezone_set('Europe/Berlin');

        // Proof that this test really is in a DST zone and not silently in UTC:
        // the calendar day 25.10.2026 is 25 hours long here. If a future PHP or
        // tzdata made this 86400, the test below would pass for the wrong
        // reason — so it is asserted, not assumed.
        $delta = strtotime('2026-10-26') - strtotime('2026-10-25');

        $html = Volt::test('calendar')->set('currentYear', 2026)->html();
    } finally {
        date_default_timezone_set($original);
    }

    expect($delta)->toBe(90000);

    $card = extractStayCard($html, 'Germany');

    // Whole list first, count second: when the run tears apart, the array diff
    // names both halves, which the bare count assertion would not.
    expect($card)->not->toBeNull()
        ->and($card['runs'])->toBe([
            ['days' => 6, 'from' => '23.10.2026', 'to' => '28.10.2026'],
        ])
        ->and($card['runs'])->toHaveCount(1)
        ->and($card['gaps'])->toBe([]);
});

/*
 | THE SEAM LINE — four guards added after the fix, because the marker did not
 | exist while the eight tests above were written and the reviewer's mutation
 | probe showed the net let three mutations of App\Support\ContiguousStays
 | through: days_in_year without its clamp, spans_years hard-wired to false, and
 | the intersection bound itself (from > $yearTo turned into >=, which drops a
 | stay that BEGINS on 31 December out of that year's view — an arrival on New
 | Year's Eve would vanish from the year it happened in).
 |
 | WHERE THE EXPECTED SENTENCES COME FROM — the point of these four tests would
 | be lost if they were read off the now-green render. The wording is the
 | specification handed over with the markup ("<n> of these <m> days fall in
 | <year>", singular "1 of these <m> days falls in <year>"); the FIGURES are
 | counted by hand from the fixture, day by day, and written into each test's
 | comment before it was ever run:
 |
 |   28.-31.12.2025 = 4 days | 01.-05.01.2026 = 5 days | run = 9 days
 |   31.12.2025 alone        = 1 day  in 2025 | run 31.12.2025-05.01.2026 = 6 days
 |   01.01.2026 alone        = 1 day  in 2026 | run 28.12.2025-01.01.2026 = 5 days
 |
 | Within THIS set of cases, the two "1 of these ..." fixtures are also the only
 | ones that reach the singular verb, so the grammar branch is covered by cases
 | that have to exist anyway. (In the code the singular is reachable by any run
 | with exactly one day inside the displayed year — it is this file that has only
 | those two.)
 */

// Kills "days_in_year without the clamp" (to - from + 1): that mutation makes the
// share equal the run length, i.e. "9 of these 9 days fall in 2026" — which is
// both arithmetically absurd and precisely the contradiction the line exists to
// resolve. Asserted from BOTH years with the same fixture, because the clamp has
// two sides (max(from, 1 Jan) and min(to, 31 Dec)) and a mutation of only one of
// them would survive a single-year test.
test('the seam line names the share of the run that falls in the displayed year', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    crossYearFixture($user);

    // 28.-31.12.2025 = 4 of the 9 days.
    $de2025 = extractStayCard(Volt::test('calendar')->set('currentYear', 2025)->html(), 'Germany');
    expect($de2025)->not->toBeNull()
        ->and($de2025['seams'])->toBe(['4 of these 9 days fall in 2025']);

    // 01.-05.01.2026 = 5 of the 9 days.
    $de2026 = extractStayCard(Volt::test('calendar')->set('currentYear', 2026)->html(), 'Germany');
    expect($de2026)->not->toBeNull()
        ->and($de2026['seams'])->toBe(['5 of these 9 days fall in 2026']);
});

// The other direction of spans_years, and the reason it is a test of its own.
// Measured, one mutation at a time: a hard-wired FALSE stays undetected as long
// as only runs WITHOUT a New Year are checked (it then fails the test above and
// passes this one — 3 failed / 9 passed over the file), and a hard-wired TRUE
// stays undetected as long as only runs WITH one are (it passes above and fails
// here — 1 failed / 11 passed). So the pair is what pins the flag; neither test
// alone does. A stay wholly inside the year needs no reconciliation — its run
// length and its card head are the same number — so a line here would be noise.
test('a stay wholly inside the displayed year carries no seam line', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    stampDays($user, 'de', ['2026-05-01', '2026-05-02', '2026-05-03']);

    $card = extractStayCard(Volt::test('calendar')->set('currentYear', 2026)->html(), 'Germany');

    // The run is asserted alongside so the case says WHICH run carries no seam:
    // exactly one entry, and it is the one that was stamped. It does NOT protect
    // against a blind seam extractor — measured: with the extractor forced to
    // $seams[] = null, this case still passes (1 passed, 3 assertions). What
    // catches that is the other three seam cases, which then fail (3 failed,
    // 9 passed over the file). A null expectation can only ever be defended
    // elsewhere, by a case that expects a value.
    expect($card)->not->toBeNull()
        ->and($card['runs'])->toBe([
            ['days' => 3, 'from' => '01.05.2026', 'to' => '03.05.2026'],
        ])
        ->and($card['seams'])->toBe([null]);
});

// Kills "from > $yearTo" turned into ">=": a run whose first day IS 31 December
// would then be dropped from that year's view, and the user who arrives on New
// Year's Eve would not find the stay in the year they arrived. Hand-counted:
// 31.12.2025 plus 01.-05.01.2026 = 6 contiguous days, of which exactly 1 falls
// in 2025 — hence the singular verb, and hence a card head of 1 next to a run of
// 6d, the sharpest form of the contradiction the seam line answers.
test('a stay that begins on 31 December appears in that year with its full length', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    stampDays($user, 'de', ['2025-12-31', '2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05']);

    $card = extractStayCard(Volt::test('calendar')->set('currentYear', 2025)->html(), 'Germany');

    expect($card)->not->toBeNull()
        ->and($card['runs'])->toBe([
            ['days' => 6, 'from' => '31.12.2025', 'to' => '05.01.2026'],
        ])
        ->and($card['seams'])->toBe(['1 of these 6 days falls in 2025'])
        // The year figure stays the year figure: one tracked day in 2025.
        ->and($card['total_days'])->toBe(1);
});

// The mirror image at the other bound, "$run['to'] < $yearFrom" turned into
// "<=": a run whose last day IS 1 January would be dropped from the view of that
// year. Not in the reviewer's probe, but it is the same edge with the operands
// swapped, and one of the two bounds being sharp says nothing about the other.
// Hand-counted: 28.-31.12.2025 plus 01.01.2026 = 5 contiguous days, of which
// exactly 1 falls in 2026.
test('a stay that ends on 1 January appears in that year with its full length', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    stampDays($user, 'de', ['2025-12-28', '2025-12-29', '2025-12-30', '2025-12-31', '2026-01-01']);

    $card = extractStayCard(Volt::test('calendar')->set('currentYear', 2026)->html(), 'Germany');

    expect($card)->not->toBeNull()
        ->and($card['runs'])->toBe([
            ['days' => 5, 'from' => '28.12.2025', 'to' => '01.01.2026'],
        ])
        ->and($card['seams'])->toBe(['1 of these 5 days falls in 2026'])
        ->and($card['total_days'])->toBe(1);
});
