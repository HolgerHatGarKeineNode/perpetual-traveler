import {Calendar} from '@fullcalendar/core'
import multiMonthPlugin from '@fullcalendar/multimonth'
import dayGridPlugin from '@fullcalendar/daygrid'
import interactionPlugin from '@fullcalendar/interaction';
import {rangeDays} from './ptrDays.js';

export default (livewireComponent) => ({

    calendar: null,

    tab: 'calendar',

    modalOpen: false,

    newEventStart: false,
    newEventEnd: false,

    events: livewireComponent.entangle('events'),

    currentYear: livewireComponent.entangle('currentYear').live,

    // The exact list of days inside the checked window that carry no country,
    // computed server-side (calendar.blade.php -> refreshUntrackedDays) and
    // shipped whole. This file does NO date arithmetic on it: it hatches set
    // members, nothing else. That is what keeps the number printed above the
    // calendar and the marked cells in step — they read the same list.
    untrackedDays: livewireComponent.entangle('untrackedDays'),

    async init() {

        const toFcEvent = (event) => ({
            title: event.title,
            start: event.start,
            allDay: true,
            country: event.country ?? null,
        });

        const events = this.events.map(toFcEvent);

        const that = this;
        const isMobile = window.matchMedia('(max-width: 1023px)').matches;

        // Titles arrive as "<flag emoji> <country name>"; the emoji is two
        // regional-indicator code points, hence slice(0, 2) on code points.
        const flagOnly = (title) => {
            const chars = Array.from(title || '');
            return chars.slice(0, 2).join('');
        };

        const countryName = (title) => {
            const rest = Array.from(title || '').slice(2).join('').trim();
            return rest || (title || '');
        };

        const esc = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        // A 'YYYY-MM-DD' string IS already this function's output format, so it
        // is handed straight back — no Date in between, because there is no
        // instant to convert. Routed through `new Date(d)` it was parsed as UTC
        // midnight and read back with the LOCAL getters, which names the day
        // BEFORE west of UTC: measured 2026-08-11, TZ=America/New_York turned
        // '2026-03-14' into '2026-03-13'. dateClick() sets newEventEnd from this
        // function, so that is the boundary of a stored stay — a wrong day here
        // is a wrong residency day, and no zone may move a calendar date.
        //
        // The shortcut is deliberately limited to date-ONLY strings. A string
        // with a time part is an instant, not a calendar date, and keeps the
        // local rendering — as do Date objects, which is what all three call
        // sites pass today (dayCellClassNames, dateClick, eventClick).
        const localISODate = (d) => {
            if (typeof d === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(d)) return d;

            const date = d instanceof Date ? d : new Date(d);
            const pad = (n) => String(n).padStart(2, '0');
            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        };

        // Set lookup, rebuilt whenever the server ships a new list. The factory
        // exists so the option and its later replacement share ONE body but get
        // separate function identities (see the $watch below).
        let untracked = new Set(this.untrackedDays ?? []);
        const makeDayCellClassNames = () => (arg) => (
            // arg.date is dateEnv.toDate(), i.e. a Date in the calendar's
            // timeZone ('local'), so localISODate is the same path every other
            // date in this file takes. A day off here would be a day off in the
            // year total.
            untracked.has(localISODate(arg.date)) ? ['ptr-untracked'] : []
        );

        this.calendar = new Calendar(this.$refs.cal, {
            plugins: [interactionPlugin, multiMonthPlugin, dayGridPlugin],
            initialView: isMobile ? 'dayGridMonth' : 'multiMonthYear',
            headerToolbar: isMobile
                ? {left: 'prev,next', center: 'title', right: 'today'}
                : {left: 'prev,next', center: 'title', right: 'today'},
            eventOverlap: false,
            selectable: true,
            selectMirror: true,
            unselectAuto: false,
            longPressDelay: 200,
            selectLongPressDelay: 200,
            height: 'auto',
            defaultAllDay: true,
            displayEventTime: false,
            dayMaxEvents: false,
            timeZone: 'local',
            firstDay: 1,
            events: events,
            dayCellClassNames: makeDayCellClassNames(),
            eventContent: (arg) => {
                // role="img" + aria-label: the flag emoji alone is the only
                // visual carrier of the country (WCAG 1.4.1), and `title` is
                // both hover-only and unreliably announced. The label carries
                // the plain country name; emoji and code stay decorative so
                // the country is announced exactly once.
                const name = countryName(arg.event.title);
                const code = arg.event.extendedProps.country || '';

                return {
                    html: `<span class="ptr-flag" role="img" aria-label="${esc(name)}" title="${esc(name)}">`
                        + `<span class="ptr-flag-emoji" aria-hidden="true">${esc(flagOnly(arg.event.title))}</span>`
                        + (code ? `<span class="ptr-code" aria-hidden="true">${esc(code)}</span>` : '')
                        + `</span>`,
                };
            },
            select: (info) => {
                this.newEventStart = info.startStr;
                this.newEventEnd = info.endStr;
                this.modalOpen = true;
            },
            dateClick: (info) => {
                // Single-Tap auf einen Tag (Touch-freundlich)
                const next = new Date(info.date);
                next.setDate(next.getDate() + 1);
                this.newEventStart = info.dateStr;
                this.newEventEnd = localISODate(next);
                this.modalOpen = true;
            },
            eventClick: (info) => {
                // Tap auf bestehendes Event: Modal mit nur diesem Tag öffnen
                const start = new Date(info.event.start);
                const next = new Date(start);
                next.setDate(next.getDate() + 1);
                this.newEventStart = localISODate(start);
                this.newEventEnd = localISODate(next);
                this.modalOpen = true;
            },
            datesSet: function (dateInfo) {
                // Month grids bleed into the neighbouring month/year, so the first
                // visible cell is unreliable; take the midpoint of the visible range.
                const mid = new Date((dateInfo.start.getTime() + dateInfo.end.getTime()) / 2);
                that.currentYear = mid.getFullYear();
            },
        });

        this.calendar.render();

        // Mobile: the pane is still zero-width at init (x-cloak/x-show), so the
        // first render mis-sizes. Recompute once it actually has a width.
        new ResizeObserver(() => this.calendar && this.calendar.updateSize())
            .observe(this.$refs.cal);

        this.$watch('events', (newEvents) => {
            this.calendar.removeAllEvents();
            this.calendar.addEventSource(newEvents.map(toFcEvent));
        });

        this.$watch('untrackedDays', (days) => {
            untracked = new Set(days ?? []);
            // dayCellClassNames only runs again when FullCalendar sees the
            // option change (SET_OPTION -> re-render of the day cells). The set
            // is captured by reference, so a fresh closure identity is what
            // makes the dispatch happen at all.
            this.calendar && this.calendar.setOption('dayCellClassNames', makeDayCellClassNames());
        });
    },

    /*
     | THE ONE derivation of "which days" — now in resources/js/ptrDays.js, so
     | it can be tested without a bundler (tests/js/ptrDays.test.js, run by
     | `node tests/js/zones.mjs` across the timezone matrix). deleteDays(),
     | setCountry() and the preview below all read THIS list, so the preview
     | counts exactly the days that get written — they cannot disagree, because
     | there is nothing to disagree with.
     |
     | That is also the reason the range half of the preview is computed here
     | and not on the server: a server-side preview would have to re-derive the
     | day list from (start, end), i.e. a SECOND derivation of the same thing.
     |
     | The derivation itself carries no timezone any more: it walks day ORDINALS
     | and never touches a local getter (the invariant is written out in
     | ptrDays.js — read it before touching the walk). Measured 2026-08-11: the
     | returned list is byte-identical in all 11 zones of tests/js/zones.mjs. Two
     | defects died with it, both pinned by the suite now:
     |   1. west of UTC the whole list shifted one day back
     |   2. in every DST zone a fall-back swallowed the last day of the range,
     |      and for one day per zone and year a single tap wrote nothing at all
     |      (which day that is has to be measured per zone — the days and the
     |      reason there is no rule are in ptrDays.js)
     | Sharing one list is what keeps the preview honest about the write:
     | whatever the docket promises is what gets stored. A second, server-side
     | derivation next to this write path would be worse than none, because the
     | two could promise different days.
     */
    rangeDays() {
        return rangeDays(this.newEventStart, this.newEventEnd);
    },

    /*
     | WHAT THE RANGE HOLDS RIGHT NOW — the country-independent half of the
     | consequence, so it is computed once and every chip reads it.
     |   free    — days inside the displayed year that carry no country
     |   held    — CODE -> days inside the range that country currently holds
     |   outside — days outside the displayed year. `events` only ever carries
     |             the displayed year (all four server queries bound to it), so
     |             those days' countries are genuinely UNKNOWN here; counting
     |             them as "no country" would be a guess, and counting them
     |             into a year total would be wrong. They get their own line.
     |             Reachable on mobile only: dayGridMonth shows bleed cells
     |             (core default showNonCurrentDates: true, internal-common.js
     |             :1512), while multiMonthYear sets it to false (multimonth/
     |             index.js:236) — so a cross-year drag needs the month view.
     |
     | Arithmetic kept to counting on purpose: a Map lookup per day, a string
     | compare for the year (ISO strings sort chronologically, the same trick
     | the stats pane uses), and integer counters. No date maths outside
     | rangeDays() above.
     */
    get ptrPreview() {
        const days = this.rangeDays();
        if (!days.length) return null;

        const year = String(this.currentYear ?? new Date().getFullYear());

        const byDay = new Map();
        for (const event of (this.events ?? [])) {
            byDay.set(String(event.start).slice(0, 10), String(event.country ?? '').toUpperCase());
        }

        const held = {};
        let free = 0;
        let outside = 0;

        for (const iso of days) {
            if (iso.slice(0, 4) !== year) {
                outside++;
                continue;
            }

            const code = byDay.get(iso);

            if (code) {
                held[code] = (held[code] ?? 0) + 1;
            } else {
                free++;
            }
        }

        return {year, total: days.length, inYear: days.length - outside, outside, free, held};
    },

    // Null-safe readouts for the docket, so the template needs no guards.
    get ptrFree() {
        const p = this.ptrPreview;
        return p ? p.free : 0;
    },

    get ptrOutside() {
        const p = this.ptrPreview;
        return p ? p.outside : 0;
    },

    get ptrHeldTotal() {
        const p = this.ptrPreview;
        return p ? Object.values(p.held).reduce((sum, n) => sum + n, 0) : 0;
    },

    ptrHeldDays(code) {
        const p = this.ptrPreview;
        return p ? (p.held[String(code).toUpperCase()] ?? 0) : 0;
    },

    /*
     | THE ONLY PREDICTED NUMBER, and the whole overwrite rule in one line.
     |
     | `before` is handed in by the server, where it comes out of the same
     | groupBy over the same array that prints "days total" in the stats pane —
     | so the figure in the modal and the figure in the stats tab are ONE
     | computation, not two that happen to agree.
     |
     | The gain is "days in the range, inside the displayed year, that are not
     | already this country". A day the target already holds adds nothing:
     | saveDays does firstOrNew on (user_id, day) and rewrites the country, so
     | re-stamping a day it already owns is a no-op for the count. Days held by
     | ANOTHER country do count — they move, which is what the docket says out
     | loud.
     */
    ptrAfter(code, before) {
        const p = this.ptrPreview;
        if (!p) return before;

        return before + p.inYear - (p.held[String(code).toUpperCase()] ?? 0);
    },

    // The chip's accessible name. The visible before → after pair is
    // aria-hidden, because "148 right-arrow 157" is not a sentence.
    ptrChipLabel(code, name, before) {
        const p = this.ptrPreview;
        if (!p) return name;

        return `${name}: ${before} days in ${p.year} now, ${this.ptrAfter(code, before)} after stamping `
            + `${p.total} ${p.total === 1 ? 'day' : 'days'}`;
    },

    deleteDays() {
        livewireComponent.call('deleteDays', this.rangeDays());

        this.modalOpen = false;
    },

    /*
     | The head's date span, now read off the SAME list as the write and the
     | docket, so the three cannot state three different things.
     |
     | Reading the list instead of re-deriving the dates is what makes that
     | guarantee hold: the label cannot name a day the write does not contain,
     | because it has no dates of its own. Measured 2026-08-11 for the marked
     | 14th-16th of March: "03/14/2026 – 03/16/2026 (3 days)" under
     | TZ=Europe/Berlin AND TZ=America/New_York, matching the written list in
     | both.
     |
     | The local constructor instead of `new Date(iso)` is load-bearing, and
     | more so than it looks: the entries are CALENDAR dates, while
     | `new Date('2026-03-14')` is parsed as UTC midnight and rendered in the
     | process zone. West of UTC that prints the day before — measured, the same
     | range would read "03/13/2026 – 03/15/2026" under TZ=America/New_York for
     | a list that writes 03-14, 03-15, 03-16. The label would then name three
     | days the user never marked. Build the Date from the components, always.
     */
    rangeLabel() {
        const days = this.rangeDays();
        if (!days.length) return '';

        const fmt = (iso) => {
            const [y, m, d] = iso.split('-').map(Number);
            return new Date(y, m - 1, d)
                .toLocaleDateString(undefined, {day: '2-digit', month: '2-digit', year: 'numeric'});
        };

        if (days.length === 1) return `${fmt(days[0])} (1 day)`;
        return `${fmt(days[0])} – ${fmt(days[days.length - 1])} (${days.length} days)`;
    },

    setCountry(country) {
        livewireComponent.call('saveDays', this.rangeDays(), country);

        this.modalOpen = false;
    }


});
