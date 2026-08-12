/*
 | THE ONE derivation of "which days a marked range covers".
 |
 | Pure on purpose: no FullCalendar, no Alpine, no DOM. nostrCal.js's
 | rangeDays() is a one-line call into this file, and deleteDays(), setCountry(),
 | rangeLabel() and the preview getters all read THAT list — so the preview
 | counts exactly the days that get written. They cannot disagree, because there
 | is nothing to disagree with.
 |
 | That is also the reason the range half of the preview is computed client-side
 | and not on the server: a server-side preview would have to re-derive the day
 | list from (start, endExclusive), i.e. a SECOND derivation of the same thing.
 |
 | ---------------------------------------------------------------------------
 | WHY THERE IS NO TIMEZONE IN HERE — the invariant, do not break it.
 | ---------------------------------------------------------------------------
 | The input is a CALENDAR DATE, never an instant: the user marks the 25th of
 | October, not a point on the time axis. So the walk runs on day ORDINALS
 | (one integer per calendar day), and Date.UTC()/getUTC*() appear for exactly
 | one job — as the Gregorian converter that already knows month lengths and
 | leap years. UTC days are 86 400 000 ms long by definition (ECMA-262 models no
 | leap seconds), which is what makes `n + 1` an exact "next calendar day".
 |
 | THE RULE: not one local getter, not one local constructor in this function.
 | `new Date('2026-03-14')` parses as UTC MIDNIGHT while getFullYear()/
 | getMonth()/getDate() read a Date back in the PROCESS zone; mixing those two
 | is what produced the two defects this file was built to remove (both measured
 | 2026-08-11, both pre-existing — they stood twice inline, in deleteDays() and
 | setCountry(), before commit dcbea1e merged them into one):
 |   1. west of UTC the whole list shifted one day back — TZ=America/New_York
 |      returned 03-13, 03-14, 03-15 for the marked 14th-16th of March.
 |   2. a local setDate() walk mixed the offsets across a DST fall-back: `last`
 |      was built with the offset AFTER the transition, the running day carried
 |      the one before, so `day <= last` stopped one step early. The last day of
 |      a range was swallowed — and for ONE day per zone and year a single tap
 |      returned [], so the user picked a country, the modal closed and NOTHING
 |      was written. Measured 2026-08-11: Europe/Berlin 2026-10-25,
 |      America/New_York 2026-11-01, Pacific/Auckland 2026-04-04.
 |
 |      Those are MEASURED days, not a rule — and there is no short rule to put
 |      here. For Berlin and New York the losing day is the transition day; for
 |      Auckland it is the day before (fall-back 2026-04-05, +13 -> +12 off the
 |      tz data; the tap on 04-05 was always correct); for Santiago it is the day
 |      after. Athens and Jerusalem share the offset +2 and differ. Two earlier
 |      attempts to compress this into one sentence ("the fall-back day", "east of
 |      UTC it is one earlier") were both measured wrong, so: if you need the
 |      losing day for a zone, measure that zone. Whether the walk breaks depends
 |      on the local clock time of the switch relative to the offset, and that is
 |      not a property you can read off a map.
 |
 | Both are gone and pinned: tests/js/ptrDays.test.js runs under every zone of
 | tests/js/zones.mjs, which is anchored as the last line of composer.json's
 | "test" script because there is no CI here and the ambient zone cannot see
 | this class of error. Measured 2026-08-11 after the fix: 11/11 zones green,
 | and the returned list is byte-identical in all 11 zones for all 1096 single
 | days and all 1096 three-day ranges of 2026-2028 — the result no longer
 | depends on the process zone at all.
 |
 | @param {string|false|null|undefined} startIso           first marked day, 'YYYY-MM-DD'
 | @param {string|false|null|undefined} endIsoExclusive    day AFTER the last marked day
 | @returns {string[]} the marked days as 'YYYY-MM-DD', ascending; [] on falsy input
 |          and on anything that is not a padded, in-range calendar date
 */
const MS_PER_DAY = 86_400_000;

export function rangeDays(startIso, endIsoExclusive) {
    if (!startIso || !endIsoExclusive) return [];

    const pad = (n) => String(n).padStart(2, '0');
    const isoOf = (n) => {
        const d = new Date(n * MS_PER_DAY);
        // The year is padded to FOUR digits, and that is load-bearing rather than
        // cosmetic: ordinal() below accepts a string only if isoOf() renders it
        // back identically, so an unpadded year here silently rejects every year
        // under 1000 — measured 2026-08-11, '0990-01-01' rendered as '990-01-01'
        // and 657 434 previously correct inputs turned into [].
        return `${String(d.getUTCFullYear()).padStart(4, '0')}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
    };

    /*
     | 'YYYY-MM-DD' -> that day's ordinal, or NaN if the string is not a calendar
     | date. The round trip is the whole check, and it is not decoration: Date.UTC
     | NORMALISES silently instead of rejecting, so without it this function
     | invents days out of nonsense (all measured 2026-08-11 against the version
     | that only tested for NaN):
     |
     |   '2026-13-40' -> 2027-02-09, 02-10, 02-11    month 13, day 40 rolled over
     |   '2026-00-00' -> 2025-11-30, 12-01, 12-02    month 0 rolled backwards
     |   '0001-01-01' -> 1901-01-01, …               years 0-99 map to 1900 + y
     |
     | ACCEPTED RANGE: years 0100-9999, measured at both ends. 0001-0099 stay
     | rejected and that is the only correct answer available here — Date.UTC maps
     | them into the 20th century and no round trip can undo that, so returning
     | the wrong century would be worse than returning nothing.
     |
     | A phantom day here is not a display glitch: the next click stamps it onto
     | the user's residency data. So the direction is fail-CLOSED — anything that
     | does not render back to the exact string it came from is no day at all.
     | The anchored pattern is part of that: it rejects '2026-3-4' (unpadded) and
     | every string carrying a time part, because an instant is not a calendar
     | date and its date half depends on the offset it is read in.
     |
     | FullCalendar only ever hands over padded, date-only strings — startStr and
     | endStr come from formatIso(…, {omitTime: span.allDay}) and daygrid forces
     | allDay on every hit, with no timegrid plugin installed. The guard is
     | therefore unreachable from today's UI and exists for the next caller.
     */
    const ordinal = (iso) => {
        if (typeof iso !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return NaN;

        const [y, m, d] = iso.split('-').map(Number);
        const n = Math.floor(Date.UTC(y, m - 1, d) / MS_PER_DAY);

        return isoOf(n) === iso ? n : NaN;
    };

    const first = ordinal(startIso);
    const bound = ordinal(endIsoExclusive);

    if (!Number.isFinite(first) || !Number.isFinite(bound)) return [];

    const days = [];
    for (let n = first; n < bound; n++) {
        days.push(isoOf(n));
    }

    return days;
}

/*
 | THE RETURN PATH OF A RESIZE — "the user pulled an edge, what has to be
 | written?" — as the two write instructions of that drag:
 |
 |   added   -> saveDays(added, country)   the days the new range covers and the
 |                                          old one did not
 |   removed -> deleteDays(removed)         the days the old range covered and the
 |                                          new one does not
 |   neither -> every day BOTH cover. An unchanged day must not be re-stamped and
 |              must above all not be deleted.
 |
 | It COMPOSES rangeDays() rather than walking days itself, and that is the whole
 | reason it lives in this file: a third walk over "which days does a span cover"
 | is exactly the second derivation the header above argues against. Both lists
 | therefore inherit the walk's guarantees for free — ascending, 'YYYY-MM-DD',
 | Gregorian month lengths and leap days from the converter, and no local getter
 | anywhere in the chain, so the traveller's timezone cannot shift a day. `filter`
 | preserves the walk's order, which is what keeps `added` ONE ascending list even
 | when it spans a gap (both edges grown at once: two days at the front, two at
 | the back, one list).
 |
 | WHY THE INPUT CHECK IS NOT MERELY DEFENSIVE — the fail-closed direction here
 | is not the same one as in rangeDays(), and getting it wrong costs data:
 | rangeDays() answers [] for "nothing marked" AND for "not a calendar date"
 | alike, and those two are not distinguishable afterwards. A plain set difference
 | over two such results turns that [] into an INSTRUCTION:
 |
 |   new range unusable -> after  = [] -> removed = every day of the stay
 |   old range unusable -> before = [] -> added   = every day of the new range
 |
 | The first deletes a whole stay because of one malformed string, the second
 | writes one out of nothing — both fail-OPEN in precisely the direction that
 | destroys residency days. So an unusable range on ANY of the four slots yields
 | NO instruction. A caller that really means "drop this stay" has to say so; the
 | modal's own delete path is what that is for.
 |
 | The check is `before`/`after` being empty and nothing more, and that is exact
 | rather than convenient: rangeDays() returns [] for every unusable input there
 | is — falsy, non-string, unpadded, a time part, a normalising nonsense date, a
 | year Date.UTC moves into the 20th century — and also for the two degenerate
 | ranges (end === start, and end before start), because its walk runs
 | `n < bound`. One condition, no second notion of "usable" that could drift from
 | the walk's own.
 |
 | Unreachable from today's UI, and kept for the next caller: FullCalendar refuses
 | a resize to zero days without firing the callback, and startStr/endStr are
 | always padded, date-only strings (measured in the browser, 2026-08-12).
 |
 | Pinned by tests/js/ptrResize.test.js, which runs under every zone of
 | tests/js/zones.mjs.
 |
 | @param {string|false|null|undefined} oldStart           first day of the stay before the drag
 | @param {string|false|null|undefined} oldEndExclusive    day AFTER its last day
 | @param {string|false|null|undefined} newStart           first day after the drag
 | @param {string|false|null|undefined} newEndExclusive    day AFTER its last day
 | @returns {{added: string[], removed: string[]}} ascending 'YYYY-MM-DD'; both
 |          empty if any slot is not a usable range
 */
export function resizeDelta(oldStart, oldEndExclusive, newStart, newEndExclusive) {
    const before = rangeDays(oldStart, oldEndExclusive);
    const after = rangeDays(newStart, newEndExclusive);

    if (!before.length || !after.length) return {added: [], removed: []};

    const beforeDays = new Set(before);
    const afterDays = new Set(after);

    return {
        added: after.filter((day) => !beforeDays.has(day)),
        removed: before.filter((day) => !afterDays.has(day)),
    };
}
