<?php

use function Laravel\Folio\{middleware};
use function Livewire\Volt\{computed, state, mount, updated, rules};
use App\Models\Event;

middleware(['auth']);

state(['events', 'currentYear', 'start', 'search', 'selectedCountries']);

rules([
    'start' => 'required|date',
]);

$countries = computed(function () {
    return collect(countries())->sortBy('name');
});

$deleteDays = function ($days) {
    $currentYear = $this->currentYear ?? now()->year;

    // delete each day in the database
    Event::query()
        ->where('day', '>=', $currentYear . '-01-01')
        ->where('day', '<=', $currentYear . '-12-31')
        ->where('user_id', auth()->id())
        ->whereIn('day', $days)
        ->delete();

    $this->events = Event::query()
        ->where('day', '>=', $currentYear . '-01-01')
        ->where('day', '<=', $currentYear . '-12-31')
        ->where('user_id', auth()->id())
        ->get()
        ->map(fn($event) => [
            'title' => country($event->country)->getEmoji() . ' ' . country($event->country)->getName(),
            'start' => $event->day,
        ])
        ->toArray();
};

$saveDays = function ($days, $country) {
    $currentYear = $this->currentYear ?? now()->year;

    // save each day in the database
    foreach ($days as $day) {
        $event = Event::firstOrNew([
            'user_id' => auth()->id(),
            'day' => $day,
        ]);
        $event->user_id = auth()->id();
        $event->day = $day;
        $event->country = str($country)->lower();
        $event->save();
    }

    $this->events = Event::query()
        ->where('day', '>=', $currentYear . '-01-01')
        ->where('day', '<=', $currentYear . '-12-31')
        ->where('user_id', auth()->id())
        ->get()
        ->map(fn($event) => [
            'title' => country($event->country)->getEmoji() . ' ' . country($event->country)->getName(),
            'start' => $event->day,
        ])
        ->toArray();
};

mount(function () {
    $currentYear = $this->currentYear ?? now()->year;

    $this->events = Event::query()
        ->where('day', '>=', $currentYear . '-01-01')
        ->where('day', '<=', $currentYear . '-12-31')
        ->where('user_id', auth()->id())
        ->get()
        ->map(fn($event) => [
            'country' => country($event->country)->getIsoAlpha2(),
            'title' => country($event->country)->getEmoji() . ' ' . country($event->country)->getName(),
            'start' => $event->day,
        ])
        ->toArray();

    $this->start = auth()->user()->pt_start?->format('Y-m-d');

    $this->selectedCountries = collect($this->events)->pluck('country')->unique()->toArray();
});

updated([
    'currentYear' => function () {
        $currentYear = $this->currentYear ?? now()->year;

        $this->events = Event::query()
            ->where('day', '>=', $currentYear . '-01-01')
            ->where('day', '<=', $currentYear . '-12-31')
            ->where('user_id', auth()->id())
            ->get()
            ->map(fn($event) => [
                'title' => country($event->country)->getEmoji() . ' ' . country($event->country)->getName(),
                'start' => $event->day,
            ])
            ->toArray();
    },
    'start' => function () {
        $user = auth()->user();
        $user->pt_start = \Illuminate\Support\Carbon::parse($this->start)->startOfDay();
        $user->save();
    },
    'search' => function () {
        if ($this->search) {
            $this->countries = collect(countries())
                ->filter(fn($country) => str($country['name'])->lower()->contains(str($this->search)->lower()))
                ->sortBy('name');
        } else {
            $this->countries = collect(countries())->sortBy('name');
        }
    },
]);

?>

<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between">
            <div>
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                    {{ __('Calendar') }}
                </h2>
            </div>
        </div>
    </x-slot>
    @volt
    <div x-data="nostrCal(@this)" class="px-4 sm:px-6 lg:px-8 py-4">
        <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-400 mb-1" for="start">
                    Choose your start date as a perpetual traveler
                </label>
                <input
                        id="start"
                        type="date"
                        class="block w-full sm:w-auto rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-base sm:text-sm px-3 py-2"
                        wire:model.live.debounce="start"/>
            </div>
        </div>

        {{-- Mobile/Tablet Tab-Switch --}}
        <div class="flex lg:hidden border border-gray-200 dark:border-gray-700 rounded-md overflow-hidden mb-3 bg-white dark:bg-gray-800 shadow-sm">
            <button type="button" @click="tab='calendar'"
                    :class="tab==='calendar' ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300'"
                    class="flex-1 py-3 text-sm font-medium transition-colors">
                Calendar
            </button>
            <button type="button" @click="tab='stats'"
                    :class="tab==='stats' ? 'bg-indigo-600 text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300'"
                    class="flex-1 py-3 text-sm font-medium transition-colors">
                Statistics
            </button>
        </div>

        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm rounded-lg">
            <div class="p-3 sm:p-6 text-gray-900 dark:text-gray-100">
                <div class="lg:flex lg:space-x-4">
                    {{-- Calendar pane --}}
                    <div wire:ignore
                         x-show="tab === 'calendar'"
                         x-cloak
                         class="w-full lg:w-9/12 xl:w-10/12 lg:!block"
                         x-ref="cal"></div>

                    {{-- Stats pane --}}
                    <div x-show="tab === 'stats'"
                         x-cloak
                         class="w-full lg:w-3/12 xl:w-2/12 lg:!block mt-4 lg:mt-0">
                        <div class="lg:flex lg:flex-auto lg:justify-center">
                            <dl class="space-y-2 w-full lg:w-80">
                                @php
                                    // Sorting the events by date
                                    usort($events, function($a, $b) {
                                    return strtotime($a['start']) - strtotime($b['start']);
                                    });
                                    // Merging events of the same country and segregating into contiguous stays
                                    $contiguousStays = [];
                                    $currentTitle = null;
                                    $anzahlTage = 0;
                                    $von = null;
                                    $bis = null;
                                    foreach ($events as $i => $item) {
                                        if ($currentTitle !== $item['title'] || strtotime($item['start']) - strtotime($events[$i-1]['start']) > 86400) {
                                            if($currentTitle){
                                                $contiguousStays[$currentTitle][] = ['anzahlTage' => $anzahlTage, 'von' => $von, 'bis' => $bis];
                                            }
                                            $currentTitle = $item['title'];
                                            $anzahlTage = 1;
                                            $von = $item['start'];
                                        } else {
                                            $anzahlTage++;
                                        }
                                        $bis = $item['start'];
                                    }
                                    if($currentTitle){
                                        $contiguousStays[$currentTitle][] = ['anzahlTage' => $anzahlTage, 'von' => $von, 'bis' => $bis];
                                    }
                                    $events = collect($events)
                                        ->groupBy('title')
                                        ->map(function($event) use($start) {
                                            $totalDaysWithoutPt = 0;
                                            $totalDaysAsPt = 0;
                                            if (!$start) {
                                                $totalDaysWithoutPt = count($event);
                                            }
                                            if ($start) {
                                                $totalDaysWithoutPt = $event
                                                    ->filter(fn($e) => $e['start'] < $start)
                                                    ->count();
                                                $totalDaysAsPt = $event
                                                    ->filter(fn($e) => $e['start'] >= $start)
                                                    ->count();
                                            }
                                            return [
                                                'total_days' => count($event),
                                                'total_days_without_pt' => $totalDaysWithoutPt,
                                                'total_days_as_pt' => $totalDaysAsPt,
                                            ];
                                    });
                                @endphp
                                @if(count($events) === 0)
                                    <div class="text-sm text-gray-500 dark:text-gray-400 py-6 text-center">
                                        No events yet. Select days in the calendar to add countries.
                                    </div>
                                @endif
                                @foreach($events as $c => $event)
                                    <div class="flex flex-col gap-y-1 border border-gray-200 dark:border-gray-700 p-3 rounded">
                                        <dd class="text-md font-semibold tracking-tight text-gray-700 dark:text-gray-200">{{ $c }}</dd>
                                        <dt class="text-base font-bold text-gray-700 dark:text-gray-200">
                                            {{ $event['total_days_as_pt'] }} days - As PT
                                        </dt>
                                        @if($event['total_days_without_pt'] > 0)
                                            <dt class="text-xs text-gray-600 dark:text-gray-400">
                                                {{ $event['total_days_without_pt'] }} days - Without PT
                                            </dt>
                                        @endif
                                        <dt class="text-xs text-gray-600 dark:text-gray-400">
                                            {{ $event['total_days'] }} days - Total
                                        </dt>
                                        <dt class="text-base text-gray-600 dark:text-gray-300 border-t border-gray-200 dark:border-gray-700 pt-1 mt-1">
                                            Contiguous stays
                                        </dt>
                                        <ul role="list">
                                            @foreach($contiguousStays[$c] as $key => $stay)
                                                <li class="relative flex flex-col gap-x-1">
                                                    <div class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                                                        <span class="font-bold underline">{{ $stay['anzahlTage'] }} days</span>
                                                    </div>
                                                    <time class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                                                        from {{ \Illuminate\Support\Carbon::parse($stay['von'])->format('d.m.Y') }}
                                                        to {{ \Illuminate\Support\Carbon::parse($stay['bis'])->format('d.m.Y') }}
                                                    </time>
                                                </li>
                                                @if(!$loop->last)
                                                    <li class="relative flex gap-x-1">
                                                        <div class="border-l align-middle"></div>
                                                        @php
                                                            $daysInBetween = \Illuminate\Support\Carbon::parse($contiguousStays[$c][$key+1]['von'])->diffInDays(\Illuminate\Support\Carbon::parse($stay['bis'])) - 1;
                                                        @endphp
                                                        <div class="text-xs leading-5 @if($daysInBetween < 21) text-red-500 @else text-green-500 @endif">
                                                            {{ $daysInBetween }} days in between
                                                        </div>
                                                    </li>
                                                @endif
                                            @endforeach
                                        </ul>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Country-Select Modal --}}
        <div @keydown.escape.window="modalOpen = false"
             class="relative z-50 w-auto h-auto" x-cloak>
            <template x-teleport="body">
                <div x-show="modalOpen"
                     class="fixed inset-0 z-[99] flex items-end sm:items-center justify-center" x-cloak>
                    <div x-show="modalOpen"
                         x-transition:enter="ease-out duration-300"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="ease-in duration-300"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         @click="modalOpen=false"
                         class="absolute inset-0 w-full h-full bg-black bg-opacity-50"></div>
                    <div x-show="modalOpen"
                         x-trap.inert.noscroll="modalOpen"
                         x-transition:enter="ease-out duration-300"
                         x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                         x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                         x-transition:leave="ease-in duration-200"
                         x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                         x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                         class="relative w-full sm:w-[95vw] max-w-screen-2xl max-h-[90vh] sm:max-h-[85vh] overflow-y-auto py-4 px-4 sm:py-6 sm:px-7 bg-white dark:bg-gray-800 rounded-t-lg sm:rounded-lg shadow-xl">
                        <div class="flex items-center justify-between pb-2 sticky top-0 bg-white dark:bg-gray-800 z-10">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Choose country</h3>
                            <button @click="modalOpen=false"
                                    aria-label="Close"
                                    class="flex items-center justify-center w-10 h-10 text-gray-600 dark:text-gray-300 rounded-full hover:text-gray-800 dark:hover:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700">
                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                     stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <div class="relative w-auto">
                            <div class="py-4 flex flex-col sm:flex-row sm:items-center gap-3">
                                <div class="flex items-center px-3 border border-gray-200 dark:border-gray-700 rounded-md flex-1">
                                    <input
                                            wire:model.live.debounce="search"
                                            type="text"
                                            class="flex w-full px-2 py-3 text-base sm:text-sm bg-transparent border-0 rounded-md outline-none focus:outline-none focus:ring-0 focus:border-0 placeholder:text-neutral-400 dark:text-gray-100 h-11 disabled:cursor-not-allowed disabled:opacity-50"
                                            placeholder="Search..." autocomplete="off" autocorrect="off"
                                            spellcheck="false">
                                </div>
                                <button type="button" @click="deleteDays"
                                        class="rounded bg-red-600 px-4 py-3 sm:py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600 whitespace-nowrap">
                                    Delete selected days
                                </button>
                            </div>
                            @if(count($this->selectedCountries))
                                <div class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mt-2">
                                    Already used
                                </div>
                                <div class="flex flex-wrap gap-1 py-3 border-b border-gray-200 dark:border-gray-700">
                                    @foreach($this->selectedCountries as $country)
                                        <button type="button"
                                                @click="setCountry('{{ $country }}')"
                                                wire:key="c_{{ $country }}"
                                                class="px-3 py-2 text-sm cursor-pointer text-gray-800 dark:text-gray-100 bg-gray-50 dark:bg-gray-700 hover:bg-indigo-50 dark:hover:bg-indigo-900 hover:text-indigo-700 dark:hover:text-indigo-200 transition-colors rounded-md min-h-[40px]">
                                            {{ country($country)->getEmoji() . ' ' . country($country)->getName() }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                            <div class="text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 mt-3">
                                All countries
                            </div>
                            <div class="flex flex-wrap gap-1 pt-2">
                                @foreach($this->countries as $country)
                                    <button type="button"
                                            @click="setCountry('{{ $country['iso_3166_1_alpha2'] }}')"
                                            wire:key="c_{{ $country['iso_3166_1_alpha2'] }}"
                                            class="px-3 py-2 text-sm cursor-pointer text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-900 hover:text-indigo-700 dark:hover:text-indigo-200 transition-colors rounded-md min-h-[40px]">
                                        {{ $country['emoji'] }} {{ $country['name'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

    </div>
    @endvolt
</x-app-layout>
