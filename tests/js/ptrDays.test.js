/*
 | The safety net under resources/js/ptrDays.js — the ONE derivation of "which
 | days did the user mark". One record is one day is one country, so a day off
 | here is a wrong residency day in the user's Nostr data. That is the whole
 | product value, hence this file.
 |
 | node --test, no dependency: `node tests/js/zones.mjs` (all zones) or
 | `node --test tests/js/` (the ambient zone only).
 |
 | HOW THE EXPECTATIONS WERE DERIVED — read this before changing one.
 | From the SPECIFICATION, never from the function's output: the user drags
 | 14.-16.03. in the grid, FullCalendar reports (startStr '2026-03-14',
 | endStr '2026-03-17', end exclusive), so the days to store are 03-14, 03-15,
 | 03-16 — in Berlin, in New York, in Kiritimati alike. A calendar date is not
 | an instant; the zone the user happens to sit in must not shift it. Every
 | `days:` array below is written out by hand for that reason.
 |
 | The zone is the AMBIENT zone (process TZ), on purpose: the run itself becomes
 | the report for that one zone, and tests/js/zones.mjs is what turns a single
 | report into coverage. The code under test is zone-INDEPENDENT today (measured
 | byte-identical across all 11 zones) — the matrix is not probing a known
 | dependency, it is what keeps that property true, because one `new Date(iso)`
 | slipped into the walk brings both defects below straight back.
 |
 | CURRENT STATE: green in every zone of tests/js/zones.mjs (11/11 as of
 | 2026-08-11, after the fix). A red run is therefore a REGRESSION, never an
 | expected result — do not read one as "known".
 |
 | HISTORY, and the reason this file exists in the shape it has. When it was
 | written, on 2026-08-11 BEFORE the fix, it pinned two defects in the day
 | derivation and the run over the then nine zones looked like this:
 |
 |   PASS  UTC                  pass 68, fail 0
 |   FAIL  Europe/Berlin        pass 63, fail 5     defect 2
 |   PASS  Asia/Kolkata         pass 68, fail 0
 |   FAIL  Pacific/Auckland     pass 66, fail 2     defect 2
 |   PASS  Pacific/Kiritimati   pass 68, fail 0
 |   FAIL  America/New_York     pass 50, fail 18    defects 1 + 2
 |   FAIL  America/Los_Angeles  pass 50, fail 18    defects 1 + 2
 |   FAIL  Pacific/Niue         pass 53, fail 15    defect 1
 |   FAIL  Etc/GMT+12           pass 53, fail 15    defect 1
 |
 |   defect 1 — west of UTC the whole list shifted one day back
 |   defect 2 — a DST fall-back swallowed the last day of the range, and a
 |              single tap on the fall-back day itself wrote nothing at all
 |
 | Both were fixed on 2026-08-11; since then every zone is green. Keep the table:
 | it says which zones were BLIND to those defects — UTC, Asia/Kolkata,
 | Pacific/Kiritimati. A developer in Europe running only the ambient suite
 | outside October, or a CI box in UTC, would have seen nothing. That is why the
 | net is the zone matrix in tests/js/zones.mjs and not a single run.
 */
import {describe, it} from 'node:test';
import assert from 'node:assert/strict';
import {rangeDays} from '../../resources/js/ptrDays.js';

const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

/*
 | Independent successor, deliberately NOT the algorithm under test: pure UTC
 | arithmetic on the split components, no local getters anywhere. Used only for
 | the contiguity invariant, so that invariant cannot confirm itself.
 */
const nextCalendarDay = (iso) => {
    const [y, m, d] = iso.split('-').map(Number);
    const next = new Date(Date.UTC(y, m - 1, d) + 86_400_000);
    const pad = (n) => String(n).padStart(2, '0');

    // The year is padded to FOUR digits. It was not, and the year-0100 case below
    // caught it: this helper claimed '0100-01-01' is followed by '100-01-02' and
    // failed a correct implementation. The oracle carried the same unpadded-year
    // bug as the code it was written to check — which is the argument for having
    // a case down there at all.
    return `${String(next.getUTCFullYear()).padStart(4, '0')}-${pad(next.getUTCMonth() + 1)}-${pad(next.getUTCDate())}`;
};

/*
 | start/end are what FullCalendar hands over (`endStr` exclusive); `days` is
 | what the user marked and therefore what has to be stored.
 */
const cases = [
    {
        name: 'a three-day drag stores exactly those three days',
        start: '2026-03-14',
        end: '2026-03-17',
        days: ['2026-03-14', '2026-03-15', '2026-03-16'],
    },
    {
        name: 'a single tap (dateClick: start, start+1) stores exactly that one day',
        start: '2026-03-14',
        end: '2026-03-15',
        days: ['2026-03-14'],
    },
    {
        name: 'a range across the US spring-forward (2026-03-08) keeps all three days',
        start: '2026-03-08',
        end: '2026-03-11',
        days: ['2026-03-08', '2026-03-09', '2026-03-10'],
    },
    {
        name: 'a range across the EU spring-forward (2026-03-29) keeps all three days',
        start: '2026-03-29',
        end: '2026-04-01',
        days: ['2026-03-29', '2026-03-30', '2026-03-31'],
    },
    {
        name: 'a range across the US fall-back (2026-11-01) keeps all three days',
        start: '2026-11-01',
        end: '2026-11-04',
        days: ['2026-11-01', '2026-11-02', '2026-11-03'],
    },
    {
        name: 'a range across the EU fall-back (2026-10-25) keeps all three days',
        start: '2026-10-25',
        end: '2026-10-28',
        days: ['2026-10-25', '2026-10-26', '2026-10-27'],
    },
    {
        name: 'a range across the southern-hemisphere fall-back (2026-04-05 NZ) keeps all three days',
        start: '2026-04-04',
        end: '2026-04-07',
        days: ['2026-04-04', '2026-04-05', '2026-04-06'],
    },
    /*
     | The MIDNIGHT class, and the only reason America/Santiago is in the zone
     | matrix at all. Santiago switches at 24:00 local, Havana at 00:00, so
     | local midnight does not EXIST on their transition day — and a derivation
     | that builds the day with `new Date(y, m - 1, d)` gets it normalised
     | forwards to 01:00 and then loses the last day of the range. That recipe
     | passes all NINE original zones of the matrix (measured 2026-08-11) and is
     | wrong here, which is exactly why this case exists.
     |
     | Without it Santiago was a passenger: every assertion in the suite ran
     | green without ever touching its transition. Its affected windows are September-only (2026-09-01..06,
     | 2027-08-31..09-05, 2028-08-29..09-03) and no other case starts there.
     | Measured against the local recipe: red under Santiago WITH this case,
     | green under all nine original zones — so the case, not the zone entry, is
     | what carries the coverage.
     */
    {
        name: 'a range across the Chile spring-forward at 24:00 (2026-09-06) keeps all three days',
        start: '2026-09-05',
        end: '2026-09-08',
        days: ['2026-09-05', '2026-09-06', '2026-09-07'],
    },
    /*
     | The single tap that silently wrote NOTHING — the sharpest form of defect 2
     | and the one that loses user data outright: the user taps the day, picks a
     | country, the modal closes, and no record exists. Measured 2026-08-11 on
     | the pre-fix code, one such day per zone and year:
     |
     |   Europe/Berlin      ('2026-10-25','2026-10-26') -> []
     |   America/New_York   ('2026-11-01','2026-11-02') -> []
     |   Pacific/Auckland   ('2026-04-04','2026-04-05') -> []
     |
     | Each case names the day that was MEASURED for that zone, because there is
     | no rule to name instead. "The fall-back day" is right for Berlin and New
     | York and wrong for Auckland, whose fall-back is 2026-04-05 (+13 -> +12 off
     | the tz data) while the losing day is 04-04; Santiago loses the day AFTER
     | its transition; Athens and Jerusalem share the offset +2 and differ from
     | each other. Two attempts at a one-line rule were measured wrong, so a new
     | zone gets a new measurement, not an extrapolation — and checking a zone on
     | its transition day alone can make the defect look refuted.
     */
    {
        name: 'a single tap on the EU fall-back day (2026-10-25) stores that day',
        start: '2026-10-25',
        end: '2026-10-26',
        days: ['2026-10-25'],
    },
    {
        name: 'a single tap on the US fall-back day (2026-11-01) stores that day',
        start: '2026-11-01',
        end: '2026-11-02',
        days: ['2026-11-01'],
    },
    {
        name: 'a single tap on the day before the NZ fall-back (2026-04-04) stores that day',
        start: '2026-04-04',
        end: '2026-04-05',
        days: ['2026-04-04'],
    },
    {
        name: 'a range across new year spans both years',
        start: '2026-12-31',
        end: '2027-01-02',
        days: ['2026-12-31', '2027-01-01'],
    },
    {
        name: 'a range across the leap day 2028-02-29 includes it',
        start: '2028-02-28',
        end: '2028-03-01',
        days: ['2028-02-28', '2028-02-29'],
    },
    {
        name: 'the same range in a non-leap year ends on the 28th',
        start: '2027-02-28',
        end: '2027-03-01',
        days: ['2027-02-28'],
    },
    {
        name: 'a range across a month boundary rolls over',
        start: '2026-01-30',
        end: '2026-02-02',
        days: ['2026-01-30', '2026-01-31', '2026-02-01'],
    },
    {
        name: 'a range across a 30-day month end rolls over',
        start: '2026-04-29',
        end: '2026-05-02',
        days: ['2026-04-29', '2026-04-30', '2026-05-01'],
    },
    /*
     | The low end of the accepted year range, and it is here because a guard
     | broke it once: rendering the year unpadded made every year under 1000 fail
     | its own round trip, so 0100-0999 returned [] (measured 2026-08-11, 657 434
     | previously correct inputs). Unreachable by hand — it takes roughly a
     | thousand prev-clicks — but the failure mode was a silent write of nothing,
     | which is the very defect this file exists to keep out.
     */
    {
        name: 'a range in year 0100 is a calendar range like any other',
        start: '0100-01-01',
        end: '0100-01-03',
        days: ['0100-01-01', '0100-01-02'],
    },
    {
        name: 'a range across the 0999/1000 year boundary keeps both days',
        start: '0999-12-31',
        end: '1000-01-02',
        days: ['0999-12-31', '1000-01-01'],
    },
    {
        name: 'a full week stores seven days',
        start: '2026-06-01',
        end: '2026-06-08',
        days: [
            '2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04',
            '2026-06-05', '2026-06-06', '2026-06-07',
        ],
    },
];

describe(`rangeDays() under TZ=${zone}`, () => {
    for (const {name, start, end, days} of cases) {
        describe(`${name} — (${start}, ${end})`, () => {
            it('returns exactly the marked days', () => {
                assert.deepEqual(rangeDays(start, end), days);
            });

            /*
             | The count is asserted apart from the list on purpose: a shifted
             | list is a wrong day, a wrong COUNT is a swallowed or duplicated
             | day — that one also breaks the day counter above the calendar and
             | every residency limit derived from it. Stepping a local Date over
             | a DST transition is exactly how that happens.
             */
            it(`returns ${days.length} day(s), no day swallowed or duplicated`, () => {
                const actual = rangeDays(start, end);
                assert.equal(actual.length, days.length);
                assert.equal(new Set(actual).size, actual.length);
            });

            it('returns strictly consecutive calendar days', () => {
                const actual = rangeDays(start, end);
                assert.ok(actual.length > 0, 'a marked range is never empty');

                for (let i = 1; i < actual.length; i++) {
                    assert.equal(
                        actual[i],
                        nextCalendarDay(actual[i - 1]),
                        `${actual[i - 1]} is not followed by ${actual[i]}`,
                    );
                }
            });

            it('returns ISO dates only', () => {
                for (const iso of rangeDays(start, end)) {
                    assert.match(iso, /^\d{4}-\d{2}-\d{2}$/);
                }
            });
        });
    }

    /*
     | The Alpine component initialises newEventStart/newEventEnd to `false` and
     | the getters read rangeDays() on every render, so this path runs before
     | the user has touched anything. It must not produce a day — a phantom day
     | here would be written to the user's data by the very next click.
     */
    describe('nothing marked', () => {
        const falsy = [
            ['false / false', false, false],
            ['start only', '2026-03-14', false],
            ['end only', false, '2026-03-17'],
            ['empty strings', '', ''],
            ['null', null, null],
            ['undefined', undefined, undefined],
        ];

        for (const [name, start, end] of falsy) {
            it(`${name} yields no day`, () => {
                assert.deepEqual(rangeDays(start, end), []);
            });
        }

        it('no arguments at all yields no day', () => {
            assert.deepEqual(rangeDays(), []);
        });

        /*
         | end is EXCLUSIVE, so end === start marks zero days. Nothing in the UI
         | produces it today, but it must never invent a day: writing one would
         | stamp a country on a day the user never touched.
         */
        it('an empty range (end === start) yields no day', () => {
            assert.deepEqual(rangeDays('2026-03-14', '2026-03-14'), []);
        });
    });

    /*
     | Not a calendar date -> not a day. Date.UTC() normalises instead of
     | rejecting, so every one of these produced INVENTED days before the guard
     | round-tripped the string (measured 2026-08-11, values in the comments).
     | Unreachable from today's UI — FullCalendar hands over padded, date-only
     | strings — so this block guards the contract for the next caller, and the
     | direction is the point: a phantom day here gets stamped onto the user's
     | residency data by the very next click.
     */
    describe('not a calendar date', () => {
        const invalid = [
            ['month 13 and day 40', '2026-13-40', '2026-13-43'],   // was 2027-02-09..11
            ['month 0 and day 0', '2026-00-00', '2026-00-03'],     // was 2025-11-30..12-02
            ['year 1, which Date.UTC moves to 1901', '0001-01-01', '0001-01-04'], // was 1901-01-01..03
            ['an unpadded date', '2026-3-4', '2026-3-6'],
            ['the 30th of February', '2026-02-30', '2026-03-04'],
            ['a date with a time part', '2026-03-14T00:00:00', '2026-03-17'],
            ['a UTC instant', '2026-03-14T00:00:00Z', '2026-03-17'],
            ['an offset instant', '2026-03-14T23:30:00-05:00', '2026-03-17'],
            ['a valid start with a broken end', '2026-03-14', '2026-03-99'],
            ['a Date object instead of a string', new Date(Date.UTC(2026, 2, 14)), '2026-03-17'],
            ['an epoch number', Date.UTC(2026, 2, 14), '2026-03-17'],
        ];

        for (const [name, start, end] of invalid) {
            it(`${name} yields no day`, () => {
                assert.deepEqual(rangeDays(start, end), []);
            });
        }
    });
});
