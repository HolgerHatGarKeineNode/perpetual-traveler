/*
 | The safety net under resizeDelta() in resources/js/ptrDays.js — the return
 | path of "correct a stay by dragging its edge" (P5).
 |
 | THE SPECIFICATION THIS FILE PINS
 | --------------------------------
 |   resizeDelta(oldStart, oldEndExclusive, newStart, newEndExclusive)
 |       -> {added: string[], removed: string[]}
 |
 |   Both lists are 'YYYY-MM-DD', ASCENDING, and they are the two write
 |   instructions of a resize: `added` goes to saveDays(), `removed` to
 |   deleteDays(). A resize moves exactly one edge, so in practice one of the two
 |   is empty — but both directions are specified and both are pinned here,
 |   because a caller relies on both and a test that checks one covers nothing
 |   about the other.
 |
 |   added   = the days the new range covers that the old one did not
 |   removed = the days the old range covered that the new one does not
 |   neither = every day BOTH ranges cover. An unchanged day must not be
 |             re-stamped and must above all not be deleted.
 |
 | WHY EVERY CASE ALSO ASSERTS THE COUNT AND THE RECONSTRUCTION
 | -----------------------------------------------------------
 | One record is one day is one country. A day too many in `removed` erases a
 | residency day the traveller really had; a day too few in `added` loses one he
 | had. Either way the YEAR TOTAL is wrong, and the year total is the number the
 | whole product exists to get right — this is not a display glitch that the next
 | render corrects. The sharpest form is the edge that shrinks: `removed` must
 | stop one day short of the last remaining day, and if it does not, the stay
 | loses a day the user never touched.
 |
 | THE FAIL-CLOSED DIRECTION, AND WHY IT IS NOT THE OBVIOUS ONE
 | ------------------------------------------------------------
 | rangeDays() is fail-closed: anything that is not a padded calendar date, and
 | anything falsy, yields []. That is the right answer THERE, and it does not
 | carry over unchanged here, because a plain set difference over two rangeDays()
 | results turns that [] into an INSTRUCTION:
 |
 |   new range unusable -> after = []  -> removed = every day of the stay
 |   old range unusable -> before = [] -> added   = every day of the new range
 |
 | The first deletes a whole stay because of one malformed string, the second
 | writes one out of nothing. Both are fail-OPEN in exactly the direction that
 | costs data. And there is no way to tell those cases apart downstream: [] means
 | "nothing marked" and "garbage" alike, so an empty day list carries no intent
 | at all, and reading intent into it is a guess about the user's data.
 |
 | Hence the derived contract, and the reason it is asserted for all four
 | argument slots separately: if ANY of the four does not resolve to a usable
 | range, the answer is no instruction — {added: [], removed: []}. A caller that
 | really means "drop this stay entirely" has to say so; deleting is what the
 | modal's own delete path is for. Unreachable from today's UI: a resize to zero
 | days is refused by FullCalendar without firing the callback (measured by the
 | coordinator in the browser), and startStr/endStr are always padded, date-only
 | strings. So this block guards the contract for the next caller, same as the
 | corresponding block in ptrDays.test.js.
 |
 | THE PATHOLOGICAL CASE, on purpose
 | ---------------------------------
 | Both edges moving in ONE call is not a resize — FullCalendar moves one edge
 | per eventResize, and a drag of the whole bar does not even reach the band
 | today (P4 put pointer-events: none on it; the coordinator measured `select`
 | firing instead of eventDrop, in both editable configurations). It is
 | constructible by hand, though, and the specification answers it without a
 | special case: added and removed are the two set differences, so BOTH come back
 | non-empty, and the overlap appears in neither. The cases below cover slide,
 | grow-both, shrink-both and fully disjoint. Grow-both carries something no
 | other case does: `added` spans a GAP (two days at the front, two at the back),
 | so it pins the ascending order across it — an implementation that concatenates
 | front and back additions in drag order returns the same days wrongly ordered.
 |
 | ZONE INDEPENDENCE, and exactly how much of it this file actually guards
 | ---------------------------------------------------------------------
 | Same invariant as rangeDays(): a calendar date is not an instant, so the
 | traveller's zone must not shift a single day. Not one local getter, not one
 | local constructor. This file therefore runs under every zone of
 | tests/js/zones.mjs, which since 2026-08-12 globs tests/js/*.test.js instead of
 | naming one file — otherwise a new test file would sit outside the matrix
 | without anything turning red.
 |
 | The DST cases below (2026-10-25, 2026-03-08, 2026-09-05) are measured, not
 | decorative, but the measurement is narrower than "they guard the walk" and the
 | difference matters, so here it is. Against the HISTORIC broken walk — inclusive
 | `last` built as a local Date, compared with `day <= last`, the shape that
 | swallowed the last day of a range before P8, with the fail-closed guard left
 | identical so only the walk differs (scratchpad, 2026-08-12, both files in the
 | matrix, so 187 per zone):
 |
 |   nine zones          187 pass, 0 fail   let it through
 |   America/Havana      182 pass, 5 fail   caught: 2026-03-08, grow-both,
 |                                          shrink-both, the 97-day case
 |   America/Santiago    185 pass, 2 fail   caught: the Chile case
 |
 | So the two midnight-transition zones carry it here just as they do in
 | ptrDays.test.js, and two cases are load-bearing for that: the Chile case is the
 | ONLY thing that makes Santiago bite, and 2026-03-08 is what Havana needs
 | (grow-both, shrink-both and the 97-day case happen to span it too). Delete
 | either and a zone goes quiet without a single test turning red. The
 | reconstruction invariant fired in both zones as well, which is the clearest
 | argument for having kept it as a property over the whole table.
 |
 | What is NOT claimed: the EU fall-back cases did not catch that variant in
 | Europe/Berlin, and a walk written with an EXCLUSIVE end and `day < last` is
 | correct in all 11 zones (measured: 0 disagreements with rangeDays() over 7623
 | spans per zone, lengths 1-7 across 2026-2028), so it passes here rightly. The
 | file that guards the day walk is ptrDays.test.js, which asserts the LISTS;
 | this file asserts a DIFFERENCE of two lists, and a difference is structurally
 | weaker — a defect that hits `before` and `after` the same way can cancel out
 | of it. That is the reason the zone matrix runs both files and not just this one.
 |
 | STATE WHEN THIS FILE WAS WRITTEN — 2026-08-12, and this paragraph is history,
 | not a standing exception. resources/js/ptrDays.js exported rangeDays() and
 | nothing else; there was no resizeDelta() and no caller for it (measured on
 | bda7949: `git grep -n resizeDelta` found nothing). The run was `tests 92,
 | pass 1, fail 91` — the one green test being the oracle self-check further
 | down, the only test in the file that does not call resizeDelta(). Once the
 | function exists this file is GREEN in every zone, and a red run then is a
 | REGRESSION — do not read one as "expected, the function is new".
 |
 | Because a run that is red for ONE reason proves nothing about the individual
 | expectations — all 91 failed on the same `undefined` — the calibration was
 | done against a throwaway simulation of the specification instead (scratchpad,
 | 2026-08-12). Spec-conformant: 92 pass, 0 fail. Then ONE boundary shifted by
 | ONE day at a time, with the fail-closed guard evaluated on the unmutated input
 | so that each mutation stays a pure arithmetic off-by-one instead of hiding
 | behind the guard. Measured, out of 92:
 |
 |   new end   - 1 day    34 failed    new end   + 1 day    33 failed
 |   new start + 1 day    34 failed    new start - 1 day    33 failed
 |   old end   - 1 day    34 failed    old end   + 1 day    33 failed
 |   old start + 1 day    34 failed    old start - 1 day    33 failed
 |
 | All four edges of the specification are covered, each with a diff that names
 | the day (verbatim from those runs):
 |
 |   end/added     new end -1     expected [03-07, 03-08], got [03-07]
 |   end/removed   new end -1     expected [03-03..03-06], got 03-02 as well
 |   start/added   new start +1   expected [03-09, 03-10], got [03-10]
 |   start/removed new start +1   expected [03-09..03-11], got 03-12 as well
 |
 | The second and fourth lines are the failure this phase exists to prevent: the
 | surviving day of a shrunk stay lands in `removed` and the stay loses a day the
 | user never touched.
 |
 | The guard has its own mutation — the plain set difference with no input check,
 | i.e. the fail-open implementation described above. It fails 22 of the 24
 | "not a usable range" cases. The two that stay green are `every argument is
 | falsy` and `no arguments at all`, and they CANNOT catch it: with both ranges
 | empty the set difference is empty too, so those two cases carry the falsy
 | contract and nothing about the guard. Naming that here rather than claiming
 | 24 teeth, because the number that matters is the one that was measured.
 |
 | All of the above is a statement about the SIMULATION: it says these
 | expectations can tell a one-day shift apart. It says nothing about whether the
 | delivered implementation is right — that is what the run says.
 |
 | node --test 'tests/js/*.test.js' (ambient zone, fast) or node tests/js/zones.mjs
 | (all 11 zones, and the only run that means anything).
 */
import {describe, it} from 'node:test';
import assert from 'node:assert/strict';
import * as ptrDays from '../../resources/js/ptrDays.js';

const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

/*
 | Namespace import plus optional call, and both halves are deliberate. The
 | namespace import does not fail at link time the way `import {resizeDelta}`
 | would, so a missing export produces one readable failure per case (`expected
 | [ '2026-03-07' ] got undefined`) instead of a single crash before any test
 | runs. The optional call masks nothing: undefined fails every expectation in
 | this file, including the ones that expect an empty list, and the export itself
 | is asserted separately below.
 */
const delta = (...args) => {
    const out = ptrDays.resizeDelta?.(...args);

    return {added: out?.added, removed: out?.removed};
};

const pad = (n) => String(n).padStart(2, '0');

/*
 | The oracle: which days a span covers, computed for the TEST side only and
 | deliberately by a different mechanism than the code under test. resizeDelta()
 | is specified to compose rangeDays(), so calling rangeDays() here would let the
 | invariants confirm the very composition they are meant to check. This walk
 | adds to the DAY COMPONENT and lets Date.UTC normalise, and it stops on a
 | STRING compare (ISO dates sort chronologically) rather than on an ordinal —
 | no local getter, no local constructor, so it is zone-independent too.
 |
 | Yes, this is a second derivation of "which days" — in test code that is the
 | point. The "one derivation" rule protects the write path, and an oracle that
 | shares its algorithm cannot contradict it.
 |
 | The cap is not decoration: it is only ever called with the spans in the table
 | below, and a hung test file is worse than a failing one.
 */
const daysOf = (startIso, endExclusiveIso) => {
    const [y, m, d] = startIso.split('-').map(Number);
    const days = [];

    for (let i = 0; i < 5000; i++) {
        const t = new Date(Date.UTC(y, m - 1, d + i));
        const iso = `${String(t.getUTCFullYear()).padStart(4, '0')}-${pad(t.getUTCMonth() + 1)}-${pad(t.getUTCDate())}`;

        if (iso >= endExclusiveIso) return days;

        days.push(iso);
    }

    throw new Error(`daysOf(${startIso}, ${endExclusiveIso}) did not terminate — bad span in the table`);
};

const ISO = /^\d{4}-\d{2}-\d{2}$/;

/*
 | old/new are the two (startStr, endStr) pairs FullCalendar hands over, end
 | EXCLUSIVE — info.oldEvent carries the state before the drag, info.event the
 | one after. added/removed are written out BY HAND from the specification, never
 | from a run: the day the drag gained or lost, counted off a calendar.
 |
 | The first three cases are the coordinator's browser measurements verbatim
 | (2026-08-12), so at least the pinned shape of the input is not invented:
 |   END handle   +2 cells  2026-03-02..2026-03-07 -> 2026-03-02..2026-03-09
 |   END handle   -2 cells  2026-03-02..2026-03-07 -> 2026-03-02..2026-03-05
 |   START handle -2 cells  2026-03-11..2026-03-12 -> 2026-03-09..2026-03-12
 */
const cases = [
    // MEASURED. old covers 03-02..03-06, new covers 03-02..03-08 -> 03-07, 03-08 are new.
    {
        name: 'the end handle pulled two cells out adds exactly those two days',
        old: ['2026-03-02', '2026-03-07'],
        new: ['2026-03-02', '2026-03-09'],
        added: ['2026-03-07', '2026-03-08'],
        removed: [],
    },
    // MEASURED. old covers 03-02..03-06, new covers 03-02..03-04 -> 03-05, 03-06 fall away.
    {
        name: 'the end handle pushed two cells in removes exactly those two days',
        old: ['2026-03-02', '2026-03-07'],
        new: ['2026-03-02', '2026-03-05'],
        added: [],
        removed: ['2026-03-05', '2026-03-06'],
    },
    // MEASURED. old covers 03-11 only, new covers 03-09..03-11 -> 03-09, 03-10 are new.
    {
        name: 'the start handle pulled two cells back adds exactly those two days',
        old: ['2026-03-11', '2026-03-12'],
        new: ['2026-03-09', '2026-03-12'],
        added: ['2026-03-09', '2026-03-10'],
        removed: [],
    },
    // The smallest real correction. old covers 03-02..03-06, new 03-02..03-07.
    {
        name: 'one day longer at the end adds one day',
        old: ['2026-03-02', '2026-03-07'],
        new: ['2026-03-02', '2026-03-08'],
        added: ['2026-03-07'],
        removed: [],
    },
    // old covers 03-11, 03-12; new covers 03-10..03-12.
    {
        name: 'one day earlier at the start adds one day',
        old: ['2026-03-11', '2026-03-13'],
        new: ['2026-03-10', '2026-03-13'],
        added: ['2026-03-10'],
        removed: [],
    },
    // old covers 06-01..06-03, new covers 06-01..06-08 -> five new days.
    {
        name: 'five days longer at the end adds five days, so the count is not only right at +-1',
        old: ['2026-06-01', '2026-06-04'],
        new: ['2026-06-01', '2026-06-09'],
        added: ['2026-06-04', '2026-06-05', '2026-06-06', '2026-06-07', '2026-06-08'],
        removed: [],
    },
    // old covers 06-01, new covers 06-01..06-07 -> six new days after a single-day stay.
    {
        name: 'a single-day stay stretched to a week adds the other six days',
        old: ['2026-06-01', '2026-06-02'],
        new: ['2026-06-01', '2026-06-08'],
        added: ['2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-06', '2026-06-07'],
        removed: [],
    },
    // old covers 03-09..03-12, new covers 03-11, 03-12 -> 03-09, 03-10 fall away.
    {
        name: 'the start handle pushed two cells forward removes exactly those two days',
        old: ['2026-03-09', '2026-03-13'],
        new: ['2026-03-11', '2026-03-13'],
        added: [],
        removed: ['2026-03-09', '2026-03-10'],
    },
    /*
     | THE OFF-BY-ONE THAT COSTS A DAY OF THE YEAR TOTAL, from the end: the stay
     | shrinks to its first day. old covers 03-02..03-06, new covers 03-02 alone,
     | so 03-03..03-06 go — four days — and 03-02 is in NEITHER list. A `removed`
     | that walks from the new END instead of the new end-exclusive takes 03-02
     | with it and the stay disappears entirely.
     */
    {
        name: 'shrunk from the end to a single day, the surviving first day is not removed',
        old: ['2026-03-02', '2026-03-07'],
        new: ['2026-03-02', '2026-03-03'],
        added: [],
        removed: ['2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06'],
    },
    /*
     | The same off-by-one from the front. old covers 03-09..03-12, new covers
     | 03-12 alone -> 03-09, 03-10, 03-11 go, three days, and 03-12 survives in
     | neither list.
     */
    {
        name: 'shrunk from the start to a single day, the surviving last day is not removed',
        old: ['2026-03-09', '2026-03-13'],
        new: ['2026-03-12', '2026-03-13'],
        added: [],
        removed: ['2026-03-09', '2026-03-10', '2026-03-11'],
    },
    // A drag that ends where it started is not a correction. Nothing to write.
    {
        name: 'no change at all writes nothing',
        old: ['2026-03-02', '2026-03-07'],
        new: ['2026-03-02', '2026-03-07'],
        added: [],
        removed: [],
    },
    // A one-day stay that ended up on the wrong day: 03-02 out, 03-03 in.
    {
        name: 'a single-day stay slid one day on writes one day and deletes one day',
        old: ['2026-03-02', '2026-03-03'],
        new: ['2026-03-03', '2026-03-04'],
        added: ['2026-03-03'],
        removed: ['2026-03-02'],
    },
    /*
     | MONTH BOUNDARIES. January has 31 days, April 30 — the arithmetic must come
     | from the Gregorian converter, not from a fixed month length.
     */
    // old covers 01-30, 01-31; new covers 01-30..02-02.
    {
        name: 'the end handle dragged over the month boundary adds the days of the next month',
        old: ['2026-01-30', '2026-02-01'],
        new: ['2026-01-30', '2026-02-03'],
        added: ['2026-02-01', '2026-02-02'],
        removed: [],
    },
    // old covers 02-01, 02-02; new covers 01-30..02-02.
    {
        name: 'the start handle dragged back over the month boundary adds the days of the previous month',
        old: ['2026-02-01', '2026-02-03'],
        new: ['2026-01-30', '2026-02-03'],
        added: ['2026-01-30', '2026-01-31'],
        removed: [],
    },
    // old covers 01-30..02-02; new covers 01-30, 01-31.
    {
        name: 'the end handle dragged back over the month boundary removes the days of the next month',
        old: ['2026-01-30', '2026-02-03'],
        new: ['2026-01-30', '2026-02-01'],
        added: [],
        removed: ['2026-02-01', '2026-02-02'],
    },
    // 30-day month: April has no 31st. old covers 04-29, 04-30; new covers 04-29..05-02.
    {
        name: 'the end handle dragged over a 30-day month end adds May, not an April 31st',
        old: ['2026-04-29', '2026-05-01'],
        new: ['2026-04-29', '2026-05-03'],
        added: ['2026-05-01', '2026-05-02'],
        removed: [],
    },
    /*
     | YEAR BOUNDARIES — and these cases are deliberately NOT reachable from
     | today's UI. Measured in the browser 2026-08-12, not derived from options:
     |
     |   1440px -> view multiMonthYear, eventDurationEditable true, 2 resizers,
     |             and 377 [data-date] cells of which 377 are in 2026 — there is
     |             no December cell to drag a January bar back onto
     |    390px -> view dayGridMonth, eventDurationEditable false,
     |             document.querySelectorAll('.fc-event-resizer').length === 0
     |
     | Nothing in nostrCal.js or calendar.blade.php calls changeView, so those two
     | widths are the whole space. Careful with the obvious shortcut: reading
     | `getOption('showNonCurrentDates')` returns TRUE here — the false is set by
     | the multiMonth VIEW (@fullcalendar/multimonth/index.js), not on the calendar,
     | so the option is the wrong witness and the cell census is the right one.
     |
     | They stay because this is a PURE function with a documented contract, and a
     | contract that holds only inside the current view configuration is not one.
     | The day the gate moves — a desktop month view, a keyboard resize — these are
     | the cases that already know the answer.
     */
    // old covers 12-30, 12-31; new covers 12-30..01-02.
    {
        name: 'the end handle dragged over new year adds the January days',
        old: ['2026-12-30', '2027-01-01'],
        new: ['2026-12-30', '2027-01-03'],
        added: ['2027-01-01', '2027-01-02'],
        removed: [],
    },
    /*
     | old covers 12-30..01-02; new covers 12-30, 12-31.
     |
     | This case was written against a CONSUMER that could not apply such a list,
     | and it is worth keeping the history because it is why the consumer changed:
     | `deleteDays()` used to bound its delete query to currentYear as well as to
     | the day list, so with the 2026 view open this exact delta deleted NOTHING —
     | both January days survived (probe against the real Volt component,
     | 2026-08-12, deleteDays(['2027-01-01','2027-01-02']) with currentYear 2026).
     | `saveDays()` never had that bound, so the two directions of the same delta
     | were not symmetric, and a `removed` crossing New Year was silently halved.
     |
     | FIXED in the same round, by the phase that added the resize: the year bound
     | is gone from the delete query (user scope and the explicit whereIn are the
     | fence), and tests/Feature/CalendarDeleteAcrossYearsTest.php pins it — that
     | file is red against bda7949 and green here. So this case now tests a delta
     | its consumer can actually apply. Do not go looking for the old asymmetry.
     */
    {
        name: 'the end handle dragged back over new year removes the January days',
        old: ['2026-12-30', '2027-01-03'],
        new: ['2026-12-30', '2027-01-01'],
        added: [],
        removed: ['2027-01-01', '2027-01-02'],
    },
    // old covers 01-01, 01-02; new covers 12-30..01-02.
    {
        name: 'the start handle dragged back over new year adds the December days',
        old: ['2027-01-01', '2027-01-03'],
        new: ['2026-12-30', '2027-01-03'],
        added: ['2026-12-30', '2026-12-31'],
        removed: [],
    },
    /*
     | THE LEAP DAY, and its control one year earlier. 2028 is a leap year, 2027
     | is not, and the two cases have the same shape on purpose: the same drag
     | adds TWO days in 2028 and exactly ONE in 2027. A walk that invents a 29th
     | of February reports two in both.
     */
    // old covers 02-27, 02-28; new covers 02-27..03-01.
    {
        name: 'the end handle dragged over the leap day 2028-02-29 adds it',
        old: ['2028-02-27', '2028-02-29'],
        new: ['2028-02-27', '2028-03-02'],
        added: ['2028-02-29', '2028-03-01'],
        removed: [],
    },
    // old covers 02-27..03-01; new covers 02-27, 02-28.
    {
        name: 'the end handle dragged back over the leap day removes it',
        old: ['2028-02-27', '2028-03-02'],
        new: ['2028-02-27', '2028-02-29'],
        added: [],
        removed: ['2028-02-29', '2028-03-01'],
    },
    // old covers 03-01, 03-02; new covers 02-28..03-02 — three days, so two are new.
    {
        name: 'the start handle dragged back over the leap day adds the 28th and the 29th',
        old: ['2028-03-01', '2028-03-03'],
        new: ['2028-02-28', '2028-03-03'],
        added: ['2028-02-28', '2028-02-29'],
        removed: [],
    },
    // The control. old covers 03-01, 03-02; new covers 02-28..03-02 — two days,
    // so exactly ONE is new. 2027 has no 29th of February.
    {
        name: 'the same drag in the non-leap year 2027 adds only the 28th',
        old: ['2027-03-01', '2027-03-03'],
        new: ['2027-02-28', '2027-03-03'],
        added: ['2027-02-28'],
        removed: [],
    },
    /*
     | THE DST CASES. They exist for the zone matrix and nothing else — see the
     | header. The dates are not free: each new range lands inside a window one
     | zone of the matrix is in the list for.
     */
    // EU fall-back. old covers 10-25 alone — the single tap that once wrote
    // nothing at all — new covers 10-25..10-27.
    {
        name: 'a stay on the EU fall-back day stretched forward adds the two following days',
        old: ['2026-10-25', '2026-10-26'],
        new: ['2026-10-25', '2026-10-28'],
        added: ['2026-10-26', '2026-10-27'],
        removed: [],
    },
    // EU fall-back as the SURVIVOR: old covers 10-23..10-27, new covers
    // 10-23..10-25, so 10-26 and 10-27 go and 10-25 must stay.
    {
        name: 'shrunk back onto the EU fall-back day, that day survives',
        old: ['2026-10-23', '2026-10-28'],
        new: ['2026-10-23', '2026-10-26'],
        added: [],
        removed: ['2026-10-26', '2026-10-27'],
    },
    // US spring-forward, the three-day window America/Havana is caught by.
    // old covers 03-08 alone, new covers 03-08..03-10.
    {
        name: 'a stay on the US spring-forward day stretched forward adds the two following days',
        old: ['2026-03-08', '2026-03-09'],
        new: ['2026-03-08', '2026-03-11'],
        added: ['2026-03-09', '2026-03-10'],
        removed: [],
    },
    // Chile switches at 24:00, so local midnight does not exist on that day.
    // A three-day span from 2026-09-05 is one of only six such spans in
    // 2026-2028 (measured in zones.mjs). old covers 09-05, new 09-05..09-07.
    {
        name: 'a stay on the Chile 24:00 switch stretched forward adds the two following days',
        old: ['2026-09-05', '2026-09-06'],
        new: ['2026-09-05', '2026-09-08'],
        added: ['2026-09-06', '2026-09-07'],
        removed: [],
    },
    /*
     | THE PATHOLOGICAL FOUR — both edges at once. Not producible by eventResize;
     | the specification answers them anyway, see the header.
     */
    // Slide. old covers 03-10..03-13, new covers 03-12..03-15. Overlap 03-12,
    // 03-13 is in neither list.
    {
        name: 'both edges moved by two, the overlap is neither added nor removed',
        old: ['2026-03-10', '2026-03-14'],
        new: ['2026-03-12', '2026-03-16'],
        added: ['2026-03-14', '2026-03-15'],
        removed: ['2026-03-10', '2026-03-11'],
    },
    // Grow on both sides. old covers 03-10, 03-11; new covers 03-08..03-13. The
    // four new days are 03-08, 03-09 at the front and 03-12, 03-13 at the back —
    // ONE ascending list across the gap, not front-then-back or back-then-front.
    {
        name: 'grown at both ends, added is one ascending list across the gap',
        old: ['2026-03-10', '2026-03-12'],
        new: ['2026-03-08', '2026-03-14'],
        added: ['2026-03-08', '2026-03-09', '2026-03-12', '2026-03-13'],
        removed: [],
    },
    // Shrink on both sides, the mirror image. old covers 03-08..03-13, new
    // covers 03-10, 03-11.
    {
        name: 'shrunk at both ends, removed is one ascending list across the gap',
        old: ['2026-03-08', '2026-03-14'],
        new: ['2026-03-10', '2026-03-12'],
        added: [],
        removed: ['2026-03-08', '2026-03-09', '2026-03-12', '2026-03-13'],
    },
    // No overlap at all: every old day goes, every new day comes.
    {
        name: 'moved to a different month entirely, every old day goes and every new day comes',
        old: ['2026-03-02', '2026-03-05'],
        new: ['2026-06-01', '2026-06-04'],
        added: ['2026-06-01', '2026-06-02', '2026-06-03'],
        removed: ['2026-03-02', '2026-03-03', '2026-03-04'],
    },
];

/*
 | THE WIDE ONE, kept out of the table because its expectation is 97 strings.
 | old covers 01-05..01-07, new covers 01-05..04-14, so `added` runs 2026-01-08
 | to 2026-04-14 inclusive. Counted off the calendar by hand: 24 days left in
 | January (08..31), all 28 of February 2026 (not a leap year), all 31 of March,
 | 14 in April -> 24 + 28 + 31 + 14 = 97.
 |
 | The expectation is the oracle's list, and the hand-counted 97 with both
 | endpoints is asserted against it separately — so a wrong oracle cannot quietly
 | become the expectation.
 */
const wide = {
    old: ['2026-01-05', '2026-01-08'],
    new: ['2026-01-05', '2026-04-15'],
    addedSpan: ['2026-01-08', '2026-04-15'],
    addedCount: 97,
};

describe(`resizeDelta() under TZ=${zone}`, () => {
    it('resources/js/ptrDays.js exports resizeDelta()', () => {
        assert.equal(
            typeof ptrDays.resizeDelta,
            'function',
            'the resize return path lives next to rangeDays(), in resources/js/ptrDays.js',
        );
    });

    for (const {name, old: before, new: after, added, removed} of cases) {
        describe(`${name} — (${before.join('..')}) -> (${after.join('..')})`, () => {
            it(`adds exactly ${added.length} day(s): [${added}]`, () => {
                assert.deepEqual(delta(...before, ...after).added, added);
            });

            it(`removes exactly ${removed.length} day(s): [${removed}]`, () => {
                assert.deepEqual(delta(...before, ...after).removed, removed);
            });
        });
    }

    /*
     | THE INVARIANTS, once over all cases instead of once per case — and the
     | shape is the result of a measurement, not a preference.
     |
     | These three used to be six assertions per case, 214 tests in all. Against
     | nine mutations of a spec-conformant simulation (one boundary shifted by one
     | day, plus the guardless set difference) not ONE of them ever failed while
     | both hand-written expectations of the same case passed: a deepEqual against
     | a hand-written ascending ISO array already pins the count, the order, the
     | shape and the uniqueness, so per-case copies of those checks were 124 tests
     | that could not detect anything the two anchors missed. They are kept as
     | properties over the whole table because what they STATE is worth stating —
     | a reader learns the contract from them — but coverage they are not, and
     | 124 tests that cannot fail alone are the kind of number that makes a suite
     | look safer than it is.
     |
     | Each carries the case name in its message, so a red run still names the
     | case even though the case is no longer a test of its own.
     */
    describe('the invariants, over every case in the table', () => {
        it('both lists are ISO, strictly ascending and free of duplicates', () => {
            for (const {name, old: before, new: after} of cases) {
                const result = delta(...before, ...after);

                for (const label of ['added', 'removed']) {
                    const list = result[label];

                    for (const iso of list) {
                        assert.match(iso, ISO, `${name}: ${label} carries a non-ISO entry`);
                    }

                    for (let i = 1; i < list.length; i++) {
                        assert.ok(
                            list[i] > list[i - 1],
                            `${name}: ${label} is not ascending, ${list[i - 1]} before ${list[i]}`,
                        );
                    }

                    assert.equal(new Set(list).size, list.length, `${name}: ${label} has a duplicate`);
                }
            }
        });

        /*
         | The churn guard, and it is not cosmetic. saveDays() and deleteDays()
         | are two separate Livewire calls, so a day in BOTH lists has an outcome
         | that depends on which one lands last. And a day both ranges cover was
         | never touched by the drag: writing it again is a pointless round trip,
         | deleting it is data loss.
         */
        it('no day is added and removed at once, and no unchanged day is touched', () => {
            for (const {name, old: before, new: after} of cases) {
                const {added, removed} = delta(...before, ...after);
                const newDays = daysOf(...after);
                const unchanged = daysOf(...before).filter((day) => newDays.includes(day));

                for (const day of added) {
                    assert.ok(!removed.includes(day), `${name}: ${day} is added AND removed`);
                    assert.ok(!unchanged.includes(day), `${name}: ${day} was already stored, re-added`);
                }

                for (const day of removed) {
                    assert.ok(!unchanged.includes(day), `${name}: ${day} is still in the range, removed`);
                }
            }
        });

        /*
         | The one that says the delta is COMPLETE rather than merely correct as
         | far as it goes: run the two write instructions against the old day set
         | and the result has to be the new day set exactly. This is the only
         | assertion in the file whose expectation comes from the independent
         | oracle rather than from a hand-written array, which makes it the one
         | that can tell "the code is wrong" from "my expectation is wrong".
         */
        it('applying the delta turns the old range into the new one, for every case', () => {
            for (const {name, old: before, new: after} of cases) {
                const {added, removed} = delta(...before, ...after);
                const stored = new Set(daysOf(...before));

                for (const day of removed) stored.delete(day);
                for (const day of added) stored.add(day);

                assert.deepEqual([...stored].sort(), daysOf(...after), `${name}: delta is not complete`);
            }
        });
    });

    describe(`a stay stretched by ${wide.addedCount} days — (${wide.old.join('..')}) -> (${wide.new.join('..')})`, () => {
        const expected = daysOf(...wide.addedSpan);

        it(`the oracle span covers the hand-counted ${wide.addedCount} days`, () => {
            assert.equal(expected.length, wide.addedCount);
            assert.equal(expected[0], '2026-01-08');
            assert.equal(expected[expected.length - 1], '2026-04-14');
        });

        it(`adds all ${wide.addedCount} days and removes none`, () => {
            const {added, removed} = delta(...wide.old, ...wide.new);

            assert.deepEqual(added, expected);
            assert.deepEqual(removed, []);
        });
    });

    /*
     | NOT A USABLE RANGE -> NO INSTRUCTION. The derivation is in the header: []
     | out of rangeDays() means "nothing marked" and "garbage" alike, so turning
     | it into a set difference deletes a whole stay or writes one out of
     | nothing. Every case here keeps the OTHER three arguments valid, so a plain
     | set difference produces a non-empty list and fails — that is what makes
     | this block a test rather than a formality, and it is checked one slot at a
     | time so a red run names which slot is unguarded.
     |
     | The valid range used throughout is 2026-03-02..2026-03-07, i.e. five
     | stored days; the naive answer would be to delete or to write all five.
     */
    describe('not a usable range', () => {
        const ok = ['2026-03-02', '2026-03-07'];

        const unusable = [
            // A resize to zero days. FullCalendar refuses it without firing the
            // callback (measured), and end is exclusive, so end === start is no day.
            ['the new range is empty (end === start)', ...ok, '2026-03-02', '2026-03-02'],
            ['the old range is empty (end === start)', '2026-03-02', '2026-03-02', ...ok],
            // Inverted: end before start. No day, and above all not "all of them".
            ['the new range is inverted', ...ok, '2026-03-07', '2026-03-02'],
            ['the old range is inverted', '2026-03-07', '2026-03-02', ...ok],

            // One malformed slot at a time, other three valid.
            ['the new end is not a calendar date', ...ok, '2026-03-02', '2026-13-40'],
            ['the new start is not a calendar date', ...ok, '2026-00-00', '2026-03-07'],
            ['the old end is not a calendar date', '2026-03-02', '2026-02-30', ...ok],
            ['the old start is not a calendar date', '2026-3-2', '2026-03-07', ...ok],
            ['the new end carries a time part', ...ok, '2026-03-02', '2026-03-09T00:00:00'],
            ['the new start is a UTC instant', ...ok, '2026-03-02T00:00:00Z', '2026-03-09'],
            ['the old start is in year 1, which Date.UTC moves to 1901', '0001-01-01', '0001-01-05', ...ok],

            // Falsy, one slot at a time. The Alpine component initialises its
            // range state to `false`, so this is the shape a premature call has.
            ['the new end is null', ...ok, '2026-03-02', null],
            ['the new start is null', ...ok, null, '2026-03-09'],
            ['the old end is null', '2026-03-02', null, ...ok],
            ['the old start is null', null, '2026-03-07', ...ok],
            ['the new end is false', ...ok, '2026-03-02', false],
            ['the old start is false', false, '2026-03-07', ...ok],
            ['the new end is undefined', ...ok, '2026-03-02', undefined],
            ['the new end is an empty string', ...ok, '2026-03-02', ''],
            ['every argument is falsy', false, false, false, false],

            // Not strings at all. A Date object is an instant, and its date half
            // depends on the offset it is read in.
            ['the new end is a Date object', ...ok, '2026-03-02', new Date(Date.UTC(2026, 2, 9))],
            ['the new end is an epoch number', ...ok, '2026-03-02', Date.UTC(2026, 2, 9)],
        ];

        for (const [name, ...args] of unusable) {
            it(`${name} — writes nothing and deletes nothing`, () => {
                const {added, removed} = delta(...args);

                assert.deepEqual(added, [], 'added must stay empty');
                assert.deepEqual(removed, [], 'removed must stay empty');
            });
        }

        it('no arguments at all writes nothing and deletes nothing', () => {
            const {added, removed} = delta();

            assert.deepEqual(added, []);
            assert.deepEqual(removed, []);
        });

        it('a partially applied call writes nothing and deletes nothing', () => {
            const {added, removed} = delta('2026-03-02', '2026-03-07');

            assert.deepEqual(added, []);
            assert.deepEqual(removed, []);
        });
    });
});
