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
        let start = new Date(this.newEventStart);
        let end = new Date(this.newEventEnd);
        end = new Date(end);
        end.setDate(end.getDate() - 1);
        let days = [];
        for (let d = start; d <= end; d.setDate(d.getDate() + 1)) {
            days.push(new Date(d).toISOString().slice(0, 10));
        }
        livewireComponent.call('deleteDays', days);

        this.modalOpen = false;
    },

    setCountry(country) {
        let start = new Date(this.newEventStart);
        let end = new Date(this.newEventEnd);
        end = new Date(end);
        end.setDate(end.getDate() - 1);
        let days = [];
        for (let d = start; d <= end; d.setDate(d.getDate() + 1)) {
            days.push(new Date(d).toISOString().slice(0, 10));
        }
        livewireComponent.call('saveDays', days, country);

        this.modalOpen = false;
    }


});
