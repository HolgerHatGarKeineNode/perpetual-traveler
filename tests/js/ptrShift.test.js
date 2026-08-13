/*
 | The net under shiftDay() in resources/js/ptrDays.js — the arithmetic of the
 | grid cursor's arrow keys (nostrCal.js). One press is one calendar day, or
 | seven of them, and the day the cursor lands on is the day Enter stamps onto
 | the user's residency data. A step that lands one day off is therefore the same
 | class of defect P8 removed from the write path, not a display glitch.
 |
 | node --test, no dependency: `node tests/js/zones.mjs` (all 11 zones) or
 | `node --test tests/js/` (the ambient zone only). Both runners glob this
 | directory, so this file is in the matrix by existing.
 |
 | HOW THE EXPECTATIONS WERE DERIVED — from the CALENDAR, never from the
 | function's output. "One day after the 31st of March is the 1st of April" is a
 | fact about the Gregorian calendar and is written out by hand below; it must
 | hold in Berlin, in Havana and in Kiritimati alike. That is why the DST block
 | exists: those four days are the ones where a local-getter implementation
 | silently returns the wrong day, and they are the reason this file is worth
 | running eleven times.
 */
import {describe, it} from 'node:test';
import assert from 'node:assert/strict';

import {shiftDay} from '../../resources/js/ptrDays.js';

describe('shiftDay', () => {
    describe('one day at a time', () => {
        const steps = [
            ['inside a month', '2026-03-10', 1, '2026-03-11'],
            ['backwards inside a month', '2026-03-10', -1, '2026-03-09'],
            ['over a month end', '2026-03-31', 1, '2026-04-01'],
            ['back over a month start', '2026-03-01', -1, '2026-02-28'],
            ['over a 30-day month end', '2026-04-30', 1, '2026-05-01'],
            ['over the new year', '2026-12-31', 1, '2027-01-01'],
            ['back over the new year', '2027-01-01', -1, '2026-12-31'],
            ['February in a common year', '2026-02-28', 1, '2026-03-01'],
            ['February in a leap year', '2028-02-28', 1, '2028-02-29'],
            ['off the leap day', '2028-02-29', 1, '2028-03-01'],
            ['back onto the leap day', '2028-03-01', -1, '2028-02-29'],
            ['a step of nothing', '2026-03-10', 0, '2026-03-10'],
        ];

        for (const [name, from, delta, expected] of steps) {
            it(`${name}: ${from} ${delta >= 0 ? '+' : ''}${delta} -> ${expected}`, () => {
                assert.equal(shiftDay(from, delta), expected);
            });
        }
    });

    /*
     | A WEEK, which is what ArrowUp/ArrowDown move in the grid. Seven days is a
     | row of the calendar in every view this app renders (firstDay: 1), so the
     | cursor lands in the same column — that IS the meaning of the key.
     */
    describe('a week at a time', () => {
        const steps = [
            ['down a row', '2026-03-02', 7, '2026-03-09'],
            ['up a row', '2026-03-09', -7, '2026-03-02'],
            ['down a row over a month end', '2026-03-30', 7, '2026-04-06'],
            ['up a row over a month start', '2026-04-06', -7, '2026-03-30'],
            ['down a row over the new year', '2026-12-28', 7, '2027-01-04'],
            ['up a row over the new year', '2027-01-04', -7, '2026-12-28'],
            ['down a row over the leap day', '2028-02-24', 7, '2028-03-02'],
            ['up a row over the leap day', '2028-03-02', -7, '2028-02-24'],
        ];

        for (const [name, from, delta, expected] of steps) {
            it(`${name}: ${from} ${delta >= 0 ? '+' : ''}${delta} -> ${expected}`, () => {
                assert.equal(shiftDay(from, delta), expected);
            });
        }
    });

    /*
     | THE FOUR DAYS THAT BREAK A LOCAL-GETTER IMPLEMENTATION, taken from the
     | measured list in ptrDays.js's header rather than guessed: a `setDate(+1)`
     | walk on a local Date mixes the offsets across a DST transition, and the
     | two Cuban/Chilean zones are the ones where LOCAL MIDNIGHT DOES NOT EXIST
     | at all. Under zones.mjs each of these runs in all 11 zones; in the zone it
     | belongs to it is the whole reason the ordinal arithmetic is not optional.
     */
    describe('DST days, in every zone', () => {
        const steps = [
            ['Europe/Berlin falls back', '2026-10-25', 1, '2026-10-26'],
            ['Europe/Berlin springs forward', '2026-03-29', 1, '2026-03-30'],
            ['America/New_York falls back', '2026-11-01', 1, '2026-11-02'],
            ['Pacific/Auckland falls back', '2026-04-04', 1, '2026-04-05'],
            ['America/Havana switches at 00:00', '2026-03-08', 1, '2026-03-09'],
            ['America/Santiago switches at 24:00', '2026-09-06', 1, '2026-09-07'],
            ['a week over the Berlin fall-back', '2026-10-22', 7, '2026-10-29'],
            ['a week back over the New York fall-back', '2026-11-05', -7, '2026-10-29'],
        ];

        for (const [name, from, delta, expected] of steps) {
            it(`${name}: ${from} ${delta >= 0 ? '+' : ''}${delta} -> ${expected}`, () => {
                assert.equal(shiftDay(from, delta), expected);
            });
        }
    });

    /*
     | FAIL-CLOSED, and null is read by the caller as "the cursor does not move".
     | Same direction and the same round trip as rangeDays(): anything that does
     | not render back to the exact string it came from is no day, so it cannot
     | become a day the next Enter stamps.
     */
    describe('not a calendar date', () => {
        const invalid = [
            ['an unpadded date', '2026-3-4', 1],
            ['a date with a time part', '2026-03-14T00:00:00', 1],
            ['a UTC instant', '2026-03-14T00:00:00Z', 1],
            ['the 30th of February', '2026-02-30', 1],
            ['month 13', '2026-13-01', 1],
            ['year 1, which Date.UTC moves to 1901', '0001-01-01', 1],
            ['a Date object instead of a string', new Date(Date.UTC(2026, 2, 14)), 1],
            ['an epoch number', Date.UTC(2026, 2, 14), 1],
            ['null', null, 1],
            ['false', false, 1],
            ['undefined', undefined, 1],
            ['an empty string', '', 1],
        ];

        for (const [name, from, delta] of invalid) {
            it(`${name} moves nowhere`, () => {
                assert.equal(shiftDay(from, delta), null);
            });
        }
    });

    describe('not a whole number of days', () => {
        for (const [name, delta] of [['a fraction', 1.5], ['NaN', NaN], ['a string', '1'], ['undefined', undefined], ['Infinity', Infinity]]) {
            it(`${name} moves nowhere`, () => {
                assert.equal(shiftDay('2026-03-10', delta), null);
            });
        }
    });

    /*
     | THE ENDS OF THE REPRESENTABLE RANGE, which are the ends ordinal() already
     | draws (years 0100-9999): stepping past either one yields null rather than
     | a year the round trip cannot express. Reached only by a user who has
     | navigated the grid there, which is why it is written down instead of
     | relied upon — but the answer has to be "no day", not a wrong one.
     */
    describe('the ends of the range', () => {
        it('the last representable day still steps backwards', () => {
            assert.equal(shiftDay('9999-12-31', -1), '9999-12-30');
        });

        it('but not forwards', () => {
            assert.equal(shiftDay('9999-12-31', 1), null);
        });

        it('the first representable day still steps forwards', () => {
            assert.equal(shiftDay('0100-01-01', 1), '0100-01-02');
        });

        it('but not backwards, because 0099 maps into the 20th century', () => {
            assert.equal(shiftDay('0100-01-01', -1), null);
        });
    });
});
