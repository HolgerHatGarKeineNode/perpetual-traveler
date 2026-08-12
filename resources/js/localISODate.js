/*
 | THE LOCAL-CLOCK COUNTERPART TO ptrDays.js's isoOf(). Deliberately the
 | opposite invariant: ptrDays.js forbids every local getter because a marked
 | RANGE is a calendar date with no zone attached. This function's callers
 | (dayCellClassNames, dateClick, eventClick in resources/js/nostrCal.js) hand
 | over a `Date` FullCalendar already resolved in the calendar's OWN timeZone
 | ('local' — nostrCal.js's Calendar option), so getFullYear()/getMonth()/
 | getDate() here read back the exact day the grid is showing, not an instant
 | that could disagree with it. Extracted into its own file, not moved into
 | ptrDays.js, so that file's "not one local getter" header stays literally
 | true and grep-able.
 |
 | A 'YYYY-MM-DD' string IS already this function's output format, so it is
 | handed straight back — no Date in between, because there is no instant to
 | convert. Routed through `new Date(d)` it was parsed as UTC midnight and read
 | back with the LOCAL getters, which names the day BEFORE west of UTC:
 | measured 2026-08-11, TZ=America/New_York turned '2026-03-14' into
 | '2026-03-13'. dateClick() sets newEventEnd from this function, so that is
 | the boundary of a stored stay — a wrong day here is a wrong residency day,
 | and no zone may move a calendar date.
 |
 | The shortcut is deliberately limited to date-ONLY strings. A string with a
 | time part is an instant, not a calendar date, and keeps the local
 | rendering — as do Date objects, which is what both call sites pass today
 | (dayCellClassNames, dateClick).
 |
 | THE YEAR IS PADDED TO FOUR DIGITS, and that is load-bearing rather than
 | cosmetic, the same defect class ptrDays.js's isoOf() was hardened against
 | (see its comment on the same padStart): rangeDays()'s ordinal() only accepts
 | a string matching /^\d{4}-\d{2}-\d{2}$/, so an unpadded year here silently
 | produces a string that pattern rejects. Practically unreachable — it takes
 | roughly a thousand `prev` clicks to walk the grid under year 1000 — but it
 | is the same failure mode as the one ptrDays.js already closed: a real click
 | that writes nothing, with no error anywhere. Pre-existing since 37d10e1
 | (P9 of docs/plans/2026-08-10T2204-kalender-ux-tageseingabe.md), not
 | introduced here.
 |
 | @param {string|Date} d
 | @returns {string} 'YYYY-MM-DD' in the process's local timezone (or the
 |          input unchanged if it already is one)
 */
export function localISODate(d) {
    if (typeof d === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(d)) return d;

    const date = d instanceof Date ? d : new Date(d);
    const pad = (n) => String(n).padStart(2, '0');

    return `${String(date.getFullYear()).padStart(4, '0')}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}
