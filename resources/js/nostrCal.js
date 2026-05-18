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

    async init() {

        const events = this.events.map(event => {
            return {
                title: event.title,
                start: event.start,
                allDay: true
            }
        });

        const that = this;
        const isMobile = window.matchMedia('(max-width: 1023px)').matches;

        const flagOnly = (title) => {
            const chars = Array.from(title || '');
            return chars.slice(0, 2).join('');
        };

        const localISODate = (d) => {
            const date = d instanceof Date ? d : new Date(d);
            const pad = (n) => String(n).padStart(2, '0');
            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        };

        this.calendar = new Calendar(this.$refs.cal, {
            plugins: [interactionPlugin, multiMonthPlugin, dayGridPlugin],
            initialView: isMobile ? 'dayGridMonth' : 'multiMonthYear',
            headerToolbar: isMobile
                ? {left: 'prev,next', center: 'title', right: 'today'}
                : {left: '', center: 'title', right: ''},
            eventOverlap: false,
            selectable: true,
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
            eventContent: (arg) => ({
                html: `<span class="ptr-flag" title="${arg.event.title}">${flagOnly(arg.event.title)}</span>`,
            }),
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
                const startYear = dateInfo.start.getFullYear();
                const endYear = dateInfo.end.getFullYear();

                if (startYear !== endYear) {
                    that.currentYear = startYear;
                } else {
                    that.currentYear = startYear;
                }
            },
        });

        this.calendar.render();

        this.$watch('events', (newEvents) => {
            this.calendar.removeAllEvents();
            this.calendar.addEventSource(newEvents.map(event => {
                return {
                    title: event.title,
                    start: event.start,
                    allDay: true
                }
            }));
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
