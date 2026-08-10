<?php

use function Laravel\Folio\{middleware};
use function Livewire\Volt\{computed, state, mount, updated, rules};
use App\Models\Event;

middleware(['auth']);

state(['events', 'currentYear', 'start', 'selectedCountries']);

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
            'country' => country($event->country)->getIsoAlpha2(),
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
            'country' => country($event->country)->getIsoAlpha2(),
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
                'country' => country($event->country)->getIsoAlpha2(),
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
]);

?>

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <h2 class="font-display font-bold text-xl text-navy-900 dark:text-navy-50 leading-tight">
                    {{ __('Calendar') }}
                </h2>
                <span class="eyebrow text-navy-400 dark:text-navy-300 hidden sm:inline">Every day, one country</span>
            </div>
        </div>
    </x-slot>
    @volt('calendar')
    <div x-data="nostrCal(@this)" class="px-4 sm:px-6 lg:px-8 py-6 max-w-[110rem] mx-auto">
        {{-- Start-date field, framed as a travel document line --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5 rounded-xl border border-navy-100 dark:border-white/10 bg-white dark:bg-navy-900 px-4 py-3.5 shadow-sm">
            <div>
                <label class="eyebrow text-gold-600 dark:text-gold-300 block mb-1" for="start">
                    Perpetual-traveler start date
                </label>
                <p class="text-sm text-navy-500 dark:text-navy-300">
                    Days before this are counted apart from your residency days.
                </p>
            </div>
            <input
                    id="start"
                    type="date"
                    class="block w-full sm:w-auto rounded-lg border-navy-200 dark:border-white/10 bg-white dark:bg-navy-950/60 text-navy-900 dark:text-navy-100 shadow-sm focus:border-gold-400 focus:ring-2 focus:ring-gold-400/40 text-base sm:text-sm px-3.5 py-2.5 [color-scheme:light] dark:[color-scheme:dark]"
                    wire:model.live.debounce="start"/>
        </div>

        {{-- Mobile/Tablet Tab-Switch — segmented control --}}
        <div class="flex lg:hidden p-1 border border-navy-100 dark:border-white/10 rounded-xl mb-4 bg-white dark:bg-navy-900 shadow-sm gap-1">
            <button type="button" @click="tab='calendar'"
                    :class="tab==='calendar' ? 'bg-gold-400 text-navy-950 shadow-sm' : 'text-navy-600 dark:text-navy-300'"
                    class="flex-1 min-h-[44px] rounded-lg text-sm font-semibold transition-colors">
                Calendar
            </button>
            <button type="button" @click="tab='stats'"
                    :class="tab==='stats' ? 'bg-gold-400 text-navy-950 shadow-sm' : 'text-navy-600 dark:text-navy-300'"
                    class="flex-1 min-h-[44px] rounded-lg text-sm font-semibold transition-colors">
                Statistics
            </button>
        </div>

        <div class="bg-white dark:bg-navy-900 overflow-hidden border border-navy-100 dark:border-white/10 shadow-sm rounded-2xl">
            <div class="p-3 sm:p-6 text-navy-900 dark:text-navy-100">
                <div class="lg:flex lg:space-x-6">
                    {{-- Calendar pane --}}
                    <div wire:ignore
                         x-show="tab === 'calendar'"
                         x-cloak
                         class="w-full lg:w-8/12 xl:w-9/12 lg:!block"
                         x-ref="cal"></div>

                    {{-- Stats pane --}}
                    <div x-show="tab === 'stats'"
                         x-cloak
                         class="w-full lg:w-4/12 xl:w-3/12 lg:!block mt-6 lg:mt-0 lg:border-l lg:border-navy-100 lg:dark:border-white/10 lg:pl-6">
                        <div class="w-full">
                            <div class="hidden lg:flex items-center gap-2 mb-4">
                                <h3 class="font-display font-semibold text-navy-900 dark:text-navy-50">Your stays</h3>
                                <span class="eyebrow text-navy-400 dark:text-navy-300">by country</span>
                            </div>
                            <dl class="space-y-3 w-full">
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
                                    <div class="pt-stamp bg-navy-50/60 dark:bg-navy-950/40 px-5 py-8 text-center">
                                        <div class="text-3xl mb-3">🛂</div>
                                        <p class="font-display font-semibold text-navy-900 dark:text-navy-50">No stamps yet</p>
                                        <p class="mt-1.5 text-sm text-navy-500 dark:text-navy-300">
                                            Drag across days in the calendar and pick a country. Each stay
                                            shows up here as a stamp with its day-count.
                                        </p>
                                    </div>
                                @endif
                                @foreach($events as $c => $event)
                                    <div class="pt-stamp bg-white dark:bg-navy-900 p-4">
                                        {{-- Stamp head: country + hero day-count --}}
                                        <div class="flex items-start justify-between gap-3">
                                            <dd class="font-display font-semibold text-base tracking-tight text-navy-900 dark:text-navy-50 leading-snug">{{ $c }}</dd>
                                            <div class="text-right shrink-0">
                                                <div class="font-mono text-2xl font-bold leading-none text-navy-900 dark:text-navy-50">{{ $event['total_days'] }}</div>
                                                <div class="eyebrow text-navy-400 dark:text-navy-300 mt-1">days total</div>
                                            </div>
                                        </div>

                                        {{-- PT split --}}
                                        <div class="flex flex-wrap gap-x-4 gap-y-1 mt-3">
                                            <dt class="font-mono text-xs text-gold-600 dark:text-gold-300">
                                                {{ $event['total_days_as_pt'] }} as PT
                                            </dt>
                                            @if($event['total_days_without_pt'] > 0)
                                                <dt class="font-mono text-xs text-navy-500 dark:text-navy-300">
                                                    {{ $event['total_days_without_pt'] }} before PT
                                                </dt>
                                            @endif
                                        </div>

                                        {{-- Contiguous stays timeline --}}
                                        <dt class="eyebrow text-navy-400 dark:text-navy-300 mt-4 pt-3 border-t border-navy-100 dark:border-white/10">
                                            Contiguous stays
                                        </dt>
                                        <ol role="list" class="mt-2">
                                            @foreach($contiguousStays[$c] as $key => $stay)
                                                <li class="flex items-baseline justify-between gap-2">
                                                    <span class="font-mono text-sm font-bold text-navy-900 dark:text-navy-50">{{ $stay['anzahlTage'] }}d</span>
                                                    <time class="font-mono text-xs text-navy-500 dark:text-navy-300">
                                                        {{ \Illuminate\Support\Carbon::parse($stay['von'])->format('d.m.Y') }}
                                                        &rarr; {{ \Illuminate\Support\Carbon::parse($stay['bis'])->format('d.m.Y') }}
                                                    </time>
                                                </li>
                                                @if(!$loop->last)
                                                    <li class="pt-gap-rail py-1.5 my-1">
                                                        @php
                                                            // Carbon 3 gibt diffInDays() vorzeichenbehaftet zurueck (Carbon 2:
                                                            // absolut). Die Richtung muss deshalb stimmen: vom letzten Tag
                                                            // dieses Aufenthalts zum ersten des naechsten. Kein abs() --
                                                            // eine negative Zahl waere ein echter Sortierfehler und soll
                                                            // sichtbar bleiben, statt still plausibel zu werden.
                                                            $daysInBetween = \Illuminate\Support\Carbon::parse($stay['bis'])->diffInDays(\Illuminate\Support\Carbon::parse($contiguousStays[$c][$key+1]['von'])) - 1;
                                                        @endphp
                                                        <span class="font-mono text-xs font-medium @if($daysInBetween < 21) text-risk dark:text-risk-bright @else text-ok dark:text-ok-bright @endif">
                                                            {{ $daysInBetween }} days gap{{ $daysInBetween < 21 ? ' · tight' : '' }}
                                                        </span>
                                                    </li>
                                                @endif
                                            @endforeach
                                        </ol>
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
                         class="absolute inset-0 w-full h-full bg-navy-950/70 backdrop-blur-sm"></div>
                    <div x-show="modalOpen"
                         x-trap.inert.noscroll="modalOpen"
                         x-transition:enter="ease-out duration-300"
                         x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                         x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                         x-transition:leave="ease-in duration-200"
                         x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                         x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                         class="relative w-full sm:w-[95vw] max-w-screen-2xl max-h-[90vh] sm:max-h-[85vh] overflow-y-auto py-4 px-4 sm:py-6 sm:px-7 bg-white dark:bg-navy-900 border border-navy-100 dark:border-white/10 rounded-t-2xl sm:rounded-2xl shadow-2xl">
                        <div class="flex items-center justify-between pb-3 sticky top-0 bg-white dark:bg-navy-900 z-10">
                            <div>
                                <p class="eyebrow text-gold-600 dark:text-gold-300 mb-1">Stamp these days</p>
                                <h3 class="font-display text-lg font-semibold text-navy-900 dark:text-navy-50">Choose country</h3>
                                <p class="font-mono text-xs text-navy-500 dark:text-navy-300 mt-0.5" x-text="rangeLabel()"></p>
                            </div>
                            <button @click="modalOpen=false"
                                    aria-label="Close"
                                    class="flex items-center justify-center w-11 h-11 text-navy-500 dark:text-navy-300 rounded-full hover:text-navy-900 dark:hover:text-navy-100 hover:bg-navy-100/60 dark:hover:bg-white/5 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-gold-400">
                                <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                     stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        @php
                            $allCountries = $this->countries->values();
                            $grouped = $allCountries
                                ->groupBy(fn($c) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($c['name'], 0, 1)))
                                ->sortKeys();
                            // letter => [lowercased names] for Alpine's instant filter/count
                            $groupsForJs = $grouped
                                ->map(fn($list) => $list->map(fn($c) => \Illuminate\Support\Str::lower($c['name']))->values()->all())
                                ->all();
                            $letters = $grouped->keys()->all();
                        @endphp
                        <div class="relative w-auto"
                             x-data="{
                                q: '',
                                groups: @js($groupsForJs),
                                get needle() { return this.q.trim().toLowerCase(); },
                                matchName(n) { return !this.needle || n.includes(this.needle); },
                                groupCount(l) {
                                    const g = this.groups[l];
                                    if (!g) return 0;
                                    return this.needle ? g.filter(n => n.includes(this.needle)).length : g.length;
                                },
                                get total() {
                                    return Object.values(this.groups)
                                        .reduce((s, a) => s + (this.needle ? a.filter(n => n.includes(this.needle)).length : a.length), 0);
                                },
                                clear() { this.q = ''; this.$refs.q?.focus(); },
                                jump(l) { document.getElementById('grp-' + l)?.scrollIntoView({ behavior: 'smooth', block: 'start' }); },
                                selectFirst() {
                                    const b = [...this.$refs.list.querySelectorAll('button[data-country]')].find(el => el.offsetParent !== null);
                                    if (b) b.click();
                                },
                             }"
                             x-effect="modalOpen ? $nextTick(() => $refs.q?.focus()) : (q = '')">

                            {{-- Controls: instant search + clear-days --}}
                            <div class="py-4 flex flex-col sm:flex-row sm:items-center gap-3">
                                <div class="flex items-center px-3 border border-navy-200 dark:border-white/10 bg-white dark:bg-navy-950/60 rounded-lg flex-1 focus-within:border-gold-400 focus-within:ring-2 focus-within:ring-gold-400/40 transition">
                                    <svg class="w-4 h-4 text-navy-400 dark:text-navy-300 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3" stroke-linecap="round"/>
                                    </svg>
                                    <input
                                            x-ref="q"
                                            x-model="q"
                                            @keydown.enter.prevent="selectFirst()"
                                            @keydown.escape="if (q) { q = ''; $event.stopPropagation(); }"
                                            type="text"
                                            aria-label="Search countries"
                                            class="flex w-full px-2 py-3 text-base sm:text-sm bg-transparent border-0 rounded-lg outline-none focus:outline-none focus:ring-0 focus:border-0 placeholder:text-navy-400 dark:placeholder:text-navy-300 text-navy-900 dark:text-navy-100 h-11 disabled:cursor-not-allowed disabled:opacity-50"
                                            placeholder="Search countries…" autocomplete="off" autocorrect="off"
                                            spellcheck="false">
                                    <button type="button" x-show="q" x-cloak @click="clear()" aria-label="Clear search"
                                            class="flex items-center justify-center w-9 h-9 shrink-0 text-navy-400 dark:text-navy-300 rounded-full hover:text-navy-900 dark:hover:text-navy-100 hover:bg-navy-100/60 dark:hover:bg-white/5 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-gold-400">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" d="M6 18 18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>
                                <button type="button" @click="deleteDays"
                                        class="inline-flex items-center justify-center gap-2 min-h-[44px] rounded-lg bg-risk px-4 py-3 sm:py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-risk/90 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-risk whitespace-nowrap transition">
                                    Clear these days
                                </button>
                            </div>

                            {{-- Recently used (hidden while searching to keep the field of view clean) --}}
                            @if(count($this->selectedCountries))
                                <div x-show="!needle" x-cloak>
                                    <div class="eyebrow text-navy-400 dark:text-navy-300 mt-1">
                                        Recently used
                                    </div>
                                    <div class="flex flex-wrap gap-1.5 py-3 border-b border-navy-100 dark:border-white/10">
                                        @foreach($this->selectedCountries as $country)
                                            <button type="button"
                                                    @click="setCountry('{{ $country }}')"
                                                    wire:key="c_{{ $country }}"
                                                    class="inline-flex items-center gap-2 px-3 py-2 text-sm cursor-pointer text-navy-900 dark:text-navy-100 bg-navy-100/70 dark:bg-white/5 border border-transparent hover:border-gold-400 hover:bg-gold-400/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-400 transition-colors rounded-lg min-h-[44px]">
                                                {{ country($country)->getEmoji() . ' ' . country($country)->getName() }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- List heading + live count --}}
                            <div class="flex items-baseline justify-between gap-3 mt-4 mb-1">
                                <div class="eyebrow text-navy-400 dark:text-navy-300">
                                    <span x-show="!needle">All countries</span>
                                    <span x-show="needle" x-cloak x-text="total + (total === 1 ? ' match' : ' matches')"></span>
                                </div>
                                <span x-show="needle && total > 0" x-cloak class="eyebrow text-navy-400 dark:text-navy-300">&crarr; stamps top match</span>
                            </div>

                            {{-- Grouped list + A–Z jump rail --}}
                            <div class="relative">
                                <div x-ref="list"
                                     class="max-h-[52vh] sm:max-h-[56vh] overflow-y-auto pr-1 sm:pr-8 scroll-pt-9"
                                     style="scroll-behavior: smooth;">
                                    @foreach($grouped as $letter => $list)
                                        <section wire:key="grp_{{ $letter }}" x-show="groupCount('{{ $letter }}') > 0" x-cloak>
                                            <div id="grp-{{ $letter }}" class="sticky top-0 z-10 bg-white dark:bg-navy-900 flex items-center gap-3 py-1.5">
                                                <span class="font-mono text-sm font-bold text-gold-600 dark:text-gold-300">{{ $letter }}</span>
                                                <span class="h-px flex-1 bg-navy-100 dark:bg-white/10"></span>
                                            </div>
                                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-1 pb-3 pt-1">
                                                @foreach($list as $country)
                                                    <button type="button"
                                                            data-country
                                                            x-show="matchName(@js(\Illuminate\Support\Str::lower($country['name'])))"
                                                            @click="setCountry('{{ $country['iso_3166_1_alpha2'] }}')"
                                                            wire:key="c_{{ $country['iso_3166_1_alpha2'] }}"
                                                            class="flex items-center gap-2 px-3 py-2 min-h-[44px] text-left text-sm rounded-lg border border-transparent text-navy-700 dark:text-navy-200 hover:border-gold-400 hover:bg-gold-400/10 hover:text-navy-900 dark:hover:text-navy-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-400 transition-colors">
                                                        <span class="text-base leading-none shrink-0">{{ $country['emoji'] }}</span>
                                                        <span class="truncate">{{ $country['name'] }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        </section>
                                    @endforeach

                                    {{-- No results --}}
                                    <div x-show="total === 0" x-cloak class="pt-stamp bg-navy-50/60 dark:bg-navy-950/40 px-5 py-10 text-center my-2">
                                        <div class="text-2xl mb-2">🔍</div>
                                        <p class="font-display font-semibold text-navy-900 dark:text-navy-50">No country matches “<span x-text="q"></span>”</p>
                                        <p class="mt-1.5 text-sm text-navy-500 dark:text-navy-300">Try the English name — e.g. “Czechia”, “Netherlands”, “United States”.</p>
                                    </div>
                                </div>

                                {{-- A–Z rail: supplementary pointer shortcut (search + list are the
                                     accessible path, so this is hidden on small screens & out of tab order) --}}
                                <nav x-show="!needle" x-cloak aria-label="Jump to letter"
                                     class="hidden sm:flex absolute right-0 top-0 bottom-0 flex-col items-center justify-center select-none">
                                    @foreach($letters as $letter)
                                        <button type="button" tabindex="-1" @click="jump('{{ $letter }}')"
                                                class="font-mono text-[0.65rem] leading-none px-1.5 py-[0.12rem] text-navy-400 dark:text-navy-300 hover:text-gold-600 dark:hover:text-gold-300 rounded transition-colors">
                                            {{ $letter }}
                                        </button>
                                    @endforeach
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

    </div>
    @endvolt
</x-app-layout>
