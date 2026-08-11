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

    /*
     | THE DAY CURSOR — the keyboard's answer to "which day of this stay".
     |
     | A chip was a day, so the tab order alone reached every stamped day. A bar
     | is one tab stop for several days, and leaving it at that took the keyboard
     | path away from every day except the first of each segment — a WCAG 2.1.1
     | regression against the chips, whatever the data happens to be: the number
     | of unreachable days is simply every stamped day minus one per segment.
     |
     | So Tab reaches a STAY and the arrow keys walk its DAYS — the roving-cursor
     | pattern a calendar wants anyway. Crossing a week or year boundary moves
     | focus to the neighbouring segment of the same stay, so the whole run is one
     | continuous walk: exactly the "one journey" the bar claims visually.
     |
     | `barCursorDay` is what Enter opens; `barCursorSpoken` is what the live
     | region next to the grid says, because the visible cursor is no use to a
     | screen reader and the bar's own label cannot re-announce itself.
     */
    barCursorDay: null,
    barCursorSpoken: '',

    // DAY-WISE, and it stays that way: one entry per tracked day of the
    // displayed year. Nothing in the grid reads it any more, but every COUNTING
    // path does — ptrPreview below builds its day->country map from it, and the
    // docket, the chips and the stats pane all count its entries. It is not the
    // calendar's event source; eventBars is.
    events: livewireComponent.entangle('events'),

    /*
     | THE GRID'S EVENT SOURCE — one entry per contiguous stay that touches the
     | displayed year, `end` EXCLUSIVE, projected server-side out of
     | App\Support\ContiguousStays (calendar.blade.php -> refreshEventBars).
     |
     | So a 30-day stay is ONE bar and not 30 chips, and no grouping happens in
     | this file: the runs are already derived, once, by the same code the stays
     | panel reads. A second grouping in here could disagree with that one, and
     | the disagreement would be invisible — both would look plausible.
     |
     | Consequence for the click path further down: a bar is ONE node spanning
     | many cells, so "the event" is no longer "the day".
     */
    eventBars: livewireComponent.entangle('eventBars'),

    currentYear: livewireComponent.entangle('currentYear').live,

    // The exact list of days inside the checked window that carry no country,
    // computed server-side (calendar.blade.php -> refreshUntrackedDays) and
    // shipped whole. This file does NO date arithmetic on it: it hatches set
    // members, nothing else. That is what keeps the number printed above the
    // calendar and the marked cells in step — they read the same list.
    untrackedDays: livewireComponent.entangle('untrackedDays'),

    async init() {

        // Handed over as shipped — `end` is already exclusive, so there is no
        // second place where a +1 could creep in or go missing. The whole
        // pipeline shifts the day exactly once, on the server.
        const toFcBar = (bar) => ({
            title: bar.title,
            start: bar.start,
            end: bar.end,
            allDay: true,
            country: bar.country ?? null,
        });

        const bars = (this.eventBars ?? []).map(toFcBar);

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
        // local rendering — as do Date objects, which is what both call sites
        // pass today (dayCellClassNames, dateClick).
        const localISODate = (d) => {
            if (typeof d === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(d)) return d;

            const date = d instanceof Date ? d : new Date(d);
            const pad = (n) => String(n).padStart(2, '0');
            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        };

        // Set lookup, rebuilt whenever the server ships a new list. The factory
        // exists so the option and its later replacement share ONE body but get
        // separate function identities (see the $watch below).
        // Where the day cursor stands right now. Declared here because eventClick
        // reads it; it is filled by the keyboard wiring after render() below.
        const cursor = {el: null, days: [], index: 0};

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
            events: bars,
            dayCellClassNames: makeDayCellClassNames(),
            eventContent: (arg) => {
                /*
                 | THE STAY BAND. Flag, ISO code and country NAME all go into the
                 | DOM; which of them is visible is decided in CSS by the band's
                 | own width (container query on .ptr-stay), because that width
                 | IS the length of the stay — one cell per day. No width is
                 | measured in here, and no viewport breakpoint stands in for
                 | one: a 5-day bar has room for the name on a phone too.
                 |
                 | role="img" + aria-label, as before: the flag emoji is the only
                 | visual carrier that is always shown (WCAG 1.4.1). Everything
                 | inside is aria-hidden, so the country is announced exactly
                 | once. The `title` attribute that used to sit here is gone: the
                 | band passes the pointer through now, so it cannot be hovered
                 | and a tooltip that never appears is worse than none.
                 | pointer-events does not reach the accessibility tree, so the
                 | role and the label are unaffected.
                 |
                 | The label also states the CUT. FullCalendar slices a bar at
                 | every week boundary and at the edge of the year view, and each
                 | slice is announced on its own — without this, a reader hears
                 | "Germany" three times and cannot tell it is one stay.
                 | arg.isStart/isEnd are false at a cut (verified in
                 | @fullcalendar/core internal-common.js:7117, where they come
                 | straight off the seg), which is the same signal the visible
                 | end caps are drawn from, via .fc-event-start/.fc-event-end.
                 */
                const name = countryName(arg.event.title);
                const code = arg.event.extendedProps.country || '';

                const cut = arg.isStart && arg.isEnd ? ''
                    : arg.isStart ? ', start of a stay that continues'
                        : arg.isEnd ? ', end of a stay that started earlier'
                            : ', middle of a longer stay';

                // data-from/data-to carry the bar's TRUE range to the day cursor
                // below, so it needs no second source and no geometry: the days
                // of a segment are the row's own day cells inside that range.
                // startStr/endStr are 'Y-m-d' for an all-day event, which is the
                // format the row's data-date attributes use — same strings, and
                // ISO strings compare chronologically.
                return {
                    html: `<span class="ptr-stay" role="img" aria-label="${esc(name + cut)}"`
                        + ` data-from="${esc(arg.event.startStr)}" data-to="${esc(arg.event.endStr)}">`
                        + `<span class="ptr-stay-flag" aria-hidden="true">${esc(flagOnly(arg.event.title))}</span>`
                        + (code ? `<span class="ptr-stay-code" aria-hidden="true">${esc(code)}</span>` : '')
                        + `<span class="ptr-stay-name" aria-hidden="true">${esc(name)}</span>`
                        // The cursor box. Decorative: the live region speaks the
                        // day, so nothing here has to be read out.
                        + `<span class="ptr-stay-cursor" aria-hidden="true"></span>`
                        + `</span>`,
                };
            },
            select: (info) => {
                this.newEventStart = info.startStr;
                this.newEventEnd = info.endStr;
                this.modalOpen = true;
            },
            /*
             | ONE POINTER PATH FOR EVERY DAY, stamped or not — and this is where
             | a click on a BAR is answered too.
             |
             | A chip used to be a day, so eventClick could answer "which day".
             | A bar is one node across many cells and eventClick reports the
             | WHOLE bar (measured: a click in the middle of the 02.-06.03. band
             | reports 2026-03-02 .. 2026-03-07), so it cannot.
             |
             | Resolving the day from the pointer position inside eventClick was
             | tried and worked (elementsFromPoint, three positions, all correct),
             | and was thrown away, because it repaired the wrong thing:
             | FullCalendar refuses a dateClick AND a date selection whose
             | pointerdown lands inside an event (isValidDateDownEl,
             | @fullcalendar/core internal-common.js:5836). A band covering the
             | full cell width therefore made every stamped day UNDRAGGABLE —
             | measured: a drag 04.->07.03. across a band produced no selection
             | at all, while the same drag over free days produced its three days.
             | The old chip did not escape that HORIZONTALLY: its .fc-event box
             | was already 63,09px of a 64,09px cell, and a drag from the middle
             | of the cell was dead there too (0 of 9). What kept it usable was
             | VERTICAL — the box was 14,59px tall in a 60,39px cell and occupied
             | only 0,37-0,61 of the height, so a drag above or below it lived.
             | Narrow, invisible and not findable. Full measurement below.
             |
             | So the band lets the pointer through (pointer-events: none in
             | app.css) and this handler sees the cell the user actually hit. One
             | behaviour for the whole grid, no geometry to get wrong, and a click
             | still changes exactly one day.
             |
             | WHAT THE OLD CHIP REALLY DID, corrected after being measured on
             | 8dd30f4 rather than assumed: the box isValidDateDownEl() inspects
             | is the `.fc-event`, and it was ALREADY the full cell width — 63,09px
             | of a 64,09px cell. The 38,5px was the inner `.ptr-flag` span, which
             | elementClosest() never looks at. And a drag from the middle of a
             | stamped cell was not "luck", it was already dead: 0 of 9 attempts
             | across three runs produced a selection. What escaped was VERTICAL —
             | the box is 14,59px tall in a 60,39px cell and occupies 0,37 to 0,61
             | of its height, so only a drag starting above or below that band
             | worked. Narrow, invisible and undiscoverable. This rule makes the
             | whole cell draggable at every height, which is the actual repair.
             */
            dateClick: (info) => {
                const next = new Date(info.date);
                next.setDate(next.getDate() + 1);
                this.newEventStart = info.dateStr;
                this.newEventEnd = localISODate(next);
                this.modalOpen = true;
            },
            /*
             | KEYBOARD ACTIVATION OF A BAND — the only thing that reaches this.
             |
             | It is not dead code, and it is not a second pointer path. Merely
             | DEFINING this handler is what keeps the bands in the tab order:
             | getSegAnchorAttrs() gives a segment tabIndex 0 and bridges
             | Enter/Space to eventClick only when eventClick has a listener
             | (@fullcalendar/core internal-common.js:4476-4493, the "ARIA
             | workaround" block at :314). Remove the handler and the segments
             | leave the tab order altogether — no keyboard route to a stamped day
             | at all (WCAG 2.1.1). It is therefore here although no pointer can
             | ever fire it: FullCalendar calls the keydown bridge directly, and
             | pointer-events cannot block a keydown.
             |
             | WHICH day it opens is the day cursor's answer (see below): Tab
             | reaches the stay, the arrow keys walk its days, Enter opens the one
             | the cursor stands on. Without a single arrow press that is the first
             | day of the focused segment, which is also the fallback here if the
             | cursor belongs to another band.
             */
            eventClick: (info) => {
                const day = (cursor.el === info.el && this.barCursorDay)
                    || info.el.closest('td[data-date]')?.getAttribute('data-date')
                    || localISODate(info.event.start);

                // From the components, like rangeLabel() below: `new Date(iso)`
                // is UTC midnight and reads back a day earlier west of UTC.
                const [y, m, d] = day.split('-').map(Number);
                const next = new Date(y, m - 1, d);
                next.setDate(next.getDate() + 1);

                this.newEventStart = day;
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

        /*
         | THE DAY CURSOR, wired to the grid — see the properties at the top of
         | this file for why it exists. Three rules and no geometry:
         |
         | WHICH DAYS a segment covers: the day cells of its own table ROW that
         | fall inside the bar's range. Measured, and it needs no special case for
         | either view: in the year grid a cell outside the month carries NO
         | data-date at all (fc-day-disabled), so it is simply not in the list; in
         | the month grid the bleed cells DO carry one — and there the segment
         | really does span them, because they are days the user can select.
         |
         | WHERE the cursor is drawn: two custom properties on the band, and the
         | CSS divides the band by them. The band spans one cell per day, so
         | 100%/days IS a day — no measuring, and it survives a resize.
         |
         | CROSSING a week or year boundary moves focus to the neighbouring
         | segment of the SAME bar (same from/to pair) instead of stopping. That is
         | what makes a cut stay one walk for the keyboard, the same claim the caps
         | make visually. Focus moves, so the browser's own focus handling stays
         | the single source of "where am I".
         */
        const bandOf = (eventEl) => eventEl && eventEl.querySelector('.ptr-stay');

        const daysOfSegment = (eventEl) => {
            const band = bandOf(eventEl);
            const row = eventEl.closest('tr');
            if (!band || !row) return [];

            const from = band.dataset.from;
            const to = band.dataset.to; // exclusive, as shipped

            if (!from || !to) return [];

            return [...row.querySelectorAll('td[data-date]')]
                .map((td) => td.getAttribute('data-date'))
                .filter((date) => date >= from && date < to);
        };

        // The segments of one bar, in calendar order. Identified by the range
        // itself: two segments of the same stay carry the same from/to pair, and
        // no other bar can — a country cannot hold two runs with equal bounds.
        const segmentsOfBar = (eventEl) => {
            const band = bandOf(eventEl);
            if (!band) return [eventEl];

            return [...this.$refs.cal.querySelectorAll('.fc-event')]
                .filter((el) => {
                    const other = bandOf(el);
                    return other
                        && other.dataset.from === band.dataset.from
                        && other.dataset.to === band.dataset.to;
                })
                .sort((a, b) => (daysOfSegment(a)[0] ?? '').localeCompare(daysOfSegment(b)[0] ?? ''));
        };

        // Speech, not display: words instead of 03/04/2026, because this string
        // is only ever read out. The date is the position, so no "day 3 of 5".
        const spokenDay = (iso, name) => {
            const [y, m, d] = iso.split('-').map(Number);
            const date = new Date(y, m - 1, d)
                .toLocaleDateString(undefined, {day: 'numeric', month: 'long', year: 'numeric'});

            return name ? `${date}, ${name}` : date;
        };

        const clearCursor = () => {
            const band = bandOf(cursor.el);
            if (band) {
                band.style.removeProperty('--ptr-days');
                band.style.removeProperty('--ptr-cursor');
            }
            cursor.el = null;
            cursor.days = [];
            cursor.index = 0;
            this.barCursorDay = null;
            this.barCursorSpoken = '';
        };

        const putCursor = (eventEl, index) => {
            if (cursor.el && cursor.el !== eventEl) clearCursor();

            const days = daysOfSegment(eventEl);
            if (!days.length) return false;

            cursor.el = eventEl;
            cursor.days = days;
            cursor.index = Math.max(0, Math.min(days.length - 1, index));

            const band = bandOf(eventEl);
            band.style.setProperty('--ptr-days', String(days.length));
            band.style.setProperty('--ptr-cursor', String(cursor.index));

            this.barCursorDay = days[cursor.index];
            this.barCursorSpoken = spokenDay(
                this.barCursorDay,
                band.querySelector('.ptr-stay-name')?.textContent ?? '',
            );

            return true;
        };

        const step = (delta) => {
            const next = cursor.index + delta;

            if (next >= 0 && next < cursor.days.length) {
                putCursor(cursor.el, next);

                return;
            }

            // Past the edge of this segment: hand over to the neighbouring one of
            // the same stay, if there is one. Focus goes with it.
            const segments = segmentsOfBar(cursor.el);
            const here = segments.indexOf(cursor.el);
            const neighbour = segments[here + (delta > 0 ? 1 : -1)];

            if (!neighbour) return;

            const days = daysOfSegment(neighbour);
            if (putCursor(neighbour, delta > 0 ? 0 : days.length - 1)) neighbour.focus();
        };

        this.$refs.cal.addEventListener('focusin', (event) => {
            const eventEl = event.target.closest('.fc-event');
            if (!eventEl) return;

            // Coming back to the SAME bar keeps the day it was left on. That is
            // the common path and not a nicety: opening the modal moves focus into
            // it (x-trap), so resetting here would send a keyboard user back to
            // day one of the stay after every single edit. A bar that was
            // re-rendered in between is a different element, so it starts at its
            // first day — which is what Enter alone did before the cursor existed.
            putCursor(eventEl, eventEl === cursor.el ? cursor.index : 0);
        });

        this.$refs.cal.addEventListener('focusout', (event) => {
            // relatedTarget is where focus is going; staying inside the same band
            // must not wipe anything.
            if (event.relatedTarget && event.relatedTarget.closest?.('.fc-event')) return;

            // The cursor keeps its POSITION (see focusin) but stops speaking: a
            // live region that still names a day nobody is standing on would be
            // read out at the next unrelated update.
            this.barCursorSpoken = '';
        });

        this.$refs.cal.addEventListener('keydown', (event) => {
            if (!cursor.el || !event.target.closest('.fc-event')) return;

            const delta = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
            if (!delta) return;

            // Otherwise the grid scrolls under the cursor.
            event.preventDefault();
            step(delta);
        });

        // The grid follows the BARS, and only the bars. Watching `events` here
        // instead would put the day-wise array back into the calendar after the
        // first Livewire morph — the grid would silently fall back to one chip
        // per day for everything the user just stamped, which is the shape of the
        // defect P1 had with the dropped `country` field: correct on first paint,
        // wrong from the first save on.
        this.$watch('eventBars', (newBars) => {
            this.calendar.removeAllEvents();
            this.calendar.addEventSource((newBars ?? []).map(toFcBar));
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
