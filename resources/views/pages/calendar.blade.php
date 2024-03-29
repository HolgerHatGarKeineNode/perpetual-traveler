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

$setCountry = function ($country) {
    dd($country);

    $this->modalOpen = false;
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
    <div x-data="nostrCal(@this)">
        <div class="flex items-center py-4 px-4 sm:px-12">
            <div>
                <div class="flex justify-between items-end mb-1">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-400"
                           for="start">
                        Choose your start date as a perpetual traveler
                    </label>
                </div>
                <input
                        id="start"
                        type="date"
                        wire:model.live.debounce="start"/>
            </div>
        </div>
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6 text-gray-900 dark:text-gray-100">
                <div class="flex space-x-2">
                    <div wire:ignore class="w-9/12 xl:w-10/12" x-ref="cal"></div>
                    <div class="w-3/12 xl:w-2/12">
                        <div class="lg:flex lg:flex-auto lg:justify-center">
                            <dl class="space-y-2 w-80">
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
                                @foreach($events as $c => $event)
                                    <div class="flex flex-col gap-y-1 border p-2 rounded">
                                        <dd class="text-md font-semibold tracking-tight text-gray-600">{{ $c }}</dd>
                                        <dt class="text-base font-bold text-gray-600">
                                            {{ $event['total_days_as_pt'] }} days - As PT
                                        </dt>
                                        @if($event['total_days_without_pt'] > 0)
                                            <dt class="text-xs text-gray-600">
                                                {{ $event['total_days_without_pt'] }} days - Without PT
                                            </dt>
                                        @endif
                                        <dt class="text-xs text-gray-600">
                                            {{ $event['total_days'] }} days - Total
                                        </dt>
                                        <dt class="text-base text-gray-600 border-t">
                                            Contiguous stays
                                        </dt>
                                        <ul role="list">
                                            @foreach($contiguousStays[$c] as $key => $stay)
                                                <li class="relative flex flex-col gap-x-1">
                                                    <div class="text-xs leading-5 text-gray-500">
                                                        <span
                                                                class="font-bold underline">{{ $stay['anzahlTage'] }} days</span>
                                                    </div>
                                                    <time datetime="2023-01-23T10:32"
                                                          class="text-xs leading-5 text-gray-500">
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
                                                        <div
                                                                class="text-xs leading-5 @if($daysInBetween < 21) text-red-500 @else text-green-500 @endif ">
                                                            {{ $daysInBetween }}
                                                            days in between
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

        <div @keydown.escape.window="modalOpen = false"
             class="relative z-50 w-auto h-auto" x-cloak>
            <template x-teleport="body">
                <div x-show="modalOpen"
                     class="fixed top-0 left-0 z-[99] flex items-center justify-center w-screen h-screen" x-cloak>
                    <div x-show="modalOpen"
                         x-transition:enter="ease-out duration-300"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="ease-in duration-300"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         @click="modalOpen=false" class="absolute inset-0 w-full h-full bg-black bg-opacity-40"></div>
                    <div x-show="modalOpen"
                         x-trap.inert.noscroll="modalOpen"
                         x-transition:enter="ease-out duration-300"
                         x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                         x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                         x-transition:leave="ease-in duration-200"
                         x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                         x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                         class="relative w-full py-6 bg-white px-7 max-w-screen-2xl sm:rounded-lg">
                        <div class="flex items-center justify-between pb-2">
                            <h3 class="text-lg font-semibold">Choose country</h3>
                            <button @click="modalOpen=false"
                                    class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 text-gray-600 rounded-full hover:text-gray-800 hover:bg-gray-50">
                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                     stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <div class="relative w-auto">
                            <div class="py-6 flex flex-row justify-between">
                                <div class="flex items-center px-3 border-b">
                                    <input
                                            wire:model.live.debounce="search"
                                            type="text"
                                            class="flex w-full px-2 py-3 text-sm bg-transparent border-0 rounded-md outline-none focus:outline-none focus:ring-0 focus:border-0 placeholder:text-neutral-400 h-11 disabled:cursor-not-allowed disabled:opacity-50"
                                            placeholder="Search..." autocomplete="off" autocorrect="off"
                                            spellcheck="false">
                                </div>
                                <button type="button" @click="deleteDays"
                                        class="rounded bg-red-600 px-2 py-1 text-sm font-semibold text-white shadow-sm hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600">
                                    Delete selected days
                                </button>
                            </div>
                            <div class="flex flex-wrap py-6 border-t border-b">
                                @foreach($this->selectedCountries as $country)
                                    <div
                                            class="px-2 py-1 text-sm cursor-pointer hover:bg-neutral-100 dark:hover:bg-neutral-800 dark:hover:text-neutral-100 transition-colors rounded-md"
                                            @click="setCountry('{{ $country }}')"
                                            wire:key="c_{{ $country }}">
                                        {{ country($country)->getEmoji() . ' ' . country($country)->getName() }}
                                    </div>
                                @endforeach
                            </div>
                            <div class="flex flex-wrap">
                                @foreach($this->countries as $country)
                                    <div
                                            class="px-2 py-1 text-sm cursor-pointer hover:bg-neutral-100 dark:hover:bg-neutral-800 dark:hover:text-neutral-100 transition-colors rounded-md"
                                            @click="setCountry('{{ $country['iso_3166_1_alpha2'] }}')"
                                            wire:key="c_{{ $country['iso_3166_1_alpha2'] }}">
                                        {{ $country['emoji'] }} {{ $country['name'] }}
                                    </div>
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
