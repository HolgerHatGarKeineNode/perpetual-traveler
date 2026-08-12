/*
 | The safety net under resources/js/localISODate.js (P9 Welle A,
 | docs/plans/2026-08-10T2204-kalender-ux-tageseingabe.md, point 2): the
 | year-under-1000 padding defect. Pre-existing since 37d10e1, belonged to the
 | same class ptrDays.js's isoOf() was already hardened against.
 |
 | node --test, no dependency: `node --test tests/js/` runs it in the ambient
 | zone; it is not part of tests/js/zones.mjs because the function's own
 | invariant is that it reads the process's LOCAL clock on purpose (the Dates
 | FullCalendar hands it are already in the calendar's local timeZone) — a
 | zone matrix would be testing the wrong thing here.
 */
import {describe, it} from 'node:test';
import assert from 'node:assert/strict';
import {localISODate} from '../../resources/js/localISODate.js';

describe('localISODate()', () => {
    it('passes a padded YYYY-MM-DD string straight through', () => {
        assert.equal(localISODate('2026-03-14'), '2026-03-14');
    });

    it('formats a Date object with a zero-padded month and day', () => {
        assert.equal(localISODate(new Date(2026, 2, 5)), '2026-03-05');
    });

    /*
     | THE FIX. A Date at year 990 must come back as '0990-01-01', not
     | '990-01-01' — the latter fails rangeDays()'s anchored
     | /^\d{4}-\d{2}-\d{2}$/ pattern (resources/js/ptrDays.js), which is not
     | decoration: ordinal() only accepts an already-4-digit year, so an
     | unpadded year here makes rangeDays() return [] for a real click,
     | writing nothing to the user's residency data with no error anywhere.
     */
    it('pads a year under 1000 to four digits', () => {
        assert.equal(localISODate(new Date(990, 0, 1)), '0990-01-01');
    });

    it('pads a year under 100 to four digits', () => {
        // new Date(year, ...) maps 0-99 to 1900+year (ECMA-262), so a literal
        // year 7 has to be set via setFullYear(), which does NOT do that
        // mapping — verified in Node before relying on it here.
        const date = new Date(2026, 5, 3);
        date.setFullYear(7);
        assert.equal(localISODate(date), '0007-06-03');
    });

    it('round-trips through rangeDays()\'s anchored pattern for a year under 1000', () => {
        const iso = localISODate(new Date(990, 0, 1));
        assert.match(iso, /^\d{4}-\d{2}-\d{2}$/);
    });

    it('leaves a four-digit year exactly as a Date reports it', () => {
        assert.equal(localISODate(new Date(2026, 11, 31)), '2026-12-31');
    });
});
