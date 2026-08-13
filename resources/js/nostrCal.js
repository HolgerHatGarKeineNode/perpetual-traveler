import {Calendar} from '@fullcalendar/core'
import multiMonthPlugin from '@fullcalendar/multimonth'
import dayGridPlugin from '@fullcalendar/daygrid'
import interactionPlugin from '@fullcalendar/interaction';
import {rangeDays, resizeDelta, shiftDay} from './ptrDays.js';
import {localISODate} from './localISODate.js';

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
     | It answered half of WCAG 2.1.1 and only half: a bar exists where days are
     | already STAMPED, so the keyboard could overwrite but not enter — an unused
     | day had no tab stop anywhere in the grid (measured on 8dd30f4 and on
     | 6d7f51b alike). The GRID CURSOR further down is the other half; between
     | them every day of the displayed range is reachable.
     |
     | `barCursorDay` is what Enter opens while a bar has focus; `cursorSpoken` is
     | what the live region next to the grid says, and it serves BOTH cursors —
     | the visible mark is no use to a screen reader, and neither the bar's own
     | label nor the day cell's can re-announce itself.
     */
    barCursorDay: null,
    cursorSpoken: '',

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

        // localISODate() moved to resources/js/localISODate.js so a Node test
        // can import it without booting FullCalendar/jsdom — see that file for
        // the invariant (local getters ARE correct here, unlike ptrDays.js) and
        // the year-padding fix.

        // Set lookup, rebuilt whenever the server ships a new list. The factory
        // exists so the option and its later replacement share ONE body but get
        // separate function identities (see the $watch below).
        // Where the day cursor stands right now. Declared here because eventClick
        // reads it; it is filled by the keyboard wiring after render() below.
        const cursor = {el: null, days: [], index: 0};

        /*
         | THE MEMORY OF THE WALK, keyed by the STAY instead of by the DOM node.
         |
         | The cursor used to remember its day only while the same element kept
         | being focused (`eventEl === cursor.el ? cursor.index : 0`), and on the
         | path a keyboard user actually takes that is no memory at all: Escape
         | hands focus back to the document, the user tabs in again from the
         | front, and EVERY bar passed on the way reset its own index to 0 —
         | 03-06 came back as 03-02 (measured by the reviewer). Node identity is
         | the wrong key for a second reason too: a bar is a new element after
         | any re-render.
         |
         | So the key is the stay's own range, `from|to`, the same pair
         | segmentsOfBar() identifies siblings by and unique per stay for the same
         | reason (the unique index on (user_id, day)). One entry per stay, and
         | passing a bar without moving its cursor does not write to it.
         */
        const barMemory = new Map();

        /*
         | THE GRID CURSOR — one tab stop for the whole grid, and the answer to
         | "how does a keyboard reach a day that carries nothing".
         |
         | FullCalendar already renders the grid as an ARIA grid: role="grid" on
         | each month (multimonth/index.js:25), role="row" on the rows, and every
         | day cell a `td[role="gridcell"]` whose accessible name is maintained by
         | the library itself (`aria-labelledby` -> the day number's own
         | `aria-label`, e.g. "March 10, 2026" — buildNavLinkAttrs' non-navLink
         | branch, core internal-common.js:5484). What is missing from that
         | pattern is exactly one thing: a roving tabindex and the keys to move
         | it. This is that, and nothing more — no label bookkeeping, no injected
         | nodes, no second source for anything the library already states.
         |
         | THE WHOLE COST IS ONE ATTRIBUTE ON ONE ELEMENT. Not 365 of them: every
         | other cell keeps FullCalendar's default of no tabindex at all, so the
         | grid gains ONE tab stop, not one per day. That the tab order stays
         | short is the point — 365 stops would be a worse answer than none.
         |
         | Measured against the two things that could have made this expensive
         | (2026-08-13, the year grid at 1280px, 365 day cells of 504 cells):
         |   - a setOption-driven re-render KEEPS the cell nodes and every
         |     attribute set on them: 365/365 td, 504/504 day numbers, and an
         |     imperative tabIndex survived on 504/504. So the option dance that
         |     re-hatches the untracked days cannot lose the cursor.
         |   - a real save through Livewire keeps them too, focus included: the
         |     focused cell was still the focused cell afterwards.
         |   - a YEAR STEP replaces all of them (0/504 survive), which is why the
         |     cursor is re-placed from datesSet rather than restored.
         */
        let gridDay = null;

        /*
         | WHAT THE MODAL IS ABOUT, so that closing it can put the keyboard back
         | where it came from — by DAY, never by element. The element is exactly
         | what is not dependable: a save re-renders the bars, so the node that
         | opened the modal may not be the node that should receive focus back.
         | `barKey` is null when the modal was opened on a day cell.
         */
        let modalReturn = null;

        /*
         | datesSet fires from INSIDE render() below, and the keyboard wiring is a
         | set of consts declared after it — calling them from there would hit
         | their temporal dead zone. So the option calls this stub, and the real
         | placement is assigned once the wiring exists (and called once by hand
         | for the first paint, which render() has already been through).
         */
        let onDatesSet = () => {};

        /*
         | ONE ENTRY TO THE MODAL for all three paths — a click on a day, Enter on
         | a bar, Enter on the grid cursor — so that "which day is this about" is
         | recorded in exactly one place and the way back cannot disagree with the
         | way in.
         |
         | The range end is built from the day's COMPONENTS, which is what
         | eventClick has always done: `new Date(iso)` is UTC midnight and reads
         | back a day earlier west of UTC. dateClick used to build the same day
         | from its own `info.date` object; that produced the identical string
         | (both are local midnight of the same day, +1) and is now simply the
         | same line. No new arithmetic on any write path: the day list the modal
         | writes still comes from rangeDays().
         */
        const openDay = (day, barKey = null) => {
            const [y, m, d] = day.split('-').map(Number);
            const next = new Date(y, m - 1, d);
            next.setDate(next.getDate() + 1);

            modalReturn = {day, barKey};

            // The grid cursor follows the last day acted on, whichever way it was
            // reached — so Tab after a pointer edit lands where the user was
            // working, and the way back after Escape has a cell to aim at even
            // when the bar it came from is gone.
            putGridCursor(day);

            this.newEventStart = day;
            this.newEventEnd = localISODate(next);
            this.modalOpen = true;
        };

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
            /*
             | RESIZE, AND ONLY RESIZE — the two options that open the edges, and
             | the one that stays shut.
             |
             | `eventDurationEditable` puts a resizer on both terminals of a bar,
             | `eventResizableFromStart` is what the START one hangs on
             | (computeSegStartResizable, @fullcalendar/core internal-common.js
             | :4386 — it is undefined in BASE_OPTION_DEFAULTS, so nothing else
             | turns it on). `editable` and `eventStartEditable` are deliberately
             | NOT set: startEditable is what makes a bar DRAGGABLE, and moving a
             | stay is a different act (delete here, write there) with a different
             | meaning, so it is not on offer. Two independent things say so:
             | the `fc-event-draggable` class is never added, and
             | applyMutationToEventInstance discards a datesDelta without
             | startEditable (:3813 — the sibling applyMutationToEventDef at
             | :3782-3803 does not touch datesDelta at all, so naming it here was
             | wrong). P4's `pointer-events: none` on the band is a third — a drag
             | out of the band's middle produces a date SELECTION, measured in both
             | configurations.
             |
             | Resizing needs neither of the two, so "resize from the front" comes
             | without "move the whole stay" attached — which is the only reason
             | this phase could be cut this narrowly.
             |
             | DESKTOP ONLY, and the gate is the OPTION rather than CSS: with these
             | two false, FullCalendar renders no resizer element at all, so the
             | phone keeps a grid with nothing new in it — no hit area to compete
             | with the multi-day selection that `touch-action: none` carries, and
             | no 8px control on a touch screen that could never show a hover state
             | anyway. Same query the view choice above uses, so "the year grid" and
             | "resizable" are one decision: dayGridMonth is the phone's view, and
             | it is the only view with bleed cells into the neighbouring year.
             | Measured consequence of that pairing: a resize can never leave the
             | displayed year, because multiMonthYear sets showNonCurrentDates
             | false.
             */
            eventDurationEditable: !isMobile,
            eventResizableFromStart: !isMobile,
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
            dateClick: (info) => openDay(info.dateStr),
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
             |
             | ONE POINTER CAN REACH THIS AFTER ALL — the resize grip, and that is
             | why the first branch below exists. The grips are the only descendants
             | of the band that take the pointer, so a CLICK on one (press and
             | release without moving: too little movement for a resize) bubbles to
             | the segment anchor and lands here. Measured before the branch existed:
             | a click on the END grip, which sits over the stay's LAST day, opened
             | the modal for its FIRST day — info.el is the whole segment and its
             | closest td is the segment's first cell. That is a P4 property broken
             | by 8px, so the grip now answers with the day it is drawn on: the end
             | grip the segment's last day, the start grip its first. Both read off
             | daysOfSegment(), the same list the day cursor walks, so there is no
             | second notion of "the days of this segment".
             */
            eventClick: (info) => {
                const grip = info.jsEvent?.target?.closest?.('.fc-event-resizer');
                const segment = grip ? daysOfSegment(info.el) : [];

                const gripDay = grip && segment.length
                    ? (grip.classList.contains('fc-event-resizer-end') ? segment[segment.length - 1] : segment[0])
                    : null;

                const day = gripDay
                    || (cursor.el === info.el && this.barCursorDay)
                    || info.el.closest('td[data-date]')?.getAttribute('data-date')
                    || localISODate(info.event.start);

                // The stay's own range travels with it, so closing the modal can
                // find this bar again even though the element itself may not
                // survive the write that happens in between.
                openDay(day, barKeyOf(info.el));
            },
            /*
             | THE EDGE WAS PULLED — the whole write path of a correction, and the
             | only handler in this file that computes nothing itself.
             |
             | resizeDelta() turns the two ranges into the two write instructions
             | (resources/js/ptrDays.js — read the fail-closed argument there before
             | touching this). `added` goes to saveDays with the bar's own country,
             | `removed` to deleteDays; a resize moves exactly one edge, so one of
             | them is empty in practice, and both are handled because the function
             | answers both.
             |
             | oldEvent carries the state BEFORE the drag and event the one after,
             | both as 'Y-m-d' with an EXCLUSIVE end — the same shape the bars were
             | shipped in, so no +1 happens anywhere on this path. Measured in the
             | browser, 2026-08-12.
             |
             | THE SERVER IS THE TRUTH, not this optimistic bar. FullCalendar has
             | already redrawn the bar by the time this runs; the refresh that
             | saveDays/deleteDays trigger replaces `events` AND `eventBars`, and
             | the $watch further down rebuilds the grid from the latter. So if the
             | write came out differently the grid corrects itself, exactly as the
             | modal path does.
             |
             | NO OVERLAP GUARD IN HERE, and that is a measurement rather than an
             | omission. `eventOverlap: false` above already refuses a resize onto
             | days another bar holds — FullCalendar validates the mutated range
             | against the other events before it fires this callback
             | (isInteractionPropsValid, @fullcalendar/core internal-common.js:6544)
             | and reverts silently when it fails. Measured 2026-08-12 against the
             | real component, all three with the same gesture that works on a free
             | day as the control:
             |   FR end  -> a day IT holds        refused, 0 rows changed, no call
             |   IT start-> a day FR holds        refused, 0 rows changed, no call
             |   FR end  -> another FR run        refused, 0 rows changed, no call
             |   IT end  -> a FREE day (control)  written, saveDays on the wire
             | So a drag can never take days off another country without saying so,
             | which is the property the modal's docket provides on its own path. An
             | info.revert() here would be a second guard for a case that cannot
             | arrive, and it would have to re-derive "who holds this day" from the
             | year-scoped `events` — a second notion of occupancy next to the one
             | the calendar already applies.
             |
             | NO COUNTRY, NO WRITE. `country` is null for a bar projected from a
             | row that carries a time part (reachable, measured — see the bar
             | projection in calendar.blade.php), and saveDays would then stamp
             | those days with an empty country. Reverting is the honest answer: the
             | days keep the country they have, and nothing is invented.
             */
            eventResize: (info) => {
                const {added, removed} = resizeDelta(
                    info.oldEvent.startStr,
                    info.oldEvent.endStr,
                    info.event.startStr,
                    info.event.endStr,
                );

                const country = info.event.extendedProps.country;

                // NOTHING TO INSTRUCT -> UNDO THE OPTIC. resizeDelta() is
                // fail-closed: an unusable date on any of the four slots yields two
                // empty sets rather than a guess (a plain set difference would read
                // as "delete the whole stay" or "write one out of nothing"). If we
                // returned here without reverting, the bar would sit visually
                // resized against an unchanged database until the next refresh —
                // the one state this plan has spent four phases removing. Not
                // reachable today (a zero-day resize fires no callback and
                // startStr/endStr are always padded date strings), which is exactly
                // why it is written down instead of relied upon.
                if (!added.length && !removed.length) {
                    info.revert();

                    return;
                }

                // A bar can carry country: null when a stored day has a time part,
                // so the year query excludes it while the projection does not. It
                // may still SHRINK — deleting needs no country, and the days it
                // removes are the ones the user just dragged away.
                if (added.length && !country) {
                    info.revert();

                    return;
                }

                if (removed.length) livewireComponent.call('deleteDays', removed);
                if (added.length) livewireComponent.call('saveDays', added, country);
            },
            datesSet: function (dateInfo) {
                // Month grids bleed into the neighbouring month/year, so the first
                // visible cell is unreliable; take the midpoint of the visible range.
                const mid = new Date((dateInfo.start.getTime() + dateInfo.end.getTime()) / 2);
                that.currentYear = mid.getFullYear();

                // A navigation step replaces every day cell (measured: 0 of 504
                // survive), so the grid cursor has to be put down again.
                onDatesSet();
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
        // no other bar can, UNCONDITIONALLY — a day belongs to exactly one
        // country (the unique index on (user_id, day), migration
        // 2026_08_13_001604_add_unique_index_to_events_user_id_day.php), so two
        // bars can never claim the identical set of calendar days regardless of
        // which countries they carry. Before that index this held only under an
        // assumption a corrupt or racing write could break: two rows for the
        // same day, different countries, produced two separate one-day runs
        // with identical [from, to) bounds (App\Support\ContiguousStays' usort
        // tie-break on the title is what made that case deterministic rather
        // than order-dependent, not what prevented it) — pinned in
        // tests/Unit/ContiguousStaysGuardsTest.php, which reaches that state by
        // constructing it directly, the only way left to reach it at all.
        // The stay a segment belongs to, as a string — see barMemory above for
        // why this and not the element is the key.
        const barKeyOf = (eventEl) => {
            const band = bandOf(eventEl);

            return band && band.dataset.from && band.dataset.to
                ? `${band.dataset.from}|${band.dataset.to}`
                : null;
        };

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
            this.cursorSpoken = '';
        };

        /*
         | `remember` is false for exactly one case, and it is not a nicety: the
         | walk was left on a day in ANOTHER segment of this same stay (a stay is
         | cut at every week boundary). Tabbing onto this segment then has to
         | start at its own first day — but writing that day back would throw away
         | the position the user actually left, and the next Tab onto the other
         | segment would find it gone. Passing through is not moving.
         */
        const putCursor = (eventEl, index, remember = true) => {
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
            this.cursorSpoken = spokenDay(
                this.barCursorDay,
                band.querySelector('.ptr-stay-name')?.textContent ?? '',
            );

            const key = barKeyOf(eventEl);
            if (remember && key) barMemory.set(key, this.barCursorDay);

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

        /*
         | THE GRID CURSOR — see the declaration of `gridDay` above for what it
         | is and what it was measured to cost. Three functions and one attribute.
         */
        const cellOf = (iso) => (iso ? this.$refs.cal.querySelector(`td[data-date="${iso}"]`) : null);

        const putGridCursor = (iso, focus = false) => {
            const cell = cellOf(iso);
            if (!cell) return false;

            // Clearing by QUERY and not by remembered element: the element may
            // have been replaced since, and a stale tabindex left behind would be
            // a second tab stop nobody put there. Reading 504 cells costs nothing
            // next to the keypress that triggered it.
            this.$refs.cal.querySelectorAll('td[data-date][tabindex]')
                .forEach((td) => td.removeAttribute('tabindex'));

            cell.setAttribute('tabindex', '0');
            gridDay = iso;

            if (focus) cell.focus();

            return true;
        };

        /*
         | WHAT THE CELL IS, spoken. The cell's own accessible name is the date
         | and nothing else (FullCalendar's, and it stays FullCalendar's), so the
         | live region carries the state — computed at the moment of the step from
         | the two live sources, never stored on the cell. That is what keeps it
         | from going stale: `events` is the day-wise array every counting path
         | reads, and the hatch class is the same one dayCellClassNames sets from
         | the server's untracked list.
         */
        const speakGridDay = (iso) => {
            const cell = cellOf(iso);

            let name = '';
            for (const event of (this.events ?? [])) {
                if (String(event.start).slice(0, 10) === iso) {
                    name = countryName(event.title);
                    break;
                }
            }

            if (!name && cell?.classList.contains('ptr-untracked')) name = 'no country';

            this.cursorSpoken = spokenDay(iso, name);
        };

        const gridStep = (delta) => {
            const next = shiftDay(gridDay, delta);

            // shiftDay returns null for an unusable day, putGridCursor false for
            // a day the displayed view does not contain (the edges of the year,
            // and both ends of the month grid). Either way the cursor stays put
            // rather than guessing — prev/next are two tab stops away.
            if (!putGridCursor(next, true)) return;

            speakGridDay(next);
        };

        /*
         | WHERE THE CURSOR STANDS AFTER A RENDER. Today when the view holds it,
         | because that is the day a calendar is about; otherwise the first day of
         | the view, so the grid always has exactly one tab stop. Trying the
         | previous day first keeps the position across a re-render that did not
         | move the view.
         */
        onDatesSet = () => {
            if (putGridCursor(gridDay)) return;
            if (putGridCursor(localISODate(new Date()))) return;

            const first = this.$refs.cal.querySelector('td[data-date]');
            if (first) putGridCursor(first.getAttribute('data-date'));
        };

        onDatesSet();

        this.$refs.cal.addEventListener('focusin', (event) => {
            const eventEl = event.target.closest('.fc-event');
            if (!eventEl) return;

            // The day this stay was left on, whatever happened in between —
            // Escape, a tab through every other bar, a re-render. See barMemory
            // above; before it, this was `eventEl === cursor.el ? cursor.index : 0`
            // and any of those three lost the position.
            const key = barKeyOf(eventEl);
            const remembered = key ? barMemory.get(key) : null;
            const hit = remembered ? daysOfSegment(eventEl).indexOf(remembered) : -1;

            putCursor(eventEl, hit >= 0 ? hit : 0, hit >= 0 || !remembered);
        });

        this.$refs.cal.addEventListener('focusout', (event) => {
            // relatedTarget is where focus is going; moving from a bar to a cell
            // or between cells must not wipe anything — the next step speaks for
            // itself.
            if (event.relatedTarget && this.$refs.cal.contains(event.relatedTarget)) return;

            // Both cursors keep their POSITION but stop speaking: a live region
            // that still names a day nobody is standing on would be read out at
            // the next unrelated update.
            this.cursorSpoken = '';
        });

        /*
         | THE KEYS. Two focusables, one meaning per key: the arrows walk days.
         |
         | ARROWUP/ARROWDOWN ARE INTERCEPTED, which they deliberately were not
         | before this phase. The old reasoning still holds and is what changed:
         | without grid navigation they had no replacement, so taking the page
         | scroll away would have been a loss; with it they are the row step the
         | grid has always implied — seven days, one calendar row (firstDay: 1).
         | On a BAR they hand over to the grid cursor at the same offset, because
         | a stay has no rows of its own and a key that means two different things
         | depending on where the focus sits is worse than either meaning.
         | Nothing else in the calendar is intercepted: PageUp/PageDown, Home/End
         | and the space bar outside the grid keep scrolling the page.
         */
        this.$refs.cal.addEventListener('keydown', (event) => {
            if (event.altKey || event.ctrlKey || event.metaKey) return;

            const week = event.key === 'ArrowDown' ? 7 : event.key === 'ArrowUp' ? -7 : 0;

            if (event.target.closest('.fc-event')) {
                if (!cursor.el) return;

                const delta = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;

                if (delta) {
                    event.preventDefault();
                    step(delta);

                    return;
                }

                if (week) {
                    event.preventDefault();

                    const next = shiftDay(this.barCursorDay, week);
                    if (putGridCursor(next, true)) speakGridDay(next);
                }

                return;
            }

            // Focus is ON the cell itself — the roving tabindex sits there and
            // nowhere else, so this cannot be a keystroke bubbling out of some
            // other control inside the grid.
            const cell = event.target.closest?.('td[data-date]');
            if (!cell || event.target !== cell) return;

            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openDay(cell.getAttribute('data-date'));

                return;
            }

            const delta = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : week;
            if (!delta) return;

            event.preventDefault();
            gridStep(delta);
        });

        /*
         | THE WAY BACK OUT OF THE MODAL — WCAG 2.4.3, and it was broken for every
         | path into it, not just the keyboard one: focus landed on BODY.
         |
         | The cause is a race between two things that both want to place focus.
         | The country list auto-focuses its search field on $nextTick
         | (calendar.blade.php, x-effect), while Alpine's x-trap activates on a
         | 15ms timeout (livewire.esm.js: `setTimeout(() => trap.activate(), 15)`)
         | — so by the time focus-trap records "the node focused before
         | activation" it records the SEARCH FIELD, not the day or the bar the
         | user came from. On release it dutifully focuses that field again; the
         | modal is display:none by then, focusing a non-rendered element is a
         | no-op, and the browser drops focus to BODY. Measured on 6d7f51b and on
         | 8dd30f4: activeElement inside the modal is the search input, and after
         | Escape it is BODY while the input is still in the DOM with a null
         | offsetParent.
         |
         | So the trap is told not to return focus at all (`.noreturn` in the
         | template) and the way back is stated here, by DAY: the modal is about a
         | day, and when it closes the keyboard stands on that day. That holds for
         | the case the node-based approach cannot serve at all — the bar that
         | opened the modal has been re-rendered by the write, or has stopped
         | existing because its days went somewhere else.
         */
        this.$watch('modalOpen', (open) => {
            if (open || !modalReturn) return;

            const {day, barKey} = modalReturn;
            modalReturn = null;

            this.$nextTick(() => {
                const segment = barKey
                    ? [...this.$refs.cal.querySelectorAll('.fc-event')].find(
                        (el) => barKeyOf(el) === barKey && daysOfSegment(el).includes(day),
                    )
                    : null;

                if (segment) {
                    // Set before focusing, so the focusin handler above puts the
                    // cursor back on the day the modal was opened for.
                    barMemory.set(barKey, day);
                    segment.focus();

                    return;
                }

                if (putGridCursor(day, true)) speakGridDay(day);
            });
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
