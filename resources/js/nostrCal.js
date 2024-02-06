import {Calendar} from '@fullcalendar/core'
import multiMonthPlugin from '@fullcalendar/multimonth'
import interactionPlugin from '@fullcalendar/interaction';

export default (livewireComponent) => ({

    calendar: null,

    modalOpen: false,

    newEventStart: false,
    newEventEnd: false,

    events: livewireComponent.entangle('events'),

    async init() {

        // map this.events into this format: {title: 'event', start: '2021-01-01'}
        const events = this.events.map(event => {
            return {
                title: event.title,
                start: event.start,
                allDay: true
            }
        });

        this.calendar = new Calendar(this.$refs.cal, {
            plugins: [interactionPlugin, multiMonthPlugin],
            initialView: 'multiMonthYear',
            eventOverlap: false,
            selectable: true,
            unselectAuto: false,
            height: 'auto',
            defaultAllDay: true,
            timeZone: 'local',
            firstDay: 1,
            events: events,
            select: (info) => {
                this.newEventStart = info.startStr;
                this.newEventEnd = info.endStr;
                console.log(info);
                this.modalOpen = true;
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
        console.log(country);
        // convert newEventStart to Date object
        let start = new Date(this.newEventStart);
        let end = new Date(this.newEventEnd);
        // end is one day too far, so subtract one day
        end = new Date(end);
        end.setDate(end.getDate() - 1);
        // create an array of days between start and end
        let days = [];
        for (let d = start; d <= end; d.setDate(d.getDate() + 1)) {
            days.push(new Date(d).toISOString().slice(0, 10));
        }
        console.log(days);
        livewireComponent.call('saveDays', days, country);

        this.modalOpen = false;
    }


});
