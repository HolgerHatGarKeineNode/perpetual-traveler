<?php

use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Volt\Volt;

/*
 | THE SPECIFICATION THESE TESTS PIN (P4 of
 | docs/plans/2026-08-10T2204-kalender-ux-tageseingabe.md): the calendar renders
 | consecutive days of the same country as ONE BAR instead of one chip per day.
 |
 |  1. ONE derivation, not two. The bar ranges come out of the existing
 |     App\Support\ContiguousStays::intersectingYear() (P7), not out of a second
 |     grouping in JavaScript.
 |  2. A new server-side property carries the bars: `eventBars`, a LIST of
 |     ['title' => …, 'country' => …, 'start' => 'Y-m-d', 'end' => 'Y-m-d'],
 |     with `end` EXCLUSIVE — FullCalendar's convention. ContiguousStays reports
 |     `to` INCLUSIVE, so the +1 happens exactly ONCE, in the projection. That
 |     single +1 is what most of this file is about.
 |  3. The bar carries the TRUE range, not one clipped to the displayed year.
 |  4. `$this->events` stays day-wise and unchanged — every counting path reads
 |     it (the modal preview getters build their day->country map from it, so do
 |     the docket and the chips). The bars are an ADDITIONAL channel, not a
 |     replacement.
 |  5. The country travels per bar, in the same spelling as the day-wise path.
 |  6. Only runs that INTERSECT the displayed year appear, as in the stays panel.
 |
 | STATE OF THE CODE WHEN THESE TESTS WERE WRITTEN (commit 8dd30f4, worktree
 | clean). Past tense on purpose: after the implementation this paragraph stays
 | TRUE as a record, so a later regression cannot be waved away as "known".
 |
 | There was no `eventBars` at all. Measured, not assumed — a Livewire component
 | returns NULL for a property it does not have rather than throwing, so every
 | case below failed on its own expected value instead of on an exception:
 |
 |   case                                   expected                    got
 |   five consecutive days -> one bar       start 2026-03-02            null
 |                                          end   2026-03-07
 |   single day                             end = start + 1 day         null
 |   a gap breaks the bar                   2 bars                      null
 |   country change                         bar1.end === bar2.start     null
 |   across New Year                        2025-12-28 -> 2026-01-06    null
 |   wholly outside the year                not listed                  null
 |   country per bar                        'DE' / 'PT'                 null
 |   consistency invariant                  8 in-year bar days          null
 |
 | The ninth case (the day-wise `events` array) was GREEN at 8dd30f4 and is the
 | calibration in the other direction: it marks what may not break, as opposed to
 | what has to be built.
 |
 | HOW THESE CASES WERE SHOWN TO BITE, given that "everything fails because the
 | property is null" proves nothing about an off-by-one. A spec-conform projection
 | was written as a THROWAWAY simulation (ContiguousStays + the title->ISO map +
 | exactly one +1) and every expectation of this file was replayed against it —
 | all satisfiable — and then against four mutations of it. Counting the checks of
 | the simulation, not the tests of this file:
 |
 |   mutation                     checks failing
 |   +1 forgotten (end = to)       14   — incl. BOTH halves of case 8
 |   +1 doubled   (end = to + 2)   13   — incl. BOTH halves of case 8
 |   bar clipped to the year        2   — case 5 only, both year views
 |   runs from the next year leak   1   — case 5, the 2025 view only
 |
 | So the file is not merely red today, its cases discriminate the specific
 | defects the specification warns about. What that evidence is NOT: a statement
 | about the production code, which did not exist when it was taken. It says the
 | expectations are reachable and sharp, nothing about how they will be met.
 |
 | ONE DEVIATION FROM THE SPECIFICATION AS HANDED OVER, reported rather than
 | silently built: its point 5 asks for the country "in the same spelling as the
 | day-wise path" and adds in parenthesis that this is lower case, per saveDays()'
 | str($country)->lower(). Those two are not the same thing. Lower case is the
 | spelling of the DATABASE COLUMN; the day-wise path that reaches the client maps
 | it through country(...)->getIsoAlpha2() and therefore carries "DE" in UPPER
 | case (measured at 8dd30f4). The rule was followed, the parenthesis was not —
 | see case 7, which pins the rule against the day-wise channel itself.
 |
 | WHY THE PROPERTY IS READ AND NOT THE HTML — the opposite choice from
 | CalendarCrossYearStayTest.php, on purpose. There the subject WAS the rendered
 | stays panel. Here the subject is a payload that goes to the client and is
 | consumed by FullCalendar in the browser (nostrCal.js entangles `events` the
 | same way), so the payload IS the interface. Its shape and its dates are
 | testable at this level; the bar's looks, its `eventContent` and which day a
 | click on a bar resolves to are NOT — those are verified in the browser and are
 | deliberately absent from this file.
 |
 | Livewire's ->get() resolves BOTH a state() property and a computed() one
 | (measured at 8dd30f4 against the existing `contiguousStays` computed
 | property, which came back fully populated). So this file does not decide that
 | question for the implementer — although only an entangled state() property
 | reaches Alpine, which is where the bars have to arrive.
 */

/*
 | Extractor. Three deliberate freedoms, each one so the net measures the
 | specification and not an incidental decision:
 |
 |   * KEY ORDER is normalised. Pest's toBe() is assertSame(), and === on arrays
 |     demands identical key order — without this, writing the four keys in a
 |     different order would fail a passing implementation.
 |   * EXTRA keys are tolerated (only the four specified ones are compared). The
 |     spec names four fields, it does not forbid a fifth; FullCalendar's own
 |     `allDay: true` is the obvious candidate.
 |   * ORDER OF THE BARS is normalised by sorting on (start, country).
 |     ContiguousStays returns its runs grouped BY TITLE, so the natural order of
 |     a two-country view depends on which country is met first — that is not
 |     part of the specification, and FullCalendar does not care.
 |
 | A MISSING key becomes null rather than a missing entry, so a diff names the
 | key that is absent instead of reporting a different array length. A property
 | that is not an array at all (its state before this phase) yields null, so the
 | assertion prints its expected value against null instead of dying in a
 | TypeError in here.
 */
function ptrBarsFrom($component): ?array
{
    $bars = $component->get('eventBars');

    if (! is_array($bars)) {
        return null;
    }

    $out = [];

    foreach ($bars as $bar) {
        if (! is_array($bar)) {
            // Kept as it is, so the failure shows what was in the list.
            $out[] = $bar;

            continue;
        }

        $out[] = [
            'title' => $bar['title'] ?? null,
            'country' => $bar['country'] ?? null,
            'start' => $bar['start'] ?? null,
            'end' => $bar['end'] ?? null,
        ];
    }

    $scalar = fn ($value) => is_scalar($value) ? (string) $value : '';
    $key = fn ($bar) => is_array($bar)
        ? $scalar($bar['start'] ?? null).'|'.$scalar($bar['country'] ?? null)
        : '';

    usort($out, fn ($a, $b) => strcmp($key($a), $key($b)));

    return $out;
}

/*
 | The number of days covered by all bars that fall INSIDE the given calendar
 | year — the left-hand side of the consistency invariant.
 |
 | Walked day by day with CarbonImmutable ON PURPOSE, although ContiguousStays
 | has integer calendar arithmetic that would be faster: an expectation must not
 | be formed with the machinery under test, or the test confirms itself. Carbon
 | is an independent oracle here, and the fixtures are a few hundred days at
 | most.
 |
 | `end` is treated as EXCLUSIVE, which is the point of the measurement: with a
 | missing +1 a single-day bar covers ZERO days and the invariant breaks, which
 | is exactly the defect the specification puts here.
 |
 | Returns null instead of counting if any bar lacks a usable pair of dates, so
 | the invariant reports "expected 8, got null" rather than looping over a value
 | that means nothing.
 */
function ptrBarDaysInYear(?array $bars, int $year): ?int
{
    if ($bars === null) {
        return null;
    }

    $sum = 0;

    foreach ($bars as $bar) {
        if (! is_array($bar)
            || ! is_string($bar['start'] ?? null)
            || ! is_string($bar['end'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $bar['start']) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $bar['end']) !== 1) {
            return null;
        }

        $end = CarbonImmutable::parse($bar['end']);

        for ($day = CarbonImmutable::parse($bar['start']); $day->lessThan($end); $day = $day->addDay()) {
            if ((int) $day->year === $year) {
                $sum++;
            }
        }
    }

    return $sum;
}

// Named apart from stampDays()/crossYearFixture() in CalendarCrossYearStayTest.php
// rather than shared with them: a global function defined in one Pest file is
// only visible in another if that file happens to load first, so sharing would
// make one of the two files order-dependent — and re-declaring the same name
// would be a fatal error in the single process both run in.
function ptrBarStamp(User $user, string $country, array $days): void
{
    foreach ($days as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => $country]);
    }
}

// One DE stay across New Year plus one ordinary PT stay in January. Hand-counted:
// 28.-31.12.2025 = 4 days, 01.-05.01.2026 = 5 days, together 9 contiguous days
// from 28.12.2025 to 05.01.2026. PT: 20.-22.01.2026 = 3 days, wholly in 2026.
function ptrBarCrossYearFixture(User $user): void
{
    ptrBarStamp($user, 'de', [
        '2025-12-28', '2025-12-29', '2025-12-30', '2025-12-31',
        '2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05',
    ]);
    ptrBarStamp($user, 'pt', ['2026-01-20', '2026-01-21', '2026-01-22']);
}

/*
 | The country titles and ISO codes below are literals, and they are MEASURED,
 | not guessed: country('de')->getEmoji() . ' ' . country('de')->getName() is
 | "🇩🇪 Germany" and country('de')->getIsoAlpha2() is "DE" (same probe for pt and
 | es: "🇵🇹 Portugal"/"PT", "🇪🇸 Spain"/"ES"). Written out instead of derived
 | through country() in here, because an expectation built with the production
 | helper would agree with it by construction.
 */

// CASE 1 — THE CORE. Five consecutive days of one country are ONE bar. Days
// stamped: 02., 03., 04., 05., 06.03.2026. Hand-computed from the spec:
// start = the first day = 2026-03-02, end = the LAST day + 1 = 2026-03-07.
// (The same range the coordinator measured in the browser as one node of 319 px
// = 4,98 cells with start=true/end=true.)
test('five consecutive days of one country become one bar', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ptrBarStamp($user, 'de', ['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06']);

    $component = Volt::test('calendar')->set('currentYear', 2026);

    expect(ptrBarsFrom($component))->toBe([
        ['title' => '🇩🇪 Germany', 'country' => 'DE', 'start' => '2026-03-02', 'end' => '2026-03-07'],
    ]);

    // A LIST, not a map keyed by country or title. Not cosmetics: a keyed PHP
    // array serialises to a JS object, and nostrCal.js maps over the entangled
    // payload — .map() on an object throws, i.e. the whole calendar would fail
    // to initialise. Asserted on the raw property, since ptrBarsFrom() above
    // re-indexes as a side effect of sorting.
    expect(array_is_list($component->get('eventBars')))->toBeTrue();
});

// CASE 2 — SINGLE DAYS, where a missing +1 makes start === end and FullCalendar
// renders NOTHING. Three of them, so that the +1 is more than "increment the day
// number":
//   DE 20.05.2026 -> end 2026-05-21   (the plain case)
//   PT 28.02.2026 -> end 2026-03-01   (2026 is not a leap year: 2026 / 4 = 506,5,
//                                      so 28 Feb is followed by 1 March)
//   ES 31.12.2026 -> end 2027-01-01   (the +1 leaves the displayed year)
// Sorted by start, the order asserted below is PT, DE, ES.
test('a single tracked day becomes a bar one day long', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ptrBarStamp($user, 'de', ['2026-05-20']);
    ptrBarStamp($user, 'pt', ['2026-02-28']);
    ptrBarStamp($user, 'es', ['2026-12-31']);

    $bars = ptrBarsFrom(Volt::test('calendar')->set('currentYear', 2026));

    expect($bars)->toBe([
        ['title' => '🇵🇹 Portugal', 'country' => 'PT', 'start' => '2026-02-28', 'end' => '2026-03-01'],
        ['title' => '🇩🇪 Germany', 'country' => 'DE', 'start' => '2026-05-20', 'end' => '2026-05-21'],
        ['title' => '🇪🇸 Spain', 'country' => 'ES', 'start' => '2026-12-31', 'end' => '2027-01-01'],
    ]);

    // The same defect stated as the property it destroys, so the failure names
    // the consequence and not only a wrong date: an exclusive end equal to the
    // start is an empty range, and an empty range is an invisible bar.
    foreach ($bars as $bar) {
        expect($bar['end'])->not->toBe($bar['start']);
    }
});

// CASE 3 — A GAP BREAKS THE BAR. DE on 09., 10., 11.04.2026, then NOTHING on
// 12. and 13., then 14., 15.04.2026. Hand-computed: two bars,
//   2026-04-09 -> end 11 + 1 = 2026-04-12
//   2026-04-14 -> end 15 + 1 = 2026-04-16
// The two untracked days are exactly the room between 2026-04-12 (exclusive end
// of the first) and 2026-04-14 (start of the second) — so this case also pins
// that the +1 does NOT swallow the gap: an end of 2026-04-14 would render the
// 12th and 13th as part of the stay, which is a wrong fact on screen.
test('an untracked day in between breaks one stay into two bars', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ptrBarStamp($user, 'de', ['2026-04-09', '2026-04-10', '2026-04-11', '2026-04-14', '2026-04-15']);

    $bars = ptrBarsFrom(Volt::test('calendar')->set('currentYear', 2026));

    expect($bars)->toBe([
        ['title' => '🇩🇪 Germany', 'country' => 'DE', 'start' => '2026-04-09', 'end' => '2026-04-12'],
        ['title' => '🇩🇪 Germany', 'country' => 'DE', 'start' => '2026-04-14', 'end' => '2026-04-16'],
    ])
        // Stated separately, because "two bars" is the whole point of the case
        // and a whole-array diff of a single merged bar would not say it.
        ->and($bars)->toHaveCount(2);
});

// CASE 4 — A COUNTRY CHANGE ON CONSECUTIVE DAYS. DE on 01.-03.06.2026, PT on
// 04.-06.06.2026, no untracked day between them. Hand-computed:
//   DE 2026-06-01 -> end 03 + 1 = 2026-06-04
//   PT 2026-06-04 -> end 06 + 1 = 2026-06-07
// With an exclusive end the two bars TOUCH: the end of the first IS the start of
// the second. Both failure directions are caught by that one equality — a
// missing +1 gives DE an end of 2026-06-03 and leaves a one-day hole on screen
// where the traveller in fact was somewhere; a +2 gives 2026-06-05 and claims
// two countries on the 4th, which the day model forbids.
test('a country change on consecutive days yields two bars that touch exactly', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ptrBarStamp($user, 'de', ['2026-06-01', '2026-06-02', '2026-06-03']);
    ptrBarStamp($user, 'pt', ['2026-06-04', '2026-06-05', '2026-06-06']);

    $bars = ptrBarsFrom(Volt::test('calendar')->set('currentYear', 2026));

    expect($bars)->toBe([
        ['title' => '🇩🇪 Germany', 'country' => 'DE', 'start' => '2026-06-01', 'end' => '2026-06-04'],
        ['title' => '🇵🇹 Portugal', 'country' => 'PT', 'start' => '2026-06-04', 'end' => '2026-06-07'],
    ]);

    // No overlap AND no hole, in one statement: with an exclusive end, "the day
    // after the German stay is the first Portuguese day" is an equality.
    expect($bars[0]['end'])->toBe($bars[1]['start'])
        ->and($bars[0]['end'])->toBe('2026-06-04');
});

// CASE 5 — ACROSS NEW YEAR, THE TRUE RANGE. The nine-day DE stay
// 28.-31.12.2025 + 01.-05.01.2026. Hand-computed: start = 2025-12-28,
// end = 05.01.2026 + 1 = 2026-01-06 — the same range the coordinator measured in
// the browser, where FullCalendar rendered its visible part in the 2026 grid as
// 3,98 + 0,98 cells with fc-event-start/end BOTH false at the cut.
//
// Asserted from BOTH year views with the same fixture, because clipping has two
// sides: a bar clipped to 2026 would start on 2026-01-01, one clipped to 2025
// would end on 2026-01-01, and a single-year test would only ever see one of
// them. The PT bar of the fixture is included in the 2026 expectation so the
// case also shows that only the CROSSING run keeps a foot outside the year.
//
// Measured against a mutated throwaway simulation of the projection (which did
// not exist yet): this is the ONLY one of the nine cases that failed when the
// bars were clipped to the displayed year, and the only one that failed when
// runs from outside the year leaked into the view — there the 2025 expectation
// caught the extra PT bar, which the consistency invariant of case 8 cannot see,
// because a bar outside the year contributes zero in-year days to it.
test('a stay across New Year carries its true range in both year views', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    ptrBarCrossYearFixture($user);

    $de = ['title' => '🇩🇪 Germany', 'country' => 'DE', 'start' => '2025-12-28', 'end' => '2026-01-06'];

    // Seen from 2026: the bar reaches BACK into the previous year.
    expect(ptrBarsFrom(Volt::test('calendar')->set('currentYear', 2026)))->toBe([
        $de,
        ['title' => '🇵🇹 Portugal', 'country' => 'PT', 'start' => '2026-01-20', 'end' => '2026-01-23'],
    ]);

    // Seen from 2025: the identical bar, reaching FORWARD into the next year.
    // The PT stay does not intersect 2025 and is therefore absent — the whole
    // list is compared, so its absence is part of the assertion.
    expect(ptrBarsFrom(Volt::test('calendar')->set('currentYear', 2025)))->toBe([$de]);
});

// CASE 6 — WHOLLY OUTSIDE THE DISPLAYED YEAR, NOT LISTED. Runs are built over
// the whole history (spec point 1), which is exactly what could make history
// leak into the grid. DE 01.-05.07.2024 and PT 01.-03.08.2024 do not touch 2026;
// DE 01.-03.05.2026 does. Hand-computed for the 2026 view: exactly ONE bar,
// 2026-05-01 -> end 03 + 1 = 2026-05-04. Portugal exists in the database but
// gets no bar at all. The whole list is compared, so both absences are asserted.
test('a stay wholly outside the displayed year produces no bar', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ptrBarStamp($user, 'de', ['2024-07-01', '2024-07-02', '2024-07-03', '2024-07-04', '2024-07-05']);
    ptrBarStamp($user, 'pt', ['2024-08-01', '2024-08-02', '2024-08-03']);
    ptrBarStamp($user, 'de', ['2026-05-01', '2026-05-02', '2026-05-03']);

    expect(ptrBarsFrom(Volt::test('calendar')->set('currentYear', 2026)))->toBe([
        ['title' => '🇩🇪 Germany', 'country' => 'DE', 'start' => '2026-05-01', 'end' => '2026-05-04'],
    ]);
});

// CASE 7 — THE COUNTRY TRAVELS PER BAR, with two countries in one view, because
// a single-country view would pass with a hard-wired code. DE 01.-02.07.2026,
// PT 10.-11.07.2026. Hand-computed: DE 2026-07-01 -> 2026-07-03,
// PT 2026-07-10 -> 2026-07-12.
//
// THE SPELLING IS PINNED AGAINST THE DAY-WISE CHANNEL, not against a literal
// alone: spec point 5 asks for "the same spelling as the day-wise path", and
// that path is $this->events, whose `country` is
// country($event->country)->getIsoAlpha2(). Measured at 8dd30f4: that is
// UPPER case, "DE" — while the parenthesis of the specification described
// saveDays()' str($country)->lower(), which is the spelling of the DATABASE
// COLUMN and never reaches the client. So the literal below is the measured one
// and the cross-channel comparison is what carries the RULE: whatever the
// day-wise path spells, the bars spell the same, and P1's ISO code in the bar
// cannot end up in a different case than the one on the day chips.
test('every bar carries its own country in the day-wise spelling', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ptrBarStamp($user, 'de', ['2026-07-01', '2026-07-02']);
    ptrBarStamp($user, 'pt', ['2026-07-10', '2026-07-11']);

    $component = Volt::test('calendar')->set('currentYear', 2026);
    $bars = ptrBarsFrom($component);

    expect($bars)->toBe([
        ['title' => '🇩🇪 Germany', 'country' => 'DE', 'start' => '2026-07-01', 'end' => '2026-07-03'],
        ['title' => '🇵🇹 Portugal', 'country' => 'PT', 'start' => '2026-07-10', 'end' => '2026-07-12'],
    ]);

    // The rule itself: the set of (country, title) pairs of the bars is the set
    // the day-wise events carry — same values, same spelling, same pairing.
    $fromEvents = collect($component->get('events'))
        ->map(fn ($event) => [$event['country'], $event['title']])
        ->unique()->sort()->values()->all();

    $fromBars = collect($bars)
        ->map(fn ($bar) => [$bar['country'], $bar['title']])
        ->unique()->sort()->values()->all();

    expect($fromBars)->toBe($fromEvents)
        ->and($fromBars)->toBe([['DE', '🇩🇪 Germany'], ['PT', '🇵🇹 Portugal']]);
});

// CASE 8 — THE CONSISTENCY INVARIANT: the number of bar days that fall INSIDE
// the displayed year equals the number of day-wise `events` entries. The bars
// are the new channel, the events the pinned old one, so the two sides of the
// equation are independent.
//
// Fixture: DE 28.12.2025-05.01.2026 (the 9-day cross-year run), PT
// 20.-22.01.2026 (3 days, wholly in 2026), ES 10.-12.11.2025 (3 days, wholly in
// 2025). Hand-counted:
//   view 2026: events = 5 DE + 3 PT = 8. Bar days in 2026 = 01.-05.01. of the DE
//              bar (5 — its four December days fall in 2025) + 20.-22.01. of the
//              PT bar (3) = 8. The ES run does not intersect 2026.
//   view 2025: events = 4 DE + 3 ES = 7. Bar days in 2025 = 28.-31.12. of the DE
//              bar (4) + 10.-12.11. of the ES bar (3) = 7. The PT run does not
//              intersect 2025.
//
// WHY A THIRD COUNTRY IN 2025, and this is measured rather than reasoned — the
// projection did not exist yet, so a throwaway simulation of it was mutated
// instead (scratchpad script, four mutations): the sum only reacts to the +1
// where a run's LAST day lies inside the displayed year. With the plain
// cross-year fixture the 2025 half of this case survived BOTH a missing and a
// doubled +1, because there the day at stake lies in 2026 and an out-of-year day
// is not counted by construction. The ES run ends inside 2025 and restores the
// teeth: with the +1 missing it contributes 2 days instead of 3 and the sum is 6
// against 7 events.
//
// Contrary to what "trivial within one year" would suggest, the within-year runs
// carry weight too: with the +1 missing, the 3-day PT bar covers 2 days and the
// 2026 sum drops to 6 against 8 events.
//
// WHAT THIS CASE CANNOT PROVE, so nobody reads more into it — both measured on
// the same mutated simulation: a bar CLIPPED to the year satisfies it (clipping
// only removes out-of-year days, which this sum ignores), and so does a run that
// leaks in from OUTSIDE the year (it contributes zero in-year days). Both are
// the business of case 5, whose whole-list comparison failed on exactly those
// two mutations.
// What it does catch besides the +1, and what it is really here for: `events`
// ceasing to be day-wise. Turn that array into ranges and the right-hand side
// collapses to 2 and 2 while the left stays 8 and 7.
test('the bar days inside the year equal the number of day-wise events', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    ptrBarCrossYearFixture($user);
    ptrBarStamp($user, 'es', ['2025-11-10', '2025-11-11', '2025-11-12']);

    $view2026 = Volt::test('calendar')->set('currentYear', 2026);
    $events2026 = $view2026->get('events');

    // The hand count first, so a changed fixture cannot silently redefine the
    // invariant; the comparison second, which is the invariant proper and holds
    // whatever the fixture is.
    expect($events2026)->toHaveCount(8)
        ->and(ptrBarDaysInYear(ptrBarsFrom($view2026), 2026))->toBe(8)
        ->and(ptrBarDaysInYear(ptrBarsFrom($view2026), 2026))->toBe(count($events2026));

    $view2025 = Volt::test('calendar')->set('currentYear', 2025);
    $events2025 = $view2025->get('events');

    expect($events2025)->toHaveCount(7)
        ->and(ptrBarDaysInYear(ptrBarsFrom($view2025), 2025))->toBe(7)
        ->and(ptrBarDaysInYear(ptrBarsFrom($view2025), 2025))->toBe(count($events2025));
});

// CASE 9 — THE CALIBRATION UPWARDS: what may NOT change. This case was GREEN at
// 8dd30f4 and has to stay green — the bars are an additional channel, and
// $this->events keeps exactly one entry per tracked day of the displayed year,
// with the keys it has today. Every counting path depends on it: the modal
// preview getters build their day->country map from it
// (resources/js/nostrCal.js: byDay.set(String(event.start).slice(0, 10), …)),
// as do the docket and the P3 chips. Were `events` replaced by ranges, nearly
// every day in the docket would suddenly read "no country".
//
// Same fixture as case 8. Hand-counted: 8 entries in the 2026 view (5 DE +
// 3 PT), 4 in the 2025 view, one per tracked day and no date from the other
// year.
test('the day-wise events array keeps one entry per tracked day of the year', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    ptrBarCrossYearFixture($user);

    $events = Volt::test('calendar')->set('currentYear', 2026)->get('events');

    expect($events)->toBeArray()->toHaveCount(8);

    foreach ($events as $event) {
        // The keys themselves, sorted so that their ORDER stays free — the three
        // names are the interface, the order they are written in is not.
        $keys = array_keys($event);
        sort($keys);

        expect($keys)->toBe(['country', 'start', 'title']);
    }

    // One entry per day, no duplicates, no day from the neighbouring year: the
    // exact set of days, compared as a whole.
    expect(collect($events)->pluck('start')->sort()->values()->all())->toBe([
        '2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05',
        '2026-01-20', '2026-01-21', '2026-01-22',
    ]);

    // And the values a day entry carries, spot-checked on both countries: ISO
    // code as the day-wise path spells it, plus emoji and name in the title.
    expect(collect($events)->firstWhere('start', '2026-01-02'))
        ->toBe(['country' => 'DE', 'title' => '🇩🇪 Germany', 'start' => '2026-01-02'])
        ->and(collect($events)->firstWhere('start', '2026-01-21'))
        ->toBe(['country' => 'PT', 'title' => '🇵🇹 Portugal', 'start' => '2026-01-21']);

    // The other side of the year boundary, the same statement: the four December
    // days and nothing from 2026.
    $events2025 = Volt::test('calendar')->set('currentYear', 2025)->get('events');

    expect($events2025)->toHaveCount(4)
        ->and(collect($events2025)->pluck('start')->sort()->values()->all())
        ->toBe(['2025-12-28', '2025-12-29', '2025-12-30', '2025-12-31']);
});
