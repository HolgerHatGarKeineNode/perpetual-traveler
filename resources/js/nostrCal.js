import {Calendar} from '@fullcalendar/core'
import multiMonthPlugin from '@fullcalendar/multimonth'
import dayGridPlugin from '@fullcalendar/daygrid'
import interactionPlugin from '@fullcalendar/interaction';

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

        const localISODate = (d) => {
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

    deleteDays() {
        const pad = (n) => String(n).padStart(2, '0');
        const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        let start = new Date(this.newEventStart);
        let end = new Date(this.newEventEnd);
        end.setDate(end.getDate() - 1);
        let days = [];
        for (let d = start; d <= end; d.setDate(d.getDate() + 1)) {
            days.push(fmt(d));
        }
        livewireComponent.call('deleteDays', days);

        this.modalOpen = false;
    },

    rangeLabel() {
        if (!this.newEventStart || !this.newEventEnd) return '';
        const fmt = (d) => new Date(d).toLocaleDateString(undefined, {day: '2-digit', month: '2-digit', year: 'numeric'});
        const start = new Date(this.newEventStart);
        const endExclusive = new Date(this.newEventEnd);
        const last = new Date(endExclusive);
        last.setDate(last.getDate() - 1);
        const dayCount = Math.round((endExclusive - start) / 86400000);
        if (dayCount <= 1) return `${fmt(start)} (1 day)`;
        return `${fmt(start)} – ${fmt(last)} (${dayCount} days)`;
    },

    setCountry(country) {
        const pad = (n) => String(n).padStart(2, '0');
        const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
        let start = new Date(this.newEventStart);
        let end = new Date(this.newEventEnd);
        end.setDate(end.getDate() - 1);
        let days = [];
        for (let d = start; d <= end; d.setDate(d.getDate() + 1)) {
            days.push(fmt(d));
        }
        livewireComponent.call('saveDays', days, country);

        this.modalOpen = false;
    }


});
